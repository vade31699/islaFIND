<?php
// ============================================================
// admin/index.php — the islaFIND superadmin panel
//
// Three views, one shell:
//
//   ?view=overview        counts + what needs attention today
//   ?view=reports&status= the moderation queue (pending / resolved /
//                         dismissed / all)
//   ?view=report&id=N     one report in full: who filed it, what
//                         they said, the listing as its owner has it,
//                         and the actions available on both
//
// Every state change goes through admin/action.php (POST + CSRF), so
// this file only ever reads and renders.
//
// The guard below is the important line: admin_require_login() refuses
// a member session, a stale admin_id, and a deactivated account, and
// sends anybody else to the login form with ?admin=1.
// ============================================================

// --- 1. Session + database -------------------------------------
require_once __DIR__ . '/../../include/security.php';
session_harden();
session_start();

require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/admin_auth.php';
require_once __DIR__ . '/../../include/reporting.php';   // reason labels + IslaProfile ID
require_once __DIR__ . '/../../include/categories.php';  // slug -> label maps
require_once __DIR__ . '/../../include/uploads.php';     // picture URLs

// --- 2. The guard. Nothing below runs unless this returns. -----
$admin = admin_require_login($pdo);

// --- 3. One-shot message from the last action ------------------
// Set by admin/action.php, shown once, then forgotten — a refresh must
// not replay "listing blocked".
$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

// --- 4. Which view ---------------------------------------------
$view = (string) ($_GET['view'] ?? 'overview');
if (!in_array($view, ['overview', 'reports', 'report'], true)) {
    $view = 'overview';
}

// The pending count rides on the Reports link from every page, so a
// growing queue is visible without opening it.
$pendingCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM profile_reports WHERE status = 'pending'"
)->fetchColumn();

/**
 * adm_upload_url(string $nameOrUrl): string
 * A stored upload's URL, resolved for a page inside public/admin/.
 *
 * isla_upload_url() returns a path relative to the WEB ROOT ("uploads/x.jpg"),
 * which is correct for a page in public/ and wrong by one level here — the
 * browser would ask public/admin/uploads/x.jpg and get a 404 for every
 * picture in this panel. One helper fixes that in a single place instead
 * of remembering the prefix at each call site.
 *
 * Accepts either a bare stored filename OR a URL that has already been
 * through isla_upload_url() (as isla_listing_photo_src() returns), and
 * leaves an absolute URL alone — which is what keeps a deployment serving
 * uploads from a CDN or bucket working untouched.
 *
 * @param string $nameOrUrl Bare stored filename, or a resolved URL.
 * @return string URL for an <img src>.
 */
function adm_upload_url(string $nameOrUrl): string
{
    if (preg_match('#^(https?:)?//#', $nameOrUrl) === 1) {
        return $nameOrUrl;              // already absolute
    }

    $url = isla_upload_url($nameOrUrl); // basename() + rawurlencode() inside

    return preg_match('#^(https?:)?//#', $url) === 1 ? $url : '../' . $url;
}

/**
 * adm_time_ago(?string $when): string
 * "just now" / "12 minutes ago" / "3 days ago", for the queue columns.
 * Falls back to the raw value when the driver hands back something
 * unexpected, so a timestamp is never rendered as the word "null".
 *
 * @param string|null $when MySQL datetime.
 * @return string
 */
