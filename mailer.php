<?php

# ------------------------------------------------------------------
# islaFIND mailer helper
# ----------------------
# - Loads SMTP credentials from .env (never hardcoded, never bundled
#   with the mobile app).
# - The .env loader is a tiny zero-dependency parser in env.php, so
#   no runtime dependency on phpdotenv is required. db.php includes
#   the same file, so one parser serves both.
# - Every send*Email() helper returns true/false. When one returns
#   false the caller keeps the code visible on screen (dev fallback),
#   so no login / registration / reset flow is ever blocked by a
#   broken mail config.
# ------------------------------------------------------------------

use PHPMailer\PHPMailer\PHPMailer;

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
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

# Shared SMTP sender behind every one-time-code e-mail.
function sendCodeMail(string $to, string $code, string $subject, string $intro, string $note): bool
{
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
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

        $mail->setFrom(env('MAIL_FROM', $user), env('MAIL_FROM_NAME', 'islaFIND'));
        $mail->addAddress($to);
        $mail->addReplyTo(env('MAIL_FROM', $user), env('MAIL_FROM_NAME', 'islaFIND'));

        $mail->Subject = $subject;

        $mail->isHTML(true);
        $mail->Body = '<p>' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>'
                    . '<p style="font-size:28px;font-weight:bold;letter-spacing:4px;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p>'
                    . '<p>' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</p>';

        $mail->AltBody = $intro . ' ' . $code . ' ' . $note;

        return $mail->send();
    } catch (\Throwable $e) {
        error_log('islaFIND mailer failure: ' . $e->getMessage());
        return false;
    }
}

# The SMTP credentials are already loaded: env.php reads .env at
# include time (above), so the accessors above always see them.