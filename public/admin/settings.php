<?php
// ============================================================
// admin/settings.php — the superadmin's own credentials
//
// Settings is a HUB, not a page of stacked forms: clicking Settings
// lists the things that can be changed, and each one opens on its own
// screen (?section=email|password|mfa|account). One decision at a
// time — a pending emailed-code form is not competing with two other
// panels for attention.
//
// Each change is gated on proof that the person at the keyboard is
// really the admin:
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

// --- 3. Which section, if any ----------------------------------
// The overview is the empty value; everything else is one of the
// sections below. Whitelisted, so the query string can only ever
// select a screen that exists.
$sections = ['email', 'password', 'mfa'];

$section = (string) ($_GET['section'] ?? '');
if (!in_array($section, $sections, true)) {
    $section = '';
}

// --- 4. Messages ------------------------------------------------
$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

// Per-section errors, so a failed password change does not blank the
// email form (and vice versa). One bucket each, keyed by section.
$errors = [
    'email'    => $_SESSION['admin_err_email']    ?? '',
    'password' => $_SESSION['admin_err_password'] ?? '',
    'mfa'      => $_SESSION['admin_err_mfa']      ?? '',
];
unset($_SESSION['admin_err_email'], $_SESSION['admin_err_password'], $_SESSION['admin_err_mfa']);

/** Back to the section that failed, with its own error message. */
function settings_fail(string $section, string $message): void
{
    $_SESSION['admin_err_' . $section] = $message;
    header('Location: settings.php?section=' . $section);
    exit;
}

/**
 * A finished change. $to is where to land: the hub for anything that
 * is now done, or a section when the work continues there (the
 * emailed-code step).
 */
function settings_done(string $message, string $to = ''): void
{
    $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => $message];
    header('Location: settings.php' . ($to !== '' ? '?section=' . $to : ''));
    exit;
}

// --- 5. The changes ---------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF first: without a valid token nothing below is even read, so
    // a cross-site form post cannot start an email change.
    if (!csrf_check()) {
        settings_done('Your session expired. Please try again.');
    }

    $do = (string) ($_POST['do'] ?? '');

    // ============================================================
    // 5a. START an email change — sends the code to the new address
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
        if (!(bool) ($pending['emailed'] ?? false)) {
            settings_fail('email', 'We could not send the code to that address. Check it and try again.');
        }

        settings_done('A code is on its way to ' . $newEmail . '. Enter it below to finish.', 'email');
    }

    // ============================================================
    // 5b. CONFIRM the email change with the emailed code
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
    // 5c. Change the password
    // ============================================================
    if ($do === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword     = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $account = admin_account($pdo, (string) $admin['email']);
        if ($account === null || !admin_verify_password($account, $currentPassword)) {
            settings_fail('password', 'Your current password is not correct.');
        }

        if ($newPassword !== $confirmPassword) {
            settings_fail('password', 'The two new passwords do not match.');
        }

        // Refuse a "change" that changes nothing: it would read as
        // success while leaving the old password in place.
        if (admin_verify_password($account, $newPassword)) {
            settings_fail('password', 'That is already your current password.');
        }

        $problem = isla_password_problem($newPassword);
        if ($problem !== null) {
            settings_fail('password', $problem);
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
    // 5d. START an MFA change — code to the CURRENT address
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

        settings_done('A code is on its way to ' . $admin['email'] . '. Enter it below to finish.', 'mfa');
    }

    // ============================================================
    // 5e. CONFIRM the MFA change
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
    settings_done('Unknown action.');
}

// --- 6. What the page needs to render --------------------------
$emailPending = admin_settings_change_pending('email');
$mfaPending   = admin_settings_change_pending('mfa');
$mfaEnabled   = (int) $admin['mfa_enabled'] === 1;

