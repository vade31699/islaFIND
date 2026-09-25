<?php
// ============================================================
// dashboard_smoke_test.php — App shell smoke test
// A standalone CLI check for dashboard.php — which now owns BOTH the
// recommendation feed and the 📖 All Listings catalogue that used to
// live on directory.php. There is no test framework in this project,
// so this runs like the other diagnostic scripts: from the project
// root,
//
//     php dashboard_smoke_test.php
//
// It does two things:
//   1. STATIC  — every local link/asset referenced by dashboard.php
//                resolves to a file that actually exists on disk,
//                plus the layout/filter/save-control guards.
//   2. LIVE    — starts PHP's built-in server on a spare port and
//                requests the pages for real, asserting they respond
//                (render, or redirect a logged-out visitor to
//                login.php) instead of erroring — and that the
//                retired directory.php URL is really gone.
//
// No database is needed: the pages redirect to login BEFORE they
// touch the database, so the live checks pass on a machine with
// MySQL stopped.
//
// Exit code 0 = all checks passed, 1 = at least one failed.
//
// COMMAND LINE ONLY: it starts a web server on a spare port, which
// is not something a browser should ever be able to trigger.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// --- Result bookkeeping ---------------------------------------
$pass   = 0;
$fail   = 0;
$failed = [];

/**
 * Record one check. $label describes what was asserted and
 * $detail is printed only when the check fails.
 */
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failed;
    if ($ok) {
        $pass++;
        echo "  PASS  $label\n";
        return;
    }
    $fail++;
    $failed[] = $label;
    echo "  FAIL  $label";
    echo $detail !== '' ? "  ($detail)\n" : "\n";
}

/**
 * Pull every local file reference out of a page's markup:
 * href="...", src="..." and action="...". External URLs, anchors
 * and the dynamic "<?php echo ... ?>" targets are skipped — only
 * fixed relative paths are worth checking on disk.
 */
function local_refs(string $html): array
{
    preg_match_all('/(?:href|src|action)\s*=\s*["\']([^"\']+)["\']/i', $html, $m);
    $refs = [];
    foreach ($m[1] as $ref) {
        // Skip anything that is not a plain relative path.
        if ($ref === '' || strpos($ref, '<?') !== false || strpos($ref, '$') !== false) {
            continue;
        }
        if (preg_match('~^(?:https?:|//|mailto:|javascript:|tel:|#)~i', $ref)) {
            continue;
        }
        // Drop ?query / #fragment so "create_profile.php?edit=3"
        // is checked as "create_profile.php".
        $ref = preg_split('/[?#]/', $ref)[0];
        if ($ref !== '') {
            $refs[$ref] = true;
        }
    }
    return array_keys($refs);
}

// Guard: run from the project root so the relative paths line up.
// The app is split in two: the pages and assets a browser may fetch
// live in public/ (the web root), while the shared includes live in
// include/ and are never web-reachable. $root is the project root,
// $web the directory actually served, $incDir the shared includes.
if (!is_file(__DIR__ . '/public/dashboard.php')) {
    fwrite(STDERR, "Run this from the project root (public/dashboard.php not found).\n");
    exit(1);
}
$root   = __DIR__;
$web    = __DIR__ . '/public';
$incDir = __DIR__ . '/include';

echo "\n=== islaFIND dashboard smoke test ===\n\n";

// ============================================================
// 1. STATIC CHECKS
// ============================================================
echo "-- Static: links + assets resolve to real files\n";

$dashboard = file_get_contents($web . '/dashboard.php');

// The directory page is RETIRED: its listings live in the Home feed's
// catalogue now, and nothing may link back to the old URL.
check(
    'directory.php is gone from disk',
    !is_file($web . '/directory.php')
);
check(
    'no page links to the retired directory.php',
    !preg_match('/href\s*=\s*["\']directory\.php/', $dashboard)
);
// ...and the catalogue it was replaced by is reachable from the rest of
// the app: the empty states and the "View in Listings" actions all deep
// link into it.
check(
    'dashboard.php deep links to the catalogue section',
    strpos($dashboard, 'dashboard.php?tab=home#feedCatalogue') !== false
);
check(
    'dashboard.php links a listing to its catalogue card',
    (bool) preg_match('/href="dashboard\.php\?tab=home#listing-<\?php/', $dashboard)
);

