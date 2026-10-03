<?php
// admin/views/reports.php — the moderation queue
// Uses: $queue, $queueCounts, $statusFilter, $providerFilter,
// $searchQuery, $queueListing, e(), adm_listing_title(), adm_time_ago().

$tabs = [
    'pending'   => 'Awaiting review',
    'resolved'  => 'Resolved',
    'dismissed' => 'Dismissed',
    'all'       => 'All',
];

// The filters that have to survive a tab click, already escaped for
// use inside an href. The search term is the one a tab must never
// silently drop: an operator who searched a person and then clicked
// "Resolved" is asking for that person's resolved reports, not for
// the whole resolved queue.
$providerQuery = $providerFilter > 0 ? '&amp;provider=' . (int) $providerFilter : '';
$searchQueryParam = $searchQuery !== '' ? '&amp;q=' . rawurlencode($searchQuery) : '';

// Tab links carry the search term through, and the Clear button on the
// active-search chip drops it while keeping the status tab.
$tabQuery = $providerQuery . $searchQueryParam;
$clearSearchHref = 'index.php?view=reports&amp;status=' . rawurlencode($statusFilter) . $providerQuery;
?>
<div class="adm-search">
    <!-- Free-text search across the four things an operator is handed:
         a listing's IslaProfile ID, and the email or member id of
         either the reported owner or the member who filed the report.
         One box rather than four fields, because the operator does not
         know which of those they were given. -->
    <form action="index.php" method="GET" role="search">
        <input type="hidden" name="view" value="reports">
        <input type="hidden" name="status" value="<?php echo e($statusFilter); ?>">
        <?php if ($providerFilter > 0): ?>
            <input type="hidden" name="provider" value="<?php echo (int) $providerFilter; ?>">
        <?php endif; ?>
        <label class="adm-search-field">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input type="search" name="q" value="<?php echo e($searchQuery); ?>"
                   placeholder="Search profile ID, email or member ID…"
                   aria-label="Search reports by profile ID, email or member ID"
                   autocomplete="off" maxlength="120">
        </label>
        <button type="submit" class="btn btn-small">Search</button>
        <?php if ($searchQuery !== ''): ?>
            <a class="btn btn-small btn-outline" href="<?php echo e($clearSearchHref); ?>">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($searchQuery !== ''): ?>
        <span class="adm-search-note">
            Showing matches for <strong><?php echo e($searchQuery); ?></strong>
            · <?php echo count($queue); ?> found
        </span>
    <?php endif; ?>
</div>
<div class="adm-panel">
    <div class="adm-panel-head">
        <div class="adm-tabs">
            <?php foreach ($tabs as $key => $label): ?>
                <a class="adm-tab<?php echo $statusFilter === $key ? ' is-active' : ''; ?>"
                   href="index.php?view=reports&amp;status=<?php echo e($key); ?><?php echo $tabQuery; ?>">
                    <?php echo e($label); ?>
                    <span class="adm-tab-n">
                        <?php echo $key === 'all' ? array_sum($queueCounts) : (int) $queueCounts[$key]; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="adm-panel-head-actions">
            <?php if ($queueListing !== null): ?>
                <span class="adm-secondary is-flat">
                    Only <?php echo e(adm_listing_title($queueListing)); ?>
                    <a href="index.php?view=reports&amp;status=<?php echo e($statusFilter); ?><?php echo $searchQueryParam; ?>">Clear</a>
                </span>
            <?php endif; ?>
            <span class="adm-secondary is-flat">Newest first &middot; <?php echo count($queue); ?> shown</span>
        </div>
    </div>

    <?php if (empty($queue)): ?>
        <div class="adm-empty">
            <strong>No reports here.</strong>
            <?php if ($queueListing !== null): ?>
                Nothing has been filed against this listing under this filter.
                <a href="index.php?view=reports&amp;status=<?php echo e($statusFilter); ?><?php echo $searchQueryParam; ?>">Show the whole queue</a>.
            <?php elseif ($searchQuery !== ''): ?>
                Nothing matches <strong><?php echo e($searchQuery); ?></strong> under this filter.
                Try the <a href="index.php?view=reports&amp;status=all<?php echo $searchQueryParam; ?>">All</a> tab, or
                <a href="index.php?view=reports&amp;status=<?php echo e($statusFilter); ?><?php echo $providerQuery; ?>">clear the search</a>.
            <?php else: ?>
                <?php echo $statusFilter === 'pending'
                    ? 'Nothing is waiting on a decision right now.'
                    : 'Nothing has been filed under this filter.'; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="adm-panel-body is-flush">
            <div class="adm-table-wrap">
                <table class="adm-table is-queue">
                    <thead>
                        <tr>
                            <th scope="col">Listing</th>
                            <th scope="col">Owner</th>
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
                                <?php $ownerBlocked = (string) $r['owner_status'] === 'blocked'; ?>
                                <span class="adm-primary"><?php echo e((string) $r['owner_name']); ?></span>
                                <?php if ($ownerBlocked): ?>
                                    <span class="adm-secondary">
                                        Account blocked
                                        <?php if (trim((string) ($r['owner_blocked_reason'] ?? '')) !== ''): ?>
                                            — <?php echo e((string) $r['owner_blocked_reason']); ?>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="adm-secondary">Owns this listing</span>
                                <?php endif; ?>
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
                                <div class="adm-row-actions">
                                    <a class="btn btn-small adm-rowlink<?php echo $rowStatus === 'pending' ? '' : ' btn-outline'; ?>"
                                       href="index.php?view=report&amp;id=<?php echo (int) $r['id']; ?>">Open</a>

                                    <?php if ($ownerBlocked): ?>
                                    <form action="action.php" method="POST"
                                          onsubmit="return confirm('Restore this account? They will be able to sign in again, and their listings return to the app.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                        <input type="hidden" name="do" value="unblock_account">
                                        <input type="hidden" name="owner_id" value="<?php echo (int) $r['owner_id']; ?>">
                                        <input type="hidden" name="report_id" value="<?php echo (int) $r['id']; ?>">
                                        <button type="submit" class="btn btn-small btn-outline">Unblock</button>
                                    </form>
                                    <?php else: ?>
                                    <form action="action.php" method="POST"
                                          onsubmit="return confirm('Block this owner\'s account? They will be signed out, unable to sign in again, and every listing they own disappears from the app.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                        <input type="hidden" name="do" value="block_account">
                                        <input type="hidden" name="owner_id" value="<?php echo (int) $r['owner_id']; ?>">
                                        <input type="hidden" name="report_id" value="<?php echo (int) $r['id']; ?>">
                                        <button type="submit" class="btn btn-small btn-danger">Block</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>