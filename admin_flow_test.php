<?php
// ============================================================
// admin_flow_test.php — end-to-end test for reporting + moderation
//
//     php admin_flow_test.php
//
// Unlike dashboard_smoke_test.php and render_smoke_test.php (which run
// without a database), this one NEEDS the configured database, because
// the whole point is that a real report travels from a member's
// browser into the admin queue and back out as a block.
//
// It runs against the real DB but only ever touches rows it created
// itself: one admin, two members, one listing and the reports filed
// against it, all tagged with a unique run id and deleted in a
// shutdown handler whether the run passed, failed or was interrupted.
//
// What it proves, in order:
//
//   1. GUARDS      — admin pages refuse a logged-out visitor and a
//                    member session.
//   2. SETTINGS    — the admin can change their password; a wrong
//                    current password and a missing CSRF token change
//                    nothing.
//   3. REPORT      — a member can file a report, it lands in the
//                    queue, and the same member cannot file a second
//                    one against the same open listing.
//   4. MODERATE    — the admin blocks the listing; every public query
//                    stops returning it while the owner still sees it
//                    and still sees why.
//   5. BLOCKED     — a blocked listing cannot be reported, saved,
//                    messaged about or hired.
//
// Exit code 0 = all checks passed, 1 = at least one failed.
//
// COMMAND LINE ONLY.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/include/db.php';

// --- Result bookkeeping ---------------------------------------
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

function section(string $title): void
{
    echo "\n-- $title\n";
}

// --- A unique run id, so two runs can never collide ------------
$runId = 'isla-test-' . bin2hex(random_bytes(4));
$adminEmail = $runId . '-admin@example.invalid';
$ownerEmail = $runId . '-owner@example.invalid';
$reporterEmail = $runId . '-reporter@example.invalid';
$ownerPassword = 'Owner-Test-2026!a';
$reporterPassword = 'Reporter-Test-2026!b';
$adminPassword = 'Admin-Test-2026!c';

// --- Fixtures + cleanup ---------------------------------------
$adminId = 0;
$ownerId = 0;
$reporterId = 0;
$listingId = 0;

function cleanup(): void
{
    global $pdo, $runId, $adminId, $ownerId, $reporterId, $listingId;

    // Each delete is attempted independently. Wrapping them all in one
    // try meant that a single failure - a table that does not exist in
    // this schema, say - aborted the rest and stranded every fixture in
    // the live database, where a later run would then trip over its own
    // leftovers. A leak is recoverable; a silent partial cleanup is not.
    $steps = [
        'reports on the listing' => 'DELETE FROM profile_reports WHERE provider_id = :v',
        'interactions on it'    => 'DELETE FROM user_interactions WHERE provider_id = :v',
        'saves of it'           => 'DELETE FROM saved_listings WHERE provider_id = :v',
        'reviews of it'         => 'DELETE FROM reviews WHERE provider_id = :v',
        'the listing itself'    => 'DELETE FROM providers WHERE id = :v',
    ];

    $problems = [];

    foreach ($steps as $label => $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':v' => $listingId]);
        } catch (Throwable $e) {
            // A table this schema does not have is not a reason to stop.
            $problems[] = $label . ' (' . $e->getMessage() . ')';
        }
    }

    foreach ([$reporterId, $ownerId] as $uid) {
        if ($uid <= 0) {
            continue;
        }
        foreach ([
            'their reports'      => 'DELETE FROM profile_reports WHERE reporter_id = :v',
            'their interactions' => 'DELETE FROM user_interactions WHERE user_id = :v',
            'their saves'        => 'DELETE FROM saved_listings WHERE user_id = :v',
            'their account'      => 'DELETE FROM users WHERE id = :v',
        ] as $label => $sql) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':v' => $uid]);
            } catch (Throwable $e) {
                $problems[] = $label . ' (user ' . $uid . ': ' . $e->getMessage() . ')';
            }
        }
    }

    if ($adminId > 0) {
        try {
            $stmt = $pdo->prepare('DELETE FROM profile_reports WHERE resolved_by = :v');
            $stmt->execute([':v' => $adminId]);
            $stmt = $pdo->prepare('DELETE FROM admins WHERE id = :v');
            $stmt->execute([':v' => $adminId]);
        } catch (Throwable $e) {
            $problems[] = 'the admin account (' . $e->getMessage() . ')';
        }
    }

    if ($problems) {
        echo "\n!! CLEANUP INCOMPLETE for run $runId - these rows may still exist:\n";
        foreach ($problems as $p) {
            echo "     - $p\n";
        }
        echo "   Remove them by hand:\n";
        echo "     DELETE FROM providers WHERE profile_code LIKE 'ISLA-TEST-%';\n";
        echo "     DELETE FROM users     WHERE email LIKE 'isla-test-%';\n";
        echo "     DELETE FROM admins    WHERE email LIKE 'isla-test-%';\n";
        return;
    }

    echo "\n(cleaned up run $runId)\n";
}
register_shutdown_function('cleanup');

section('Fixtures');

// --- The admin. mfa_enabled = 0 so the test can sign in without an
// --- inbox; the MFA path itself is exercised separately at the end.
$stmt = $pdo->prepare(
    'INSERT INTO admins (email, password_hash, mfa_enabled, is_active) VALUES (:e, :h, 0, 1)'
);
$stmt->execute([':e' => $adminEmail, ':h' => password_hash($adminPassword, PASSWORD_DEFAULT)]);
$adminId = (int) $pdo->lastInsertId();

