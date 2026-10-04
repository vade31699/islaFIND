<?php
// ============================================================
// purge.php — what "deleted" is allowed to mean
//
// Two things can be deleted in this app: ONE listing
// (public/delete_profile.php) and a WHOLE ACCOUNT (the danger
// zone in public/dashboard.php). Both are promises made to the
// person doing the deleting — "your profile is gone", "your
// account is gone" — and a promise kept halfway is worse than
// one never made, because what is left behind is still there:
// a device row holding an IP address, a throttle row holding
// the email of an account nobody owns any more, a picture in
// uploads/ that no row points at and no code will ever find
// again.
//
// THE ROWS. Every table that points at users or providers
// carries ON DELETE CASCADE (final_app.sql), so one DELETE
// settles the arithmetic: the listings, the album, the reviews,
// the saves, the interactions, the inquiries, the reports, the
// chats, the messages and the contracts all go with their
// parent. Exactly TWO tables are not foreign-keyed, so a
// cascade cannot reach them, and both are cleared by hand HERE:
//
//   user_devices    device history — device name, IP, session id
//   login_attempts  the sign-in throttle, keyed by email or IP
//
// They are here, in one file, because the alternative is the
// rule living in whichever handler remembered to write it: the
// account path cleared one of them, the listing path could not
// clear either, and the database slowly filled up with rows
// belonging to people who no longer existed.
//
// THE FILES. A row stores a FILENAME, never the bytes. Once the
// row is gone the name is unreadable, so the files are removed
// FIRST — best-effort, through the storage seam, because a file
// that has already vanished must never block the database change
// that follows.
//
// A filesystem and a database cannot share a transaction, so the
// order is fixed and deliberate: files first (best-effort,
// unlosable), rows second (atomic). If the row deletion then
// fails, what is left is a card with no picture — the same state
// the app has always rendered — rather than a database full of
// files nothing can reach.
// ============================================================

require_once __DIR__ . '/uploads.php';

/**
 * isla_purge_counters()
 * The shape both purges return, so a caller (or a test) can see
 * what was actually removed instead of trusting that it was.
 *
 * @return array<string,int>
 */
function isla_purge_counters(): array
{
    return ['files' => 0, 'listings' => 0, 'devices' => 0, 'throttle' => 0, 'rows' => 0];
}

/**
 * isla_purge_listing_files()
 * Every file ONE listing owns: its own cover plus its album.
 *
 * Read while the rows still exist, because afterwards the names
 * are gone. A LEFT JOIN, so a listing with no album still yields
 * its cover (one row, album_pic NULL) and one with an album
 * yields every picture; the empty checks skip the NULLs.
 *
 * NOT the account avatar: a listing without its own picture falls
 * back to the avatar, which every other listing of that person
 * is still using, so it leaves with the ACCOUNT, not the listing.
 *
 * @param PDO $pdo        Live connection.
 * @param int  $providerId providers.id.
 * @return string[] Stored filenames, de-duplicated.
 */
function isla_purge_listing_files(PDO $pdo, int $providerId): array
{
    if ($providerId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT p.profile_picture AS cover_pic, a.image_name AS album_pic
           FROM providers p
           LEFT JOIN provider_album_images a ON a.provider_id = p.id
          WHERE p.id = :id'
    );
    $stmt->execute([':id' => $providerId]);

    $files = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!empty($row['cover_pic'])) {
            $files[] = (string) $row['cover_pic'];
        }
        if (!empty($row['album_pic'])) {
            $files[] = (string) $row['album_pic'];
        }
    }

    return array_values(array_unique($files));
}

/**
 * isla_listing_purge()
 * Removes ONE listing and every trace of it: the files it owned,
 * then the row. Reviews, saves, interactions, inquiries, reports
 * and album rows go with the row by cascade (fk_*_provider).
 *
 * Chats and contracts belong to the ACCOUNT behind the listing,
 * not to the listing, so they stay — the person keeps their job
 * history when they take one listing down.
 *
 * @param PDO $pdo        Live connection.
 * @param int  $providerId providers.id.
 * @return array<string,int> Counters ('files', 'listings', 'rows').
 * @throws PDOException   Re-thrown unchanged, so the caller decides
 *                        what the visitor is told.
 */
