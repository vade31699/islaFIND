<?php
// ============================================================
// session_store.php — where the PHP session is kept
//
// DEFAULT: PHP's own file storage. Nothing in this file has any
// effect unless SESSION_DRIVER=mysql is set, so the WAMP setup
// behaves exactly as it always has.
//
// WHY MySQL IS OFFERED AT ALL
// A host that rebuilds its container on every deploy — and that may
// run more than one instance of the app — keeps no session files
// between releases and shares none between instances. Every visitor
// would be signed out on each deploy, at random, and a request that
// landed on a second instance would not see the login at all.
// Moving the session into the database the app already uses fixes
// both, and is why this switch exists.
//
// HOW IT BEHAVES
// The session payload is opaque to us: PHP serialises it, we store
// the bytes in a MEDIUMBLOB and hand them back unchanged. The table
// is created on first use (CREATE TABLE IF NOT EXISTS) — the same
// self-healing approach db.php takes for its own two tables — so a
// deployment never needs a manual migration step.
//
// LOCKING
// Two requests from the same visitor can overlap: messenger_poll.php
// and notifications.php poll on short timers while the visitor is
// also loading pages. PHP's file handler serialises those by locking
// the session file; a plain database handler does not, so a poll could
// read the session, a page could then write a fresh CSRF token or
// flash message, and the poll's later write would clobber it with its
// stale copy. To keep the behaviour the app has today, a named lock
// (GET_LOCK) is held for the life of the request. It is released on
// write/close, and MySQL releases it by itself if the connection
// dies, so a crashed request cannot wedge a visitor out. If the lock
// cannot be taken within the timeout the request proceeds WITHOUT it
// (fail-open): session bookkeeping must never lock a visitor out of
// the app.
// ============================================================

require_once __DIR__ . '/db_settings.php';

/**
 * isla_session_driver()
 * Which storage the session should use: 'files' (the default) or
 * 'mysql'. Anything unrecognised falls back to 'files', so a typo in
 * the environment cannot leave an app with no session at all.
 *
 * @return string 'files' or 'mysql'
 */
function isla_session_driver(): string
{
    return strtolower(trim(env('SESSION_DRIVER', 'files'))) === 'mysql' ? 'mysql' : 'files';
}

/**
 * isla_session_register()
 * Swaps in the database-backed session handler when it is asked for.
 * MUST be called before session_start(); session_harden() is where
 * every page does that, so this is called from there.
 *
 * @return bool TRUE when the mysql handler was registered.
 */
function isla_session_register(): bool
{
    if (isla_session_driver() !== 'mysql') {
        return false;   // leave PHP's own file storage exactly as it is
    }

    static $registered = false;
    if ($registered) {
        return true;    // session_set_save_handler() may only run once
    }

    session_set_save_handler(new IslaMysqlSessionHandler(isla_db_pdo()), true);
    $registered = true;

    return true;
}

/**
 * IslaMysqlSessionHandler
 * Stores PHP sessions in the `isla_sessions` table.
 *
 * Every statement is a prepared statement: the session id arrives in
 * a cookie, which is attacker-controlled text, so it is never built
 * into SQL. Reads also filter on last_seen, which means a row that has
 * outlived gc_maxlifetime counts as absent even if PHP's own garbage
 * collector has not run yet — expiry never depends on gc() being
 * called.
 */
final class IslaMysqlSessionHandler implements SessionHandlerInterface
{
    /** @var PDO Live connection, shared with the rest of the request. */
    private PDO $pdo;

    /** @var array<string,string> What read() returned, so write() can skip a no-op. */
    private array $loaded = [];

    /** @var array<string,bool> Which session ids currently hold a lock. */
    private array $locked = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * gc_maxlifetime as it stands for THIS request, in seconds. Read
     * live rather than captured, because PHP may change it per request.
     */
    private function maxLifetime(): int
    {
        $lifetime = (int) ini_get('session.gc_maxlifetime');

        return $lifetime > 0 ? $lifetime : 1440;   // 1440s = PHP's own default
    }

    /**
     * A lock name MySQL accepts for any session id.
     * GET_LOCK() names are capped at 64 characters, and a session id
     * can be longer than that and may contain arbitrary bytes, so the
     * id is hashed down to a fixed, safe length. Two different ids can
     * therefore collide only by losing a SHA-256 collision.
     */
    private function lockName(string $id): string
    {
        return 'isla.sess.' . substr(hash('sha256', $id), 0, 40);
    }

