<?php
// ============================================================
// 404.php — "Page not found" screen
// Served by Apache for any URL that does not match a real file
// (see .htaccess -> ErrorDocument 404 /404.php) and reached
// directly by any handler that wants to bounce a bad request.
//
// It is deliberately friendly rather than technical: the visitor
// gets the app's branding, one explanation, and the two exits
// that actually help — back into the app (when signed in) or to
// the login page. Nothing about the failed URL is echoed back,
// so the page cannot be used to reflect a hostile link.
// ============================================================

// --- 1. Session (only to pick the right primary button) --------
require_once __DIR__ . '/security.php';
session_harden();
session_start();

$isLoggedIn = isset($_SESSION['user_id']);

// Where the primary button goes: straight back into the app for a
// signed-in visitor, otherwise to the login / sign-up screen.
$primaryHref  = sid_append($isLoggedIn ? 'dashboard.php' : 'login.php');
$primaryLabel = $isLoggedIn ? 'Back to Dashboard' : 'Log In / Sign Up';

// --- 2. Response metadata --------------------------------------
http_response_code(404);

$headTitle = 'Page not found';
$headDesc  = 'That link is not part of islaFIND. Head back to the dashboard or the login screen.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/head_meta.php'; ?>
</head>
<body class="auth-body">

    <header class="app-header">
        <a href="index.php" class="chat-back" aria-label="Back to the start">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
        <span class="app-header-title">islaFIND</span>
    </header>

    <main class="auth-section">
        <div class="auth-card notfound">

            <!-- Map-pin + 404: same pin glyph the app uses for
                 locations, so even an error screen looks like islaFIND. -->
            <div class="notfound-art" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2s7 5.2 7 12a7 7 0 0 1-14 0c0-6.8 7-12 7-12z"/>
                    <circle cx="12" cy="14" r="2.5"/>
                </svg>
                <span class="notfound-code">404</span>
            </div>

            <h2 class="notfound-title">We could not find that page</h2>
            <p class="sec-hint">
                The link may be broken, or the page may have been moved. Nothing about your
                account changed &mdash; use one of the buttons below to get back on track.
            </p>

            <div class="notfound-actions">
                <a href="<?php echo htmlspecialchars($primaryHref); ?>" class="btn btn-isla"><?php echo htmlspecialchars($primaryLabel); ?></a>
                <a href="index.php" class="btn btn-outline">Start over</a>
            </div>
        </div>
    </main>

</body>
</html>
