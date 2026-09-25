<?php
// ============================================================
// _import_sql.php — schema importer (developer / deployment tool)
// Creates the app's database and tables on whatever MySQL server
// .env points at — local WAMP, or a managed MySQL such as TiDB
// Cloud Serverless, which refuses plaintext connections and so
// needs DB_SSL_CA set (see db.php, section 3b).
//
//     php _import_sql.php                  (imports final_app.sql)
//     php _import_sql.php dump.sql         (imports another file)
//     php _import_sql.php --dry-run        (parse + list, touch nothing)
//
// WHY THIS EXISTS INSTEAD OF `mysql < final_app.sql`
//   1. A managed MySQL has no shell to run that client in, so the
//      import has to be possible from a checkout and from a CI or
//      platform "command" runner.
//   2. PDO cannot execute a multi-statement script at all, and
//      mysqli's multi_query() aborts the REST of the file on the
//      first failure — which turns one bad line into an opaque
//      "nothing happened".
//   So every statement is split out and run on its own, and each
//   result (or error) is printed. One failure never hides the rest,
//   and the failing statement is shown verbatim.
//
// Safe to re-run: every statement in the dump is CREATE ... IF NOT
// EXISTS, so an existing database is left exactly as it is.
//
// COMMAND LINE ONLY. It writes DDL, so a browser hit must never
// reach it: web requests are answered with a 404 before anything
// below runs (the same guard _smtp_test.php uses).
//
// Exit code 0 = every statement ran, 1 = at least one failed.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/env.php';

// ------------------------------------------------------------
// split_sql()
// Splits a script into individual statements on the ';' characters
// that are REALLY statement terminators, ignoring ones inside
// 'string' / "string" / `identifier` literals and inside comments.
//
// This is not academic: final_app.sql carries prose comments such
// as "-- service_contracts (hired jobs; rating eligibility)", so a
// naive explode(';') would cut a line of documentation in half and
// feed the fragments to the server.
//
// @param string $sql Raw file contents.
// @return string[] Statements, in file order, comments stripped.
// ------------------------------------------------------------
function split_sql(string $sql): array
{
    $statements = [];
    $current    = '';
    $length     = strlen($sql);
    $quote      = '';       // the quote character we are inside, or ''
    $in_line    = false;    // inside a -- or # comment
    $in_block   = false;    // inside a /* */ comment

    for ($i = 0; $i < $length; $i++) {
        $ch   = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        // --- inside a comment: copy nothing, keep the newline ------
        if ($in_line) {
            if ($ch === "\n") {
                $in_line  = false;
                $current .= $ch;            // keeps statements readable
            }
            continue;
        }
        if ($in_block) {
            if ($ch === '*' && $next === '/') {
                $in_block = false;
                $i++;                       // skip the '/'
            }
            continue;
        }

        // --- inside a string / identifier literal ------------------
        if ($quote !== '') {
            $current .= $ch;
            if ($ch === '\\' && $quote !== '`') {
                if ($next !== '') {         // escaped char: copy as-is
                    $current .= $next;
                    $i++;
                }
                continue;
            }
            if ($ch === $quote) {
                $quote = '';
            }
            continue;
        }

        // --- not inside anything: comment or quote start? ----------
        // MySQL only treats '--' as a comment when followed by
        // whitespace, which is why the third character is checked.
        if ($ch === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
            $in_line = true;
            $i++;                           // skip the second '-'
            continue;
        }
        if ($ch === '#') {
            $in_line = true;
            continue;
        }
        if ($ch === '/' && $next === '*') {
            $in_block = true;
            $i++;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote    = $ch;
            $current .= $ch;
            continue;
        }

        // --- a real statement terminator ---------------------------
        if ($ch === ';') {
            $statement = trim($current);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    // A final statement without its ';' still counts.
    $tail = trim($current);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

// ------------------------------------------------------------
// statement_label()
// A one-line name for a statement, for the progress log. DDL is
// long (a CREATE TABLE runs 40 lines), so the log prints the verb
// and the object instead of 40 lines of columns.
// ------------------------------------------------------------
function statement_label(string $statement): string
{
    $flat = preg_replace('/\s+/', ' ', trim($statement)) ?? $statement;

    if (preg_match('/^CREATE\s+DATABASE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([A-Za-z0-9_]+)`?/i', $flat, $m)) {
        return 'CREATE DATABASE ' . $m[1];
    }
    if (preg_match('/^CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([A-Za-z0-9_]+)`?/i', $flat, $m)) {
        return 'CREATE TABLE ' . $m[1];
    }
    if (preg_match('/^USE\s+`?([A-Za-z0-9_]+)`?/i', $flat, $m)) {
        return 'USE ' . $m[1];
    }

    // Anything else: the first 60 characters are enough to identify it.
    return strlen($flat) > 60 ? substr($flat, 0, 57) . '...' : $flat;
}

// ------------------------------------------------------------
// 1. Arguments
// ------------------------------------------------------------
$dryRun = in_array('--dry-run', $argv, true);
$file   = __DIR__ . '/final_app.sql';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg !== '--dry-run') {
        $file = $arg;
    }
}

if (!is_file($file)) {
    fwrite(STDERR, "SQL file not found: $file\n");
    exit(1);
}

$sql = file_get_contents($file);
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "Could not read (or the file is empty): $file\n");
    exit(1);
}