function isla_listing_purge(PDO $pdo, int $providerId): array
{
    $counters = isla_purge_counters();
    $providerId = (int) $providerId;

    if ($providerId <= 0) {
        return $counters;
    }

    // Files first: the names stop being readable the moment the row
    // goes, and these are best-effort removals.
    foreach (isla_purge_listing_files($pdo, $providerId) as $file) {
        isla_upload_delete($file);
        $counters['files']++;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM providers WHERE id = :id');
    $stmt->execute([':id' => $providerId]);
    $counters['listings'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('DELETE FROM providers WHERE id = :id');
    $stmt->execute([':id' => $providerId]);
    $counters['rows'] = $stmt->rowCount();

    return $counters;
}

/**
 * isla_account_purge()
 * Removes a whole ACCOUNT and every trace of it: the avatar,
 * every listing's cover and album, the device history, the email
 * address still sitting in the sign-in throttle, and finally the
 * user row itself — which cascades the listings, album, reviews,
 * saves, interactions, inquiries, reports, chats, messages and
 * contracts.
 *
 * Both gates are the caller's: by the time this runs the caller
 * has already proved the password and the typed word DELETE.
 *
 * The throttle row is the one nobody remembers. login_attempts
 * has no foreign key (its "subject" is sometimes an email and
 * sometimes an IP), so deleting the account leaves its EMAIL
 * ADDRESS in the database — the personal detail of somebody who
 * asked to be forgotten, still queryable. Only the 'account'
 * scope row is removed: an 'ip' row belongs to the address, not
 * to the person, and clearing it would hand a bot a free retry.
 *
 * @param PDO $pdo    Live connection.
 * @param int  $userId users.id.
 * @return array<string,int> Counters ('files', 'listings', 'devices', 'throttle', 'rows').
 * @throws PDOException   Re-thrown unchanged; the row deletion rolls back first.
 */
function isla_account_purge(PDO $pdo, int $userId): array
{
    $counters = isla_purge_counters();
    $userId = (int) $userId;

    if ($userId <= 0) {
        return $counters;
    }

    // Read the account while it still exists: its avatar file and
    // its email address are the two things the rows below will take
    // with them.
    //
    // Note the TWO ids. providers.user_id and users.id are the same
    // number, but user_devices.user_id is users.user_id -- the
    // public "ISLA-xxxxxx" number the app hands out, which is what
    // login.php and dashboard.php write and read. Purging with the
    // primary key alone would leave every device row behind, so both
    // are needed.
    $stmt = $pdo->prepare('SELECT email, profile_picture, user_id FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        return $counters;
    }

    // --- 1. Files, while every name is still readable -----------
    if (!empty($user['profile_picture'])) {
        isla_upload_delete((string) $user['profile_picture']);
        $counters['files']++;
    }

    $stmt = $pdo->prepare(
        'SELECT p.profile_picture AS cover_pic, a.image_name AS album_pic
           FROM providers p
           LEFT JOIN provider_album_images a ON a.provider_id = p.id
          WHERE p.user_id = :uid'
    );
    $stmt->execute([':uid' => $userId]);

    foreach ($stmt->fetchAll() as $row) {
        if (!empty($row['cover_pic'])) {
            isla_upload_delete((string) $row['cover_pic']);
            $counters['files']++;
        }
        if (!empty($row['album_pic'])) {
            isla_upload_delete((string) $row['album_pic']);
            $counters['files']++;
        }
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM providers WHERE user_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $counters['listings'] = (int) $stmt->fetchColumn();

    // --- 2. Rows, in one transaction ----------------------------
    // The two tables no foreign key reaches are cleared by hand, so
    // the promise is the same whoever runs the purge.
    $own = $pdo->inTransaction();
    if (!$own) {
        $pdo->beginTransaction();
    }

    try {
        // user_devices.user_id holds users.user_id, not users.id.
        $stmt = $pdo->prepare('DELETE FROM user_devices WHERE user_id = :pk OR user_id = :custom');
        $stmt->execute([':pk' => $userId, ':custom' => (int) $user['user_id']]);
        $counters['devices'] = $stmt->rowCount();

        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE scope = 'account' AND subject = :email");
        $stmt->execute([':email' => (string) $user['email']]);
        $counters['throttle'] = $stmt->rowCount();

        $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $counters['rows'] = $stmt->rowCount();

        if (!$own) {
            $pdo->commit();
        }
    } catch (PDOException $e) {
        if (!$own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $counters;
}

/**
 * isla_purge_orphans()
 * The sweep for rows and files left behind BEFORE this file
 * existed — by a test that deleted an account with raw SQL, by a
 * hand-run DELETE in a console, by a restore of an old dump. It
 * only ever removes what can no longer belong to anybody: a device
 * row whose account is gone, an uploaded picture no row points at.
 *
 * IT DELIBERATELY LEAVES login_attempts ALONE except for the
 * reserved documentation domains (example.com/.org/.net/.invalid),
 * which no real account can ever own. A throttle row for an
 * address that has no account is usually a BOT being throttled;
 * deleting it because the address is unknown would hand that bot a
 * free run at the login form. An account that really existed has its
 * throttle row removed by isla_account_purge(), where the account's
 * existence is not in doubt.
 *
 * Safe to run at any time; it cannot touch a row that still has a
 * parent. Run it after any bulk delete done outside the app:
 *   php -r 'require "include/db_settings.php"; print_r(isla_purge_orphans(isla_db_pdo()));'
 *
 * @param PDO $pdo Live connection.
 * @return array<string,int> Counters ('files', 'devices', 'throttle').
 */
function isla_purge_orphans(PDO $pdo): array
{
    $counters = isla_purge_counters();

    // 1. Device rows whose account no longer exists. No foreign key
    //    (a device can be listed before its owner signs in), so a
    //    deleted account would otherwise leave its whole device
    //    history — names and IP addresses — behind forever.
    //
    //    The join is on users.user_id, NOT users.id: that is the
    //    column login.php writes and dashboard.php reads. Joining on
    //    the primary key matches almost nothing and the sweep would
    //    throw away the device history of every live account.
    $counters['devices'] = (int) $pdo->exec(
        'DELETE d FROM user_devices d
           LEFT JOIN users u ON u.user_id = d.user_id
          WHERE u.user_id IS NULL'
    );

    // 2. Throttle rows for addresses that can never be an account.
    $counters['throttle'] = (int) $pdo->exec(
        "DELETE FROM login_attempts
          WHERE scope = 'account'
            AND (subject LIKE '%@example.invalid'
                 OR subject LIKE '%@example.com'
                 OR subject LIKE '%@example.org'
                 OR subject LIKE '%@example.net')"
    );

    // 3. Uploaded pictures no row points at. Only files this app
    //    could have written are considered: the name carries the
    //    owner id, so an orphan is provably the picture of a row
    //    that is gone. Anything else in uploads/ (.htaccess, a
    //    README, a file somebody dropped in by hand) is left alone.
    $referenced = [];
    foreach ([
        'SELECT profile_picture FROM users',
        'SELECT profile_picture FROM providers',
        'SELECT image_name FROM provider_album_images',
    ] as $sql) {
        foreach ($pdo->query($sql) as $row) {
            $name = (string) reset($row);
            if ($name !== '') {
                $referenced[basename($name)] = true;
            }
        }
    }

    $dir = isla_uploads_dir();
    foreach (scandir($dir) ?: [] as $name) {
        if (isset($referenced[$name])) {
            continue;
        }
        if (!preg_match('/^(user|listing)_\d+_[0-9a-f]{16}\.(jpe?g|png)$/i', $name)) {
            continue;
        }
        if (!is_file($dir . '/' . $name)) {
            continue;
        }
        isla_upload_delete($name);
        $counters['files']++;
    }

    return $counters;
}