// Every fixed relative reference in the page must resolve.
foreach (['dashboard.php' => $dashboard] as $name => $html) {
    foreach (local_refs($html) as $ref) {
        // Only check app files (pages, styles, scripts) — data
        // assets such as uploaded images may be absent by design.
        if (!preg_match('/\.(?:php|css|js)$/i', $ref)) {
            continue;
        }
        check("$name references an existing file: $ref", is_file($web . '/' . $ref));
    }
}

// dashboard.php's PHP includes (require __DIR__ . '/x.php').
if (preg_match_all('/require(?:_once)?\s+__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/', $dashboard, $m)) {
    foreach ($m[1] as $inc) {
        $inc = ltrim($inc, '/\\');
        // The captured path is relative to the PAGE (public/), because
        // that is where dashboard.php sits — hence '../include/...'.
        check("dashboard.php includes an existing file: $inc", is_file($web . '/' . $inc));
    }
}

// ============================================================
// 1b. STATIC CHECKS — shared layout + the finishing-touch pages
// ============================================================
echo "\n-- Static: shared head, assets and new pages\n";

// Every page must render through the SAME head partial, so title,
// description, favicon, manifest and social tags can never drift
// apart between pages.
$headPages = [
    'index.php', 'login.php', 'dashboard.php',
    'messenger.php', 'create_profile.php', '404.php',
];
foreach ($headPages as $page) {
    $src = is_file($web . '/' . $page) ? (string) file_get_contents($web . '/' . $page) : '';
    check(
        "$page renders the shared head",
        strpos($src, 'head_meta.php') !== false,
        $src === '' ? 'file missing' : 'no head_meta.php include'
    );
}

// The partial's own assets must exist, or every page 404s them.
$headSrc = is_file($incDir . '/head_meta.php') ? (string) file_get_contents($incDir . '/head_meta.php') : '';
foreach (local_refs($headSrc) as $ref) {
    if (!preg_match('/\.(?:css|js|svg|webmanifest)$/i', $ref)) {
        continue;
    }
    check("head_meta.php references an existing file: $ref", is_file($web . '/' . $ref));
}

// A manifest that does not parse is worse than none: the browser
// silently refuses to offer "add to home screen".
$manifestRaw = is_file($web . '/manifest.webmanifest')
    ? (string) file_get_contents($web . '/manifest.webmanifest')
    : '';
$manifest = json_decode($manifestRaw, true);
check(
    'manifest.webmanifest is valid JSON with a name + icons',
    is_array($manifest) && !empty($manifest['name']) && !empty($manifest['icons'])
);

// The branded error page + crawler rules.
check('404.php exists', is_file($web . '/404.php'));
check(
    '404.php answers with an HTTP 404',
    strpos((string) file_get_contents($web . '/404.php'), 'http_response_code(404)') !== false
);
check('robots.txt exists', is_file($web . '/robots.txt'));
check(
    '.htaccess routes missing URLs to 404.php',
    strpos((string) file_get_contents($web . '/.htaccess'), 'ErrorDocument 404 /404.php') !== false
);

// Saved listings: the endpoint must gate on CSRF like every other
// writer in the app, and the directory must offer the filter + sort.
$saveSrc = is_file($web . '/save_listing.php')
    ? (string) file_get_contents($web . '/save_listing.php')
    : '';
