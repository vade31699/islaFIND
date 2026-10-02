<?php
// ============================================================
// admin/action.php — every superadmin state change
//
// One endpoint for all of them, on purpose: each action is a POST that
// carries a CSRF token and is checked against a whitelist below, so
// there is exactly one place where "is this allowed?" is answered.
//
//   do=block_listing   provider_id, reason
//   do=unblock_listing provider_id
//   do=block_account   owner_id, reason, report_id (to come back to)
//   do=unblock_account owner_id, report_id
//   do=resolve_report  report_id, outcome=resolved, notes
//   do=dismiss_report  report_id, outcome=dismissed, notes
//   do=save_note       report_id, notes
//
// Nothing here renders: it validates, changes the database, sets one
// flash message and redirects. A refresh after a decision re-reads the
// report instead of deciding again.
// ============================================================

require_once __DIR__ . '/../../include/security.php';
session_harden();
session_start();

require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/admin_auth.php';
require_once __DIR__ . '/../../include/reporting.php';
// NOTE: uploads.php is deliberately NOT loaded here. Evidence files are
// kept after a report closes - the row's evidence_path stays readable so
// a later audit can still see what was submitted, and this file used to
// require a path that does not exist (public/uploads.php), which would
// have fataled on every moderation action.

$admin = admin_require_login($pdo);

/**
 * adm_redirect(string $url): never returns
 * Flash a message and leave. Every path through this file ends here, so
 * the flash can never be left unconsumed and the PRG pattern holds.
 *
 * @param string $type 'success' or 'error'.
 * @param string $msg  Message shown once.
 * @param string $url  Where to go.
 */
function adm_redirect(string $type, string $msg, string $url): void
{
    $_SESSION['admin_flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: ' . $url);
    exit;
}

// --- 1. POST only ----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    adm_redirect('error', 'That action has to be submitted from the form.', 'index.php');
}

// --- 2. CSRF --------------------------------------------------
// Checked before anything is read or written. A rejected token changes
// nothing and says nothing about why.
if (!csrf_check()) {
    adm_redirect('error', 'Your session expired. Please try that again.', 'index.php');
}

$action = (string) ($_POST['do'] ?? '');

$allowedActions = [
    'block_listing', 'unblock_listing',
    'block_account', 'unblock_account',
    'resolve_report', 'dismiss_report', 'save_note',
];
if (!in_array($action, $allowedActions, true)) {
    adm_redirect('error', 'Unknown action.', 'index.php');
}

// --- 3. Block a listing ---------------------------------------
if ($action === 'block_listing') {
    $providerId = (int) ($_POST['provider_id'] ?? 0);
    $reason     = trim((string) ($_POST['reason'] ?? ''));

    if ($providerId <= 0) {
        adm_redirect('error', 'Which listing?', 'index.php');
    }
    if (strlen($reason) > 255) {
        $reason = substr($reason, 0, 255);
    }
    if ($reason === '') {
        // A block with no recorded reason is one nobody can appeal
        // sensibly, so it falls back to the report that caused it.
        $reason = 'Reported listing';
    }

    $stmt = $pdo->prepare(
        "UPDATE providers
            SET status        = 'blocked',
                blocked_at    = NOW(),
                blocked_reason = :reason
          WHERE id = :id"
    );
    $stmt->execute([':reason' => $reason, ':id' => $providerId]);

    if ($stmt->rowCount() === 0) {
        // Either no such listing, or it was already blocked. The first is
        // the only case worth a message.
        $check = $pdo->prepare('SELECT status FROM providers WHERE id = :id LIMIT 1');
        $check->execute([':id' => $providerId]);
        if ($check->fetchColumn() === false) {
            adm_redirect('error', 'That listing no longer exists.', 'index.php');
        }
        adm_redirect('error', 'That listing is already blocked.', 'index.php?view=reports&status=pending');
    }

    // Blocking answers every OPEN report on the same listing: the admin
    // acted on the listing itself, so leaving those reports pending would
    // put the queue out of step with reality.
    $stmt = $pdo->prepare(
        "UPDATE profile_reports
            SET status          = 'resolved',
                listing_blocked = 1,
                admin_notes     = CASE
                                   WHEN admin_notes IS NULL OR admin_notes = ''
                                   THEN CONCAT('Listing blocked by admin: ', :reason)
                                   ELSE admin_notes
                                 END,
                resolved_by     = :admin,
                resolved_at     = NOW()
          WHERE provider_id = :pid AND status = 'pending'"
    );
    $stmt->execute([
        ':reason' => $reason,
        ':admin'  => (int) $admin['id'],
        ':pid'    => $providerId,
    ]);
    $closed = $stmt->rowCount();

    adm_redirect(
        'success',
        'Listing blocked and hidden from the app.'
        . ($closed > 0 ? ' ' . $closed . ' open report(s) on it resolved.' : ''),
        'index.php?view=reports&status=pending'
    );
}