/**
 * make_user()
 * A verified member. is_verified = 1 is the only reason this works
 * without an inbox: login.php refuses to sign in an unverified
 * account, and the code is emailed.
 *
 * user_id is an UNSIGNED INT, not a string, so it is drawn from a
 * high random range instead of hex - a hex value is rejected by the
 * column itself with "Incorrect ? value", which is a confusing way to
 * learn the type.
 */
function make_user(string $email, string $password): int
{
    global $pdo;
    $stmt = $pdo->prepare(
        'INSERT INTO users (user_id, full_name, email, phone, date_of_birth, password_hash, is_verified)
         VALUES (:uid, :name, :email, :phone, :dob, :h, 1)'
    );
    $stmt->execute([
        ':uid'   => random_int(9000000, 9999999),
        ':name'  => 'Test Person',
        ':email' => $email,
        ':phone' => '9' . random_int(100000000, 999999999),
        ':dob'   => '1990-01-01',
        ':h'     => password_hash($password, PASSWORD_DEFAULT),
    ]);
    return (int) $pdo->lastInsertId();
}

$ownerId    = make_user($ownerEmail, $ownerPassword);
$reporterId = make_user($reporterEmail, $reporterPassword);

$stmt = $pdo->prepare(
    "INSERT INTO providers (profile_type, user_id, name, profile_description, selected_title, status)
     VALUES ('business', :uid, :name, :desc, :title, 'active')"
);
$stmt->execute([
    ':uid'   => $ownerId,
    ':name'  => 'Report Test Shop',
    ':desc'  => 'A listing created by admin_flow_test.php.',
    ':title' => 'Mechanic',
]);
$listingId = (int) $pdo->lastInsertId();

// profile_code is assigned by save_profile.php in normal life; the
// test writes one so the admin queue has the same reference it would
// see in production.
$stmt = $pdo->prepare('UPDATE providers SET profile_code = :c WHERE id = :id');
$stmt->execute([':c' => 'ISLA-TEST-' . $listingId, ':id' => $listingId]);

check('the test fixtures were created', $adminId > 0 && $ownerId > 0 && $reporterId > 0 && $listingId > 0);

// ============================================================
// The web server
// ============================================================
$port    = random_int(20000, 60000);
$base    = 'http://127.0.0.1:' . $port;
$root    = __DIR__;               // project root: the child server's cwd
$web     = __DIR__ . '/public';
$logFile = tempnam(sys_get_temp_dir(), 'isla_adm_');
$proc    = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $web],
    [0 => ['file', $logFile, 'a'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
    $pipes,
    $root
);

function stop_server(&$proc): void
{
    if (!is_resource($proc)) {
        return;
    }
    $status = proc_get_status($proc);
    $pid    = (int) ($status['pid'] ?? 0);
    if (!empty($status['running']) && $pid > 0 && stripos(PHP_OS_FAMILY, 'Windows') === 0) {
        @exec('taskkill /F /PID ' . $pid . ' 2>NUL');
    } elseif (!empty($status['running'])) {
        @proc_terminate($proc, 9);
    }
    @proc_close($proc);
    $proc = null;
}
register_shutdown_function(function () use (&$proc) {
    stop_server($proc);
});

if (!is_resource($proc)) {
    check('PHP built-in server starts', false, 'proc_open failed');
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}

$up = false;
for ($i = 0; $i < 50; $i++) {
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($sock) {
        fclose($sock);
        $up = true;
        break;
    }
    usleep(100000);
}
check('PHP built-in server starts', $up);

/**
 * req(): one HTTP request against the test server.
 *
 * $jar is a curl cookie file, which is how each browser (admin,
 * member) keeps its own session. Redirects are NOT followed, because
 * the redirect itself is usually what is being asserted.
 */
function req(string $path, ?string $jar = null, array $post = null): array
{
    global $base;
    $ch = curl_init($base . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 2,
    ];
    if ($jar !== null) {
        $opts[CURLOPT_COOKIEJAR]  = $jar;
        $opts[CURLOPT_COOKIEFILE] = $jar;
    }
    if ($post !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($ch, $opts);
    $raw      = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $raw      = is_string($raw) ? $raw : '';
    $hSize    = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $location = '';
    if (preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $hSize), $m)) {
        $location = trim($m[1]);
    }
    return ['status' => $status, 'location' => $location, 'body' => substr($raw, $hSize)];
}

/** csrf_of(): pull the CSRF token out of a rendered form. */
function csrf_of(string $html): string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

/**
 * flash_of(): the one-shot message the last POST left behind.
 *
 * Both the success and the refusal path of a handler end in a redirect,
 * so the redirect alone proves nothing; the flash is what says which
 * one happened. dashboard.php routes $_SESSION['flash_isla'] into the
 * islaFIND panel's message row rather than a generic .alert, so this
 * looks for that row's wording instead.
 */
function flash_of(string $html): string
{
    if (preg_match('/<div class="alert[^"]*"[^>]*>\s*(.*?)\s*<\/div>/s', $html, $m)) {
        return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    }
    // The panel's own success/error rows.
    if (preg_match('/class="[^"]*(?:ok|success|err|error|notice)[^"]*"[^>]*>\s*(.*?)\s*<\//s', $html, $m)) {
        return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    }
    return '';
}

