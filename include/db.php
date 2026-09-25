<?php
// ============================================================
// db.php — Secure database connection (PDO)
// This single file is included by every other PHP page so the
// whole app talks to the same MySQL connection.
//
// Credentials are read from .env (see env.php / .env.example).
// The WAMP values are only FALLBACKS used when a key is missing,
// so a fresh checkout still runs without a .env file while a real
// deployment keeps its password out of the source.
//
// The DSN, the credentials and the TLS setup live in
// db_settings.php rather than here, because the session store
// needs the same connection details at a moment when this file has
// not run yet — session_start() happens before db.php on most
// pages. See that file for the TLS reasoning.
// ============================================================

// --- 1. One place knows how to connect -------------------------
// db_settings.php also includes env.php, so env() is available to
// every page from here on. Both are dependency-free and cheap.
require_once __DIR__ . '/db_settings.php';

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
 * table exists, and the once-per-session guard below (or the plain
 * call from a CLI script) keeps even that no-op off the hot path of
 * every request.
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

// --- 2. Try to open the connection ----------------------------
try {
    // The DSN, credentials and TLS options are built by
    // isla_db_pdo() (see db_settings.php). It throws PDOException on
    // failure, which the catch below turns into the app's screen.
    $pdo = isla_db_pdo();

    // --- 3. Self-healing schema ----------------------------------
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