// --- 4. Unblock a listing -------------------------------------
if ($action === 'unblock_listing') {
    $providerId = (int) ($_POST['provider_id'] ?? 0);

    if ($providerId <= 0) {
        adm_redirect('error', 'Which listing?', 'index.php');
    }

    $stmt = $pdo->prepare(
        "UPDATE providers
            SET status         = 'active',
                blocked_at     = NULL,
                blocked_reason = NULL
          WHERE id = :id AND status = 'blocked'"
    );
    $stmt->execute([':id' => $providerId]);

    if ($stmt->rowCount() === 0) {
        adm_redirect('error', 'That listing is not blocked (or no longer exists).', 'index.php');
    }

    // The block record goes with it: once the listing is live again, the
    // reason it was hidden is stale on the listing. The reports keep their
    // own history, which is where the record belongs.
    adm_redirect('success', 'Listing restored to the feed.', 'index.php?view=reports&status=pending');
}

// --- 4b. Block the OWNER's account ----------------------------
// The bigger hammer, next to the listing block above. Where that hides
// one listing, this closes the whole account: the sign-in is refused
// (login.php), any session already open is dropped
// (include/member_guard.php) and every listing they own leaves
// discovery, because the public reads filter on the owner's status
// (include/listing_visibility.php).
//
// Not one providers row is touched, which is the point: the listings
// keep their own status, photos and history, so unblocking restores all
// of them exactly as they were — no "which ones did the block hide?"
// bookkeeping.
if ($action === 'block_account') {
    $ownerId  = (int) ($_POST['owner_id'] ?? 0);
    $reportId = (int) ($_POST['report_id'] ?? 0);
    $reason   = trim((string) ($_POST['reason'] ?? ''));

    if ($ownerId <= 0) {
        adm_redirect('error', 'Which account?', 'index.php');
    }
    if (strlen($reason) > 255) {
        $reason = substr($reason, 0, 255);
    }
    if ($reason === '') {
        // Same reasoning as a listing block: a block with no recorded
        // reason is one nobody can appeal sensibly.
        $reason = 'Repeated or serious reports';
    }

    $stmt = $pdo->prepare(
        "UPDATE users
            SET status         = 'blocked',
                blocked_at     = NOW(),
                blocked_reason = :reason
          WHERE id = :id AND status <> 'blocked'"
    );
    $stmt->execute([':reason' => $reason, ':id' => $ownerId]);

    if ($stmt->rowCount() === 0) {
        // Either no such account, or it was already blocked. Only the
        // first is worth a message.
        $check = $pdo->prepare('SELECT status FROM users WHERE id = :id LIMIT 1');
        $check->execute([':id' => $ownerId]);
        if ($check->fetchColumn() === false) {
            adm_redirect('error', 'That account no longer exists.', 'index.php');
        }
        adm_redirect('error', 'That account is already blocked.', 'index.php');
    }

    // Every open report on anything this account owns is answered by the
    // block, for the same reason a listing block answers the reports on
    // its listing: the admin acted on the thing being reported, and
    // leaving those rows pending would put the queue out of step with
    // reality. `listing_blocked` is deliberately NOT set — it means "this
    // listing was blocked", and no listing was.
    $stmt = $pdo->prepare(
        "UPDATE profile_reports r
            JOIN providers p ON p.id = r.provider_id
            SET r.status      = 'resolved',
                r.admin_notes = CASE
                                  WHEN r.admin_notes IS NULL OR r.admin_notes = ''
                                  THEN CONCAT('Owner account blocked by admin: ', :reason)
                                  ELSE r.admin_notes
                                END,
                r.resolved_by = :admin,
                r.resolved_at = NOW()
          WHERE p.user_id = :owner AND r.status = 'pending'"
    );
    $stmt->execute([
        ':reason' => $reason,
        ':admin'  => (int) $admin['id'],
        ':owner'  => $ownerId,
    ]);
    $closed = $stmt->rowCount();

    // Back to the report the admin was reading, where the account's new
    // state is on screen — falling back to the queue when the action was
    // fired from somewhere without one.
    adm_redirect(
        'success',
        'Account blocked: sign-in refused, session ended, and every listing it owns is hidden.'
        . ($closed > 0 ? ' ' . $closed . ' open report(s) resolved.' : ''),
        $reportId > 0 ? 'index.php?view=report&id=' . $reportId : 'index.php?view=reports&status=pending'
    );
}

