<?php
// ============================================================
// responsive_smoke_test.php — "Is every page mobile/tablet ready?"
// A standalone CLI diagnostic (no test framework in this project),
// run from the project root:
//
//     php responsive_smoke_test.php
//
// WHY IT EXISTS: the layout guards in dashboard_smoke_test.php are
// measured against ONE page (the dashboard). This one sweeps EVERY
// page in the app and answers the three questions that make a page
// usable on a phone or a tablet at all:
//
//   1. Does the page declare a viewport? Without
//      <meta name="viewport" width=device-width> a mobile browser
//      renders the desktop layout zoomed out — no CSS below can save
//      it. Member pages all inherit theirs from include/head_meta.php
//      (the point of that partial), so the check is "did this page
//      render through the shared head?" for them and "does it carry
//      its own device-width viewport?" for any page that rolls its
//      own <head> (the admin panel does).
//
//   2. Do the stylesheets still carry the breakpoints the layout
//      depends on? Each one below is load-bearing, not decorative:
//      style.css's 768px and 480px blocks, and admin.css's 1024px
//      (drop the widest table columns) and 860px (fold the sidebar
//      into the hamburger) blocks. Deleting or retyping one silently
//      un-does the responsive design, and no PHP render test would
//      ever notice.
//
//   3. Do the wrap / no-overflow guards that keep a narrow screen
//      from scrolling sideways still exist? Long text that breaks,
//      flex children allowed to shrink, rows that may wrap, and
//      wide content that scrolls inside its own box. Each assertion
//      names the bug it prevents.
//
// No database and no web server are needed: it is a source check.
//
// Exit code 0 = every page is declared responsive, 1 = at least one
// problem was found.
//
// COMMAND LINE ONLY: a browser has no business running diagnostics.
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
 * is_html_document()
 * Does this PHP source emit a full HTML document? A DOCTYPE or an
 * <html> tag is the signal. Endpoints (JSON, redirects), redirect
 * wrappers and markup fragments that are <include>d into a page all
 * return false and are deliberately skipped: they have no <head> to
 * put a viewport in, and demanding one would only produce noise.
 *
 * A page that renders through the shared head is treated as a
 * document even if its own file has no literal <html> tag, because
 * head_meta.php prints it on the page's behalf.
 */
function is_html_document(string $src): bool
{
    return preg_match('/<!doctype\s+html|<html[\s>]/i', $src) === 1
        || strpos($src, 'head_meta.php') !== false;
}

// Guard: run from the project root so the paths line up.
if (!is_file(__DIR__ . '/public/dashboard.php')) {
    fwrite(STDERR, "Run this from the project root (public/dashboard.php not found).\n");
    exit(1);
}
$web    = __DIR__ . '/public';
$incDir = __DIR__ . '/include';

echo "\n=== islaFIND responsive smoke test ===\n\n";

// ============================================================
// 1. Every page declares a viewport
// ============================================================
echo "-- Every page declares a device-width viewport\n";

// The shared head is where the six member pages get their viewport;
// assert the partial itself first, then that each page uses it.
$headSrc = is_file($incDir . '/head_meta.php')
    ? (string) file_get_contents($incDir . '/head_meta.php')
    : '';
$headHasViewport = (bool) preg_match(
    '/<meta\s+name=["\']viewport["\'][^>]*content=["\'][^"\']*width\s*=\s*device-width/i',
    $headSrc
);
check(
    'include/head_meta.php declares a device-width viewport',
    $headSrc !== '' && $headHasViewport,
    $headSrc === '' ? 'file missing' : 'no width=device-width viewport meta'
);

// Every PHP file the web root can serve, member pages and the admin
// panel alike.
$candidates = array_merge(
    glob($web . '/*.php') ?: [],
    glob($web . '/admin/*.php') ?: []
);
sort($candidates);

$pages = 0;
$skipped = [];
foreach ($candidates as $file) {
    $rel = ltrim(str_replace($web, '', $file), '/\\');
    $src = (string) file_get_contents($file);

    if (!is_html_document($src)) {
        // A JSON endpoint, a redirect/handler or a fragment — no
        // document, therefore no viewport to demand.
        $skipped[] = $rel;
        continue;
    }

    $pages++;

    // Which <head> owns this page?
    if (strpos($src, 'head_meta.php') !== false) {
        // Member pages delegate to the shared partial; the assertion
        // is that the partial really is included AND carries the
        // viewport (both proved above, so this pins the wiring).
        check(
            "$rel renders through the shared head (viewport comes from head_meta.php)",
            $headHasViewport
        );
        continue;
    }

    // A page that rolls its own <head> must declare the viewport
    // itself, and it must be device-width (a fixed width is worse
    // than none on a phone).
    check(
        "$rel declares its own device-width viewport",
        (bool) preg_match(
            '/<meta\s+name=["\']viewport["\'][^>]*content=["\'][^"\']*width\s*=\s*device-width/i',
            $src
        ),
        'no <meta name="viewport" content="width=device-width ..."> in the page source'
    );
}