/**
 * sign_in(): drive the real login form for a member or the admin.
 *
 * Two GETs of the login page are needed: the first is what creates the
 * session (and the CSRF token with it), the second reads a token that
 * belongs to the cookie the server just set.
 */
function sign_in(string $path, string $jar, array $fields): array
{
    req($path, $jar);
    $page = req($path, $jar);
    $csrf = csrf_of($page['body']);
    if ($csrf === '') {
        return ['ok' => false, 'why' => 'no CSRF token in the login form', 'csrf' => ''];
    }
    $fields['csrf_token'] = $csrf;
    $res = req($path, $jar, $fields);
    return ['ok' => true, 'csrf' => $csrf, 'res' => $res];
}

// ============================================================
// 1. GUARDS
// ============================================================
section('Guards: who may open the admin panel');

$anonJar = tempnam(sys_get_temp_dir(), 'isla_jar_');
$r = req('/admin/index.php', $anonJar);
check(
    'a logged-out visitor is sent to the admin login',
    $r['status'] === 302 && strpos($r['location'], 'login.php') !== false,
    'status ' . $r['status'] . ' -> ' . $r['location']
);

$memberJar = tempnam(sys_get_temp_dir(), 'isla_jar_');
$login = sign_in('/login.php', $memberJar, [
    'identifier' => $reporterEmail,
    'password' => $reporterPassword,
]);
check('a test member can sign in', $login['ok'] && ($login['res']['status'] ?? 0) === 302,
    $login['ok'] ? 'status ' . $login['res']['status'] . ' -> ' . $login['res']['location'] : $login['why']);

$r = req('/admin/index.php', $memberJar);
// Either answer is a refusal: bounced to the login form, or (because
// the member IS signed in, just not as an admin) sent back to their own
// dashboard. What must never happen is the panel rendering.
check(
    'a member session cannot open the admin panel',
    $r['status'] === 302 && strpos($r['body'], 'Superadmin') === false,
    'status ' . $r['status'] . ' -> ' . $r['location']
);
$r = req('/admin/settings.php', $memberJar);
check(
    'a member session cannot open admin settings',
    $r['status'] === 302,
    'status ' . $r['status']
);

// ============================================================
// 2. ADMIN SIGN-IN + SETTINGS
// ============================================================
section('Admin sign-in and settings');

$adminJar = tempnam(sys_get_temp_dir(), 'isla_jar_');
$login = sign_in('/login.php?admin=1', $adminJar, [
    'identifier' => $adminEmail,
    'password' => $adminPassword,
]);
check('the admin can sign in with MFA off', $login['ok'] && ($login['res']['status'] ?? 0) === 302,
    $login['ok'] ? 'status ' . $login['res']['status'] . ' -> ' . $login['res']['location'] : $login['why']);

$r = req('/admin/index.php', $adminJar);
check('the admin panel opens for the admin', $r['status'] === 200 && strpos($r['body'], 'Superadmin') !== false,
    'status ' . $r['status']);

// Settings is a HUB: the landing page lists the choices and links on
// to a section, and each section is its own screen. Assert that shape,
// then open each section — the token for the POSTs below comes from the
// Password screen, which is where the change-password form lives.
$r = req('/admin/settings.php', $adminJar);
check('admin settings renders the option hub', $r['status'] === 200
    && strpos($r['body'], 'Account security') !== false
    && strpos($r['body'], 'Email address') !== false
    && strpos($r['body'], 'Password') !== false
    && strpos($r['body'], 'Two-factor') !== false,
    'status ' . $r['status']);
check('the settings hub links on to each section',
    strpos($r['body'], 'settings.php?section=email') !== false
        && strpos($r['body'], 'settings.php?section=password') !== false
        && strpos($r['body'], 'settings.php?section=mfa') !== false);
check('the settings hub does not carry the change forms',
    preg_match('/type="password"/', $r['body']) === 0);
check('admin settings shows the admin\'s own email', strpos($r['body'], $adminEmail) !== false);

$rEmail = req('/admin/settings.php?section=email', $adminJar);
check('the email section opens on its own screen', $rEmail['status'] === 200
    && strpos($rEmail['body'], 'Change email address') === false
    && strpos($rEmail['body'], 'name="new_email"') !== false,
    'status ' . $rEmail['status']);

$rMfa = req('/admin/settings.php?section=mfa', $adminJar);
check('the two-factor section opens on its own screen', $rMfa['status'] === 200
    && strpos($rMfa['body'], 'name="current_password"') !== false
    && strpos($rMfa['body'], 'name="new_email"') === false,
    'status ' . $rMfa['status']);

// An unknown section falls back to the hub rather than erroring.
$rBogus = req('/admin/settings.php?section=nope', $adminJar);
check('an unknown settings section falls back to the hub',
    $rBogus['status'] === 200 && strpos($rBogus['body'], 'Account security') !== false,
    'status ' . $rBogus['status']);

$rPassword = req('/admin/settings.php?section=password', $adminJar);
check('the password section opens on its own screen', $rPassword['status'] === 200
    && strpos($rPassword['body'], 'name="new_password"') !== false,
    'status ' . $rPassword['status']);
check('admin settings never renders a password field value',
    preg_match('/type="password"[^>]*value="[^"]+"/', $rPassword['body']) === 0);

$settingsCsrf = csrf_of($rPassword['body']);

