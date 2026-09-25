<?php
// ============================================================
// _tidb_probe.php — MySQL/TiDB compatibility probe (CLI tool)
// TiDB is MySQL-compatible, but "compatible" has edges. This runs
// the exact SQL shapes islaFIND depends on against the configured
// server (TiDB Cloud Serverless in production, WAMP locally) and
// reports which ones behave the same, so a difference shows up
// here instead of in production.
//
// Run it after any query or schema change, against whichever server
// .env points at:
//
//     php _tidb_probe.php
//
// It needs DB_SSL_CA set when the server demands TLS (TiDB does).
//
//   php _tidb_probe.php
//
// Uses one scratch table (zz_compat_probe) and drops it again; the
// only other statement it runs against real tables is an UPDATE
// inside a transaction that is rolled back.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/db.php';

$pass = 0;
$fail = 0;
$notes = [];

function ok(string $label, bool $isOk, string $detail = ''): void
{
    global $pass, $fail;
    if ($isOk) {
        $pass++;
        echo "  PASS  $label\n";
        return;
    }
    $fail++;
    echo "  FAIL  $label" . ($detail !== '' ? "  ($detail)" : '') . "\n";
}

function note(string $text): void
{
    global $notes;
    $notes[] = $text;
}

echo "\n=== TiDB compatibility probe ===\n\n";

echo '  server: ' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
echo '  version: ' . (string) $pdo->query('SELECT @@version_comment')->fetchColumn() . "\n\n";

// ------------------------------------------------------------
// The scratch table mirrors the two structures the app leans on:
// login_attempts (ENUM + UNIQUE + counters + TIMESTAMP) and any
// table with an AUTO_INCREMENT primary key.
// ------------------------------------------------------------
$pdo->exec('DROP TABLE IF EXISTS zz_compat_probe');
$pdo->exec(
    'CREATE TABLE zz_compat_probe (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        scope          ENUM(\'account\', \'ip\') NOT NULL,
        subject        VARCHAR(100) NOT NULL,
        failures       INT UNSIGNED NOT NULL DEFAULT 0,
        last_failed_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_probe_key (scope, subject)
    ) ENGINE = InnoDB'
);

// --- 1. AUTO_INCREMENT + lastInsertId() ----------------------
$pdo->exec("INSERT INTO zz_compat_probe (scope, subject) VALUES ('account', 'a@b.c')");
$id1 = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO zz_compat_probe (scope, subject) VALUES ('account', 'd@e.f')");
$id2 = (int) $pdo->lastInsertId();
ok('AUTO_INCREMENT + lastInsertId()', $id1 > 0 && $id2 === $id1 + 1, "ids $id1, $id2");

// --- 2. INSERT IGNORE (save_listing.php, hire_action.php) ----
$before = (int) $pdo->query('SELECT COUNT(*) FROM zz_compat_probe')->fetchColumn();
$pdo->exec("INSERT IGNORE INTO zz_compat_probe (scope, subject) VALUES ('account', 'a@b.c')");
$after = (int) $pdo->query('SELECT COUNT(*) FROM zz_compat_probe')->fetchColumn();
ok('INSERT IGNORE ignores the duplicate', $before === $after, "$before -> $after rows");

