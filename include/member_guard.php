<?php
// ============================================================
// member_guard.php — what a blocked account cannot do
//
// A superadmin can block an ACCOUNT from the report queue (see
// admin/action.php, do=block_account). A block that means nothing
// in the member app would just be a note, so it is felt in three
// places:
//
//   1. Signing in — refused by login.php, and only after the
//      password is proved, so the form never tells a stranger
//      which addresses exist.
//   2. A session already open — refused here, on the next request.
//   3. Their listings — gone from every public read, which is a
//      query-level rule (see include/listing_visibility.php) and not
//      something this file does.
//
// (2) is the reason this file exists, and the reason it is called
// from include/db.php rather than from the pages: every
// authenticated page reaches the database connection, while the
// session guard itself is written out by hand on twenty pages — and
// twenty hand-written guards is twenty chances to forget one. The
// check is a single primary-key lookup, and it happens before the
// page has read its POST body, so a blocked account cannot save,
// message or hire anything on its way out the door.
// ============================================================

/**
 * isla_account_blocked(?array $user): bool
 * Is this users row blocked? Missing keys and NULLs read as 'active',
 * which is what keeps a row loaded before the column existed — or by a
 * query that did not select it — from being refused by accident.
 *
 * @param array|null $user A users row (or null for "no such account").
 * @return bool
 */
function isla_account_blocked(?array $user): bool
{
    return $user !== null && (string) ($user['status'] ?? 'active') === 'blocked';
}

/**
 * isla_refuse_blocked_member(PDO $pdo): void
 * Ends the session of a member whose account has been blocked, and
 * sends them to the sign-in screen. Does nothing for an anonymous
 * visitor, for an admin session, or when the status cannot be read.
 *
 * Called from include/db.php on every request; never returns when it
 * decides to refuse.
 *
 * @param PDO $pdo The shared connection.
 * @return void
 */
function isla_refuse_blocked_member(PDO $pdo): void
{
    // A member session only. The admin check matters: an operator who
    // also happens to be a blocked member must not be thrown out of the
    // panel they are moderating from.
    if (empty($_SESSION['user_id']) || !empty($_SESSION['admin_id'])) {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT status, blocked_reason FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $_SESSION['user_id']]);
        $current = $stmt->fetch();
    } catch (PDOException $e) {
        // The column is missing (a schema check that could not run), or
        // the row cannot be read. Let the page continue: refusing a
        // signed-in member over a schema problem would be worse than
        // the problem.
        return;
    }

    if (!isla_account_blocked(is_array($current) ? $current : null)) {
        return;                     // no such row: the page's own guard handles it
    }

    // Out. The whole session goes, not just the identity: everything in
    // it belongs to an account that is no longer allowed to act.
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $p['path'],
            $p['domain'],
            (bool) $p['secure'],
            (bool) $p['httponly']
        );
    }
    session_destroy();

    // A query flag rather than a session message, because the session it
    // would have travelled in is the one just destroyed. The reason is
    // NOT put in the URL: anyone can request ?blocked=1, and why an
    // account was closed is between the panel and its owner — the owner
    // still reads it when they try to sign in.
    header('Location: ' . sid_append('login.php?blocked=1'));
    exit;
}
