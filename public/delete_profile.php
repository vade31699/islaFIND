<?php
// ============================================================
// delete_profile.php — remove one of your islaFIND profiles
// Lets a user permanently delete ONE of their own provider
// listings (a user may own several). The handler:
//   1. Refuses everything except a POST carrying a valid CSRF
//      token (a plain GET link can never delete anything)
//   2. Loads the profile by id and verifies it belongs to the
//      logged-in user — you can only delete your own listings
//   3. Deletes the provider row (interactions, reviews and the album
//      are removed automatically by ON DELETE CASCADE) together with
//      the picture files the listing owned — all of it by the ONE
//      shared purge in include/purge.php, which the account deletion
//      in dashboard.php runs too. The shared account avatar is NOT
//      touched: every other listing still uses it.
//   4. Redirects back to the dashboard with a confirmation
// ============================================================

// --- 1. Harden the session cookie, then start the session ------
require_once __DIR__ . '/../include/security.php';
session_harden();
session_start();

// --- 2. Session guard: logged in? ------------------------------
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 3. Database connection -------------------------------------
require_once __DIR__ . '/../include/db.php';

// --- 4. Load the current user -----------------------------------
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_unset();
    session_destroy();
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 5. Flash helper: message + return to the isla panel --------
function islaFlash(string $type, string $msg): void
{
    $_SESSION['flash_isla'] = ['type' => $type, 'msg' => $msg];
    header('Location: ' . sid_append('dashboard.php?tab=isla'));
    exit;
}

// --- 6. Method + CSRF guard -------------------------------------
// A forged cross-site request cannot know the session token, so
// anything that is not a valid POST is bounced with an error.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    islaFlash('error', 'Your session expired or the form token is invalid. Please try again.');
}

// --- 7. Locate the target profile + check ownership -------------
$profileId = isla_post_int($_POST['profile_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM providers WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $profileId]);
$profile = $stmt->fetch();

if (!$profile) {
    islaFlash('error', 'That profile no longer exists.');
}
if ((int) $profile['user_id'] !== (int) $user['id']) {
    // Only the owner may delete a listing.
    islaFlash('error', 'You can only delete your own profiles.');
}

// --- 8. Delete the listing and EVERY trace of it ----------------
// include/purge.php owns the whole rule, because the account
// path in dashboard.php needs exactly the same thing and the two
// must not drift: the cover and album files go first (their names
// stop being readable the moment the row does), then the row —
// and ON DELETE CASCADE takes the album rows, the interactions,
// the reviews, the saves, the inquiries and the reports with it.
//
// The shared ACCOUNT avatar is NOT touched: every other listing of
// this person is still using it. Chats and jobs belong to the
// account, not to this listing, so they stay.
require_once __DIR__ . '/../include/purge.php';

$deleted = false;

try {
    isla_listing_purge($pdo, (int) $profile['id']);
    $deleted = true;
} catch (PDOException $e) {
    // Nothing was removed, so the banner below must NOT claim it was.
    error_log('islaFIND listing deletion failed: ' . $e->getMessage());
    islaFlash('error', 'We could not delete that profile just now. Please try again.');
}

if (!$deleted) {
    header('Location: ' . sid_append('dashboard.php?tab=isla'));
    exit;
}

// --- 9. Confirm + redirect -------------------------------------
// NOTE: no htmlspecialchars() here — the flash is escaped ONCE at the
// point where dashboard.php prints it. Escaping in both places would
// make an apostrophe show up as "&#039;" in the banner.
islaFlash('success', 'Your islaFIND profile "' . $profile['name'] . '" was deleted.');