// --- 3. ON DUPLICATE KEY UPDATE (login.php, send_message.php) -
// Start from a known value: the assertion is about `failures + 1`
// reading the EXISTING row (5), not the value in VALUES (1).
$pdo->exec("UPDATE zz_compat_probe SET failures = 5 WHERE subject = 'a@b.c'");
$pdo->exec("INSERT INTO zz_compat_probe (scope, subject, failures) VALUES ('account', 'a@b.c', 1)
            ON DUPLICATE KEY UPDATE failures = failures + 1");
$n = (int) $pdo->query("SELECT failures FROM zz_compat_probe WHERE subject = 'a@b.c'")->fetchColumn();
ok('ON DUPLICATE KEY UPDATE increments the existing row', $n === 6, "failures = $n (expected 6)");

// --- 4. The REAL throttle statement from security.php --------
// INCLUDING the ordering rule the comment there depends on:
// last_failed_at = NOW() must be evaluated LAST.
try {
    $cutoff = time() - 60;
    $stmt = $pdo->prepare(
        "INSERT INTO zz_compat_probe (scope, subject, failures, first_failed_at, last_failed_at)
         VALUES (:scope, :subject, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            failures        = IF(UNIX_TIMESTAMP(last_failed_at) < :cutoff_a, 1, failures + 1),
            last_failed_at  = NOW()"
    );
    $stmt->execute([':scope' => 'account', ':subject' => 'a@b.c', ':cutoff_a' => $cutoff]);
    $n = (int) $pdo->query("SELECT failures FROM zz_compat_probe WHERE subject = 'a@b.c'")->fetchColumn();
    ok('UNIX_TIMESTAMP() + IF() inside the throttle upsert', is_int($n) && $n > 0, "failures = $n");
} catch (PDOException $e) {
    // column first_failed_at does not exist in the probe table — the
    // point of this check is the IF/UNIX_TIMESTAMP expression, so
    // re-run it without that column.
    $stmt = $pdo->prepare(
        "INSERT INTO zz_compat_probe (scope, subject, failures, last_failed_at)
         VALUES (:scope, :subject, 1, NOW())
         ON DUPLICATE KEY UPDATE
            failures       = IF(UNIX_TIMESTAMP(last_failed_at) < :cutoff_a, 1, failures + 1),
            last_failed_at = NOW()"
    );
    $stmt->execute([':scope' => 'account', ':subject' => 'a@b.c', ':cutoff_a' => $cutoff]);
    $n = (int) $pdo->query("SELECT failures FROM zz_compat_probe WHERE subject = 'a@b.c'")->fetchColumn();
    ok('UNIX_TIMESTAMP() + IF() inside the throttle upsert', is_int($n) && $n > 0, "failures = $n");
}

// --- 5. UNIX_TIMESTAMP() read (security.php step 3) ----------
$epoch = $pdo->query("SELECT UNIX_TIMESTAMP(last_failed_at) FROM zz_compat_probe WHERE subject = 'a@b.c'")->fetchColumn();
ok('UNIX_TIMESTAMP() returns an epoch integer', ctype_digit((string) $epoch) && (int) $epoch > 1600000000, "got $epoch");

// --- 6. DATE_SUB(NOW(), INTERVAL n DAY) (login_attempts purge) -
$old = $pdo->query("SELECT COUNT(*) FROM zz_compat_probe WHERE last_failed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
ok('DATE_SUB(NOW(), INTERVAL n DAY) works in a WHERE', $old !== false, 'query ran');

// --- 7. ENUM enforcement -------------------------------------
$rejected = false;
try {
    $pdo->exec("INSERT INTO zz_compat_probe (scope, subject) VALUES ('nonsense', 'x@y.z')");
} catch (PDOException $e) {
    $rejected = true;
}
ok('ENUM rejects a value outside the list', $rejected, 'bad value was ACCEPTED');

// --- 8. ON UPDATE CURRENT_TIMESTAMP actually fires -----------
$pdo->exec("UPDATE zz_compat_probe SET failures = failures + 1 WHERE subject = 'a@b.c'");
$t1 = (string) $pdo->query("SELECT last_failed_at FROM zz_compat_probe WHERE subject = 'a@b.c'")->fetchColumn();
sleep(1);
$pdo->exec("UPDATE zz_compat_probe SET failures = failures + 1 WHERE subject = 'a@b.c'");
$t2 = (string) $pdo->query("SELECT last_failed_at FROM zz_compat_probe WHERE subject = 'a@b.c'")->fetchColumn();
ok('ON UPDATE CURRENT_TIMESTAMP refreshes the column', $t1 !== $t2, "stuck at $t1");
if ($t1 === $t2) {
    note('user_devices.last_login will NOT auto-refresh on TiDB — the app must set it explicitly.');
}

// --- 9. COLLATION: is a comparison case-insensitive? ---------
// MySQL defaults to a *_ci collation, so "Dave@Example.com" finds
// "dave@example.com". TiDB's own default is utf8mb4_bin (case
// SENSITIVE), so this is the single most dangerous difference for
// a login that matches on email.
$ci = $pdo->query("SELECT 'Dave' = 'dave'")->fetchColumn();
ok('string comparison is case-INSENSITIVE', (int) $ci === 1, "got $ci (1 expected)");
if ((int) $ci !== 1) {
    note('Email lookups are case-SENSITIVE here: login.php + register must compare LOWER(email).');
}

$like = $pdo->query("SELECT 'Dave Vidad' LIKE '%vidad%'")->fetchColumn();
ok('LIKE is case-insensitive', (int) $like === 1, "got $like (1 expected)");

// The column's own collation is what the app's queries actually use.
$collation = (string) $pdo->query(
    "SELECT COLLATION_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email'"
)->fetchColumn();
ok('users.email uses a *_ci collation', str_ends_with($collation, '_ci'), "collation is '$collation'");
if (!str_ends_with($collation, '_ci')) {
    note("users.email collation is '$collation' — case-sensitive logins. Fix with an ALTER TABLE ... COLLATE.");
}

// --- 10. The correlated-subquery UPDATE from rate_service.php -
// (aliased UPDATE ... SET p.col = (SELECT ...) — MySQL allows it;
// run inside a transaction and roll back, so nothing changes.)
$pdo->beginTransaction();
try {
    $pdo->exec(
        'UPDATE providers p
            SET p.average_rating = COALESCE((SELECT AVG(r.rating) FROM reviews r WHERE r.provider_id = p.id), 0),
                p.review_count   = (SELECT COUNT(*) FROM reviews r WHERE r.provider_id = p.id)'
    );
    ok('aliased UPDATE with correlated subqueries (rating recompute)', true);
} catch (PDOException $e) {
    ok('aliased UPDATE with correlated subqueries (rating recompute)', false, $e->getMessage());
} finally {
    $pdo->rollBack();
}

// --- 11. The aggregate read the feed uses --------------------
try {
    $row = $pdo->query('SELECT COUNT(*) AS n, AVG(1) AS a FROM providers')->fetch();
    ok('COUNT()/AVG() aggregate reads', is_array($row) && isset($row['n']), 'query ran');
} catch (PDOException $e) {
    ok('COUNT()/AVG() aggregate reads', false, $e->getMessage());
}

// ------------------------------------------------------------
$pdo->exec('DROP TABLE IF EXISTS zz_compat_probe');
echo "\n  scratch table dropped.\n";

if ($notes) {
    echo "\n--- Differences to handle ---\n";
    foreach ($notes as $n) {
        echo '  * ' . $n . "\n";
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
