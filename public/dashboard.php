<?php
// ============================================================
// dashboard.php — User Dashboard (Facebook-style mobile app)
// A protected page with a sticky green header, scrolling
// content, a fixed bottom navigation bar (Home / Messenger), and
// a hamburger in the header that opens the Settings hub.
// The content is ONE sliding track with six panels:
//   1. Home            — search bar + feed area
//   2. Settings menu   — hub listing the sub-sections
//   3. Profile         — avatar, info, picture upload, logout
//   4. islaFIND Profile — the user's provider listings
//   5. Privacy & Sec.  — change password + MFA toggle
//   6. My Jobs         — service contracts where the user is the
//                        provider (accept/decline, complete jobs)
// Tapping the bottom "Settings" nav opens the menu hub; each
// menu option slides to its panel, with back links to return.
// ============================================================

// --- 1. Harden the session cookie, then start the session ------
require_once __DIR__ . '/../include/security.php';
session_harden(); // must run before session_start()
session_start();

// --- 2. Session guard: logged in? ------------------------------
// No user_id in the session -> not logged in -> back to login.
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 3. Database connection + shared category lists ------------
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/categories.php';

// --- 4. Load the user's CURRENT row from the database ----------
// (Not just the session copy, so profile picture, MFA state and
// other columns are always fresh.)
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch();