check('save_listing.php exists', $saveSrc !== '');
check(
    'save_listing.php requires POST + a CSRF token',
    strpos($saveSrc, 'csrf_check()') !== false
        && strpos($saveSrc, "REQUEST_METHOD'] !== 'POST'") !== false
);
check(
    'the catalogue offers the sort control',
    strpos($dashboard, 'id="feedSort"') !== false
);
// The catalogue is what directory.php used to be, so it must carry the
// same three filters it did: profile type, category chips, saved-only.
// ...but it is the RESULTS view, not a permanent wall of cards: it
// ships hidden and the search box (or a filter chip) opens it. The
// cards stay in the DOM either way, which is what lets one search box
// reach every listing — not just the handful a rail happens to carry.
check(
    'the catalogue ships hidden and opens on a search',
    strpos($dashboard, 'id="feedCatalogue" hidden') !== false
        && strpos($dashboard, "const catalogueOpen = q !== '' || curType !== '' || curCat !== ''") !== false
        && strpos($dashboard, 'if (isCatalogue && !catalogueOpen) {') !== false
);
check(
    'the catalogue carries the profile-type toggle',
    substr_count($dashboard, 'class="feed-type-btn on" data-type=""') === 1
        && strpos($dashboard, 'data-type="individual"') !== false
        && strpos($dashboard, 'data-type="business"') !== false
);
check(
    'the catalogue carries a category chip per category',
    strpos($dashboard, 'class="feed-filters"') !== false
        && strpos($dashboard, 'foreach ($providerCategories as $catSlug => $catName)') !== false
);
// The Save heart lives in feedCardHtml(), so it comes with EVERY card:
// a rail's, a catalogue card's and a Saved-list card's. WHERE it sits
// matters as much as that it exists — beside the name in the header row
// (.feed-card-top .inline-save), not buried at the bottom of the card.
check(
    'every feed card has a Save heart',
    strpos($dashboard, 'save-btn') !== false && strpos($dashboard, 'save_listing.php') !== false
);
$nameAt   = strpos($dashboard, "'<h5>' . \$nameHtml . '</h5>'");
// The USAGE (. $saveControl), not the assignment further up the file.
$saveAt   = strpos($dashboard, ". \$saveControl\n");
$ratingAt = strpos($dashboard, '. $ratingRow');
check(
    'feedCardHtml puts Save beside the name, above the rating row',
    $nameAt !== false && $saveAt !== false && $ratingAt !== false
        && $nameAt < $saveAt && $saveAt < $ratingAt
);
// The catalogue cards are the deep-linkable ones: only feedCardHtml's
// $catalogue mode renders id="listing-N", so save_listing.php's anchor
// can never match two cards.
check(
    'only the catalogue cards carry the #listing-N anchor',
    // Gated on $catalogue, so a listing that ALSO sits in a rail renders
    // the anchor exactly once — on its catalogue card.
    strpos($dashboard, '($catalogue ? \' id="listing-\' . (int) $p[\'id\'] . \'"\' : \'\')') !== false
);

// Bookmarks must also be reachable from the dashboard itself: a menu
// entry and its own sliding panel, wired into the panel switcher.
check(
    'dashboard.php has a Saved Listings menu entry',
    strpos($dashboard, 'data-go="saved"') !== false && strpos($dashboard, 'dashSaved') !== false
);
check(
    'the Saved panel is wired into the slide + height logic',
    strpos($dashboard, "'show-saved'") !== false && strpos($dashboard, 'savedPanel') !== false
);
// ...and the panel's own Remove control sits where the directory's Save
// control does: beside the listing name in the card header, above the
// rating row (the two cards are meant to look like the same card).
$savedCardAt = strpos($dashboard, 'id="saved-listing-');
$from        = $savedCardAt === false ? 0 : $savedCardAt;
$savedHeadAt = strpos($dashboard, 'provider-card-head', $from);
$savedRmAt   = strpos($dashboard, 'value="saved"', $from);
$savedRateAt = strpos($dashboard, 'class="card-rating"', $from);
check(
    'the Saved panel puts Remove beside the name, above the rating row',
    $savedCardAt !== false && $savedHeadAt !== false && $savedRmAt !== false && $savedRateAt !== false
        && $savedHeadAt < $savedRmAt && $savedRmAt < $savedRateAt
);
// ...and the feed's detail modal follows the same rule: its Save heart
// lives in the name row (.pm-name-row, beside the provider name) and
// above the badge row — NOT down in the .pm-actions stack, where it
// used to sit under "Get Directions".
$modalAt   = strpos($dashboard, 'id="providerModal"');
$pmFrom    = $modalAt === false ? 0 : $modalAt;
$nameRowAt = strpos($dashboard, 'class="pm-name-row"', $pmFrom);
$pmSaveAt  = strpos($dashboard, 'id="pmSaveForm"', $pmFrom);
$pmTagsAt  = strpos($dashboard, 'class="pm-tags"', $pmFrom);
$pmActsAt  = strpos($dashboard, 'class="pm-actions"', $pmFrom);
check(
    'the detail modal puts Save beside the name, above the badge row',
    $modalAt !== false && $nameRowAt !== false && $pmSaveAt !== false
        && $pmTagsAt !== false && $pmActsAt !== false
        && $nameRowAt < $pmSaveAt && $pmSaveAt < $pmTagsAt
        && $pmSaveAt < $pmActsAt
);
check(
    'style.css matches the seven-panel track',
    strpos((string) file_get_contents($web . '/style.css'), 'width: 700%') !== false
        && strpos((string) file_get_contents($web . '/style.css'), '.dash-slider-track.show-saved') !== false
);
check(
    'save_listing.php can return to the saved panel',
    strpos($saveSrc, "'saved'") !== false && strpos($saveSrc, 'dashboard.php?tab=saved') !== false
);