// --- CSRF is refused -----------------------------------------
$hashBefore = (string) $pdo->query("SELECT password_hash FROM admins WHERE id = " . $adminId)->fetchColumn();
req('/admin/settings.php', $adminJar, [
    'do'               => 'change_password',
    'current_password' => $adminPassword,
    'new_password'     => 'Should-Not-Apply-1!',
    'confirm_password' => 'Should-Not-Apply-1!',
]);
$hashAfterCsrf = (string) $pdo->query("SELECT password_hash FROM admins WHERE id = " . $adminId)->fetchColumn();
check('a settings POST without a CSRF token changes nothing', $hashBefore === $hashAfterCsrf);

// --- Wrong current password is refused -----------------------
$r = req('/admin/settings.php', $adminJar, [
    'csrf_token'       => $settingsCsrf,
    'do'               => 'change_password',
    'current_password' => 'Wrong-Password-9!',
    'new_password'     => 'Should-Not-Apply-2!',
    'confirm_password' => 'Should-Not-Apply-2!',
]);
$hashAfterWrong = (string) $pdo->query("SELECT password_hash FROM admins WHERE id = " . $adminId)->fetchColumn();
check('a wrong current password changes nothing', $hashBefore === $hashAfterWrong);
check('the wrong-password attempt says so on the page',
    strpos($r['location'], 'settings.php') !== false, $r['location']);

// --- Mismatched confirmation is refused ----------------------
req('/admin/settings.php', $adminJar, [
    'csrf_token'       => $settingsCsrf,
    'do'               => 'change_password',
    'current_password' => $adminPassword,
    'new_password'     => 'Brand-New-Pass-1!',
    'confirm_password' => 'Brand-New-Pass-2!',
]);
$hashAfterMismatch = (string) $pdo->query("SELECT password_hash FROM admins WHERE id = " . $adminId)->fetchColumn();
check('mismatched confirmation changes nothing', $hashBefore === $hashAfterMismatch);

// --- A password that breaks the policy is refused -----------
req('/admin/settings.php', $adminJar, [
    'csrf_token'       => $settingsCsrf,
    'do'               => 'change_password',
    'current_password' => $adminPassword,
    'new_password'     => 'weak',
    'confirm_password' => 'weak',
]);
$hashAfterWeak = (string) $pdo->query("SELECT password_hash FROM admins WHERE id = " . $adminId)->fetchColumn();
check('a too-weak password is refused', $hashBefore === $hashAfterWeak);

// --- The real change ----------------------------------------
$newPassword = 'Changed-By-Settings-1!';
req('/admin/settings.php', $adminJar, [
    'csrf_token'       => $settingsCsrf,
    'do'               => 'change_password',
    'current_password' => $adminPassword,
    'new_password'     => $newPassword,
    'confirm_password' => $newPassword,
]);
$hashAfter = (string) $pdo->query("SELECT password_hash FROM admins WHERE id = " . $adminId)->fetchColumn();
check('a valid password change is applied', $hashAfter !== $hashBefore);
check('the new password verifies', password_verify($newPassword, $hashAfter));
check('the old password no longer verifies', !password_verify($adminPassword, $hashAfter));

// --- The email-change flow cannot be faked -------------------
// The address must not move until a code sent to the NEW inbox has
// come back, and the round trip must never die halfway: whether the
// mailer works or not, this either lands back on the settings page or
// renders one - it cannot fatal with the change half applied.
$emailStart = req('/admin/settings.php', $adminJar, [
    'csrf_token'       => $settingsCsrf,
    'do'               => 'start_email_change',
    'current_password' => $newPassword,
    'new_email'        => $runId . '-new@example.invalid',
]);
$emailNow = (string) $pdo->query('SELECT email FROM admins WHERE id = ' . $adminId)->fetchColumn();
check('the admin email only moves once a code is confirmed', $emailNow === $adminEmail,
    'email is now ' . $emailNow);
check('starting an email change ends safely',
    $emailStart['status'] === 302 || strpos($emailStart['body'], 'Email address') !== false,
    'status ' . $emailStart['status']);
check('the email change page never fataled',
    stripos($emailStart['body'], 'Fatal error') === false
        && stripos($emailStart['body'], 'Uncaught') === false);

// ============================================================
// 3. REPORTING
// ============================================================
section('Reporting a listing');

$r = req('/dashboard.php?tab=home', $reporterJar ?? $memberJar);
$memberCsrf = csrf_of($r['body']);
check('the report form token is available to a member', $memberCsrf !== '');

$r = req('/report_listing.php', $memberJar, [
    'csrf_token' => $memberCsrf,
    'provider_id' => $listingId,
    'reason_code' => 'scam_request',
    'details'     => 'They asked me to pay a deposit that disappeared.',
    'return_to'   => 'home',
]);
check('filing a report redirects back to the dashboard',
    $r['status'] === 302 && strpos($r['location'], 'dashboard.php') !== false,
    'status ' . $r['status'] . ' -> ' . $r['location']);

// A refusal and an acceptance both redirect, so the flash the handler
// left behind is what tells them apart. Read it, and print it on
// failure - otherwise a rejected report looks identical to a stored
// one until the next assertion.
$flash = flash_of(req('/dashboard.php?tab=isla', $memberJar)['body']);
check('the report was accepted, not refused',
    stripos($flash, 'thank') !== false || stripos($flash, 'received') !== false,
    'flash said: ' . ($flash !== '' ? $flash : '(none)'));

