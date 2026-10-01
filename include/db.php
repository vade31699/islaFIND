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
 * built: saved_listings (the bookmarked-listings heart),
 * login_attempts (the server-side failed-login counters),
 * provider_album_images (a business listing's photo album),
 * admins (the superadmin accounts) and profile_reports (the listing
 * report queue), none of which existed in the original schema dump.
 * It also adds the providers columns those features need:
 * profile_picture (the listing's own picture), profile_code (the
 * public IslaProfile ID) and status / blocked_at / blocked_reason
 * (moderation state).
 *
 * Why here instead of only in final_app.sql: an existing deployment
 * must not have to re-import the dump to use a new feature. Every
 * statement is CREATE TABLE IF NOT EXISTS, so it is a no-op once the
 * table exists, and the once-per-session guard below (or the plain
 * call from a CLI script) keeps even that no-op off the hot path of
 * every request. Every ALTER is preceded by an information_schema
 * probe, because none of them can be written idempotently.
 *
 * A failure here must NOT take the whole app down, so errors are
 * logged and swallowed — the only consequence is that the bookmarks
 * feature reports "unavailable" instead of crashing every page, that
 * login throttling reads an empty table (which the throttle helpers
 * treat as "no failures yet"), that a listing's own picture
 * column is missing, which leaves every card on the account avatar
 * it already used, and that the superadmin login cannot match any
 * account (which fails CLOSED — see include/admin_auth.php).
 *
 * @param PDO $pdo Live connection created just above.
 * @return void
 */

/**
 * isla_schema_has_column(PDO $pdo, string $table, string $column): bool
 * Does this table already have this column? The information_schema probe
 * that makes an ALTER safe to re-run.
 *
 * WHY IT EXISTS: MySQL has no `ADD COLUMN IF NOT EXISTS` (MariaDB has it,
 * TiDB does not), so a plain ALTER would throw "Duplicate column name" the
 * second time it ran — and isla_ensure_schema() runs on every request until
 * the once-per-session guard is set. Asking the server first is the only
 * portable way to make the ALTER idempotent.
 *
 * @param PDO    $pdo    Connection to inspect.
 * @param string $table  Table name.
 * @param string $column Column name.
 * @return bool TRUE when the column is already there.
 */
function isla_schema_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = :t
            AND COLUMN_NAME  = :c
          LIMIT 1'
    );
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (bool) $stmt->fetchColumn();
}

/**
 * isla_schema_has_index(PDO $pdo, string $table, string $index): bool
 * Same question for an index. Kept beside isla_schema_has_column() so the
 * one rule of this file is obvious: every ALTER is preceded by a probe.
 *
 * @param PDO    $pdo    Connection to inspect.
 * @param string $table  Table name.
 * @param string $index  Index name.
 * @return bool TRUE when the index is already there.
 */
