<?php
// ============================================================
// _mail_api_test.php — HTTPS email API diagnostic (developer tool)
// Replays ONE real send through the API driver and prints the
// provider's raw answer, which is the fastest way to tell a bad
// key from an unverified sender from an account-level block.
//
//   php _mail_api_test.php            (sends to MAIL_FROM)
//   php _mail_api_test.php you@x.test (sends to another address)
//
// Exit 0 = the provider accepted the message, 1 = it did not.
//
// COMMAND LINE ONLY. It lives in the web root, and a browser hit
// would let a stranger send mail from this server on demand, so web
// requests are answered with a 404 before any of the work below runs.
// ============================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/include/mailer.php';

$driver   = strtolower(trim(env('MAIL_DRIVER', 'smtp')));
$provider = strtolower(trim(env('MAIL_API_PROVIDER', 'brevo')));
$from     = trim(env('MAIL_FROM', env('SMTP_USER', '')));
$to       = $argv[1] ?? $from;

echo "--- Config check ---\n";
echo "MAIL_DRIVER:       $driver\n";
echo "MAIL_API_PROVIDER: $provider\n";
echo "MAIL_FROM:         " . ($from === '' ? '(MISSING)' : $from) . "\n";
echo "MAIL_API_KEY:      " . (trim(env('MAIL_API_KEY', '')) !== '' ? 'set' : 'MISSING') . "\n";
echo "MAIL_API_SECRET:   " . (trim(env('MAIL_API_SECRET', '')) !== '' ? 'set' : '(not set)') . "\n";
echo "recipient:         " . ($to === '' ? '(none)' : $to) . "\n\n";

if ($driver !== 'api') {
    echo "MAIL_DRIVER is \"$driver\", not \"api\" — the API path is switched off.\n";
    echo "Use _smtp_test.php for the SMTP path.\n";
    exit(1);
}

$request = isla_mail_api_request(
    $provider,
    $to,
    'islaFIND mailer probe',
    '<p>islaFIND mailer probe — no action needed.</p>',
    'islaFIND mailer probe — no action needed.',
    $from,
    env('MAIL_FROM_NAME', 'islaFIND')
);

if ($request === null) {
    echo "*** FAILED before sending ***\n";
    echo "The request builder returned NULL, so a required value is missing\n";
    echo "or MAIL_API_PROVIDER is not one of mailjet|brevo|resend|sendgrid.\n";
    exit(1);
}

$json = json_encode($request['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

echo "--- Sending (POST $provider) ---\n";
$ch = curl_init($request['url']);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $json,
    CURLOPT_HTTPHEADER     => $request['headers'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 20,
]);

$body   = (string) curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$error  = curl_error($ch);

echo "HTTP:  $status\n";
echo "curl:  " . ($error === '' ? '(none)' : $error) . "\n";
echo "body:  " . substr($body, 0, 800) . "\n";

$ok = $status >= 200 && $status < 300 && isla_mail_api_response_ok($provider, $body);

if ($ok) {
    echo "\n*** SUCCESS — the provider accepted the message ***\n";
    exit(0);
}

echo "\n*** FAILED — the provider did not accept the message ***\n";

// Common answers, in the words the provider actually uses.
$lower = strtolower($body);
if ($status === 401 && $provider === 'mailjet' && strpos($lower, 'temporarily blocked') !== false) {
    echo "This is an ACCOUNT-level Mailjet block (mj-0001), not a bad key:\n";
    echo "  every key on the account gets 401 until the account is unlocked.\n";
    echo "  Check the Mailjet dashboard first: a \"Complete the next step to\n";
    echo "  unlock Mailjet\" banner (setup under 100%) is exactly this block.\n";
    echo "  If the account is fully set up, contact Mailjet support — nothing in\n";
    echo "  this codebase can lift it (a new provider key is the other option).\n";
} elseif ($status === 401 || $status === 403) {
    if ($provider === 'mailjet') {
        echo "The Mailjet API Key / Secret Key pair was rejected AT AUTHENTICATION.\n";
        echo "Every endpoint answers this — including /v3/REST/apikey, which only\n";
        echo "needs a valid pair — so it is not the send request or the sender.\n";
        echo "A Mailjet secret is displayed only once: regenerate the pair under\n";
        echo "Account Settings -> API Key Management, copy BOTH values at the same\n";
        echo "moment, and put them in MAIL_API_KEY / MAIL_API_SECRET.\n";
    } else {
        echo "The API key was rejected. Re-copy it (a Brevo key starts xkeysib-).\n";
    }
} elseif (strpos($lower, 'sender') !== false || strpos($lower, 'from') !== false) {
    echo "The From address is probably not verified with the provider.\n";
    echo "MAIL_FROM must be an address the account is allowed to send as.\n";
} else {
    echo "The server log has the same detail: search it for\n";
    echo "\"islaFIND mailer API failure\".\n";
}

exit(1);