// Guard against the scan silently matching nothing (a move to a
// different web root, say) and reporting a hollow "all clear".
check('the sweep found every page in the app', $pages >= 8, "only $pages page(s) detected");

// ============================================================
// 2. The stylesheets still carry the key breakpoints
// ============================================================
echo "\n-- The stylesheets carry the key breakpoints\n";

/**
 * has_media()
 * Does the stylesheet define a max-width media query at $px?
 * Matched loosely (any whitespace) so reformatting the query — the
 * usual result of a running a formatter — does not fail the check,
 * while changing or deleting the breakpoint still does.
 */
function has_media(string $css, int $px): bool
{
    return (bool) preg_match('/@media\s*\(\s*max-width:\s*' . $px . 'px\s*\)/i', $css);
}

/**
 * css_block_declares()
 * Does ANY block for this exact selector declare the given value?
 * preg_quote() keeps selectors such as ".feed-head .feed-sort" literal,
 * the trailing \s*\{ keeps ".dash-card" from matching ".dash-card-wide",
 * and [^}]* confines the match to one rule so a declaration in a LATER
 * block cannot satisfy it. ALL blocks are scanned (not just the first),
 * because a selector may legitimately be split across two rules —
 * .auth-card, for instance, sets `margin` and `width` from separate
 * blocks — and a layout guard should still be found in either of them.
 */
function css_block_declares(string $css, string $selector, string $declarationPattern): bool
{
    if (!preg_match_all('/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/s', $css, $m)) {
        return false;
    }
    foreach ($m[1] as $block) {
        if (preg_match('/' . $declarationPattern . '/s', $block)) {
            return true;
        }
    }
    return false;
}

$styleCss = is_file($web . '/style.css') ? (string) file_get_contents($web . '/style.css') : '';
$adminCss = is_file($web . '/admin.css') ? (string) file_get_contents($web . '/admin.css') : '';

// style.css — the member app. 768px is the tablet/phone shell
// (full-bleed, no frame); 480px tightens the small-phone spacing.
// These two are the visible ends of the ramp, so their absence is
// exactly the "desktop layout on a phone" failure.
check('style.css defines the 768px (tablet/phone) breakpoint', has_media($styleCss, 768));
check('style.css defines the 480px (small phone) breakpoint', has_media($styleCss, 480));

// admin.css — the panel. 1024px drops the queue's two least
// load-bearing columns so a tablet does not have to scroll them;
// 860px folds the sidebar into the hamburger menu.
check('admin.css defines the 1024px (tablet) breakpoint', has_media($adminCss, 1024));
check('admin.css defines the 860px (phone / hamburger) breakpoint', has_media($adminCss, 860));

// ============================================================
// 3. The handful of primitives the breakpoints lean on
// ============================================================
echo "\n-- Mobile primitives the breakpoints lean on\n";

// 100dvh with a 100vh fallback: the app fills the visible area on a
// phone instead of the area behind the browser's own bars.
check(
    'style.css uses dvh with a vh fallback (no clipped phone viewport)',
    strpos($styleCss, '100dvh') !== false && strpos($styleCss, '100vh') !== false
);

// Notches and home indicators: fixed bars that ignore these sit
// under the phone's own chrome.
check(
    'style.css respects the safe-area insets',
    strpos($styleCss, 'env(safe-area-inset') !== false
);

// 16px+ inputs are what keeps iOS Safari from zooming the page the
// moment a field is focused.
check(
    'style.css sizes inputs at 16px (no iOS auto-zoom on focus)',
    (bool) preg_match('/\.form-group\s+input\s*\{[^}]*font-size:\s*16px/s', $styleCss)
);

// ============================================================
// 4. Layout guards — wrap, shrink, scroll in place
// ============================================================
// A viewport meta only makes the page RESPONSIVE-capable. What
// actually keeps a narrow screen from scrolling sideways is a small
// set of load-bearing rules: text that breaks instead of running
// past the card, flex children allowed to shrink, rows that may
// wrap, and wide content that scrolls inside its own box. Each
// check below names the failure it prevents; deleting one of these
// rules is exactly how a phone-only horizontal-scroll bug comes
// back. All of them live in the member stylesheet unless the check
// says otherwise.
echo "\n-- Long text breaks instead of widening the layout\n";