function isla_schema_has_index(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = :t
            AND INDEX_NAME   = :i
          LIMIT 1'
    );
    $stmt->execute([':t' => $table, ':i' => $index]);
    return (bool) $stmt->fetchColumn();
}

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

        // provider_album_images holds a BUSINESS listing's photo album
        // (see final_app.sql). Same reasoning as the two tables above:
        // the dump is the schema of record, this keeps an existing
        // database in step with it without a re-import.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS provider_album_images (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                provider_id INT UNSIGNED NOT NULL,
                image_name  VARCHAR(255) NOT NULL,
                sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_album_provider (provider_id, sort_order, id),
                CONSTRAINT fk_album_provider FOREIGN KEY (provider_id)
                    REFERENCES providers (id) ON DELETE CASCADE
            ) ENGINE = InnoDB'
        );

        // providers.profile_picture is a COLUMN, not a table, so it
        // needs the information_schema probe before the ALTER: a plain
        // ADD COLUMN would throw on every request after the first one
        // ("Duplicate column name"), and this runs once per session.
        // A NULL value is the point of the column — NULL means "this
        // listing has no picture of its own yet" and every card falls
        // back to the account avatar, so no existing row changes.
        $hasListingPic = (bool) $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'providers'
                AND COLUMN_NAME  = 'profile_picture'
              LIMIT 1"
        )->fetchColumn();

        if (!$hasListingPic) {
            $pdo->exec('ALTER TABLE providers ADD COLUMN profile_picture VARCHAR(255) NULL AFTER name');
        }

        // ------------------------------------------------------------------
        // admins — the islaFIND superadmin accounts
        //
        // Deliberately NOT created with a seeded account: there is no row
        // here until someone runs `php tools/create_admin.php`, which asks
        // for the password and hashes it (see that file). Shipping a fixed
        // superadmin password in the repository would hand every deployment
        // of this app the same working key.
        //
        // mfa_enabled defaults to 1 (a code is emailed on every login). The
        // safest setting is the default one, so a half-finished setup is
        // still a protected one.
        // ------------------------------------------------------------------
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admins (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                email         VARCHAR(190) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                full_name     VARCHAR(120) NULL,
                mfa_enabled   TINYINT(1)   NOT NULL DEFAULT 1,
                is_active     TINYINT(1)   NOT NULL DEFAULT 1,
                last_login_at TIMESTAMP    NULL DEFAULT NULL,
                last_login_ip VARCHAR(45)  NULL,
                created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                  ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_admins_email (email)
            ) ENGINE = InnoDB'
        );

        // ------------------------------------------------------------------
        // profile_reports — a member's report about a listing
        //
        // provider_id is providers.id: the plan for this feature called the
        // column "isla_profile_id", but the listing table in this schema has
        // always been `providers`, and every other table that points at a
        // listing (saved_listings, provider_album_images, service_contracts)
        // calls it provider_id. Matching them keeps the joins obvious.
        //
        // reporter_id is the member who filed it. Both CASCADE, so deleting
        // a listing or a member takes their reports with them and the queue
        // can never show a report about a listing that no longer exists.
        //
        // There is NO unique key on (provider_id, reporter_id) on purpose:
        // "one open report per member per listing" has to be checked in
        // code, because the rule is about *open* ones — a member whose
        // report was dismissed must be able to report the same listing again
        // later, and a unique index would silently refuse that.
        // ------------------------------------------------------------------
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS profile_reports (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                provider_id     INT UNSIGNED NOT NULL,
                reporter_id     INT UNSIGNED NOT NULL,
                reason_code     VARCHAR(40)  NOT NULL,
                details         TEXT         NULL,
                evidence_image  VARCHAR(255) NULL,
                status          ENUM(\'pending\', \'resolved\', \'dismissed\')
                                    NOT NULL DEFAULT \'pending\',
                admin_notes     TEXT         NULL,
                listing_blocked TINYINT(1)   NOT NULL DEFAULT 0,
                resolved_by     INT UNSIGNED NULL,
                resolved_at     TIMESTAMP    NULL DEFAULT NULL,
                created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_reports_queue (status, created_at),
                KEY idx_reports_listing (provider_id, status),
                KEY idx_reports_reporter (reporter_id, id),
                CONSTRAINT fk_reports_listing FOREIGN KEY (provider_id)
                    REFERENCES providers (id) ON DELETE CASCADE,
                CONSTRAINT fk_reports_reporter FOREIGN KEY (reporter_id)
                    REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_reports_admin FOREIGN KEY (resolved_by)
                    REFERENCES admins (id) ON DELETE SET NULL
            ) ENGINE = InnoDB'
        );

        // ------------------------------------------------------------------
        // providers.profile_code — the public "IslaProfile ID"
        //
        // Every listing gets a stable reference an admin can quote over the
        // phone ("that's ISLA-000142"). It is derived from the primary key
        // (see isla_profile_code() in include/listing_ref.php), so it is
        // unique by construction and needs no extra sequence.
        //
        // NULL-able, which is what lets the column be added without touching
        // existing rows, and the backfill below fills them in.
        // ------------------------------------------------------------------
        if (!isla_schema_has_column($pdo, 'providers', 'profile_code')) {
            $pdo->exec('ALTER TABLE providers ADD COLUMN profile_code VARCHAR(20) NULL AFTER id');
        }

        // Backfill anything without a code. Self-limiting (only NULL/empty
        // rows match) and idempotent, so it is also the repair step for a run
        // that was interrupted between the ALTER and the backfill.
        if ($pdo->query(
            "SELECT 1 FROM providers WHERE profile_code IS NULL OR profile_code = '' LIMIT 1"
        )->fetchColumn()) {
            $pdo->exec(
                "UPDATE providers
                    SET profile_code = CONCAT('ISLA-', LPAD(id, 6, '0'))
                  WHERE profile_code IS NULL OR profile_code = ''"
            );
        }

        // Unique last, once no row is left NULL. (A unique index tolerates
        // NULLs, so this would have succeeded either way — ordering it here
        // keeps the invariant "no listing without a code" true.)
        if (!isla_schema_has_index($pdo, 'providers', 'uq_providers_profile_code')) {
            $pdo->exec('ALTER TABLE providers ADD UNIQUE KEY uq_providers_profile_code (profile_code)');
        }

        // ------------------------------------------------------------------
        // providers.status + blocked_* — moderation state
        //
        // 'active' is the default, so every existing row is live and nothing
        // disappears when these columns land. A blocked listing is hidden
        // from the feed, the catalogue, search and every other public query
        // (they all filter on status = 'active'); the owner still sees their
        // own listing with a "blocked" badge so the block is never a mystery.
        // ------------------------------------------------------------------
        if (!isla_schema_has_column($pdo, 'providers', 'status')) {
            $pdo->exec("ALTER TABLE providers ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER profile_type");
        }
        if (!isla_schema_has_column($pdo, 'providers', 'blocked_at')) {
            $pdo->exec('ALTER TABLE providers ADD COLUMN blocked_at TIMESTAMP NULL DEFAULT NULL');
        }
        if (!isla_schema_has_column($pdo, 'providers', 'blocked_reason')) {
            $pdo->exec('ALTER TABLE providers ADD COLUMN blocked_reason VARCHAR(255) NULL DEFAULT NULL');
        }
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
