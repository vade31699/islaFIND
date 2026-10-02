<?php
// ============================================================
// admin_auth.php — superadmin identity, kept separate from members
//
// Every superadmin page starts with:
//
//     require_once __DIR__ . '/../include/security.php';
//     session_harden(); session_start();
//     require_once __DIR__ . '/../../include/db.php';
//     require_once __DIR__ . '/../../include/admin_auth.php';
//     $admin = admin_require_login($pdo);
//
// WHY A SEPARATE FILE AND A SEPARATE SESSION KEY
// ----------------------------------------------
// The `admins` table is NOT a kind of user. A superadmin can read the
// moderation queue and act on any member's listing, so it must never be
// reachable through a member's code path: if an admin session could be
// expressed as $_SESSION['user_id'], every member page would treat it
// as a logged-in member, and every member guard would silently pass an
// admin who was never a member. So:
//
//   - the session key is admin_id, never user_id
//   - admin login never writes user_id, and member login never writes
//     admin_id
//   - no member page reads this file
//
// The helpers below are the only place that knows those rules, so the
// rule cannot be enforced "almost" correctly in two different pages.
// ============================================================

// ------------------------------------------------------------------
// The mailer this file depends on.
// ------------------------------------------------------------------
// sendAdminMfaEmail() lives in mailer.php, and BOTH
// admin_begin_challenge() (sign-in) and admin_begin_settings_change()
// (an email or MFA change) call it. Requiring it here rather than
// leaving it to each page is deliberate: login.php happens to load the
// mailer already, so the missing include stayed invisible until
// admin/settings.php reached the one flow that sends from this file.
// A dependency of this file belongs to this file. mailer.php is
// self-sufficient (it loads Composer's autoloader and .env itself)
// and require_once makes a second load a no-op.
// ------------------------------------------------------------------
require_once __DIR__ . '/mailer.php';

// admin_asset_url() below shares its stamping logic with the member
// app's asset_url(); assets.php owns that primitive.
require_once __DIR__ . '/assets.php';

// ------------------------------------------------------------------
// One-time-code policy for the SUPERADMIN sign-in challenge.
// ------------------------------------------------------------------
// Deliberately its own set of constants rather than the CODE_* names
// login.php defines for members: those are declared with `const` at the
// top level of login.php, so a second definition anywhere in the same
// request would be a fatal error. Separate names also make it obvious at
// the call site that an admin's code is governed by admin policy.
//
// The values are the member values (2 minutes for one code, 20 minutes
// for the whole challenge) because an admin's mailbox is worth exactly
// as much as a member's, and a superadmin code that lived longer would
// be the weakest credential in the app.
const ADMIN_CODE_TTL            = 120;
const ADMIN_CODE_RESEND_COOLDOWN = 120;
const ADMIN_CODE_TOTAL_LIFETIME  = 1200;

// How many code guesses one challenge allows. A 6-digit code has a
// million possibilities, and two minutes is a hundred-odd guesses' worth
// of typing — so this limit is only ever reached by a script. Without it
// a 6-digit code is brute-forceable from a fast connection, and with it
// the attacker has to start over (and wait out the resend cooldown) every
// three tries.
const ADMIN_MFA_MAX_GUESSES = 3;

/**
 * ADMIN_NO_ACCOUNT_HASH — a real bcrypt hash of a value nobody knows.
 *
 * Used when the typed identifier matches no admin at all. Without it, the
 * "no such account" path would skip password_verify() entirely and finish
 * measurably faster than the "wrong password" path, so the response time
 * alone would tell an attacker which addresses are real admin accounts.
 * Verifying against this hash makes both paths do the same work.
 *
 * The plaintext behind it is a random string generated once, at author
 * time; it exists only so the hash is not a known, crackable password.
 * Regenerating it is safe: it is not used to authenticate anything.
 */
const ADMIN_NO_ACCOUNT_HASH = '$2y$10$K3Jf9xQ2mTvBpLzY8WnXeH4cR7uA1dG6iO0pS3wXjH5nB8yVm2ZqTu';

