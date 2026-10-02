<?php
// admin/views/report.php — one report
//
// Uses: $report, $listing, $reporter, $ownerOther, $listingOther, e(),
// adm_upload_url(), adm_listing_title(), adm_time_ago().
//
// The listing first, the claim beside it. The owner is a step away:
// "Show owner profile" opens a separate screen listing everything the
// account owns.

if ($report === null) {
    ?>
    <div class="adm-panel">
        <div class="adm-empty">
            <strong>Report not found.</strong>
            It may have been removed with the listing it was about.
            <div class="adm-secondary adm-spaced">
                <a class="btn btn-small btn-outline" href="index.php?view=reports&amp;status=pending">Back to the queue</a>
            </div>
        </div>
    </div>
    <?php
    return;
}

$providerId   = (int) $report['provider_id'];
$reportId     = (int) $report['id'];
$isPending    = (string) $report['status'] === 'pending';
$isBlocked    = (string) ($listing['status'] ?? 'active') === 'blocked';
$ownerBlocked = $listing !== null && (string) ($listing['owner_status'] ?? 'active') === 'blocked';
$reasonLabel  = isla_report_reason_label((string) $report['reason_code']);
$evidence     = trim((string) ($report['evidence_image'] ?? ''));

$pic = null;
if ($listing !== null) {
    $resolved = isla_listing_photo_src(
        $listing['profile_picture'] ?? null,
        $listing['account_photo'] ?? null
    );
    if ($resolved !== null) {
        $pic = adm_upload_url($resolved);
    }
}