// Route links must start from the VISITOR, not from the business:
// an omitted origin only works when the browser has already shared a
// location, and on a desktop that has not, Google Maps used the pin
// itself as the start point. dashboard.php's own helpers supply it.
// (maps_integration.js — the old standalone directory-card helper — is
// gone with the page; there is one route implementation now.)
check(
    'dashboard.php builds an origin-aware route',
    strpos($dashboard, 'routeDirectionsUrl') !== false
        && strpos($dashboard, "'&origin='") !== false
        && strpos($dashboard, 'getMyPosition()') !== false
);
check(
    'the retired maps_integration.js is gone',
    !is_file($web . '/maps_integration.js')
        && !is_file($web . '/maps_integration_test.js')
);
check(
    'the route button says what it does (Get Directions)',
    (bool) preg_match('/Get Directions\s*<\/a>/', $dashboard)
);
// The hint above the location prompt: it must exist, or visitors are
// prompted with no explanation of why.
check(
    'the route button explains the location prompt ("Starts from your location")',
    substr_count($dashboard, 'Starts from your location') === 1
        && substr_count($dashboard, 'id="pmRouteHint"') === 1
        && strpos($dashboard, 'class="route-hint"') !== false
);
check(
    'the modal hint hides and shows with the button',
    strpos($dashboard, "document.getElementById('pmRouteHint')") !== false
        && preg_match('/mapHint\.hidden = false;/', $dashboard)
        && preg_match('/mapHint\.hidden = true;/', $dashboard)
);
$cssSrc = (string) file_get_contents($web . '/style.css');
check(
    'style.css styles the route hint',
    strpos($cssSrc, '.route-hint {') !== false
);
// The modal heart is pinned to the right edge of the name row the same
// way the card's pill is (margin-left:auto), and the name is allowed to
// yield the row to it instead of pushing the pill onto its own line.
check(
    'style.css pins the modal heart beside the name',
    (bool) preg_match('/\.pm-head \.pm-name-row \.inline-save \{[^}]*margin-left:\s*auto;/s', $cssSrc)
        && (bool) preg_match('/\.pm-name-row \{[^}]*display:\s*flex;[^}]*flex-wrap:\s*wrap;/s', $cssSrc)
);
// The Home feed cards carry the same pill in their header row.
check(
    'style.css pins the feed card heart beside the name too',
    (bool) preg_match('/\.feed-card-top \{[^}]*flex-wrap:\s*wrap;/s', $cssSrc)
        && (bool) preg_match('/\.feed-card-top \.inline-save \{[^}]*margin-left:\s*auto;/s', $cssSrc)
);
// A feed card is itself a click/keydown target, so the heart inside it needs
// the card's own wiring to step aside — otherwise tapping Save would submit
// the bookmark AND pop the detail modal open behind it.
check(
    'the feed card steps aside for its own Save heart',
    substr_count($dashboard, 'if (inSaveControl(e)) return;') === 2
        && strpos($dashboard, "e.target.closest('.inline-save')") !== false
);
// The Home feed once carried its own "Saved only" view switch; it is gone.
// Bookmarks are reached through the Saved Listings panel in Settings, so
// the home feed stays a feed — rails for browsing, results for a search.
check(
    'the Home feed no longer carries a Saved-only view switch',
    strpos($dashboard, 'id="feedSavedChip"') === false
        && strpos($dashboard, 'id="feedSavedSection"') === false
        && strpos($dashboard, 'id="savedEmpty"') === false
);
check(
    'style.css spaces the catalogue list cards apart',
    (bool) preg_match('/\.feed-list \.feed-card \{[^}]*margin-bottom:/s', $cssSrc)
);