/**
 * admin_account(PDO $pdo, string $identifier): ?array
 * The admin row matching this identifier (an email address), or NULL.
 *
 * Only ever called with an address that has been lowercased and trimmed,
 * which is why the comparison is exact rather than a case-insensitive
 * one: MySQL's default collation is already case-insensitive here, and
 * making the app agree with it explicitly would mean a query whose
 * behaviour depends on a server setting.
 *
 * @param PDO    $pdo        Connection.
 * @param string $identifier Lowercased email typed on the login form.
 * @return array|null Row, or NULL when there is no such admin.
 */
function admin_account(PDO $pdo, string $identifier): ?array
{
    if ($identifier === '' || strpos($identifier, '@') === false) {
        return null;   // admins sign in by email only — no phone lookup
    }

    $stmt = $pdo->prepare(
        'SELECT id, email, password_hash, full_name, mfa_enabled, is_active
           FROM admins
          WHERE email = :e
          LIMIT 1'
    );
    $stmt->execute([':e' => $identifier]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/**
 * admin_verify_password(array|null $account, string $password): bool
 * Was that the account's password? True when the account exists, is
 * active, and the password matches.
 *
 * Runs password_verify() even for a missing or deactivated account so
 * all three cases cost the same time (see ADMIN_NO_ACCOUNT_HASH). It
 * returns false for a deactivated account, so "deactivated" is
 * indistinguishable from "wrong password" from outside.
 *
 * @param array|null $account  Row from admin_account(), or NULL.
 * @param string     $password Password typed on the form.
 * @return bool
 */
function admin_verify_password(?array $account, string $password): bool
{
    $hash = ADMIN_NO_ACCOUNT_HASH;
    $ok   = false;

    if ($account !== null) {
        $hash = (string) $account['password_hash'];
        if ((int) $account['is_active'] === 1 && $password !== '') {
            $ok = password_verify($password, $hash);
        }
    } else {
        // Same work, same cost, no account to match against.
        password_verify($password === '' ? 'x' : $password, $hash);
    }

    return $ok;
}

/**
 * admin_begin_challenge(PDO $pdo, array $account, string $subject): array
 * Issue the emailed code for a successful password check and store the
 * challenge in the session. Returns the challenge for the caller to
 * report on (and to tell the page whether SMTP took it).
 *
 * The code is the same 6-digit random_int the member flow uses and is
 * sent with the same mailer, so a second mail template is not needed.
 *
 * Nothing here records the account as logged in: the session is only
 * promoted to a real admin session by admin_complete_login() once the
 * code has been answered.
 *
 * @param PDO    $pdo      Connection.
 * @param array  $account  Row from admin_account() (password already checked).
 * @param string $subject  Subject line for the code email.
 * @return array The session challenge.
 */
function admin_begin_challenge(PDO $pdo, array $account, string $subject): array
{
    $code = (string) random_int(100000, 999999);

    $_SESSION['admin_mfa'] = [
        'admin_id'            => (int) $account['id'],
        'email'               => $account['email'],
        'code'                => $code,
        'expires'             => time() + ADMIN_CODE_TTL,
        'started_at'          => time(),
        'resend_available_at' => time() + ADMIN_CODE_RESEND_COOLDOWN,
        'guesses'             => 0,
        'emailed'             => sendAdminMfaEmail($account['email'], $code, $subject),
    ];

    return $_SESSION['admin_mfa'];
}

/**
 * admin_resend_challenge(PDO $pdo, string $subject): ?string
 * Re-send the code for the challenge already in the session, honouring
 * both limits: the cooldown between sends and the hard cap on how long
 * the challenge may live. Returns null on success, or a sentence to show
 * the admin when the request was refused or the challenge is spent.
 *
 * @param PDO    $pdo     Connection.
 * @param string $subject Subject line for the code email.
 * @return string|null Error text, or NULL when a code was sent.
 */
function admin_resend_challenge(PDO $pdo, string $subject): ?string
{
    if (!isset($_SESSION['admin_mfa'])) {
        return 'No verification request is pending.';
    }

    $challenge = &$_SESSION['admin_mfa'];
    $wait      = (int) ($challenge['resend_available_at'] ?? 0) - time();
    $expiresAt = (int) ($challenge['started_at'] ?? time()) + ADMIN_CODE_TOTAL_LIFETIME;

    if ($expiresAt <= time()) {
        unset($challenge);
        return 'This verification request has expired. Please sign in again.';
    }
    if ($wait > 0) {
        unset($challenge);
        return 'Please wait ' . $wait . ' more second(s) before requesting another code.';
    }

    // A fresh code replaces the old one — a resent code must invalidate
    // the previous one, or the first code an attacker saw stay usable.
    $code = (string) random_int(100000, 999999);
    $challenge['code']                = $code;
    $challenge['expires']             = min(time() + ADMIN_CODE_TTL, $expiresAt);
    $challenge['resend_available_at'] = time() + ADMIN_CODE_RESEND_COOLDOWN;
    $challenge['guesses']             = 0;   // a new code gets a fresh allowance
    $challenge['emailed']             = sendAdminMfaEmail((string) $challenge['email'], $code, $subject);
    unset($challenge);

    return null;
}

/**
 * admin_check_challenge(string $code, string $subject): ?array
 * Judge a submitted code. On success returns the challenge (already
 * consumed) for the caller to complete the login with; on failure
 * returns null after writing the reason into $_SESSION['admin_mfa_error'].
 *
 * Failure reasons are deliberately few, and none of them says whether the
 * code was ever right.
 *
 * @param string $code    Code typed on the form.
 * @param string $subject Subject line for the code email.
 * @return array|null The consumed challenge, or NULL when it did not pass.
 */
function admin_check_challenge(string $code, string $subject): ?array
{
    $_SESSION['admin_mfa_error'] = '';

    if (!isset($_SESSION['admin_mfa'])) {
        return null;
    }

    $challenge = $_SESSION['admin_mfa'];

    if ($code === '') {
        $_SESSION['admin_mfa_error'] = 'Please enter the verification code.';
        return null;
    }

    if ((int) $challenge['expires'] < time()) {
        $_SESSION['admin_mfa_error'] = 'This code has expired. Please resend a new one.';
        return null;
    }

    // Guessing is counted against the challenge itself, not the session,
    // so three wrong guesses cost a full resend wait even if the admin
    // reloads the page in between.
    if ((int) ($challenge['guesses'] ?? 0) >= ADMIN_MFA_MAX_GUESSES) {
        $_SESSION['admin_mfa_error'] = 'Too many incorrect codes. Please request a new one.';
        return null;
    }

    if (!hash_equals((string) $challenge['code'], $code)) {
        $_SESSION['admin_mfa']['guesses'] = (int) ($challenge['guesses'] ?? 0) + 1;
        $left = ADMIN_MFA_MAX_GUESSES - (int) $_SESSION['admin_mfa']['guesses'];
        $_SESSION['admin_mfa_error'] = 'Invalid verification code.'
            . ($left > 0 ? ' ' . $left . ' attempt(s) left.' : '');
        return null;
    }

    // The code was right. The challenge is dropped here so it cannot be
    // answered twice; admin_complete_login() re-reads the account by id.
    unset($_SESSION['admin_mfa'], $_SESSION['admin_mfa_error']);
    return $challenge;
}

// ============================================================
// Settings changes (email / MFA) - their OWN challenge channel
// ============================================================
//
// Signing in uses $_SESSION['admin_mfa']. Changing the credentials that
// guard the panel is a different action with a bigger blast radius, so
// it gets a separate session key rather than borrowing that one:
//
//   * starting a settings code can never disturb an in-flight sign-in
//     (and vice versa), and
//   * a code that arrived for "sign in" can NEVER be replayed here to
//     change the admin's email - the two never share storage.
//
// Same TTL, same guess budget, same constant-time comparison as login.

/**
 * admin_begin_settings_change(string $email, string $action, array $payload = []): string
 * Mint a code, store it against the admin's session and email it.
 *
 * @param string $email   Where the code goes (the NEW address for an
 *                        email change, the current one otherwise).
 * @param string $action  Which change this authorises: 'email' or 'mfa'.
 *                        Checked again on the way back in, so a code
 *                        issued for one can never complete the other.
 * @param array  $payload Extra data to carry across the round trip
 *                        (the pending new email).
 * @return string The code (returned only so the caller could log it).
 */
function admin_begin_settings_change(string $email, string $action, array $payload = []): string
{
    $code = (string) random_int(100000, 999999);

    $_SESSION['admin_settings_code'] = [
        'action'    => $action,
        'email'     => $email,
        'code'      => $code,
        'payload'   => $payload,
        'expires'   => time() + ADMIN_CODE_TTL,
        'guesses'   => 0,
        'emailed'   => sendAdminMfaEmail(
            $email,
            $code,
            $action === 'email'
                ? 'Confirm your new islaFIND admin email'
                : 'Confirm your islaFIND admin settings change'
        ),
    ];

    return $code;
}

/**
 * admin_settings_change_pending(string $action): ?array
 * The unspent challenge for this action, or NULL if there is none or
 * the one there was issued for something else.
 */
function admin_settings_change_pending(string $action): ?array
{
    $challenge = $_SESSION['admin_settings_code'] ?? null;

    if (!is_array($challenge) || (string) ($challenge['action'] ?? '') !== $action) {
        return null;
    }

    return $challenge;
}

/**
 * admin_settings_check_code(string $code, string $action): ?array
 * Spend a settings challenge.
 *
 * @return array|null The (now spent) challenge on success, or NULL on
 *                    failure with the reason in $_SESSION['admin_settings_error'].
 */
function admin_settings_check_code(string $code, string $action): ?array
{
    $_SESSION['admin_settings_error'] = '';

    $challenge = admin_settings_change_pending($action);

    if ($challenge === null) {
        $_SESSION['admin_settings_error'] = 'Please request a new verification code.';
        return null;
    }

    if ((int) $challenge['expires'] < time()) {
        unset($_SESSION['admin_settings_code']);
        $_SESSION['admin_settings_error'] = 'That code has expired. Please request a new one.';
        return null;
    }

    if ((int) ($challenge['guesses'] ?? 0) >= ADMIN_MFA_MAX_GUESSES) {
        unset($_SESSION['admin_settings_code']);
        $_SESSION['admin_settings_error'] = 'Too many incorrect codes. Please request a new one.';
        return null;
    }

    if (!hash_equals((string) $challenge['code'], trim($code))) {
        $_SESSION['admin_settings_code']['guesses'] = (int) ($challenge['guesses'] ?? 0) + 1;
        $left = ADMIN_MFA_MAX_GUESSES - (int) $_SESSION['admin_settings_code']['guesses'];
        $_SESSION['admin_settings_error'] = 'Invalid verification code.'
            . ($left > 0 ? ' ' . $left . ' attempt(s) left.' : '');
        return null;
    }

    unset($_SESSION['admin_settings_code'], $_SESSION['admin_settings_error']);
    return $challenge;
}

/** admin_clear_settings_change(): void - drop any unspent challenge. */
function admin_clear_settings_change(): void
{
    unset($_SESSION['admin_settings_code'], $_SESSION['admin_settings_error']);
}

/**
 * admin_complete_login(PDO $pdo, int $adminId): ?array
 * Promote a passed challenge to a real admin session and return the
 * account row (or NULL when the account has been deactivated or deleted
 * since it was checked).
 *
 * A FRESH session id is generated before the identity is written, which
 * is what prevents session fixation: an attacker who managed to plant a
 * session id in the browser cannot ride it into the admin panel.
 *
 * Takes an id rather than the whole challenge because an account with
 * MFA turned off never gets a challenge at all — but both paths land
 * here, and both must get the new session id.
 *
 * @param PDO $pdo     Connection.
 * @param int $adminId admins.id of the account being signed in.
 * @return array|null The admin row, or NULL if it is no longer usable.
 */
function admin_complete_login(PDO $pdo, int $adminId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, email, password_hash, full_name, mfa_enabled, is_active
           FROM admins
          WHERE id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $adminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    // Deactivated between the password check and the code being typed:
    // refuse, rather than honouring a challenge issued before the block.
    if ($admin === false || (int) $admin['is_active'] !== 1) {
        return null;
    }

    // A new id on the privilege change, and a new CSRF token with it.
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);

    // The identity. admin_id only — never user_id (see the file header).
    $_SESSION['admin_id']       = (int) $admin['id'];
    $_SESSION['admin_email']    = $admin['email'];
    $_SESSION['admin_name']     = $admin['full_name'];
    // The moment a second factor last held, so the settings page can say
    // when the last code was entered without storing any code.
    $_SESSION['admin_mfa_at']   = time();

    $stmt = $pdo->prepare(
        'UPDATE admins
            SET last_login_at = NOW(), last_login_ip = :ip
          WHERE id = :id'
    );
    $stmt->execute([
        ':ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ':id' => (int) $admin['id'],
    ]);

    return $admin;
}

/**
 * admin_current(PDO $pdo): ?array
 * The signed-in admin's row, re-read from the database on every call.
 *
 * Re-read rather than trusted from the session on purpose: a deactivated
 * or deleted account must lose access on its NEXT request, not whenever
 * its session happens to expire.
 *
 * @param PDO $pdo Connection.
 * @return array|null
 */
function admin_current(PDO $pdo): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, email, full_name, mfa_enabled, is_active, last_login_at, created_at
           FROM admins
          WHERE id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => (int) $_SESSION['admin_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin === false || (int) $admin['is_active'] !== 1) {
        return null;
    }

    return $admin;
}

