<?php
// ============================================================
// render_smoke_test.php — "Does every page still render?" check
// A standalone CLI diagnostic (no test framework in this project),
// run from the project root:
//
//     php render_smoke_test.php
//
// WHY IT EXISTS: directory_smoke_test.php proves the two public
// pages load for a LOGGED-OUT visitor. This one goes further and
// loads every page for a LOGGED-IN user — the state a real visitor
// spends all their time in — with full error reporting switched on,
// so a PHP notice, a missing variable or a broken query shows up as
// a failed check instead of a blank panel someone notices later.
//
// HOW IT AUTHENTICATES WITHOUT A PASSWORD: it mints a real PHP
// session (the same session files the web server reads), stores one
// existing user's id in it, and sends that cookie with every
// request. Nothing is written to the database and no password is
// needed. If the database has no users yet the script skips the
// render checks and says so.
//
// Exit code 0 = every page rendered clean, 1 = something broke.
//
// COMMAND LINE ONLY: it starts a web server on a spare port, which
// is not something a browser should ever be able to trigger.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Output buffering is on from the very first line: the session is
// minted below (step 2), and PHP refuses to touch session cookies or
// ids once anything has been printed, so no echo may run before it.
ob_start();

$pass   = 0;
$fail   = 0;
$failed = [];

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

// --- 1. Pick a user + a listing to render with ------------------
require $incDir . '/db.php';

$userId = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if ($userId === false) {
    echo "\n=== islaFIND logged-in render smoke test ===\n\n";
    echo "No users in the database yet — nothing to render as.\n";
    echo "Create an account through login.php, then run this again.\n";
    exit(0);
}
$userId = (int) $userId;

// A second account (if any) exercises the "chat with someone else" view.
$otherId = (int) ($pdo->query("SELECT id FROM users WHERE id <> $userId ORDER BY id LIMIT 1")->fetchColumn() ?: 0);

// A listing owned by the user, so the edit form renders on a real id.
$listingId = (int) ($pdo->query("SELECT id FROM providers WHERE user_id = $userId ORDER BY id LIMIT 1")->fetchColumn() ?: 0);

// --- 1b. A temporary listing from the OTHER account ----------------
// The bookmark toggle and the directory card markup both need at
// least one listing that does not belong to the user under test. A
// real deployment may have none, so one is created here and removed
// again by the shutdown handler below — the database is left exactly
// as it was found.
$tmpListingId = 0;
if ($otherId > 0) {
    $tmpListingId = (int) ($pdo->query("SELECT id FROM providers WHERE user_id = $otherId ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    if ($tmpListingId === 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO providers (user_id, profile_type, selected_title, municipality, barangay, profile_description)
             VALUES (:uid, 'individual', 'mechanic', 'Santa Fe', 'Poblacion', 'Temporary listing created by render_smoke_test.php')"
        );
        $stmt->execute([':uid' => $otherId]);
        $tmpListingId = (int) $pdo->lastInsertId();
    }
}

// --- 2. Mint a session for that user ----------------------------
// Same session storage the web server uses, so the pages under test
// see a normal logged-in visitor.
//
// That has to be taken literally when the app is configured with
// SESSION_DRIVER=mysql: the server under test would read the session
// from the database, so a session minted into PHP's file storage here
// would be invisible to it and every "renders clean" check below would
// fail as a signed-out redirect. Registering the same handler keeps
// this test honest under either driver.
require_once $incDir . '/session_store.php';
isla_session_register();

$sid = bin2hex(random_bytes(16));
session_id($sid);
session_start();
$_SESSION['user_id']        = $userId;
$_SESSION['full_name']      = 'Render Smoke';
$_SESSION['email']          = 'render@example.test';
$_SESSION['user_id_custom'] = 0;
session_write_close();

echo "\n=== islaFIND logged-in render smoke test ===\n\n";

// --- 3. Start the built-in server with errors visible -----------
$port    = random_int(20000, 60000);
$base    = 'http://127.0.0.1:' . $port;
$logFile = tempnam(sys_get_temp_dir(), 'isla_render_');
$proc    = proc_open(
    [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-S', '127.0.0.1:' . $port, '-t', $web],
    [0 => ['file', $logFile, 'a'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
    $pipes,
    $root
);

if (!is_resource($proc)) {
    fwrite(STDERR, "Could not start the PHP built-in server.\n");
    exit(1);
}

/**
 * stop_server()
 * Kills the child PHP server for good. proc_terminate() alone can
 * leave the process alive on Windows, where a surviving server keeps
 * its port bound AND holds this terminal's output handle open (which
 * is what makes an interrupted run look like it is still running).
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

// Safety net: if this script dies for any reason, the child server
// must not be left listening, and every row this run created is
// removed again.
register_shutdown_function(function () use (&$proc, &$logFile, $pdo, $userId, $otherId, &$tmpListingId) {
    stop_server($proc);
    @unlink($logFile);

    if ($pdo instanceof PDO) {
        try {
            // Drop any bookmark the tests made...
            $pdo->prepare('DELETE FROM saved_listings WHERE user_id = :uid')->execute([':uid' => $userId]);
            // ...and the throwaway listing, but only if THIS run created it.
            if ($tmpListingId > 0 && $otherId > 0) {
                $stmt = $pdo->prepare(
                    "DELETE FROM providers
                     WHERE id = :id AND user_id = :uid
                       AND profile_description = 'Temporary listing created by render_smoke_test.php'"
                );
                $stmt->execute([':id' => $tmpListingId, ':uid' => $otherId]);
            }

            // ...and every login-throttling probe row. Step 5 below
            // hammers those identifiers and IPs on purpose, so they
            // must not outlive the run: a leftover row would mean a
            // real visitor who happened to use one of them started
            // life inside a backoff.
            $pdo->exec(
                "DELETE FROM login_attempts
                  WHERE subject LIKE 'render-smoke%'
                     OR subject IN ('203.0.113.9', '203.0.113.10')"
            );
        } catch (PDOException $e) {
            fwrite(STDERR, "Cleanup warning: " . $e->getMessage() . "\n");
        }
    }
});

/**
 * GET a page with the logged-in cookie. Returns the status, the
 * body, and every PHP diagnostic printed inside the body.
 */
function render_get(string $url, string $sid, array &$diag): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_COOKIE         => 'PHPSESSID=' . $sid,
    ]);
    $raw    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    // Split headers from the body at the first blank line.
    $split = preg_split("/\r?\n\r?\n/", $raw, 2);
    $body  = $split[1] ?? '';

    $diag = array_merge($diag, php_diagnostics($body));

    return ['status' => $status, 'body' => $body, 'err' => $err];
}