function adm_time_ago(?string $when): string
{
    if ($when === null || $when === '') {
        return '—';
    }

    $ts = strtotime($when);
    if ($ts === false) {
        return $when;
    }

    $diff = max(0, time() - $ts);
    if ($diff < 60)      { return 'just now'; }
    if ($diff < 3600)    { $m = intdiv($diff, 60);  return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago'; }
    if ($diff < 86400)   { $h = intdiv($diff, 3600); return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago'; }
    $d = intdiv($diff, 86400);
    if ($d < 30)         { return $d . ' day' . ($d === 1 ? '' : 's') . ' ago'; }

    return date('j M Y', $ts);
}

/**
 * adm_listing_title(array $row): string
 * How a listing is named in the panel: a business by its business name,
 * an individual by their category ("Plumber, Santa Fe"). Mirrors the rule
 * the member app uses to title a card, so the admin sees the same thing
 * the member who filed the report saw.
 *
 * @param array $row A row with profile_type, name, selected_title, municipality.
 * @return string
 */
function adm_listing_title(array $row): string
{
    global $providerCategories;

    $label = $providerCategories[$row['selected_title'] ?? ''] ?? ($row['selected_title'] ?? 'Listing');

    if (($row['profile_type'] ?? '') === 'business') {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            $place = trim((string) ($row['municipality'] ?? ''));
            return $name . ($place !== '' ? ' — ' . $label . ', ' . $place : ' — ' . $label);
        }
    }

    $place = trim((string) ($row['municipality'] ?? ''));
    return $label . ($place !== '' ? ', ' . $place : '');
}

// ------------------------------------------------------------
// 5a. Overview data
// ------------------------------------------------------------
$stats = [];
$recentReports = [];

if ($view === 'overview') {
    // One round trip for every headline number. Counting in SQL keeps the
    // page correct on a large database, where pulling rows to count them in
    // PHP would eventually be the slowest thing the panel does.
    $stats = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM profile_reports WHERE status = 'pending')       AS pending,
            (SELECT COUNT(*) FROM profile_reports
                WHERE created_at >= (NOW() - INTERVAL 24 HOUR))                    AS last24,
            (SELECT COUNT(*) FROM profile_reports
                WHERE status = 'resolved' AND resolved_at >= (NOW() - INTERVAL 7 DAY)) AS resolved7,
            (SELECT COUNT(*) FROM profile_reports WHERE status = 'dismissed')       AS dismissed,
            (SELECT COUNT(*) FROM profile_reports)                                  AS total_reports,
            (SELECT COUNT(*) FROM providers WHERE status = 'blocked')               AS blocked,
            (SELECT COUNT(*) FROM providers)                                        AS total_listings"
    )->fetch() ?: [];

    // The five newest pending reports: enough to work through without
    // leaving the overview, with the queue one click away for the rest.
    $recentReports = $pdo->query(
        "SELECT r.id, r.reason_code, r.created_at, r.status,
                p.id AS provider_id, p.profile_code, p.name, p.selected_title,
                p.profile_type, p.municipality,
                u.full_name AS reporter_name
           FROM profile_reports r
           JOIN providers p ON p.id = r.provider_id
           JOIN users     u ON u.id = r.reporter_id
          WHERE r.status = 'pending'
          ORDER BY r.created_at DESC
          LIMIT 5"
    )->fetchAll();
}

// ------------------------------------------------------------
// 5b. Queue data
// ------------------------------------------------------------
$statusFilter = (string) ($_GET['status'] ?? 'pending');
if (!in_array($statusFilter, ['pending', 'resolved', 'dismissed', 'all'], true)) {
    $statusFilter = 'pending';
}

$queue = [];
$queueCounts = ['pending' => 0, 'resolved' => 0, 'dismissed' => 0];

if ($view === 'reports') {
    // Tab counters, from one grouped query.
    foreach ($pdo->query(
        'SELECT status, COUNT(*) AS n FROM profile_reports GROUP BY status'
    )->fetchAll() as $row) {
        $queueCounts[$row['status']] = (int) $row['n'];
    }

    // The filter is validated against a whitelist above, so it is safe to
    // splice into SQL — and it is a bound value anyway, which is what keeps
    // this from being an injection point if the whitelist is ever loosened.
    $sql = "SELECT r.id, r.reason_code, r.status, r.created_at, r.listing_blocked,
                   p.id AS provider_id, p.profile_code, p.name, p.selected_title,
                   p.profile_type, p.municipality, p.status AS listing_status,
                   u.full_name AS reporter_name
              FROM profile_reports r
              JOIN providers p ON p.id = r.provider_id
              JOIN users     u ON u.id = r.reporter_id";
    $params = [];

    if ($statusFilter !== 'all') {
        $sql .= ' WHERE r.status = :st';
        $params[':st'] = $statusFilter;
    }

    // Open reports first, newest within each group: the queue is a work
    // list, and an unresolved report is always more urgent than a closed one.
    // LIMIT keeps a long history from turning this page into a slow dump —
    // the filters are how you find something older than 200 rows.
    $sql .= ' ORDER BY (r.status = \'pending\') DESC, r.created_at DESC LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $queue = $stmt->fetchAll();
}

// ------------------------------------------------------------
// 5c. One report, in full
// ------------------------------------------------------------
$report       = null;
$listing      = null;
$owner        = null;
$ownerOther   = [];
$listingOther = [];
$reporter     = null;

