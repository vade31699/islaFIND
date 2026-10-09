<?php
// ============================================================
// uploads_url_test.php — proves the READ side of the upload seam.
//
// WHY THIS TEST EXISTS
// include/uploads.php decides which URL a picture gets, and that
// decision is the difference between a card that renders and a card
// with a broken image. It has three cases (public origin, signed
// read, local file) and the middle one is new: a bucket with no
// public URL is served by public/image.php, which signs a read with
// the app's own credentials. That file's input is attacker-reachable
// — it is ?f=<name> on a public URL — so the whitelist that guards it
// has to be checked, not assumed.
//
// Two halves:
//   1. isla_upload_url() under each driver/base combination, in
//      process (cheap, and each env() read is live).
//   2. public/image.php as its own process — it exits, and it is the
//      browser-facing interface — asserting the status code it answers
//      for a bad name, a good name, and the wrong driver.
//
// What it cannot cover: the actual signed GET, which needs a live
// bucket and credentials. The signer itself is pinned to AWS's own
// published examples in s3_sign_test.php.
//
// Dependency-free and needs no database, like the other smoke tests:
//   php uploads_url_test.php        (exit 0 = pass, 1 = fail)
// ============================================================

require_once __DIR__ . '/include/uploads.php';

$failures = 0;
$checks   = 0;

/**
 * One assertion, in the shared smoke-test style: print, count, never
 * throw, so a run reports every failure it finds instead of stopping
 * at the first.
 */
function check(string $label, string $expected, string $actual): void
{
    global $failures, $checks;
    $checks++;

    if ($expected === $actual) {
        echo "  PASS  $label\n";
        return;
    }

    $failures++;
    echo "  FAIL  $label\n";
    echo "        expected: $expected\n";
    echo "        actual:   $actual\n";
}

/**
 * set_env()
 * Writes one configuration value for the duration of this process,
 * through BOTH accessors env() consults: putenv() for the real
 * process environment and $_ENV for the parsed .env fallback. An
 * empty value is a genuine "unset", which is the case this test
 * cares about most.
 */
function set_env(string $key, string $value): void
{
    putenv($key . '=' . $value);

    if ($value === '') {
        unset($_ENV[$key]);
        return;
    }

    $_ENV[$key] = $value;
}

/**
 * run_image_php()
 * Runs public/image.php in a fresh process with a fixed query string
 * and driver, and returns the status code it answered with.
 *
 * It has to be its own process: image.php ends in exit, and it is
 * meant to be hit by a browser, so the honest way to check its
 * answers is to run the file the way a request would. A shutdown
 * function reports http_response_code(), which is what the CLI SAPI
 * records for a script that (in a real request) would send headers.
 *
 * @param string      $driver 'remote' or 'local'.
 * @param string|null $f      Value for ?f=, or NULL for no parameter.
 * @return array{status:int,out:string}
 */
function run_image_php(string $driver, ?string $f): array
{
    $get = $f === null
        ? 'array()'
        : 'array(' . var_export('f', true) . ' => ' . var_export($f, true) . ')';

    // The child is a real file rather than a -r one-liner: the quoting
    // a shell needs for a nested script differs per platform, and a
    // test that fails because of escaping teaches nothing.
    //
    // Every S3 setting is cleared so the "no credentials" outcome is
    // deterministic even on a machine that has AWS_* exported.
    $code = "<?php\n"
        . "register_shutdown_function(function () {\n"
        . "    fwrite(STDOUT, 'STATUS=' . http_response_code() . '|');\n"
        . "});\n"
        // error_log() is asserted below, so send it somewhere this
        // process can read. A bundled php.ini (XAMPP and friends)
        // usually points error_log at a file instead of stderr.
        . "ini_set('log_errors', '1');\n"
        . "ini_set('error_log', '');\n"
        . "foreach (['AWS_BUCKET', 'AWS_ENDPOINT', 'AWS_ENDPOINT_URL', 'AWS_REGION',\n"
        . "          'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_URL', 'UPLOADS_URL_BASE'] as \$k) {\n"
        . "    putenv(\$k . '='); unset(\$_ENV[\$k]);\n"
        . "}\n"
        . 'putenv(' . var_export('UPLOADS_DRIVER=' . $driver, true) . ");\n"
        . "\$_ENV['UPLOADS_DRIVER'] = " . var_export($driver, true) . ";\n"
        . '$_GET = ' . $get . ";\n"
        . 'include ' . var_export(__DIR__ . '/public/image.php', true) . ";\n";

    $runner = sys_get_temp_dir() . '/isla_image_test_' . getmypid() . '_' . mt_rand() . '.php';
    file_put_contents($runner, $code);

    $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 ' . escapeshellarg($runner) . ' 2>&1';
    $out     = (string) shell_exec($command);

    @unlink($runner);

    return [
        'status' => preg_match('/STATUS=(\d+)\|/', $out, $m) === 1 ? (int) $m[1] : -1,
        'out'    => $out,
    ];
}

