<?php
// ============================================================
// report_listing.php — file a report against a listing
//
// The member-facing half of the moderation system: the form in
// dashboard.php's listing modal posts here, and an admin decides what
// to do with it in the admin panel.
//
// WHAT A REPORT IS NOT
// --------------------
// It is not a message to the listing owner and not a way to reach
// them — there is deliberately no owner contact anywhere in this
// flow, because a report that doubles as a way to harass an owner
// (or to expose a member's email to them) would be a feature nobody
// asked for. Everything filed here goes only to islaFIND staff.
//
// WHY EACH CHECK BELOW EXISTS
// ---------------------------
//   member session  — an anonymous visitor has no account to attach
//                     the report to, and an unauthenticated POST
//                     endpoint is a spam target.
//   CSRF            — the same reason as every other form here.
//   listing exists  — a report against nothing is not actionable.
//   not blocked     — a hidden listing cannot be seen, so it cannot
//                     be reported; otherwise the queue fills with
//                     noise about listings already dealt with.
//   not your own    — a member must not be able to have their own
//                     listing removed by reporting themselves.
//   reason is known — the submitted reason is checked against the
//                     whitelist in include/reporting.php, so a
//                     tampered value is refused rather than stored
//                     and later rendered as raw text by the admin
//                     panel.
//   "other" needs text — otherwise the queue gets a reason with no
//                     information in it.
//   one open report — you cannot stack five identical open reports on
//                     the same listing. A DISMISSED report does not
//                     block a new one: the rule is about open ones,
//                     so a member whose report was wrongly dismissed
//                     can report the same listing again later.
//   5 per hour      — a bored (or scripted) member cannot bury the
//                     real queue under noise.
//
// Evidence images are optional and validated exactly like every other
// upload in the app (isla_upload_validate → isla_upload_name →
// isla_upload_store), so a report can carry a screenshot without
// opening a new upload path.
// ============================================================

// --- 1. Session ------------------------------------------------
require_once __DIR__ . '/../include/security.php';
session_harden();
session_start();

// --- 2. Members only ------------------------------------------
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 3. Database + shared helpers -----------------------------
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/reporting.php';
require_once __DIR__ . '/../include/listing_visibility.php';
require_once __DIR__ . '/../include/uploads.php';

/**
 * report_back(): never returns
 * Show one message back on the dashboard and stop.
 *
 * Where it lands depends on `return_to`, which is validated against a
 * whitelist first: it decides a redirect, and an unchecked value here
 * would turn this page into an open redirect (a phishing link wearing
 * the app's own domain).
 *
 * @param string $type 'success' or 'error'.
 * @param string $msg  Message.
 * @param string $to   'home' or 'catalogue'.
 */
function report_back(string $type, string $msg, string $to = 'home'): void
{
    $_SESSION['flash_isla'] = ['type' => $type, 'msg' => $msg];
    // The catalogue is a PANEL of the Home tab, not a tab of its own -
    // there is no ?tab=directory, it falls through to Home - so
    // "back to the catalogue" is Home plus that panel's anchor.
    header('Location: ' . sid_append(
        $to === 'catalogue' ? 'dashboard.php?tab=home#feedCatalogue' : 'dashboard.php?tab=home'
    ));
    exit;
}

// --- 4. POST + CSRF -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    report_back('error', 'Reports have to be submitted from a listing.');
}

if (!csrf_check()) {
    report_back('error', 'Your session expired. Please try again.');
}

$returnTo = (($_POST['return_to'] ?? '') === 'catalogue') ? 'catalogue' : 'home';

// --- 5. Input --------------------------------------------------
$userId     = (int) $_SESSION['user_id'];
// Not (int): the cast would turn "5abc" into 5 and report the wrong
// listing without ever complaining. Anything not written as a plain
// number comes back as 0 and is refused by the check below.
$providerId = isla_post_int($_POST['provider_id'] ?? 0);
$reasonCode = trim((string) ($_POST['reason_code'] ?? ''));
$details    = trim((string) ($_POST['details'] ?? ''));

if ($providerId <= 0) {
    report_back('error', 'Which listing?', $returnTo);
}

if (!array_key_exists($reasonCode, isla_report_reasons())) {
    report_back('error', 'Please choose a reason for the report.', $returnTo);
}

// A TEXTAREA can be made to submit far more than the field's maxlength
// says, so the length is enforced here as well — this value is stored,
// shown to an admin, and re-rendered on a page that must never have to
// cope with a megabyte of text.
if (strlen($details) > 2000) {
    $details = substr($details, 0, 2000);
}