$statements = split_sql($sql);

// ------------------------------------------------------------
// 2. Parse report (also the whole of --dry-run)
// ------------------------------------------------------------
echo "--- Script ---\n";
echo 'file:       ' . $file . "\n";
echo 'statements: ' . count($statements) . "\n\n";

foreach ($statements as $n => $statement) {
    echo '  ' . str_pad((string) ($n + 1), 3, ' ', STR_PAD_LEFT) . '. ' . statement_label($statement) . "\n";
}

if ($dryRun) {
    echo "\n--dry-run: nothing was sent to the server.\n";
    exit(0);
}

// ------------------------------------------------------------
// 3. Connection (never prints the password)
// ------------------------------------------------------------
$host   = env('DB_HOST', 'localhost');
$port   = (int) env('DB_PORT', '3306');
$user   = env('DB_USER', 'root');
$pass   = env('DB_PASS', '');
$name   = env('DB_NAME', 'final_app');
$ssl_ca = env('DB_SSL_CA', '');

// Same path rule as db.php: relative values resolve from the root.
$ca_file = '';
if ($ssl_ca !== '') {
    $ca_file = preg_match('#^([A-Za-z]:[\\\\/]|/)#', $ssl_ca) === 1
        ? $ssl_ca
        : __DIR__ . '/' . $ssl_ca;
    if (!is_file($ca_file)) {
        fwrite(STDERR, "DB_SSL_CA is set but no certificate was found at $ca_file\n");
        exit(1);
    }
}

echo "\n--- Connection ---\n";
echo 'server: ' . $host . ':' . $port . "\n";
echo 'user:   ' . $user . ' (password ' . ($pass !== '' ? 'set' : 'empty') . ")\n";
echo 'tls:    ' . ($ca_file !== '' ? "on, CA $ca_file" : 'off (plaintext, DB_SSL_CA empty)') . "\n";

$link = mysqli_init();
if ($link === false) {
    fwrite(STDERR, "mysqli_init() failed — is the mysqli extension enabled?\n");
    exit(1);
}

if ($ca_file !== '') {
    mysqli_ssl_set($link, null, null, $ca_file, null, null);
}

// NOTE: the database name is deliberately NOT passed here. The dump
// creates it (CREATE DATABASE IF NOT EXISTS) and then USEs it, so
// connecting to it up front would fail on a fresh server — exactly
// the case this script exists for.
$connected = @mysqli_real_connect(
    $link,
    $host,
    $user,
    $pass,
    null,
    $port,
    null,
    $ca_file !== '' ? MYSQLI_CLIENT_SSL : 0
);

if (!$connected) {
    fwrite(STDERR, "\nConnection FAILED: " . mysqli_connect_error() . "\n");
    exit(1);
}

echo "connected.\n";

// ------------------------------------------------------------
// 4. Run every statement, one at a time
// ------------------------------------------------------------
echo "\n--- Import ---\n";

$failed = 0;
foreach ($statements as $n => $statement) {
    $label = statement_label($statement);

    if (@mysqli_query($link, $statement)) {
        echo '  OK    ' . $label . "\n";
        continue;
    }

    $failed++;
    echo '  FAIL  ' . $label . "\n";
    echo '        ' . mysqli_error($link) . "\n";
    // The first line of the statement is enough to locate the problem
    // in the file without dumping 40 lines of columns into the log.
    echo '        statement starts: ' . strtok($statement, "\n") . "\n";
}

// ------------------------------------------------------------
// 5. What actually landed
// ------------------------------------------------------------
$tables = [];
$result = @mysqli_query($link, 'SHOW TABLES');
if ($result) {
    while ($row = mysqli_fetch_row($result)) {
        $tables[] = $row[0];
    }
    mysqli_free_result($result);
}

echo "\n--- Result ---\n";
if ($tables === []) {
    echo "no tables found on the connection's current database\n";
} else {
    echo count($tables) . ' table(s): ' . implode(', ', $tables) . "\n";
}

mysqli_close($link);

echo "\n=== " . ($failed === 0
    ? 'all ' . count($statements) . ' statement(s) ran'
    : $failed . ' of ' . count($statements) . ' statement(s) FAILED') . " ===\n";

exit($failed === 0 ? 0 : 1);
