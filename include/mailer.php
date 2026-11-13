<?php

# ------------------------------------------------------------------
# islaFIND mailer helper
# ----------------------
# - Loads mail credentials from .env (never hardcoded, never bundled
#   with the mobile app). MAIL_DRIVER=smtp uses PHPMailer;
#   MAIL_DRIVER=api uses Mailjet, Brevo, Resend or SendGrid over HTTPS,
#   for hosts that block outbound SMTP ports.
# - The .env loader is a tiny zero-dependency parser in env.php, so
#   no runtime dependency on phpdotenv is required. db.php includes
#   the same file, so one parser serves both.
# - Every send*Email() helper returns true/false. When one returns
#   false the caller keeps the code visible on screen (dev fallback),
#   so no login / registration / reset flow is ever blocked by a
#   broken mail config.
# ------------------------------------------------------------------

use PHPMailer\PHPMailer\PHPMailer;

// This file lives in include/, so Composer's autoloader — which stays
// in the project root next to composer.json — is one level up.
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

# ------------------------------------------------------------------
# Configuration (.env)
# ------------------------------------------------------------------
# load_env() + env() live in env.php so the mailer and db.php share
# ONE parser and ONE .env file. Including it also loads the file.
require_once __DIR__ . '/env.php';

# ------------------------------------------------------------------
# Verification e-mail
# ------------------------------------------------------------------
function sendVerificationEmail(string $to, string $code): bool
{
    return sendCodeMail(
        $to,
        $code,
        'Your islaFIND verification code',
        'Your islaFIND verification code is:',
        // NOTE: the "2 minutes" here mirrors CODE_TTL in login.php — the
        // session-side expiry is what actually rejects a late code, so the
        // two must be changed together.
        'This code expires in 2 minutes.'
    );
}

# Password-reset code e-mail (forgot-password flow).
function sendResetEmail(string $to, string $code): bool
{
    return sendCodeMail(
        $to,
        $code,
        'Your islaFIND password reset code',
        'A password reset was requested for your islaFIND account. This code lets you set a new password:',
        'This code expires in 2 minutes. If you did not request a reset, you can safely ignore this e-mail.'
    );
}

# Login MFA code e-mail (the second factor, sent after a correct
# password when the account has MFA enabled). There is no SMS provider
# in this stack, so the one-time code travels to the address the
# account already registered.
function sendMfaEmail(string $to, string $code): bool
{
    return sendCodeMail(
        $to,
        $code,
        'Your islaFIND login code',
        'Use this code to finish signing in to your islaFIND account:',
        'This code expires in 2 minutes. If you did not try to sign in, someone may know your password — change it as soon as possible.'
    );
}

# Superadmin sign-in code e-mail (include/admin_auth.php). Same code
# shape and the same "2 minutes" wording as the member MFA mail, because
# it plays exactly the same role — the second factor on an admin login —
# and the session-side TTL (ADMIN_CODE_TTL) is deliberately identical to
# the member one. Changing one means changing the other.
function sendAdminMfaEmail(string $to, string $code, string $subject = 'Your islaFIND admin sign-in code'): bool
{
    return sendCodeMail(
        $to,
        $code,
        $subject,
        'Use this code to finish signing in to the islaFIND superadmin panel:',
        'This code expires in 2 minutes. If you did not try to sign in, someone may know your admin password — change it in Settings as soon as possible.'
    );
}

# E-mail-CHANGE code (Privacy & Security -> Change email address).
# Deliberately sent to the address being moved TO, not the one in force:
# the point of the code is to prove the member can read mail where they
# are asking the account to live. Nothing is written to the users row
# until they do, so a typo can never strand someone who can no longer
# receive their own sign-in code.
function sendEmailChangeCode(string $to, string $code): bool
{
    return sendCodeMail(
        $to,
        $code,
        'Confirm your new islaFIND email address',
        'Confirm this email address for your islaFIND account with this code:',
        'This code expires in 2 minutes. Your sign-in email only changes once you enter it. If you did not request this, ignore this e-mail and change your password.'
    );
}

