<?php
// ============================================================
// upload_profile.php — Secure Profile Picture Upload Handler
// A dedicated backend that receives the profile picture form
// from the dashboard's profile panel. It:
//   1. Rejects unauthenticated / CSRF-invalid requests
//   2. Validates the file is a real JPG/PNG image (max 5 MB),
//      using the shared rules in include/uploads.php
//   3. Saves it under a unique, random filename
//   4. AUTOMATICALLY DELETES the user's previous picture file
//   5. Updates the profile_picture path in the database
// Then redirects back to the dashboard profile panel with a
// success or error message (carried in a session flash).
//
// SCOPE: this is the ACCOUNT avatar only. A listing's own picture
// and its photo album are handled by upload_listing_photos.php,
// which shares the same validation and storage helpers.
// ============================================================

// --- 1. Harden the session cookie, then start the session ------
require_once __DIR__ . '/../include/security.php';
session_harden(); // must run before session_start()
session_start();

// --- 2. Session guard: logged in? ------------------------------
// No user_id in the session -> not logged in -> back to login.
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 3. Database connection ------------------------------------
require_once __DIR__ . '/../include/db.php';

// --- 3b. Upload storage ----------------------------------------
// One seam for where pictures live, so the backend is changed in a
// single file instead of here (see include/uploads.php).
require_once __DIR__ . '/../include/uploads.php';

// --- 4. Load the user's CURRENT row ----------------------------
// We need the user's custom id (for the filename) and their
// current profile_picture path (so the old file can be deleted).
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch();

// Account deleted while logged in? Treat like a logged-out user.
if (!$user) {
    session_unset();
    session_destroy();
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 5. Flash helper --------------------------------------------
// Stores a message for the NEXT page load, then redirects back
// to the dashboard profile panel. The dashboard reads and clears
// $_SESSION['flash_upload'] to render the success/error banner.
function flashRedirect(string $type, string $msg): void
{
    $_SESSION['flash_upload'] = ['type' => $type, 'msg' => $msg];
    header('Location: ' . sid_append('dashboard.php?tab=profile'));
    exit;
}

// --- 6. CSRF + method check -------------------------------------
// Every upload must be a POST carrying a valid token; a forged
// cross-site upload cannot know the token, so it is rejected.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    flashRedirect('error', 'Your session expired or the form token is invalid. Please try again.');
}

// --- 7. Grab the uploaded file ----------------------------------
$file = $_FILES['profile_picture'] ?? null;

// --- 8. Validation ----------------------------------------------
// All of it lives in include/uploads.php (isla_upload_validate), so
// the account avatar, a listing's own photo and its album are judged
// by exactly the same rules: 5 MB maximum, a real image (getimagesize
// reads the CONTENT, so a .txt renamed to .png still fails), JPG or
// PNG only. Nothing here re-implements any of that.
$check = isla_upload_validate(is_array($file) ? $file : [], 5 * 1024 * 1024);

if (!$check['ok']) {
    flashRedirect('error', $check['error']);
}

// --- 9. Unique filename + save ----------------------------------
$filename = isla_upload_name('user', (int) $user['user_id'], $check['ext']);
// Hand the bytes to the storage seam — the local uploads folder
// unless the app is configured for a remote driver.
// A failure here carries its own reason when the storage seam knows
// it (object storage misconfigured, for instance); the generic line is
// only the fallback, so a deployment problem is never reported as a
// permissions problem on the wrong folder.
if (!isla_upload_store($file['tmp_name'], $filename)) {
    flashRedirect('error', isla_upload_failed_message('Could not save the file. Check the uploads folder permissions.'));
}

// --- 10. Automatic old picture deletion -------------------------
// Keep the server clean: delete the user's PREVIOUS picture file
// so no orphaned images accumulate. basename() is a safety net so
// a stored path can never escape the uploads directory. If the old
// path is NULL/empty (user never had a picture) there is nothing
// to delete — this also means the default avatar placeholder is
// never touched.
$oldPath = $user['profile_picture'] ?? null;
if ($oldPath !== null && $oldPath !== '') {
    isla_upload_delete($oldPath);   // best-effort cleanup (see uploads.php)
}

// --- 11. Update the database reference --------------------------
// Store the new relative path using a prepared statement (the
// column is varchar(255), exactly what this stores).
$stmt = $pdo->prepare('UPDATE users SET profile_picture = :pic WHERE id = :id');
$stmt->execute([':pic' => $filename, ':id' => $user['id']]);

// --- 12. Report success and go back to the profile --------------
flashRedirect('success', 'Profile picture updated.');
