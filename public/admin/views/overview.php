<?php
// ============================================================
// admin/views/overview.php — the landing view
//
// Required by admin/index.php, which has already run the guard and
// built the data. Uses: $stats, $recentReports, $pendingCount,
// e(), adm_listing_title(), adm_time_ago().
// ============================================================

$statPending  = (int) ($stats['pending'] ?? 0);
$statBlocked  = (int) ($stats['blocked'] ?? 0);
$statLast24   = (int) ($stats['last24'] ?? 0);
$statResolv7  = (int) ($stats['resolved7'] ?? 0);
$statDismiss  = (int) ($stats['dismissed'] ?? 0);
$statTotalRep = (int) ($stats['total_reports'] ?? 0);
$statListings = (int) ($stats['total_listings'] ?? 0);
$statActive   = max(0, $statListings - $statBlocked);
?>
<div class="adm-stats">
    <!-- The one number that asks for action, so it is the one that is
         visually loud when it is non-zero. -->
    <a class="adm-stat<?php echo $statPending > 0 ? ' is-alert' : ''; ?>" href="index.php?view=reports&amp;status=pending">
        <div class="adm-stat-top">
            <div class="adm-stat-label">Awaiting review</div>
            <div class="adm-stat-icon is-amber">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
        </div>
        <div class="adm-stat-value"><?php echo $statPending; ?></div>
        <div class="adm-stat-hint">
            <?php echo $statPending === 0 ? 'The queue is clear.' : 'Reports members have filed'; ?>
        </div>
    </a>

    <div class="adm-stat">
        <div class="adm-stat-top">
            <div class="adm-stat-label">Filed in 24h</div>
            <div class="adm-stat-icon is-slate">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21v-2a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
        </div>
        <div class="adm-stat-value"><?php echo $statLast24; ?></div>
        <div class="adm-stat-hint"><?php echo $statTotalRep; ?> report<?php echo $statTotalRep === 1 ? '' : 's'; ?> in total</div>
    </div>

    <div class="adm-stat">
        <div class="adm-stat-top">
            <div class="adm-stat-label">Resolved in 7 days</div>
            <div class="adm-stat-icon is-green">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            </div>
        </div>
        <div class="adm-stat-value"><?php echo $statResolv7; ?></div>
        <div class="adm-stat-hint"><?php echo $statDismiss; ?> dismissed overall</div>
    </div>

    <div class="adm-stat">
        <div class="adm-stat-top">
            <div class="adm-stat-label">Blocked listings</div>
            <div class="adm-stat-icon is-coral">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
        </div>
        <div class="adm-stat-value"><?php echo $statBlocked; ?></div>
        <div class="adm-stat-hint">Hidden from every part of the app</div>
    </div>

    <div class="adm-stat">
        <div class="adm-stat-top">
            <div class="adm-stat-label">Live listings</div>
            <div class="adm-stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l9-9 9 9"/><path d="M9 21V9h6v12"/></svg>
            </div>
        </div>
        <div class="adm-stat-value"><?php echo $statActive; ?></div>
        <div class="adm-stat-hint">of <?php echo $statListings; ?> total</div>
    </div>
</div>

<div class="adm-panel">
    <div class="adm-panel-head">
        <div>
            <h2>Newest reports</h2>
            <p>The five most recent reports still waiting on a decision.</p>
        </div>
        <div class="adm-panel-head-actions">
            <a class="btn btn-small btn-outline" href="index.php?view=reports&amp;status=pending">See all <?php echo $statPending; ?></a>
        </div>
    </div>

    <?php if (empty($recentReports)): ?>
        <div class="adm-empty">
            <strong>Nothing waiting.</strong>
            Every report has been reviewed.
        </div>
    <?php else: ?>
        <div class="adm-panel-body is-flush">
            <div class="adm-table-wrap">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th scope="col">Listing</th>
                            <th scope="col">Reason</th>
                            <th scope="col">Reported by</th>
                            <th scope="col">When</th>
                            <th scope="col"><span class="adm-cell-actions">Review</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentReports as $r): ?>
                        <tr>
                            <td>
                                <span class="adm-primary"><?php echo e(adm_listing_title($r)); ?></span>
                                <span class="adm-secondary adm-ref"><?php echo e((string) ($r['profile_code'] ?: isla_profile_code((int) $r['provider_id']))); ?></span>
                            </td>
                            <td><?php echo e(isla_report_reason_label((string) $r['reason_code'])); ?></td>
                            <td><?php echo e((string) $r['reporter_name']); ?></td>
                            <td><?php echo e(adm_time_ago((string) $r['created_at'])); ?></td>
                            <td class="adm-cell-actions">
                                <a class="btn btn-small" href="index.php?view=report&amp;id=<?php echo (int) $r['id']; ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>