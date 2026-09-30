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
//      the picture files the listing owned. The shared account avatar
//      is NOT touched — every other listing still uses it.
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

// --- 8. Delete the pictures this listing OWNS -------------------
// A listing carries its own cover (providers.profile_picture) and,
// for a business, an album (provider_album_images). Both die with
// the row, and the row's name is the only pointer to the FILE, so
// the files have to go first — read while the names are still
// knowable, best-effort, and never the shared account avatar, which
// every other listing of this person is still using.
//
// A LEFT JOIN so a listing with no album still yields its cover (one
// row, album_pic NULL); the empty checks skip the NULLs.
require_once __DIR__ . '/../include/uploads.php';

$stmt = $pdo->prepare(
    'SELECT p.profile_picture AS cover_pic, a.image_name AS album_pic
       FROM providers p
       LEFT JOIN provider_album_images a ON a.provider_id = p.id
      WHERE p.id = :id'
);
$stmt->execute([':id' => $profile['id']]);

foreach ($stmt->fetchAll() as $ownedFile) {
    if (!empty($ownedFile['cover_pic'])) {
        isla_upload_delete((string) $ownedFile['cover_pic']);
    }
    if (!empty($ownedFile['album_pic'])) {
        isla_upload_delete((string) $ownedFile['album_pic']);
    }
}

// --- 9. Delete the provider row ---------------------------------
// user_interactions and reviews reference providers.id with
// ON DELETE CASCADE, so they vanish with the listing, and so do the
// album rows (fk_album_provider). Chats and jobs are tied to the USER
// account, not the listing, so they stay intact.
$stmt = $pdo->prepare('DELETE FROM providers WHERE id = :id');
$stmt->execute([':id' => $profile['id']]);

// --- 10. Confirm + redirect -------------------------------------
// NOTE: no htmlspecialchars() here — the flash is escaped ONCE at the
// point where dashboard.php prints it. Escaping in both places would
// make an apostrophe show up as "&#039;" in the banner.
islaFlash('success', 'Your islaFIND profile "' . $profile['name'] . '" was deleted.');