// Markup is refused as well as length-capped. The details are rendered
// escaped for the admin queue, so a <script> tag could not run there
// anyway; keeping it out of the table means it is also out of the copy
// an admin might paste into an email or a note elsewhere.
if (contains_html_tag($details)) {
    report_back('error', 'Your report cannot contain HTML tags or scripts.', $returnTo);
}

// "Something else" with nothing written is not a report.
if ($reasonCode === 'other' && strlen($details) < 10) {
    report_back('error', 'Please describe the problem so an admin can act on it.', $returnTo);
}

// --- 6. The listing -------------------------------------------
$stmt = $pdo->prepare(
    'SELECT p.id, p.user_id, p.status, p.name, p.selected_title, p.profile_type
       FROM providers p
       ' . isla_listing_live_join() . '
      WHERE p.id = :id AND ' . isla_listing_live_where() . '
      LIMIT 1'
);
$stmt->execute([':id' => $providerId]);
$listing = $stmt->fetch();

// No row means either the listing is gone or it is not discoverable —
// its own block, or its owner's account block (the JOIN above filters
// on both, see include/listing_visibility.php). The member is told the
// same thing either way, because from where they are standing the
// listing is simply not there.
if (!$listing) {
    report_back('error', 'That listing no longer exists.', $returnTo);
}

if ((string) $listing['status'] !== 'active') {
    report_back('error', 'That listing is not available.', $returnTo);
}

if ((int) $listing['user_id'] === $userId) {
    report_back('error', 'You cannot report your own listing.', $returnTo);
}

// --- 7. Duplicates and flooding -------------------------------
// One OPEN report per member per listing (see the file header for why
// this is checked in code instead of with a unique index).
$stmt = $pdo->prepare(
    "SELECT id
       FROM profile_reports
      WHERE provider_id = :pid AND reporter_id = :uid AND status = 'pending'
      LIMIT 1"
);
$stmt->execute([':pid' => $providerId, ':uid' => $userId]);

if ($stmt->fetchColumn()) {
    report_back('error', 'You have already reported this listing. An admin will review it.', $returnTo);
}

// A cap per hour, so one member cannot push a hundred listings into the
// queue in a minute and bury the genuine reports.
$stmt = $pdo->prepare(
    'SELECT COUNT(*)
       FROM profile_reports
      WHERE reporter_id = :uid AND created_at >= (NOW() - INTERVAL 1 HOUR)'
);
$stmt->execute([':uid' => $userId]);

if ((int) $stmt->fetchColumn() >= 5) {
    report_back('error', 'You have filed several reports already. Please wait a little before filing more.', $returnTo);
}

// --- 8. Evidence (optional) -----------------------------------
$evidenceName = null;

if (isset($_FILES['evidence']) && (string) ($_FILES['evidence']['name'] ?? '') !== '') {
    // 3 MB: a screenshot of the listing in a browser is well under that,
    // and a smaller ceiling keeps the uploads folder from filling with
    // full-resolution camera photos attached to a report.
    $check = isla_upload_validate($_FILES['evidence'], 3 * 1024 * 1024);

    if (!$check['ok']) {
        report_back('error', $check['error'], $returnTo);
    }

    $evidenceName = isla_upload_name('report', $userId, $check['ext']);

    if (!isla_upload_store($_FILES['evidence']['tmp_name'], $evidenceName)) {
        // The name is generated, so nothing half-written is left behind.
        report_back('error', 'Your screenshot could not be saved. Please try again, or send the report without it.', $returnTo);
    }
}

// --- 9. Store the report --------------------------------------
try {
    $stmt = $pdo->prepare(
        'INSERT INTO profile_reports
            (provider_id, reporter_id, reason_code, details, evidence_image, status)
         VALUES (:pid, :uid, :reason, :details, :evidence, \'pending\')'
    );
    $stmt->execute([
        ':pid'      => $providerId,
        ':uid'      => $userId,
        ':reason'   => $reasonCode,
        ':details'  => $details !== '' ? $details : null,
        ':evidence' => $evidenceName,
    ]);
} catch (PDOException $e) {
    // The screenshot is already on disk by this point, so it has to go
    // again — an insert that failed leaves an upload no report points at.
    if ($evidenceName !== null) {
        isla_upload_delete($evidenceName);
    }

    error_log('islaFIND report insert failed: ' . $e->getMessage());
    report_back('error', 'Your report could not be saved. Please try again.', $returnTo);
}

// --- 10. Done --------------------------------------------------
// Deliberately says nothing about the listing or its owner.
report_back('success', 'Thank you. Your report was sent to islaFIND and an admin will review it.');