$stmt = $pdo->prepare('SELECT * FROM profile_reports WHERE provider_id = :p AND reporter_id = :r LIMIT 1');
$stmt->execute([':p' => $listingId, ':r' => $reporterId]);
$report = $stmt->fetch();
check('the report was stored as pending', $report !== false && (string) $report['status'] === 'pending');
check('the report kept the reason and the detail',
    $report !== false && (string) $report['reason_code'] === 'scam_request'
        && strpos((string) $report['details'], 'deposit') !== false);

// The queue must show it.
$q = req('/admin/index.php?view=reports&status=pending', $adminJar);
check('the report appears in the admin queue',
    $q['status'] === 200 && strpos($q['body'], 'Report Test Shop') !== false, 'status ' . $q['status']);

$detail = req('/admin/index.php?view=report&id=' . (int) ($report['id'] ?? 0), $adminJar);
check('the report detail page opens', $detail['status'] === 200
    && strpos($detail['body'], 'deposit') !== false, 'status ' . $detail['status']);
check('the detail page identifies who filed it',
    strpos($detail['body'], 'Test Person') !== false,
    'the panel shows the reporter by name and join date, not their email');

// A PHP BLOCK THAT FAILED TO OPEN IS INVISIBLE TO EVERY OTHER CHECK HERE.
// views/report.php mixes a PHP preamble with HTML; lose one `<?php` and PHP
// leaves code mode, the preamble is echoed as literal text, and every
// assertion above still passes — the page is bigger, not smaller, because
// the source is printed into it. The panel then dies partway down on
// undefined variables. So assert the one thing that is true only when the
// page was actually RENDERED: no PHP source in the response, and no
// warnings on it.
check('the detail page is rendered, not echoed as source',
    strpos($detail['body'], '<?php') === false
        && strpos($detail['body'], '$providerId') === false
        && preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $detail['body']) !== 1,
    strpos($detail['body'], '<?php') !== false
        ? 'the response contains a raw <?php tag'
        : 'the response contains PHP warnings or leaked source');
// The same class of bug on the two views checked above.
check('the queue and overview are rendered, not echoed as source',
    strpos($q['body'], '<?php') === false
        && preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $q['body']) !== 1
        && preg_match('/(Warning|Notice|Deprecated|Fatal error):/', (string) req('/admin/index.php', $adminJar)['body']) !== 1,
    'an admin view leaked PHP source or emitted a warning');

// The panel is styled entirely by public/admin.css, which only works if
// the markup does not reach around it. An inline style wins over the
// stylesheet, so one strays in and the next edit inherits a layout nobody
// can find in the CSS. Five panels, one invariant.
$inlineStyled = [];
foreach ([
    '/admin/index.php',
    '/admin/index.php?view=reports&status=pending',
    '/admin/index.php?view=reports&status=all',
    '/admin/index.php?view=report&id=' . (int) ($report['id'] ?? 0),
    '/admin/settings.php',
] as $u) {
    $p = req($u, $adminJar);
    if ($p['status'] === 200 && preg_match('/\sstyle="[^"]*"/', $p['body'])) {
        $inlineStyled[] = $u;
    }
}
check('no admin view hardcodes inline styles',
    $inlineStyled === [],
    'inline style attribute found on: ' . implode(', ', $inlineStyled));

// --- A second open report is refused -------------------------
$before = (int) $pdo->query('SELECT COUNT(*) FROM profile_reports WHERE provider_id = ' . $listingId)->fetchColumn();
req('/report_listing.php', $memberJar, [
    'csrf_token'  => $memberCsrf,
    'provider_id' => $listingId,
    'reason_code' => 'fake_listing',
    'details'     => 'A second report against the same open listing.',
    'return_to'   => 'home',
]);
$after = (int) $pdo->query('SELECT COUNT(*) FROM profile_reports WHERE provider_id = ' . $listingId)->fetchColumn();
check('a duplicate open report is refused', $before === $after, "before=$before after=$after");

// --- The owner cannot report their own listing ---------------
$ownerJar = tempnam(sys_get_temp_dir(), 'isla_jar_');
sign_in('/login.php', $ownerJar, ['identifier' => $ownerEmail, 'password' => $ownerPassword]);
$ownerPage = req('/dashboard.php?tab=isla', $ownerJar);
$ownerCsrf = csrf_of($ownerPage['body']);
req('/report_listing.php', $ownerJar, [
    'csrf_token'  => $ownerCsrf,
    'provider_id' => $listingId,
    'reason_code' => 'other',
    'details'     => 'Trying to report my own shop listing here.',
    'return_to'   => 'home',
]);
$ownerReports = (int) $pdo->query(
    'SELECT COUNT(*) FROM profile_reports WHERE provider_id = ' . $listingId . ' AND reporter_id = ' . $ownerId
)->fetchColumn();
check('an owner cannot report their own listing', $ownerReports === 0);

// ============================================================
// 4. MODERATION
// ============================================================
section('Moderation: blocking the listing');

$adminCsrf = csrf_of(req('/admin/index.php?view=reports&status=pending', $adminJar)['body']);

$r = req('/admin/action.php', $adminJar, [
    'csrf_token'  => $adminCsrf,
    'do'          => 'block_listing',
    'provider_id' => $listingId,
    'reason'      => 'Confirmed scam: deposit requests, no delivery.',
]);
check('the block action redirects back', $r['status'] === 302, 'status ' . $r['status']);

