<?php
// ============================================================
// login.php — Unified Authentication + Email Verification + MFA
// + Password Reset
// This single file handles EVERYTHING authentication related:
//   - Login:         email/phone + password (password_verify)
//   - Create Account: first/middle/last name, email, 11-digit
//                     mobile (09123456789), DOB, password
//   - Email Verify:  a simulated email verification code that
//                    must be entered before a new account is
//                    confirmed (the real mailer plugs in later)
//   - MFA:           users who enabled it need a one-time code
//                    after their password (two-factor login)
//   - Forgot/Reset:  request a reset code for a lost password,
//                    then enter the code + a new password
// The correct flow is chosen by the hidden "mode" field each
// form posts, and the panels slide between each other in pure
// CSS (no page reload) via the JS view switcher below.
// ============================================================

// --- 1. Harden the session cookie BEFORE starting the session --
// security.php provides session_harden() (HttpOnly + SameSite=None
// cookie on localhost/HTTPS so it survives mobile-preview iframes,
// strict session IDs) plus the CSRF helpers used below.
require_once __DIR__ . '/../include/security.php';
session_harden(); // must run before session_start()

// --- 2. Start (or resume) the PHP session ----------------------
// Holds the logged-in state, pending verification codes, MFA
// challenge data, and the CSRF token.
session_start();

// Already logged in? Skip the forms and go straight to the dashboard.
if (isset($_SESSION['user_id'])) {
    header('Location: ' . sid_append('dashboard.php'));
    exit; // Stop executing the rest of this file
}

// --- 3. Connect to the database --------------------------------
// db.php creates the shared $pdo connection object.
require_once __DIR__ . '/../include/db.php';

// mailer.php loads .env and provides sendVerificationEmail().
require_once __DIR__ . '/../include/mailer.php';

// --- 4. Page state ---------------------------------------------
// $errors:       field-name => message for every failed check
// $oldInput:     what the user typed, to re-populate on error
// $initialPanel: which of the three panels the page opens on
//                ('login' | 'register' | 'verify')
$errors = [];

$oldInput = [
    'identifier'        => '',  // login field (email OR phone)
    'first_name'        => '',  // register field
    'middle_name'       => '',  // register field
    'last_name'         => '',  // register field
    'email'             => '',  // register field
    'phone'             => '',  // register field (11-digit mobile)
    'date_of_birth'     => '',  // register field
    'verify_code'       => '',  // email / MFA code field
    'forgot_identifier' => '',  // forgot-password field (email OR phone)
    'reset_code'        => '',  // password-reset code field
];

// login.php?registered=1 is set right after a successful signup.
$justRegistered = isset($_GET['registered']);
// login.php?reset=done is set right after a successful password reset.
$justReset = isset($_GET['reset']) && $_GET['reset'] === 'done';

// login.php?deleted=1 comes back from the Delete Account flow, so the
// user who just closed their account gets a confirmation instead of a
// silent bounce to a blank login form.
$justDeleted = isset($_GET['deleted']) && $_GET['deleted'] === '1';