if ($view === 'report') {
    $reportId = (int) ($_GET['id'] ?? 0);

    if ($reportId > 0) {
        $stmt = $pdo->prepare(
            'SELECT r.*, p.profile_code, p.name, p.selected_title, p.profile_type,
                    p.status AS listing_status, p.municipality, p.barangay,
                    p.blocked_at, p.blocked_reason
               FROM profile_reports r
               JOIN providers p ON p.id = r.provider_id
              WHERE r.id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $reportId]);
        $report = $stmt->fetch() ?: null;

        if ($report !== null) {
            $providerId = (int) $report['provider_id'];

            // The listing exactly as it is stored — the admin judges what is
            // really being shown to members, not what was reported about.
            $stmt = $pdo->prepare(
                'SELECT p.*, u.full_name AS owner_name, u.email AS owner_email,
                        u.phone AS owner_phone, u.user_id AS owner_code,
                        u.profile_picture AS account_photo,
                        u.created_at AS owner_since
                   FROM providers p
                   JOIN users u ON u.id = p.user_id
                  WHERE p.id = :id
                  LIMIT 1'
            );
            $stmt->execute([':id' => $providerId]);
            $listing = $stmt->fetch() ?: null;

            // The member who filed it, for context on the reporter.
            $stmt = $pdo->prepare('SELECT id, full_name, email, created_at FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (int) $report['reporter_id']]);
            $reporter = $stmt->fetch() ?: null;

            // Moderation context: does this listing have a history, and does
            // this owner have others? Both change how seriously to take a
            // single report, so both are on the page.
            $stmt = $pdo->prepare(
                'SELECT id, reason_code, status, created_at
                   FROM profile_reports
                  WHERE provider_id = :pid AND id <> :id
                  ORDER BY created_at DESC
                  LIMIT 10'
            );
            $stmt->execute([':pid' => $providerId, ':id' => $reportId]);
            $listingOther = $stmt->fetchAll();

            if ($listing !== null) {
                $stmt = $pdo->prepare(
                    'SELECT id, profile_code, name, selected_title, profile_type,
                            municipality, status, created_at
                       FROM providers
                      WHERE user_id = :uid AND id <> :id
                      ORDER BY created_at DESC
                      LIMIT 10'
                );
                $stmt->execute([
                    ':uid' => (int) $listing['user_id'],
                    ':id'  => $providerId,
                ]);
                $ownerOther = $stmt->fetchAll();
            }
        }
    }
}

// ------------------------------------------------------------
// 6. Page furniture
// ------------------------------------------------------------
$pageTitle    = 'Overview';
$pageSubtitle = 'How islaFIND is doing right now.';

if ($view === 'reports') {
    $pageTitle    = 'Reported Listings';
    $pageSubtitle = 'Everything members have reported, newest first.';
} elseif ($view === 'report') {
    $pageTitle    = 'Report #' . ($report !== null ? (int) $report['id'] : '');
    $pageSubtitle = $report !== null
        ? isla_report_reason_label((string) $report['reason_code'])
        : 'That report could not be found.';
}

// The nav item that should read as current.
$activeNav = $view === 'overview' ? 'overview' : 'reports';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- No index, no follow: a moderation queue must never end up in a
         search result, and the listing pages it links to are the app's. -->
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
            <a class="adm-nav-link<?php echo $activeNav === 'overview' ? ' is-active' : ''; ?>" href="index.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
                Overview
            </a>
            <a class="adm-nav-link<?php echo $activeNav === 'reports' ? ' is-active' : ''; ?>" href="index.php?view=reports&amp;status=pending">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21v-2a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Reports
                <?php if ($pendingCount > 0): ?>
                    <span class="adm-nav-count"><?php echo (int) $pendingCount; ?></span>
                <?php endif; ?>
            </a>
            <a class="adm-nav-link<?php echo ($_SERVER['SCRIPT_NAME'] ?? '') !== '' && str_ends_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), 'settings.php') ? ' is-active' : ''; ?>" href="settings.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.2.61.77 1.02 1.41 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
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
            <!-- POST, not a link: signing out is a state change, and a GET
                 link would let any page log an admin out with an <img>. -->
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
            <?php if ($view === 'overview'): ?>
            <div class="adm-top-actions">
                <a class="btn btn-small" href="index.php?view=reports&amp;status=pending">Open the queue</a>
            </div>
            <?php endif; ?>
        </header>

        <div class="adm-body">

            <?php if ($flash !== null): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'error' : 'success'; ?>" role="status">
                    <?php echo e($flash['msg']); ?>
                </div>
            <?php endif; ?>

            <?php if ($view === 'overview'): ?>
                <?php require __DIR__ . '/views/overview.php'; ?>

            <?php elseif ($view === 'reports'): ?>
                <?php require __DIR__ . '/views/reports.php'; ?>

            <?php else: ?>
                <?php require __DIR__ . '/views/report.php'; ?>
            <?php endif; ?>

        </div>
    </main>
</div>
</body>
</html>