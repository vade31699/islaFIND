<?php
// ============================================================
// mailer_api_test.php - pins the HTTPS email request shapes.
//
// WHY THIS TEST EXISTS
// Render's free instance cannot reach an SMTP port, so the API
// driver is the only mail path on the deployment. A wrong Mailjet
// payload or Basic-auth header would fail at the exact moment a
// visitor is waiting for a sign-in code - after the password is
// accepted. This test builds the request without sending it, so
// the wire format is checked against the provider's documented
// fields instead of only against a live account.
//
// What it cannot cover: the network round trip and the provider's
// account-level sender verification. Exercise one real code after
// deploying with real credentials.
//
// Dependency-free and needs no database:
//   php mailer_api_test.php        (exit 0 = pass, 1 = fail)
// ============================================================

require_once __DIR__ . '/include/mailer.php';

$failures = 0;
$checks   = 0;

/**
 * One assertion, in the shared smoke-test style.
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
 * Writes one configuration value through both accessors env() reads.
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
 * One header value by name, or '' when it is absent.
 */
function header_value(array $headers, string $name): string
{
    foreach ($headers as $header) {
        if (stripos($header, $name . ':') === 0) {
            return trim(substr($header, strlen($name) + 1));
        }
    }

    return '';
}

echo "Mailjet request\n";

set_env('MAIL_API_KEY', 'test-api-key');
set_env('MAIL_API_SECRET', 'test-api-secret');

$mailjet = isla_mail_api_request(
    'mailjet',
    'member@example.test',
    'Your islaFIND login code',
    '<p>123456</p>',
    '123456',
    'sender@example.test',
    'islaFIND'
);

$message = is_array($mailjet) && isset($mailjet['payload']['Messages'][0])
    ? $mailjet['payload']['Messages'][0]
    : [];

check(
    'Mailjet uses the v3.1 send endpoint',
    'https://api.mailjet.com/v3.1/send',
    $mailjet['url'] ?? ''
);
check(
    'Mailjet uses Basic auth with API key and secret',
    '1',
    header_value($mailjet['headers'] ?? [], 'Authorization')
        === 'Basic ' . base64_encode('test-api-key:test-api-secret') ? '1' : '0'
);
check(
    'Mailjet sends one message',
    '1',
    is_array($mailjet['payload']['Messages'] ?? null)
        && count($mailjet['payload']['Messages']) === 1 ? '1' : '0'
);
check(
    'Mailjet names the verified sender',
    'sender@example.test|islaFIND',
    ($message['From']['Email'] ?? '') . '|' . ($message['From']['Name'] ?? '')
);
check(
    'Mailjet addresses the recipient',
    'member@example.test',
    $message['To'][0]['Email'] ?? ''
);
check(
    'Mailjet carries subject, text, and HTML',
    'Your islaFIND login code|123456|<p>123456</p>',
    ($message['Subject'] ?? '') . '|' . ($message['TextPart'] ?? '') . '|' . ($message['HTMLPart'] ?? '')
);
check(
    'Mailjet does not put the secret in the JSON body',
    '0',
    strpos((string) json_encode($mailjet['payload'] ?? []), 'test-api-secret') !== false ? '1' : '0'
);

echo "Mailjet response\n";

check(
    'Mailjet succeeds only on per-message success',
    '1',
    isla_mail_api_response_ok('mailjet', '{"Messages":[{"Status":"success"}]}') ? '1' : '0'
);
check(
    'Mailjet rejects an HTTP-200 per-message error',
    '0',
    isla_mail_api_response_ok('mailjet', '{"Messages":[{"Status":"error","ErrorMessage":"bad sender"}]}') ? '1' : '0'
);
check(
    'Mailjet rejects an empty or malformed body',
    '0',
    isla_mail_api_response_ok('mailjet', '{}') || isla_mail_api_response_ok('mailjet', 'not json') ? '1' : '0'
);
check(
    'Other providers keep the 2xx rule',
    '1',
    isla_mail_api_response_ok('brevo', '') ? '1' : '0'
);

echo "Configuration failures\n";

set_env('MAIL_API_SECRET', '');
check(
    'Mailjet refuses to build without its second secret',
    '1',
    isla_mail_api_request(
        'mailjet',
        'member@example.test',
        'Subject',
        '<p>123456</p>',
        '123456',
        'sender@example.test',
        'islaFIND'
    ) === null ? '1' : '0'
);

set_env('MAIL_API_KEY', '');
check(
    'An empty API key is a failure, not a request',
    '1',
    isla_mail_api_request(
        'brevo',
        'member@example.test',
        'Subject',
        '<p>123456</p>',
        '123456',
        'sender@example.test',
        'islaFIND'
    ) === null ? '1' : '0'
);

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