    private function acquireLock(string $id): void
    {
        if (isset($this->locked[$id])) {
            return;
        }

        try {
            // 5s is long enough for the overlapping poll this exists to
            // serialise, and short enough that a visitor never hangs.
            $stmt = $this->pdo->prepare('SELECT GET_LOCK(:name, 5)');
            $stmt->execute([':name' => $this->lockName($id)]);
            $this->locked[$id] = ((int) $stmt->fetchColumn()) === 1;
        } catch (PDOException $e) {
            // Fail-open: without the lock the request still works, it
            // is only exposed to the clobbering described above.
            error_log('islaFIND: session lock unavailable: ' . $e->getMessage());
            $this->locked[$id] = false;
        }
    }

    private function releaseLock(string $id): void
    {
        if (empty($this->locked[$id])) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $stmt->execute([':name' => $this->lockName($id)]);
        } catch (PDOException $e) {
            // The connection closing releases it anyway.
            error_log('islaFIND: session unlock failed: ' . $e->getMessage());
        }

        $this->locked[$id] = false;
    }

    private function table(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        // A row per session. `last_seen` is written from PHP's time()
        // rather than MySQL's NOW() so the comparison in read() can
        // never be thrown off by the two clocks disagreeing — the same
        // reasoning as the login_attempts table in security.php.
        //
        // MEDIUMBLOB, not TEXT: PHP's session payload is serialised
        // bytes (it can hold binary), and the column must not be
        // collation-converted or truncated on the way in or out.
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS isla_sessions (
                id        VARCHAR(128) NOT NULL,
                data      MEDIUMBLOB   NOT NULL,
                last_seen INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                KEY idx_sessions_seen (last_seen)
            ) ENGINE = InnoDB'
        );

        $ready = true;
    }

    public function open(string $path, string $name): bool
    {
        $this->table();

        return true;
    }

    public function close(): bool
    {
        foreach (array_keys($this->locked) as $id) {
            $this->releaseLock($id);
        }

        return true;
    }

    public function read(string $id): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT data FROM isla_sessions
              WHERE id = :id AND last_seen >= :cutoff
              LIMIT 1'
        );
        $stmt->execute([
            ':id'     => $id,
            ':cutoff' => time() - $this->maxLifetime(),
        ]);

        $data = $stmt->fetchColumn();

        // A missing or expired row is an empty session — exactly what
        // PHP expects for a visitor who has not started one yet.
        $this->loaded[$id] = $data === false ? '' : (string) $data;
        $this->acquireLock($id);

        return $this->loaded[$id];
    }

    public function write(string $id, string $data): bool
    {
        // Nothing changed since read() — a poll endpoint reading the
        // session and writing the same bytes back must not touch the
        // row, or it would roll back a write another request just made.
        if (($this->loaded[$id] ?? null) === $data) {
            $this->releaseLock($id);

            return true;
        }

        // One upsert: insert the first write, or replace the payload.
        // VALUES() is used rather than MySQL 8's newer alias syntax
        // because MariaDB (which WAMP ships) does not support the alias.
        $stmt = $this->pdo->prepare(
            'INSERT INTO isla_sessions (id, data, last_seen)
             VALUES (:id, :data, :seen)
             ON DUPLICATE KEY UPDATE
                data      = VALUES(data),
                last_seen = VALUES(last_seen)'
        );
        $stmt->execute([
            ':id'   => $id,
            ':data' => $data,
            ':seen' => time(),
        ]);

        $this->loaded[$id] = $data;
        $this->releaseLock($id);

        return true;
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM isla_sessions WHERE id = :id');
        $stmt->execute([':id' => $id]);

        unset($this->loaded[$id]);
        $this->releaseLock($id);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        // PHP calls this on its own schedule; the LIMIT keeps a sweep
        // from turning into one huge delete on a busy table.
        $stmt = $this->pdo->prepare('DELETE FROM isla_sessions WHERE last_seen < :cutoff LIMIT 1000');
        $stmt->execute([':cutoff' => time() - $max_lifetime]);

        return $stmt->rowCount();
    }
}