// Layout guards. Each one is the fix for a bug that was MEASURED in a real
// browser at 320-1024px, so they are worth holding onto:
//   * the create-profile type picker's hidden radio needs top/left, or it
//     stays at its static position while `width: 100%` measures the padding
//     box — which dragged that whole page 18-58px wide on a phone;
//   * the card header must be free to wrap the heart below the name, or a
//     long business name is squeezed into a 68px column (five lines);
//   * the catalogue must never produce a card narrower than a phone.
check(
    'style.css pins the hidden type-picker radio (no sideways scroll)',
    (bool) preg_match('/\.type-card input \{[^}]*top:\s*0;[^}]*left:\s*0;/s', $cssSrc)
);
check(
    'style.css lets the card header wrap the heart instead of squeezing the name',
    (bool) preg_match('/\.provider-card-top \{[^}]*flex-wrap:\s*wrap;/s', $cssSrc)
        && (bool) preg_match('/\.provider-card-head \{[^}]*min-width:\s*8rem;/s', $cssSrc)
);
check(
    'the catalogue is a single-column list, not a two-up grid',
    // The old directory could afford two columns: it was the one page on the
    // wider .dash-card-wide shell. The catalogue now lives in the Home panel,
    // whose 480px shell is shared with six other panels — two columns in there
    // would make every card NARROWER than it is on a phone, so the catalogue
    // is a list (.feed-list) and looks exactly like the rails and the Saved
    // view. If a .dir-grid or a second column ever comes back, this fails.
    strpos($cssSrc, '.dir-grid') === false
        && strpos($cssSrc, '.dash-card-wide') === false
        && (bool) preg_match('/\.feed-list \.feed-card \{[^}]*margin-bottom:/s', $cssSrc)
);
// The gutter and the shell's side padding RAMP with the viewport rather than
// switching at a breakpoint. With flat tablet values the shell lost width the
// moment the screen grew past one: the two-up cards measured 332px at 768px
// and 323px at 769px — narrower on a bigger screen. Every ramp ends where the
// 960px shell does (2.2vw reaches 20px at 909px, 2.8vw reaches 28px at
// 1000px), because growing the padding past the cap would shrink the cards
// again now that the shell itself can no longer grow.
check(
    'the card header furniture ramps too (avatar, gap, pill)',
    // Same reasoning inside the card: the avatar, the row gap and the Save
    // pill's padding were flat overrides in the tablet block, so all three
    // stepped at 769px and — because the furniture grew faster than the card
    // did — the NAME column went 163px -> 140px on a wider screen. Their ramps
    // start and end at exactly the old tablet/desktop values, so phones are
    // untouched and ~920px up is as it was.
    (bool) preg_match('/\.provider-card-pic \{[^}]*width:\s*clamp\(48px,\s*7vw,\s*64px\)/s', $cssSrc)
        && (bool) preg_match('/\.provider-card-top \{[^}]*gap:\s*clamp\(10px,\s*1\.3vw,\s*12px\)/s', $cssSrc)
        && (bool) preg_match(
            '/\.provider-card-top \.save-btn \{[^}]*padding:\s*clamp\(6px,\s*0\.9vw,\s*7px\)\s*clamp\(10px,\s*1\.4vw,\s*12px\)/s',
            $cssSrc
        )
        // ... and the flat overrides must not come back: the avatar is only
        // sized in ONE place now, and `padding: 6px 10px` was the pill's
        // tablet value, so its return here is the step returning.
        && substr_count($cssSrc, '.provider-card-pic {') === 1
        && strpos($cssSrc, 'padding: 6px 10px') === false
);
check(
    'the gutter and shell padding ramp with the viewport, not at 768px',
    (bool) preg_match('/\.app-content \{[^}]*padding:\s*8px\s*clamp\(16px,\s*2\.2vw,\s*20px\)/s', $cssSrc)
        && (bool) preg_match('/\.dash-card \{[^}]*padding:\s*18px\s*clamp\(16px,\s*2\.8vw,\s*28px\)\s*28px\s*clamp\(16px,\s*2\.8vw,\s*28px\)/s', $cssSrc)
        // ... and no flat override may creep back in, because those two flat
        // rules ARE the cliff.
        && strpos($cssSrc, 'padding: 8px 16px') === false
        && strpos($cssSrc, 'padding: 18px 14px') === false
);
check(
    'the profile pin preview opens the SPOT, not a route',
    strpos((string) file_get_contents($web . '/maps_pinning.js'), 'maps/search/?api=1&query=') !== false
);

// The password meter (register / reset / change password).
check('password_strength.js exists', is_file($web . '/password_strength.js'));
check(
    'dashboard.php loads the password strength meter',
    strpos($dashboard, 'password_strength.js') !== false
);
check(
    'login.php loads the password strength meter',
    strpos((string) file_get_contents($web . '/login.php'), 'password_strength.js') !== false
);