/**
 * admin_require_login(PDO $pdo): array
 * The guard every admin page opens with. Returns the admin row, or
 * redirects a non-admin away and stops the script.
 *
 * Two things it refuses beyond "not logged in":
 *   - a session that claims to be a member. The admin panel and the member
 *     app share one session, so a member who walks into this URL is sent
 *     to their dashboard rather than shown an empty admin page, and no
 *     admin_id is ever written for a member session.
 *   - a stale or forged admin_id, resolved by admin_current() above.
 *
 * @param PDO $pdo Connection.
 * @return array The signed-in admin's row.
 */
function admin_require_login(PDO $pdo): array
{
    $admin = admin_current($pdo);

    if ($admin !== null) {
        return $admin;
    }

    // Not an admin (or no longer one). Clear the marker so a stale id
    // cannot keep re-triggering this branch.
    unset($_SESSION['admin_id'], $_SESSION['admin_email'], $_SESSION['admin_name']);

    if (isset($_SESSION['user_id'])) {
        // A signed-in member: back to their own app.
        header('Location: ' . sid_append('dashboard.php'));
        exit;
    }

    header('Location: ' . sid_append('login.php?admin=1'));
    exit;
}

/**
 * admin_forget(): void
 * Sign the admin out: drop the admin identity (and only that) and give
 * the session a new id.
 *
 * The whole session is NOT destroyed, so a member signed in on the same
 * browser keeps their own login when they switch between the member app
 * and the admin panel.
 *
 * @return void
 */
