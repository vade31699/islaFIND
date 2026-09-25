<?php
// ============================================================
// logout.php — Secure logout handler
// A tiny dedicated page that ends the user's session properly:
//   1. Marks the current device/session inactive in the
//      user_devices table (so it no longer shows as Active).
//   2. Clears every session variable and destroys the session.
//   3. Redirects back to the unified login page.
// It only accepts POST requests carrying a valid CSRF token, so
// a plain link (GET) can never log someone out — the same rule
// the rest of the app follows.
// ============================================================

// --- 1. Harden the session cookie, then start the session ------
require_once __DIR__ . '/../include/security.php';
session_harden(); // must run before session_start()
session_start();

// --- 2. Only POST + a valid CSRF token may log out --------------
// Anything else (a stray GET, a forged cross-site request) is
// redirected away from the action, not executed.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 3. Mark this device/session as logged out ------------------
// The device row stays in the trusted list (so the user can log
// back in on it later) but is flagged inactive. Only needed when
// a session actually exists.
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../include/db.php';

    $stmt = $pdo->prepare('UPDATE user_devices SET is_active = 0 WHERE session_id = :sid');
    $stmt->execute([':sid' => session_id()]);
}

// --- 4. Clear the session completely ----------------------------
session_unset();     // Remove every session variable
session_destroy();   // Destroy the session (and its data file)

// --- 5. Send the user back to the login page --------------------
header('Location: ' . sid_append('login.php'));
exit;