// One place where each section's label, status and icon live, so the
// hub and the section screen can never drift apart. `status` is the
// one line the hub shows next to the label.
$sectionMeta = [
    'email' => [
        'label'  => 'Email address',
        'status' => (string) $admin['email'],
        'icon'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7.5l9 6 9-6"/>',
    ],
    'password' => [
        'label'  => 'Password',
        'status' => 'Last changed on this page or from the server tool',
        'icon'   => '<rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    ],
    'mfa' => [
        'label'  => 'Two-factor sign-in',
        'status' => $mfaEnabled ? 'On' : 'Off',
        'icon'   => '<path d="M12 3l7 3v6c0 4.5-3 8-7 8s-7-3.5-7-8V6z"/><path d="M9.5 12.5l1.8 1.8 3.4-3.8"/>',
    ],
];

// A pending emailed code is unfinished work, so the hub says so and
// the section carries the form that finishes it.
$pendingSection = null;
if ($emailPending !== null) {
    $pendingSection = 'email';
} elseif ($mfaPending !== null) {
    $pendingSection = 'mfa';
}

// --- 7. Page furniture -----------------------------------------
$sectionLabel  = $section !== '' ? $sectionMeta[$section]['label'] : '';

$pageTitle = $section === '' ? 'Settings' : $sectionLabel;

// Where the header's Back button goes: a section returns to the hub.
$backHref = 'index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo e($pageTitle . ' · islaFIND Admin'); ?></title>
    <link rel="stylesheet" href="<?php echo e(admin_asset_url('../style.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(admin_asset_url('../admin.css')); ?>">
</head>
<body>
<div class="adm">

    <!-- Below 860px this checkbox folds the sections, the account row and
         the sign-out button behind the hamburger — the round header
         button, and the card of rows, the member app uses for the same
         job — and stands the page content down while it is open. That is
         what opening the menu does in the member app: the menu takes the
         screen, not a slice of it.

         It sits out here, in front of both columns, rather than inside
         the sidebar, because a checked box can only style what comes
         after it — and this one has to reach the sidebar (what unfolds)
         as well as the main column (what gets out of the way). A
         checkbox rather than a script keeps the panel working with
         JavaScript off, which matters for a moderation tool an operator
         may open on a locked-down machine. -->
    <input type="checkbox" id="adm-menu" class="adm-menu-toggle">

    <!-- ============ Sidebar ============ -->
    <aside class="adm-side">
        <div class="adm-brand">
            <div class="adm-brand-mark">iF</div>
            <div class="adm-brand-text">
                <strong>islaFIND</strong>
                <span>Superadmin</span>
            </div>
            <label class="adm-burger" for="adm-menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                <span class="adm-burger-text">Menu</span>
            </label>
        </div>

        <!-- Everything the hamburger folds: the sections, then the
             account row and the sign-out button. On a phone this is
             one card of rows, like the member app's menu.
             The inner div is the card's single grid item, which is
             what lets the animation below reveal it from nothing
             without a guessed max-height. -->
        <div class="adm-hub">
            <div class="adm-hub-inner">
                <nav class="adm-nav" aria-label="Admin sections">
                    <a class="adm-nav-link" href="index.php">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
                        <span class="adm-nav-label">Overview</span>
                        <span class="adm-nav-go" aria-hidden="true">&#8250;</span>
                    </a>
                    <a class="adm-nav-link" href="index.php?view=reports&amp;status=pending">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21v-2a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span class="adm-nav-label">Reports</span>
                        <span class="adm-nav-go" aria-hidden="true">&#8250;</span>
                    </a>
                    <a class="adm-nav-link is-active" href="settings.php">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.2.61.77 1.02 1.41 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        <span class="adm-nav-label">Settings</span>
                        <span class="adm-nav-go" aria-hidden="true">&#8250;</span>
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
            </div>
        </div>
    </aside>

    <!-- ============ Main ============ -->
    <main class="adm-main">
        <header class="adm-top">
            <a class="adm-back" href="<?php echo e($backHref); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
                Back
            </a>
            <div>
                <h1><?php echo e($pageTitle); ?></h1>
            </div>
        </header>

        <div class="adm-body">

            <?php if ($flash !== null): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'error' : 'success'; ?>" role="status">
                    <?php echo e($flash['msg']); ?>
                </div>
            <?php endif; ?>

            <?php if ($section === ''): ?>
            <!-- ============ The hub ============ -->
            <div class="adm-panel">
                <div class="adm-panel-head">
                    <div>
                        <h2>Account security</h2>
                    </div>
                </div>

                <div class="adm-option-list">
                    <?php foreach ($sectionMeta as $key => $meta): ?>
                        <a class="adm-option" href="settings.php?section=<?php echo e($key); ?>">
                            <span class="adm-option-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $meta['icon']; ?></svg>
                            </span>
                            <span class="adm-option-text">
                                <strong><?php echo e($meta['label']); ?></strong>
                            </span>
                            <span class="adm-option-meta">
                                <?php if ($key === $pendingSection): ?>
                                    <span class="adm-badge adm-badge-pending"><span class="adm-badge-dot"></span>Code pending</span>
                                <?php else: ?>
                                    <span class="adm-option-status"><?php echo e($meta['status']); ?></span>
                                <?php endif; ?>
                                <span class="adm-option-go" aria-hidden="true">&#8250;</span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php elseif ($section === 'email'): ?>
            <!-- ============ Email address ============ -->
            <div class="adm-panel">
                <div class="adm-panel-head">
                    <div>
                        <h2>Email address</h2>
                    </div>
                </div>
                <div class="adm-panel-body">
                    <p class="adm-secondary">
                        Currently signing in as <strong><?php echo e($admin['email']); ?></strong>
                    </p>

                    <?php if ($errors['email'] !== ''): ?>
                        <div class="alert alert-error" role="alert"><?php echo e($errors['email']); ?></div>
                    <?php endif; ?>

                    <?php if ($emailPending !== null): ?>
                        <form method="POST" class="adm-form">
                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="do" value="confirm_email_change">
                            <div class="field">
                                <label class="field-label" for="email_code">Verification code</label>
                                <input class="field-in adm-code" type="text" id="email_code" name="code"
                                       inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                                       pattern="[0-9]{6}" placeholder="000000" required>
                            </div>
                            <div class="adm-actions-row">
                                <button type="submit" class="btn">Confirm new email</button>
                                <a class="btn btn-outline" href="settings.php?section=email">Cancel</a>
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
            </div>

            <?php elseif ($section === 'password'): ?>
            <!-- ============ Password ============ -->
            <div class="adm-panel">
                <div class="adm-panel-head">
                    <div>
                        <h2>Password</h2>
                    </div>
                </div>
                <div class="adm-panel-body">
                    <?php if ($errors['password'] !== ''): ?>
                        <div class="alert alert-error" role="alert"><?php echo e($errors['password']); ?></div>
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
            </div>

            <?php elseif ($section === 'mfa'): ?>
            <!-- ============ Two-factor ============ -->
            <div class="adm-panel">
                <div class="adm-panel-head">
                    <div>
                        <h2>Two-factor sign-in</h2>
                    </div>
                    <div class="adm-panel-head-actions">
                        <?php if ($mfaEnabled): ?>
                            <span class="adm-badge adm-badge-active"><span class="adm-badge-dot"></span>On</span>
                        <?php else: ?>
                            <span class="adm-badge adm-badge-dismissed"><span class="adm-badge-dot"></span>Off</span>
                        <?php endif; ?>
                    </div>
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
                            <div class="field">
                                <label class="field-label" for="mfa_code">Verification code</label>
                                <input class="field-in adm-code" type="text" id="mfa_code" name="code"
                                       inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                                       pattern="[0-9]{6}" placeholder="000000" required>
                            </div>
                            <div class="adm-actions-row">
                                <button type="submit" class="btn">Confirm change</button>
                                <a class="btn btn-outline" href="settings.php?section=mfa">Cancel</a>
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
                                <button type="submit" class="btn<?php echo $mfaEnabled ? ' btn-danger' : ''; ?>">
                                    <?php echo $mfaEnabled ? 'Turn two-factor OFF' : 'Turn two-factor ON'; ?>
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </main>
</div>
<script src="<?php echo e(admin_asset_url('../admin_nav.js')); ?>"></script>
</body>
</html>
