<?php
// ============================================================
// admin/settings.php — the superadmin's own credentials
//
// Three things can be changed here, and each one is gated on proof
// that the person at the keyboard is really the admin:
//
//   1. Email address — current password, then a code sent to the NEW
//      address. Proving you can read the new inbox is what stops a
//      typo (or a hijacked unlocked laptop) from locking everyone out
//      of the panel forever.
//   2. Password — current password plus the shared password policy.
//      No emailed code: they already proved who they are, and unlike
//      the email there is no new address to prove you own.
//   3. Two-factor sign-in codes — a code sent to the CURRENT address,
//      because turning MFA off is exactly what an attacker wants to
//      do. Toggling it on also takes a code, so a stolen password
//      alone cannot quietly enable MFA either.
//
// Codes live in their own session channel (admin_begin_settings_change
// / admin_settings_check_code), never in the sign-in one: a code that
// arrived to "log you in" cannot be replayed here to rewrite the
// account, and vice versa.
//
// This file handles its own POSTs rather than routing through
// action.php, because these changes are about the admin account
// itself, not about any listing.
// ============================================================

// --- 1. Session + database -------------------------------------
require_once __DIR__ . '/../../include/security.php';
session_harden();
session_start();

require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/admin_auth.php';

// --- 2. The guard. Nothing below runs unless this returns. -----
$admin = admin_require_login($pdo);

// --- 3. Messages ------------------------------------------------
$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

// Per-form errors, so a failed password change does not blank the
// email form (and vice versa). One bucket each, keyed by form name.
$errors = [
    'email'  => $_SESSION['admin_err_email']  ?? '',
    'mfa'    => $_SESSION['admin_err_mfa']    ?? '',
    'notice' => $_SESSION['admin_err_notice'] ?? '',
];
unset($_SESSION['admin_err_email'], $_SESSION['admin_err_mfa'], $_SESSION['admin_err_notice']);

function settings_fail(string $form, string $message): void
{
    $key = 'admin_err_' . $form;
    $_SESSION[$key] = $message;
    header('Location: settings.php#' . $form);
    exit;
}

function settings_done(string $message): void
{
    $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => $message];
    header('Location: settings.php');
    exit;
}