$stmt = $pdo->prepare('SELECT status, blocked_reason FROM providers WHERE id = :id');
$stmt->execute([':id' => $listingId]);
$row = $stmt->fetch();
check('the listing is now blocked', (string) $row['status'] === 'blocked', 'status=' . $row['status']);
check('the block reason was stored', strpos((string) $row['blocked_reason'], 'deposit') !== false);

$stmt = $pdo->prepare('SELECT status, listing_blocked FROM profile_reports WHERE id = :id');
$stmt->execute([':id' => (int) $report['id']]);
$afterBlock = $stmt->fetch();
check('the open report was resolved by the block', (string) $afterBlock['status'] === 'resolved',
    'report status=' . $afterBlock['status']);
check('the report records that the listing was blocked', (int) $afterBlock['listing_blocked'] === 1);

// ============================================================
// 5. A BLOCKED LISTING IS GONE
// ============================================================
section('A blocked listing disappears from the public app');

/**
 * feed_sees(): does the query dashboard.php uses for the feed and the
 * catalogue return this listing? Same WHERE clause, so this is the
 * real filter and not a paraphrase of it.
 */
function feed_sees(int $listingId): bool
{
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM providers p
          JOIN users u ON u.id = p.user_id
          WHERE p.status = 'active' AND p.id = :id"
    );
    $stmt->execute([':id' => $listingId]);
    return (int) $stmt->fetchColumn() > 0;
}

check('the feed/catalogue query no longer returns the listing', feed_sees($listingId) === false);

// The listing's NAME also appears in the confirmation message left by
// the report above, and every tab's markup is in the one HTML document
// (the tabs are switched with CSS, not separate requests). So the name
// alone proves nothing: the check has to look for the CARD, which is
// what carries a feed/catalogue entry - data-name is set by the one
// renderer both panels use.
$r = req('/dashboard.php?tab=home', $memberJar);
$cardHit = strpos($r['body'], 'data-name="Report Test Shop"');
$context = $cardHit === false ? '' : substr($r['body'], max(0, $cardHit - 220), 260);
check('the blocked listing is not in the rendered feed',
    $r['status'] === 200 && $cardHit === false,
    'status ' . $r['status'] . ($context !== '' ? ' near: ' . preg_replace('/\s+/', ' ', $context) : ''));

// The owner keeps it, WITH the explanation.
$r = req('/dashboard.php?tab=isla', $ownerJar);
check('the owner still sees their blocked listing', strpos($r['body'], 'Report Test Shop') !== false);
check('the owner is told it is not visible', strpos($r['body'], 'not visible to anyone') !== false);
check('the owner is shown the reason', strpos($r['body'], 'deposit') !== false);

// Reporting a blocked listing is refused.
$before = (int) $pdo->query('SELECT COUNT(*) FROM profile_reports WHERE provider_id = ' . $listingId)->fetchColumn();
req('/report_listing.php', $memberJar, [
    'csrf_token'  => $memberCsrf,
    'provider_id' => $listingId,
    'reason_code' => 'fake_photos',
    'details'     => 'Reporting a listing that is already blocked.',
    'return_to'   => 'home',
]);
$after = (int) $pdo->query('SELECT COUNT(*) FROM profile_reports WHERE provider_id = ' . $listingId)->fetchColumn();
check('a blocked listing cannot be reported', $before === $after, "before=$before after=$after");

// So can it be saved, messaged about or viewed-tracked?
$ownerIsReporter = false;
req('/save_listing.php', $memberJar, [
    'csrf_token'  => $memberCsrf,
    'provider_id' => $listingId,
    'return_to'   => 'home',
]);
$stmt = $pdo->prepare('SELECT COUNT(*) FROM saved_listings WHERE provider_id = :p AND user_id = :u');
$stmt->execute([':p' => $listingId, ':u' => $reporterId]);
check('a blocked listing cannot be saved', (int) $stmt->fetchColumn() === 0);

req('/send_message.php', $memberJar, [
    'csrf_token'    => $memberCsrf,
    'provider_id'   => $listingId,
    'message'       => 'Are you still taking bookings?',
    'return_to'     => 'home',
]);
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM conversations c
       JOIN providers p ON p.user_id = c.provider_id
      WHERE c.client_id = :c AND p.id = :p'
);
$stmt->execute([':c' => $reporterId, ':p' => $listingId]);
check('a blocked listing cannot be messaged', (int) $stmt->fetchColumn() === 0);

req('/track_view.php?provider_id=' . $listingId, $memberJar);
$stmt = $pdo->prepare('SELECT COUNT(*) FROM user_interactions WHERE provider_id = :p AND user_id = :u');
$stmt->execute([':p' => $listingId, ':u' => $reporterId]);
check('a blocked listing is not view-tracked', (int) $stmt->fetchColumn() === 0);

// --- Unblocking restores it ----------------------------------
$r = req('/admin/index.php?view=reports&status=all', $adminJar);
$adminCsrf = csrf_of($r['body']);
req('/admin/action.php', $adminJar, [
    'csrf_token'  => $adminCsrf,
    'do'          => 'unblock_listing',
    'provider_id' => $listingId,
]);
check('unblocking puts the listing back in the feed', feed_sees($listingId) === true);

// ============================================================
// 6. MFA
// ============================================================
section('Two-factor sign-in');