// --- 4c. Unblock an account -----------------------------------
if ($action === 'unblock_account') {
    $ownerId  = (int) ($_POST['owner_id'] ?? 0);
    $reportId = (int) ($_POST['report_id'] ?? 0);

    if ($ownerId <= 0) {
        adm_redirect('error', 'Which account?', 'index.php');
    }

    $stmt = $pdo->prepare(
        "UPDATE users
            SET status         = 'active',
                blocked_at     = NULL,
                blocked_reason = NULL
          WHERE id = :id AND status = 'blocked'"
    );
    $stmt->execute([':id' => $ownerId]);

    if ($stmt->rowCount() === 0) {
        adm_redirect('error', 'That account is not blocked (or no longer exists).', 'index.php');
    }

    // Nothing else to undo: the listings were never touched. Reports
    // resolved by the block stay resolved — reopening the queue is a
    // second decision, not a side effect of this one.
    adm_redirect(
        'success',
        'Account restored. Its listings are live again, and the owner can sign in.',
        $reportId > 0 ? 'index.php?view=report&id=' . $reportId : 'index.php?view=reports&status=pending'
    );
}

// --- 5. Report outcomes ---------------------------------------
if ($action === 'resolve_report' || $action === 'dismiss_report') {
    $reportId = (int) ($_POST['report_id'] ?? 0);
    $outcome  = (string) ($_POST['outcome'] ?? '');
    $notes    = trim((string) ($_POST['notes'] ?? ''));

    // The submit button's value is what decided the outcome; the hidden
    // `do` is only a fallback, and both are validated rather than trusted.
    $outcome = in_array($outcome, ['resolved', 'dismissed'], true) ? $outcome : 'resolved';
    if ($action === 'dismiss_report') {
        $outcome = 'dismissed';
    }

    if ($reportId <= 0) {
        adm_redirect('error', 'Which report?', 'index.php?view=reports&status=pending');
    }

    $notes = substr($notes, 0, 2000);

    $stmt = $pdo->prepare(
        'UPDATE profile_reports
            SET status      = :status,
                admin_notes = :notes,
                resolved_by = :admin,
                resolved_at = NOW()
          WHERE id = :id'
    );
    $stmt->execute([
        ':status' => $outcome,
        ':notes'  => $notes !== '' ? $notes : null,
        ':admin'  => (int) $admin['id'],
        ':id'     => $reportId,
    ]);

    if ($stmt->rowCount() === 0) {
        // Nothing changed: either the report is gone, or it already said
        // exactly this. Both are fine — say so plainly.
        adm_redirect('error', 'That report was already closed the same way.', 'index.php?view=reports&status=' . $outcome);
    }

    adm_redirect(
        'success',
        $outcome === 'resolved' ? 'Report marked resolved.' : 'Report dismissed.',
        'index.php?view=reports&status=' . $outcome
    );
}

// --- 6. Notes only --------------------------------------------
// Available on a closed report: an admin revisiting an old decision can
// record what they learned without reopening it.
$reportId = (int) ($_POST['report_id'] ?? 0);
$notes    = substr(trim((string) ($_POST['notes'] ?? '')), 0, 2000);

if ($reportId <= 0) {
    adm_redirect('error', 'Which report?', 'index.php?view=reports&status=all');
}

$stmt = $pdo->prepare('UPDATE profile_reports SET admin_notes = :notes WHERE id = :id');
$stmt->execute([':notes' => $notes !== '' ? $notes : null, ':id' => $reportId]);

adm_redirect('success', 'Note saved.', 'index.php?view=report&id=' . $reportId);