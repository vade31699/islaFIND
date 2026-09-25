<?php
// ============================================================
// db.php — Secure database connection (PDO)
// This single file is included by every other PHP page so the
// whole app talks to the same MySQL connection.
//
// Credentials are read from .env (see env.php / .env.example).
// The WAMP values below are only FALLBACKS used when a key is
// missing, so a fresh checkout still runs without a .env file
// while a real deployment keeps its password out of the source.
// ============================================================

// --- 1. Make env() available ----------------------------------
// env.php parses .env on include; it has no other dependencies,
// so it is cheap enough to load on every request.
require_once __DIR__ . '/env.php';

// --- 2. Connection settings -----------------------------------
// env(key, fallback) checks the process environment first, then
// .env, then the fallback. A real password belongs in .env only.
$db_host = env('DB_HOST', 'localhost');   // MySQL server address (WAMP runs it locally)
$db_port = env('DB_PORT', '3306');        // MySQL port (WAMP default)
$db_name = env('DB_NAME', 'final_app');   // Database created in Phase 1 via HeidiSQL
$db_user = env('DB_USER', 'root');        // WAMP's default MySQL username
$db_pass = env('DB_PASS', '');            // WAMP's default MySQL password (empty)

/**
 * isla_ensure_schema(PDO $pdo)
 * Creates tables that a feature added AFTER the database was first
 * built: saved_listings (the bookmarked-listings heart) and
 * login_attempts (the server-side failed-login counters), neither of
 * which existed in the original schema dump.
 *
 * Why here instead of only in final_app.sql: an existing deployment
 * must not have to re-import the dump to use a new feature. Every
 * statement is CREATE TABLE IF NOT EXISTS, so it is a no-op once the
 * table exists, and the once-per-session guard in step 4 (or the
 * plain call from a CLI script) keeps even that no-op off the hot
 * path of every request.
 *
 * A failure here must NOT take the whole app down, so errors are
 * logged and swallowed — the only consequence is that the bookmarks
 * feature reports "unavailable" instead of crashing every page, and
 * that login throttling reads an empty table (which the throttle
 * helpers treat as "no failures yet").

 * @param PDO $pdo Live connection created just above.
 * @return void
 */
function isla_ensure_schema(PDO $pdo): void
{
    // Once per PHP session is plenty — and for a CLI script (no active
    // session) the check simply runs on each invocation.
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['isla_schema_ok'])) {
        return;
    }

    try {
        // login_attempts holds one counter row per throttle key: the
        // typed identifier ('account') and the caller's IP ('ip'). A
        // UNIQUE key on (scope, subject) lets the login page bump the
        // row with a single upsert instead of counting rows, and the
        // index on last_failed_at serves both the decay check and the
        // periodic prune of rows nobody has failed against in a while.
        //
        // No foreign keys on purpose: "subject" is a typed email/phone
        // (which may match no account at all) or an IP, so there is
        // nothing in users to point at.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                scope           ENUM(\'account\', \'ip\') NOT NULL,
                subject         VARCHAR(190) NOT NULL,
                failures        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                first_failed_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_failed_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_login_key (scope, subject),
                KEY idx_login_last (last_failed_at)
            ) ENGINE = InnoDB'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS saved_listings (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id     INT UNSIGNED NOT NULL,
                provider_id INT UNSIGNED NOT NULL,
                created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_saved_pair (user_id, provider_id),
                KEY idx_saved_user (user_id),
                CONSTRAINT fk_saved_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_saved_provider FOREIGN KEY (provider_id)
                    REFERENCES providers (id) ON DELETE CASCADE
            ) ENGINE = InnoDB'
        );
    } catch (PDOException $e) {
        // Logged, never fatal: the app keeps working, only the new
        // feature that needs this table reports itself unavailable.
        error_log('islaFIND schema check failed: ' . $e->getMessage());
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['isla_schema_ok'] = 1;   // guard the rest of this session
    }
}

// --- 3. Try to open the connection ---------------------------
try {
    // Build the DSN: which driver, host, port, database, and charset.
    // utf8mb4 guarantees full UTF-8 support (emoji, accents, etc.).
    $dsn = 'mysql:host=' . $db_host . ';port=' . $db_port . ';dbname=' . $db_name . ';charset=utf8mb4';

    // --- 3a. Connection options ---------------------------------
    // The two attributes below used to be set AFTER connecting; they
    // are passed to the constructor instead, so there is one place
    // that decides how the connection behaves.
    $pdo_options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    // --- 3b. TLS for a managed MySQL server ---------------------
    // TiDB Cloud Serverless (and most managed MySQL) REFUSE plaintext
    // connections, and PDO ignores the DSN's ssl-mode entirely — the
    // certificate has to be handed to the driver through these
    // constants. Local WAMP needs none of it: leave DB_SSL_CA empty
    // (the default) and this whole block does nothing.
    $db_ssl_ca = env('DB_SSL_CA', '');
    if ($db_ssl_ca !== '') {
        // A relative path is resolved against the project root (this
        // file lives in include/, hence the '../'), so .env can say
        // "certs/islandCA.pem" and the same file keeps working
        // wherever the app is deployed. An absolute path is used as-is.
        $ca_file = preg_match('#^([A-Za-z]:[\\\\/]|/)#', $db_ssl_ca) === 1
            ? $db_ssl_ca
            : __DIR__ . '/../' . $db_ssl_ca;

        if (!is_file($ca_file)) {
            // Logged, never echoed: a missing certificate is about to
            // break the connection below, and the visitor must not see
            // filesystem paths. See the catch block at the end of file.
            error_log('islaFIND: DB_SSL_CA is set but no certificate was found at ' . $ca_file);
        }

        $pdo_options[PDO::MYSQL_ATTR_SSL_CA] = $ca_file;
        // Verify the server's certificate (on unless DB_SSL_VERIFY=0).
        // Disabling it is only ever right for a throwaway test server:
        // without verification, TLS still encrypts but no longer proves
        // you are talking to the server you think you are.
        $pdo_options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = env('DB_SSL_VERIFY', '1') !== '0';
    }

    // Create the PDO object (the connection itself).
    $pdo = new PDO($dsn, $db_user, $db_pass, $pdo_options);

    // --- 4. Self-healing schema ----------------------------------
    // Runs the once-per-session guard below; defined here so the DDL
    // lives with the connection that owns it.
    isla_ensure_schema($pdo);

} catch (PDOException $e) {
    // --- Connection failed ------------------------------------
    // The driver's message names the host, database and user, and
    // a failed AUTHENTICATION echoes the attempted credentials —
    // so it is written to the server log for the administrator and
    // NEVER sent to the browser. Same convention as the mailer's
    // error_log() calls.
    error_log('islaFIND database connection failed: ' . $e->getMessage());

    // The visitor sees a short generic message with no internals:
    // no host, no database name, no username, no driver text.
    http_response_code(500);
    die('Database connection failed. Please check that MySQL is running and that the database is configured, then try again.');
}