// login.php?cancel_verify=1 — explicit escape from the Verify panel.
// Clears any pending email-verification / MFA challenge so a hard
// refresh of a stale ?verify=1 URL returns to the login page instead.
if (isset($_GET['cancel_verify'])) {
    unset($_SESSION['pending_verify']);
    unset($_SESSION['mfa']);
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 5. Resolve which panel the page should open on -------------
// Priority: MFA challenge > email verification > password reset
// > forgot request > register > login.
$initialPanel = 'login';
if (isset($_SESSION['mfa'])) {
    $initialPanel = 'verify';                       // MFA code entry
} elseif (isset($_GET['verify']) && isset($_SESSION['pending_verify'])) {
    $initialPanel = 'verify';                       // emailed-code entry
} elseif (isset($_GET['reset']) && isset($_SESSION['pending_reset'])) {
    $initialPanel = 'reset';                        // password reset entry
} elseif (isset($_GET['forgot'])) {
    $initialPanel = 'forgot';                       // forgot-password request
} elseif (isset($_GET['panel']) && $_GET['panel'] === 'register') {
    $initialPanel = 'register';                     // deep link to signup
}

// --- 6. Track a successful login as a device -------------------
// Called after any full login (normal or MFA). Inserts one row
// per session into user_devices so the dashboard's Device Login
// settings can list and revoke active sessions.
function trackDevice(PDO $pdo, int $customUserId): void
{
    // Parse a friendly name out of the User-Agent header.
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $browser = 'Unknown';
    if (stripos($ua, 'edg/') !== false) {          // Edge before Chrome (it contains "chrome")
        $browser = 'Edge';
    } elseif (stripos($ua, 'firefox/') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($ua, 'chrome/') !== false) {
        $browser = 'Chrome';
    } elseif (stripos($ua, 'safari/') !== false) {
        $browser = 'Safari';
    }
    $platform = preg_match('/android|iphone|ipad|mobile/i', $ua) ? 'Mobile' : 'Desktop';
    $deviceName = $browser . ' / ' . $platform;

    // The visitor's IP address.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Upsert: one row per session id (re-login refreshes the row).
    $stmt = $pdo->prepare(
        'INSERT INTO user_devices (user_id, device_name, ip_address, session_id, last_login, is_active)
         VALUES (:uid, :name, :ip, :sid, NOW(), 1)
         ON DUPLICATE KEY UPDATE
            device_name = :name, ip_address = :ip, last_login = NOW(), is_active = 1'
    );
    $stmt->execute([
        ':uid'  => $customUserId,
        ':name' => $deviceName,
        ':ip'   => $ip,
        ':sid'  => session_id(),
    ]);
}

// --- 6b. One-time-code policy -----------------------------------
// Shared by the MFA challenge, the registration code and the password
// reset code, because all three travel by e-mail and are re-sent from
// the same style of button. Three limits apply:
//   CODE_TTL             -> how long ONE emailed code stays valid. Kept
//                           short because a 6-digit code is often all
//                           that stands between a leaked password and
//                           the account, and a short life leaves little
//                           room for guessing.
//   CODE_RESEND_COOLDOWN -> how long after a send before another one
//                           is allowed (stops mailbox flooding), and
//   CODE_TOTAL_LIFETIME  -> how long a challenge may exist AT ALL.
// The last one matters because extending the expiry on every resend
// would let a single code window be held open forever.
const CODE_TTL             = 120;   // seconds an emailed code is valid (2 min)
const CODE_RESEND_COOLDOWN = 120;   // seconds before another code may be sent (2 min)
const CODE_TOTAL_LIFETIME  = 1200;  // seconds a whole challenge may live (20 min)

// --- 7. Handle the form submission ------------------------------
// Only runs when the browser sends a POST request to this page.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Which form was submitted? Each form carries a hidden input.
    $mode = $_POST['mode'] ?? 'login';

    // A failed request must re-open the panel it came from, so
    // remember it BEFORE any processing (even CSRF rejections).
    if ($mode === 'register') {
        $initialPanel = 'register';
    } elseif ($mode === 'verify') {
        $initialPanel = 'verify';
    } elseif ($mode === 'forgot') {
        $initialPanel = 'forgot';
    } elseif ($mode === 'reset') {
        $initialPanel = 'reset';
    } else {
        $initialPanel = 'login';
    }

    // ==== CSRF check: every POST must carry a valid token ========
    // The token is stored in the session and embedded in each form
    // as a hidden field; a forged cross-site request cannot know it.
    if (!csrf_check()) {
        $errors['form'] = 'Your session expired or the form token is invalid. Please try again.';
    }

    // ==== 7a. Email / MFA code verification mode =================
    elseif ($mode === 'verify') {

        // ---- Resend: issue a fresh code for the active context ---
        // Routed by the hidden verify_action field, never by the
        // Resend button's name: busy.js disables the clicked submit
        // button inside the submit event, and browsers omit a disabled
        // control from the POST body, so name-based routing would skip
        // this branch entirely.
        if (($_POST['verify_action'] ?? '') === 'resend' || isset($_POST['resend'])) {
            // Which challenge is this for? One button serves the MFA
            // challenge and the registration code, so both get the same
            // policy: a cooldown between sends, and a hard cap on how
            // long the challenge may live in total.
            $ctx = isset($_SESSION['mfa'])
                ? 'mfa'
                : (isset($_SESSION['pending_verify']) ? 'verify' : null);

            if ($ctx === null) {
                $errors['form'] = 'No verification request is pending.';
            } else {
                // Work on the session array directly (by reference) so the
                // same code below can serve both contexts.
                $challenge = &$_SESSION[$ctx === 'mfa' ? 'mfa' : 'pending_verify'];

                $wait      = (int) ($challenge['resend_available_at'] ?? 0) - time();
                $expiresAt = (int) ($challenge['started_at'] ?? time()) + CODE_TOTAL_LIFETIME;

                if ($expiresAt <= time()) {
                    // The challenge is fully spent: no further codes. The
                    // user has to sign in again, which re-checks the
                    // password instead of leaning on this challenge.
                    $errors['form'] = 'This verification request has expired. Please sign in again.';
                } elseif ($wait > 0) {
                    // Too soon: refuse to send, and say how long to wait.
                    $errors['form'] = 'Please wait ' . $wait
                        . ' more second(s) before requesting another code.';
                } else {
                    $code = (string) random_int(100000, 999999);
                    $challenge['code'] = $code;
                    // This code gets a fresh CODE_TTL, but never past
                    // the cap on the challenge as a whole.
                    $challenge['expires'] = min(time() + CODE_TTL, $expiresAt);
                    $challenge['resend_available_at'] = time() + CODE_RESEND_COOLDOWN;

                    if ($ctx === 'mfa') {
                        $challenge['emailed'] = sendMfaEmail($challenge['email'] ?? '', $code);
                    } else {
                        $challenge['emailed'] = sendVerificationEmail($challenge['email'] ?? '', $code);
                        // Registration codes also live on the user row, so
                        // keep that copy in step with the session one.
                        $stmt = $pdo->prepare('UPDATE users SET verification_code = :code WHERE email = :email');
                        $stmt->execute([':code' => $code, ':email' => $challenge['email'] ?? '']);
                    }
                }
                unset($challenge);
            }
        }

        // ---- Check the submitted code ----------------------------
        else {
            $code = trim($_POST['verify_code'] ?? '');
            $oldInput['verify_code'] = $code;

            if ($code === '') {                       // Must not be empty
                $errors['verify_code'] = 'Please enter the verification code.';
            } elseif (isset($_SESSION['mfa'])) {
                // ---- MFA challenge passed? -----------------------
                $mfa = $_SESSION['mfa'];
                if ($mfa['expires'] < time()) {
                    $errors['form'] = 'This code has expired. Please resend a new one.';
                } elseif (!hash_equals($mfa['code'], $code)) {
                    $errors['form'] = 'Invalid verification code.';
                } else {
                    // Code OK -> complete the login for this user.
                    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
                    $stmt->execute([':id' => $mfa['user_id']]);
                    $mfaUser = $stmt->fetch();
                    unset($_SESSION['mfa']);          // Challenge consumed

                    if (!$mfaUser) {
                        $errors['form'] = 'Account not found.';
                    } else {
                        // Fresh session ID (fixation protection) + login.
                        session_regenerate_id(true);
                        $_SESSION['user_id']        = (int) $mfaUser['id'];
                        $_SESSION['full_name']      = $mfaUser['full_name'];
                        $_SESSION['email']          = $mfaUser['email'];
                        $_SESSION['user_id_custom'] = (int) $mfaUser['user_id'];

                        trackDevice($pdo, (int) $mfaUser['user_id']);
                        header('Location: ' . sid_append('dashboard.php'));
                        exit;
                    }
                }
            } elseif (isset($_SESSION['pending_verify'])) {
                // ---- Registration verification passed? -----------
                $email = $_SESSION['pending_verify']['email'];
                $stmt  = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
                $stmt->execute([':email' => $email]);
                $vUser = $stmt->fetch();

                $expired = ($_SESSION['pending_verify']['expires'] ?? 0) < time();
                if ($expired) {
                    $errors['form'] = 'This code has expired. Please resend a new one.';
                } elseif (!$vUser || $vUser['verification_code'] === null
                    || !hash_equals((string) $vUser['verification_code'], $code)) {
                    $errors['form'] = 'Invalid verification code.';
                } else {
                    // Mark the account verified and clear the code.
                    $stmt = $pdo->prepare('UPDATE users SET is_verified = 1, verification_code = NULL WHERE id = :id');
                    $stmt->execute([':id' => $vUser['id']]);
                    unset($_SESSION['pending_verify']);
                    header('Location: ' . sid_append('login.php?registered=1'));
                    exit;
                }
            } else {
                $errors['form'] = 'No verification request is pending.';
            }
        }
    }

    // ==== 7b. CREATE ACCOUNT mode ================================
    elseif ($mode === 'register') {

        // --- Collect every submitted field ----------------------
        // trim() strips accidental leading/trailing whitespace.
        // The full name is collected as THREE separate fields
        // (first / middle / last); they are recombined into the
        // single full_name column the rest of the app still reads.
        $firstName  = trim($_POST['first_name'] ?? '');
        $middleName = trim($_POST['middle_name'] ?? '');
        $lastName   = trim($_POST['last_name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $phone       = preg_replace('/\D/', '', trim($_POST['phone'] ?? '')); // digits only
        $dateOfBirth = trim($_POST['date_of_birth'] ?? '');
        $password       = $_POST['password'] ?? ''; // passwords are NOT trimmed
        $confirmPassword = $_POST['confirm_password'] ?? ''; // never stored

        // Remember these values so the form re-populates on error.
        $oldInput['first_name']     = $firstName;
        $oldInput['middle_name']    = $middleName;
        $oldInput['last_name']      = $lastName;
        $oldInput['email']          = $email;
        $oldInput['phone']          = $phone;
        $oldInput['date_of_birth']  = $dateOfBirth;

        // Combined display name for the users.full_name column.
        // Collapses repeated spaces when the middle name is blank.
        $fullName = trim(preg_replace('/\s+/', ' ', $firstName . ' ' . $middleName . ' ' . $lastName));

        // --- Validate First Name --------------------------------
        // Names may contain letters only — digits are rejected
        // (spaces, hyphens, apostrophes and dots are allowed, e.g.
        // "Dela Cruz", "O'Brien", "Smith Jr.").
        if ($firstName === '') {                       // Field must not be empty
            $errors['first_name'] = 'First name is required.';
        } elseif (mb_strlen($firstName) < 2) {
            $errors['first_name'] = 'First name must be at least 2 characters long.';
        } elseif (mb_strlen($firstName) > 50) {
            $errors['first_name'] = 'First name must be 50 characters or fewer.';
        } elseif (preg_match('/\d/', $firstName)) {
            $errors['first_name'] = 'First name cannot contain numbers.';
        } elseif (!preg_match('/^[\p{L}\p{M}]+(?:[\s\'\.\-][\p{L}\p{M}]+)*$/u', $firstName)) {
            $errors['first_name'] = 'First name can only contain letters.';
        }

        // --- Validate Middle Name (optional) --------------------
        if ($middleName !== '') {
            if (mb_strlen($middleName) > 50) {
                $errors['middle_name'] = 'Middle name must be 50 characters or fewer.';
            } elseif (preg_match('/\d/', $middleName)) {
                $errors['middle_name'] = 'Middle name cannot contain numbers.';
            } elseif (!preg_match('/^[\p{L}\p{M}]+(?:[\s\'\.\-][\p{L}\p{M}]+)*$/u', $middleName)) {
                $errors['middle_name'] = 'Middle name can only contain letters.';
            }
        }

        // --- Validate Last Name --------------------------------
        if ($lastName === '') {                        // Field must not be empty
            $errors['last_name'] = 'Last name is required.';
        } elseif (mb_strlen($lastName) < 2) {
            $errors['last_name'] = 'Last name must be at least 2 characters long.';
        } elseif (mb_strlen($lastName) > 50) {
            $errors['last_name'] = 'Last name must be 50 characters or fewer.';
        } elseif (preg_match('/\d/', $lastName)) {
            $errors['last_name'] = 'Last name cannot contain numbers.';
        } elseif (!preg_match('/^[\p{L}\p{M}]+(?:[\s\'\.\-][\p{L}\p{M}]+)*$/u', $lastName)) {
            $errors['last_name'] = 'Last name can only contain letters.';
        }

        // The three parts are recombined into users.full_name, which
        // is a 100-character column — keep the total within it.
        if (mb_strlen($fullName) > 100) {
            $errors['form'] = 'Full name must be 100 characters or fewer in total.';
        }

        // --- Validate Email -------------------------------------
        if ($email === '') {                          // Field must not be empty
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { // Built-in format check
            $errors['email'] = 'Please enter a valid email address.';
        } elseif (strlen($email) > 255) {             // Matches the DB column limit
            $errors['email'] = 'Email must be 255 characters or fewer.';
        }

// --- Validate Phone Number ------------------------------
        // A Philippine mobile number: exactly 11 digits starting
        // with 09 (e.g. 09123456789). Letters and symbols are
        // rejected; the field was already stripped to digits above.
        if ($phone === '') {                          // Field must not be empty
            $errors['phone'] = 'Mobile number is required.';
        } elseif (!preg_match('/^09\d{9}$/', $phone)) {
            $errors['phone'] = 'Enter a valid 11-digit mobile number starting with 09 (e.g. 09123456789).';
        }

        // --- Validate Date of Birth + Age (18+ rule) ------------
        if ($dateOfBirth === '') {                    // Field must not be empty
            $errors['date_of_birth'] = 'Date of birth is required.';
        } else {
            $birthDate  = DateTime::createFromFormat('Y-m-d', $dateOfBirth);
            $dateErrors = DateTime::getLastErrors();

            if ($birthDate === false
                || ($dateErrors !== false
                    && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
                $errors['date_of_birth'] = 'Please enter a valid date of birth.';
            } elseif ($birthDate > new DateTime('today')) { // No future birthdays
                $errors['date_of_birth'] = 'Date of birth cannot be in the future.';
            } elseif ($birthDate->diff(new DateTime('today'))->y < 18) { // 18+ rule
                $errors['date_of_birth'] = 'You must be at least 18 years old to register.';
            }
        }

        // --- Validate Password ----------------------------------
        if ($password === '') {                       // Field must not be empty
            $errors['password'] = 'Password is required.';
        } elseif (strlen($password) < 8) {            // Minimum 8 characters
            $errors['password'] = 'Password must be at least 8 characters long.';
        } elseif (!preg_match('/[A-Z]/', $password)) { // At least one uppercase letter
            $errors['password'] = 'Password must contain at least one uppercase letter.';
        } elseif (!preg_match('/[a-z]/', $password)) { // At least one lowercase letter
            $errors['password'] = 'Password must contain at least one lowercase letter.';
        } elseif (!preg_match('/[!@\-]/', $password)) { // At least one special char from ! @ -
            $errors['password'] = 'Password must contain at least one special character (! @ -).';
        } elseif (strlen($password) > 64) {           // bcrypt only uses 72 bytes anyway
            $errors['password'] = 'Password must be 64 characters or fewer.';
        }

        // --- Validate Confirm Password ---------------------------
        if ($password !== $confirmPassword) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }

        // --- Check email / phone are not already registered -----
        if (empty($errors)) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
            $stmt->execute([':email' => $email]);
            if ((int) $stmt->fetchColumn() > 0) {
                $errors['email'] = 'This email is already registered.';
            }

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE phone = :phone');
            $stmt->execute([':phone' => $phone]);
            if ((int) $stmt->fetchColumn() > 0) {
                $errors['phone'] = 'This mobile number is already registered.'; // 1 number = 1 account
            }
        }

        // --- Insert the (unverified) user ------------------------
        if (empty($errors)) {

            // --- Random custom user_id (0 - 500,000, unique) -----
            // Collect the IDs already in use, then keep rolling a
            // random number until one is free. The UNIQUE index in
            // the DB is the final backstop against duplicates.
            $usedIds = array_flip($pdo->query('SELECT user_id FROM users')->fetchAll(PDO::FETCH_COLUMN));
            do {
                $customId = random_int(0, 500000);
            } while (isset($usedIds[$customId]));

            // Hash the password with bcrypt (never store plaintext).
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            // Generate the 6-digit EMAIL code that must be entered
            // before the account is marked verified. It is sent to
            // the email address used for registration (currently just
            // shown on the verification panel — the real mailer plugs
            // in later).
            $verificationCode = (string) random_int(100000, 999999);

            // Insert the row with is_verified = 0 (default) and the
            // pending code; the account is only "finalized" once the
            // user enters this code on the verification panel.
            $stmt = $pdo->prepare(
                'INSERT INTO users (user_id, full_name, email, phone, date_of_birth, password_hash, verification_code)
                 VALUES (:uid, :full_name, :email, :phone, :date_of_birth, :password_hash, :code)'
            );
            $stmt->execute([
                ':uid'           => $customId,
                ':full_name'     => $fullName,
                ':email'         => $email,
                ':phone'         => $phone,
                ':date_of_birth' => $dateOfBirth,
                ':password_hash' => $passwordHash,
                ':code'          => $verificationCode,
            ]);

            // Remember what needs verifying for the next request.
            // The code is emailed to the registration address. If the
            // mailer is unavailable (not configured / SMTP down) the
            // 'emailed' flag stays false and the verification panel
            // falls back to showing the code on screen for testing.
            $_SESSION['pending_verify'] = [
                'email'   => $email,
                'code'    => $verificationCode,
                'expires' => time() + CODE_TTL,       // valid for CODE_TTL seconds
                'started_at' => time(),               // the challenge as a whole is capped
                'resend_available_at' => time() + CODE_RESEND_COOLDOWN,
                'emailed' => sendVerificationEmail($email, $verificationCode),
            ];

            // Off to the verification panel.
            header('Location: ' . sid_append('login.php?verify=1'));
            exit;
        }
    }

    // ==== 7c. FORGOT PASSWORD: request a reset code ==============
    elseif ($mode === 'forgot') {

        // --- Collect the email or phone the user forgot ---------
        $identifier = trim($_POST['forgot_identifier'] ?? '');
        $oldInput['forgot_identifier'] = $identifier;

        if ($identifier === '') {                  // Must not be empty
            $errors['forgot_identifier'] = 'Please enter your email or phone number.';
        } else {
            // Find the account by email OR phone.
            $stmt = $pdo->prepare(
                'SELECT id, full_name, email FROM users WHERE email = :identifier OR phone = :identifier LIMIT 1'
            );
            $stmt->execute([':identifier' => $identifier]);
            $resetUser = $stmt->fetch();

            // Show the SAME message whether or not the account exists,
            // so nobody can probe which emails/phones are registered.
            if (!$resetUser) {
                $errors['form'] = 'If an account exists for that email/phone, a reset code has been sent.';
            } else {
                // ---- Cooldown: one reset code per account per window ----
                // Every submission used to issue a fresh code AND re-start
                // the code window, so the form could be driven in a loop
                // to flood the account holder's inbox. If this session
                // already asked for a code for THIS account moments ago, do
                // not send another one: the code we already gave out is
                // still valid, so nothing is lost.
                $pending    = $_SESSION['pending_reset'] ?? null;
                $sameTarget = $pending
                    && (int) ($pending['user_id'] ?? 0) === (int) $resetUser['id'];
                $wait       = $sameTarget
                    ? ((int) ($pending['resend_available_at'] ?? 0) - time())
                    : 0;

                if ($sameTarget && $wait > 0) {
                    // The remaining seconds are not repeated here: the panel
                    // shows a live countdown next to the button instead.
                    $errors['form'] = 'A reset code was already sent. Use the code you '
                        . 'received, or wait for the countdown below.';
                } else {
                    // Generate the 6-digit reset code (valid CODE_TTL
                    // seconds) and remember who it belongs to in the
                    // session. The code is emailed to the account address
                    // (sendResetEmail); if the mailer can't send, 'emailed'
                    // stays false and the reset panel shows the code as a
                    // dev fallback.
                    $code = (string) random_int(100000, 999999);
                    $_SESSION['pending_reset'] = [
                        'user_id'             => (int) $resetUser['id'],
                        'code'                => $code,
                        'expires'             => time() + CODE_TTL,
                        // Earliest a NEW code may be emailed for this account.
                        'resend_available_at' => time() + CODE_RESEND_COOLDOWN,
                        'emailed'             => sendResetEmail($resetUser['email'], $code),
                    ];

                    // Off to the reset-code entry panel.
                    header('Location: ' . sid_append('login.php?reset=1'));
                    exit;
                }
            }
        }
    }

    // ==== 7d. RESET PASSWORD: verify the emailed code first,
    //         then (and only then) let the user set a new password ===
    elseif ($mode === 'reset') {

        // Which step was submitted is decided by the HIDDEN reset_step
        // field (with the POST's shape as a fallback) — never by a
        // submit button's name. busy.js disables the clicked submit
        // button inside the submit event; the browser then omits that
        // control from the POST body, so name-based routing silently
        // skipped this branch and reloaded the code step (the "blink
        // and ask for the code again" bug).
        $resetStep = $_POST['reset_step'] ?? '';
        $isStepTwo = $resetStep === '2'
            || isset($_POST['complete_reset'])      // pages rendered pre-fix
            || isset($_POST['new_password'])
            || isset($_POST['confirm_password']);

        if ($isStepTwo) {

            // ---- Step 2: code verified -> collect the new password ----
            $newPass     = $_POST['new_password'] ?? '';      // not trimmed
            $confirmPass = $_POST['confirm_password'] ?? '';  // never stored

            if (!isset($_SESSION['pending_reset'])
                || ($_SESSION['pending_reset']['verified'] ?? false) !== true) {
                $errors['form'] = 'Verify your reset code first.';
            } elseif (strlen($newPass) < 8) {              // Same rules as registration
                $errors['new_password'] = 'New password must be at least 8 characters long.';
            } elseif (!preg_match('/[A-Z]/', $newPass)) {
                $errors['new_password'] = 'New password must contain at least one uppercase letter.';
            } elseif (!preg_match('/[a-z]/', $newPass)) {
                $errors['new_password'] = 'New password must contain at least one lowercase letter.';
            } elseif (!preg_match('/[!@\-]/', $newPass)) {
                $errors['new_password'] = 'New password must contain at least one special character (! @ -).';
            } elseif (strlen($newPass) > 64) {
                $errors['new_password'] = 'New password must be 64 characters or fewer.';
            } elseif ($newPass !== $confirmPass) {
                $errors['new_password'] = 'Passwords do not match.';
            } else {
                // All good: hash the new password and store it.
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
                $stmt->execute([':hash' => $hash, ':id' => $_SESSION['pending_reset']['user_id']]);
                unset($_SESSION['pending_reset']);        // reset request consumed

                header('Location: ' . sid_append('login.php?reset=done'));
                exit;
            }
        } else {

            // ---- Step 1: verify the code from the e-mail -------------
            $code = trim($_POST['reset_code'] ?? '');
            $oldInput['reset_code'] = $code;

            if (!isset($_SESSION['pending_reset'])) {
                $errors['form'] = 'No password reset request is pending.';
            } elseif (!empty($_SESSION['pending_reset']['used_at'])) {
                // One-time means one-time: a code that was already accepted
                // is refused even inside its original TTL, so a code that
                // leaked (or a replayed request) is worth nothing.
                $errors['form'] = 'That reset code has already been used. Please request a new one.';
            } elseif ($code === '') {
                $errors['reset_code'] = 'Please enter the reset code.';
            } elseif (($_SESSION['pending_reset']['expires'] ?? 0) < time()) {
                $errors['form'] = 'This code has expired. Please request a new one.';
            } elseif (!hash_equals((string) $_SESSION['pending_reset']['code'], $code)) {
                $errors['form'] = 'Invalid reset code.';
            } else {
                // Code checks out: unlock the new-password step and CONSUME
                // the code in the same breath — record that it was used and
                // drop the value, so it can never be accepted a second time.
                $_SESSION['pending_reset']['verified'] = true;
                $_SESSION['pending_reset']['used_at']  = time();
                unset($_SESSION['pending_reset']['code']);
                header('Location: ' . sid_append('login.php?reset=1'));
                exit;
            }
        }
    }

    // ==== 7e. LOGIN mode =========================================
    else {

        // Failed attempts are counted SERVER-side now: security.php
        // keeps one counter per account and one per IP in the
        // login_attempts table, and adds an increasing (but capped)
        // wait between attempts — see the throttle gate below.
        //
        // $_SESSION['login_failures'] is the key the OLD session-based
        // lockout used. That lockout was removed because an attacker
        // could shake it off simply by dropping the cookie, while a
        // legitimate user sharing a device could be locked out of
        // their OWN account by somebody else's guessing. The key is
        // still cleared here so anyone who was mid-block when it was
        // removed is released immediately.
        unset($_SESSION['login_failures']);

        // --- Collect the submitted fields ------------------------
        $identifier = trim($_POST['identifier'] ?? '');
        $password   = $_POST['password'] ?? '';
        $oldInput['identifier'] = $identifier;

        // --- Basic "not empty" checks ----------------------------
        if ($identifier === '') {
            $errors['identifier'] = 'Please enter your email or phone number.';
        }
        if ($password === '') {
            $errors['password'] = 'Please enter your password.';
        }

        // --- Throttle gate: is this caller still waiting? --------
        // Asked BEFORE the password is looked at, so a brute-force
        // loop cannot keep guessing while it waits — it is simply
        // told to come back later. Counted against the typed
        // identifier AND this IP (see security.php): the account key
        // stops one attacker grinding one account, the IP key stops
        // one source spraying many different accounts.
        //
        // Only worth asking once the form itself is complete: an
        // empty identifier has already failed the check above and
        // never reaches a password comparison, so there would be
        // nothing to key the counter on.
        if ($identifier !== '') {
            $loginWait = login_throttle_wait($pdo, $identifier);

            if ($loginWait > 0) {
                // Once the wait is a minute or more, seconds read as
                // noise ("please wait 160 seconds"), so round up to
                // whole minutes instead.
                if ($loginWait >= 60) {
                    $minutes  = (int) ceil($loginWait / 60);
                    $waitText = $minutes . ' minute' . ($minutes === 1 ? '' : 's');
                } else {
                    $waitText = $loginWait . ' second' . ($loginWait === 1 ? '' : 's');
                }

                // The wording is the same whether or not the account
                // exists, so a throttled guess cannot be used to find
                // out which addresses are real.
                $errors['form'] = 'Too many failed sign-in attempts. Please wait '
                    . $waitText . ' before trying again.';
            }
        }

        // --- Look up the user and verify the password ------------
        if (empty($errors)) {

            // Prepared statement: find ONE user whose email OR phone
            // matches what was typed. Placeholder prevents SQL injection.
            $stmt = $pdo->prepare(
                'SELECT id, user_id, full_name, email, phone, date_of_birth,
                        password_hash, is_verified, mfa_enabled
                 FROM users
                 WHERE email = :identifier OR phone = :identifier
                 LIMIT 1'
            );
            $stmt->execute([':identifier' => $identifier]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {

                // The password was RIGHT: forget the failed-attempt
                // streak for both counters before anything else.
                // Every branch below — unverified account, MFA,
                // normal login — has already proved the password, so
                // none of them should leave the user sitting in a
                // backoff.
                login_throttle_clear($pdo, $identifier);

                // ---- Account must be email-verified first -------
                if ((int) $user['is_verified'] !== 1) {
                    // Issue a fresh code and email it to the account
                    // address; the user confirms it on the verify panel.
                    $code = (string) random_int(100000, 999999);
                    $stmt = $pdo->prepare('UPDATE users SET verification_code = :code WHERE id = :id');
                    $stmt->execute([':code' => $code, ':id' => $user['id']]);
                    $_SESSION['pending_verify'] = [
                        'email'   => $user['email'],
                        'code'    => $code,
                        'expires' => time() + CODE_TTL,  // valid for CODE_TTL seconds
                        'started_at' => time(),        // the challenge as a whole is capped
                        'resend_available_at' => time() + CODE_RESEND_COOLDOWN,
                        'emailed' => sendVerificationEmail($user['email'], $code),
                    ];
                    header('Location: ' . sid_append('login.php?verify=1'));
                    exit;
                }

                // ---- MFA: require a one-time code before login --
                // The code is E-MAILED to the address already registered
                // on the account (this stack has no SMS provider), so the
                // second factor travels a channel the user controls.
                // 'emailed' records whether SMTP accepted it: when it did
                // not, the panel shows the code instead of locking the
                // user out of their own account.
                if ((int) $user['mfa_enabled'] === 1) {
                    $code = (string) random_int(100000, 999999);
                    $_SESSION['mfa'] = [
                        'user_id' => (int) $user['id'],
                        'email'   => $user['email'],  // where the code is sent
                        'code'    => $code,
                        'expires' => time() + CODE_TTL, // valid for CODE_TTL seconds
                        'started_at' => time(),       // the challenge as a whole is capped
                        'resend_available_at' => time() + CODE_RESEND_COOLDOWN,
                        'emailed' => sendMfaEmail($user['email'], $code),
                    ];
                    header('Location: ' . sid_append('login.php?mfa=1'));
                    exit;
                }

                // ---- Normal login --------------------------------
                // Rotate the CSRF token on privilege change.
                unset($_SESSION['csrf_token']);

                // Fresh session ID to prevent session fixation.
                session_regenerate_id(true);

                // Store the logged-in user's identity in the session.
                $_SESSION['user_id']        = (int) $user['id'];     // internal id
                $_SESSION['user_id_custom'] = (int) $user['user_id']; // 0-500,000 id
                $_SESSION['full_name']      = $user['full_name'];
                $_SESSION['email']          = $user['email'];

                // Record this login as a device (Device Login list).
                trackDevice($pdo, (int) $user['user_id']);

                header('Location: ' . sid_append('dashboard.php'));
                exit;
            }

            // Wrong credentials. Record the failure against both
            // counters FIRST, then show ONE generic message so we
            // never reveal whether the account exists.
            //
            // Only a failure that reached a real password comparison
            // is counted: an attempt the throttle turned away above
            // never gets this far, which is what stops an attacker
            // from renewing somebody else's backoff forever.
            login_throttle_record_failure($pdo, $identifier);
            $errors['form'] = 'Invalid email/phone or password.';
        }
    }
}

// --- 8. Panel helper strings for the shared header ---------------
// The title/subtitle/footer change with the active panel; these are
// computed server-side so the correct text shows even without JS.
$mfaMode = isset($_SESSION['mfa']);
$panelTitle    = 'Login';
$panelSubtitle = 'Sign in with your email or phone number';
$footerQuestion = 'No account yet?';
$footerLink     = 'Create one here';
// The verify and forgot panels each carry their own "Back to login"
// control, so the shared footer link would be a duplicate — hide the
// whole footer while either of those panels is active.
$hideFooter     = false;
if ($initialPanel === 'register') {
    $panelTitle    = 'Create Account';
    $panelSubtitle = 'Join islaFIND — you must be 18 or older.';
    $footerQuestion = 'Already have an account?';
    $footerLink     = 'Log in';
} elseif ($initialPanel === 'verify') {
    $panelTitle    = $mfaMode ? 'Two-Factor Authentication' : 'Verify your email';
    $panelSubtitle = $mfaMode
        ? 'Enter the one-time code we emailed to your registered address'
        : 'Enter the code we emailed to you to confirm your account';
    // No footer: the verify panel's own "Back to login" link already
    // clears the pending verification and returns to the login panel.
    $footerQuestion = '';
    $footerLink     = '';
    $hideFooter     = true;
} elseif ($initialPanel === 'forgot') {
    $panelTitle    = 'Forgot Password';
    $panelSubtitle = 'Enter your email or phone and we will send a reset code.';
    // No footer: the panel's own "Back to Login" button is enough.
    $footerQuestion = '';
    $footerLink     = '';
    $hideFooter     = true;
} elseif ($initialPanel === 'reset') {
    // Two-step reset: the code is verified FIRST, and only after it
    // passes does the new-password form appear.
    $resetVerified = isset($_SESSION['pending_reset']['verified'])
                     && $_SESSION['pending_reset']['verified'] === true;
    $panelTitle    = 'Reset Password';
    $panelSubtitle = $resetVerified
        ? 'Code verified — enter your new password.'
        : 'Enter the 6-digit code we emailed to you.';
    // The reset panel keeps its own "Back to Login" button, so this
    // footer link is NOT redundant: it takes a new visitor straight
    // to registration.
    $footerQuestion = 'New to islaFIND?';
    $footerLink     = 'Create an account';
}
// --- Code-request countdowns ------------------------------------
// The server is what actually enforces the one-code-per-window rule;
// these two values only expose the deadline so the panels can count it
// down and keep their request/resend button disabled until it passes.
// 0 means "a code may be requested right now".
$verifyWait = 0;                       // verify panel (MFA / registration)
foreach (['mfa', 'pending_verify'] as $ctxKey) {
    if (isset($_SESSION[$ctxKey]['resend_available_at'])) {
        $verifyWait = max(0, (int) $_SESSION[$ctxKey]['resend_available_at'] - time());
        break;
    }
}
$forgotWait = max(0, (int) ($_SESSION['pending_reset']['resend_available_at'] ?? 0) - time());

// "1:59" — the value the script in this page re-renders every second.
$mmss = static function (int $seconds): string {
    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
};

// The track's slide class, mirroring $initialPanel.
$trackClass = $initialPanel === 'register' ? ' show-register'
            : ($initialPanel === 'verify'  ? ' show-verify'
            : ($initialPanel === 'forgot'  ? ' show-forgot'
            : ($initialPanel === 'reset'   ? ' show-reset' : '')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php
// Shared metadata (title, description, favicon, manifest, social
// preview) + style.css and busy.js live in one partial so every
// page ships the same head.
$headTitle = 'Login / Sign Up';
$headDesc  = 'Log in to islaFIND or create an account to list your skill or business on Bantayan Island.';
include __DIR__ . '/../include/head_meta.php';
?>
    <!-- Strength meter for the password fields on this page
         (sign-up + reset). Purely a hint: the server still owns
         the real password policy. -->
    <script src="password_strength.js"></script>
</head>
<body>
    <section class="auth-section">
        <div class="auth-card">

            <!-- Shared app logo (stays still while panels slide) -->
            <img src="img/isla_logo.svg" alt="islaFIND logo" class="auth-logo">

            <!-- Heading + subtitle; text is swapped by the JS switcher -->
            <h2 id="authTitle"><?php echo htmlspecialchars($panelTitle); ?></h2>
            <p class="auth-subtitle" id="authSubtitle"><?php echo htmlspecialchars($panelSubtitle); ?></p>

            <!-- Green success alert, shown right after verification -->
            <?php if ($justRegistered): ?>
                <div class="alert alert-success" role="status">Account verified successfully! Please login.</div>
            <?php endif; ?>

            <!-- Green success alert, shown right after a password reset -->
            <?php if ($justReset): ?>
                <div class="alert alert-success" role="status">Password reset successfully! Please login.</div>
            <?php endif; ?>

            <!-- Neutral confirmation after the user deleted their account -->
            <?php if ($justDeleted): ?>
                <div class="alert alert-info" role="status">Your islaFIND account was deleted. Thanks for being part of the island.</div>
            <?php endif; ?>

            <!-- ====================================================
                 Sliding panel area
                 The track is 500% wide and holds FIVE panels
                 (login, register, verify, forgot, reset). Sliding
                 it left by 20% per step reveals the next panel.
                 ==================================================== -->
            <div class="auth-slider">
                <div class="auth-slider-track<?php echo $trackClass; ?>" id="authSliderTrack">

                    <!-- ============ Panel 1: LOGIN ============ -->
                    <div class="auth-panel" id="loginPanel">

                        <!-- Red alert shown when the credentials are wrong -->
                        <?php if (isset($errors['form']) && $initialPanel === 'login'): ?>
                            <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($errors['form']); ?></div>
                        <?php endif; ?>

                        <form action="login.php" method="POST" novalidate>
                            <input type="hidden" name="mode" value="login">
                            <!-- CSRF token: binds this form to the session -->
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

                            <!-- Email OR Phone (single flexible input) -->
                            <div class="form-group">
                                <div class="input-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"/></svg>
                                    <input type="text" id="identifier" name="identifier" autocomplete="username"
                                           value="<?php echo htmlspecialchars($oldInput['identifier']); ?>"
                                           placeholder="Email or Phone Number" aria-label="Email or phone number">
                                </div>
                                <?php if (isset($errors['identifier'])): ?>
                                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['identifier']); ?></p>
                                <?php endif; ?>
                            </div>

                            <!-- Password (with show/hide eye toggle) -->
                            <div class="form-group">
                                <div class="input-icon input-icon-password">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    <input type="password" id="password" name="password" autocomplete="current-password" placeholder="Password" aria-label="Password">
                                    <button type="button" class="toggle-password" data-target="password" aria-label="Show password" aria-pressed="false">
                                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                    </button>
                                </div>
                                <?php if (isset($errors['password'])): ?>
                                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['password']); ?></p>
                                <?php endif; ?>
                            </div>

                            <button type="submit" class="btn" data-loading-label="Logging in…">Login</button>

                            <!-- Forgot password? slides to the reset request panel -->
                            <p class="auth-forgot"><a href="#" id="forgotLink">Forgot password?</a></p>
                        </form>
                    </div>

                    <!-- ============ Panel 2: CREATE ACCOUNT ============ -->
                    <div class="auth-panel" id="registerPanel">

                        <!-- Red alert for rejected register requests (CSRF) -->
                        <?php if (isset($errors['form']) && $initialPanel === 'register'): ?>
                            <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($errors['form']); ?></div>
                        <?php endif; ?>

                        <form action="login.php" method="POST" novalidate>
                            <input type="hidden" name="mode" value="register">
                            <!-- CSRF token: binds this form to the session -->
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

                            <!-- First Name -->
                            <div class="form-group">
                                <div class="input-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <input type="text" id="first_name" name="first_name" autocomplete="given-name"
                                           value="<?php echo htmlspecialchars($oldInput['first_name']); ?>"
                                           placeholder="First Name" aria-label="First name">
                                </div>
                                <?php if (isset($errors['first_name'])): ?>
                                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['first_name']); ?></p>
                                <?php endif; ?>
                            </div>

                            <!-- Middle Name (optional) -->
                            <div class="form-group">
                                <div class="input-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <input type="text" id="middle_name" name="middle_name" autocomplete="additional-name"
                                           value="<?php echo htmlspecialchars($oldInput['middle_name']); ?>"
                                           placeholder="Middle Name (optional)" aria-label="Middle name">
                                </div>
                                <?php if (isset($errors['middle_name'])): ?>
                                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['middle_name']); ?></p>
                                <?php endif; ?>
                            </div>

                            <!-- Last Name -->
                            <div class="form-group">
                                <div class="input-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <input type="text" id="last_name" name="last_name" autocomplete="family-name"
                                           value="<?php echo htmlspecialchars($oldInput['last_name']); ?>"
                                           placeholder="Last Name" aria-label="Last name">
                                </div>
                                <?php if (isset($errors['last_name'])): ?>
                                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['last_name']); ?></p>
                                <?php endif; ?>
                            </div>

                            <!-- Email -->
                            <div class="form-group">
                                <div class="input-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                    <input type="email" id="email" name="email" autocomplete="email"
                                           value="<?php echo htmlspecialchars($oldInput['email']); ?>"
                                           placeholder="Email Address" aria-label="Email address">
                                </div>
                                <?php if (isset($errors['email'])): ?>
                                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['email']); ?></p>
                                <?php endif; ?>
                            </div>

                            <!-- Mobile Number (11 digits, must start 09) -->
                            <div class="form-group">
                                <div class="input-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                    <input type="tel" id="phone" name="phone" inputmode="numeric" maxlength="11" pattern="[0-9]{11}"
                                           autocomplete="tel-national"
                                           value="<?php echo htmlspecialchars($oldInput['phone']); ?>"
                                           placeholder="09*********" aria-label="Mobile number (11 digits)">
                                </div>
                                <?php if (isset($errors['phone'])): ?>
                                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['phone']); ?></p>
                                <?php endif; ?>
                            </div>

                            <!-- Date of Birth (Month / Day / Year dropdowns) -->
                            <?php
                            // Split the saved value (Y-m-d) into its parts so the
                            // three dropdowns re-populate after a validation error.
                            $dobParts = $oldInput['date_of_birth'] !== ''
                                ? explode('-', $oldInput['date_of_birth'])
                                : [];
                            $dobSelY = $dobParts[0] ?? '';
                            $dobSelM = (int) ($dobParts[1] ?? 0);
                            $dobSelD = (int) ($dobParts[2] ?? 0);
                            $dobMinY = (int) date('Y') - 100;   // 100 years back
                            $dobMaxY = (int) date('Y');          // no future years
                            ?>
                            <div class="form-group">
                                <div class="dob-row">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    <select id="dobMonth" class="dob-select" autocomplete="bday-month" aria-label="Birth month">
                                        <option value=""<?php echo $dobSelM === 0 ? ' selected' : ''; ?>>Month</option>
                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                            <option value="<?php echo $m; ?>"<?php echo $dobSelM === $m ? ' selected' : ''; ?>><?php echo $m; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <span class="dob-slash" aria-hidden="true">/</span>
                                    <select id="dobDay" class="dob-select" autocomplete="bday-day" aria-label="Birth day">
                                        <option value=""<?php echo $dobSelD === 0 ? ' selected' : ''; ?>>Day</option>
                                        <?php for ($d = 1; $d <= 31; $d++): ?>
                                            <option value="<?php echo $d; ?>"<?php echo $dobSelD === $d ? ' selected' : ''; ?>><?php echo $d; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <span class="dob-slash" aria-hidden="true">/</span>
                                    <select id="dobYear" class="dob-select" autocomplete="bday-year" aria-label="Birth year">
                                        <option value=""<?php echo $dobSelY === '' ? ' selected' : ''; ?>>Year</option>
                                        <?php for ($y = $dobMaxY; $y >= $dobMinY; $y--): ?>
                                            <option value="<?php echo $y; ?>"<?php echo $dobSelY === (string) $y ? ' selected' : ''; ?>><?php echo $y; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <input type="hidden" id="date_of_birth" name="date_of_birth"
                                           value="<?php echo htmlspecialchars($oldInput['date_of_birth']); ?>">
                                </div>
                                <?php if (isset($errors['date_of_birth'])): ?>
                                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['date_of_birth']); ?></p>
                                <?php endif; ?>
                            </div>

                            <!-- Password -->
                            <div class="form-group">
                                <div class="input-icon input-icon-password">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    <input type="password" id="register_password" name="password" autocomplete="new-password" placeholder="Create a password" aria-label="Password"
                                           data-pw-strength>
                                    <button type="button" class="toggle-password" data-target="register_password" aria-label="Show password" aria-pressed="false">
                                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                    </button>
                                </div>

                                <!-- Live strength meter + compact rule checklist -->
                                <div class="strength-area" id="strengthArea" data-level="0">
                                    <div class="strength-row">
                                        <div class="strength-meter" aria-hidden="true">
                                            <span class="strength-seg"></span>
                                            <span class="strength-seg"></span>
                                            <span class="strength-seg"></span>
                                            <span class="strength-seg"></span>
                                        </div>
                                        <p class="strength-label" id="strengthLabel">Password strength</p>
                                    </div>
                                </div>

                                <ul class="rule-list" id="ruleList">
                                    <li class="rule-item" data-rule="length"><span class="rule-check"></span>8+ chars</li>
                                    <li class="rule-item" data-rule="upper"><span class="rule-check"></span>A-Z</li>
                                    <li class="rule-item" data-rule="lower"><span class="rule-check"></span>a-z</li>
                                    <li class="rule-item" data-rule="special"><span class="rule-check"></span>! @ -</li>
                                </ul>
                                <p class="rule-hint" id="ruleHint" hidden>&#10003; All requirements met</p>

                                <?php if (isset($errors['password'])): ?>
                                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['password']); ?></p>
                                <?php endif; ?>
                            </div>

                            <!-- Confirm Password -->
                            <div class="form-group">
                                <div class="input-icon input-icon-password" id="confirmIcon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" placeholder="Confirm Password" aria-label="Confirm password">
                                    <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show password" aria-pressed="false">
                                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                    </button>
                                </div>
                                <p class="match-status" id="matchStatus" hidden>Passwords do not match</p>

                                <?php if (isset($errors['confirm_password'])): ?>
                                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['confirm_password']); ?></p>
                                <?php endif; ?>
                            </div>

                            <button type="submit" class="btn" data-loading-label="Creating your account…">Register</button>
                        </form>
                    </div>

                    <!-- ============ Panel 3: VERIFY (EMAIL code / MFA) ============ -->
                    <div class="auth-panel" id="verifyPanel">

                        <!-- Red alert for an invalid / expired code -->
                        <?php if (isset($errors['form']) && $initialPanel === 'verify'): ?>
                            <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($errors['form']); ?></div>
                        <?php endif; ?>

                        <!-- SIMULATED EMAIL box: shown only as a dev
                             fallback when the one-time code could not be
                             e-mailed (unconfigured SMTP / send failure).
                             When the mailer delivers the code, nothing is
                             revealed on screen. -->
                        <?php if ($mfaMode && ($_SESSION['mfa']['emailed'] ?? false) !== true): ?>
                            <div class="alert alert-info" role="status">SIMULATED EMAIL — your MFA code could not be emailed and is shown here instead:
                                <strong><?php echo htmlspecialchars($_SESSION['mfa']['email'] ?? ''); ?></strong><br>
                                Code: <strong><?php echo htmlspecialchars($_SESSION['mfa']['code']); ?></strong></div>
                        <?php elseif (isset($_SESSION['pending_verify']['email'])
                                    && ($_SESSION['pending_verify']['emailed'] ?? false) !== true): ?>
                            <div class="alert alert-info" role="status">SIMULATED EMAIL — your verification code could not be emailed and is shown here instead:
                                <strong><?php echo htmlspecialchars($_SESSION['pending_verify']['email']); ?></strong><br>
                                Code: <strong><?php echo htmlspecialchars($_SESSION['pending_verify']['code']); ?></strong></div>
                        <?php endif; ?>

                        <!-- Escape hatch: a hard refresh of a stale ?verify=1
                             URL reopens this panel, so give a clear way back. -->
                        <p class="auth-switch" style="text-align:center;margin-bottom:14px;">
                            <a href="<?php echo htmlspecialchars(sid_append('login.php?cancel_verify=1')); ?>">&#8592; Back to login</a>
                        </p>

                        <!-- The code entry form. Routing uses the hidden verify_action
                             field rather than a button name: busy.js disables
                             the clicked submit button inside the submit event
                             and browsers then drop it from the POST body. -->
                        <form action="login.php" method="POST" novalidate>
                            <input type="hidden" name="mode" value="verify">
                            <input type="hidden" name="verify_action" value="code">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

                            <div class="form-group">
                                <div class="input-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                    <input type="text" id="verify_code" name="verify_code" inputmode="numeric"
                                           maxlength="6" autocomplete="one-time-code"
                                           value="<?php echo htmlspecialchars($oldInput['verify_code']); ?>"
                                           placeholder="6-digit code" aria-label="Verification code">
                                </div>
                                <?php if (isset($errors['verify_code'])): ?>
                                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['verify_code']); ?></p>
                                <?php endif; ?>
                            </div>

                            <button type="submit" class="btn" data-loading-label="Verifying…">Verify</button>
                        </form>

                        <!-- Live countdown to the moment another code may be
                             requested. The rule itself is enforced on the
                             server (CODE_RESEND_COOLDOWN); this just shows the
                             wait instead of rejecting the click. Rendered only
                             when there IS a wait, so a ready button needs no
                             script at all. -->
                        <?php if ($verifyWait > 0): ?>
                            <p class="code-countdown" data-countdown
                               data-available-at="<?php echo time() + $verifyWait; ?>"
                               data-countdown-target="resendCodeBtn"
                               data-ready-text="You can request another code now.">
                                You can request another code in <span data-countdown-value><?php echo htmlspecialchars($mmss($verifyWait)); ?></span>
                            </p>
                        <?php endif; ?>

                        <!-- Separate form for Resend so its intent is carried by
                             the hidden verify_action field, never by the
                             button's name. -->
                        <form action="login.php" method="POST" novalidate>
                            <input type="hidden" name="mode" value="verify">
                            <input type="hidden" name="verify_action" value="resend">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <button type="submit" id="resendCodeBtn" class="btn btn-outline" data-loading-label="Sending…">Resend code</button>
                        </form>
                    </div>

                    <!-- ============ Panel 4: FORGOT PASSWORD ============ -->
                    <div class="auth-panel" id="forgotPanel">

                        <!-- Red alert for unknown accounts / CSRF failures -->
                        <?php if (isset($errors['form']) && $initialPanel === 'forgot'): ?>
                            <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($errors['form']); ?></div>
                        <?php endif; ?>

                        <form action="login.php" method="POST" novalidate>
                            <input type="hidden" name="mode" value="forgot">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

                            <!-- Email OR Phone (single flexible input) -->
                            <div class="form-group">
                                <div class="input-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"/></svg>
                                    <input type="text" id="forgot_identifier" name="forgot_identifier" autocomplete="username"
                                           value="<?php echo htmlspecialchars($oldInput['forgot_identifier']); ?>"
                                           placeholder="Email or Phone Number" aria-label="Email or phone number">
                                </div>
                                <?php if (isset($errors['forgot_identifier'])): ?>
                                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['forgot_identifier']); ?></p>
                                <?php endif; ?>
                            </div>

                            <!-- Same countdown as the verify panel: after a
                                 reset code was sent, the button stays locked
                                 until the cooldown is over. -->
                            <?php if ($forgotWait > 0): ?>
                                <p class="code-countdown" data-countdown
                                   data-available-at="<?php echo time() + $forgotWait; ?>"
                                   data-countdown-target="sendResetBtn"
                                   data-ready-text="You can request another reset code now.">
                                    You can request another reset code in <span data-countdown-value><?php echo htmlspecialchars($mmss($forgotWait)); ?></span>
                                </p>
                            <?php endif; ?>

                            <button type="submit" id="sendResetBtn" class="btn" data-loading-label="Sending code…">Send Reset Code</button>
                            <button type="button" class="btn btn-outline" data-go="login">Back to Login</button>
                        </form>
                    </div>

                    <!-- ============ Panel 5: RESET PASSWORD ============ -->
                    <div class="auth-panel" id="resetPanel">

                        <!-- Two-step reset. STEP 1 (code) shows first;
                             STEP 2 (new password) appears only after
                             the emailed code was verified server-side. -->
                        <?php $resetVerified = isset($_SESSION['pending_reset']['verified'])
                                              && $_SESSION['pending_reset']['verified'] === true; ?>

                        <!-- ===== STEP 1: verify the emailed code ===== -->
                        <div id="resetStepCode"<?php echo $resetVerified ? ' hidden' : ''; ?>>

                            <!-- Red alert for an invalid / expired code -->
                            <?php if (isset($errors['form']) && $initialPanel === 'reset' && !$resetVerified): ?>
                                <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($errors['form']); ?></div>
                            <?php endif; ?>

                            <!-- SIMULATED reset code: shown only as a dev fallback when the
                                 code could not be emailed (unconfigured SMTP /
                                 send failure). When the mailer delivers the
                                 code, nothing is revealed on screen. -->
                            <?php if (!isset($_SESSION['pending_reset']['verified'])
                                      && isset($_SESSION['pending_reset']['code'])
                                      && ($_SESSION['pending_reset']['emailed'] ?? false) !== true): ?>
                                <div class="alert alert-info" role="status">SIMULATED — your password reset code could not be emailed and is shown here instead:
                                    <strong><?php echo htmlspecialchars($_SESSION['pending_reset']['code']); ?></strong></div>
                            <?php endif; ?>

                            <p class="sec-hint" style="margin:0 0 14px 0;text-align:center;">We emailed a 6-digit reset code to your account. Enter it to continue.</p>

                            <form action="login.php" method="POST" novalidate>
                                <input type="hidden" name="mode" value="reset">
                                <input type="hidden" name="reset_step" value="1">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

                                <div class="form-group">
                                    <div class="input-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                        <input type="text" id="reset_code" name="reset_code" inputmode="numeric"
                                               maxlength="6" autocomplete="one-time-code"
                                               value="<?php echo htmlspecialchars($oldInput['reset_code']); ?>"
                                               placeholder="6-digit code" aria-label="Reset code">
                                    </div>
                                    <?php if (isset($errors['reset_code'])): ?>
                                        <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['reset_code']); ?></p>
                                    <?php endif; ?>
                                </div>

                                <button type="submit" name="verify_reset_code" value="1" class="btn" data-loading-label="Verifying…">Verify Code</button>
                                <button type="button" class="btn btn-outline" data-go="login">Back to Login</button>
                            </form>
                        </div>

                        <!-- ===== STEP 2: choose the new password ===== -->
                        <div id="resetStepPassword"<?php echo $resetVerified ? '' : ' hidden'; ?>>

                            <!-- Red alert for a failed password validation -->
                            <?php if (isset($errors['form']) && $initialPanel === 'reset' && $resetVerified): ?>
                                <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($errors['form']); ?></div>
                            <?php endif; ?>

                            <p class="sec-hint" style="margin:0 0 14px 0;text-align:center;">Code verified! Now choose your new password.</p>

                            <form action="login.php" method="POST" novalidate>
                                <input type="hidden" name="mode" value="reset">
                                <input type="hidden" name="reset_step" value="2">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

                                <!-- New password -->
                                <div class="form-group">
                                    <div class="input-icon input-icon-password">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                        <input type="password" id="reset_new_password" name="new_password" autocomplete="new-password" placeholder="New password (min 8, A-Z, a-z, ! @ -)" aria-label="New password"
                                               data-pw-strength>
                                        <button type="button" class="toggle-password" data-target="reset_new_password" aria-label="Show password" aria-pressed="false">
                                            <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                        </button>
                                    </div>
                                    <?php if (isset($errors['new_password'])): ?>
                                        <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['new_password']); ?></p>
                                    <?php endif; ?>
                                </div>

                                <!-- Confirm new password -->
                                <div class="form-group">
                                    <div class="input-icon input-icon-password">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                        <input type="password" id="reset_confirm_password" name="confirm_password" autocomplete="new-password" placeholder="Confirm new password" aria-label="Confirm new password">
                                        <button type="button" class="toggle-password" data-target="reset_confirm_password" aria-label="Show password" aria-pressed="false">
                                            <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                        </button>
                                    </div>
                                </div>

                                <button type="submit" name="complete_reset" value="1" class="btn" data-loading-label="Resetting…">Reset Password</button>
                                <button type="button" class="btn btn-outline" data-go="login">Back to Login</button>
                            </form>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Shared toggle link; text is swapped by the JS switcher.
                 Hidden on the forgot panel, which has its own back button. -->
            <p class="auth-footer"<?php echo $hideFooter ? ' hidden' : ''; ?>>
                <span id="toggleQuestion"><?php echo htmlspecialchars($footerQuestion); ?></span>
                <a href="#" id="toggleLink" class="toggle-link"><?php echo htmlspecialchars($footerLink); ?></a>
            </p>

        </div>
    </section>

    <script>
        // ============================================================
        // View switcher — slides between Login / Register / Verify /
        // Forgot / Reset
        // ============================================================

        // Grab the sliding track and the shared texts.
        const sliderTrack    = document.getElementById('authSliderTrack');
        const toggleLink     = document.getElementById('toggleLink');
        const toggleQuestion = document.getElementById('toggleQuestion');
        const authTitle      = document.getElementById('authTitle');
        const authSubtitle   = document.getElementById('authSubtitle');
        const authFooter     = document.querySelector('.auth-footer');
        const forgotLink     = document.getElementById('forgotLink');

        // Initial panel comes from PHP so server-side failures land
        // on the correct panel. Values: 'login' | 'register' | 'verify'
        // | 'forgot' | 'reset'
        let panelState = <?php echo json_encode($initialPanel); ?>;

        // Whether the verify panel is showing an MFA challenge (true)
        // or a registration emailed code (false) — affects its heading.
        const mfaMode = <?php echo $mfaMode ? 'true' : 'false'; ?>;

        // The five panels inside the sliding track (for height syncing).
        const loginPanel    = document.getElementById('loginPanel');
        const registerPanel = document.getElementById('registerPanel');
        const verifyPanel   = document.getElementById('verifyPanel');
        const forgotPanel   = document.getElementById('forgotPanel');
        const resetPanel    = document.getElementById('resetPanel');

        // Measure a panel's NATURAL height (flex stretching would make
        // offsetHeight return the track height, so disable it briefly).
        function panelHeight(panel) {
            sliderTrack.style.alignItems = 'flex-start';
            const h = panel.offsetHeight;
            sliderTrack.style.alignItems = '';
            return h;
        }

        // Pin the track (and therefore the card) to the ACTIVE panel's
        // height; the CSS height transition animates the change.
        function syncTrackHeight() {
            const active = panelState === 'register' ? registerPanel
                         : panelState === 'verify'   ? verifyPanel
                         : panelState === 'forgot'   ? forgotPanel
                         : panelState === 'reset'    ? resetPanel
                         : loginPanel;
            sliderTrack.style.height = panelHeight(active) + 'px';
        }

        // Slide the track and update the heading/footer text together.
        function showPanel(panel) {
            panelState = panel;

            // The CSS classes drive the transform (see style.css).
            sliderTrack.classList.toggle('show-register', panel === 'register');
            sliderTrack.classList.toggle('show-verify',   panel === 'verify');
            sliderTrack.classList.toggle('show-forgot',   panel === 'forgot');
            sliderTrack.classList.toggle('show-reset',    panel === 'reset');
            syncTrackHeight();   // Animate the card height to the panel

            if (panel === 'register') {
                authTitle.textContent = 'Create Account';
                authSubtitle.textContent = 'Join islaFIND — you must be 18 or older.';
                toggleQuestion.textContent = 'Already have an account?';
                toggleLink.textContent = 'Log in';
            } else if (panel === 'verify') {
                authTitle.textContent = mfaMode
                    ? 'Two-Factor Authentication'
                    : 'Verify your email';
                authSubtitle.textContent = mfaMode
                    ? 'Enter the one-time code we emailed to your registered address'
                    : 'Enter the code we emailed to you to confirm your account';
                toggleQuestion.textContent = '';
                toggleLink.textContent = '';
            } else if (panel === 'forgot') {
                authTitle.textContent = 'Forgot Password';
                authSubtitle.textContent = 'Enter your email or phone and we will send a reset code.';
                toggleQuestion.textContent = '';
                toggleLink.textContent = '';
            } else if (panel === 'reset') {
                authTitle.textContent = 'Reset Password';
                // The subtitle reflects the two-step flow: the code is
                // verified first, only then does the new-password form
                // (and its subtitle) show.
                const resetPwStep = document.getElementById('resetStepPassword');
                authSubtitle.textContent = (resetPwStep && !resetPwStep.hidden)
                    ? 'Code verified — enter your new password.'
                    : 'Enter the 6-digit code we emailed to you.';
                toggleQuestion.textContent = 'New to islaFIND?';
                toggleLink.textContent = 'Create an account';
            } else {
                authTitle.textContent = 'Login';
                authSubtitle.textContent = 'Sign in with your email or phone number';
                toggleQuestion.textContent = 'No account yet?';
                toggleLink.textContent = 'Create one here';
            }

            // The verify and forgot panels each have their own
            // "Back to login" control, so the shared footer link is
            // hidden there to avoid a duplicate.
            if (authFooter) {
                authFooter.hidden = (panel === 'verify' || panel === 'forgot');
            }
        }

        if (toggleLink) {
            // One click on the footer link moves to the next sensible panel:
            // login -> register, reset -> register (its footer advertises
            // "Create an account"), and every other panel back to login.
            toggleLink.addEventListener('click', function (event) {
                event.preventDefault();   // Stop the browser jumping to "#"
                if (panelState === 'login' || panelState === 'reset') {
                    showPanel('register');      // login / reset -> register
                } else {
                    showPanel('login');         // register & others -> login
                }
            });
        }

        // "Forgot password?" on the login panel slides to the reset request.
        forgotLink.addEventListener('click', function (event) {
            event.preventDefault();
            showPanel('forgot');
        });

        // In-panel buttons with data-go (Back to Login) slide directly.
        document.querySelectorAll('[data-go]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                showPanel(btn.dataset.go);
            });
        });

        // Re-measure whenever the window resizes or the logo loads.
        window.addEventListener('resize', syncTrackHeight);
        window.addEventListener('load', syncTrackHeight);

        // Sync the DOM with the server-rendered initial state.
        showPanel(panelState);

        // ============================================================
        // Code-request countdowns (verify + forgot panels)
        //
        // PHP writes the deadline into data-available-at; this keeps the
        // "you can request another code in 1:59" text ticking and holds the
        // button disabled until it reaches zero, so nobody has to be told
        // off by the server for clicking too early. The server enforces the
        // same rule regardless — this is only the visible half of
        // CODE_RESEND_COOLDOWN, never the control.
        // ============================================================

        const countdowns = document.querySelectorAll('[data-countdown]');

        function formatWait(totalSeconds) {
            const mins = Math.floor(totalSeconds / 60);
            const secs = totalSeconds % 60;
            return mins + ':' + (secs < 10 ? '0' + secs : String(secs));
        }

        // Returns true while at least one countdown is still running.
        function tickCountdowns() {
            const now = Math.floor(Date.now() / 1000);
            let pending = false;

            countdowns.forEach(function (node) {
                const left   = parseInt(node.getAttribute('data-available-at'), 10) - now;
                const output = node.querySelector('[data-countdown-value]');
                const button = document.getElementById(node.getAttribute('data-countdown-target'));

                if (left > 0) {
                    pending = true;
                    if (output) output.textContent = formatWait(left);
                    if (button) button.disabled = true;
                } else if (!node.classList.contains('is-ready')) {
                    // Time is up: release the button and replace the countdown
                    // with a plain sentence, since a number that no longer
                    // counts anything down only invites confusion.
                    node.classList.add('is-ready');
                    node.textContent = node.getAttribute('data-ready-text')
                        || 'You can request another code now.';
                    if (button) button.disabled = false;
                }
            });

            return pending;
        }

        if (countdowns.length) {
            tickCountdowns();                        // lock the button at once
            const ticker = setInterval(function () {
                if (!tickCountdowns()) clearInterval(ticker);   // stop when done
            }, 1000);
        }

        // ============================================================
        // Password strength meter + checklist (register panel)
        // ============================================================

        const registerPassword = document.getElementById('register_password');
        const strengthArea     = document.getElementById('strengthArea');
        const strengthLabel    = document.getElementById('strengthLabel');
        const strengthLabels   = ['', 'Weak', 'Fair', 'Good', 'Strong'];
        const ruleList = document.getElementById('ruleList');
        const ruleHint = document.getElementById('ruleHint');
        const ruleItems = {
            length:  document.querySelector('.rule-item[data-rule="length"]'),
            upper:   document.querySelector('.rule-item[data-rule="upper"]'),
            lower:   document.querySelector('.rule-item[data-rule="lower"]'),
            special: document.querySelector('.rule-item[data-rule="special"]'),
        };

        function updateStrength() {
            const value = registerPassword.value;
            const checks = {
                length:  value.length >= 8,
                upper:   /[A-Z]/.test(value),
                lower:   /[a-z]/.test(value),
                special: /[!@\-]/.test(value),
            };
            let score = 0;
            for (const key in checks) {
                if (checks[key]) score++;
            }
            strengthArea.dataset.level = score;
            strengthLabel.textContent = score === 0 ? 'Password strength' : strengthLabels[score];
            for (const key in checks) {
                ruleItems[key].classList.toggle('met', checks[key]);
            }
            const allMet = score === 4;
            ruleList.hidden = allMet;
            ruleHint.hidden = !allMet;
            syncTrackHeight();   // checklist collapse changes the panel height
        }
        registerPassword.addEventListener('input', updateStrength);
        updateStrength();

        // ============================================================
        // Confirm password match check (register panel)
        // ============================================================

        const confirmPassword = document.getElementById('confirm_password');
        const matchStatus     = document.getElementById('matchStatus');
        const confirmIcon     = document.getElementById('confirmIcon');

        function updateMatch() {
            const value = confirmPassword.value;
            if (value === '') {
                matchStatus.hidden = true;
                confirmIcon.classList.remove('match-ok', 'match-bad');
                return;
            }
            const ok = value === registerPassword.value;
            matchStatus.hidden = false;
            matchStatus.textContent = ok ? '\u2713 Passwords match' : 'Passwords do not match';
            matchStatus.className = 'match-status ' + (ok ? 'match-ok-text' : 'match-bad-text');
            confirmIcon.classList.toggle('match-ok', ok);
            confirmIcon.classList.toggle('match-bad', !ok);
            syncTrackHeight();
        }
        confirmPassword.addEventListener('input', updateMatch);
        registerPassword.addEventListener('input', updateMatch);
        updateMatch();

        // ============================================================
        // Date of birth: Month / Day / Year dropdowns -> hidden field
        // ============================================================
        // The three dropdowns replace the native browser calendar (which
        // looks different on every device). They write a standard
        // Y-m-d string into the hidden #date_of_birth input, so the
        // server-side 18+ validation keeps working unchanged. The day
        // list is trimmed to the real length of the chosen month
        // (handling leap-year Februaries) so Feb 31 can never happen.

        const dobMonth = document.getElementById('dobMonth');
        const dobDay   = document.getElementById('dobDay');
        const dobYear  = document.getElementById('dobYear');
        const dobInput = document.getElementById('date_of_birth');

        // Real number of days in a month (month is 1-12; day 0 of the
        // NEXT month is the last day of THIS month, so leap years work).
        function daysInMonth(month, year) {
            return new Date(year, month, 0).getDate();
        }

        // Rebuild the day dropdown for the chosen month/year. The
        // currently-selected day is kept when it still fits.
        function rebuildDays() {
            const m = parseInt(dobMonth.value, 10);
            const y = parseInt(dobYear.value, 10);
            if (!m || !y) return;                 // need both to know the length
            const max = daysInMonth(m, y);
            const kept = parseInt(dobDay.value, 10);
            while (dobDay.options.length > 1) {   // keep only the "Day" placeholder
                dobDay.remove(1);
            }
            for (let d = 1; d <= max; d++) {
                const opt = document.createElement('option');
                opt.value = String(d);
                opt.textContent = String(d);
                dobDay.appendChild(opt);
            }
            dobDay.value = kept >= 1 && kept <= max ? String(kept) : '';
            syncDob();
        }

        // Write Y-m-d (zero-padded) into the hidden field whenever a
        // dropdown changes, so the form submits the same format as the
        // old native date input.
        function syncDob() {
            const m = dobMonth.value;
            const d = dobDay.value;
            const y = dobYear.value;
            dobInput.value = (m && d && y)
                ? y + '-' + m.padStart(2, '0') + '-' + d.padStart(2, '0')
                : '';
        }

        dobMonth.addEventListener('change', rebuildDays);
        dobYear.addEventListener('change', rebuildDays);
        dobDay.addEventListener('change', syncDob);
        // Trim the day list once on load if a value was pre-filled
        // (e.g. the form re-rendered after a validation error).
        if (dobMonth.value && dobYear.value) rebuildDays();

        // ============================================================
        // Show / hide password eye toggles (all password fields)
        // ============================================================

        document.querySelectorAll('.toggle-password').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const input = document.getElementById(btn.dataset.target);
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.classList.toggle('showing', show);
                btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            });
        });

        // ============================================================
        // Input guardrails: names = letters only, mobile = 11 digits
        // ============================================================
        // Name fields: block numbers as the user types (mirrors the
        // server-side "cannot contain numbers" rule so they find out
        // instantly instead of after the POST).
        ['first_name', 'middle_name', 'last_name'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', function () {
                    el.value = el.value.replace(/\d+/g, '');
                });
            }
        });

        // Mobile number: digits only, hard cap at 11 characters.
        const mobileInput = document.getElementById('phone');
        if (mobileInput) {
            mobileInput.addEventListener('input', function () {
                mobileInput.value = mobileInput.value.replace(/[^\d]/g, '').slice(0, 11);
            });
        }
    </script>
</body>
</html>