// ---------------------------------------------------------------
// 1. Which URL each driver and base combination produces
// ---------------------------------------------------------------
$pic = 'user_5_deadbeefcafebabe.jpg';

echo "isla_upload_url — driver and base combinations\n";

set_env('UPLOADS_DRIVER', 'local');
set_env('UPLOADS_URL_BASE', '');
set_env('AWS_URL', '');
check('local driver keeps the web-root path', 'uploads/' . $pic, isla_upload_url($pic));

set_env('UPLOADS_URL_BASE', 'https://cdn.example.com/pics/');
check(
    'a base with a trailing slash is not doubled',
    'https://cdn.example.com/pics/' . $pic,
    isla_upload_url($pic)
);

set_env('UPLOADS_DRIVER', 'remote');
set_env('UPLOADS_URL_BASE', '');
check('remote with no public URL falls back to image.php', 'image.php?f=' . $pic, isla_upload_url($pic));

// The platform's own variable is NOT a read origin. A live deployment
// had AWS_URL pointing at a bucket custom domain that did not resolve,
// so every picture was a broken image (Cloudflare 1016 / HTTP 530)
// while public/image.php served the same bytes. Only the app's OWN
// switch may turn the fast path on.
set_env('AWS_URL', 'https://pub-abc123.r2.dev');
check('AWS_URL is ignored: the signed read is used', 'image.php?f=' . $pic, isla_upload_url($pic));

set_env('UPLOADS_URL_BASE', 'https://cdn.example.com/pics');
check('UPLOADS_URL_BASE alone enables the public origin', 'https://cdn.example.com/pics/' . $pic, isla_upload_url($pic));

set_env('UPLOADS_URL_BASE', '');
set_env('AWS_URL', '');
check('a stored path is reduced to its basename', 'image.php?f=' . $pic, isla_upload_url('uploads/' . $pic));
check('names are URL-encoded', 'image.php?f=a%20b.jpg', isla_upload_url('a b.jpg'));

// public/admin/index.php turns a relative upload URL into '../<url>',
// which is only correct while the fallback stays relative.
check(
    'the fallback is relative, so the admin panel\'s ../ prefix holds',
    '0',
    (string) preg_match('#^(https?:)?//#', isla_upload_url($pic))
);

// ---------------------------------------------------------------
// 2. Which names public/image.php will sign
// ---------------------------------------------------------------
echo "isla_upload_servable_name — the whitelist in front of the signed read\n";

$acceptable = [
    'user_5_deadbeefcafebabe.jpg',
    'listing_12_a1b2c3d4e5f60718.png',
    'report_1_0011223344556677.jpeg',
    'legacy.gif',
    'photo.webp',
    'UPPER.JPG',
    'a.b.c.png',
];

foreach ($acceptable as $name) {
    check('accepts ' . $name, '1', isla_upload_servable_name($name) ? '1' : '0');
}

$rejectable = [
    '../.env',
    '../../etc/passwd',
    'a/b.jpg',
    'sub/dir/photo.png',
    '.hidden.jpg',
    '.env',
    'image.php',
    'shell.php',
    'photo.jpg.php',
    'archive.zip',
    '',
    '..',
    '-leading.jpg',
    'trailing.jpg.',
];

foreach ($rejectable as $name) {
    check('refuses ' . var_export($name, true), '0', isla_upload_servable_name($name) ? '1' : '0');
}

// A 300-character name is refused by the whitelist's leading-character
// rule only if it is otherwise invalid; assert the length limit too, so
// a huge key cannot be signed either.
check('refuses a 300-character name', '0', isla_upload_servable_name(str_repeat('a', 300) . '.jpg') ? '1' : '0');

// ---------------------------------------------------------------
// 3. What public/image.php answers a request
// ---------------------------------------------------------------
echo "public/image.php — the browser-facing answers\n";

$case = run_image_php('remote', '../.env');
check('refuses a traversal name with 400', '400', (string) $case['status']);
check(
    'the 400 carries a plain body, not picture bytes',
    '1',
    strpos($case['out'], 'Bad request') !== false ? '1' : '0'
);

$case = run_image_php('remote', 'photo.jpg.php');
check('refuses a non-picture extension with 400', '400', (string) $case['status']);

$case = run_image_php('remote', null);
check('refuses a missing ?f= with 400', '400', (string) $case['status']);

$case = run_image_php('local', $pic);
check('answers 404 on the local driver, where Apache serves the file', '404', (string) $case['status']);

// A valid name with no credentials reaches the signer, which reports
// "not configured" (status 0) — i.e. the request got PAST the
// whitelist, which is what proves the gate is not refusing everything.
$case = run_image_php('remote', $pic);
check('passes a valid name through to the signer (503 without credentials)', '503', (string) $case['status']);
check('the 503 is reported with a reason in the log', '1', strpos($case['out'], 'image.php could not read') !== false ? '1' : '0');

// ---------------------------------------------------------------
echo "\n$checks checks, $failures failure(s)\n";

if ($failures > 0) {
    exit(1);
}
exit(0);
