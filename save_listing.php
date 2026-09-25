<?php
// ============================================================
// save_listing.php — Bookmark toggle for islaFIND listings
// One POST endpoint that flips the heart on a listing:
//   not saved -> INSERT (save it)
//   already saved -> DELETE (remove it)
// so the same button works both ways and the state can never
// drift out of sync with a separate "unsave" button.
//
// WHERE THE BUTTONS ARE
//   dashboard.php — every Home feed card from someone else carries a
//                   heart ("Save" / "Saved"): the ✨ Recommended and ⭐
//                   category rails and the 📖 All Listings results. The
//                   feed's detail modal has the same heart, so a listing
//                   can be saved the moment it is read.
//   dashboard.php — the Saved Listings panel (Settings) lists the
//                   bookmarks with a Remove button of its own.
//
// RULES
//   - Login required, POST only, valid CSRF token (same as every
//     other write in the app), so a link or a forged form cannot
//     change someone's bookmarks.
//   - You cannot save your OWN listing (it already lives in
//     Settings -> islaFIND Profile) — the request is refused with a
//     friendly message rather than silently ignored.
//   - The redirect returns to where the click came from (Home or the
//     Settings panel). Only the Settings panel gets a one-shot flash:
//     on the Home feed the heart is its own feedback, so a successful
//     toggle flashes nothing there (an error always does). The Home
//     return also carries #listing-N, the anchor of the catalogue card,
//     so the visitor lands back on the listing they just toggled.
//   - The Home feed posts this with fetch (see the X-Requested-With
//     check in step 6b), so that toggle is answered with JSON and the
//     page is never reloaded: a reload would repaint the whole feed in
//     server order and throw away the distances it had already
//     measured. Without the script the plain form POST still gets the
//     redirect + one-shot flash below, and the Settings panel keeps
//     them too — that list has to be re-rendered anyway.
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

// --- 3. Database connection ------------------------------------
require_once __DIR__ . '/db.php';

// --- 4. Load the current user ----------------------------------
$stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_unset();
    session_destroy();
    header('Location: ' . sid_append('login.php'));
    exit;
}

$myId = (int) $user['id'];

// --- 5. Where the visitor came from + where to send them back ---
// THREE surfaces use this endpoint, and each one gets its result on
// the page it came from:
//   directory -> the directory (with the card's anchor, so the
//                browser scrolls straight back to it)
//   home      -> the Home feed
//   saved     -> the dashboard's Saved Listings panel
// The value is whitelisted, so a crafted request can never redirect
// the user somewhere unexpected.
$returnTo = in_array($_POST['return_to'] ?? '', ['home', 'saved'], true)
    ? $_POST['return_to']
    : 'home';

$backUrl = $returnTo === 'saved'
    ? 'dashboard.php?tab=saved'
    : 'dashboard.php?tab=home';

$flashKey = $returnTo === 'saved' ? 'flash_saved' : 'flash_feed';

// --- 6b. How to answer ----------------------------------------
// The Home feed's Save heart posts with fetch(), so it is answered
// with JSON instead of a redirect — the page keeps its scroll
// position, its order and the distances it already measured. Every
// other path (no script, the Settings panel) redirects exactly as
// before. The whitelist above still decides WHERE a redirect goes.
$wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

/**
 * Finish a bookmark toggle. A fetch() call gets the outcome as JSON
 * so the feed can flip the heart in place; anything else gets the
 * redirect + one-shot flash every other form in the app uses.
 *
 * @param ?array $flash Error/success banner, or null for none.
 * @param bool   $saved The bookmark state to report back.
 */
function save_listing_finish(
    bool $wantsJson,
    string $flashKey,
    string $backUrl,
    string $anchor,
    ?array $flash,
    bool $saved = false
): void {
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            // A refusal is a 200 with ok=false: the feed answers it by
            // handing the request back to the ordinary POST, whose
            // flash banner explains what happened.
            'ok'    => $flash === null || ($flash['type'] ?? '') !== 'error',
            'msg'   => $flash['msg'] ?? '',
            'saved' => $saved,
        ]);
        exit;
    }

    if ($flash !== null) {
        $_SESSION[$flashKey] = $flash;
    }
    header('Location: ' . sid_append($backUrl . $anchor));
    exit;
}

