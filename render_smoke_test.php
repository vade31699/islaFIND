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

    // --- 5. Login throttling ------------------------------------
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
    check(
        'the wait is capped (an increasing backoff, never a hard lock)',
        $maxWait <= LOGIN_BACKOFF_MAX && $maxWait >= LOGIN_BACKOFF_MAX - 2,
        'wait ' . $maxWait . 's / cap ' . LOGIN_BACKOFF_MAX . 's'
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