// Open reports the owner has on their other listings.
$ownerOpenElsewhere = 0;
foreach ($ownerOther as $other) {
    $ownerOpenElsewhere += (int) ($other['open_reports'] ?? 0);
}
?>
<div class="adm-panel">
    <div class="adm-panel-head">
        <div>
            <h2><?php echo e($reasonLabel); ?></h2>
            <p>Report #<?php echo $reportId; ?> · filed <?php echo e(adm_time_ago((string) $report['created_at'])); ?></p>
        </div>
        <div class="adm-panel-head-actions">
            <?php if ($isPending): ?>
                <span class="adm-badge adm-badge-pending"><span class="adm-badge-dot"></span>Open</span>
            <?php elseif ((string) $report['status'] === 'resolved'): ?>
                <span class="adm-badge adm-badge-resolved"><span class="adm-badge-dot"></span>Resolved</span>
            <?php else: ?>
                <span class="adm-badge adm-badge-dismissed"><span class="adm-badge-dot"></span>Dismissed</span>
            <?php endif; ?>
            <?php if ($isBlocked): ?>
                <span class="adm-badge adm-badge-blocked"><span class="adm-badge-dot"></span>Listing blocked</span>
            <?php endif; ?>
            <?php if ($ownerOpenElsewhere > 0): ?>
                <span class="adm-badge adm-badge-flag">
                    <span class="adm-badge-dot"></span>Owner: <?php echo $ownerOpenElsewhere; ?> open report<?php echo $ownerOpenElsewhere === 1 ? '' : 's'; ?> elsewhere
                </span>
            <?php endif; ?>
            <?php if ($listing !== null): ?>
                <a class="btn btn-small" href="index.php?view=owner&amp;id=<?php echo (int) ($listing['user_id'] ?? 0); ?>&amp;report_id=<?php echo $reportId; ?>">
                    Show owner profile
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="adm-panel-body">
        <div class="adm-detail">

            <div class="adm-detail-col">
                <h3 class="adm-subhead">The reported listing</h3>

                <?php if ($listing === null): ?>
                    <div class="adm-empty is-inline">This listing has been deleted.</div>
                <?php else: ?>
                    <div class="adm-listing-head">
                        <?php if ($pic !== null): ?>
                            <img class="adm-listing-pic" src="<?php echo e($pic); ?>" alt="">
                        <?php else: ?>
                            <div class="adm-listing-pic is-empty" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                            </div>
                        <?php endif; ?>
                        <div>
                            <strong class="adm-listing-title"><?php echo e(adm_listing_title($listing)); ?></strong>
                            <span class="adm-secondary">
                                <?php echo e((string) ($report['profile_code'] ?: isla_profile_code($providerId))); ?>
                                · listed <?php echo e(adm_time_ago((string) $listing['created_at'])); ?>
                                · <?php echo (int) ($listing['view_count'] ?? 0); ?> views
                                · <?php echo (int) ($listing['review_count'] ?? 0); ?> ratings
                            </span>
                        </div>
                    </div>

                    <dl class="adm-kv">
                        <dt>Owner</dt>
                        <dd><?php echo e((string) ($listing['owner_name'] ?? '—')); ?></dd>

                        <dt>Account</dt>
                        <dd>
                            <?php if ($ownerBlocked): ?>
                                <span class="adm-badge adm-badge-blocked"><span class="adm-badge-dot"></span>Blocked</span>
                            <?php else: ?>
                                <span class="adm-badge adm-badge-active"><span class="adm-badge-dot"></span>Live</span>
                            <?php endif; ?>
                        </dd>

                        <dt>Location</dt>
                        <dd><?php echo e(trim(((string) ($listing['barangay'] ?? '')) . ', ' . ((string) ($listing['municipality'] ?? '')), ', ')); ?></dd>

                        <dt>State</dt>
                        <dd>
                            <?php if ($isBlocked): ?>
                                <span class="adm-badge adm-badge-blocked"><span class="adm-badge-dot"></span>Blocked</span>
                                <?php if (!empty($listing['blocked_at'])): ?>
                                    <span class="adm-secondary">
                                        since <?php echo e(adm_time_ago((string) $listing['blocked_at'])); ?>
                                        <?php if (!empty($listing['blocked_reason'])): ?>
                                            — <?php echo e((string) $listing['blocked_reason']); ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="adm-badge adm-badge-active"><span class="adm-badge-dot"></span>Live</span>
                            <?php endif; ?>
                        </dd>
                    </dl>

                    <?php if (trim((string) ($listing['profile_description'] ?? '')) !== ''): ?>
                        <h3 class="adm-subhead is-spaced">Description</h3>
                        <div class="adm-quote"><?php echo e((string) $listing['profile_description']); ?></div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!empty($listingOther)): ?>
                    <h3 class="adm-subhead is-spaced">Other reports on this listing</h3>
                    <?php foreach ($listingOther as $other): ?>
                        <p class="adm-secondary adm-stack">
                            <a href="index.php?view=report&amp;id=<?php echo (int) $other['id']; ?>">
                                <?php echo e(isla_report_reason_label((string) $other['reason_code'])); ?>
                            </a>
                            — <?php echo e(adm_time_ago((string) $other['created_at'])); ?>
                            (<?php echo e((string) $other['status']); ?>)
                        </p>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="adm-detail-col">
                <h3 class="adm-subhead">The report</h3>

                <dl class="adm-kv">
                    <dt>Reported by</dt>
                    <dd>
                        <?php echo e((string) ($reporter['full_name'] ?? 'Member')); ?>
                        <?php if ($reporter !== null): ?>
                            <span class="adm-secondary">member since <?php echo e(date('j M Y', (int) strtotime((string) $reporter['created_at']))); ?></span>
                        <?php endif; ?>
                    </dd>

                    <dt>Contact</dt>
                    <dd><?php echo e((string) ($reporter['email'] ?? '—')); ?></dd>
                </dl>

                <?php if (trim((string) ($report['details'] ?? '')) !== ''): ?>
                    <h3 class="adm-subhead is-spaced">In their words</h3>
                    <div class="adm-quote"><?php echo e((string) $report['details']); ?></div>
                <?php endif; ?>

                <?php if ($evidence !== ''): ?>
                    <figure class="adm-evidence">
                        <a href="<?php echo e(adm_upload_url($evidence)); ?>" target="_blank" rel="noopener noreferrer">
                            <img src="<?php echo e(adm_upload_url($evidence)); ?>" alt="Evidence screenshot supplied with this report">
                        </a>
                        <figcaption>Evidence uploaded by the member.</figcaption>
                    </figure>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="adm-panel">
    <div class="adm-panel-head">
        <h2>Decide</h2>
    </div>

    <div class="adm-panel-body">
        <?php if ($listing !== null && !$isBlocked): ?>
        <form action="action.php" method="POST" class="adm-note-box"
              onsubmit="return confirm('Block this listing? It disappears from the feed, the catalogue and search immediately.');">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="do" value="block_listing">
            <input type="hidden" name="provider_id" value="<?php echo $providerId; ?>">
            <div class="form-group">
                <label class="field-label" for="blockReason">Reason for blocking (stored on the listing)</label>
                <input class="field-in" type="text" id="blockReason" name="reason"
                       maxlength="255" placeholder="<?php echo e($reasonLabel); ?>"
                       value="<?php echo e($reasonLabel); ?>">
            </div>
            <div class="adm-actions-row is-spaced">
                <button type="submit" class="btn btn-danger btn-small">Block this listing</button>
                <span class="adm-secondary">Resolves every open report on it.</span>
            </div>
        </form>
        <?php elseif ($listing !== null && $isBlocked): ?>
        <form action="action.php" method="POST"
              onsubmit="return confirm('Restore this listing to the feed?');">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="do" value="unblock_listing">
            <input type="hidden" name="provider_id" value="<?php echo $providerId; ?>">
            <div class="adm-actions-row">
                <button type="submit" class="btn btn-small">Unblock this listing</button>
                <span class="adm-secondary">It returns to the feed and search at once.</span>
            </div>
        </form>
        <?php endif; ?>

        <form action="action.php" method="POST" class="adm-form-block">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="do" value="<?php echo $isPending ? 'resolve_report' : 'save_note'; ?>">
            <input type="hidden" name="report_id" value="<?php echo $reportId; ?>">

            <div class="form-group">
                <label class="field-label" for="adminNotes"><?php echo $isPending ? 'Decision note' : 'Note'; ?></label>
                <textarea class="field-in" id="adminNotes" name="notes" rows="3" maxlength="2000"
                          placeholder="What did you find, and what did you do about it?"><?php echo e((string) ($report['admin_notes'] ?? '')); ?></textarea>
            </div>

            <div class="adm-actions-row is-spaced">
                <?php if ($isPending): ?>
                    <button type="submit" name="outcome" value="resolved" class="btn btn-small">Mark resolved</button>
                    <button type="submit" name="outcome" value="dismissed" class="btn btn-small btn-outline"
                            onclick="return confirm('Dismiss this report? The listing stays live.');">
                        Dismiss report
                    </button>
                <?php else: ?>
                    <button type="submit" class="btn btn-small btn-outline">Save note</button>
                <?php endif; ?>
            </div>
        </form>

        <?php if (!empty($report['resolved_at'])): ?>
            <p class="adm-secondary adm-spaced">
                Closed <?php echo e(adm_time_ago((string) $report['resolved_at'])); ?>
                by admin #<?php echo (int) ($report['resolved_by'] ?? 0); ?>.
            </p>
        <?php endif; ?>
    </div>
</div>