function admin_forget(): void
{
    unset(
        $_SESSION['admin_id'],
        $_SESSION['admin_email'],
        $_SESSION['admin_name'],
        $_SESSION['admin_mfa'],
        $_SESSION['admin_mfa_error'],
        $_SESSION['admin_mfa_at'],
        $_SESSION['admin_pending_email_change'],
        $_SESSION['admin_pending_mfa_change']
    );
    session_regenerate_id(true);
}

/**
 * admin_asset_url(string $href): string
 * Stamp an admin asset URL with the file's modification time, so a
 * browser holding the old copy is forced to fetch the new one the
 * moment the file changes.
 *
 * WHY THIS EXISTS even though public/.htaccess already sends
 * "Expires: 0 seconds" for CSS and JS: a zero expiry tells the browser
 * to revalidate, but a phone that backgrounds the panel — or restores
 * a page from the back/forward cache — can still paint the copy it
 * saved earlier, and a menu that only appears after a reload is the
 * kind of change an operator reports as "it did not apply". A `?v=`
 * stamp gives the browser something concrete to compare instead.
 *
 * Generic on purpose: pass the href exactly as it is written in the
 * page and it works for any admin-side asset, stylesheet or script.
 * The path is resolved from public/admin/, which is where those pages
 * link from; a file that cannot be read is returned untouched rather
 * than taking the page down over an asset stamp. The stamping itself
 * lives in include/assets.php, which the member app shares.
 *
 * @param string $href URL as written in the page, e.g. '../admin.css'.
 * @return string The href with ?v=<mtime> appended.
 */
function admin_asset_url(string $href): string
{
    return isla_asset_stamp($href, __DIR__ . '/../public/admin');
}