# Shared sender behind every one-time-code e-mail.
#
# MAIL_DRIVER=smtp (the default) keeps the original PHPMailer path, so
# local WAMP behaviour does not change. MAIL_DRIVER=api sends the same
# message over HTTPS instead, for hosts that block outbound SMTP ports
# (Render's free tier blocks 25/465/587). Both paths return false on
# failure, which keeps the caller's on-screen-code fallback working.
function sendCodeMail(string $to, string $code, string $subject, string $intro, string $note): bool
{
    $html = '<p>' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>'
          . '<p style="font-size:28px;font-weight:bold;letter-spacing:4px;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p>'
          . '<p>' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</p>';
    $alt = $intro . ' ' . $code . ' ' . $note;

    $from     = trim(env('MAIL_FROM', env('SMTP_USER', '')));
    $fromName = env('MAIL_FROM_NAME', 'islaFIND');

    if (strtolower(trim(env('MAIL_DRIVER', 'smtp'))) === 'api') {
        return sendCodeMailApi($to, $subject, $html, $alt, $from, $fromName);
    }

    if (!class_exists(PHPMailer::class)) {
        return false;
    }

    $host = env('SMTP_HOST', 'smtp.gmail.com');
    $user = env('SMTP_USER', '');
    $pass = env('SMTP_PASS', '');
    if ($host === '' || $user === '' || $pass === '') {
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host        = $host;
        $mail->Port        = (int) env('SMTP_PORT', '587');
        $mail->SMTPAuth    = true;
        $mail->Username    = $user;
        $mail->Password    = $pass;
        $mail->SMTPSecure  = env('SMTP_ENCRYPTION', 'tls') === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet     = 'UTF-8';

        $mail->setFrom($from !== '' ? $from : $user, $fromName);
        $mail->addAddress($to);
        $mail->addReplyTo($from !== '' ? $from : $user, $fromName);

        $mail->Subject = $subject;

        $mail->isHTML(true);
        $mail->Body    = $html;
        $mail->AltBody = $alt;

        return $mail->send();
    } catch (\Throwable $e) {
        error_log('islaFIND mailer failure: ' . $e->getMessage());
        return false;
    }
}

/**
 * isla_mail_api_request()
 * Builds the URL, headers and JSON payload for one HTTPS email API.
 *
 * MAIL_API_PROVIDER selects the wire format:
 *   mailjet  - Basic auth, Messages[]/TextPart/HTMLPart
 *   brevo    - api-key header, htmlContent/textContent
 *   resend   - Bearer token, html/text
 *   sendgrid - Bearer token, personalizations/content[]
 *
 * Mailjet needs a SECOND secret (MAIL_API_SECRET), because its Basic
 * auth password is not the API key itself. The other providers keep
 * their single MAIL_API_KEY.
 *
 * Returns NULL on missing configuration or an unsupported provider, so
 * the caller can fail closed without ever putting a secret in a log.
 *
 * @return array{url:string,headers:string[],payload:array<string,mixed>}|null
 */
function isla_mail_api_request(string $provider, string $to, string $subject, string $html, string $alt, string $from, string $fromName): ?array
{
    $key = trim(env('MAIL_API_KEY', ''));
    if ($key === '') {
        error_log('islaFIND mailer API: MAIL_API_KEY is not configured');
        return null;
    }

    $headers = ['Content-Type: application/json', 'Accept: application/json'];

    if ($provider === 'brevo') {
        $url = 'https://api.brevo.com/v3/smtp/email';
        $headers[] = 'api-key: ' . $key;
        $payload = [
            'sender'      => ['email' => $from, 'name' => $fromName],
            'to'          => [['email' => $to]],
            'subject'     => $subject,
            'htmlContent' => $html,
            'textContent' => $alt,
        ];
    } elseif ($provider === 'resend') {
        $url = 'https://api.resend.com/emails';
        $headers[] = 'Authorization: Bearer ' . $key;
        $payload = [
            'from'    => $fromName . ' <' . $from . '>',
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
            'text'    => $alt,
        ];
    } elseif ($provider === 'sendgrid') {
        $url = 'https://api.sendgrid.com/v3/mail/send';
        $headers[] = 'Authorization: Bearer ' . $key;
        $payload = [
            'personalizations' => [['to' => [['email' => $to]]]],
            'from'             => ['email' => $from, 'name' => $fromName],
            'subject'          => $subject,
            'content'          => [
                ['type' => 'text/plain', 'value' => $alt],
                ['type' => 'text/html',  'value' => $html],
            ],
        ];
    } elseif ($provider === 'mailjet') {
        $secret = trim(env('MAIL_API_SECRET', ''));
        if ($secret === '') {
            error_log('islaFIND mailer API: MAIL_API_SECRET is not configured for Mailjet');
            return null;
        }

        $url = 'https://api.mailjet.com/v3.1/send';
        $headers[] = 'Authorization: Basic ' . base64_encode($key . ':' . $secret);
        $payload = [
            'Messages' => [[
                'From'     => ['Email' => $from, 'Name' => $fromName],
                'To'       => [['Email' => $to]],
                'Subject'  => $subject,
                'TextPart' => $alt,
                'HTMLPart' => $html,
            ]],
        ];
    } else {
        error_log('islaFIND mailer API: unsupported MAIL_API_PROVIDER "' . $provider . '"');
        return null;
    }

    return ['url' => $url, 'headers' => $headers, 'payload' => $payload];
}

