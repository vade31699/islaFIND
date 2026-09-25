<?php
// ============================================================
// delete_profile.php — remove one of your islaFIND profiles
// Lets a user permanently delete ONE of their own provider
// listings (a user may own several). The handler:
//   1. Refuses everything except a POST carrying a valid CSRF
//      token (a plain GET link can never delete anything)
//   2. Loads the profile by id and verifies it belongs to the
//      logged-in user — you can only delete your own listings
//   3. Deletes the provider row (interactions and reviews are
//      removed automatically by ON DELETE CASCADE). The shared
//      account avatar is NOT touched.
//   4. Redirects back to the dashboard with a confirmation
// ============================================================

// --- 1. Harden the session cookie, then start the session ------
require_once __DIR__ . '/security.php';
session_harden();
session_start();

// --- 2. Session guard: logged in? ------------------------------
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 3. Database connection -------------------------------------
require_once __DIR__ . '/db.php';

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
$profileId = (int) ($_POST['profile_id'] ?? 0);
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

// --- 8. (No picture cleanup needed) -----------------------------
// Profile cards inherit the account's avatar from the users table,
// so deleting a listing must NOT touch that shared file.

// --- 9. Delete the provider row ---------------------------------
// user_interactions and reviews reference providers.id with
// ON DELETE CASCADE, so they vanish with the listing. Chats and
// jobs are tied to the USER account, not the listing, so they
// stay intact.
$stmt = $pdo->prepare('DELETE FROM providers WHERE id = :id');
$stmt->execute([':id' => $profile['id']]);

// --- 10. Confirm + redirect -------------------------------------
// NOTE: no htmlspecialchars() here — the flash is escaped ONCE at the
// point where dashboard.php prints it. Escaping in both places would
// make an apostrophe show up as "&#039;" in the banner.
islaFlash('success', 'Your islaFIND profile "' . $profile['name'] . '" was deleted.');
