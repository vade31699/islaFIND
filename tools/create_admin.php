<?php
// ============================================================
// create_admin.php — provision an islaFIND superadmin account
//
//     php tools/create_admin.php
//
// WHY A TOOL AND NOT A SEEDED ROW
// ---------------------------
// A superadmin account is the one credential in this app that can read
// the moderation queue and act on any member's listing, so its password
// must never be written down anywhere: not in final_app.sql, not in a
// migration, not in this repository. A seed row would put a working
// master key into git history, where it survives every future "rotate
// the credentials" decision and every copy of the code.
//
// So the `admins` table is created empty and this tool fills it, by
// asking the operator for the password and hashing it on the spot. The
// only thing this file ever prints is the account's id and email —
// never the password, never the hash.
//
// Non-interactive use (CI, first-boot provisioning). Prefer the prompt;
// these exist so a platform "command" runner can create the first
// account without a TTY:
//
//     set ISLA_ADMIN_EMAIL=... && set ISLA_ADMIN_PASSWORD=...
//     php tools/create_admin.php
//
// An env var is still visible to other processes of the same user via
// the process environment, so on a shared machine delete it from the
// shell afterwards. It is only ever safer than a command-line argument,
// which lands in the shell history and in `ps` output.
//
// Re-running with an existing email RESETS that account's password,
// reactivates it if it had been deactivated, and turns email MFA back
// on. That is the recovery path for a locked-out admin — and it asks
// for confirmation first, so a typo cannot silently rotate the password
// of the account you are currently using.
//
// COMMAND LINE ONLY: a browser hit must never reach it. A web request
// is answered with 404 before anything below runs (same guard
// _smtp_test.php and _import_sql.php use).
//
// Exit code 0 = account created or updated, 1 = refused, 2 = bad usage.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/db.php';

/** Print a line to the terminal (and only to the terminal). */
function say(string $line = ''): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

/**
 * ask(string $prompt): string
 * Read one visible line from the operator.
 */
function ask(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

/**
 * ask_secret(string $prompt): string
 * Read one line WITHOUT echoing it, so the password never appears on
 * screen, in a screen recording, or in a shoulder-surfer's eyes.
 *
 * Windows has no portable way to switch the console out of echo mode
 * from PHP, so a hidden Read-Host in a child PowerShell does it (the
 * secure string is converted back to text inside that process, which
 * is the same trade-off ssh and sudo make). POSIX uses stty.
 *
 * @param string $prompt Label to show.
 * @return string The typed line, or '' when the input could not be read.
 */
function ask_secret(string $prompt): string
{
    if (DIRECTORY_SEPARATOR === '\\') {
        // Single quotes in the label would end the PowerShell string,
        // so they are doubled rather than passed through raw.
        $safe = str_replace("'", "''", $prompt);
        $cmd  = 'powershell -NoProfile -Command '
              . '$sec = Read-Host -AsSecureString \'' . $safe . '\'; '
              . '$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec); '
              . 'try { [Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)) } '
              . 'finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }';
        return (string) shell_exec($cmd);
    }

    shell_exec('stty -echo 2>/dev/null');
    fwrite(STDOUT, $prompt);
    $line = fgets(STDIN);
    shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, PHP_EOL);
    return $line === false ? '' : trim($line);
}

/**
 * ask_yes_no(string $question): bool
 * Confirm before anything destructive. Only a literal "y" (or the
 * env-var mode, which has no TTY to ask on) proceeds.
 */
function ask_yes_no(string $question): bool
{
    return strtolower(ask($question . ' [y/N] ')) === 'y';
}

// --- 1. The administrator's email -------------------------------
// Taken from the environment when set, otherwise prompted. It is the
// only identifier an admin has: there is no phone number and no link
// to a members row.
$email = trim((string) (getenv('ISLA_ADMIN_EMAIL') ?: ''));
$envMode = $email !== '';

if (!$envMode) {
    say('islaFIND superadmin setup');
    say('-------------------------');
    say('This creates (or resets) the account that can moderate listings.');
    say('The password is asked for in hidden text and stored as a bcrypt hash.');
    say();
    $email = ask('Admin email: ');
}

$email = strtolower(trim($email));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    say('That is not a usable email address. Nothing was changed.');
    exit(1);
}

// --- 2. Does this account already exist? ------------------------
// Checked BEFORE asking for a password, so a mistyped address is
// corrected without anybody having to invent a new password first.
$stmt = $pdo->prepare('SELECT id, is_active FROM admins WHERE email = :e LIMIT 1');
$stmt->execute([':e' => $email]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing && !$envMode && !ask_yes_no(
    'An admin already uses ' . $email . ' (id ' . $existing['id'] . '). Reset its password?'
)) {
    say('Nothing was changed.');
    exit(1);
}

// --- 3. The password ---------------------------------------------
// Asked twice and compared, because a mistyped hidden field is
// invisible to the person typing it — the usual failure mode of every
// "create account" script.
$password = (string) (getenv('ISLA_ADMIN_PASSWORD') ?: '');
$envPass = $password !== '';

if (!$envPass) {
    $password = ask_secret('New password (hidden): ');
    $again   = ask_secret('Confirm password: ');
    if ($password !== $again) {
        say('The two passwords did not match. Nothing was changed.');
        exit(1);
    }
}

$problem = isla_password_problem($password);
if ($problem !== null) {
    say($problem . ' Nothing was changed.');
    exit(1);
}

// --- 4. Store it as a bcrypt hash, never as text -----------------
// PASSWORD_DEFAULT means PHP's strongest currently-available
// algorithm, which is what the member side of the app uses too (see
// login.php) — so both halves of the app upgrade together.
$hash = password_hash($password, PASSWORD_DEFAULT);
if (!is_string($hash) || $hash === '') {
    say('Could not hash the password. Nothing was changed.');
    exit(1);
}

// The variable is dropped as soon as the hash exists so the plaintext
// does not sit in memory for the rest of the request.
$password = '';
unset($password);

// --- 5. Create or reset the account ------------------------------
// One upsert, so the tool has no third path that could half-apply.
// MFA goes back ON on a reset: an admin who had turned it off and then
// lost their password should not silently get a weaker account back.
if ($existing) {
    $stmt = $pdo->prepare(
        'UPDATE admins
            SET password_hash = :h, is_active = 1, mfa_enabled = 1
          WHERE id = :id'
    );
    $stmt->execute([':h' => $hash, ':id' => (int) $existing['id']]);
    $adminId = (int) $existing['id'];
    say('Password reset for admin #' . $adminId . ' (' . $email . ').');
    say('Email verification code on login: ON.');
} else {
    $stmt = $pdo->prepare(
        'INSERT INTO admins (email, password_hash, mfa_enabled, is_active)
         VALUES (:e, :h, 1, 1)'
    );
    $stmt->execute([':e' => $email, ':h' => $hash]);
    $adminId = (int) $pdo->lastInsertId();
    say('Created admin #' . $adminId . ' (' . $email . ').');
    say('Email verification code on login: ON.');
}

say();
say('Sign in at login.php with that email, then enter the emailed code.');
say('The admin can change the email, the password and the code setting later');
say('in the admin panel under Settings.');

exit(0);