// Login throttling. render_smoke_test.php proves the counters behave
// (they grow, they cap, they clear); these checks prove login.php is
// wired to them at all — and, just as important, WHERE. The gate has
// to run before the password is verified, or a brute-force loop keeps
// guessing while it waits and the throttle does nothing.
$loginSrc = (string) file_get_contents($web . '/login.php');
$secSrc   = (string) file_get_contents($incDir . '/security.php');
check(
    'security.php provides the throttle helpers (counters in the database)',
    strpos($secSrc, 'function login_throttle_wait(') !== false
        && strpos($secSrc, 'function login_throttle_record_failure(') !== false
        && strpos($secSrc, 'function login_throttle_clear(') !== false
        && strpos($secSrc, 'login_attempts') !== false
);
$waitAt   = strpos($loginSrc, 'login_throttle_wait(');
$verifyAt = strpos($loginSrc, 'password_verify(');
check(
    'login.php checks the throttle BEFORE it verifies the password',
    $waitAt !== false && $verifyAt !== false && $waitAt < $verifyAt
);
check(
    'login.php counts a wrong password and clears the streak on success',
    strpos($loginSrc, 'login_throttle_record_failure(') !== false
        && strpos($loginSrc, 'login_throttle_clear(') !== false
);
// The old lockout lived in $_SESSION, which is exactly what made it
// useless against an attacker (a new cookie = a clean counter) and
// harmful to a real user on a shared device. Nothing may write it.
check(
    'no lockout state is written to the session any more',
    !preg_match('/\$_SESSION\[[\'\"]login_failures[\'\"]\]\s*=/', $loginSrc)
);
check(
    'the schema ships the table (runtime creation + SQL dump)',
    strpos((string) file_get_contents($incDir . '/db.php'), 'CREATE TABLE IF NOT EXISTS login_attempts') !== false
        && strpos((string) file_get_contents($root . '/final_app.sql'), 'CREATE TABLE IF NOT EXISTS login_attempts') !== false
);

// The delete-account danger zone must exist in Privacy & Security,
// and every developer script must refuse web requests.
check(
    'dashboard.php offers the delete-account flow',
    strpos($dashboard, 'delete_account') !== false && strpos($dashboard, 'danger-zone') !== false
);
foreach (['setup_own.php', '_smtp_test.php', 'dashboard_smoke_test.php', 'render_smoke_test.php'] as $dev) {
    $src = is_file($root . '/' . $dev) ? (string) file_get_contents($root . '/' . $dev) : '';
    check(
        "$dev is command-line only",
        $src !== '' && strpos($src, "PHP_SAPI !== 'cli'") !== false,
        $src === '' ? 'file missing' : 'no CLI guard'
    );
}

// ============================================================
// 2. LIVE CHECKS (PHP built-in server on a spare port)
// ============================================================
echo "\n-- Live: load the pages over HTTP\n";

/**
 * GET a URL without following redirects, so a 3xx stays visible.
 * Returns ['status' => int, 'location' => string, 'err' => string].
 */
function http_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,   // status line + headers in the body
        CURLOPT_FOLLOWLOCATION => false,  // keep redirects observable
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = $raw === false ? curl_error($ch) : '';
    curl_close($ch);

    $location = '';
    if (is_string($raw) && preg_match('/^Location:\s*(.+)$/mi', $raw, $m)) {
        $location = trim($m[1]);
    }
    return ['status' => $status, 'location' => $location, 'err' => $err];
}