/**
 * php_diagnostics()
 * Returns every PHP diagnostic printed inside a response body
 * ("Warning: ...", "Fatal error: ...", ...).
 *
 * HTML comments are stripped first: the app's own markup is full of
 * prose like "Account sync notice: what the card will inherit", which
 * would otherwise look exactly like a PHP notice.
 */
function php_diagnostics(string $body): array
{
    $scan = preg_replace('/<!--.*?-->/s', '', $body);
    if (!preg_match_all('/\b(Fatal error|Parse error|Uncaught|Warning|Notice|Deprecated)\s*:/', (string) $scan, $m)) {
        return [];
    }

    $found = [];
    foreach (array_unique($m[0]) as $hit) {
        $found[] = trim($hit);
    }
    return $found;
}

/**
 * render_post()
 * Submits a form against the running server with the logged-in
 * cookie and returns the status + Location header (no following),
 * so a redirect is observable. Diagnostics in the body are collected
 * exactly like render_get().
 */
function render_post(string $url, string $sid, array $fields, array &$diag): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_COOKIE         => 'PHPSESSID=' . $sid,
    ]);
    $raw    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    $split = preg_split("/\r?\n\r?\n/", $raw, 2);
    $head  = $split[0] ?? '';
    $body  = $split[1] ?? '';

    $location = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $head, $lm)) {
        $location = trim($lm[1]);
    }

    $diag = array_merge($diag, php_diagnostics($body));

    return ['status' => $status, 'location' => $location, 'body' => $body, 'err' => $err];
}

// Wait for the server to accept connections. Windows can take a
// moment to bind the port, so allow ~10 seconds before giving up.
$ready = false;
for ($i = 0; $i < 100; $i++) {
    $probeDiag = [];                      // render_get() fills this by reference
    $probe     = render_get($base . '/index.php', $sid, $probeDiag);
    if ($probe['status'] > 0) { $ready = true; break; }
    usleep(100000);
}

echo "-- Signed in as user #$userId" . ($otherId ? " (other account #$otherId)" : '') . "\n";

