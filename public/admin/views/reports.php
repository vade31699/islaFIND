<?php
// ============================================================
// admin/views/reports.php — the moderation queue
//
// Required by admin/index.php, which has already run the guard and
// built the data. Uses: $queue, $queueCounts, $statusFilter,
// e(), adm_listing_title(), adm_time_ago().
// ============================================================

$tabs = [
    'pending'   => 'Awaiting review',
    'resolved'  => 'Resolved',
    'dismissed' => 'Dismissed',
    'all'       => 'All',
];
?>
<div class="adm-panel">
    <div class="adm-panel-head">
        <div class="adm-tabs">
            <?php foreach ($tabs as $key => $label): ?>
                <a class="adm-tab<?php echo $statusFilter === $key ? ' is-active' : ''; ?>"
                   href="index.php?view=reports&amp;status=<?php echo e($key); ?>">
                    <?php echo e($label); ?>
                    <span class="adm-tab-n">
                        <?php echo $key === 'all' ? array_sum($queueCounts) : (int) $queueCounts[$key]; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="adm-panel-head-actions">
            <span class="adm-secondary is-flat">Newest first &middot; <?php echo count($queue); ?> shown</span>
        </div>
    </div>

    <?php if (empty($queue)): ?>
        <div class="adm-empty">
            <strong>No reports here.</strong>
            <?php echo $statusFilter === 'pending'
                ? 'Nothing is waiting on a decision right now.'
                : 'Nothing has been filed under this filter.'; ?>
        </div>
    <?php else: ?>
        <div class="adm-panel-body is-flush">
            <div class="adm-table-wrap">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th scope="col">Listing</th>
                            <th scope="col">Reason</th>
                            <th scope="col">Report</th>
                            <th scope="col">Listing state</th>
                            <th scope="col">Reported by</th>
                            <th scope="col">When</th>
                            <th scope="col"><span class="adm-cell-actions">Review</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    // Anything still open after a day gets the urgent accent.
                    // The threshold lives here rather than in SQL so it is one
                    // number in one place and readable next to the row it
                    // colours.
                    $urgentCutoff = time() - 86400;
                    foreach ($queue as $r):
                        $rowStatus = (string) $r['status'];
                        $isBlocked = (string) $r['listing_status'] === 'blocked';
                        $hasPhoto  = isset($r['evidence_image']) && trim((string) $r['evidence_image']) !== '';
                        $isUrgent  = $rowStatus === 'pending'
                                  && strtotime((string) $r['created_at']) < $urgentCutoff;
                        ?>
                        <tr class="is-linked<?php echo $isUrgent ? ' is-urgent' : ''; ?>">
                            <td>
                                <span class="adm-primary"><?php echo e(adm_listing_title($r)); ?></span>
                                <span class="adm-secondary adm-ref"><?php echo e((string) ($r['profile_code'] ?: isla_profile_code((int) $r['provider_id']))); ?>
                                    <?php echo $hasPhoto ? ' · has evidence' : ''; ?>
                                </span>
                            </td>
                            <td>
                                <?php echo e(isla_report_reason_label((string) $r['reason_code'])); ?>
                                <?php if ((int) $r['listing_blocked'] === 1): ?>
                                    <span class="adm-secondary">Listing was blocked</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($rowStatus === 'pending'): ?>
                                    <span class="adm-badge adm-badge-pending"><span class="adm-badge-dot"></span>Open</span>
                                <?php elseif ($rowStatus === 'resolved'): ?>
                                    <span class="adm-badge adm-badge-resolved"><span class="adm-badge-dot"></span>Resolved</span>
                                <?php else: ?>
                                    <span class="adm-badge adm-badge-dismissed"><span class="adm-badge-dot"></span>Dismissed</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isBlocked): ?>
                                    <span class="adm-badge adm-badge-blocked"><span class="adm-badge-dot"></span>Blocked</span>
                                <?php else: ?>
                                    <span class="adm-badge adm-badge-active"><span class="adm-badge-dot"></span>Live</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo e((string) $r['reporter_name']); ?></td>
                            <td><?php echo e(adm_time_ago((string) $r['created_at'])); ?></td>
                            <td class="adm-cell-actions">
                                <a class="btn btn-small adm-rowlink<?php echo $rowStatus === 'pending' ? '' : ' btn-outline'; ?>"
                                   href="index.php?view=report&amp;id=<?php echo (int) $r['id']; ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (count($queue) >= 200): ?>
            <div class="adm-panel-body">
                <p class="adm-secondary is-flat">
                    Showing the newest 200. Narrow the filter to see anything older.
                </p>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>