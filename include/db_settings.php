<?php
// ============================================================
// db_settings.php — the one place that knows how to reach MySQL
//
// db.php and session_store.php both need a PDO connection with the
// SAME credentials, the same utf8mb4 charset and the same TLS setup,
// but they need it at different moments. db.php connects as soon as
// a page includes it; the session store connects during
// session_start(), which on most pages happens FIRST — login.php
// starts its session and only includes the database ten lines later.
//
// That is why the connection is not built inside db.php any more:
// the session store cannot wait for db.php, and two copies of the
// TLS block would be two places for the subtlest part of the
// configuration to drift (see the TLS notes in .env.example — PDO
// has no "use TLS" switch, so a managed MySQL is only reached at all
// when the certificate is handed to the driver).
//
// Values come from the process environment first and .env second
// (see env.php), so a host can override any of them without editing
// a file.
// ============================================================

require_once __DIR__ . '/env.php';

/**
 * isla_db_pdo()
 * Opens a NEW PDO connection built from the environment.
 *
 * Returns the live connection, or throws PDOException — the caller
 * decides what a failed connection means. db.php turns it into the
 * app's "Database connection failed" screen; the session store lets
 * it reach PHP's own error handling, because a session that cannot
 * be read must not be presented as a working session.
 *
 * @return PDO
 */
function isla_db_pdo(): PDO
{
    // WAMP's defaults are the fallbacks, so a fresh checkout with no
    // .env still runs against a local MySQL.
    //
    // A host may inject its OWN names for the same values — Laravel
    // Cloud provisions a database and injects DB_DATABASE,
    // DB_USERNAME and DB_PASSWORD. Reading those as second choices
    // means attaching a database needs no copy-pasting of one set of
    // values into the other's variable names. The app's own names
    // still win, so .env stays authoritative wherever it is used.
    $host = env('DB_HOST', 'localhost');
    $port = env('DB_PORT', '3306');
    $name = env('DB_NAME', env('DB_DATABASE', 'final_app'));
    $user = env('DB_USER', env('DB_USERNAME', 'root'));
    $pass = env('DB_PASS', env('DB_PASSWORD', ''));

    // utf8mb4 guarantees full UTF-8 support (emoji, accents, etc.).
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    // TLS for a managed MySQL server. TiDB Cloud Serverless (and most
    // managed MySQL) REFUSE plaintext connections, and PDO ignores the
    // DSN's ssl-mode entirely — the certificate has to be handed to
    // the driver through these constants. Local WAMP needs none of it:
    // leave DB_SSL_CA empty (the default) and this block does nothing.
    $ca = env('DB_SSL_CA', '');
    if ($ca !== '') {
        // Absolute paths are used as-is; anything else is relative to
        // the PROJECT ROOT, so .env can say "certs/lets-encrypt-roots.pem"
        // and the same value keeps working from include/ or public/.
        $is_absolute = str_starts_with($ca, '/') || preg_match('#^[A-Za-z]:#', $ca) === 1;
        $ca_file     = $is_absolute ? $ca : dirname(__DIR__) . '/' . $ca;

        if (!is_file($ca_file)) {
            // Logged, never echoed: a missing certificate is about to
            // break the connection, and the visitor must not be shown
            // filesystem paths.
            error_log('islaFIND: DB_SSL_CA is set but no certificate was found at ' . $ca_file);
        }

        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca_file;

        // Verify the server's certificate (on unless DB_SSL_VERIFY=0).
        // Disabling it is only ever right for a throwaway test server:
        // without verification, TLS still encrypts but no longer proves
        // you are talking to the server you think you are.
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = env('DB_SSL_VERIFY', '1') !== '0';
    }

    return new PDO($dsn, $user, $pass, $options);
}
