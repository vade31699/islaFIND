<?php
// ============================================================
// _smtp_test.php — SMTP diagnostic (developer tool)
// Sends ONE real message to the configured From address with the
// full SMTP conversation printed, which is the fastest way to tell
// a bad password from a wrong port when email stops working.
//
//   php _smtp_test.php        (from the project root)
//
// COMMAND LINE ONLY. It lives in the web root, and a browser hit
// would let a stranger send mail from this server on demand, so web
// requests are answered with a 404 before any of the work below runs.
// ============================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/include/mailer.php';
load_env(__DIR__ . '/.env');

echo "--- Config check ---\n";
$host = env('SMTP_HOST');
$port = env('SMTP_PORT');
$user = env('SMTP_USER');
$pass = env('SMTP_PASS');
$enc  = env('SMTP_ENCRYPTION');
$from = env('MAIL_FROM');
$name = env('MAIL_FROM_NAME');
echo "HOST=$host PORT=$port USER=" . ($user !== '' ? 'set' : 'MISSING') .
     " PASS=" . ($pass !== '' ? 'set (' . strlen($pass) . ' chars)' : 'MISSING') .
     " ENC=$enc FROM=" . ($from !== '' ? 'set' : 'MISSING') .
     " FROM_NAME=$name\n";
echo "User matches From: " . (strcasecmp($user, $from) === 0 ? 'yes' : 'no') . "\n\n";

echo "--- Sending test message (SMTPDebug=2) ---\n";

$mail = new PHPMailer\PHPMailer\PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->Port       = (int) $port;
    $mail->SMTPAuth   = true;
    $mail->Username   = $user;
    $mail->Password   = $pass;
    $mail->SMTPSecure = $enc === 'ssl'
        ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet  = 'UTF-8';
    $mail->SMTPDebug = PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
    $mail->Debugoutput = function ($str) { echo $str; };

    $mail->setFrom($from !== '' ? $from : $user, $name !== '' ? $name : 'islaFIND');
    $mail->addAddress($from !== '' ? $from : $user);   // test to self
    $mail->Subject = 'islaFIND SMTP test';
    $mail->Body    = 'SMTP test body';
    $mail->send();
    echo "\n*** SUCCESS — mail sent ***\n";
} catch (\Throwable $e) {
    echo "\n*** FAILED ***\n";
    echo "Class: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
}