$pdo->prepare('UPDATE admins SET mfa_enabled = 1 WHERE id = ' . $adminId)->execute();
$mfaJar = tempnam(sys_get_temp_dir(), 'isla_jar_');
$login = sign_in('/login.php?admin=1', $mfaJar, [
    'identifier' => $adminEmail,
    'password' => $newPassword,
]);
check('with MFA on, a correct password still asks for a code',
    $login['ok'] && strpos((string) ($login['res']['location'] ?? ''), 'mfa=1') !== false,
    $login['ok'] ? ($login['res']['location'] ?? '') : $login['why']);

$r = req('/admin/index.php', $mfaJar);
check('a half-finished MFA sign-in cannot reach the panel',
    $r['status'] === 302 && strpos($r['location'], 'login.php') !== false,
    'status ' . $r['status'] . ' -> ' . $r['location']);

// Put it back for anyone poking at the live DB after the run.
$pdo->prepare('UPDATE admins SET mfa_enabled = 0 WHERE id = ' . $adminId)->execute();

// ============================================================
// 7. CHANGE EMAIL ADDRESS (member, Privacy & Security)
// ============================================================
section('Changing the sign-in email address');

// --- The option exists, and email + password are SEPARATE rows --
// The request was for these to be two options in the settings menu
// rather than one shared "security" form. Sharing them is the failure
// mode worth guarding: it lets a password change post through a form
// that only collected an email, or the reverse, and neither panel
// reports which field actually failed.
$r = req('/dashboard.php?tab=security', $memberJar);
check('the security options list offers Change Email',
    strpos($r['body'], 'data-sec-view="emailSec"') !== false);
check('...and Change Password as its own option',
    strpos($r['body'], 'data-sec-view="passwordSec"') !== false);
check('the two options are separate rows, not one shared form',
    substr_count($r['body'], 'data-sec-view="emailSec"') === 1
        && substr_count($r['body'], 'data-sec-view="passwordSec"') === 1
        && strpos($r['body'], 'name="change_email"') === false
            || strpos($r['body'], 'name="change_password"') !== false);
check('the email option is not nested in the password view',
    !preg_match('#id="passwordSec".*?name="change_email"#s', $r['body']));

$memberCsrf2 = csrf_of($r['body']);
check('a member has a CSRF token for the change-email form', $memberCsrf2 !== '');

// --- The password stays required --------------------------------
// Without the current password, anyone who walks up to an unlocked
// session can move the account's identity — and the email is where
// the reset link lands. It is the same gate change_password uses.
req('/dashboard.php', $memberJar, [
    'csrf_token'   => $memberCsrf2,
    'change_email' => '1',
    'new_email'    => 'hijack-' . $reporterId . '@example.com',
    'confirm_email'=> 'hijack-' . $reporterId . '@example.com',
]);
$stmt = $pdo->prepare('SELECT email FROM users WHERE id = :id');
$stmt->execute([':id' => $reporterId]);
check('changing email without the current password is refused',
    $stmt->fetchColumn() === $reporterEmail,
    'the address must not move on a password-less request');

// --- An address already in use is refused -----------------------
// UNIQUE(email) makes this true regardless, but the handler has to
// catch it: the duplicate-key exception would otherwise abort the
// request mid-render and show the member a blank page.
// dashboard.php renders its POST result inline (there is no redirect),
// so the refusal message is in the POST response itself.
$r = req('/dashboard.php', $memberJar, [
    'csrf_token'       => $memberCsrf2,
    'change_email'     => '1',
    'current_password' => $reporterPassword,
    'new_email'        => $ownerEmail,          // someone else's address
    'confirm_email'    => $ownerEmail,
]);
check('an email already in use is refused with a readable message',
    strpos($r['body'], 'already in use') !== false,
    'the duplicate must be reported, not thrown');
$stmt->execute([':id' => $reporterId]);
check('...and the member keeps their own address', $stmt->fetchColumn() === $reporterEmail);

// --- A mismatched confirmation is refused ----------------------
$r = req('/dashboard.php', $memberJar, [
    'csrf_token'       => $memberCsrf2,
    'change_email'     => '1',
    'current_password' => $reporterPassword,
    'new_email'        => 'fresh-' . $reporterId . '@example.com',
    'confirm_email'    => 'different-' . $reporterId . '@example.com',
]);
check('mismatched email confirmation is refused',
    strpos($r['body'], 'do not match') !== false);

// --- Step 1 parks the code WITHOUT touching the users row -------
// This is the load-bearing assertion of the whole feature: the address
// only moves after the new one is proven. Committing first and verifying
// after means a typo locks the owner out of their own account, with the
// only route back being support. The code is read from the session file
// rather than from the page, because the page deliberately does not
// contain it when SMTP is configured.
$newEmail = 'fresh-' . $reporterId . '@example.com';
req('/dashboard.php', $memberJar, [
    'csrf_token'       => $memberCsrf2,
    'change_email'     => '1',
    'current_password' => $reporterPassword,
    'new_email'        => $newEmail,
    'confirm_email'    => $newEmail,
]);
$stmt->execute([':id' => $reporterId]);
check('requesting a code does NOT change the address yet',
    $stmt->fetchColumn() === $reporterEmail,
    'the users row must stay untouched until the code is entered');

$r = req('/dashboard.php?tab=security', $memberJar);
check('the pending address comes back for the code step',
    strpos($r['body'], 'email_code') !== false,
    'the code box must render while a code is pending');