$port    = random_int(20000, 60000);
$base    = 'http://127.0.0.1:' . $port;
$logFile = tempnam(sys_get_temp_dir(), 'isla_srv_');
$proc    = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $web],
    [0 => ['file', $logFile, 'a'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
    $pipes,
    $root
);

/**
 * stop_server()
 * Kills the child PHP server for good. proc_terminate() alone can
 * leave the process alive on Windows, where a surviving server keeps
 * its port bound AND holds this terminal's output handle open (which
 * makes an interrupted run look like it is still running).
 */
function stop_server(&$proc): void
{
    // Idempotent: the normal path stops the server, and the shutdown
    // hook may call this again afterwards.
    if (!is_resource($proc)) {
        return;
    }

    $status = proc_get_status($proc);
    $pid    = (int) ($status['pid'] ?? 0);
    $alive  = !empty($status['running']);

    if ($alive && $pid > 0 && stripos(PHP_OS_FAMILY, 'Windows') === 0) {
        @exec('taskkill /F /PID ' . $pid . ' 2>NUL');
    } elseif ($alive) {
        @proc_terminate($proc, 9);
    }

    @proc_close($proc);
    $proc = null;                  // so a second call is a no-op
}

// Safety net: however this run ends, the child server must not be
// left listening.
register_shutdown_function(function () use (&$proc) {
    stop_server($proc);
});

if (!is_resource($proc)) {
    check('PHP built-in server starts', false, 'proc_open failed');
} else {
    // Wait up to ~10s for the server to accept connections (Windows
    // can take a moment to bind the port on a busy machine).
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        if (http_get($base . '/login.php')['status'] > 0) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check('PHP built-in server is listening', $ready, 'timed out on port ' . $port);

    if ($ready) {
        // login.php is the logged-out destination — prove it renders.
        $login = http_get($base . '/login.php');
        check('GET /login.php returns 200', $login['status'] === 200, 'status ' . $login['status']);

        // The branded error page must actually answer with a 404,
        // otherwise search engines and clients are told the wrong
        // thing about a missing URL.
        $nf = http_get($base . '/404.php');
        check('GET /404.php answers 404', $nf['status'] === 404, 'status ' . $nf['status']);

        // The manifest is a static file: it must be served as JSON.
        $mani = http_get($base . '/manifest.webmanifest');check(
    'GET /manifest.webmanifest is served',
    $mani['status'] === 200,
    'status ' . $mani['status']
);

        // The retired directory URL must really be gone: .htaccess
        // routes a missing file to 404.php, which answers 404 — not a
        // 200 (something is still serving) and not a 5xx (broken).
        $dir = http_get($base . '/directory.php');
        check(
            'GET /directory.php is retired (404)',
            $dir['status'] === 404,
            'status ' . $dir['status'] . ($dir['err'] !== '' ? ' / ' . $dir['err'] : '')
        );

        // The catalogue tab everything now deep links into must resolve.
        // The #feedCatalogue fragment is client-side, so the request is
        // the plain Home tab URL.
        $home = http_get($base . '/dashboard.php?tab=home');
        $homeOk = $home['status'] === 200
               || ($home['status'] === 302 && strpos($home['location'], 'login.php') !== false);
        check(
            'GET /dashboard.php?tab=home loads (200, or 302 -> login.php)',
            $homeOk,
            'status ' . $home['status'] . ($home['err'] !== '' ? ' / ' . $home['err'] : '')
        );

        // Whatever a dashboard visitor is redirected to must resolve.
        $dash = http_get($base . '/dashboard.php');
        $dashOk = $dash['status'] === 200
               || ($dash['status'] === 302 && strpos($dash['location'], 'login.php') !== false);
        check(
            'GET /dashboard.php loads (200, or 302 -> login.php)',
            $dashOk,
            'status ' . $dash['status'] . ($dash['err'] !== '' ? ' / ' . $dash['err'] : '')
        );

        // Every catalogue deep link found on the dashboard must resolve
        // over HTTP: request its target path and reject 4xx/5xx.
        preg_match_all('/href\s*=\s*["\'](dashboard\.php\?tab=home[^"\']*)["\']/', $dashboard, $lm);
        $targets = array_unique(array_map(static function (string $u): string {
            return preg_split('/[?#]/', $u)[0] . '?tab=home';
        }, $lm[1]));
        check('dashboard has at least one catalogue link to follow', $targets !== []);
        foreach ($targets as $target) {
            $res = http_get($base . '/' . $target);
            check(
                "catalogue link resolves over HTTP: $target",
                $res['status'] > 0 && $res['status'] < 400,
                'status ' . $res['status'] . ($res['err'] !== '' ? ' / ' . $res['err'] : '')
            );
        }
    }

    // Shut the server down and show its log only when something broke.
    stop_server($proc);
    if ($fail > 0 && is_file($logFile)) {
        $log = trim((string) file_get_contents($logFile));
        if ($log !== '') {
            echo "\n-- server log --\n" . $log . "\n";
        }
    }
}
@unlink($logFile);

// ============================================================
// SUMMARY
// ============================================================
echo "\n=== $pass passed, $fail failed ===\n";
if ($fail > 0) {
    echo "Failed checks:\n";
    foreach ($failed as $label) {
        echo "  - $label\n";
    }
    exit(1);
}
echo "The dashboard, its feed and its catalogue are intact and reachable.\n";
exit(0);