// Account deleted while logged in? Treat like a logged-out user.
if (!$user) {
    session_unset();
    session_destroy();
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 5. Derived values for display ------------------------------
// Age = whole years between the birth date and today.
$birthDate = new DateTime($user['date_of_birth']);
$age = $birthDate->diff(new DateTime('today'))->y;

// Status messages rendered as colored alert banners.
$messages = [];
$errors   = [];

// --- 6. Handle POST actions (all require a valid CSRF token) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {

    // ==== 6a. Change password -----------------------------------
    // (Logout is handled by the dedicated logout.php; profile
    // picture uploads by upload_profile.php.)
    if (isset($_POST['change_password'])) {
        $current  = $_POST['current_password'] ?? '';
        $newPass  = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        // Must know the CURRENT password first.
        if (!password_verify($current, $user['password_hash'])) {
            $errors['password'] = 'Your current password is incorrect.';
        } elseif (strlen($newPass) < 8) {             // Same rules as registration
            $errors['password'] = 'New password must be at least 8 characters long.';
        } elseif (!preg_match('/[A-Z]/', $newPass)) {
            $errors['password'] = 'New password must contain at least one uppercase letter.';
        } elseif (!preg_match('/[a-z]/', $newPass)) {
            $errors['password'] = 'New password must contain at least one lowercase letter.';
        } elseif (!preg_match('/[!@\-]/', $newPass)) {
            $errors['password'] = 'New password must contain at least one special character (! @ -).';
        } elseif (strlen($newPass) > 64) {
            $errors['password'] = 'New password must be 64 characters or fewer.';
        } elseif ($newPass !== $confirm) {
            $errors['password'] = 'The new passwords do not match.';
        } else {
            // Hash the new password and store it.
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $stmt->execute([':hash' => $hash, ':id' => $user['id']]);
            $user['password_hash'] = $hash;           // refresh local copy
            $messages['password'] = 'Password updated successfully.';
        }
    }

    // ==== 6b. Toggle MFA ----------------------------------------
    elseif (isset($_POST['toggle_mfa'])) {
        // Flip the flag: 1 -> 0, 0 -> 1.
        $newVal = $user['mfa_enabled'] ? 0 : 1;
        $stmt = $pdo->prepare('UPDATE users SET mfa_enabled = :val WHERE id = :id');
        $stmt->execute([':val' => $newVal, ':id' => $user['id']]);
        $user['mfa_enabled'] = $newVal;               // refresh local copy
        $messages['mfa'] = $newVal
            ? 'MFA enabled. Your next login will email you a one-time code.'
            : 'MFA disabled.';
    }

    // ==== 6c. Revoke a device / session -------------------------
    elseif (isset($_POST['revoke_device'])) {
        $deviceId = (int) ($_POST['device_id'] ?? 0);

        // Only the owner may revoke (user_id must match).
        $stmt = $pdo->prepare('SELECT * FROM user_devices WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmt->execute([':id' => $deviceId, ':uid' => $user['user_id']]);
        $device = $stmt->fetch();

        if ($device) {
            // If they revoked the CURRENT session, log them out.
            if ($device['session_id'] === session_id()) {
                $stmt = $pdo->prepare('DELETE FROM user_devices WHERE id = :id');
                $stmt->execute([':id' => $deviceId]);
                session_unset();
                session_destroy();
                header('Location: ' . sid_append('login.php'));
                exit;
            }

            // Otherwise delete the row AND destroy that other session
            // so the device is actually kicked out, not just hidden.
            $targetSid = $device['session_id'];
            $stmt = $pdo->prepare('DELETE FROM user_devices WHERE id = :id');
            $stmt->execute([':id' => $deviceId]);

            // Destroy the target session file. PHP only allows ONE open
            // session per request, so we must close ours first, open the
            // target's, destroy it, then re-open our own session.
            $mySid = session_id();
            session_write_close();      // save + close OUR session
            session_id($targetSid);     // point PHP at the target's id
            session_start();            // open the target session
            session_destroy();          // delete its data file
            session_id($mySid);         // point PHP back at ours
            session_start();            // re-open our session (data intact)

            $messages['devices'] = 'Device session terminated.';
        } else {
            $errors['devices'] = 'Device not found.';
        }
    }

    // ==== 6d. Update a service contract's status (provider side) --
    elseif (isset($_POST['update_contract'])) {
        $contractId = (int) ($_POST['contract_id'] ?? 0);
        $newStatus  = $_POST['contract_status'] ?? '';

        // Only the worker-driven lifecycle statuses may be written
        // here. pending_hire -> accepted/declined happens via the
        // HIRE flow (hire_action.php); the client cannot write these.
        if (!in_array($newStatus, ['accepted', 'declined', 'completed'], true)) {
            $errors['isla'] = 'Invalid job status.';
        } else {
            // The contract must belong to THIS user as the provider.
            $stmt = $pdo->prepare(
                'UPDATE service_contracts
                 SET status = :status
                 WHERE id = :id AND provider_id = :uid'
            );
            $stmt->execute([
                ':status' => $newStatus,
                ':id'     => $contractId,
                ':uid'    => $user['id'],
            ]);
            if ($stmt->rowCount() > 0) {
                // A completed job unlocks the client's rating prompt.
                $messages['isla'] = $newStatus === 'completed'
                    ? 'Job marked completed — the client can now rate your service.'
                    : 'Job status updated.';
            } else {
                $errors['isla'] = 'Job not found.';
            }
        }
    }

    // ==== 6f. Delete my account (Privacy & Security danger zone) --
    // Permanent and irreversible, so TWO gates must pass: the current
    // password AND the typed word DELETE. A stray tap can therefore
    // never wipe an account.
    //
    // Cleanup:
    //   - every table that points at this user cascades from the
    //     users row (providers, conversations, messages,
    //     service_contracts, reviews, user_interactions,
    //     saved_listings — see final_app.sql)
    //   - user_devices has NO foreign key, so it is cleared by hand
    //   - the uploaded avatar file is removed from uploads/ (its name
    //     is unreadable once the row is gone)
    elseif (isset($_POST['delete_account'])) {
        $deletePassword = $_POST['delete_password'] ?? '';
        $confirmWord    = trim($_POST['confirm_delete'] ?? '');

        if (!password_verify($deletePassword, $user['password_hash'])) {
            $errors['delete'] = 'That password is incorrect, so your account was not deleted.';
        } elseif (strtoupper($confirmWord) !== 'DELETE') {
            // Case-insensitive by design: the point is a deliberate
            // gesture, not a spelling test.
            $errors['delete'] = 'Type DELETE to confirm, then submit again.';
        } else {
            // Avatar first — after the DELETE we can no longer resolve
            // its filename. basename() keeps a stored value from ever
            // escaping the uploads directory.
            if (!empty($user['profile_picture'])) {
                $picPath = __DIR__ . '/uploads/' . basename($user['profile_picture']);
                if (is_file($picPath)) {
                    @unlink($picPath);
                }
            }

            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('DELETE FROM user_devices WHERE user_id = :uid');
                $stmt->execute([':uid' => $user['id']]);
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
                $stmt->execute([':id' => $user['id']]);
                $pdo->commit();

                // The account is gone: end the session and confirm on
                // the login screen.
                session_unset();
                session_destroy();
                header('Location: ' . sid_append('login.php?deleted=1'));
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('islaFIND account deletion failed: ' . $e->getMessage());
                $errors['delete'] = 'We could not delete your account just now. Please try again.';
            }
        }
    }

    // ==== 6e. Logout a device (deactivate, keep it listed) --------
    elseif (isset($_POST['logout_device'])) {
        $deviceId = (int) ($_POST['device_id'] ?? 0);

        // Only the owner may log out this device (user_id must match).
        $stmt = $pdo->prepare('SELECT * FROM user_devices WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmt->execute([':id' => $deviceId, ':uid' => $user['user_id']]);
        $device = $stmt->fetch();

        if ($device) {
            // Logging out THIS device means a full logout for the user.
            if ($device['session_id'] === session_id()) {
                $stmt = $pdo->prepare('UPDATE user_devices SET is_active = 0 WHERE session_id = :sid');
                $stmt->execute([':sid' => session_id()]);
                session_unset();
                session_destroy();
                header('Location: ' . sid_append('login.php'));
                exit;
            }

            // Otherwise just deactivate that session — the device stays
            // in the trusted list but is marked "Logged out" (the user
            // can log back in on it without re-adding it).
            $stmt = $pdo->prepare('UPDATE user_devices SET is_active = 0 WHERE id = :id');
            $stmt->execute([':id' => $deviceId]);
            $messages['devices'] = 'Device logged out.';
        } else {
            $errors['devices'] = 'Device not found.';
        }
    }
}

// --- 7. Load the user's islaFIND provider profiles --------------
// A user can own MULTIPLE listings (e.g. an engineer who also
// runs a shop), so every profile the user created is loaded here
// and rendered as its own card in the isla panel below. The card
// also pulls THIS listing's rating from the reviews table.
$stmt = $pdo->prepare(
    'SELECT p.*,
            p.average_rating AS avg_rating,
            p.review_count   AS review_count,
            CASE WHEN p.profile_type = \'business\'
                  AND (p.latitude IS NULL OR p.longitude IS NULL)
                 THEN 1 ELSE 0 END AS needs_pin
     FROM providers p
     WHERE p.user_id = :uid
     ORDER BY p.id DESC'
);
$stmt->execute([':uid' => $user['id']]);
$myProviders = $stmt->fetchAll();

// --- 7a. How many of those listings still need a map pin? --------
// The pin is REQUIRED for every BUSINESS listing (save_profile.php),
// so a business saved before that rule existed is flagged on its card
// — and counted once here for the panel-level notice — until the
// owner adds the coordinates. INDIVIDUAL listings may skip the pin
// and are never counted.
$pinlessProviders = 0;
foreach ($myProviders as $mp) {
    $pinlessProviders += (int) ($mp['needs_pin'] ?? 0);
}

// --- 7b. Load the user's service contracts -----------------------
// As a PROVIDER: the jobs they must manage (pending -> completed).
$stmt = $pdo->prepare(
    'SELECT sc.*, u.full_name AS client_name
     FROM service_contracts sc
     JOIN users u ON u.id = sc.client_id
     WHERE sc.provider_id = :uid
     ORDER BY sc.created_at DESC'
);
$stmt->execute([':uid' => $user['id']]);
$myContracts = $stmt->fetchAll();

// As a CLIENT: completed jobs waiting to be rated (rating unlock).
// The contract is pinned to the EXACT listing the client hired
// (sc.provider_listing_id), so a person with several islaFIND
// profiles only ever shows the one that was actually hired — never
// their other, unrelated listings. The display name falls back to
// the account name for individual skills listings (providers.name
// is NULL there — only businesses set their own name), so the
// prompt never shows a blank label.
$stmt = $pdo->prepare(
    'SELECT sc.*, p.id AS provider_listing_id,
            COALESCE(NULLIF(p.name, \'\'), u.full_name) AS provider_name,
            p.selected_title AS provider_listing_title
     FROM service_contracts sc
     JOIN providers p ON p.id = sc.provider_listing_id
     JOIN users u ON u.id = sc.provider_id
     WHERE sc.client_id = :uid AND sc.status = \'completed\' AND sc.is_rated = 0
     ORDER BY sc.created_at DESC'
);
$stmt->execute([':uid' => $user['id']]);
$rateableContracts = $stmt->fetchAll();

// --- 7c. The user's SAVED listings ------------------------------
// Bookmarks are their own little panel (Settings -> Saved Listings)
// so they are reachable without opening the directory and filtering.
// One JOIN brings everything a card shows — the listing, the owner's
// account name/picture and this listing's rating — newest save
// first. The table is created by db.php on first use; a failure here
// degrades to an empty panel rather than a broken dashboard.
$savedListings = [];
try {
    $stmt = $pdo->prepare(
        'SELECT p.*,
                u.full_name       AS user_name,
                u.profile_picture AS user_pic,
                p.average_rating  AS avg_rating,
                p.review_count    AS review_count,
                sl.created_at     AS saved_at
         FROM saved_listings sl
         JOIN providers p ON p.id = sl.provider_id
         JOIN users u     ON u.id = p.user_id
         WHERE sl.user_id = :uid
         ORDER BY sl.created_at DESC, p.id DESC'
    );
    $stmt->execute([':uid' => $user['id']]);
    $savedListings = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('islaFIND saved listings unavailable: ' . $e->getMessage());
    $savedListings = [];
}

// The feed needs the same information as a fast lookup map (its
// detail modal paints the heart from it), so it is derived here
// instead of running a second query.
$savedIds = [];
foreach ($savedListings as $savedRow) {
    $savedIds[(int) $savedRow['id']] = true;
}

// --- 8. Load the device list (Device Login) ----------------------
// (Section 9's flash block reads the upload result below.)
$stmt = $pdo->prepare(
    'SELECT * FROM user_devices WHERE user_id = :uid ORDER BY last_login DESC'
);
$stmt->execute([':uid' => $user['user_id']]);
$devices = $stmt->fetchAll();

// --- 9. Read the flash messages left by other handlers -----------
// upload_profile.php stores a one-shot flash message in the session
// then redirects here; read it, clear it, and render it as a banner
// on the Profile panel so the user sees the outcome of the upload.
if (isset($_SESSION['flash_upload'])) {
    $flash = $_SESSION['flash_upload'];
    unset($_SESSION['flash_upload']);
    if ($flash['type'] === 'success') {
        $messages['upload'] = $flash['msg'];
    } else {
        $errors['upload'] = $flash['msg'];
    }
}

// create_profile.php / send_message.php leave a similar flash that
// is shown on the islaFIND Profile panel.
if (isset($_SESSION['flash_isla'])) {
    $flash = $_SESSION['flash_isla'];
    unset($_SESSION['flash_isla']);
    if ($flash['type'] === 'success') {
        $messages['isla'] = $flash['msg'];
    } else {
        $errors['isla'] = $flash['msg'];
    }
}

// rate_service.php / send_message.php (from Home) leave a flash
// that is shown on the Home feed panel.
if (isset($_SESSION['flash_feed'])) {
    $flash = $_SESSION['flash_feed'];
    unset($_SESSION['flash_feed']);
    if ($flash['type'] === 'success') {
        $messages['feed'] = $flash['msg'];
    } else {
        $errors['feed'] = $flash['msg'];
    }
}

// save_listing.php leaves one when the heart was tapped FROM the
// saved-listings panel, so the result appears on that panel.
if (isset($_SESSION['flash_saved'])) {
    $flash = $_SESSION['flash_saved'];
    unset($_SESSION['flash_saved']);
    if ($flash['type'] === 'success') {
        $messages['saved'] = $flash['msg'];
    } else {
        $errors['saved'] = $flash['msg'];
    }
}

// --- 10. Safe display values ------------------------------------
$fullName = htmlspecialchars($user['full_name']);
$customId = (int) $user['user_id'];
$email    = htmlspecialchars($user['email']);
$phone    = htmlspecialchars($user['phone']);
// Initials for the avatar placeholder (e.g. "JD" for "Juan Dela Cruz").
// mb_* functions are used when available; plain str_* is the fallback.
$initials = '';
foreach (preg_split('/\s+/', trim($user['full_name'])) as $part) {
    if ($part !== '' && strlen($initials) < 2) {
        $first = function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
        $initials .= function_exists('mb_strtoupper') ? mb_strtoupper($first) : strtoupper($first);
    }
}
$avatarSrc = $user['profile_picture']
    ? 'uploads/' . rawurlencode($user['profile_picture'])
    : null;
$csrf = htmlspecialchars(csrf_token());

// Start the change-password form EXPANDED only when there is feedback
// to show (a failed attempt or a success message after a reload).
// Otherwise the panel shows a tidy "Change Password" button first.
$passwordOpen = isset($errors['password']) || isset($messages['password']);

// Same idea for the Device Logins collapsible in Privacy & Security.
$devicesOpen = isset($errors['devices']) || isset($messages['devices']);

// ...and for MFA, which is now an option like the others.
$mfaOpen = isset($errors['mfa']) || isset($messages['mfa']);

// ...and for the delete-account form, so a rejected attempt (wrong
// password / missing confirmation) reopens on its own form instead
// of dumping the user back on the options list with a hidden error.
$deleteOpen = isset($errors['delete']);

// Which of the Privacy & Security sub-views should open on load
// ('' = show the options list). Only one can be open: the chosen
// option replaces the list entirely.
$securityView = '';
if ($passwordOpen)    { $securityView = 'passwordSec'; }
elseif ($mfaOpen)     { $securityView = 'mfaSec'; }
elseif ($devicesOpen) { $securityView = 'devicesSec'; }
elseif ($deleteOpen)  { $securityView = 'deleteSec'; }

// Render the device list once and reuse it in both the Privacy &
// Security collapsible and the dedicated Device Login panel. Each
// device gets two options: Logout (deactivate the session, keep the
// device listed) and Revoke (remove the trusted device entirely).
$deviceItemsHtml = '';
if (!$devices) {
    $deviceItemsHtml = '<p class="sec-hint">No trusted devices recorded yet.</p>';
} else {
    $deviceItemsHtml = '<ul class="device-list">';
    foreach ($devices as $device) {
        $deviceItemsHtml .= '<li class="device-item">'
            . '<div class="device-meta">'
            . '<strong>' . htmlspecialchars($device['device_name']) . '</strong>'
            . '<span>' . htmlspecialchars($device['ip_address']) . ' &middot; '
            . htmlspecialchars(date('M j, Y g:i A', strtotime($device['last_login']))) . '</span>'
            . '<span class="device-status ' . ($device['is_active'] ? 'on' : 'off') . '">'
            . ($device['is_active'] ? 'Active' : 'Logged out') . '</span>'
            . '</div>'
            . '<div class="device-actions">'
            // Logout: deactivate this device's session (keeps it listed)
            . '<form action="dashboard.php" method="POST">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="device_id" value="' . (int) $device['id'] . '">'
            . '<button type="submit" name="logout_device" value="1" class="btn btn-small btn-outline">Logout</button>'
            . '</form>'
            // Revoke: remove this trusted device entirely
            . '<form action="dashboard.php" method="POST">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="device_id" value="' . (int) $device['id'] . '">'
            . '<button type="submit" name="revoke_device" value="1" class="btn btn-small btn-danger">Revoke</button>'
            . '</form>'
            . '</div>'
            . '</li>';
    }
    $deviceItemsHtml .= '</ul>';
}

// --- 11. Recommendation engine for the Home feed -----------------
// Two modes:
//   COLD START  — the user has zero interaction history, so we show
//                 the top 3 highest-rated profiles per category.
//   RETURNING   — the user has history, so we rank a mixed feed by
//                 a score combining category affinity (what they
//                 view/inquire/search), quality (rating + reviews),
//                 and recency (fresh listings get a boost).

// A provider card is rendered identically in both modes. Cards are
// account-synced: the photo and phone come from users, INDIVIDUAL
// listings show the account holder's name, BUSINESS listings show
// their own name. Each card also shows THIS listing's rating (avg
// stars + review count) aggregated from the reviews table.
// $catalogue = true renders the card for the Home feed's 📖 All Listings
// section (what directory.php used to be). It is the ONLY place a card
// carries id="listing-N", so that anchor stays unique even though the
// same listing can also sit in a rail — save_listing.php returns the
// visitor to #listing-N after a toggle, and an anchor that matched two
// cards would scroll to whichever came first.
function feedCardHtml(array $p, array $categories, int $myId, string $csrf, array $savedIds = [], bool $catalogue = false): string
{
    $catLabel = htmlspecialchars($categories[$p['selected_title']] ?? $p['selected_title']);
    // Business listings keep their own name; individuals use the account name.
    $name     = ($p['profile_type'] === 'business' && $p['name'] !== null && $p['name'] !== '')
        ? $p['name']
        : $p['user_name'];
    $nameHtml = htmlspecialchars($name);
    $pic      = $p['user_pic'] ? 'uploads/' . rawurlencode($p['user_pic']) : null;
    $jobs     = (int) ($p['completed_jobs'] ?? 0);   // hired + finished jobs
    $avgR     = round((float) ($p['avg_rating'] ?? 0), 1);
    $revN     = (int) ($p['review_count'] ?? 0);
    // "On the Job" applies ONLY to Individual Skills listings, and
    // only once the employer + worker agreed (an accepted hire).
    // Business profiles never carry the badge; inquiries stay open
    // either way.
    $onJob = !empty($p['on_job']) && $p['profile_type'] === 'individual';

    // Full-width strip at the very top of the card so the status is
    // visible in the feed itself, not only after opening the detail.
    $onJobBadge = $onJob
        ? '<div class="feed-card-onthejob"><span class="otj-dot"></span>On the Job</div>'
        : '';

    // Avatar: account photo or initials fallback.
    $initials = '';
    foreach (preg_split('/\s+/', trim($name)) as $part) {
        if ($part !== '' && strlen($initials) < 2) {
            $first = function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
            $initials .= function_exists('mb_strtoupper') ? mb_strtoupper($first) : strtoupper($first);
        }
    }
    $avatar = $pic
        ? '<img src="' . htmlspecialchars($pic) . '" alt="' . $nameHtml . '" class="feed-card-pic">'
        : '<span class="feed-card-pic feed-card-pic-placeholder">' . htmlspecialchars($initials ?: '?') . '</span>';

    // Per-listing rating row (stars + number + review count).
    if ($revN > 0) {
        $ratingRow = '<div class="card-rating"><span class="stars" aria-hidden="true">'
            . str_repeat('★', max(1, min(5, (int) round($avgR))))
            . '</span><span class="rating-num">' . number_format($avgR, 1)
            . '</span><span class="rating-count">(' . $revN . ' review' . ($revN === 1 ? '' : 's') . ')</span></div>';
    } else {
        $ratingRow = '<div class="card-rating"><span class="rating-none">No reviews yet</span></div>';
    }

    // NOTE: the account phone is deliberately NOT shown on the
    // public profile card. Contact details are sensitive, so the
    // provider decides when (and whether) to share their number
    // inside the messenger conversation — never on a public feed.

    // CONTEXTUAL FIELD: the profile type decides what extra detail
    // the card shows.
    //   - INDIVIDUAL SKILLS -> a short description snippet (the
    //     full text lives in the detail modal).
    //   - BUSINESS -> an "N units available" badge (rooms, bikes...).
    $isBusiness = $p['profile_type'] === 'business';
    $desc       = trim((string) ($p['profile_description'] ?? ''));
    $hasUnits   = isset($p['unit_inventory']) && $p['unit_inventory'] !== null;
    $units      = $hasUnits ? (int) $p['unit_inventory'] : 0;
    if ($isBusiness && $hasUnits) {
        // Only render the badge when the owner actually set a count
        // (a business that never entered units shows nothing).
        $contextBlock = '<div class="card-units">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>'
            . '<span>' . $units . ' unit' . ($units === 1 ? '' : 's') . ' available</span>'
            . '</div>';
    } elseif ($desc !== '') {
        $snippet = function_exists('mb_substr')
            ? (mb_strlen($desc) > 110 ? mb_substr($desc, 0, 110) . '…' : $desc)
            : (strlen($desc) > 110 ? substr($desc, 0, 110) . '…' : $desc);
        $contextBlock = '<p class="card-desc">' . htmlspecialchars($snippet) . '</p>';
    } else {
        $contextBlock = '';
    }

    // Compact location chip on every feed card: "Barangay, Municipality"
    // (e.g. "Poblacion, Madridejos") so the municipality is visible
    // right in the Home feed — same style as the Directory badge.
    $locMun  = trim((string) ($p['municipality'] ?? ''));
    $locBgy  = trim((string) ($p['barangay'] ?? ''));
    $locChip = ($locMun !== '' || $locBgy !== '')
        ? '<span class="card-location">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>'
            . '<span>' . htmlspecialchars(trim($locBgy . ', ' . $locMun, ", \n\r\t")) . '</span>'
            // Proximity slot: filled by the Home feed's script while
            // search results are ranked nearest-first, and left empty +
            // hidden otherwise so the pill never grows for a card (or a
            // sort) that has no distance to show.
            . '<span class="card-distance" hidden></span>'
            . '</span>'
        : '';

    // --- Pinned location (fed to the detail modal) ---------------
    // Only a BUSINESS listing whose owner pinned GPS coordinates on
    // the create/edit form is routable. The values ride along as
    // data-map-* attributes on the card; the modal's "Get Route"
    // button is filled from them when the card is tapped, so Google
    // Maps shows the path from the visitor's current position to the
    // business's pin.
    $mapLat = $p['latitude']  ?? null;
    $mapLng = $p['longitude'] ?? null;
    $hasPin = $isBusiness && $mapLat !== null && $mapLng !== null;

    // Distance ordering (the nearest-first search results) needs a pin
    // on EVERY listing, not just the businesses the detail modal can
    // route to: an INDIVIDUAL skill carries its own GPS pin from the
    // create form too. Keep a separate pair so the modal's
    // business-only route data above stays exactly as it was.
    $geoLat = $p['latitude']  ?? null;
    $geoLng = $p['longitude'] ?? null;
    $hasGeo = $geoLat !== null && $geoLat !== '' && $geoLng !== null && $geoLng !== '';

    // The feed card is COMPACT by design: it shows only the profile
    // details (photo, name, badge, jobs, rating, phone, and the
    // contextual description / units). Every action — including the
    // "Get Route" link for a pinned business — lives in the detail
    // modal that opens when the card is tapped. All the detail fields
    // are carried as data attributes so the modal can be filled
    // without a reload.
    //
    // NOTE: the card does NOT carry data-map-url. The stored
    // google_maps_url is only the destination-only deep link built at
    // save time; the modal builds a better one from the coordinates
    // below (with the visitor's own origin), so shipping the stored
    // copy would just be a misleading second source of truth.
    $own = (int) $p['user_id'] === $myId ? '1' : '0';

    // --- Save heart, BESIDE the name ----------------------------
    // The same POST toggle the directory uses (save_listing.php), so a
    // listing can be bookmarked straight from the Home feed without
    // opening the detail modal first. Rendered for OTHER people's
    // listings only: your own are managed in Settings and the server
    // refuses to bookmark them. The card's own click / keydown handler
    // deliberately steps aside for this control (see the .inline-save
    // guard in the feed wiring), so the heart never opens the modal by
    // accident on the way to the POST.
    $isSaved     = isset($savedIds[(int) $p['id']]);
    $saveControl = $own === '1' ? '' : '<form action="save_listing.php" method="POST" class="inline-save">'
        . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
        . '<input type="hidden" name="provider_id" value="' . (int) $p['id'] . '">'
        . '<input type="hidden" name="return_to" value="home">'
        . '<button type="submit" class="save-btn' . ($isSaved ? ' is-saved' : '') . '"'
        . ' aria-pressed="' . ($isSaved ? 'true' : 'false') . '"'
        . ' title="' . ($isSaved ? 'Remove from your saved listings' : 'Save this listing for later') . '">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>'
        . '<span>' . ($isSaved ? 'Saved' : 'Save') . '</span>'
        . '</button>'
        . '</form>';

    $html = '<div class="feed-card feed-card-clickable" role="button" tabindex="0"'
        // The catalogue's cards are the deep-linkable ones (see above).
        . ($catalogue ? ' id="listing-' . (int) $p['id'] . '"' : '')
        . ' data-id="' . (int) $p['id'] . '"'
        . ' data-name="' . $nameHtml . '"'
        . ' data-title="' . $catLabel . '"'
        // Category SLUG (not the label): the catalogue's category chips
        // filter on it, and the label is already rendered as the badge.
        . ' data-cat="' . htmlspecialchars($p['selected_title']) . '"'
        . ' data-type="' . htmlspecialchars($p['profile_type']) . '"'
        . ' data-barangay="' . htmlspecialchars($p['barangay'] ?? '') . '"'
        . ' data-municipality="' . htmlspecialchars($p['municipality'] ?? '') . '"'
        . ' data-rating="' . number_format($avgR, 1) . '"'
        . ' data-reviews="' . $revN . '"'
        . ' data-onjob="' . ($onJob ? '1' : '0') . '"'
        . ' data-pic="' . ($pic ? htmlspecialchars($pic) : '') . '"'
        . ' data-desc="' . htmlspecialchars($desc) . '"'
        . ' data-units="' . ($isBusiness && $hasUnits ? (int) $units : '') . '"'
        . ' data-map-lat="' . ($hasPin ? htmlspecialchars((string) $mapLat) : '') . '"'
        . ' data-map-lng="' . ($hasPin ? htmlspecialchars((string) $mapLng) : '') . '"'
        // The search ranking's coordinates: present on every pinned
        // listing (business OR individual), so a search can measure and
        // order results by distance from the visitor.
        . ' data-geo-lat="' . ($hasGeo ? htmlspecialchars((string) $geoLat) : '') . '"'
        . ' data-geo-lng="' . ($hasGeo ? htmlspecialchars((string) $geoLng) : '') . '"'
        . ' data-saved="' . (isset($savedIds[(int) $p['id']]) ? '1' : '0') . '"'
        . ' data-own="' . $own . '">'
        . $onJobBadge
        . '<div class="feed-card-top">'
        . $avatar
        . '<div class="feed-card-head">'
        . '<h5>' . $nameHtml . '</h5>'
        . '<span class="provider-badge">' . $catLabel . '</span>'
        . '</div>'
        . $saveControl
        . '</div>'
        . $ratingRow
        . $locChip
        . $contextBlock
        . '<span class="feed-card-hint">Tap for details &#8250;</span>'
        . '</div>';
    return $html;
}

// Category affinity: how many interactions (views/inquiries/searches)
// the user has per provider category — their "taste" signal.
// (With zero history the recommendation formula below degrades
// gracefully to job volume + engagement, so a cold start still
// gets a useful ✨ Recommended rail.)
$affinity = [];
$stmt = $pdo->prepare(
    'SELECT category, COUNT(*) AS n FROM user_interactions WHERE user_id = :uid GROUP BY category'
);
$stmt->execute([':uid' => $user['id']]);
foreach ($stmt->fetchAll() as $row) {
    $affinity[$row['category']] = (int) $row['n'];
}

// Load every provider WITH its completed-job count and the data
// needed to render a synced card: the account name/picture/phone
// (JOIN users) and this listing's rating (stored aggregate
// columns on providers, kept in sync by rate_service.php).
// Ranking uses completed-job volume + interaction counts, not
// public star scores.
$stmt = $pdo->query(
    'SELECT p.*,
            u.full_name  AS user_name,
            u.profile_picture AS user_pic,
            u.phone      AS user_phone,
            p.average_rating AS avg_rating,
            p.review_count   AS review_count,
            (SELECT COUNT(sc.id) FROM service_contracts sc
              WHERE sc.provider_id = p.user_id AND sc.status = \'completed\')             AS completed_jobs,
            EXISTS (SELECT 1 FROM service_contracts oj
                     WHERE oj.provider_id = p.user_id AND oj.status = \'accepted\')        AS on_job
     FROM providers p
     JOIN users u ON u.id = p.user_id
     ORDER BY p.created_at DESC'
);
$allProviders = $stmt->fetchAll();

// ($savedIds — the lookup map the feed's cards use — was built in
// step 7c from the saved-listings panel's own query.)

$feedHtml = '';
// How many cards the ✨ Recommended rail shows (a curated row of
// highlights — the complete catalogue is the 📖 All Listings section
// further down the same panel).
$railLimit = 6;
// How many cards each ⭐ category rail shows: the TOP-RATED 3 in
// that category, so below the recommendations the feed surfaces
// the best-reviewed individual skills and business listings.
$catLimit = 3;

// ---- 1. ✨ RECOMMENDED FOR YOU (always first) ----------------
// Personalized ranking for returning users; for a cold start (no
// interaction history yet) the formula degenerates gracefully to
// job volume + engagement, so the rail is never empty.
$maxAff = max(array_values($affinity) ?: [1]);   // normalize by the max
$now    = time();

// Score every provider. The formula uses behavior, not ratings:
//   - affinity: how often the user interacts with this category
//   - jobs:     completed service volume (social proof)
//   - recency:  freshness nudge for new listings
$scored = [];
foreach ($allProviders as $p) {
    $cat     = $p['selected_title'];
    $aff     = ($affinity[$cat] ?? 0) / $maxAff;              // 0..1 taste match
    $jobs    = min((int) $p['completed_jobs'], 25) / 25;      // capped job signal
    $popular = min((int) $p['interaction_count'], 100) / 100; // engagement
    $age     = max(0, $now - strtotime($p['created_at']));
    $recency = exp(-$age / (60 * 60 * 24 * 14));              // 14-day half-life

    // Weighted formula: taste dominates, completed jobs second,
    // engagement third, recency is a gentle freshness nudge.
    $score = 0.5 * $aff + 0.25 * $jobs + 0.15 * $popular + 0.1 * $recency;
    $scored[] = ['score' => $score, 'p' => $p];
}

// Highest score first. Only the top-N highest-ranked profiles
// make the rail — a curated swipeable row, not the directory.
usort($scored, function ($a, $b) { return $b['score'] <=> $a['score']; });
$topRecs = array_slice($scored, 0, $railLimit);
if ($topRecs) {
    $feedHtml .= '<section class="feed-section">'
        // Plain heading: the rail re-orders itself nearest-first once a
        // GPS fix lands (see applyRailDistanceOrder) and each card's
        // location pill carries the real distance, so the heading needs
        // no sort badge of its own.
        . '<h3 class="feed-section-title">✨ Recommended for You</h3>'
        // Horizontal slide-to-the-right rail: cards snap into view
        // and the row swipes sideways instead of pushing the rest
        // of the feed down.
        . '<div class="feed-rail">';
    foreach ($topRecs as $item) {
        $feedHtml .= feedCardHtml($item['p'], $providerCategories, (int) $user['id'], $csrf, $savedIds);
    }
    $feedHtml .= '</div></section>';
}

// ---- 2. ⭐ CATEGORY RAILS (every individual + business type) --
// Below the recommendations, group every listing by its category
// (electricians, welders, resorts, rentals...) and show the
// TOP-RATED 3 of each — the best-reviewed profiles in that skill
// or business type surface first.
$byCat = [];
foreach ($allProviders as $p) {
    $byCat[$p['selected_title']][] = $p;
}
foreach ($providerCategories as $slug => $label) {
    if (empty($byCat[$slug])) {
        continue;                    // no listings for this category
    }
    // Highest average rating first, then most reviews (tiebreak),
    // then most interactions — the genuinely best-reviewed float up.
    usort($byCat[$slug], function ($a, $b) {
        $ar = (float) ($a['avg_rating'] ?? 0);
        $br = (float) ($b['avg_rating'] ?? 0);
        if ($br !== $ar) {
            return $br <=> $ar;
        }
        if ((int) $b['review_count'] !== (int) $a['review_count']) {
            return (int) $b['review_count'] <=> (int) $a['review_count'];
        }
        return (int) $b['interaction_count'] <=> (int) $a['interaction_count'];
    });

    // Only the top-rated $catLimit listings make this rail.
    $topCat = array_slice($byCat[$slug], 0, $catLimit);
    $feedHtml .= '<section class="feed-section">'
        . '<h3 class="feed-section-title">⭐ ' . htmlspecialchars($label) . '</h3>'
        . '<div class="feed-rail">';
    foreach ($topCat as $p) {
        $feedHtml .= feedCardHtml($p, $providerCategories, (int) $user['id'], $csrf, $savedIds);
    }
    $feedHtml .= '</div></section>';
}

// ---- 3. 📖 ALL LISTINGS (the catalogue) ------------------------
// Every published listing in one place: the profile-type toggle, the
// category chips, the sort control and a live count, over the same
// .feed-card the rails use — one card renderer and one interaction
// model (tap a card for its details: directions, Inquire, reviews).
// This is what directory.php used to be, now inside the Home feed
// instead of on a second page that showed the same listings again.
//
// No cap: the filters, the sort and the search box above the feed are
// how a visitor narrows it, not a top-N slice. The cards are the only
// ones carrying id="listing-N" (see feedCardHtml), because the Save
// toggle returns here with that anchor.
$catalogueCardsHtml = '';
foreach ($allProviders as $catalogueRow) {
    $catalogueCardsHtml .= feedCardHtml(
        $catalogueRow,
        $providerCategories,
        (int) $user['id'],
        $csrf,
        $savedIds,
        true
    );
}
if ($catalogueCardsHtml !== '') {
    // It ships HIDDEN and the search box reveals it: an empty Home feed
    // shows the rails, and the moment a visitor looks for something the
    // results view below steps in with every match. The cards are in the
    // DOM either way — that is exactly what lets ONE search box reach
    // every listing instead of only the handful a rail happens to carry.
    // (Rendering it hidden rather than hiding it in JS also keeps the
    // whole list from flashing before the script runs.)
    $feedHtml .= '<section class="feed-section" id="feedCatalogue" hidden>'
        // The results heading and the SORT control share one row: the
        // heading says what was found ("⭐ Mechanic · 3 results") and the
        // pill right beside it re-orders the list — nearest, highest
        // rated, most reviewed, newest. The browsing furniture
        // (profile-type toggle + the category chip grid) stays COLLAPSED
        // behind the Filters button below, because on a phone that chip
        // wall ends up taller than the results themselves.
        . '<div class="feed-head">'
        . '<h3 class="feed-section-title" id="feedCatalogueTitle">📖 All Listings</h3>'
        . '<label class="feed-sort" for="feedSort">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="15" y2="12"/><line x1="4" y1="18" x2="10" y2="18"/></svg>'
        . '<select id="feedSort" aria-label="Sort listings">'
        . '<option value="newest">Newest first</option>'
        . '<option value="nearest">Nearest first</option>'
        . '<option value="rating">Highest rated</option>'
        . '<option value="reviews">Most reviewed</option>'
        . '</select>'
        . '</label>'
        . '</div>'
        // The browsing row: the Filters reveal + the live count. It is
        // hidden while a query is being answered (the heading carries the
        // count then), so the results stay the thing on screen.
        . '<div class="feed-toolbar">'
        . '<div class="feed-toolbar-left">'
        . '<button type="button" class="feed-filter-btn" id="feedFilterBtn"'
        . ' aria-expanded="false" aria-controls="feedFilters">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/></svg>'
        . '<span>Filters</span>'
        . '</button>'
        . '<span class="feed-count" id="feedCount" aria-live="polite"></span>'
        . '</div>'
        . '</div>'
        // The collapsed filter panel: the profile-type toggle and the
        // category chips, revealed by the Filters button (never shown
        // until asked for, so the results are what a search sees first).
        . '<div class="feed-filter-panel" id="feedFilters" hidden>'
        // Profile type: the create-page treatment, compacted.
        . '<div class="feed-type-toggle" role="group" aria-label="Filter by profile type">'
        . '<button type="button" class="feed-type-btn on" data-type="" aria-pressed="true">All</button>'
        . '<button type="button" class="feed-type-btn" data-type="individual" aria-pressed="false">Individual Skills</button>'
        . '<button type="button" class="feed-type-btn" data-type="business" aria-pressed="false">Business</button>'
        . '</div>'
        // Category chips: one per category, All first.
        . '<div class="feed-filters" role="group" aria-label="Filter by category">'
        . '<button type="button" class="chip on" data-cat="" aria-pressed="true">All</button>';
    foreach ($providerCategories as $catSlug => $catName) {
        $feedHtml .= '<button type="button" class="chip"'
            . ' data-cat="' . htmlspecialchars($catSlug) . '"'
            . ' aria-pressed="false">' . htmlspecialchars($catName) . '</button>';
    }
    $feedHtml .= '</div>'
        . '</div>'
        . '<div class="feed-list" id="feedGrid">' . $catalogueCardsHtml . '</div>'
        // Shown when the search and the filters between them leave
        // nothing behind. It lives INSIDE the results view on purpose:
        // the controls that would undo it are right above it, so nobody
        // is left staring at an empty page wondering what filtered them.
        . '<div class="feed-empty" id="feedCatalogueEmpty" hidden>'
        . '<p><strong>No listings match</strong></p>'
        . '<p>Try another search term, profile type or category.</p>'
        . '</div>'
        . '</section>';
}

if ($feedHtml === '') {
    // No listings anywhere: keep a simple empty state — just the
    // message, no create-profile CTA (the home feed only shows
    // existing profiles; profile creation lives in the settings
    // panel, not here).
    $feedHtml = '<div class="feed-empty">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2s7 5.2 7 12a7 7 0 0 1-14 0c0-6.8 7-12 7-12z"/><circle cx="12" cy="14" r="2.5"/></svg>'
        . '<p><strong>No listings yet</strong></p>'
        . '</div>';
}

// Which panel the app should open on
// ('home' | 'menu' | 'profile' | 'isla' | 'security' | 'jobs').
// A deep link (?tab=...) opens that panel directly, and after an
// action the reload lands on the panel that shows the result.
$activeTab = 'home';
if (isset($_GET['tab'])) {
    if ($_GET['tab'] === 'menu')        { $activeTab = 'menu'; }
    elseif ($_GET['tab'] === 'profile')  { $activeTab = 'profile'; }
    elseif ($_GET['tab'] === 'isla')     { $activeTab = 'isla'; }
    elseif ($_GET['tab'] === 'security') { $activeTab = 'security'; }
    elseif ($_GET['tab'] === 'jobs')     { $activeTab = 'jobs'; }
    elseif ($_GET['tab'] === 'saved')    { $activeTab = 'saved'; }
}
if (isset($errors['upload']) || isset($messages['upload'])) {
    $activeTab = 'profile';
}
if (isset($errors['password']) || isset($errors['mfa'])
 || isset($messages['password']) || isset($messages['mfa'])) {
    $activeTab = 'security';
}
if (isset($errors['devices']) || isset($messages['devices'])) {
    // Device feedback lives in the Device Logins collapsible inside
    // Privacy & Security (the standalone Device Login panel is gone).
    $activeTab = 'security';
}
if (isset($errors['isla']) || isset($messages['isla'])) {
    $activeTab = 'isla';
}
// The track's slide class mirrors the active panel.
$tabClass = '';
if ($activeTab === 'menu')        { $tabClass = ' show-menu'; }
elseif ($activeTab === 'profile')  { $tabClass = ' show-profile'; }
elseif ($activeTab === 'isla')     { $tabClass = ' show-isla'; }
elseif ($activeTab === 'security') { $tabClass = ' show-security'; }
elseif ($activeTab === 'jobs')     { $tabClass = ' show-jobs'; }
elseif ($activeTab === 'saved')    { $tabClass = ' show-saved'; }
// Bottom nav highlight: Home only when on the home panel; the
// Settings item is active for the menu hub and all sub-panels.
$homeNavActive = $activeTab === 'home' ? ' active' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php
// Shared metadata (title, description, favicon, manifest, social
// preview) + style.css and busy.js live in one partial so every
// page ships the same head.
$headTitle = 'Dashboard';
$headDesc  = 'Your islaFIND home feed, listings, jobs and account settings.';
include __DIR__ . '/../include/head_meta.php';
?>
    <!-- Strength meter for the Change Password fields below -->
    <script src="password_strength.js"></script>
</head>
<body>

    <!-- ============ Top app bar (Facebook-style header) ============ -->
    <header class="app-header">
        <img src="img/isla_logo.svg" alt="islaFIND logo" class="app-header-logo">
        <span class="app-header-title">islaFIND</span>
        <!-- Right-side cluster: notification bell, then the hamburger
             menu pinned to the far right corner (bell sits LEFT of it) -->
        <div class="header-actions">
            <!-- Global notification bell (badge + dropdown panel) -->
            <?php include __DIR__ . '/../include/notifications_bell.php'; ?>

            <!-- Hamburger menu button -> opens the Settings hub -->
            <button type="button" class="header-icon header-menu-btn" id="menuBtn"
                    aria-label="Menu" aria-haspopup="true" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
        </div>
    </header>

    <!-- ============ Scrolling content area ============ -->
    <main class="app-content">
        <div class="dash-card">

            <!-- ============ Sliding panel area ============
                 The track is 600% wide and holds SIX panels:
                 Home -> Settings menu -> Profile -> islaFIND
                 Profile -> Privacy & Security -> My Jobs.
                 Sliding it left by 16.67% per step reveals the
                 next panel. The JS also pins the track height
                 to the ACTIVE panel so the card shrinks/grows
                 with the slide. -->
            <div class="dash-slider">
                <div class="dash-slider-track<?php echo $tabClass; ?>" id="dashTrack">

                    <!-- ============ Panel 1: HOME ============ -->
                    <div class="dash-panel" id="dashHome">

                        <!-- Search bar (top of the feed, like the Facebook app) -->
                        <div class="search-bar">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="search" id="homeSearch" placeholder="Search services, people, barangay…" aria-label="Search">
                            <!-- Clear (x): appears only while the box has text -->
                            <button type="button" class="search-clear" id="homeSearchClear" aria-label="Clear search" hidden>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <?php if (isset($errors['feed'])): ?>
                            <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['feed']); ?></p>
                        <?php endif; ?>
                        <?php if (isset($messages['feed'])): ?>
                            <p class="field-ok" role="status"><?php echo htmlspecialchars($messages['feed']); ?></p>
                        <?php endif; ?>

                        <!-- Rating unlock: completed jobs waiting for a review -->
                        <?php if ($rateableContracts): ?>
                            <div class="rate-prompt">
                                <h4>Rate a completed service</h4>
                                <?php foreach ($rateableContracts as $rc): ?>
                                    <?php
                                    // Label the SPECIFIC listing that was hired
                                    // (e.g. "Dave Vidad — Electrician") so the
                                    // client rates the exact profile they hired,
                                    // never the person's other listings.
                                    $rcCat = htmlspecialchars($providerCategories[$rc['provider_listing_title'] ?? ''] ?? ($rc['provider_listing_title'] ?? ''));
                                    $rcName = htmlspecialchars($rc['provider_name']) . ($rcCat !== '' ? ' — ' . $rcCat : '');
                                    ?>
                                    <div class="rate-prompt-item">
                                        <span><?php echo $rcName; ?></span>
                                        <button type="button" class="btn btn-small btn-rate"
                                                data-contract="<?php echo (int) $rc['id']; ?>"
                                                data-name="<?php echo $rcName; ?>">Rate now</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Feed: cold-start carousels or personalized ranking -->
                        <?php echo $feedHtml; ?>

                    </div>

                    <!-- ============ Panel 2: SETTINGS MENU ============ -->
                    <div class="dash-panel" id="dashMenu">

                        <h4 class="sec-section">Settings</h4>
                        <p class="sec-hint">Choose a section to manage your account.</p>

                        <ul class="menu-list">
                            <li>
                                <button type="button" class="menu-item" data-go="profile">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <span class="menu-label">Profile</span>
                                    <span class="menu-chevron">&#8250;</span>
                                </button>
                            </li>
                            <li>
                                <button type="button" class="menu-item" data-go="isla">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2s7 5.2 7 12a7 7 0 0 1-14 0c0-6.8 7-12 7-12z"/><circle cx="12" cy="14" r="2.5"/></svg>
                                    <span class="menu-label">islaFIND Profile</span>
                                    <span class="menu-chevron">&#8250;</span>
                                </button>
                            </li>
                            <li>
                                <button type="button" class="menu-item" data-go="security">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                    <span class="menu-label">Privacy &amp; Security</span>
                                    <span class="menu-chevron">&#8250;</span>
                                </button>
                            </li>
                            <li>
                                <button type="button" class="menu-item" data-go="jobs">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect x="2" y="6" width="20" height="14" rx="2"/></svg>
                                    <span class="menu-label">My Jobs</span>
                                    <span class="menu-chevron">&#8250;</span>
                                </button>
                            </li>
                            <li>
                                <!-- Bookmarks, in a list of their own. -->
                                <button type="button" class="menu-item" data-go="saved">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                                    <span class="menu-label">Saved Listings</span>
                                    <span class="menu-chevron">&#8250;</span>
                                </button>
                            </li>
                        </ul>

                        <!-- Log Out button pinned at the bottom of the
                             settings hub (posts to logout.php) -->
                        <form action="logout.php" method="POST" class="settings-logout">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <button type="submit" class="btn btn-danger btn-block">Log Out</button>
                        </form>
                    </div>

                    <!-- ============ Panel 3: PROFILE ============ -->
                    <div class="dash-panel" id="dashProfile">

                        <button type="button" class="panel-back" data-go="menu">&#8592; Settings</button>

                        <!-- ======= Facebook-style profile picture =======
                             The avatar is clickable: it opens a small
                             popup menu with "Show Profile Picture" (opens
                             the full-size lightbox) and "Upload Profile
                             Picture" (triggers the hidden file input).
                             The upload form below posts to the dedicated
                             upload_profile.php handler and auto-submits
                             as soon as a file is chosen. -->
                        <div class="avatar-wrap">

                            <!-- Clickable avatar (button) -->
                            <button type="button" class="dash-avatar dash-avatar-btn"
                                    id="avatarBtn" aria-label="Profile picture options"
                                    aria-haspopup="true" aria-expanded="false">
                                <?php if ($avatarSrc): ?>
                                    <img src="<?php echo e($avatarSrc); ?>" alt="<?php echo $fullName; ?>">
                                <?php else: ?>
                                    <span class="dash-avatar-initials"><?php echo htmlspecialchars($initials ?: '?'); ?></span>
                                <?php endif; ?>
                                <!-- Camera hover overlay ("Change photo") -->
                                <span class="avatar-hover">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                    Change photo
                                </span>
                            </button>

                            <!-- Always-visible camera badge on the avatar -->
                            <span class="avatar-badge" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                            </span>

                            <!-- Popup action menu (toggle via JS) -->
                            <div class="avatar-menu" id="avatarMenu" hidden role="menu">
                                <button type="button" id="showPicBtn" role="menuitem"
                                        <?php echo $avatarSrc ? '' : 'disabled'; ?>>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    Show Profile Picture
                                </button>
                                <button type="button" id="uploadPicBtn" role="menuitem">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                    Upload Profile Picture
                                </button>
                            </div>
                        </div>

                        <h2 class="dash-name"><?php echo $fullName; ?></h2>
                        <p class="dash-id">User ID: <?php echo $customId; ?></p>

                        <div class="dash-info">
                            <div class="dash-row"><span>Email</span><strong><?php echo $email; ?></strong></div>
                            <div class="dash-row"><span>Phone</span><strong><?php echo $phone; ?></strong></div>
                            <div class="dash-row"><span>Age</span><strong><?php echo $age; ?> years old</strong></div>
                            <div class="dash-row"><span>Status</span><strong><?php echo $user['is_verified'] ? 'Verified' : 'Unverified'; ?></strong></div>
                        </div>

                        <!-- Upload feedback banners -->
                        <?php if (isset($messages['upload'])): ?>
                            <div class="alert alert-success" role="status"><?php echo htmlspecialchars($messages['upload']); ?></div>
                        <?php endif; ?>
                        <?php if (isset($errors['upload'])): ?>
                            <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($errors['upload']); ?></div>
                        <?php endif; ?>

                        <!-- Hidden upload form: posts to the dedicated
                             handler and auto-submits on file selection -->
                        <form action="upload_profile.php" method="POST" enctype="multipart/form-data" id="uploadForm" class="dash-upload">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="file" name="profile_picture" id="profile_picture" accept="image/jpeg,image/png" hidden>
                        </form>

                        <div class="dash-actions">
                            <!-- Logout form (POST + CSRF) -> logout.php -->
                            <form action="logout.php" method="POST" class="logout-form">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <button type="submit" class="btn btn-outline btn-danger">Logout</button>
                            </form>
                        </div>
                    </div>

                    <!-- ============ Panel 4: islaFIND PROFILE ============ -->
                    <div class="dash-panel" id="dashIsla">

                        <button type="button" class="panel-back" data-go="menu">&#8592; Settings</button>
                        <h4 class="sec-section">islaFIND Profile</h4>
                        <p class="sec-hint">List your service on the islaFIND directory so people on Bantayan Island can find and message you.</p>

                        <!-- Feedback banners -->
                        <?php if (isset($errors['isla'])): ?>
                            <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['isla']); ?></p>
                        <?php endif; ?>
                        <?php if (isset($messages['isla'])): ?>
                            <p class="field-ok" role="status"><?php echo htmlspecialchars($messages['isla']); ?></p>
                        <?php endif; ?>

                        <?php if ($pinlessProviders > 0): ?>
                            <!-- Panel-level flag: one line for every
                                 business listing still missing its pin,
                                 so the owner fixes them before clients
                                 hit a dead end (no route button). -->
                            <div class="pin-warning pin-warning-summary" role="status">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2s7 5.2 7 12a7 7 0 0 1-14 0c0-6.8 7-12 7-12z"/><circle cx="12" cy="14" r="2.5"/></svg>
                                <span>
                                    <strong><?php echo $pinlessProviders; ?> business listing<?php echo $pinlessProviders === 1 ? '' : 's'; ?></strong>
                                    still need<?php echo $pinlessProviders === 1 ? 's' : ''; ?> a map pin. Clients cannot get
                                    directions until you add one &mdash; use &ldquo;Add map pin&rdquo; on the card below.
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if (!$myProviders): ?>
                            <!-- No profile yet: a prominent CTA + directory link -->
                            <div class="isla-empty">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2s7 5.2 7 12a7 7 0 0 1-14 0c0-6.8 7-12 7-12z"/><circle cx="12" cy="14" r="2.5"/></svg>
                                <p><strong>You don't have an islaFIND profile yet.</strong></p>
                                <p>Create one to appear in the directory as a mechanic, resort, VA, and more.</p>
                                <a href="create_profile.php" class="btn btn-isla">Create an islaFIND Profile</a>
                                <a href="dashboard.php?tab=home#feedCatalogue" class="btn btn-outline btn-small">Browse All Listings</a>
                            </div>
                        <?php else: ?>
                            <!-- Every profile the user owns, rendered as its own card -->
                            <?php foreach ($myProviders as $myProvider): ?>
                                <?php
                                $provCat = htmlspecialchars($providerCategories[$myProvider['selected_title']] ?? $myProvider['selected_title']);
                                // Business listings keep their own name;
                                // individual listings show the account name.
                                $provName = ($myProvider['profile_type'] === 'business' && $myProvider['name'] !== null && $myProvider['name'] !== '')
                                    ? $myProvider['name']
                                    : $user['full_name'];
                                $provName = htmlspecialchars($provName);
                                // Photo + phone inherit from the account.
                                $provPic  = $user['profile_picture']
                                    ? 'uploads/' . rawurlencode($user['profile_picture']) : null;
                                $provPhone = htmlspecialchars($user['phone'] ?? '');
                                $avgR = round((float) ($myProvider['avg_rating'] ?? 0), 1);
                                $revN = (int) ($myProvider['review_count'] ?? 0);
                                // Initials for the picture placeholder.
                                $provInitials = '';
                                foreach (preg_split('/\s+/', trim($myProvider['name'] ?: $user['full_name'])) as $part) {
                                    if ($part !== '' && strlen($provInitials) < 2) {
                                        $first = function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
                                        $provInitials .= function_exists('mb_strtoupper') ? mb_strtoupper($first) : strtoupper($first);
                                    }
                                }
                                ?>
                                <div class="provider-card isla-mine">
                                    <div class="provider-card-top">
                                        <?php if ($provPic): ?>
                                            <img src="<?php echo e($provPic); ?>" alt="<?php echo $provName; ?>" class="provider-card-pic">
                                        <?php else: ?>
                                            <span class="provider-card-pic provider-card-pic-placeholder"><?php echo htmlspecialchars($provInitials ?: '?'); ?></span>
                                        <?php endif; ?>
                                        <div class="provider-card-head">
                                            <h5><?php echo $provName; ?></h5>
                                            <span class="provider-badge"><?php echo $provCat; ?></span>
                                        </div>
                                    </div>
                                    <!-- Rating for this specific profile -->
                                    <div class="card-rating">
                                        <?php if ($revN > 0): ?>
                                            <span class="stars" aria-hidden="true"><?php echo str_repeat('★', max(1, min(5, (int) round($avgR)))); ?></span>
                                            <span class="rating-num"><?php echo number_format($avgR, 1); ?></span>
                                            <span class="rating-count">(<?php echo $revN; ?> review<?php echo $revN === 1 ? '' : 's'; ?>)</span>
                                        <?php else: ?>
                                            <span class="rating-none">No reviews yet</span>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Owner-only analytics for THIS listing.
                                         view_count / interaction_count are bumped by
                                         track_view.php (a feed card is opened) and
                                         send_message.php (an inquiry arrives), so the
                                         owner can see which listing earns attention.
                                         title= spells each number out for the
                                         screen-reader / hover case. -->
                                    <div class="card-stats">
                                        <span title="Times this listing was opened in the feed">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <strong><?php echo number_format((int) $myProvider['view_count']); ?></strong>
                                            <?php echo (int) $myProvider['view_count'] === 1 ? 'view' : 'views'; ?>
                                        </span>
                                        <span title="Views, inquiries and reviews combined">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                                            <strong><?php echo number_format((int) $myProvider['interaction_count']); ?></strong>
                                            interactions
                                        </span>
                                        <span title="Verified reviews earned from completed jobs">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2l3 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.9 21l1.2-6.8-5-4.9 6.9-1z"/></svg>
                                            <strong><?php echo $revN; ?></strong>
                                            <?php echo $revN === 1 ? 'review' : 'reviews'; ?>
                                        </span>
                                    </div>
                                    <?php if ($provPhone !== ''): ?>
                                        <p class="provider-phone">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                            <?php echo $provPhone; ?>
                                        </p>
                                    <?php endif; ?>
                                    <!-- Contextual field: description
                                         (individual) or unit count
                                         (business) -->
                                    <?php if ($myProvider['profile_type'] === 'business' && $myProvider['unit_inventory'] !== null): ?>
                                        <div class="card-units">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                                            <span><?php echo (int) $myProvider['unit_inventory']; ?> unit<?php echo (int) $myProvider['unit_inventory'] === 1 ? '' : 's'; ?> available</span>
                                        </div>
                                    <?php elseif (($myProvider['profile_description'] ?? '') !== ''): ?>
                                        <?php $d = $myProvider['profile_description']; $snip = function_exists('mb_substr') ? (mb_strlen($d) > 110 ? mb_substr($d, 0, 110) . '…' : $d) : (strlen($d) > 110 ? substr($d, 0, 110) . '…' : $d); ?>
                                        <p class="card-desc"><?php echo htmlspecialchars($snip); ?></p>
                                    <?php endif; ?>
                                    <?php if ((int) ($myProvider['needs_pin'] ?? 0) === 1): ?>
                                        <!-- This BUSINESS listing has no coordinates,
                                             so the directory shows no "Get Route"
                                             button for it. Flagged for the owner
                                             with a direct link to the pin step of
                                             the edit form (the anchor scrolls
                                             straight to the Google Maps block). -->
                                        <div class="pin-warning">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2s7 5.2 7 12a7 7 0 0 1-14 0c0-6.8 7-12 7-12z"/><circle cx="12" cy="14" r="2.5"/></svg>
                                            <span>
                                                <strong>No map pin yet.</strong>
                                                Clients cannot get directions to this business, and a business
                                                listing must be pinned to be saved. Add your shop&rsquo;s spot on Google Maps.
                                            </span>
                                        </div>
                                        <div class="provider-card-actions">
                                            <a href="create_profile.php?edit=<?php echo (int) $myProvider['id']; ?>#gmapsPinGroup"
                                               class="btn btn-small btn-isla">Add map pin</a>
                                            <a href="create_profile.php?edit=<?php echo (int) $myProvider['id']; ?>" class="btn btn-small btn-outline">Edit Profile</a>
                                            <!-- See it the way a visitor does: the catalogue card, which
                                                 is the one carrying id="listing-N". -->
                                            <a href="dashboard.php?tab=home#listing-<?php echo (int) $myProvider['id']; ?>" class="btn btn-small">View in Listings</a>
                                            <!-- Delete: a POST form (GET can't delete anything) -->
                                            <form action="delete_profile.php" method="POST" class="inline-del"
                                                  onsubmit="return confirm('Delete this islaFIND profile? This cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="profile_id" value="<?php echo (int) $myProvider['id']; ?>">
                                                <button type="submit" class="btn btn-small btn-danger">Delete</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                    <div class="provider-card-actions">
                                        <!-- Edit rides on ?edit=<id> so it targets THIS listing -->
                                        <a href="create_profile.php?edit=<?php echo (int) $myProvider['id']; ?>" class="btn btn-small btn-outline">Edit Profile</a>
                                        <!-- See it the way a visitor does (see the pinned branch above). -->
                                        <a href="dashboard.php?tab=home#listing-<?php echo (int) $myProvider['id']; ?>" class="btn btn-small">View in Listings</a>
                                        <!-- Delete: a POST form (GET can't delete anything) -->
                                        <form action="delete_profile.php" method="POST" class="inline-del"
                                              onsubmit="return confirm('Delete this islaFIND profile? This cannot be undone.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="profile_id" value="<?php echo (int) $myProvider['id']; ?>">
                                            <button type="submit" class="btn btn-small btn-danger">Delete</button>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <!-- One person, many listings: add another anytime -->
                            <a href="create_profile.php" class="btn btn-isla" style="margin-top:12px;width:100%">Add another islaFIND Profile</a>
                        <?php endif; ?>
                    </div>

                    <!-- ============ Panel 5: PRIVACY & SECURITY ============ -->
                    <div class="dash-panel" id="dashSecurity">

                        <button type="button" class="panel-back" data-go="menu">&#8592; Settings</button>
                        <h4 class="sec-section">Privacy &amp; Security</h4>

                        <!-- ======= Security options list =======
                             Change Password / MFA / Device Logins are
                             all styled the same. Tapping an option
                             hides this list and shows ONLY that
                             option's content, with a back link to
                             return to the options. -->
                        <div class="sec-block" id="securityOptions"<?php echo $securityView !== '' ? ' hidden' : ''; ?>>
                            <button type="button" class="collapse-toggle" data-sec-view="passwordSec">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <span class="menu-label">Change Password</span>
                                <span class="menu-chevron">&#8250;</span>
                            </button>
                            <button type="button" class="collapse-toggle" data-sec-view="mfaSec">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
                                <span class="menu-label">Multi-Factor Authentication (MFA)</span>
                                <span class="menu-chevron">&#8250;</span>
                            </button>
                            <button type="button" class="collapse-toggle" data-sec-view="devicesSec">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                                <span class="menu-label">Device Logins</span>
                                <span class="menu-chevron">&#8250;</span>
                            </button>
                            <button type="button" class="collapse-toggle" data-sec-view="deleteSec">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                <span class="menu-label">Delete Account</span>
                                <span class="menu-chevron">&#8250;</span>
                            </button>
                        </div>

                        <!-- ======= Change Password view ======= -->
                        <div class="sec-block sec-view" id="passwordSec"<?php echo $securityView === 'passwordSec' ? '' : ' hidden'; ?>>
                            <button type="button" class="panel-back" data-sec-back>&#8592; Back to security options</button>
                            <h5>Change Password</h5>
                            <?php if (isset($errors['password'])): ?>
                                <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['password']); ?></p>
                            <?php endif; ?>
                            <?php if (isset($messages['password'])): ?>
                                <p class="field-ok" role="status"><?php echo htmlspecialchars($messages['password']); ?></p>
                            <?php endif; ?>
                            <form action="dashboard.php" method="POST" novalidate>
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <div class="form-group">
                                    <div class="input-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                        <input type="password" id="current_password" name="current_password" autocomplete="current-password" placeholder="Current password" aria-label="Current password">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <div class="input-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                        <input type="password" id="new_password" name="new_password" autocomplete="new-password" placeholder="New password" aria-label="New password"
                                               data-pw-strength>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <div class="input-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                        <input type="password" id="confirm_new_password" name="confirm_password" autocomplete="new-password" placeholder="Confirm new password" aria-label="Confirm new password">
                                    </div>
                                </div>
                                <button type="submit" name="change_password" value="1" class="btn" data-loading-label="Updating…">Update Password</button>
                            </form>
                        </div>

                        <!-- ======= Multi-Factor Authentication view ======= -->
                        <div class="sec-block sec-view" id="mfaSec"<?php echo $securityView === 'mfaSec' ? '' : ' hidden'; ?>>
                            <button type="button" class="panel-back" data-sec-back>&#8592; Back to security options</button>
                            <h5>Multi-Factor Authentication (MFA)</h5>
                            <p class="sec-hint">When enabled, logging in also asks for a one-time code emailed to your registered address.</p>
                            <?php if (isset($messages['mfa'])): ?>
                                <p class="field-ok" role="status"><?php echo htmlspecialchars($messages['mfa']); ?></p>
                            <?php endif; ?>
                            <form action="dashboard.php" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <button type="submit" name="toggle_mfa" value="1"
                                        class="btn <?php echo $user['mfa_enabled'] ? 'btn-danger' : ''; ?>">
                                    <?php echo $user['mfa_enabled'] ? 'MFA is ON — click to disable' : 'MFA is OFF — click to enable'; ?>
                                </button>
                            </form>
                        </div>

                        <!-- ======= Device Logins view ======= -->
                        <div class="sec-block sec-view" id="devicesSec"<?php echo $securityView === 'devicesSec' ? '' : ' hidden'; ?>>
                            <button type="button" class="panel-back" data-sec-back>&#8592; Back to security options</button>
                            <h5>Device Logins</h5>
                            <p class="sec-hint">Trusted devices with active sessions. Logout ends the session (device stays listed); Revoke removes the trusted device entirely.</p>
                            <?php if (isset($errors['devices'])): ?>
                                <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['devices']); ?></p>
                            <?php endif; ?>
                            <?php if (isset($messages['devices'])): ?>
                                <p class="field-ok" role="status"><?php echo htmlspecialchars($messages['devices']); ?></p>
                            <?php endif; ?>
                            <?php echo $deviceItemsHtml; ?>
                        </div>

                        <!-- ======= Delete Account view =======
                             The danger zone: permanent, so it demands the
                             account password AND a typed DELETE before the
                             handler runs (see step 6f). -->
                        <div class="sec-block sec-view" id="deleteSec"<?php echo $securityView === 'deleteSec' ? '' : ' hidden'; ?>>
                            <button type="button" class="panel-back" data-sec-back>&#8592; Back to security options</button>
                            <h5>Delete Account</h5>

                            <div class="danger-zone">
                                <h5>This cannot be undone</h5>
                                <p>Deleting your account permanently removes:</p>
                                <ul>
                                    <li>your login and profile details, plus your profile picture file</li>
                                    <li>every islaFIND listing you created</li>
                                    <li>your conversations, messages and saved listings</li>
                                    <li>your jobs and the reviews tied to them</li>
                                </ul>
                                <p>Completed and cancelled jobs, and any review you wrote for someone else, disappear with it.</p>

                                <?php if (isset($errors['delete'])): ?>
                                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['delete']); ?></p>
                                <?php endif; ?>

                                <form action="dashboard.php" method="POST" novalidate
                                      onsubmit="return confirm('Delete your islaFIND account permanently? This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <div class="form-group">
                                        <div class="input-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                            <input type="password" id="delete_password" name="delete_password"
                                                   autocomplete="current-password"
                                                   placeholder="Your current password"
                                                   aria-label="Current password">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="field-label" for="confirm_delete">Type <code>DELETE</code> to confirm</label>
                                        <div class="input-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                            <input type="text" id="confirm_delete" name="confirm_delete"
                                                   autocomplete="off" placeholder="DELETE"
                                                   aria-label="Type DELETE to confirm">
                                        </div>
                                    </div>
                                    <button type="submit" name="delete_account" value="1"
                                            class="btn btn-danger btn-block"
                                            data-loading-label="Deleting…">Permanently delete my account</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- ============ Panel 6: MY JOBS ============ -->
                    <div class="dash-panel" id="dashJobs">

                        <button type="button" class="panel-back" data-go="menu">&#8592; Settings</button>
                        <h4 class="sec-section">My Jobs</h4>
                        <p class="sec-hint">Service contracts where you are the provider. Accept or decline hire requests, and mark jobs completed so the client can rate you.</p>

                        <?php if (isset($errors['isla'])): ?>
                            <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['isla']); ?></p>
                        <?php endif; ?>
                        <?php if (isset($messages['isla'])): ?>
                            <p class="field-ok" role="status"><?php echo htmlspecialchars($messages['isla']); ?></p>
                        <?php endif; ?>

                        <?php if (!$myContracts): ?>
                            <p class="sec-hint">No jobs yet — when someone inquires about your service, a job is created here. Mark it completed to let them rate you.</p>
                        <?php else: ?>
                            <ul class="inquiry-list">
                                <?php foreach ($myContracts as $job): ?>
                                    <li class="inquiry-item">
                                        <div class="inquiry-meta">
                                            <strong><?php echo htmlspecialchars($job['client_name']); ?></strong>
                                            <span><?php echo htmlspecialchars(date('M j, Y', strtotime($job['created_at']))); ?></span>
                                        </div>
                                        <div class="inquiry-foot">
                                            <span class="inquiry-status st-<?php echo htmlspecialchars($job['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($job['status']))); ?></span>
                                            <?php if ($job['status'] === 'pending_hire'): ?>
                                                <!-- Worker quick actions for an incoming hire request -->
                                                <div class="inquiry-form">
                                                    <form action="hire_action.php" method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                        <input type="hidden" name="action" value="accept">
                                                        <input type="hidden" name="contract_id" value="<?php echo (int) $job['id']; ?>">
                                                        <button type="submit" class="btn btn-small btn-accept">Accept</button>
                                                    </form>
                                                    <form action="hire_action.php" method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                        <input type="hidden" name="action" value="decline">
                                                        <input type="hidden" name="contract_id" value="<?php echo (int) $job['id']; ?>">
                                                        <button type="submit" class="btn btn-small btn-decline">Decline</button>
                                                    </form>
                                                </div>
                                            <?php elseif ($job['status'] === 'accepted'): ?>
                                                <!-- On the Job: finish it to unlock the client's rating -->
                                                <div class="inquiry-form">
                                                    <form action="dashboard.php" method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                        <input type="hidden" name="contract_id" value="<?php echo (int) $job['id']; ?>">
                                                        <input type="hidden" name="contract_status" value="completed">
                                                        <button type="submit" name="update_contract" value="1" class="btn btn-small">Mark Completed</button>
                                                    </form>
                                                </div>
                                            <?php elseif ($job['status'] === 'completed'): ?>
                                                <span class="inquiry-status st-<?php echo $job['is_rated'] ? 'replied' : 'available'; ?>"><?php echo $job['is_rated'] ? 'Rated' : 'Awaiting rating'; ?></span>
                                            <?php elseif ($job['status'] === 'cancelled'): ?>
                                                <span class="sec-hint" style="margin:0">Cancelled by the client.</span>
                                            <?php elseif ($job['status'] === 'pending'): ?>
                                                <span class="sec-hint" style="margin:0">Waiting for the client to send a hire request from the chat.</span>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                    </div>

                    <!-- ============ Panel 7: SAVED LISTINGS ============ -->
                    <!-- Bookmarks made from the directory or the feed's
                         detail modal. Everything the owner kept in one
                         place, so it is reachable without opening the
                         directory and switching its filter on. Cards
                         reuse the directory's look (picture, name,
                         badge, rating, place) with two actions: open it
                         in the directory, or take it back out. -->
                    <div class="dash-panel" id="dashSaved">

                        <button type="button" class="panel-back" data-go="menu">&#8592; Settings</button>
                        <h4 class="sec-section">Saved Listings</h4>
                        <p class="sec-hint">Listings you saved while browsing. Tap the heart on any listing to keep it here.</p>

                        <?php if (isset($errors['saved'])): ?>
                            <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['saved']); ?></p>
                        <?php endif; ?>
                        <?php if (isset($messages['saved'])): ?>
                            <p class="field-ok" role="status"><?php echo htmlspecialchars($messages['saved']); ?></p>
                        <?php endif; ?>

                        <?php if (!$savedListings): ?>
                            <!-- Empty state: explains how the list fills up
                                 and offers the two ways to get there. -->
                            <div class="isla-empty">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                                <p><strong>Nothing saved yet</strong></p>
                                <p>Open a card in your Home feed &mdash; or any listing under <strong>All Listings</strong> &mdash; and tap <strong>Save</strong> to bookmark it here.</p>
                                <a href="dashboard.php?tab=home#feedCatalogue" class="btn btn-isla">Browse All Listings</a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($savedListings as $saved): ?>
                                <?php
                                // Same rules as every other card in the app:
                                // businesses show their own name, individuals
                                // fall back to the account holder's name.
                                $savedName = ($saved['profile_type'] === 'business'
                                        && $saved['name'] !== null && $saved['name'] !== '')
                                    ? $saved['name']
                                    : $saved['user_name'];
                                $savedNameHtml = htmlspecialchars($savedName);
                                $savedCat   = htmlspecialchars($providerCategories[$saved['selected_title']] ?? $saved['selected_title']);
                                $savedPic   = $saved['user_pic'] ? 'uploads/' . rawurlencode($saved['user_pic']) : null;
                                $savedRating = round((float) ($saved['avg_rating'] ?? 0), 1);
                                $savedReviews = (int) ($saved['review_count'] ?? 0);
                                $savedPlace = trim(($saved['barangay'] ?? '') . ', ' . ($saved['municipality'] ?? ''), ', ');
                                // Initials for the picture placeholder, the
                                // same way the islaFIND card does it.
                                $savedInitials = '';
                                foreach (preg_split('/\s+/', trim($savedName)) as $part) {
                                    if ($part !== '' && strlen($savedInitials) < 2) {
                                        $first = function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
                                        $savedInitials .= function_exists('mb_strtoupper') ? mb_strtoupper($first) : strtoupper($first);
                                    }
                                }
                                ?>
                                <!-- id="saved-listing-N" makes the card easy to
                                     find (and to assert on in the tests). -->
                                <div class="provider-card" id="saved-listing-<?php echo (int) $saved['id']; ?>">
                                    <div class="provider-card-top">
                                        <?php if ($savedPic): ?>
                                            <img src="<?php echo e($savedPic); ?>" alt="<?php echo $savedNameHtml; ?>" class="provider-card-pic">
                                        <?php else: ?>
                                            <span class="provider-card-pic provider-card-pic-placeholder"><?php echo htmlspecialchars($savedInitials ?: '?'); ?></span>
                                        <?php endif; ?>
                                        <div class="provider-card-head">
                                            <h5><?php echo $savedNameHtml; ?></h5>
                                            <span class="provider-badge"><?php echo $savedCat; ?></span>
                                        </div>
                                        <!-- Remove: the same POST toggle the heart
                                             uses, returning to THIS panel with a
                                             flash instead of to the directory. It
                                             sits beside the listing name (see
                                             .provider-card-top .inline-save in
                                             style.css) like the Save control on
                                             the directory cards. -->
                                        <form action="save_listing.php" method="POST" class="inline-save">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="provider_id" value="<?php echo (int) $saved['id']; ?>">
                                            <input type="hidden" name="return_to" value="saved">
                                            <button type="submit" class="save-btn is-saved" aria-pressed="true"
                                                    title="Remove from your saved listings">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                                                Remove
                                            </button>
                                        </form>
                                    </div>

                                    <div class="card-rating">
                                        <?php if ($savedReviews > 0): ?>
                                            <span class="stars" aria-hidden="true"><?php echo str_repeat('★', max(1, min(5, (int) round($savedRating)))); ?></span>
                                            <span class="rating-num"><?php echo number_format($savedRating, 1); ?></span>
                                            <span class="rating-count">(<?php echo $savedReviews; ?> review<?php echo $savedReviews === 1 ? '' : 's'; ?>)</span>
                                        <?php else: ?>
                                            <span class="rating-none">No reviews yet</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($savedPlace !== ''): ?>
                                        <div class="card-location">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                            <span><?php echo htmlspecialchars($savedPlace); ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($saved['saved_at'])): ?>
                                        <p class="saved-since">Saved <?php echo htmlspecialchars(date('M j, Y', strtotime($saved['saved_at']))); ?></p>
                                    <?php endif; ?>

                                    <div class="provider-card-actions">
                                        <!-- Opens the catalogue card on the Home tab,
                                             which is the card carrying id="listing-N". -->
                                        <a href="dashboard.php?tab=home#listing-<?php echo (int) $saved['id']; ?>"
                                           class="btn btn-small">Open in Listings</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <p class="sec-hint" style="margin-top:14px">More listings? <a href="dashboard.php?tab=home#feedCatalogue">Browse all listings</a> and tap <strong>Save</strong> on anything you want to keep.</p>
                        <?php endif; ?>

                    </div>

                </div>
            </div>

        </div>
    </main>

    <!-- ============ Full-size lightbox viewer ============
         Lives OUTSIDE the sliding track (direct body child) so its
         position: fixed is relative to the viewport — a transformed
         ancestor would otherwise become its containing block and the
         dark overlay + close button would be misplaced/clipped. -->
    <div class="lightbox" id="lightbox" hidden>
        <button type="button" class="lightbox-close" id="lightboxClose" aria-label="Close">&times;</button>
        <?php if ($avatarSrc): ?>
            <img src="<?php echo e($avatarSrc); ?>" id="lightboxImg" alt="<?php echo $fullName; ?> profile picture">
        <?php else: ?>
            <img src="" id="lightboxImg" alt="">
        <?php endif; ?>
    </div>

    <!-- ============ Inquiry modal (opens from feed cards) ============
         Same behavior as the directory modal: pick the provider,
         type a message, and POST to send_message.php. -->
    <div class="modal" id="inquiryModal" hidden>
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card">
            <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
            <h4>Inquire Availability</h4>
            <p class="sec-hint" id="inquiryTarget"></p>
            <form action="send_message.php" method="POST" id="inquiryForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="provider_id" id="inquiryProviderId" value="">
                <input type="hidden" name="return_to" value="home">
                <div class="form-group">
                    <textarea name="message_text" rows="4" maxlength="2000" required
                              placeholder="e.g. Hi, are you available to fix a motorcycle clutch tomorrow in Santa Fe?"
                              aria-label="Your message"></textarea>
                </div>
                <button type="submit" class="btn" data-loading-label="Sending…">Send Message Request</button>
            </form>
        </div>
    </div>

    <!-- ============ Provider detail modal (opens from feed cards) ============
         Tapping a feed card fills this modal with ALL of the listing's
         details — photo, name, title badge, profile type, barangay,
         phone, rating and jobs — plus the Inquire Availability button
         (or an Edit link when the listing is your own). -->
    <div class="modal" id="providerModal" hidden>
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card provider-modal-card">
            <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
            <div class="pm-header">
                <img src="" alt="" id="pmPic" class="pm-pic">
                <div class="pm-head">
                    <!-- Name + Save heart on ONE row (see .pm-name-row in
                         style.css), so the bookmark sits BESIDE the
                         listing name exactly like the Save pill on the
                         directory / Saved cards, instead of being buried
                         in the action stack at the bottom. -->
                    <div class="pm-name-row">
                        <h4 id="pmName"></h4>
                        <!-- Save for later: the same POST toggle the
                             directory uses, returning to the Home tab
                             with a flash. Shown for other people's
                             listings only — openProviderDetail() hides it
                             on your own and paints its saved state from
                             the card's data-saved attribute. -->
                        <form action="save_listing.php" method="POST" class="inline-save" id="pmSaveForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="provider_id" id="pmSaveId" value="">
                            <input type="hidden" name="return_to" value="home">
                            <button type="submit" class="save-btn" id="pmSaveBtn" aria-pressed="false">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                                <span id="pmSaveLabel">Save</span>
                            </button>
                        </form>
                    </div>
                    <div class="pm-tags">
                        <span class="provider-badge" id="pmBadge"></span>
                        <span class="pm-type" id="pmType"></span>
                        <span class="pm-onjob" id="pmOnJob" hidden>&#128338; On the Job</span>
                    </div>
                </div>
            </div>
            <div class="pm-rating" id="pmRating"></div>
            <!-- Contextual detail: the worker's full description or
                 the business's available-unit count (hidden when the
                 listing has neither). -->
            <div class="pm-context" id="pmContext" hidden></div>
            <ul class="pm-details">
                <li id="pmBarangay"></li>
                <!-- Distance + estimated drive time from the visitor to a
                     pinned business (e.g. "4.2 km away · about 12 min
                     drive"). Left EMPTY for anything without a pin,
                     which collapses the row on its own via the
                     .pm-details li:empty rule in style.css. -->
                <li id="pmTrip"></li>
            </ul>
            <div class="pm-actions">
                <!-- "Get Route": shown ONLY for a BUSINESS listing whose
                     owner pinned a location. It deep-links into Google
                     Maps directions from the visitor's current position
                     to the business's pin; openProviderDetail() fills the
                     href from the card's data-map-* attributes and hides
                     the button for everything else. -->
                <!-- The link and its hint travel together: the hint says
                     WHY the browser may ask for a location (the route is
                     built from the visitor's own position), so it must
                     appear and disappear with the button. -->
                <div class="pm-route">
                    <a href="#" target="_blank" rel="noopener" class="btn-map" id="pmMap" hidden>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l19-9-9 19-2-8-8-2z"/></svg>
                        Get Directions
                    </a>
                    <p class="route-hint" id="pmRouteHint" hidden
                       title="Your browser may ask for your location once &mdash; the route then starts from wherever you are.">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>
                        <span>Starts from your location</span>
                    </p>
                </div>
                <!-- Profiles cannot be edited from the feed; only the
                     dashboard's islaFIND panel offers that. The Reviews
                     button opens the full review records (disabled when
                     the listing has no reviews yet). -->
                <button type="button" class="btn btn-inquire" id="pmInquire">Inquire Availability</button>
                <button type="button" class="btn btn-outline" id="pmReviews">View Reviews</button>
            </div>
        </div>
    </div>

    <!-- ============ Reviews modal (opens from the detail modal) ============
         Shows the review records of one listing, filterable by
         star rating: ALL / 5★ / 4★ / 3★ / 2★ / 1★. Content is
         fetched from provider_reviews.php when the button is tapped. -->
    <div class="modal" id="reviewsModal" hidden>
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card reviews-modal-card">
            <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
            <h4 id="revTitle">Reviews</h4>
            <p class="sec-hint" id="revSubtitle"></p>
            <div class="rev-tabs" id="revTabs"></div>
            <div class="rev-list" id="revList"></div>
        </div>
    </div>

    <!-- ============ Rate modal (opens from the rating prompt) ============
         A 1-5 star picker plus an optional comment, posted to
         rate_service.php. It only appears for completed jobs; the
         backend re-checks the contract before saving. -->
    <div class="modal" id="rateModal" hidden>
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card">
            <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
            <h4>Rate this service</h4>
            <p class="sec-hint" id="rateTarget"></p>
            <form action="rate_service.php" method="POST" id="rateForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="contract_id" id="rateContractId" value="">
                <div class="star-picker" id="starPicker">
                    <input type="hidden" name="rating" id="ratingValue" value="5">
                    <button type="button" class="star" data-v="1" aria-label="1 star">★</button>
                    <button type="button" class="star" data-v="2" aria-label="2 stars">★</button>
                    <button type="button" class="star" data-v="3" aria-label="3 stars">★</button>
                    <button type="button" class="star" data-v="4" aria-label="4 stars">★</button>
                    <button type="button" class="star" data-v="5" aria-label="5 stars">★</button>
                </div>
                <div class="form-group">
                    <textarea name="comment" rows="3" maxlength="500"
                              placeholder="Optional: what was your experience like?"
                              aria-label="Review comment"></textarea>
                </div>
                <button type="submit" class="btn" data-loading-label="Submitting…">Submit Rating</button>
            </form>
        </div>
    </div>

    <!-- ============ Bottom navigation bar ============ -->
    <nav class="bottom-nav" aria-label="Main navigation">
        <button type="button" class="nav-item<?php echo $homeNavActive; ?>"
                data-tab="home" aria-label="Home">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span>Home</span>
        </button>
        <a href="messenger.php" class="nav-item" aria-label="Messenger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <span>Messenger</span>
        </a>
    </nav>

    <script>
        // ============================================================
        // View switcher — slides between the seven panels
        // (home -> settings menu -> profile / isla / security / jobs / saved)
        // ============================================================

        // Grab the sliding track, the panels, and the bottom nav.
        const dashTrack     = document.getElementById('dashTrack');
        const homePanel     = document.getElementById('dashHome');
        const menuPanel     = document.getElementById('dashMenu');
        const profilePanel  = document.getElementById('dashProfile');
        const islaPanel     = document.getElementById('dashIsla');
        const securityPanel = document.getElementById('dashSecurity');
        const jobsPanel     = document.getElementById('dashJobs');
        const savedPanel    = document.getElementById('dashSaved');
        // Bottom nav items (Home button + Messenger link).
        const navItems      = document.querySelectorAll('.bottom-nav .nav-item');

        // Initial panel comes from PHP. Values:
        // 'home' | 'menu' | 'profile' | 'isla' | 'security' | 'jobs' | 'saved'
        let activeTab = <?php echo json_encode($activeTab); ?>;

        // Measure a panel's NATURAL height (flex stretching would make
        // offsetHeight return the track height, so disable it briefly).
        function panelHeight(panel) {
            dashTrack.style.alignItems = 'flex-start';
            const h = panel.offsetHeight;
            dashTrack.style.alignItems = '';
            return h;
        }

        // Pin the track (and therefore the card) to the ACTIVE panel's
        // height; the CSS height transition animates the change.
        function syncTrackHeight() {
            const active =
                activeTab === 'menu'     ? menuPanel
              : activeTab === 'profile'  ? profilePanel
              : activeTab === 'isla'     ? islaPanel
              : activeTab === 'security' ? securityPanel
              : activeTab === 'jobs'     ? jobsPanel
              : activeTab === 'saved'    ? savedPanel
              : homePanel;
            dashTrack.style.height = panelHeight(active) + 'px';
        }

        // Slide the track and move the active highlight on the nav bar.
        // $keepScroll is set by the caller when it is about to scroll
        // somewhere specific instead (see the #listing-N deep link).
        function showTab(tab, keepScroll) {
            activeTab = tab;

            // The CSS classes drive the transform (see style.css).
            dashTrack.classList.remove('show-menu', 'show-profile', 'show-isla', 'show-security', 'show-jobs', 'show-saved');
            if (tab === 'menu')     dashTrack.classList.add('show-menu');
            if (tab === 'profile')  dashTrack.classList.add('show-profile');
            if (tab === 'isla')     dashTrack.classList.add('show-isla');
            if (tab === 'security') dashTrack.classList.add('show-security');
            if (tab === 'jobs')     dashTrack.classList.add('show-jobs');
            if (tab === 'saved')    dashTrack.classList.add('show-saved');
            syncTrackHeight();   // Animate the card height to the panel

            // Only the Home tab gets the active highlight (the
            // Messenger item is a plain link, not a sliding tab).
            navItems.forEach(function (btn) {
                const on = btn.dataset.tab === 'home' ? tab === 'home' : false;
                btn.classList.toggle('active', on);
                btn.setAttribute('aria-current', on ? 'page' : 'false');
            });

            // Scroll back to the top of the content on a panel change.
            if (!keepScroll) {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }

        // Bottom nav: Home -> home panel (Messenger is a link).
        navItems.forEach(function (btn) {
            if (btn.dataset.tab) {
                btn.addEventListener('click', function () {
                    showTab(btn.dataset.tab);
                });
            }
        });

        // Header hamburger -> opens the Settings hub.
        document.getElementById('menuBtn').addEventListener('click', function () {
            showTab('menu');
        });

        // Menu options + back links (data-go) slide to their panel.
        // The Saved Listings panel is the exception: it is rendered once
        // per page load, so a bookmark toggled on the Home feed (see
        // applySavedState) leaves it out of date. Instead of stitching
        // its cards together in the browser, the panel is re-rendered by
        // the server the moment it is opened — which is exactly the
        // reload the heart itself no longer causes.
        document.querySelectorAll('[data-go]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (btn.dataset.go === 'saved' && savedPanelStale) {
                    window.location.href = <?php echo json_encode(sid_append('dashboard.php?tab=saved')); ?>;
                    return;
                }
                showTab(btn.dataset.go);
            });
        });

        // Re-measure whenever the window resizes or the logo loads.
        window.addEventListener('resize', syncTrackHeight);
        window.addEventListener('load', syncTrackHeight);

        // ---- The #listing-N deep link ------------------------------
        // Saving or removing a bookmark on a catalogue card posts to
        // save_listing.php, which sends the visitor straight back to
        // dashboard.php?tab=home#listing-N. The panel switch above wants
        // to scroll to the top, so it is told to stand down here and the
        // jump is re-applied to the card itself — which is also ringed
        // briefly, so it is obvious WHICH listing just changed.
        const hashTarget = /^#listing-\d+$/.test(window.location.hash)
            ? document.getElementById(window.location.hash.slice(1))
            : null;

        // Sync the DOM with the server-rendered initial state.
        showTab(activeTab, !!hashTarget);

        if (hashTarget) {
            // Let the track height settle for a frame first, so the card
            // is measured where it will actually sit.
            requestAnimationFrame(function () {
                hashTarget.scrollIntoView({ block: 'center' });
                hashTarget.classList.add('is-flash');
                setTimeout(function () { hashTarget.classList.remove('is-flash'); }, 1800);
            });
        }

        // ============================================================
        // Facebook-style profile picture interaction
        // Click the avatar -> popup menu (Show / Upload). "Show" opens
        // a full-size lightbox; "Upload" triggers the hidden file input
        // which auto-submits the form to upload_profile.php.
        // ============================================================

        const avatarBtn      = document.getElementById('avatarBtn');
        const avatarMenu     = document.getElementById('avatarMenu');
        const showPicBtn     = document.getElementById('showPicBtn');
        const uploadPicBtn   = document.getElementById('uploadPicBtn');
        const lightbox       = document.getElementById('lightbox');
        const lightboxImg    = document.getElementById('lightboxImg');
        const lightboxClose  = document.getElementById('lightboxClose');
        const picInput       = document.getElementById('profile_picture');
        const uploadForm     = document.getElementById('uploadForm');

        // Toggle the popup menu when the avatar is clicked (or pressed).
        function toggleMenu(event) {
            event.stopPropagation();          // don't let the outside-click handler fire
            const willOpen = avatarMenu.hidden;
            avatarMenu.hidden = !willOpen;
            avatarBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        }

        avatarBtn.addEventListener('click', toggleMenu);
        avatarBtn.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();       // space must not scroll the page
                toggleMenu(event);
            }
        });

        // Clicking anywhere outside the avatar + menu closes the menu.
        document.addEventListener('click', function (event) {
            if (!avatarMenu.hidden &&
                !avatarMenu.contains(event.target) &&
                !avatarBtn.contains(event.target)) {
                avatarMenu.hidden = true;
                avatarBtn.setAttribute('aria-expanded', 'false');
            }
        });

        // "Show Profile Picture": open the full-size lightbox viewer.
        function openLightbox() {
            const img = avatarBtn.querySelector('img');
            if (!img) return;                 // no picture -> button is disabled anyway
            lightboxImg.src = img.src;        // reuse the avatar's image URL
            lightbox.hidden = false;
            document.body.classList.add('no-scroll');   // stop page scroll behind it
        }

        function closeLightbox() {
            lightbox.hidden = true;
            document.body.classList.remove('no-scroll');
        }

        showPicBtn.addEventListener('click', openLightbox);
        lightboxClose.addEventListener('click', closeLightbox);
        // Clicking the dark backdrop (not the image) also closes it.
        lightbox.addEventListener('click', function (event) {
            if (event.target === lightbox) closeLightbox();
        });

        // "Upload Profile Picture": close the menu and open the file picker.
        uploadPicBtn.addEventListener('click', function () {
            avatarMenu.hidden = true;
            avatarBtn.setAttribute('aria-expanded', 'false');
            picInput.click();
        });

        // Auto-submit the upload as soon as a file is chosen.
        picInput.addEventListener('change', function () {
            if (picInput.files.length) {
                // Spin the avatar while upload_profile.php runs.
                // form.submit() bypasses the form's submit event, so
                // the visible busy state is set right here instead of
                // waiting for busy.js.
                const uploadWrap = document.querySelector('.avatar-wrap');
                if (uploadWrap) {
                    uploadWrap.classList.add('is-uploading');
                    uploadWrap.setAttribute('aria-busy', 'true');
                }
                uploadForm.submit();
            }
        });

        // Escape closes whichever overlay is open.
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                avatarMenu.hidden = true;
                avatarBtn.setAttribute('aria-expanded', 'false');
                closeLightbox();
            }
        });

        // ============================================================
        // Feed cards: view tracking + inquiry/rate modals
        // ============================================================

        const inquiryModal  = document.getElementById('inquiryModal');
        const rateModal     = document.getElementById('rateModal');
        const providerModal = document.getElementById('providerModal');
        const reviewsModal  = document.getElementById('reviewsModal');

        // Escape user text before injecting it into the detail modal
        // (descriptions come from the providers table).
        function escHtml(str) {
            return String(str).replace(/[&<>"']/g, function (ch) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
            });
        }

        // Track a profile view for the recommendation engine (one
        // lightweight request per card shown — used for affinity).
        function trackView(providerId) {
            fetch('track_view.php?provider_id=' + providerId, { credentials: 'same-origin' });
        }

        function openModal(modal) {
            modal.hidden = false;
            document.body.classList.add('no-scroll');
        }
        function closeModals() {
            inquiryModal.hidden  = true;
            rateModal.hidden     = true;
            providerModal.hidden = true;
            reviewsModal.hidden  = true;
            document.body.classList.remove('no-scroll');
        }

        // The provider currently shown in the detail modal, so the
        // Inquire button knows who to message.
        let currentProvider = null;

        // ---- Distance + travel time to a pinned business ----------
        // Answers "how far am I from this shop?" with the visitor's own
        // GPS fix and the free, key-less OSRM routing service (the same
        // no-API-key approach as the reverse geocoding on the profile
        // form). OSRM returns the real DRIVING distance and duration
        // along the roads, not a straight-line guess, rendered by
        // tripSummary() as e.g. "4.2 km away · about 12 min drive".
        //
        // The result lands in #pmTrip, an empty <li> inside .pm-details:
        // an empty detail row collapses by itself, so "no pin" and "no
        // location permission" leave nothing behind. When the routing
        // service itself is unreachable (or has no drivable route) the
        // row degrades to a straight-line distance — measured locally
        // with the haversine formula, so it still works offline — and
        // deliberately reports NO travel time, because the real road
        // distance is always longer than the line we measured.
        //
        // Every finished measurement is cached for the rest of the page
        // view (tripCache), so reopening the same business renders the
        // same answer instantly instead of asking the routing service
        // again. That is safe to do because the visitor's own position
        // is cached for the page view too — a stored answer can never
        // be stale relative to where the visitor is standing.
        let myPositionPromise = null;   // one GPS fix per page view
        let myPositionDenied  = false;  // remembered hard refusal
        let tripRequestId     = 0;      // guards against stale responses
        const tripCache       = new Map();  // "lat,lng" -> rendered label text

        function getMyPosition() {
            // Reuse the fix already taken for this page view, so
            // tapping through several cards costs a single lookup.
            if (myPositionPromise) { return myPositionPromise; }

            myPositionPromise = new Promise(function (resolve) {
                if (!('geolocation' in navigator) || myPositionDenied) {
                    resolve(null);            // unsupported, or refused earlier
                    return;
                }
                navigator.geolocation.getCurrentPosition(
                    function (pos) { resolve(pos.coords); },
                    function (err) {
                        // 1 = PERMISSION_DENIED: stop asking (and stop
                        // re-prompting) for the rest of the visit. Any
                        // other code is transient, so drop the cached
                        // promise and let the next card retry.
                        if (err && err.code === 1) {
                            myPositionDenied = true;
                        } else {
                            myPositionPromise = null;
                        }
                        resolve(null);
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
                );
            });
            return myPositionPromise;
        }

        // The little navigate arrow that prefixes the trip row. Shared
        // by the routed label and the straight-line fallback.
        const TRIP_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l19-9-9 19-2-8-8-2z"/></svg>';

        // Write the trip row: icon + text, or nothing at all (which
        // collapses the <li> through the li:empty rule).
        function renderTrip(tripEl, text) {
            tripEl.innerHTML = text === '' ? '' : TRIP_ICON + '<span>' + text + '</span>';
        }

        // Metres -> "850 m" (sub-kilometre), "4.2 km" (under 10 km) or
        // "18 km". Shared by both labels so they read identically.
        function formatDistance(metres) {
            const km = metres / 1000;
            return km < 1
                ? Math.max(10, Math.round(metres / 10) * 10) + ' m'
                : km.toFixed(km < 10 ? 1 : 0) + ' km';
        }

        // Format a routed leg: metres + seconds -> "850 m away · about
        // 3 min drive", with "1 hr 25 min" once past the hour. The dot
        // separator is HTML, so this string is written with innerHTML.
        function tripSummary(metres, seconds) {
            const mins = Math.max(1, Math.round(seconds / 60));
            let time;
            if (mins < 60) {
                time = mins + ' min';
            } else {
                const h = Math.floor(mins / 60);
                const rest = mins % 60;
                time = h + ' hr' + (rest > 0 ? ' ' + rest + ' min' : '');
            }
            return formatDistance(metres) + ' away &middot; about ' + time + ' drive';
        }

        // Great-circle (straight-line) distance in metres between two
        // lat/lng points — the haversine formula. This is the fallback
        // when the routing service cannot be reached: it needs no
        // network at all, so an offline visitor still gets a real
        // answer to "how far away is this shop?".
        function straightLineMetres(lat1, lng1, lat2, lng2) {
            const earthRadius = 6371000;                       // mean radius, metres
            const toRad = function (deg) { return deg * Math.PI / 180; };
            const dLat = toRad(lat2 - lat1);
            const dLng = toRad(lng2 - lng1);
            const h = Math.sin(dLat / 2) * Math.sin(dLat / 2)
                    + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2))
                    * Math.sin(dLng / 2) * Math.sin(dLng / 2);
            // asin() of sqrt(h) is numerically stable for the small
            // angles this app deals with; the min() guards rounding.
            return 2 * earthRadius * Math.asin(Math.min(1, Math.sqrt(h)));
        }

        function loadTripInfo(tripEl, lat, lng) {
            if (!tripEl) { return; }

            // A newer card was opened: this call's late reply must not
            // overwrite what the current one is about to render.
            const reqId = ++tripRequestId;
            renderTrip(tripEl, '');                // clear the previous card
            if (!lat || !lng) { return; }          // no pin -> no row at all

            // Already measured this pin during this page view? Render
            // the stored result straight away — no GPS wait, no request.
            // (An empty string is a real cached answer meaning "nothing
            // to show", e.g. location was refused.)
            const key = lat + ',' + lng;
            if (tripCache.has(key)) {
                renderTrip(tripEl, tripCache.get(key));
                return;
            }

            tripEl.textContent = 'Locating you\u2026';

            getMyPosition().then(function (here) {
                if (!here) {
                    // Nothing to measure from. A hard refusal is cached
                    // (this visit will never get a fix), but a transient
                    // GPS failure is not, so the next tap retries.
                    if (myPositionDenied) { tripCache.set(key, ''); }
                    if (reqId === tripRequestId) { renderTrip(tripEl, ''); }
                    return;
                }
                if (reqId !== tripRequestId) { return; }   // superseded

                // OSRM takes "lng,lat;lng,lat" — visitor first, pin last.
                const ctrl  = new AbortController();
                const timer = setTimeout(function () { ctrl.abort(); }, 8000);
                const url = 'https://router.project-osrm.org/route/v1/driving/'
                    + here.longitude + ',' + here.latitude + ';' + lng + ',' + lat
                    + '?overview=false';

                return fetch(url, { signal: ctrl.signal })
                    .then(function (res) {
                        clearTimeout(timer);
                        if (!res.ok) { throw new Error('route status ' + res.status); }
                        return res.json();
                    })
                    .then(function (data) {
                        const route = (data && data.routes && data.routes[0]) || null;
                        if (!route) { throw new Error('no route'); }
                        // Cache the answer even when this reply is already
                        // superseded — it is still the right answer for
                        // this pin, just not for the card now on screen.
                        const text = tripSummary(route.distance, route.duration);
                        tripCache.set(key, text);
                        if (reqId === tripRequestId) { renderTrip(tripEl, text); }
                    })
                    .catch(function () {
                        clearTimeout(timer);

                        // Routing service unreachable, timed out, rate-
                        // limited — or it found no drivable route. Fall
                        // back to the straight-line distance between the
                        // visitor and the pin, measured locally so this
                        // path needs no network. No travel time is shown:
                        // the road distance would be longer than the line
                        // we measured, and inventing a speed would make
                        // the row quietly wrong.
                        const line = straightLineMetres(
                            parseFloat(here.latitude), parseFloat(here.longitude),
                            parseFloat(lat), parseFloat(lng)
                        );
                        // The fallback is cached too: if the service is
                        // unreachable it stays unreachable for the rest
                        // of the visit, and the straight-line figure is
                        // correct either way — so there is nothing to
                        // gain by re-requesting it on every tap.
                        const text = formatDistance(line) + ' away (straight line)';
                        tripCache.set(key, text);
                        if (reqId === tripRequestId) { renderTrip(tripEl, text); }
                    });
            });
        }

        // ---- Route links: always start from the visitor -----------
        // Google's Maps URL docs say an omitted origin "defaults to the
        // most relevant starting location, such as device location, if
        // available" — but on a browser that has never shared a location
        // with Google there is nothing to default to, and Maps silently
        // uses the DESTINATION as the origin (a route from the shop to
        // the shop). So the app fills origin in itself whenever it knows
        // where the visitor is.
        //
        //   routeDirectionsUrl(lat, lng)          -> destination only
        //   routeDirectionsUrl(lat, lng, coords)  -> from the visitor
        //
        // travelmode=driving matches the "about 12 min drive" estimate
        // shown right above the button, so the visitor lands on the same
        // kind of route the app just quoted.
        function routeDirectionsUrl(destLat, destLng, origin) {
            var url = 'https://www.google.com/maps/dir/?api=1'
                + '&destination=' + encodeURIComponent(destLat) + ',' + encodeURIComponent(destLng)
                + '&travelmode=driving';
            if (origin) {
                url += '&origin=' + encodeURIComponent(origin.latitude) + ',' + encodeURIComponent(origin.longitude);
            }
            return url;
        }

        // ---- Feed cards: tap opens the full detail modal ----------
        // The card carries every detail as data attributes, so the
        // modal is filled instantly without a page reload. Opening
        // the detail also logs a view for the recommendation engine.
        function openProviderDetail(card) {
            currentProvider = { id: card.dataset.id, name: card.dataset.name };
            document.getElementById('pmName').textContent = card.dataset.name;
            document.getElementById('pmBadge').textContent = card.dataset.title;
            document.getElementById('pmType').textContent =
                card.dataset.type === 'individual' ? 'Individual Skills' : 'Business';

            // Photo (hidden when the account has none).
            const pic = document.getElementById('pmPic');
            if (card.dataset.pic) {
                pic.src = card.dataset.pic;
                pic.hidden = false;
            } else {
                pic.hidden = true;
            }

            // Rating row: stars + number + review count, or a hint.
            const revN = parseInt(card.dataset.reviews, 10);
            document.getElementById('pmRating').innerHTML = revN > 0
                ? '<span class="stars" aria-hidden="true">' + '\u2605'.repeat(Math.max(1, Math.min(5, Math.round(parseFloat(card.dataset.rating))))) + '</span>'
                + '<span class="rating-num">' + card.dataset.rating + '</span>'
                + '<span class="rating-count">(' + revN + ' review' + (revN === 1 ? '' : 's') + ')</span>'
                : '<span class="rating-none">No reviews yet</span>';

            // Detail rows: barangay + municipality (e.g. "Poblacion,
            // Madridejos"). The phone is deliberately NOT rendered —
            // contact details stay private until the provider shares
            // them inside a messenger conversation.
            const b = document.getElementById('pmBarangay');
            const mun = card.dataset.municipality || '';
            const bgy = card.dataset.barangay || '';
            const place = (bgy && mun) ? bgy + ', ' + mun : (bgy || mun);
            b.innerHTML = place
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> '
                    + '<span>' + escHtml(place) + '</span>'
                : '';

            // Contextual detail: description (individual) or unit
            // count (business). Hidden when the listing has neither.
            const ctx = document.getElementById('pmContext');
            const desc = (card.dataset.desc || '').trim();
            const units = card.dataset.units;
            if (card.dataset.type === 'business' && units !== '') {
                ctx.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>'
                    + '<span>' + escHtml(units) + ' unit' + (units === '1' ? '' : 's') + ' available</span>';
                ctx.hidden = false;
            } else if (desc) {
                ctx.innerHTML = '<p>' + escHtml(desc) + '</p>';
                ctx.hidden = false;
            } else {
                ctx.hidden = true;
            }

            // "Get Route": only for a BUSINESS listing with a pinned
            // location, and the route must start from the VISITOR, not
            // from the shop. Google only falls back to the device
            // location when the browser has already shared one, so the
            // origin is supplied explicitly as soon as this page view
            // knows the visitor's position — the distance row below asks
            // for the same fix, so this adds no extra prompt. Until a fix
            // exists the link stays destination-only (Google's own
            // fallback), so the button always opens something useful.
            //
            // Coordinates are preferred over the owner-stored
            // google_maps_url for the same reason: the stored link is
            // exactly the destination-only form being fixed here.
            const mapBtn = document.getElementById('pmMap');
            const mapHint = document.getElementById('pmRouteHint');
            const mapLat = card.dataset.mapLat || '';
            const mapLng = card.dataset.mapLng || '';
            const openCardId = card.dataset.id;
            if (card.dataset.type === 'business' && mapLat !== '' && mapLng !== '') {
                mapBtn.href = routeDirectionsUrl(mapLat, mapLng);
                mapBtn.hidden = false;
                // The hint explains why the browser may ask for a
                // location, so it lives and dies with the button.
                mapHint.hidden = false;

                getMyPosition().then(function (coords) {
                    // The guard stops a fix that lands late from
                    // rewriting a modal the user has already left.
                    if (coords && currentProvider && currentProvider.id === openCardId) {
                        mapBtn.href = routeDirectionsUrl(mapLat, mapLng, coords);
                    }
                });
            } else {
                mapBtn.hidden = true;
                mapHint.hidden = true;
                mapBtn.removeAttribute('href');
            }

            // Distance + estimated drive time from the visitor to the
            // pin, right under the address row. Empty coordinates (any
            // non-business listing, or a business with no pin) clear
            // the row.
            loadTripInfo(document.getElementById('pmTrip'), mapLat, mapLng);

            // Own listing? No Inquire (editing happens in Settings,
            // never from the feed). The Reviews button stays for all.
            const isOwn = card.dataset.own === '1';
            document.getElementById('pmInquire').hidden = isOwn;

            // Save heart: hidden on your own listing (the server refuses
            // those too), otherwise painted from the card's data-saved
            // flag so the button always matches the stored bookmark.
            const saveForm  = document.getElementById('pmSaveForm');
            const saveBtn   = document.getElementById('pmSaveBtn');
            const saveLabel = document.getElementById('pmSaveLabel');
            const isSaved   = card.dataset.saved === '1';
            saveForm.hidden = isOwn;
            document.getElementById('pmSaveId').value = card.dataset.id;
            saveBtn.classList.toggle('is-saved', isSaved);
            saveBtn.setAttribute('aria-pressed', isSaved ? 'true' : 'false');
            saveBtn.title = isSaved
                ? 'Remove from your saved listings'
                : 'Save this listing for later';
            saveLabel.textContent = isSaved ? 'Saved' : 'Save';

            // "On the Job" badge in the modal header (accepted hire).
            document.getElementById('pmOnJob').hidden = card.dataset.onjob !== '1';

            // Reviews button: visible always, but disabled (not
            // clickable) when the listing has no reviews yet.
            const revBtn = document.getElementById('pmReviews');
            const hasReviews = parseInt(card.dataset.reviews, 10) > 0;
            revBtn.disabled = !hasReviews;
            revBtn.textContent = hasReviews
                ? 'View Reviews (' + card.dataset.reviews + ')'
                : 'No Reviews Yet';

            openModal(providerModal);
            trackView(card.dataset.id);
        }

        // Set by a bookmark toggled in place; the Saved Listings panel
        // is re-rendered from the server when it is next opened.
        let savedPanelStale = false;

        document.querySelectorAll('.feed-card').forEach(function (card) {
            // The Save heart is a real form living inside the card, so a tap
            // on it (or Enter / Space with it focused) must POST to
            // save_listing.php and NOT open the detail modal underneath.
            // Both handlers below stand down for anything inside
            // .inline-save; the button's own default action still runs.
            function inSaveControl(e) {
                return !!(e.target && e.target.closest && e.target.closest('.inline-save'));
            }

            card.addEventListener('click', function (e) {
                if (inSaveControl(e)) return;
                openProviderDetail(card);
            });
            // Keyboard access: Enter or Space opens the details too.
            card.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    if (inSaveControl(e)) return;
                    e.preventDefault();
                    openProviderDetail(card);
                }
            });
        });

        // ---- Bookmark in place: no reload for a heart tap ------------
        // The heart stays a real form (save_listing.php), so it works with
        // no script at all. When the script IS running the submit is
        // answered with fetch instead of a navigation: bookmarking is not
        // a reason to reload the whole feed, and a reload would repaint
        // the cards in server order and wipe out the distances the feed
        // had already measured (see applyRailDistanceOrder).
        //
        // The Settings panel's Remove button keeps the plain POST — that
        // list has to be re-rendered after a removal anyway.
        function paintSaveControl(form, isSaved) {
            const btn   = form.querySelector('.save-btn');
            const label = form.querySelector('.save-btn span');
            if (btn) {
                btn.classList.toggle('is-saved', isSaved);
                btn.setAttribute('aria-pressed', isSaved ? 'true' : 'false');
                btn.title = isSaved
                    ? 'Remove from your saved listings'
                    : 'Save this listing for later';
            }
            if (label) { label.textContent = isSaved ? 'Saved' : 'Save'; }
        }

        // One listing can be on screen twice (its rail card AND its
        // catalogue card) plus the detail modal, so the answer is applied
        // to every control carrying that provider id — and to the cards'
        // data-saved flag, which is what the modal paints itself from.
        function applySavedState(providerId, isSaved) {
            const id = String(providerId);
            savedPanelStale = true;      // the Settings panel needs a repaint

            document.querySelectorAll('.feed-card').forEach(function (card) {
                if (card.dataset.id === id) {
                    card.dataset.saved = isSaved ? '1' : '0';
                }
            });
            document.querySelectorAll('.inline-save').forEach(function (form) {
                const field = form.querySelector('input[name="provider_id"]');
                if (field && field.value === id) {
                    paintSaveControl(form, isSaved);
                }
            });
        }

        // ONE listener on the document, in the CAPTURE phase, so it runs
        // before busy.js's submit listener — which only skips its spinner
        // when the event is already prevented (the same trick
        // maps_pinning.js uses to block a submit outright). Anything this
        // listener hands back is not intercepted at all.
        const savesInFlight = new WeakSet();

        document.addEventListener('submit', function (e) {
            const form = e.target && e.target.closest
                ? e.target.closest('.inline-save')
                : null;
            if (!form || savesInFlight.has(form) || !window.fetch) { return; }

            // The Settings panel's Remove button keeps the plain POST:
            // that list has to be re-rendered after a removal.
            const returnField = form.querySelector('input[name="return_to"]');
            if (!returnField || returnField.value !== 'home') { return; }

            const field = form.querySelector('input[name="provider_id"]');
            const id    = field ? field.value : '';
            if (!id) { return; }             // nothing to toggle

            e.preventDefault();
            savesInFlight.add(form);         // and ignore a double tap meanwhile

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' }
            }).then(function (res) {
                return res.json();           // a redirect to login lands in catch
            }).then(function (data) {
                savesInFlight.delete(form);
                if (!data || !data.ok) {
                    // Refused (your own listing, expired session, DB
                    // hiccup): hand the request back to the ordinary POST
                    // so the server's flash banner explains why.
                    form.submit();
                    return;
                }
                applySavedState(id, data.saved);
            }).catch(function () {
                savesInFlight.delete(form);
                form.submit();               // no JSON answer: the plain POST path
            });
        }, true);

        // ---- Home feed filters: search + the catalogue ---------------
        // ONE function owns both, because they combine (AND):
        //   * the search box narrows EVERY card on the page (rails +
        //     catalogue) across name, title, barangay, municipality and
        //     phone;
        //   * the profile-type toggle and the category chips narrow the
        //     catalogue only.
        // Nothing reloads: cards are hidden in place, a section with
        // nothing left collapses, and the catalogue counts what survives.
        //
        // The catalogue is the RESULTS view: it is out of the way while
        // the search box is empty and no filter is set, and steps in the
        // moment the visitor looks for something. Its cards live in the
        // DOM either way, which is what lets one search box reach every
        // listing — not just the handful a rail happens to carry.
        const homeSearch = document.getElementById('homeSearch');
        const homeSearchClear = document.getElementById('homeSearchClear');
        const catalogueSection = document.getElementById('feedCatalogue');
        const catalogueTitle   = document.getElementById('feedCatalogueTitle');
        const catalogueToolbar = document.querySelector('#feedCatalogue .feed-toolbar');
        const catalogueEmpty = document.getElementById('feedCatalogueEmpty');
        const feedCount = document.getElementById('feedCount');
        const feedSort = document.getElementById('feedSort');
        const feedGrid = document.getElementById('feedGrid');
        let curType   = '';      // catalogue: '' | 'individual' | 'business'
        let curCat    = '';      // catalogue: '' | a category slug
        let lastSearchQuery = ''; // previous search text (detects a fresh search)

        function applyFeedFilters(animate) {
            const q = homeSearch.value.trim().toLowerCase();
            // Open the results view for a search OR a filter — a chip can
            // only be clicked while it is open, so it has to keep the
            // section open once the box is cleared again (otherwise a
            // stale chip would filter a search nobody can see).
            const catalogueOpen = q !== '' || curType !== '' || curCat !== '';

            document.querySelectorAll('.feed-section').forEach(function (section) {
                const isCatalogue = section === catalogueSection;

                // A search or a filter turns the page into RESULTS and
                // nothing else: the ✨ Recommended rail and the ⭐ category
                // rails step aside, so the visitor sees exactly the
                // listings they looked for — never the recommendation row
                // competing with them for attention.
                if (catalogueOpen && !isCatalogue) {
                    section.hidden = true;
                    return;
                }

                if (isCatalogue && !catalogueOpen) {
                    section.hidden = true;
                    return;
                }

                let visible = 0;
                section.querySelectorAll('.feed-card').forEach(function (card) {
                    // The haystack: name, service title, barangay,
                    // municipality, phone.
                    const haystack = [
                        card.dataset.name,
                        card.dataset.title,
                        card.dataset.barangay,
                        card.dataset.municipality,
                        card.dataset.phone
                    ].join(' ').toLowerCase();
                    const matchesSearch = q === '' || haystack.indexOf(q) !== -1;

                    // The catalogue's own type + category filters. They
                    // never touch a rail's cards (those carry no
                    // data-cat and are not in #feedCatalogue).
                    const allowed = matchesSearch
                        && (!isCatalogue
                            || ((curType === '' || card.dataset.type === curType)
                                && (curCat === '' || card.dataset.cat === curCat)));
                    card.hidden = !allowed;
                    if (!allowed) { return; }
                    visible++;

                    // Tapping a chip re-plays the reveal on every card
                    // that survived, so the change is visible (the same
                    // treatment the filter buttons on the create-profile
                    // form use).
                    if (animate && isCatalogue) {
                        card.classList.remove('feed-in');
                        void card.offsetWidth;
                        card.classList.add('feed-in');
                    }
                });

                // A rail with nothing left disappears, heading and all.
                // The open catalogue never hides itself over its OWN
                // filters: that would take the controls needed to undo
                // them off the screen, so it keeps its toolbar and shows
                // its own "no listings match" state instead.
                section.hidden = isCatalogue ? false : visible === 0;

                if (isCatalogue) {
                    // A search wears the feed's own clothes: a heading and
                    // the matching cards, nothing else. The toolbar
                    // (Filters + count + sort) and the filter panel are the
                    // BROWSING furniture, so they step aside while a query
                    // is being answered — the results read like any other
                    // section of the Home feed.
                    const searching = q !== '';
                    if (catalogueToolbar) { catalogueToolbar.hidden = searching; }
                    if (feedFilterPanel && searching) {
                        feedFilterPanel.hidden = true;
                        if (feedFilterBtn) {
                            feedFilterBtn.setAttribute('aria-expanded', 'false');
                        }
                    }

                    if (catalogueEmpty) { catalogueEmpty.hidden = visible > 0; }
                    if (feedCount) {
                        feedCount.innerHTML = '<strong>' + visible + '</strong> '
                            + (visible === 1 ? 'listing' : 'listings');
                    }

                    // Heading: the matched category in the feed's own
                    // "⭐ Mechanic" style when the query IS a category name,
                    // otherwise a plain results line — both carrying the live
                    // count, so the answer reads like a feed section.
                    if (catalogueTitle) {
                        if (!searching) {
                            catalogueTitle.textContent = '📖 All Listings';
                        } else {
                            const typed = homeSearch.value.trim();
                            const hit = Array.prototype.find.call(catBtns, function (b) {
                                return b.dataset.cat !== ''
                                    && b.textContent.trim().toLowerCase() === q;
                            });
                            catalogueTitle.textContent =
                                (hit ? '⭐ ' + hit.textContent.trim() : '🔍 ' + typed)
                                + ' · ' + visible + (visible === 1 ? ' result' : ' results');
                        }
                    }
                }
            });

            // A collapsed filter panel must never hide the fact that it
            // IS filtering: the pill lights up while a profile type or a
            // category is actually on.
            if (feedFilterBtn) {
                feedFilterBtn.classList.toggle('on', curType !== '' || curCat !== '');
            }
        }

        homeSearch.addEventListener('input', function () {
            const wasEmpty = lastSearchQuery === '';
            const q        = homeSearch.value.trim();
            lastSearchQuery = q;

            if (homeSearchClear) { homeSearchClear.hidden = q === ''; }
            applyFeedFilters();

            if (q === '') {
                applyFeedSort();            // back to the chosen order
                return;
            }

            // A fresh search switches to Nearest first (a sort the
            // visitor then picks by hand is respected). The GPS fix is
            // awaited before re-ordering; until it lands the current
            // order stands, and if it never arrives we fall back to the
            // default instead of pretending to measure.
            if (wasEmpty && feedSort) { feedSort.value = 'nearest'; }
            if (feedSort && feedSort.value === 'nearest') {
                feedLocate().then(function () {
                    if (!feedOrigin && feedSort.value === 'nearest') {
                        feedSort.value = 'newest';
                    }
                    applyFeedSort();
                });
            }
        });

        // Profile-type toggle (catalogue): highlight the tapped button,
        // then let the one apply function repaint.
        const typeBtns = document.querySelectorAll('.feed-type-btn');
        const catBtns  = document.querySelectorAll('.feed-filters .chip');

        typeBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                curType = btn.dataset.type;
                typeBtns.forEach(function (other) {
                    const on = other === btn;
                    other.classList.toggle('on', on);
                    other.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
                applyFeedFilters(true);
            });
        });

        // Category chips (catalogue), same treatment.
        catBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                curCat = btn.dataset.cat;
                catBtns.forEach(function (other) {
                    const on = other === btn;
                    other.classList.toggle('on', on);
                    other.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
                applyFeedFilters(true);
            });
        });

        // The filter furniture (profile type + the category chip grid)
        // ships COLLAPSED so a search opens straight onto its results.
        // This one button reveals it; aria-expanded carries the state.
        const feedFilterBtn   = document.getElementById('feedFilterBtn');
        const feedFilterPanel = document.getElementById('feedFilters');
        if (feedFilterBtn && feedFilterPanel) {
            feedFilterBtn.addEventListener('click', function () {
                const open = feedFilterPanel.hidden;
                feedFilterPanel.hidden = !open;
                feedFilterBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        // ---- Nearest-first search results -------------------------
        // A search ranks listings by how far they are from the visitor:
        // closest first, farthest last. Distances reuse one GPS fix per
        // page view (getMyPosition, the same one the detail cards take)
        // and the same haversine helper, measured against the coordinates
        // every pinned listing carries in data-geo-lat / data-geo-lng.
        // A listing with no pin has no distance and keeps its relative
        // order at the end of the results.
        let feedOrigin        = null;   // visitor's coords, once known
        let feedOriginPromise = null;   // one lookup per page view
        let feedWatchId       = null;   // live position watcher, once started
        const FEED_MIN_MOVE   = 25;     // metres — below this it is just GPS jitter

        // Ask for the visitor's position (and remember the answer).
        function feedLocate() {
            if (!feedOriginPromise) {
                feedOriginPromise = getMyPosition().then(function (here) {
                    feedOrigin = here || null;
                    // A real fix lets us follow the visitor for the rest
                    // of the page view, so a move re-ranks the feed.
                    if (feedOrigin) { startFeedWatch(); }
                    return feedOrigin;
                });
            }
            return feedOriginPromise;
        }

        // ---- Live position for the distance ordering ----------------
        // Keep the distances honest while the page is open: the browser
        // reports new fixes as the visitor moves, and each real move
        // re-ranks what is on screen — the rails always, and the results
        // list whenever it is the one being ordered by distance.
        //
        // Only the feed is repainted. The detail modal keeps its own
        // single fix, whose trip answers are cached for the page view, so
        // this watcher never invalidates that cache.
        function startFeedWatch() {
            if (feedWatchId !== null
                || !('geolocation' in navigator)
                || typeof navigator.geolocation.watchPosition !== 'function') {
                return;                     // already watching, or unsupported
            }
            feedWatchId = navigator.geolocation.watchPosition(
                function (pos) {
                    const here = pos.coords;
                    // Ignore GPS jitter — re-ranking on every wobble would
                    // make the feed shuffle under the visitor's thumb.
                    if (feedOrigin && straightLineMetres(
                            feedOrigin.latitude, feedOrigin.longitude,
                            here.latitude, here.longitude) < FEED_MIN_MOVE) {
                        return;
                    }
                    feedOrigin = here;
                    applyRailDistanceOrder();
                    if (feedSort && feedSort.value === 'nearest') {
                        applyFeedSort();
                    }
                },
                function () { /* transient failure: keep the last known fix */ },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 20000 }
            );
        }

        // Straight-line metres from the visitor to a card's pin, or null
        // when either side is unknown (no fix yet, or no pin on the card).
        function cardDistanceMetres(card) {
            if (!feedOrigin) { return null; }
            const lat = parseFloat(card.dataset.geoLat);
            const lng = parseFloat(card.dataset.geoLng);
            if (isNaN(lat) || isNaN(lng)) { return null; }
            return straightLineMetres(feedOrigin.latitude, feedOrigin.longitude, lat, lng);
        }

        // Nearest first: pinned listings before unpinned ones; both keep
        // the order they were in (Array.sort is stable).
        function compareNearest(a, b) {
            const da = cardDistanceMetres(a);
            const db = cardDistanceMetres(b);
            if (da === null && db === null) { return 0; }
            if (da === null) { return 1; }
            if (db === null) { return -1; }
            return da - db;
        }

        // ---- Sort the catalogue: re-order the SAME cards ------------
        // Every catalogue card already carries data-rating /
        // data-reviews / data-name / data-id, so sorting is a pure DOM
        // re-append — the hidden state, the saved state and the click
        // listeners all ride along with the element.
        function applyFeedSort() {
            if (!feedGrid || !feedSort) { return; }
            const mode  = feedSort.value;

            const cards = Array.prototype.slice.call(feedGrid.querySelectorAll('.feed-card'));

            cards.sort(function (a, b) {
                const an = (a.dataset.name || '').toLowerCase();
                const bn = (b.dataset.name || '').toLowerCase();
                switch (mode) {
                    case 'rating':
                        // Highest average first; ties fall back to A-Z.
                        return (parseFloat(b.dataset.rating) - parseFloat(a.dataset.rating))
                            || an.localeCompare(bn);
                    case 'reviews':
                        return (parseInt(b.dataset.reviews, 10) - parseInt(a.dataset.reviews, 10))
                            || an.localeCompare(bn);
                    case 'nearest':
                        // Closest to the visitor first; unpinned listings
                        // keep their relative order after the pinned ones.
                        return compareNearest(a, b);
                    default:
                        // "Newest first": the newest listing has the
                        // highest id, which matches the server order.
                        return parseInt(b.dataset.id, 10) - parseInt(a.dataset.id, 10);
                }
            });

            cards.forEach(function (card) { feedGrid.appendChild(card); });

            // The "x away" label belongs to the nearest-first order: fill
            // it when that sort is on, clear it for every other order.
            paintCardDistances(feedGrid, mode === 'nearest');
        }

        // ---- Distance-ordered rails --------------------------------
        // The home rails (✨ Recommended, ⭐ categories) are ranked
        // server-side by affinity and rating, but where the visitor is
        // standing matters more here: once a GPS fix lands, every rail
        // re-orders its OWN cards nearest-first (the catalogue order is
        // untouched). With no fix — or a rail with no pinned listings to
        // measure — the rail keeps its server order.
        function applyRailDistanceOrder() {
            document.querySelectorAll('.feed-section .feed-rail').forEach(function (rail) {
                const cards = Array.prototype.slice.call(rail.querySelectorAll('.feed-card'));

                // A rail can only be ordered by distance if it actually
                // has a pin to measure against.
                const pinned = cards.some(function (card) {
                    return card.dataset.geoLat && card.dataset.geoLng;
                });
                if (!feedOrigin || !pinned) {
                    paintCardDistances(rail, false);   // not distance-ordered
                    return;
                }

                cards.sort(compareNearest);
                cards.forEach(function (card) { rail.appendChild(card); });
                // The same "x km away" label the search results carry:
                // the distance order has to be visible on the cards.
                paintCardDistances(rail, true);
            });
        }

        // Write the distance into each card's location pill (the empty
        // .card-distance slot feedCardHtml leaves there). A card with no
        // pin has nothing to measure, and its slot stays hidden so the
        // pill never grows. `show` turns the labels off again for a list
        // that is NOT ordered by distance — the search results (see
        // applyFeedSort) and each distance-ordered rail.
        function paintCardDistances(list, show) {
            if (!list) { return; }
            list.querySelectorAll('.feed-card').forEach(function (card) {
                const slot = card.querySelector('.card-distance');
                if (!slot) { return; }
                const metres = show ? cardDistanceMetres(card) : null;
                if (metres === null) {
                    slot.hidden = true;
                    slot.textContent = '';
                    return;
                }
                slot.textContent = formatDistance(metres) + ' away';
                slot.hidden = false;
            });
        }

        if (feedSort) {
            feedSort.addEventListener('change', function () {
                // Choosing "Nearest first" by hand needs the GPS fix too,
                // so resolve it before re-ordering.
                if (feedSort.value === 'nearest') {
                    feedLocate().then(function () {
                        if (!feedOrigin) { feedSort.value = 'newest'; }
                        applyFeedSort();
                    });
                    return;
                }
                applyFeedSort();
            });
            applyFeedSort();          // apply the default order up front
        }

        // Order the rails by distance once a fix lands — but only ask for
        // the visitor's position when a rail actually carries a pin, so a
        // directory with no coordinates never triggers a GPS prompt.
        const anyRailPin = Array.prototype.some.call(
            document.querySelectorAll('.feed-rail .feed-card'),
            function (card) { return card.dataset.geoLat && card.dataset.geoLng; }
        );
        if (anyRailPin) {
            feedLocate().then(applyRailDistanceOrder);
        }

        // The catalogue starts in server order; paint its count once so
        // the toolbar is never blank before the first interaction.
        applyFeedFilters();

        // Clear (x): empty the box and restore the full feed in one tap.
        if (homeSearchClear) {
            homeSearchClear.addEventListener('click', function () {
                homeSearch.value = '';
                homeSearchClear.hidden = true;
                homeSearch.focus();
                homeSearch.dispatchEvent(new Event('input', { bubbles: true }));
            });
        }

        // Inquire button inside the detail modal -> switch to the
        // inquiry modal with the provider already selected.
        document.getElementById('pmInquire').addEventListener('click', function () {
            if (!currentProvider) { return; }
            document.getElementById('inquiryProviderId').value = currentProvider.id;
            document.getElementById('inquiryTarget').textContent = 'Send a message to ' + currentProvider.name;
            providerModal.hidden = true;
            openModal(inquiryModal);
        });

        // ---- Reviews button: fetch + render the review records ---
        // The reviews modal shows a per-star breakdown (ALL / 5★ /
        // 4★ / 3★ / 2★ / 1★) with counts, and the matching list of
        // review cards below. Filtering happens client-side.
        let reviewsData = null;
        let reviewsFilter = 'all';

        function starGlyph(n) {
            return '\u2605'.repeat(n) + '\u2606'.repeat(5 - n);
        }

        function renderReviewList() {
            const list = document.getElementById('revList');
            const items = reviewsFilter === 'all'
                ? reviewsData.reviews
                : reviewsData.reviews.filter(function (r) { return r.rating === reviewsFilter; });

            if (!items.length) {
                list.innerHTML = '<p class="rev-empty">No reviews for this rating.</p>';
                return;
            }
            // The reviewer name and the written comment are BOTH
            // user-supplied text, so every one of them is HTML-escaped
            // before it reaches innerHTML — otherwise a comment like
            // <img src=x onerror=...> would execute for every visitor.
            list.innerHTML = items.map(function (r) {
                return '<div class="rev-item">'
                    + '<div class="rev-head"><span class="rev-stars" aria-hidden="true">' + starGlyph(r.rating) + '</span>'
                    + '<strong>' + escHtml(r.reviewer) + '</strong><span class="rev-date">' + escHtml(r.date) + '</span></div>'
                    + (r.comment ? '<p class="rev-comment">' + escHtml(r.comment) + '</p>' : '')
                    + '</div>';
            }).join('');
        }

        function renderReviewTabs() {
            const tabs = document.getElementById('revTabs');
            const c = reviewsData.counts;
            const defs = [
                { key: 'all', label: 'ALL', n: reviewsData.count },
                { key: 5, label: '5\u2605', n: c[5] },
                { key: 4, label: '4\u2605', n: c[4] },
                { key: 3, label: '3\u2605', n: c[3] },
                { key: 2, label: '2\u2605', n: c[2] },
                { key: 1, label: '1\u2605', n: c[1] }
            ];
            tabs.innerHTML = defs.map(function (d) {
                return '<button type="button" class="rev-tab' + (reviewsFilter === d.key ? ' on' : '') + '"'
                    + ' data-filter="' + d.key + '">' + d.label + ' <span class="rev-tab-count">' + d.n + '</span></button>';
            }).join('');
            tabs.querySelectorAll('.rev-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    reviewsFilter = tab.dataset.filter === 'all' ? 'all' : parseInt(tab.dataset.filter, 10);
                    renderReviewTabs();
                    renderReviewList();
                });
            });
        }

        document.getElementById('pmReviews').addEventListener('click', function () {
            if (!currentProvider || this.disabled) { return; }

            // Open the reviews modal IMMEDIATELY with a visible
            // loading state, then swap in the real content when
            // provider_reviews.php answers — so the wait is always
            // obvious instead of a silent delay before the modal.
            document.getElementById('revTitle').textContent = 'Reviews — ' + currentProvider.name;
            document.getElementById('revSubtitle').textContent = 'Loading reviews…';
            document.getElementById('revTabs').innerHTML = '';
            const revListEl = document.getElementById('revList');
            revListEl.innerHTML = '';
            revListEl.appendChild(
                window.islaLoading ? window.islaLoading.row('Loading reviews…')
                                   : document.createTextNode('Loading reviews…'));
            providerModal.hidden = true;
            openModal(reviewsModal);

            fetch('provider_reviews.php?provider_id=' + currentProvider.id, { credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.ok) { throw new Error('Reviews response was not ok'); }
                    reviewsData = data;
                    reviewsFilter = 'all';
                    document.getElementById('revSubtitle').textContent =
                        data.count + ' review' + (data.count === 1 ? '' : 's') + ' \u00b7 ' + data.avg + ' average';
                    renderReviewTabs();
                    renderReviewList();
                })
                .catch(function () {
                    document.getElementById('revList').innerHTML =
                        '<p class="rev-empty">Could not load the reviews — close this and try again.</p>';
                    document.getElementById('revSubtitle').textContent = 'Something went wrong';
                });
        });

        // Rate button -> opens the star-rating modal (post-service only,
        // triggered from the "Rate a completed service" prompt).
        document.querySelectorAll('.btn-rate').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('rateContractId').value = btn.dataset.contract;
                document.getElementById('rateTarget').textContent = 'Rate ' + btn.dataset.name + ' (completed job)';
                openModal(rateModal);
            });
        });

        // Close any modal via backdrop click or the X button.
        document.querySelectorAll('[data-close]').forEach(function (el) {
            el.addEventListener('click', closeModals);
        });

        // Star picker: tap a star to choose 1-5; the row fills gold up to it.
        const starButtons = document.querySelectorAll('#starPicker .star');
        function paintStars(value) {
            starButtons.forEach(function (s) {
                s.classList.toggle('on', parseInt(s.dataset.v, 10) <= value);
            });
        }
        starButtons.forEach(function (s) {
            s.addEventListener('click', function () {
                document.getElementById('ratingValue').value = s.dataset.v;
                paintStars(parseInt(s.dataset.v, 10));
            });
        });
        paintStars(5);   // default selection

        // Escape closes the overlays too.
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeModals();
        });

        // ============================================================
        // Privacy & Security sub-views.
        // The options list (Change Password / MFA / Device Logins)
        // shows only the three choices. Tapping one hides the whole
        // list and shows ONLY that option's content; "Back to security
        // options" returns to the list. Only one view is visible at a
        // time — choosing an option makes the others disappear.
        // ============================================================

        function showSecurityView(viewId) {
            const opts = document.getElementById('securityOptions');
            if (opts) { opts.hidden = viewId !== ''; }
            document.querySelectorAll('#dashSecurity .sec-view').forEach(function (view) {
                view.hidden = view.id !== viewId;
            });
            syncTrackHeight();
        }

        document.querySelectorAll('#dashSecurity [data-sec-view]').forEach(function (row) {
            row.addEventListener('click', function () {
                showSecurityView(row.dataset.secView);
            });
        });

        document.querySelectorAll('#dashSecurity [data-sec-back]').forEach(function (back) {
            back.addEventListener('click', function () {
                showSecurityView('');
            });
        });
    </script>
</body>
</html>