// --- 6. Method + CSRF guard ------------------------------------
// A forged cross-site request cannot know the session token, so
// anything that is not a valid POST is bounced.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    save_listing_finish($wantsJson, $flashKey, $backUrl, '', [
        'type' => 'error',
        'msg'  => 'Your session expired or the form token is invalid. Please try again.',
    ]);
}

$providerId = (int) ($_POST['provider_id'] ?? 0);

// The Home return lands on the exact card that was toggled: only the
// catalogue cards carry id="listing-N" (see feedCardHtml), so the anchor
// is unique even though a listing can also appear in a rail. The Settings
// panel lists its own cards, so there is nothing to anchor to there.
$anchor = $returnTo === 'home' ? '#listing-' . $providerId : '';

if ($providerId <= 0) {
    save_listing_finish($wantsJson, $flashKey, $backUrl, '', [
        'type' => 'error',
        'msg'  => 'No listing was selected.',
    ]);
}

// --- 7. The listing must exist ---------------------------------
$stmt = $pdo->prepare('SELECT id, user_id FROM providers WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $providerId]);
$provider = $stmt->fetch();

if (!$provider) {
    save_listing_finish($wantsJson, $flashKey, $backUrl, '', [
        'type' => 'error',
        'msg'  => 'That listing no longer exists.',
    ]);
}

// --- 8. You cannot save your own listing -----------------------
if ((int) $provider['user_id'] === $myId) {
    save_listing_finish($wantsJson, $flashKey, $backUrl, $anchor, [
        'type' => 'error',
        'msg'  => 'That is your own listing — manage it from Settings, not your saved list.',
    ]);
}

// --- 9. Flip the bookmark --------------------------------------
// One row per (user, listing) thanks to the UNIQUE key, so INSERT
// IGNORE cannot create duplicates even if this page is submitted
// twice in a row (double tap, back button, retry).
// $isSavedNow is the state the answer reports back to the feed.
$isSavedNow = false;
try {
    $stmt = $pdo->prepare(
        'SELECT id FROM saved_listings WHERE user_id = :me AND provider_id = :pid LIMIT 1'
    );
    $stmt->execute([':me' => $myId, ':pid' => $providerId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $pdo->prepare(
            'DELETE FROM saved_listings WHERE user_id = :me AND provider_id = :pid'
        );
        $stmt->execute([':me' => $myId, ':pid' => $providerId]);
        $isSavedNow = false;
        // Only the Settings panel needs the confirmation. On the Home
        // feed the heart already flipped, so a banner over the feed is
        // noise: nothing is flashed there.
        $flash = $returnTo === 'saved'
            ? ['type' => 'success', 'msg' => 'Removed from your saved listings.']
            : null;
    } else {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO saved_listings (user_id, provider_id) VALUES (:me, :pid)'
        );
        $stmt->execute([':me' => $myId, ':pid' => $providerId]);
        $isSavedNow = true;
        $flash = $returnTo === 'saved'
            ? ['type' => 'success', 'msg' => 'Saved to your saved listings.']
            : null;
    }
} catch (PDOException $e) {
    // Concurrent tap between the SELECT and the INSERT, or the table
    // could not be created — either way the user gets a clear answer
    // instead of a stack trace.
    error_log('islaFIND save_listing failed: ' . $e->getMessage());
    $flash = [
        'type' => 'error',
        'msg'  => 'Could not update your saved listings. Please try again.',
    ];
}

// --- 10. Answer: JSON for the feed's fetch(), otherwise flash + redirect
// A successful Home toggle flashes NOTHING (see step 9); an error always
// flashes, wherever the click came from, because the visitor needs to know
// why nothing changed.
save_listing_finish($wantsJson, $flashKey, $backUrl, $anchor, $flash, $isSavedNow);