// --- 4. The three changes --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF first: without a valid token nothing below is even read, so
    // a cross-site form post cannot start an email change.
    if (!csrf_check()) {
        settings_fail('notice', 'Your session expired. Please try again.');
    }

    $do = (string) ($_POST['do'] ?? '');

    // ============================================================
    // 4a. START an email change — sends the code to the new address
    // ============================================================
    if ($do === 'start_email_change') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newEmail        = trim((string) ($_POST['new_email'] ?? ''));

        // Re-read the account: the password in hand must be checked
        // against the CURRENT hash, not whatever this request carried.
        $account = admin_account($pdo, (string) $admin['email']);
        if ($account === null || !admin_verify_password($account, $currentPassword)) {
            settings_fail('email', 'Your current password is not correct.');
        }

        if ($newEmail === '' || filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
            settings_fail('email', 'Please enter a valid email address.');
        }

        // The address is stored lowercase everywhere, so normalise
        // before comparing - otherwise Foo@x.com and foo@x.com would
        // both be allowed and the UNIQUE key would reject the second
        // insert with an error nobody can act on.
        $newEmail = strtolower($newEmail);

        if ($newEmail === strtolower((string) $admin['email'])) {
            settings_fail('email', 'That is already your admin email address.');
        }

        $stmt = $pdo->prepare('SELECT id FROM admins WHERE email = :e AND id <> :id LIMIT 1');
        $stmt->execute([':e' => $newEmail, ':id' => (int) $admin['id']]);
        if ($stmt->fetch()) {
            settings_fail('email', 'Another admin already uses that email address.');
        }

        // Carry the new address through the emailed round trip rather
        // than trusting a hidden field on the way back: a tampered
        // hidden input cannot make the panel write to an address the
        // admin never proved they own.
        admin_begin_settings_change($newEmail, 'email', ['new_email' => $newEmail]);

        $pending = admin_settings_change_pending('email');
        $sent    = (bool) ($pending['emailed'] ?? false);

        if (!$sent) {
            settings_fail('email', 'We could not send the code to that address. Check it and try again.');
        }

        settings_done('A code is on its way to ' . $newEmail . '. Enter it below to finish.');
    }

    // ============================================================
    // 4b. CONFIRM the email change with the emailed code
    // ============================================================
    if ($do === 'confirm_email_change') {
        $code = trim((string) ($_POST['code'] ?? ''));

        $challenge = admin_settings_check_code($code, 'email');
        if ($challenge === null) {
            settings_fail('email', (string) ($_SESSION['admin_settings_error'] ?? 'Invalid code.'));
        }

        $newEmail = (string) ($challenge['payload']['new_email'] ?? '');
        if ($newEmail === '' || filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
            settings_fail('email', 'That address is not valid. Please start again.');
        }

        // Re-check uniqueness at the moment of writing: a second admin
        // could have claimed the address while the code was in transit.
        $stmt = $pdo->prepare('SELECT id FROM admins WHERE email = :e AND id <> :id LIMIT 1');
        $stmt->execute([':e' => $newEmail, ':id' => (int) $admin['id']]);
        if ($stmt->fetch()) {
            settings_fail('email', 'Another admin already uses that email address.');
        }

        $stmt = $pdo->prepare('UPDATE admins SET email = :e WHERE id = :id');
        $stmt->execute([':e' => $newEmail, ':id' => (int) $admin['id']]);

        settings_done('Your admin email is now ' . $newEmail . '. Use it to sign in from now on.');
    }

    // ============================================================
    // 4c. Change the password
    // ============================================================
    if ($do === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword     = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $account = admin_account($pdo, (string) $admin['email']);
        if ($account === null || !admin_verify_password($account, $currentPassword)) {
            settings_fail('notice', 'Your current password is not correct.');
        }

        if ($newPassword !== $confirmPassword) {
            settings_fail('notice', 'The two new passwords do not match.');
        }

        // Refuse a "change" that changes nothing: it would read as
        // success while leaving the old password in place.
        if (admin_verify_password($account, $newPassword)) {
            settings_fail('notice', 'That is already your current password.');
        }

        $problem = isla_password_problem($newPassword);
        if ($problem !== null) {
            settings_fail('notice', $problem);
        }

        $stmt = $pdo->prepare(
            'UPDATE admins SET password_hash = :h, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            ':h'  => password_hash($newPassword, PASSWORD_DEFAULT),
            ':id' => (int) $admin['id'],
        ]);

        // A fresh session id after a credential change, so a session id
        // an attacker may have captured is no longer the live one.
        session_regenerate_id(true);

        settings_done('Your admin password has been changed.');
    }

    // ============================================================
    // 4d. START an MFA change — code to the CURRENT address
    // ============================================================
    if ($do === 'start_mfa_change') {
        $account = admin_account($pdo, (string) $admin['email']);
        if ($account === null || !admin_verify_password($account, (string) ($_POST['current_password'] ?? ''))) {
            settings_fail('mfa', 'Your current password is not correct.');
        }

        admin_begin_settings_change((string) $account['email'], 'mfa');

        $pending = admin_settings_change_pending('mfa');
        if (!(bool) ($pending['emailed'] ?? false)) {
            settings_fail('mfa', 'We could not send a code to your admin email. Check it and try again.');
        }

        settings_done('A code is on its way to ' . $admin['email'] . '. Enter it below to finish.');
    }

    // ============================================================
    // 4e. CONFIRM the MFA change
    // ============================================================
    if ($do === 'confirm_mfa_change') {
        $code = trim((string) ($_POST['code'] ?? ''));
        $want = (string) ($_POST['mfa_enabled'] ?? '0') === '1' ? 1 : 0;

        $challenge = admin_settings_check_code($code, 'mfa');
        if ($challenge === null) {
            settings_fail('mfa', (string) ($_SESSION['admin_settings_error'] ?? 'Invalid code.'));
        }

        $stmt = $pdo->prepare(
            'UPDATE admins SET mfa_enabled = :m, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':m' => $want, ':id' => (int) $admin['id']]);

        settings_done($want === 1
            ? 'Two-factor sign-in codes are now ON.'
            : 'Two-factor sign-in codes are now OFF. Your password alone will sign you in.');
    }

    // Anything unrecognised: refuse rather than guess.
    settings_fail('notice', 'Unknown action.');
}

