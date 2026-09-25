<?php
// ============================================================
// index.php — Loading screen with pre-load logic
// Shows the logo, animates a 0% -> 100% progress bar, then
// redirects to login.php once everything is ready.
// ============================================================

// --- 1. Server-side setup ------------------------------------
// Harden the session cookie first (HttpOnly + SameSite=Lax + strict
// IDs — see security.php), then start the session. Needed later by
// login.php so it can remember that the user is logged in.
require_once __DIR__ . '/security.php';
session_harden(); // must run before session_start()
session_start();

// Include the PDO connection from db.php ($pdo becomes available).
require_once __DIR__ . '/db.php';

/**
 * initializeAppEnvironment(PDO $pdo)
 * Runs every server-side pre-load check the app needs before the
 * user is allowed to continue to the login page.
 *
 * @param PDO $pdo The shared database connection from db.php.
 * @return bool TRUE when the app is ready, FALSE when something is broken.
 */
function initializeAppEnvironment(PDO $pdo): bool
{
    // Track whether every check has passed; start optimistic.
    $ready = true;

    // --- Check 1: the database must be reachable -------------
    // Run a trivial query. If MySQL is down, PDO throws an exception.
    try {
        $pdo->query('SELECT 1'); // 'SELECT 1' always returns one row if the DB works
    } catch (PDOException $e) {
        $ready = false;          // Database unreachable -> app is NOT ready
    }

    // Return the final readiness result to the caller.
    return $ready;
}

// Actually run the pre-load checks now, passing the PDO connection,
// and store the result in $appReady.
$appReady = initializeAppEnvironment($pdo);

// Convert the PHP boolean into a JS-friendly string ('true'/'false')
// so the JavaScript below knows whether it may redirect.
$appReadyJs = $appReady ? 'true' : 'false';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php
// Shared metadata (title, description, favicon, manifest, social
// preview) + style.css and busy.js live in one partial so every
// page ships the same head. A page only names itself here.
$headTitle = 'Loading';
$headDesc  = 'islaFIND is starting up — connecting to the Bantayan Island services directory.';
include __DIR__ . '/head_meta.php';
?>
</head>
<body>
    <section class="loader">
        <div class="container">
            <!-- App logo -->
            <img src="img/isla_logo.svg" alt="islaFIND logo" class="logo">

            <!-- Progress bar + percentage, positioned directly below the logo -->
            <div class="progress-area">
                <div class="progress-track">
                    <!-- The green fill; width is animated by JavaScript below -->
                    <div class="progress-fill" id="progressFill"></div>
                </div>
                <!-- Live percentage text, updated by JavaScript -->
                <p class="progress-percent" id="progressPercent">0%</p>
            </div>

            <!-- Show a visible error when a server-side check failed -->
            <?php if (!$appReady): ?>
                <p class="alert alert-error">Server check failed — is WAMP/MySQL running and is the <strong>final_app</strong> database created?</p>
            <?php endif; ?>

            <!-- App title -->
            <h1 class="apptitle">islaFIND</h1>
        </div>

        <div class="developer">
            <p>Developer: Dave Vidad</p>
        </div>
    </section>

    <script>
        // ============================================================
        // Loading progress animation (0% -> 100%)
        // ============================================================

        // Grab the green fill bar and the percentage text from the DOM.
        const progressFill = document.getElementById('progressFill');
        const progressPercent = document.getElementById('progressPercent');

        // Was the app declared ready by PHP? (true or false)
        const appReady = <?php echo $appReadyJs; ?>;

        // Start the counter at 0%.
        let progress = 0;

        // Use a timer that fires every 40ms so the bar moves smoothly.
        const timer = setInterval(function () {

            // Move the counter forward by a small random step (2-5)
            // so the animation looks organic instead of robotic.
            progress += Math.floor(Math.random() * 4) + 2;

            // Clamp the value so it can never exceed 100.
            if (progress > 100) {
                progress = 100;
            }

            // Update the width of the green fill bar to match progress.
            progressFill.style.width = progress + '%';

            // Update the visible percentage text.
            progressPercent.textContent = progress + '%';

            // Once we reach 100%, stop the timer and move on.
            if (progress === 100) {

                // Stop the interval so it stops firing.
                clearInterval(timer);

                // Short pause so the user can see the bar reach 100%.
                setTimeout(function () {

                    // Only redirect if every server-side check passed.
                    if (appReady) {
                        window.location.href = 'login.php';
                    }
                    // If not ready, the user stays here and sees the error box.
                }, 500);
            }
        }, 40);
    </script>
</body>
</html>
