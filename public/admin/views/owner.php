<?php
// admin/views/owner.php — the owner profile
// Uses: $ownerAccount, $ownerListings, $ownerReportId, e(),
// adm_upload_url(), adm_listing_title(), adm_time_ago(), isla_profile_code().

if ($ownerAccount === null) {
    ?>
    <div class="adm-panel">
        <div class="adm-empty">
            <strong>Account not found.</strong>
            It may have been deleted since the report was filed.
            <div class="adm-secondary adm-spaced">
                <a class="btn btn-small btn-outline" href="index.php?view=reports&amp;status=pending">Back to the queue</a>
            </div>
        </div>
    </div>
    <?php
    return;
}

$ownerId      = (int) $ownerAccount['id'];
$ownerBlocked = (string) ($ownerAccount['status'] ?? 'active') === 'blocked';

$avatar = null;
if (trim((string) ($ownerAccount['profile_picture'] ?? '')) !== '') {
    $avatar = adm_upload_url((string) $ownerAccount['profile_picture']);
}

$blockedCount = 0;
$openReports  = 0;
$allReports   = 0;
foreach ($ownerListings as $l) {
    if ((string) $l['status'] === 'blocked') {
        $blockedCount++;
    }
    $openReports += (int) ($l['open_reports'] ?? 0);
    $allReports  += (int) ($l['total_reports'] ?? 0);
}
?>
<div class="adm-panel">
    <div class="adm-panel-head">
        <div>
            <h2><?php echo e((string) $ownerAccount['full_name']); ?></h2>
            <p>Account #<?php echo $ownerId; ?> · member since <?php echo e(date('j M Y', (int) strtotime((string) $ownerAccount['created_at']))); ?></p>
        </div>
        <div class="adm-panel-head-actions">
            <?php if ($ownerBlocked): ?>
                <span class="adm-badge adm-badge-blocked"><span class="adm-badge-dot"></span>Account blocked</span>
            <?php else: ?>
                <span class="adm-badge adm-badge-active"><span class="adm-badge-dot"></span>Live</span>
            <?php endif; ?>
            <?php if ($ownerReportId > 0): ?>
                <a class="btn btn-small btn-outline" href="index.php?view=report&amp;id=<?php echo $ownerReportId; ?>">Back to report</a>
            <?php else: ?>
                <a class="btn btn-small btn-outline" href="index.php?view=reports&amp;status=pending">Back to the queue</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="adm-panel-body">
        <div class="adm-detail">
            <div class="adm-detail-col">
                <h3 class="adm-subhead">Owner</h3>

                <div class="adm-listing-head">
                    <?php if ($avatar !== null): ?>
                        <img class="adm-listing-pic" src="<?php echo e($avatar); ?>" alt="">
                    <?php else: ?>
                        <div class="adm-listing-pic is-empty" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21v-2a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </div>
                    <?php endif; ?>
                    <div>
                        <strong class="adm-listing-title"><?php echo e((string) $ownerAccount['full_name']); ?></strong>
                        <span class="adm-secondary">Member ID <?php echo (int) ($ownerAccount['user_id'] ?? 0); ?></span>
                    </div>
                </div>

                <dl class="adm-kv">
                    <dt>Contact</dt>
                    <dd><?php echo e((string) $ownerAccount['email']); ?></dd>

                    <dt>Phone</dt>
                    <dd><?php echo e((string) ($ownerAccount['phone'] ?? '—')); ?></dd>

                    <dt>Listings</dt>
                    <dd>
                        <?php echo count($ownerListings); ?> total
                        <?php if ($blockedCount > 0): ?>
                            · <?php echo $blockedCount; ?> blocked
                        <?php endif; ?>
                    </dd>

                    <dt>Reports</dt>
                    <dd>
                        <?php echo $allReports; ?> against this account's listings
                        <?php if ($openReports > 0): ?>
                            <span class="adm-secondary"><?php echo $openReports; ?> still open</span>
                        <?php endif; ?>
                    </dd>

                    <dt>Account</dt>
                    <dd>
                        <?php if ($ownerBlocked): ?>
                            <span class="adm-badge adm-badge-blocked"><span class="adm-badge-dot"></span>Blocked</span>
                            <?php if (!empty($ownerAccount['blocked_at'])): ?>
                                <span class="adm-secondary">
                                    since <?php echo e(adm_time_ago((string) $ownerAccount['blocked_at'])); ?>
                                    <?php if (!empty($ownerAccount['blocked_reason'])): ?>
                                        — <?php echo e((string) $ownerAccount['blocked_reason']); ?>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="adm-badge adm-badge-active"><span class="adm-badge-dot"></span>Live</span>
                        <?php endif; ?>
                    </dd>
                </dl>

                <?php if ($ownerBlocked): ?>
                <form action="action.php" method="POST" class="adm-spaced"
                      onsubmit="return confirm('Restore this account? They will be able to sign in again, and their listings return to the app.');">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="do" value="unblock_account">
                    <input type="hidden" name="owner_id" value="<?php echo $ownerId; ?>">
                    <input type="hidden" name="report_id" value="<?php echo $ownerReportId; ?>">
                    <input type="hidden" name="return" value="owner">
                    <button type="submit" class="btn btn-small">Unblock this account</button>
                </form>
                <?php else: ?>
                <form action="action.php" method="POST" class="adm-note-box"
                      onsubmit="return confirm('Block this owner\'s account? They will be signed out, unable to sign in again, and every listing they own disappears from the app.');">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="do" value="block_account">
                    <input type="hidden" name="owner_id" value="<?php echo $ownerId; ?>">
                    <input type="hidden" name="report_id" value="<?php echo $ownerReportId; ?>">
                    <input type="hidden" name="return" value="owner">
                    <div class="form-group">
                        <label class="field-label" for="blockAccountReason">Reason (shown to the owner on sign-in)</label>
                        <input class="field-in" type="text" id="blockAccountReason" name="reason"
                               maxlength="255" placeholder="Repeated or serious reports"
                               value="">
                    </div>
                    <div class="adm-actions-row is-spaced">
                        <button type="submit" class="btn btn-danger btn-small">Block this account</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>

            <div class="adm-detail-col">
                <h3 class="adm-subhead">Listings under this account</h3>

                <?php if (empty($ownerListings)): ?>
                    <div class="adm-empty is-inline">This account owns no listings.</div>
                <?php else: ?>
                    <div class="adm-table-wrap">
                        <table class="adm-table">
                            <thead>
                                <tr>
                                    <th scope="col">Listing</th>
                                    <th scope="col">State</th>
                                    <th scope="col">Reports</th>
                                    <th scope="col">Listed</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($ownerListings as $l): ?>
                                <?php
                                $lBlocked = (string) $l['status'] === 'blocked';
                                $lOpen    = (int) ($l['open_reports'] ?? 0);
                                $lTotal   = (int) ($l['total_reports'] ?? 0);
                                $reportsUrl = 'index.php?view=reports&amp;status=all&amp;provider=' . (int) $l['id'];
                                ?>
                                <tr>
                                    <td>
                                        <a class="adm-primary" href="<?php echo $reportsUrl; ?>"><?php echo e(adm_listing_title($l)); ?></a>
                                        <span class="adm-secondary adm-ref"><?php echo e((string) ($l['profile_code'] ?: isla_profile_code((int) $l['id']))); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($lBlocked): ?>
                                            <span class="adm-badge adm-badge-blocked"><span class="adm-badge-dot"></span>Blocked</span>
                                        <?php else: ?>
                                            <span class="adm-badge adm-badge-active"><span class="adm-badge-dot"></span>Live</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo $reportsUrl; ?>">
                                            <?php if ($lOpen > 0): ?>
                                                <span class="adm-badge adm-badge-pending"><span class="adm-badge-dot"></span><?php echo $lOpen; ?> open</span>
                                            <?php elseif ($lTotal > 0): ?>
                                                <span class="adm-secondary is-flat"><?php echo $lTotal; ?> closed</span>
                                            <?php else: ?>
                                                <span class="adm-secondary is-flat">None</span>
                                            <?php endif; ?>
                                        </a>
                                    </td>
                                    <td><?php echo e(adm_time_ago((string) $l['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