check(
    '.dash-row strong breaks long values (email addresses, IDs)',
    css_block_declares($styleCss, '.dash-row strong', 'word-break:\s*break-word')
);
check(
    '.pm-head .pm-name-row h4 breaks a long listing name in the detail modal',
    css_block_declares($styleCss, '.pm-head .pm-name-row h4', 'overflow-wrap:\s*break-word')
);
check(
    '.feed-card-head h5 breaks a long listing name',
    css_block_declares($styleCss, '.feed-card-head h5', 'overflow-wrap:\s*break-word')
);
check(
    '.provider-card-head h5 breaks a long listing name',
    css_block_declares($styleCss, '.provider-card-head h5', 'overflow-wrap:\s*break-word')
);
check(
    'admin .adm-kv dd breaks unbroken strings (long emails/URLs)',
    css_block_declares($adminCss, '.adm-kv dd', 'overflow-wrap:\s*anywhere|word-break')
);

echo "\n-- Flex children may shrink so a row cannot overflow\n";

check(
    'the search field can shrink (min-width: 0)',
    css_block_declares($styleCss, '.search-bar input', 'min-width:\s*0')
);
check(
    'a chat row can shrink so its preview ellipsizes (min-width: 0)',
    css_block_declares($styleCss, '.chat-meta', 'min-width:\s*0')
);
check(
    'the chat preview ellipsizes instead of pushing the time off-screen',
    css_block_declares($styleCss, '.chat-preview', 'text-overflow:\s*ellipsis')
);
check(
    'a device session row can shrink (min-width: 0)',
    css_block_declares($styleCss, '.device-meta', 'min-width:\s*0')
);
check(
    'the catalogue heading row can shrink (min-width: 0)',
    css_block_declares($styleCss, '.feed-head .feed-section-title', 'min-width:\s*0')
);

echo "\n-- Rows are allowed to wrap instead of squeezing their content\n";

check(
    'the provider card wraps its Save heart under a long name',
    css_block_declares($styleCss, '.provider-card-top', 'flex-wrap:\s*wrap')
);
check(
    'the detail modal name row wraps its pills under a long name',
    css_block_declares($styleCss, '.pm-name-row', 'flex-wrap:\s*wrap')
);
check(
    'the admin action row wraps its buttons',
    css_block_declares($adminCss, '.adm-actions-row', 'flex-wrap:\s*wrap')
);

echo "\n-- Wide content scrolls inside its own box, never the page\n";

check(
    'the feed rail scrolls horizontally inside the shell',
    css_block_declares($styleCss, '.feed-rail', 'overflow-x:\s*auto')
);
check(
    'the modal photo gallery scrolls horizontally inside the card',
    css_block_declares($styleCss, '.pm-gallery', 'overflow-x:\s*auto')
);
check(
    'the admin queue table scrolls horizontally inside its panel',
    css_block_declares($adminCss, '.adm-table-wrap', 'overflow-x:\s*auto')
);

echo "\n-- Cards cannot exceed the viewport\n";

check(
    'the auth card caps at 100% of the viewport',
    css_block_declares($styleCss, '.auth-card', 'width:\s*min\(\s*100%')
);
check(
    'the dashboard card caps at 100% of the viewport',
    css_block_declares($styleCss, '.dash-card', 'width:\s*min\(\s*100%')
);
// The create-profile type picker's hidden radio must be pinned with
// top/left: left in the flow it keeps the padding box's width while
// `width: 100%` measures it, which dragged the whole page 18-58px
// wide on a phone. This is a measured overflow bug, not a theory.
check(
    'the create-profile type picker pins its hidden radio (no sideways scroll)',
    css_block_declares($styleCss, '.type-card input', 'top:\s*0')
        && css_block_declares($styleCss, '.type-card input', 'left:\s*0')
);

// ============================================================
// SUMMARY
// ============================================================
echo "\n=== $pass passed, $fail failed ===\n";
if ($skipped !== []) {
    echo 'Skipped ' . count($skipped) . " non-page file(s): " . implode(', ', $skipped) . "\n";
}
if ($fail > 0) {
    echo "Failed checks:\n";
    foreach ($failed as $label) {
        echo "  - $label\n";
    }
    exit(1);
}
echo "Every page declares a device-width viewport, the key breakpoints are in place, and the wrap/no-overflow guards hold.\n";
exit(0);