if (!$ready) {
    check('PHP built-in server is listening', false, 'timed out on port ' . $port);
} else {
    // Every page a signed-in user can reach. 'tab' targets each
    // dashboard panel, so one broken panel cannot hide behind a
    // working default.
    $pages = [
        'dashboard (home feed)'   => 'dashboard.php?tab=home',
        'dashboard (settings)'    => 'dashboard.php?tab=menu',
        'dashboard (profile)'     => 'dashboard.php?tab=profile',
        'dashboard (my listings)' => 'dashboard.php?tab=isla',
        'dashboard (security)'    => 'dashboard.php?tab=security',
        'dashboard (jobs)'        => 'dashboard.php?tab=jobs',
        'dashboard (saved)'       => 'dashboard.php?tab=saved',
        'create profile'          => 'create_profile.php',
        'messenger (inbox)'       => 'messenger.php',
        'notifications (JSON)'    => 'notifications.php',
        '404 screen'              => '404.php',
    ];
    if ($listingId > 0) {
        $pages['edit listing #' . $listingId] = 'create_profile.php?edit=' . $listingId;
    }
    if ($otherId > 0) {
        $pages['messenger (thread)'] = 'messenger.php?chat=' . $otherId;
    }
    // The retired directory URL: .htaccess routes a missing file to
    // 404.php, so a hard 404 here IS the pass.
    $pages['retired directory.php'] = 'directory.php';

    // 404.php answers with a 404 ON PURPOSE — that status IS the pass,
    // and so does the retired directory URL above.
    $expected = ['404 screen' => 404, 'retired directory.php' => 404];

    foreach ($pages as $label => $path) {
        $diag = [];
        $res  = render_get($base . '/' . $path, $sid, $diag);
        $want = $expected[$label] ?? 200;
        $ok   = $res['status'] === $want && !$diag;
        check(
            "$label renders clean",
            $ok,
            $res['status'] !== $want
                ? 'HTTP ' . $res['status'] . ($res['err'] !== '' ? ' / ' . $res['err'] : '')
                : implode(' | ', array_slice($diag, 0, 3))
        );
    }

    // login.php for an already signed-in visitor: the app sends them
    // back into the dashboard, so a 302 is correct here — a guest
    // gets the 200 the other smoke test already covers.
    $pubDiag = [];
    $pub = render_get($base . '/login.php', $sid, $pubDiag);
    check(
        'login.php responds for a signed-in user (200, or 302 to the app)',
        in_array($pub['status'], [200, 302], true) && !$pubDiag,
        'HTTP ' . $pub['status'] . ($pubDiag ? ' / ' . implode(' | ', $pubDiag) : '')
    );

    // --- 4. Write paths -----------------------------------------
    // The pages above are read-only. These two POST families are the
    // new writes, and both carry the CSRF token the request needs —
    // fetched from the page itself, exactly as a browser would.
    echo "\n-- Writes: saved listings + delete-account gate\n";

    // Pull a real CSRF token out of a rendered page. A fresh session
    // has none until a page creates one.
    $pageDiag = [];
    $page     = render_get($base . '/dashboard.php?tab=home', $sid, $pageDiag);
    $csrf     = '';
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]{64})"/i', $page['body'], $cm)) {
        $csrf = $cm[1];
    }
    check('a CSRF token is embedded in the dashboard forms', $csrf !== '');

    // The Home feed offers the same bookmark control, and it has to sit
    // INSIDE a feed card — the heart beside the provider name — not only
    // in the detail modal that opens when a card is tapped. The modal is
    // rendered after the feed on the page, so the first .inline-save must
    // come after the card header and before the modal markup.
    $feedTopAt  = strpos($page['body'], 'class="feed-card-top"');
    $feedSaveAt = strpos($page['body'], 'class="inline-save"');
    $feedModalAt = strpos($page['body'], 'id="providerModal"');
    $feedReturnOk = (bool) preg_match(
        '/class="inline-save"[\s\S]{0,600}?name="return_to" value="home"/',
        $page['body']
    );
    check(
        'the Home feed cards carry the Save heart beside the name',
        $feedTopAt !== false && $feedSaveAt !== false && $feedModalAt !== false
            && $feedTopAt < $feedSaveAt && $feedSaveAt < $feedModalAt
            && $feedReturnOk && !$pageDiag,
        $pageDiag ? implode(' | ', $pageDiag) : 'no Save heart rendered inside a feed card'
    );

    // Somebody else's listing, so the bookmark toggle is allowed —
    // the temporary one created in step 1b.
    $foreignId = $tmpListingId;

    if ($csrf !== '' && $foreignId > 0) {
        // Clean slate for this pair, so the check is meaningful even
        // if a previous run was interrupted.
        $pdo->prepare('DELETE FROM saved_listings WHERE user_id = :uid AND provider_id = :pid')
            ->execute([':uid' => $userId, ':pid' => $foreignId]);

        $d1  = [];
        $r1  = render_post($base . '/save_listing.php', $sid, [
            'csrf_token'  => $csrf,
            'provider_id' => $foreignId,
            'return_to'   => 'home',
        ], $d1);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM saved_listings WHERE user_id = $userId AND provider_id = $foreignId")->fetchColumn();
        check(
            'save_listing.php saves a listing and returns to its card',
            // The Home return carries the catalogue card's anchor, so the
            // visitor lands back on the listing they just toggled.
            $r1['status'] === 302 && $count === 1
                && strpos($r1['location'], 'dashboard.php?tab=home#listing-' . $foreignId) !== false,
            'status ' . $r1['status'] . ' / rows ' . $count . ' / location ' . $r1['location'] . ($d1 ? ' / ' . implode(' | ', $d1) : '')
        );

        // The Home feed used to carry a "Saved only" view switch of its
        // own. It is gone: bookmarks live in the Saved Listings panel
        // (checked below), so the home feed stays a feed. Guard the
        // removal so the chip cannot quietly creep back in.
        $homeDiag = [];
        $homePage = render_get($base . '/dashboard.php?tab=home', $sid, $homeDiag);
        check(
            'the Home feed no longer offers a Saved-only chip',
            $homePage['status'] === 200
                && strpos($homePage['body'], 'id="feedSavedChip"') === false
                && strpos($homePage['body'], 'id="feedSavedSection"') === false
                && !$homeDiag,
            $homeDiag ? implode(' | ', $homeDiag) : 'saved-only markup is still rendered'
        );

        // The catalogue — what directory.php used to be — has to render
        // for real: the section, its toolbar, and an anchored card for
        // THIS listing (the very anchor save_listing.php returns to).
        check(
            'the catalogue renders every listing with its own anchor',
            $homePage['status'] === 200
                // ...and it ships HIDDEN: the search box reveals it, so an
                // empty Home feed shows the rails as before.
                && strpos($homePage['body'], 'id="feedCatalogue" hidden') !== false
                && strpos($homePage['body'], 'id="feedSort"') !== false
                && strpos($homePage['body'], 'data-cat=') !== false
                && preg_match('/id="listing-' . $foreignId . '"/', $homePage['body']) === 1
                && !$homeDiag,
            $homeDiag ? implode(' | ', $homeDiag) : 'catalogue markup missing'
        );

        $d2  = [];
        $r2  = render_post($base . '/save_listing.php', $sid, [
            'csrf_token'  => $csrf,
            'provider_id' => $foreignId,
            'return_to'   => 'home',
        ], $d2);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM saved_listings WHERE user_id = $userId AND provider_id = $foreignId")->fetchColumn();
        check(
            'the same button un-saves it (toggle)',
            $r2['status'] === 302 && $count === 0,
            'status ' . $r2['status'] . ' / rows ' . $count . ' / location ' . $r2['location'] . ($d2 ? ' / ' . implode(' | ', $d2) : '')
        );

        // The saved VIEW itself needs no separate request now: bookmarks
        // live in the Saved Listings panel (checked below), so the Home
        // feed carries no saved-only view of its own.

        // Put the bookmark back (the toggle above removed it), because
        // the two checks below need a saved row to find.
        $d6 = [];
        render_post($base . '/save_listing.php', $sid, [
            'csrf_token'  => $csrf,
            'provider_id' => $foreignId,
            'return_to'   => 'home',
        ], $d6);

        // The dashboard's own Saved Listings panel must also show the
        // same bookmark (it reads the same table independently).
        $panelDiag = [];
        $panel     = render_get($base . '/dashboard.php?tab=saved', $sid, $panelDiag);
        $onPanel   = strpos($panel['body'], 'id="saved-listing-' . $foreignId . '"') !== false;
        $hasRemove = (bool) preg_match('/id="saved-listing-' . $foreignId . '"[\s\S]{0,4000}?return_to" value="saved"/', $panel['body']);
        check(
            'the Saved Listings panel lists the bookmark',
            $panel['status'] === 200 && $onPanel && $hasRemove && !$panelDiag,
            'status ' . $panel['status']
                . ($onPanel ? '' : ' / card missing')
                . ($hasRemove ? '' : ' / remove form missing')
                . ($panelDiag ? ' / ' . implode(' | ', $panelDiag) : '')
        );

        // Back to a clean slate for the next run, and the panel must
        // fall back to its empty state once the bookmark is gone.
        $d7 = [];
        render_post($base . '/save_listing.php', $sid, [
            'csrf_token'  => $csrf,
            'provider_id' => $foreignId,
            'return_to'   => 'home',
        ], $d7);

        $panelDiag2 = [];
        $panel2     = render_get($base . '/dashboard.php?tab=saved', $sid, $panelDiag2);
        check(
            'the panel shows its empty state once nothing is saved',
            $panel2['status'] === 200
                && strpos($panel2['body'], 'id="saved-listing-' . $foreignId . '"') === false
                && strpos($panel2['body'], 'Nothing saved yet') !== false
                && !$panelDiag2,
            'status ' . $panel2['status'] . ($panelDiag2 ? ' / ' . implode(' | ', $panelDiag2) : '')
        );
    }

    // Your own listing can never be bookmarked (the server refuses).
    if ($csrf !== '' && $listingId > 0) {
        $d3  = [];
        $r3  = render_post($base . '/save_listing.php', $sid, [
            'csrf_token'  => $csrf,
            'provider_id' => $listingId,
            'return_to'   => 'directory',
        ], $d3);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM saved_listings WHERE user_id = $userId AND provider_id = $listingId")->fetchColumn();
        check(
            'your own listing is refused',
            $r3['status'] === 302 && $count === 0,
            'status ' . $r3['status'] . ' / rows ' . $count . ($d3 ? ' / ' . implode(' | ', $d3) : '')
        );
    }

    // A bogus CSRF token must not change anything either.
    if ($foreignId > 0) {
        $pdo->prepare('DELETE FROM saved_listings WHERE user_id = :uid AND provider_id = :pid')
            ->execute([':uid' => $userId, ':pid' => $foreignId]);
        $d4  = [];
        $r4  = render_post($base . '/save_listing.php', $sid, [
            'csrf_token'  => str_repeat('0', 64),
            'provider_id' => $foreignId,
            'return_to'   => 'directory',
        ], $d4);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM saved_listings WHERE user_id = $userId AND provider_id = $foreignId")->fetchColumn();
        check(
            'a forged token saves nothing',
            $count === 0 && !$d4,
            'rows ' . $count . ($d4 ? ' / ' . implode(' | ', $d4) : '')
        );
    }

    // Delete Account: a wrong password must be rejected, and the
    // user row must still be there afterwards. (The SUCCESS path is
    // deliberately never exercised here — this script must not be
    // able to destroy a real account.)
    if ($csrf !== '') {
        $d5 = [];
        $r5 = render_post($base . '/dashboard.php', $sid, [
            'csrf_token'      => $csrf,
            'delete_account'  => '1',
            'delete_password' => 'definitely-not-the-password-123',
            'confirm_delete'  => 'DELETE',
        ], $d5);
        $stillThere = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE id = $userId")->fetchColumn();
        check(
            'delete-account refuses a wrong password and keeps the account',
            $r5['status'] === 200 && $stillThere === 1
                && strpos($r5['body'], 'password is incorrect') !== false && !$d5,
            'status ' . $r5['status'] . ' / user rows ' . $stillThere . ($d5 ? ' / ' . implode(' | ', $d5) : '')
        );
    }

    // --- 5. Listing photos: a picture of its own + the album -----
    // Everything above proves a listing RENDERS; this proves the photo
    // controls actually work, because the rules that matter (the 5-photo
    // cap, the 1-picture floor, the individual/business split, the
    // ownership check) live in a handler no page render can reach.
    //
    // It runs against a THROWAWAY listing owned by the account under
    // test, and every file it writes is deleted again below and by the
    // shutdown handler — nothing here may leave a picture or a row
    // behind in a real database.
    echo "\n-- Listing photos (own picture + business album)\n";

    require_once $incDir . '/uploads.php';

    /** A real 1x1 PNG, so the handler's getimagesize() test is genuine. */
    function tiny_png_bytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    /** A real 1x1 JPEG, so the second accepted mime type is covered too. */
    function tiny_jpeg_bytes(): string
    {
        return base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
            . 'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='
        );
    }

    /**
     * posts_files()
     * A multipart/form-data POST through curl, which is the only way to
     * send $_FILES over HTTP.
     *
     * The body is assembled BY HAND rather than left to curl's array
     * form, because a browser's <input type="file" multiple
     * name="album_photos[]"> sends the same field name once per chosen
     * file, with the [] as part of the name. curl's array shorthand
     * numbers the keys instead (album_photos[0]), which PHP hands back
     * NESTED — a shape the handler correctly does not have to support.
     * Writing the parts out produces the exact bytes a browser sends,
     * so this exercises the real $_FILES structure.
     */
    function posts_files(string $url, string $sid, array $fields): array
    {
        $boundary = '----islaRenderTest' . bin2hex(random_bytes(8));
        $body     = '';

        foreach ($fields as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $path) {
                    $body .= "--$boundary\r\n"
                        . 'Content-Disposition: form-data; name="' . $name . '[]"; filename="' . basename((string) $path) . '"' . "\r\n"
                        . "Content-Type: application/octet-stream\r\n\r\n"
                        . (string) file_get_contents((string) $path) . "\r\n";
                }
            } elseif (is_string($value) && is_file($value)) {
                $body .= "--$boundary\r\n"
                    . 'Content-Disposition: form-data; name="' . $name . '"; filename="' . basename($value) . '"' . "\r\n"
                    . "Content-Type: application/octet-stream\r\n\r\n"
                    . (string) file_get_contents($value) . "\r\n";
            } else {
                $body .= "--$boundary\r\n"
                    . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n"
                    . (string) $value . "\r\n";
            }
        }
        $body .= "--$boundary--\r\n";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: multipart/form-data; boundary=' . $boundary],
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_COOKIE         => 'PHPSESSID=' . $sid,
        ]);
        $raw    = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $head = preg_split("/\r?\n\r?\n/", $raw, 2)[0] ?? '';
        $loc  = preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '';

        return ['status' => $status, 'location' => $loc];
    }

    $photoListingId = 0;
    $photoFiles     = [];
    $testListingIds = [];
    $uploadDir      = isla_uploads_dir();

    // A throwaway BUSINESS listing: a shop is the one profile type the
    // album exists for, so the cap and the individual refusal are both
    // measurable against real rows.
    $stmt = $pdo->prepare(
        "INSERT INTO providers (user_id, profile_type, name, selected_title, municipality, barangay, unit_inventory)
         VALUES (:uid, 'business', 'Render Photo Test Shop', 'hardware', 'Santa Fe', 'Poblacion', 3)"
    );
    $stmt->execute([':uid' => $userId]);
    $photoListingId = (int) $pdo->lastInsertId();
    $testListingIds[] = $photoListingId;

    // Two image files to upload, one of each accepted type.
    $pngPath = tempnam(sys_get_temp_dir(), 'isla_png_') . '.png';
    $jpgPath = tempnam(sys_get_temp_dir(), 'isla_jpg_') . '.jpg';
    file_put_contents($pngPath, tiny_png_bytes());
    file_put_contents($jpgPath, tiny_jpeg_bytes());

    // Cleanup, whether the run gets here or dies later.
    //
    // The shutdown handler is the ONLY cleanup path, not the tidier at
    // the end of the section: this test writes to the real database and
    // the real uploads folder, so a fatal error halfway through must not
    // leave "Render Photo Test Shop" listings and orphan album rows
    // behind for the next visitor to see. register_shutdown_function()
    // still runs on a fatal, an uncaught error and exit().
    //
    // The floor checks below switch the account's own avatar off and
    // back on, so the original value is restored from here as well: an
    // interrupted run must not leave the account pointing at a file
    // this test is about to delete.
    $savedAccountPic   = null;
    $accountPicTouched = false;
    $cleanupPhotos = function () use (&$photoFiles, &$testListingIds, $uploadDir, $pngPath, $jpgPath, &$savedAccountPic, &$accountPicTouched, $pdo, $userId) {
        // Files first: the rows are about to go, and once a row is gone
        // its filename is unreachable from the database.
        foreach ($photoFiles as $name) {
            if (is_file($uploadDir . '/' . $name)) {
                @unlink($uploadDir . '/' . $name);
            }
        }
        // The album rows go with the listing by cascade, but any row
        // whose provider was already deleted by hand still needs its
        // own file collected, which is why the names above are tracked
        // separately rather than read back from the listing.
        $delete = $pdo->prepare('DELETE FROM providers WHERE id = :id');
        foreach ($testListingIds as $id) {
            if ($id > 0) {
                $delete->execute([':id' => $id]);
            }
        }
        if ($accountPicTouched) {
            $pdo->prepare('UPDATE users SET profile_picture = :pic WHERE id = :uid')
                ->execute([':pic' => $savedAccountPic, ':uid' => $userId]);
        }
        @unlink($pngPath);
        @unlink($jpgPath);
    };
    register_shutdown_function($cleanupPhotos);

    if ($csrf !== '' && $photoListingId > 0) {
        // --- The listing's OWN picture -----------------------------
        $d6 = [];
        $r6 = posts_files($base . '/upload_listing_photos.php', $sid, [
            'csrf_token' => $csrf,
            'action'     => 'cover',
            'profile_id' => $photoListingId,
            'cover_photo' => $pngPath,
        ]);
        $coverName = $pdo->query("SELECT profile_picture FROM providers WHERE id = $photoListingId")->fetchColumn();
        $coverName = $coverName === false ? '' : (string) $coverName;
        if ($coverName !== '') {
            $photoFiles[] = $coverName;
        }
        check(
            'a listing gets a picture of its own, stored as a real file',
            $r6['status'] === 302 && $coverName !== ''
                && is_file($uploadDir . '/' . $coverName)
                && strpos($coverName, 'listing_' . $photoListingId . '_') === 0,
            'status ' . $r6['status'] . ' / stored as "' . $coverName . '"'
        );

        // Replacing it must not leave the previous file behind: the
        // handler deletes the old cover only after the new one is
        // safely stored, so uploads/ never accumulates orphans.
        $oldCover = $coverName;
        $r7 = posts_files($base . '/upload_listing_photos.php', $sid, [
            'csrf_token'  => $csrf,
            'action'      => 'cover',
            'profile_id'  => $photoListingId,
            'cover_photo' => $jpgPath,
        ]);
        $newCover = (string) ($pdo->query("SELECT profile_picture FROM providers WHERE id = $photoListingId")->fetchColumn() ?: '');
        if ($newCover !== '') {
            $photoFiles[] = $newCover;
        }
        check(
            'replacing a listing picture removes the old file',
            $r7['status'] === 302 && $newCover !== '' && $newCover !== $oldCover
                && !is_file($uploadDir . '/' . $oldCover)
                && is_file($uploadDir . '/' . $newCover),
            'old "' . $oldCover . '" still on disk / new "' . $newCover . '"'
        );

        // --- The album, up to the cap ------------------------------
        // Five in one batch: the limit is 5, so this is the most a
        // listing can ever hold and it must be accepted.
        $r8 = posts_files($base . '/upload_listing_photos.php', $sid, [
            'csrf_token'   => $csrf,
            'action'       => 'album_add',
            'profile_id'   => $photoListingId,
            'album_photos' => [$pngPath, $jpgPath, $pngPath, $jpgPath, $pngPath],
        ]);
        $albumRows = $pdo->query("SELECT image_name FROM provider_album_images WHERE provider_id = $photoListingId ORDER BY sort_order, id")
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($albumRows as $n) {
            $photoFiles[] = (string) $n;
        }
        check(
            'a business listing accepts a full album of 5',
            $r8['status'] === 302 && count($albumRows) === ISLA_ALBUM_MAX_PHOTOS
                && count(array_filter($albumRows, fn($n) => is_file($uploadDir . '/' . $n))) === ISLA_ALBUM_MAX_PHOTOS,
            'status ' . $r8['status'] . ' / ' . count($albumRows) . ' rows'
        );

        // The sixth must be REFUSED, not trimmed: a silent drop would
        // leave the owner believing every photo they picked was saved.
        $r9 = posts_files($base . '/upload_listing_photos.php', $sid, [
            'csrf_token'   => $csrf,
            'action'       => 'album_add',
            'profile_id'   => $photoListingId,
            'album_photos' => [$pngPath],
        ]);
        $afterOverflow = (int) $pdo->query("SELECT COUNT(*) FROM provider_album_images WHERE provider_id = $photoListingId")->fetchColumn();
        check(
            'the 6th photo is refused (the cap is a hard limit)',
            $r9['status'] === 302 && $afterOverflow === ISLA_ALBUM_MAX_PHOTOS,
            'rows after the 6th attempt: ' . $afterOverflow
        );

        // Removing one frees exactly one slot.
        $firstId = (int) $pdo->query("SELECT id FROM provider_album_images WHERE provider_id = $photoListingId ORDER BY sort_order, id LIMIT 1")->fetchColumn();
        $d10 = [];
        $r10 = render_post($base . '/upload_listing_photos.php', $sid, [
            'csrf_token' => $csrf,
            'action'     => 'album_remove',
            'profile_id' => $photoListingId,
            'image_id'   => $firstId,
        ], $d10);
        $afterRemove = (int) $pdo->query("SELECT COUNT(*) FROM provider_album_images WHERE provider_id = $photoListingId")->fetchColumn();
        check(
            'one photo can be removed, and its file goes with it',
            $r10['status'] === 302 && $afterRemove === ISLA_ALBUM_MAX_PHOTOS - 1,
            'rows after removal: ' . $afterRemove
        );

        // A photo id from a DIFFERENT listing must remove nothing: the
        // DELETE is scoped by provider_id, so a tampered form is inert.
        //
        // The other listing is CREATED here rather than hunted for in the
        // table: a check that quietly skips itself when the database
        // happens to be empty is worse than no check, because the suite
        // still reports green. Its file is tracked for cleanup like the
        // rest.
        $rivalId = 0;
        $stmt = $pdo->prepare(
            "INSERT INTO providers (user_id, profile_type, name, selected_title, municipality, barangay)
             VALUES (:uid, 'business', 'Render Photo Rival Shop', 'hardware', 'Santa Fe', 'Poblacion')"
        );
        $stmt->execute([':uid' => $otherId]);
        $rivalId = (int) $pdo->lastInsertId();
        $testListingIds[] = $rivalId;

        $rivalName = 'listing_' . $rivalId . '_' . bin2hex(random_bytes(8)) . '.png';
        file_put_contents($uploadDir . '/' . $rivalName, tiny_png_bytes());
        $photoFiles[] = $rivalName;
        $pdo->prepare(
            'INSERT INTO provider_album_images (provider_id, image_name, sort_order) VALUES (:pid, :name, 0)'
        )->execute([':pid' => $rivalId, ':name' => $rivalName]);

        $foreignPhotoId = (int) $pdo->query("SELECT id FROM provider_album_images WHERE provider_id = $rivalId ORDER BY id LIMIT 1")->fetchColumn();
        check(
            'the rival listing really does hold a photo to protect',
            $foreignPhotoId > 0,
            'no album row on the rival listing'
        );

        $d11 = [];
        $r11 = render_post($base . '/upload_listing_photos.php', $sid, [
            'csrf_token' => $csrf,
            'action'     => 'album_remove',
            'profile_id' => $photoListingId,
            'image_id'   => $foreignPhotoId,
        ], $d11);
        $rivalRowLeft = (int) $pdo->query("SELECT COUNT(*) FROM provider_album_images WHERE id = $foreignPhotoId")->fetchColumn();
        check(
            "another listing's photo id removes nothing",
            $r11['status'] === 302 && $rivalRowLeft === 1 && is_file($uploadDir . '/' . $rivalName),
            'rival album rows left: ' . $rivalRowLeft
                . ' / file still there: ' . (is_file($uploadDir . '/' . $rivalName) ? 'yes' : 'no')
        );

        // --- Someone else's listing is off limits -------------------
        if ($foreignId > 0) {
            $before = (int) $pdo->query("SELECT COUNT(*) FROM provider_album_images WHERE provider_id = $foreignId")->fetchColumn();
            $r12 = posts_files($base . '/upload_listing_photos.php', $sid, [
                'csrf_token'   => $csrf,
                'action'       => 'album_add',
                'profile_id'   => $foreignId,
                'album_photos' => [$pngPath],
            ]);
            $after = (int) $pdo->query("SELECT COUNT(*) FROM provider_album_images WHERE provider_id = $foreignId")->fetchColumn();
            check(
                'a listing belonging to somebody else is refused',
                $r12['status'] === 302 && $before === $after,
                'rows ' . $before . ' -> ' . $after
            );
        }

        // --- The 1-photo floor --------------------------------------
        // The floor counts the account avatar as a picture, so the test
        // controls all three sources explicitly rather than depending on
        // whether the account under test happens to have a photo:
        // album emptied, account avatar switched off -> the cover is
        // the last picture and must survive.
        $pdo->prepare('DELETE FROM provider_album_images WHERE provider_id = :pid')->execute([':pid' => $photoListingId]);

        // A REAL avatar file for the fallback, so the "may be removed"
        // case below is proved by a picture that genuinely exists rather
        // than by a filename pointing at nothing.
        $testAvatarName = 'render_test_avatar_' . bin2hex(random_bytes(6)) . '.png';
        file_put_contents($uploadDir . '/' . $testAvatarName, tiny_png_bytes());
        $photoFiles[] = $testAvatarName;

        $savedAccountPic = $pdo->query("SELECT profile_picture FROM users WHERE id = $userId")->fetchColumn();
        $accountPicTouched = true;
        $pdo->prepare('UPDATE users SET profile_picture = NULL WHERE id = :uid')->execute([':uid' => $userId]);

        $d13 = [];
        $r13 = render_post($base . '/upload_listing_photos.php', $sid, [
            'csrf_token'   => $csrf,
            'action'       => 'cover',
            'profile_id'   => $photoListingId,
            'remove_cover' => '1',
        ], $d13);
        $stillCovered = (string) ($pdo->query("SELECT profile_picture FROM providers WHERE id = $photoListingId")->fetchColumn() ?: '');
        check(
            'the last picture on a listing cannot be removed',
            $r13['status'] === 302 && $stillCovered !== '',
            'profile_picture is now "' . $stillCovered . '"'
        );

        // With the account avatar back, the cover may go and the listing
        // falls through to it again (which is the whole fallback).
        $pdo->prepare('UPDATE users SET profile_picture = :pic WHERE id = :uid')
            ->execute([':pic' => $testAvatarName, ':uid' => $userId]);
        $d14 = [];
        $r14 = render_post($base . '/upload_listing_photos.php', $sid, [
            'csrf_token'   => $csrf,
            'action'       => 'cover',
            'profile_id'   => $photoListingId,
            'remove_cover' => '1',
        ], $d14);
        $cleared = $pdo->query("SELECT profile_picture FROM providers WHERE id = $photoListingId")->fetchColumn();
        check(
            'a cover may be removed when the account avatar still carries the listing',
            $r14['status'] === 302 && ($cleared === false || $cleared === null || $cleared === ''),
            'profile_picture is now "' . var_export($cleared, true) . '"'
        );

        // The album is a business feature. An individual listing is a
        // person, and the account avatar is that person.
        $indId = 0;
        $stmt = $pdo->prepare(
            "INSERT INTO providers (user_id, profile_type, selected_title, municipality, barangay, profile_description)
             VALUES (:uid, 'individual', 'mechanic', 'Santa Fe', 'Poblacion', 'Temporary individual listing created by render_smoke_test.php')"
        );
        $stmt->execute([':uid' => $userId]);
        $indId = (int) $pdo->lastInsertId();
        $testListingIds[] = $indId;

        $r15 = posts_files($base . '/upload_listing_photos.php', $sid, [
            'csrf_token'   => $csrf,
            'action'       => 'album_add',
            'profile_id'   => $indId,
            'album_photos' => [$pngPath],
        ]);
        $indRows = (int) $pdo->query("SELECT COUNT(*) FROM provider_album_images WHERE provider_id = $indId")->fetchColumn();
        check(
            'an individual listing is refused a photo album',
            $r15['status'] === 302 && $indRows === 0,
            'album rows on an individual listing: ' . $indRows
        );

        // From here the listing has NO picture of its own, so every
        // check below is "did a refused upload change nothing?".
        $coverBefore = (string) ($pdo->query("SELECT profile_picture FROM providers WHERE id = $photoListingId")->fetchColumn() ?: '');

        // --- The floor holds for the album too ----------------------
        // The listing is right now at the exact state that broke it:
        // no cover, no account avatar. One album photo is the last
        // picture on the page, and removing it must be refused.
        $pdo->prepare('UPDATE users SET profile_picture = NULL WHERE id = :uid')->execute([':uid' => $userId]);
        $rAlbum = posts_files($base . '/upload_listing_photos.php', $sid, [
            'csrf_token' => $csrf,
            'action'     => 'album_add',
            'profile_id' => $photoListingId,
            'album_photos' => [$pngPath],
        ]);
        $soleId = (int) $pdo->query("SELECT id FROM provider_album_images WHERE provider_id = $photoListingId ORDER BY sort_order, id LIMIT 1")->fetchColumn();
        $soleName = (string) ($pdo->query("SELECT image_name FROM provider_album_images WHERE id = $soleId")->fetchColumn() ?: '');
        if ($soleName !== '') {
            $photoFiles[] = $soleName;
        }

        $d18 = [];
        $r18 = render_post($base . '/upload_listing_photos.php', $sid, [
            'csrf_token' => $csrf,
            'action'     => 'album_remove',
            'profile_id' => $photoListingId,
            'image_id'   => $soleId,
        ], $d18);
        $afterSole = (int) $pdo->query("SELECT COUNT(*) FROM provider_album_images WHERE provider_id = $photoListingId")->fetchColumn();
        check(
            'the last ALBUM photo cannot be removed either',
            $r18['status'] === 302 && $afterSole === 1 && is_file($uploadDir . '/' . $soleName),
            'album rows after the attempt: ' . $afterSole . ', file still there: '
                . (is_file($uploadDir . '/' . $soleName) ? 'yes' : 'no')
        );

        // Back to the account avatar so the rest of the section runs on
        // a listing with a real fallback picture.
        $pdo->prepare('UPDATE users SET profile_picture = :pic WHERE id = :uid')
            ->execute([':pic' => $testAvatarName, ':uid' => $userId]);

        // --- A forged token uploads nothing -------------------------
        $r16 = posts_files($base . '/upload_listing_photos.php', $sid, [
            'csrf_token'   => 'not-a-real-token',
            'action'       => 'cover',
            'profile_id'   => $photoListingId,
            'cover_photo' => $pngPath,
        ]);
        $forgedPic = (string) ($pdo->query("SELECT profile_picture FROM providers WHERE id = $photoListingId")->fetchColumn() ?: '');
        check(
            'a forged token uploads nothing',
            $r16['status'] === 302 && $forgedPic === $coverBefore,
            'profile_picture went "' . $coverBefore . '" -> "' . $forgedPic . '"'
        );

        // --- A file that is not an image is refused ---------------
        $txtPath = tempnam(sys_get_temp_dir(), 'isla_txt_');
        file_put_contents($txtPath, '<?php echo "not an image";');
        $r17 = posts_files($base . '/upload_listing_photos.php', $sid, [
            'csrf_token'   => $csrf,
            'action'       => 'cover',
            'profile_id'   => $photoListingId,
            'cover_photo' => $txtPath,
        ]);
        $txtRejected = (string) ($pdo->query("SELECT profile_picture FROM providers WHERE id = $photoListingId")->fetchColumn() ?: '');
        @unlink($txtPath);
        check(
            'a renamed text file is refused (getimagesize reads the content)',
            $r17['status'] === 302 && $txtRejected === $coverBefore,
            'profile_picture went "' . $coverBefore . '" -> "' . $txtRejected . '"'
        );

        // --- The panel renders the controls it just used ------------
        $islaDiag = [];
        $islaPage = render_get($base . '/dashboard.php?tab=isla', $sid, $islaDiag);
        check(
            'the isla panel renders a picture control and the album counter',
            $islaPage['status'] === 200
                && strpos($islaPage['body'], 'name="cover_photo"') !== false
                && strpos($islaPage['body'], 'name="album_photos[]"') !== false
                && strpos($islaPage['body'], 'prov-album-count') !== false
                && !$islaDiag,
            $islaDiag ? implode(' | ', $islaDiag) : 'the photo controls did not render'
        );
    }

    // The throwaway listings and every file this section wrote are
    // dropped by the shutdown handler above, which is deliberately the
    // only cleanup path so that a fatal error in the checks below cannot
    // leave them in the real database. Nothing to do here.

    // --- 6. Login throttling ------------------------------------
    // Failed sign-in attempts are counted in the login_attempts
    // table (one counter per account, one per IP) and the login page
    // refuses an attempt that is still inside its backoff. These
    // checks drive the real helpers, then post the real form, so a
    // change that unplugs the throttle from login.php fails the run.
    //
    // Every key used here is a sentinel: identifiers under
    // render-smoke@example.test and the TEST-NET-3 addresses
    // 203.0.113.x, which are reserved for documentation and can never
    // belong to a visitor. The shutdown handler deletes every row
    // this section creates, so running the test cannot throttle
    // anybody for real.
    echo "\n-- Login throttling (failed-attempt counters)\n";

    require_once $incDir . '/security.php';

    $probeId = 'render-smoke@example.test';
    $probeIp = '203.0.113.9';

    // The helpers are FAIL-OPEN on purpose, so a missing table would
    // quietly turn every check below into a no-op instead of a
    // failure. Prove the table is really there first.
    $tbl = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $tbl->execute([':t' => 'login_attempts']);
    check(
        'the login_attempts table exists (db.php creates it at runtime)',
        (int) $tbl->fetchColumn() === 1
    );

    // A clean slate, in case an interrupted run left rows behind.
    login_throttle_clear($pdo, $probeId, $probeIp);
    check('a fresh identifier is not throttled', login_throttle_wait($pdo, $probeId, $probeIp) === 0);

    // A few honest typos must still cost nothing.
    for ($i = 0; $i < LOGIN_FREE_ATTEMPTS; $i++) {
        login_throttle_record_failure($pdo, $probeId, $probeIp);
    }
    $streakRow = $pdo->prepare("SELECT failures FROM login_attempts WHERE scope = 'account' AND subject = :s");
    $streakRow->execute([':s' => $probeId]);
    $counted  = (int) $streakRow->fetchColumn();
    $freeWait = login_throttle_wait($pdo, $probeId, $probeIp);
    check(
        'failures are counted server-side, in the table',
        $counted === LOGIN_FREE_ATTEMPTS && $freeWait === 0,
        'failures ' . $counted . ', wait ' . $freeWait . 's'
    );

    // One failure past the allowance starts the wait...
    login_throttle_record_failure($pdo, $probeId, $probeIp);
    $firstWait = login_throttle_wait($pdo, $probeId, $probeIp);
    check(
        'the next failure starts a backoff',
        $firstWait > 0 && $firstWait <= LOGIN_BACKOFF_STEP,
        'wait ' . $firstWait . 's'
    );

    // ...and every failure after that waits longer.
    login_throttle_record_failure($pdo, $probeId, $probeIp);
    $secondWait = login_throttle_wait($pdo, $probeId, $probeIp);
    check(
        'each further failure waits longer',
        $secondWait > $firstWait,
        $firstWait . 's then ' . $secondWait . 's'
    );

    // ...up to the ceiling: the wait stops growing, so the account is
    // always minutes away from being reachable again rather than shut
    // out for good.
    for ($i = 0; $i < 12; $i++) {
        login_throttle_record_failure($pdo, $probeId, $probeIp);
    }
    $maxWait = login_throttle_wait($pdo, $probeId, $probeIp);

    // The CAP itself is a property of the backoff curve, not of the
    // clock, so assert it on the pure function: however long the
    // streak grows, the wait stops at LOGIN_BACKOFF_MAX.
    check(
        'the backoff curve is capped (never a hard lock)',
        login_backoff_seconds(LOGIN_FREE_ATTEMPTS + 200) === LOGIN_BACKOFF_MAX,
        'backoff for a huge streak = ' . login_backoff_seconds(LOGIN_FREE_ATTEMPTS + 200) . 's'
    );

    // The LIVE wait is measured from the DATABASE's clock
    // (UNIX_TIMESTAMP(last_failed_at) against PHP's time()), so it
    // runs a few seconds behind on a server whose clock is not
    // perfectly in step with the web process — TiDB Cloud sat ~4s
    // behind in practice. What matters is that the wait has REACHED
    // the cap and can never pass it, not that it equals it to the
    // second, so this tolerates that skew. A cap that was missing or
    // set to the wrong value still fails: an uncapped wait is orders
    // of magnitude over the ceiling, and a wrong cap lands well below
    // the floor.
    $skew = 60;
    check(
        'the live wait has reached the cap and never passes it',
        $maxWait <= LOGIN_BACKOFF_MAX && $maxWait >= LOGIN_BACKOFF_MAX - $skew,
        'wait ' . $maxWait . 's / cap ' . LOGIN_BACKOFF_MAX . 's (tolerating ' . $skew . 's of clock skew)'
    );

    // The account key and the IP key are independent, which is the
    // point of having both: an attacker spraying MANY accounts from
    // one source leaves every account counter sitting at 1, so only
    // the IP key can see that attack at all.
    $sprayIp = '203.0.113.10';
    for ($i = 0; $i <= LOGIN_FREE_ATTEMPTS + 1; $i++) {
        login_throttle_record_failure($pdo, 'render-smoke-spray-' . $i . '@example.test', $sprayIp);
    }
    $sprayWait = login_throttle_wait($pdo, 'render-smoke-fresh@example.test', $sprayIp);
    check('one IP spraying many accounts is throttled too', $sprayWait > 0, 'wait ' . $sprayWait . 's');

    // Now the page itself: sign in as nobody, pull a real CSRF token
    // off the login panel, and post the throttled identifier.
    $anonSid = bin2hex(random_bytes(16));
    session_id($anonSid);
    session_start();
    session_write_close();                    // a fresh, signed-out session

    $anonDiag  = [];
    $anonPage  = render_get($base . '/login.php', $anonSid, $anonDiag);
    $anonCsrf  = '';
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]{64})"/i', $anonPage['body'], $cm2)) {
        $anonCsrf = $cm2[1];
    }

    $streakRow->execute([':s' => $probeId]);
    $failuresBefore = (int) $streakRow->fetchColumn();

    $blocked     = ['status' => 0, 'body' => ''];
    $blockedDiag = [];
    if ($anonCsrf !== '') {
        $blocked = render_post($base . '/login.php', $anonSid, [
            'csrf_token' => $anonCsrf,
            'mode'       => 'login',
            'identifier' => $probeId,
            'password'   => 'not-the-right-password',
        ], $blockedDiag);
    }

    $streakRow->execute([':s' => $probeId]);
    $failuresAfter = (int) $streakRow->fetchColumn();

    check(
        'the login page refuses an attempt that is inside its backoff',
        $anonCsrf !== '' && $blocked['status'] === 200
            && strpos($blocked['body'], 'Too many failed sign-in attempts') !== false,
        $anonCsrf === ''
            ? 'no CSRF token on the login panel'
            : 'HTTP ' . $blocked['status'] . ' / no throttle notice in the page'
    );

    // A refused attempt must NOT be counted again. If it were, an
    // attacker could keep pushing somebody else's release further
    // away just by hammering the form, and the cap would mean
    // nothing.
    check(
        'a refused attempt is not counted (the backoff cannot be renewed)',
        $failuresAfter === $failuresBefore,
        'failures ' . $failuresBefore . ' -> ' . $failuresAfter
    );

    // And a correct password clears both counters — the helpers are
    // exactly what login.php calls the moment password_verify()
    // succeeds.
    login_throttle_clear($pdo, $probeId, $probeIp);
    $left = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE subject IN (:id, :ip)');
    $left->execute([':id' => $probeId, ':ip' => $probeIp]);
    $rowsLeft = (int) $left->fetchColumn();
    check(
        'a successful sign-in clears the counters',
        $rowsLeft === 0 && login_throttle_wait($pdo, $probeId, $probeIp) === 0,
        'rows left ' . $rowsLeft
    );
}

if ($fail > 0 && is_file($logFile)) {
    $log = trim((string) file_get_contents($logFile));
    if ($log !== '') {
        echo "\n-- server log --\n" . $log . "\n";
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
if ($fail > 0) {
    echo "Failed checks:\n";
    foreach ($failed as $label) {
        echo "  - $label\n";
    }
    exit(1);
}
echo "Every page renders for a signed-in user.\n";
exit(0);