check('the pending address is shown back to the member',
    strpos($r['body'], htmlspecialchars($newEmail)) !== false);

// Pull the code out of the session so the test can confirm it, the way
// a member would by reading their inbox. It is deliberately not read
// off the page: when SMTP is configured the page does not contain it.
function pending_code(string $jar): string
{
    // The cookie jar holds the session id. Which storage holds the
    // payload depends on SESSION_DRIVER: PHP's own files by default, or
    // the isla_sessions table when the app runs with the database-backed
    // handler. Reading the wrong one silently returns ''.
    //
    // The child server under test shares this process's environment, so
    // both agree on the driver; isla_session_driver() is the same switch
    // the app itself reads.
    $sid = '';
    foreach (preg_split('/\r?\n/', (string) @file_get_contents($jar)) as $line) {
        $parts = explode("\t", trim($line));
        if (count($parts) === 7 && $parts[5] === 'PHPSESSID') {
            $sid = end($parts);
        }
    }
    if ($sid === '') {
        return '';
    }

    global $pdo;
    require_once __DIR__ . '/include/session_store.php';

    if (isla_session_driver() === 'mysql') {
        // Database-backed sessions: the payload is a row, not a file.
        $stmt = $pdo->prepare('SELECT data FROM isla_sessions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $sid]);
        $data = (string) $stmt->fetchColumn();
    } else {
        // PHP's file storage. The payload lives in whatever save_path
        // php.ini configured, which on WAMP is NOT the system temp dir.
        $path = rtrim(session_save_path(), '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sid;
        $data = (string) @file_get_contents($path);
    }
    if ($data === '') {
        return '';
    }
    // PHP's session serializer ('php') joins a nested array's keys and
    // values with ';' (only the TOP-level key is followed by '|'), so the
    // code sits as: ...;s:4:"code";s:6:"123456";...
    return preg_match('/[|;]s:4:"code";s:6:"(\d{6})"/', $data, $m) ? $m[1] : '';
}
$code = pending_code($memberJar);
check('a 6-digit code is pending in the session', (bool) preg_match('/^\d{6}$/', $code),
    'code read: ' . ($code === '' ? '(none)' : $code));

// --- A wrong code does not move the address ---------------------
// A mistyped digit should not cost the member a re-request.
$r = req('/dashboard.php', $memberJar, [
    'csrf_token'            => $memberCsrf2,
    'confirm_email_change'  => '1',
    'email_code'            => $code === '000000' ? '111111' : '000000',
]);
check('a wrong confirmation code is refused',
    strpos($r['body'], 'not correct') !== false);
$stmt->execute([':id' => $reporterId]);
check('...and a wrong code does not change the address',
    $stmt->fetchColumn() === $reporterEmail);

// --- The right code completes the change ------------------------
$r = req('/dashboard.php', $memberJar, [
    'csrf_token'            => $memberCsrf2,
    'confirm_email_change'  => '1',
    'email_code'            => $code,
]);
$stmt->execute([':id' => $reporterId]);
check('the correct code moves the address', $stmt->fetchColumn() === $newEmail);

// The session copy drives the Profile panel and anything else that
// greets the member, so a stale $_SESSION['email'] would show the old
// address right after a successful change.
check('the session copy of the address is updated too',
    strpos($r['body'], htmlspecialchars($newEmail)) !== false,
    'the Profile panel should show the new address, not the old one');

// --- The pending code is single-use -----------------------------
check('the code is cleared once used',
    strpos($r['body'], 'email_code') === false,
    'a consumed code must not leave the code box open');

// --- Cancelling abandons it --------------------------------------
req('/dashboard.php', $memberJar, [
    'csrf_token'        => $memberCsrf2,
    'change_email'      => '1',
    'current_password'  => $reporterPassword,
    'new_email'         => $newEmail . '.two',
    'confirm_email'     => $newEmail . '.two',
]);
$r = req('/dashboard.php', $memberJar, [
    'csrf_token'          => $memberCsrf2,
    'cancel_email_change' => '1',
]);
check('cancelling clears the pending code',
    strpos($r['body'], 'email_code') === false && strpos($r['body'], 'Cancelled') !== false,
    'a client-side hide would leave the code armed in the session');

// --- The new address is what signs in ---------------------------
// The whole point of the flow: after the change, the OLD address must
// stop working and the NEW one must start. Otherwise "change email"
// is cosmetic.
$oldJar = tempnam(sys_get_temp_dir(), 'isla_jar_');
$login = sign_in('/login.php', $oldJar, [
    'identifier' => $reporterEmail,
    'password'   => $reporterPassword,
]);
check('the old email no longer signs in',
    !($login['ok'] && ($login['res']['status'] ?? 0) === 302),
    'the previous address must stop working once changed');

$newJar = tempnam(sys_get_temp_dir(), 'isla_jar_');
$login = sign_in('/login.php', $newJar, [
    'identifier' => $newEmail,
    'password'   => $reporterPassword,
]);
check('the new email signs in',
    $login['ok'] && ($login['res']['status'] ?? 0) === 302,
    $login['ok'] ? 'status ' . $login['res']['status'] : $login['why']);

// ============================================================
// Result
// ============================================================
echo "\n=== $pass passed, $fail failed ===\n";
if ($failed) {
    echo "Failed checks:\n";
    foreach ($failed as $f) {
        echo "  - $f\n";
    }
}
exit($fail === 0 ? 0 : 1);