// --- 5. What the page needs to render --------------------------
$emailPending  = admin_settings_change_pending('email');
$mfaPending    = admin_settings_change_pending('mfa');
$mfaEnabled    = (int) $admin['mfa_enabled'] === 1;
$pageTitle     = 'Settings';
$pageSubtitle  = 'Your admin email, password and sign-in security.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo e($pageTitle . ' · islaFIND Admin'); ?></title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../admin.css">
</head>
<body>
<div class="adm">

    <!-- ============ Sidebar ============ -->
    <aside class="adm-side">
        <div class="adm-brand">
            <div class="adm-brand-mark">iF</div>
            <div class="adm-brand-text">
                <strong>islaFIND</strong>
                <span>Superadmin</span>
            </div>
        </div>

        <nav class="adm-nav" aria-label="Admin sections">
            <a class="adm-nav-link" href="index.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
                Overview
            </a>
            <a class="adm-nav-link" href="index.php?view=reports&amp;status=pending">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21v-2a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Reports
            </a>
            <a class="adm-nav-link is-active" href="settings.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A2.65 2.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A2.65 2.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A2.65 2.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a2.65 2.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0 .33 1.82 1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                Settings
            </a>
        </nav>

        <div class="adm-side-foot">
            <div class="adm-who">
                <div class="adm-who-avatar"><?php echo e(strtoupper(substr((string) ($admin['full_name'] ?: $admin['email']), 0, 1))); ?></div>
                <div class="adm-who-text">
                    <strong><?php echo e($admin['full_name'] ?: 'Superadmin'); ?></strong>
                    <span><?php echo e($admin['email']); ?></span>
                </div>
            </div>
            <form action="logout.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                <button type="submit" class="btn btn-outline btn-block btn-small">Sign out</button>
            </form>
        </div>
    </aside>

    <!-- ============ Main ============ -->
    <main class="adm-main">
        <header class="adm-top">
            <div>
                <h1><?php echo e($pageTitle); ?></h1>
                <p><?php echo e($pageSubtitle); ?></p>
            </div>
        </header>

        <div class="adm-body">

            <?php if ($flash !== null): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'error' : 'success'; ?>" role="status">
                    <?php echo e($flash['msg']); ?>
                </div>
            <?php endif; ?>

            <!-- ---------- Current sign-in email ---------- -->
            <section class="adm-panel">
                <div class="adm-panel-head">
                    <h2>Sign-in email</h2>
                    <p>Where your verification codes go.</p>
                </div>
                <div class="adm-panel-body">
                    <p class="adm-secondary"><strong><?php echo e($admin['email']); ?></strong></p>
                </div>
            </section>

            <!-- ---------- Change email ---------- -->
            <section class="adm-panel" id="email">
                <div class="adm-panel-head">
                    <h2>Change email address</h2>
                    <p>We send a code to the new address before anything moves, so a typo cannot lock you out.</p>
                </div>
                <div class="adm-panel-body">
                    <?php if ($errors['email'] !== ''): ?>
                        <div class="alert alert-error" role="alert"><?php echo e($errors['email']); ?></div>
                    <?php endif; ?>

                    <?php if ($emailPending !== null): ?>
                        <form method="POST" class="adm-form">
                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="do" value="confirm_email_change">
                            <p class="adm-secondary">
                                Enter the 6-digit code sent to
                                <strong><?php echo e((string) $emailPending['email']); ?></strong>.
                                It expires in 2 minutes.
                            </p>
                            <div class="field">
                                <label class="field-label" for="email_code">Verification code</label>
                                <input class="field-in adm-code" type="text" id="email_code" name="code"
                                       inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                                       pattern="[0-9]{6}" placeholder="000000" required>
                            </div>
                            <div class="adm-actions-row">
                                <button type="submit" class="btn">Confirm new email</button>
                                <a class="btn btn-outline" href="settings.php#email">Cancel</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <form method="POST" class="adm-form">
                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="do" value="start_email_change">

                            <div class="field">
                            <label class="field-label" for="current_password_email">Current password</label>
                            <input class="field-in" type="password" id="current_password_email"
                                   name="current_password" autocomplete="current-password" required>
                        </div>

                            <div class="field">
                            <label class="field-label" for="new_email">New email address</label>
                            <input class="field-in" type="email" id="new_email" name="new_email"
                                   autocomplete="email" required>
                        </div>

                            <div class="adm-actions-row">
                                <button type="submit" class="btn">Send code to new address</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ---------- Change password ---------- -->
            <section class="adm-panel" id="password">
                <div class="adm-panel-head">
                    <h2>Change password</h2>
                    <p>At least 8 characters, with an uppercase letter, a lowercase letter and one of ! @ -.</p>
                </div>
                <div class="adm-panel-body">
                    <?php if ($errors['notice'] !== ''): ?>
                        <div class="alert alert-error" role="alert"><?php echo e($errors['notice']); ?></div>
                    <?php endif; ?>

                    <form method="POST" class="adm-form">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="do" value="change_password">

                        <div class="field">
                            <label class="field-label" for="current_password">Current password</label>
                            <input class="field-in" type="password" id="current_password"
                                   name="current_password" autocomplete="current-password" required>
                        </div>

                        <div class="field">
                            <label class="field-label" for="new_password">New password</label>
                            <input class="field-in" type="password" id="new_password"
                                   name="new_password" autocomplete="new-password" required>
                        </div>

                        <div class="field">
                            <label class="field-label" for="confirm_password">Confirm new password</label>
                            <input class="field-in" type="password" id="confirm_password"
                                   name="confirm_password" autocomplete="new-password" required>
                        </div>

                        <div class="adm-actions-row">
                            <button type="submit" class="btn">Change password</button>
                        </div>
                    </form>
                </div>
            </section>

            <!-- ---------- Two-factor ---------- -->
            <section class="adm-panel" id="mfa">
                <div class="adm-panel-head">
                    <h2>Two-factor sign-in</h2>
                    <p>
                        <?php if ($mfaEnabled): ?>
                            <strong>On.</strong> After your password, we email a 6-digit code to
                            <?php echo e($admin['email']); ?>.
                        <?php else: ?>
                            <strong>Off.</strong> Your password alone signs you in. Turning this on is
                            strongly recommended.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="adm-panel-body">
                    <?php if ($errors['mfa'] !== ''): ?>
                        <div class="alert alert-error" role="alert"><?php echo e($errors['mfa']); ?></div>
                    <?php endif; ?>

                    <?php if ($mfaPending !== null): ?>
                        <form method="POST" class="adm-form">
                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="do" value="confirm_mfa_change">
                            <input type="hidden" name="mfa_enabled" value="<?php echo $mfaEnabled ? '0' : '1'; ?>">
                            <p class="adm-secondary">
                                Enter the 6-digit code sent to
                                <strong><?php echo e((string) $mfaPending['email']); ?></strong>
                                to <?php echo $mfaEnabled ? 'turn this OFF' : 'turn this ON'; ?>.
                                It expires in 2 minutes.
                            </p>
                            <div class="field">
                            <label class="field-label" for="mfa_code">Verification code</label>
                            <input class="field-in adm-code" type="text" id="mfa_code" name="code"
                                   inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                                   pattern="[0-9]{6}" placeholder="000000" required>
                        </div>
                            <div class="adm-actions-row">
                                <button type="submit" class="btn">Confirm change</button>
                                <a class="btn btn-outline" href="settings.php#mfa">Cancel</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <form method="POST" class="adm-form">
                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="do" value="start_mfa_change">

                            <div class="field">
                            <label class="field-label" for="current_password_mfa">Current password</label>
                            <input class="field-in" type="password" id="current_password_mfa"
                                   name="current_password" autocomplete="current-password" required>
                        </div>

                            <div class="adm-actions-row">
                                <button type="submit" class="btn">
                                    <?php echo $mfaEnabled ? 'Turn two-factor OFF' : 'Turn two-factor ON'; ?>
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ---------- How the account is created ---------- -->
            <section class="adm-panel">
                <div class="adm-panel-head">
                    <h2>Creating or resetting this account</h2>
                    <p>There is no self-service admin signup, by design.</p>
                </div>
                <div class="adm-panel-body">
                    <p class="adm-secondary">
                        Superadmin accounts are made on the server, from a shell, with the password typed
                        in blind so it never reaches your shell history:
                    </p>
                    <pre class="adm-code-block">php tools/create_admin.php --email=you@example.com</pre>
                    <p class="adm-secondary">
                        Run the same command with <strong>--reset</strong> to set a new password from the
                        command line. Everything on this page works afterwards: change the email here, and
                        change the password from the tool or from the form above.
                    </p>
                </div>
            </section>

        </div>
    </main>
</div>
</body>
</html>
