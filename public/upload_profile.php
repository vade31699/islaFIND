<?php
// ============================================================
// upload_profile.php — Secure Profile Picture Upload Handler
// A dedicated backend that receives the profile picture form
// from the dashboard's profile panel. It:
//   1. Rejects unauthenticated / CSRF-invalid requests
//   2. Validates the file is a real JPG/PNG image (max 5 MB)
//   3. Saves it under a unique, random filename
//   4. AUTOMATICALLY DELETES the user's previous picture file
//   5. Updates the profile_picture path in the database
// Then redirects back to the dashboard profile panel with a
// success or error message (carried in a session flash).
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
// (a) A file must actually be present and uploaded without errors.
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    flashRedirect('error', 'Please choose an image file to upload.');
}

// (b) Size cap: 5 MB maximum (the plan's requirement).
if ($file['size'] > 5 * 1024 * 1024) {
    flashRedirect('error', 'The image must be 5 MB or smaller.');
}

// (c) getimagesize() returns FALSE for non-images, so it both
// confirms the file really is an image AND gives us its true MIME
// type — a spoofed extension (e.g. rename evil.txt to evil.png)
// still fails here because the file's content is not an image.
$imgInfo = @getimagesize($file['tmp_name']);
if ($imgInfo === false) {
    flashRedirect('error', 'The file is not a valid image.');
}

// (d) Only JPG and PNG are accepted (matches the front-end accept).
if (!in_array($imgInfo['mime'], ['image/jpeg', 'image/png'], true)) {
    flashRedirect('error', 'Only JPG and PNG images are allowed.');
}

// --- 9. Unique filename + save ----------------------------------
// Build a random filename: user_<customId>_<16 random hex chars>.
// The original filename is NEVER trusted (it could contain path
// tricks); the random suffix prevents collisions entirely.
$ext      = $imgInfo['mime'] === 'image/png' ? 'png' : 'jpg';
$filename = 'user_' . $user['user_id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$target   = __DIR__ . '/uploads/' . $filename;

// Move the uploaded temp file into the uploads folder.
if (!move_uploaded_file($file['tmp_name'], $target)) {
    flashRedirect('error', 'Could not save the file. Check the uploads folder permissions.');
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
    $oldFile = __DIR__ . '/uploads/' . basename($oldPath);
    if (is_file($oldFile)) {
        @unlink($oldFile);   // best-effort cleanup (ignore errors)
    }
}

// --- 11. Update the database reference --------------------------
// Store the new relative path using a prepared statement (the
// column is varchar(255), exactly what this stores).
$stmt = $pdo->prepare('UPDATE users SET profile_picture = :pic WHERE id = :id');
$stmt->execute([':pic' => $filename, ':id' => $user['id']]);

// --- 12. Report success and go back to the profile --------------
flashRedirect('success', 'Profile picture updated.');