/**
 * isla_mail_api_response_ok()
 * Mailjet can answer HTTP 200 while the individual message failed, so
 * "2xx" is not enough for it: Messages[0].Status must literally say
 * "success". The other providers use a 2xx to mean accepted.
 *
 * @return bool
 */
function isla_mail_api_response_ok(string $provider, string $body): bool
{
    if ($provider !== 'mailjet') {
        return true;
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['Messages']) || !is_array($decoded['Messages'])) {
        return false;
    }

    if ($decoded['Messages'] === []) {
        return false;
    }

    foreach ($decoded['Messages'] as $message) {
        if (!is_array($message)
            || strtolower((string) ($message['Status'] ?? '')) !== 'success'
        ) {
            return false;
        }
    }

    return true;
}

/**
 * sendCodeMailApi()
 * Delivers a one-time-code e-mail through an HTTPS email API.
 *
 * Secrets stay in the environment: the key is put in the request
 * header and is never written to the log, even on failure. Mailjet
 * also has its response checked for a per-message rejection, because
 * that provider can return HTTP 200 with Status=error.
 *
 * @return bool TRUE only when the provider accepted the message.
 */
function sendCodeMailApi(string $to, string $subject, string $html, string $alt, string $from, string $fromName): bool
{
    if ($from === '') {
        error_log('islaFIND mailer API: MAIL_FROM is not configured');
        return false;
    }

    if (!function_exists('curl_init')) {
        error_log('islaFIND mailer API: the cURL extension is not available');
        return false;
    }

    $provider = strtolower(trim(env('MAIL_API_PROVIDER', 'brevo')));
    if ($provider === '') {
        $provider = 'brevo';
    }

    $request = isla_mail_api_request($provider, $to, $subject, $html, $alt, $from, $fromName);
    if ($request === null) {
        return false;
    }

    $json = json_encode($request['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        error_log('islaFIND mailer API: could not encode the request body');
        return false;
    }

    $ch = curl_init($request['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => $request['headers'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error  = curl_error($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        $detail = $error !== '' ? $error : substr((string) $body, 0, 500);
        error_log('islaFIND mailer API failure (' . $provider . ', HTTP ' . $status . '): ' . $detail);
        return false;
    }

    if (!isla_mail_api_response_ok($provider, (string) $body)) {
        $detail = substr((string) $body, 0, 500);
        $decoded = json_decode((string) $body, true);
        if (is_array($decoded) && isset($decoded['Messages'][0]) && is_array($decoded['Messages'][0])) {
            $detail = (string) ($decoded['Messages'][0]['ErrorMessage']
                ?? $decoded['Messages'][0]['Status']
                ?? $detail);
        }
        error_log('islaFIND mailer API failure (' . $provider . ', HTTP ' . $status . ', rejected): ' . substr($detail, 0, 500));
        return false;
    }

    return true;
}

# The SMTP credentials are already loaded: env.php reads .env at
# include time (above), so the accessors above always see them.
