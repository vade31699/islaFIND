<?php
// ============================================================
// admin/views/report.php — one report, and what can be done about it
//
// Required by admin/index.php, which has already run the guard and
// built the data. Uses: $report, $listing, $reporter, $ownerOther,
// $listingOther, e(), adm_upload_url(), adm_listing_title(),
// adm_time_ago().
//
// The layout follows what the decision actually needs: the claim on
// one side, the listing as members see it on the other, and the
// actions underneath both — because "block this listing" is a
// judgement about the listing, while "dismiss this report" is a
// judgement about the claim.
// ============================================================

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
?>

<?php
$providerId   = (int) $report['provider_id'];
$reportId     = (int) $report['id'];
$isPending    = (string) $report['status'] === 'pending';
$listingState = (string) ($listing['status'] ?? 'active');
$isBlocked    = $listingState === 'blocked';
$reasonLabel  = isla_report_reason_label((string) $report['reason_code']);
$evidence     = trim((string) ($report['evidence_image'] ?? ''));

// The listing's own picture, falling back to the owner's account avatar —
// the same resolution a member's card does, so the admin sees exactly the
// picture members saw when they decided to report it.
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
?>
<div class="adm-panel">
    <div class="adm-panel-head">
        <div>
            <h2>
                <?php echo e($reasonLabel); ?>
            </h2>
            <p>
                Report #<?php echo $reportId; ?> ·
                filed <?php echo e(adm_time_ago((string) $report['created_at'])); ?> ·
                <?php echo e((string) $report['created_at']); ?>
            </p>
        </div>
        <div class="adm-panel-head-actions">
            <?php if ((string) $report['status'] === 'pending'): ?>
                <span class="adm-badge adm-badge-pending"><span class="adm-badge-dot"></span>Awaiting review</span>
            <?php elseif ((string) $report['status'] === 'resolved'): ?>
                <span class="adm-badge adm-badge-resolved"><span class="adm-badge-dot"></span>Resolved</span>
            <?php else: ?>
                <span class="adm-badge adm-badge-dismissed"><span class="adm-badge-dot"></span>Dismissed</span>
            <?php endif; ?>

            <?php if ($isBlocked): ?>
                <span class="adm-badge adm-badge-blocked"><span class="adm-badge-dot"></span>Listing blocked</span>
            <?php endif; ?>

            <a class="btn btn-small btn-outline" href="index.php?view=reports&amp;status=<?php echo e((string) $report['status']); ?>">Back</a>
        </div>
    </div>

    <div class="adm-panel-body">
        <div class="adm-detail">

            <!-- ============ Left: the claim ============ -->
            <div class="adm-detail-col">
                <h3 class="adm-subhead">What was reported</h3>

                <dl class="adm-kv">
                    <dt>Listing</dt>
                    <dd><?php echo e(adm_listing_title($listing ?? $report)); ?></dd>

                    <dt>IslaProfile ID</dt>
                    <dd class="adm-ref"><?php echo e((string) ($report['profile_code'] ?: isla_profile_code($providerId))); ?></dd>

                    <dt>Reported by</dt>
                    <dd>
                        <?php echo e((string) ($reporter['full_name'] ?? 'Member')); ?>
                        <?php if ($reporter !== null): ?>
                            <span class="adm-secondary">
                                member since <?php echo e(date('j M Y', (int) strtotime((string) $reporter['created_at']))); ?>
                            </span>
                        <?php endif; ?>
                    </dd>
                </dl>

                <?php if (trim((string) ($report['details'] ?? '')) !== ''): ?>
                    <h3 class="adm-subhead is-spaced">In their words</h3>
                    <!-- The member's text is quoted, never interpreted:
                         it is printed as text with its own line breaks. -->
                    <div class="adm-quote"><?php echo e((string) $report['details']); ?></div>
                <?php endif; ?>

                <?php if ($evidence !== ''): ?>
                    <figure class="adm-evidence">
                        <!-- The stored name is a bare filename produced by
                             isla_upload_name(); adm_upload_url() runs it
                             through basename() + rawurlencode(), so this can
                             never point outside the uploads folder. -->
                        <a href="<?php echo e(adm_upload_url($evidence)); ?>" target="_blank" rel="noopener noreferrer">
                            <img src="<?php echo e(adm_upload_url($evidence)); ?>" alt="Evidence screenshot supplied with this report">
                        </a>
                        <figcaption>Evidence uploaded by the member — click to open full size.</figcaption>
                    </figure>
                <?php endif; ?>

                <?php if ($reporter !== null): ?>
                    <p class="adm-secondary adm-spaced">
                        Reporter contact (for follow-up only):
                        <?php echo e((string) $reporter['email']); ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- ============ Right: the listing ============ -->
            <div class="adm-detail-col">
                <h3 class="adm-subhead">The listing as members see it</h3>

                <?php if ($listing === null): ?>
                    <div class="adm-empty is-inline">
                        This listing has been deleted. The report was kept for the record.
                    </div>
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
                                Listed <?php echo e(adm_time_ago((string) $listing['created_at'])); ?>
                                <?php $visits = (int) ($listing['view_count'] ?? 0); ?>
                                · <?php echo $visits; ?> view<?php echo $visits === 1 ? '' : 's'; ?>
                                <?php $reports = (int) ($listing['review_count'] ?? 0); ?>
                                · <?php echo $reports; ?> rating<?php echo $reports === 1 ? '' : 's'; ?>
                            </span>
                        </div>
                    </div>

                    <dl class="adm-kv">
                        <dt>Owner</dt>
                        <dd><?php echo e((string) ($listing['owner_name'] ?? '—')); ?></dd>

                        <dt>Contact</dt>
                        <dd><?php echo e((string) ($listing['owner_email'] ?? '—')); ?></dd>

                        <dt>Phone</dt>
                        <dd><?php echo e((string) ($listing['owner_phone'] ?? '—')); ?></dd>

                        <dt>Location</dt>
                        <dd>
                            <?php echo e(trim(((string) ($listing['barangay'] ?? '')) . ', ' . ((string) ($listing['municipality'] ?? '')), ', ')); ?>
                        </dd>

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
                    <p class="adm-secondary adm-tight">
                        <?php echo count($listingOther); ?> more — a pattern worth weighing.
                    </p>
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

                <?php if (!empty($ownerOther)): ?>
                    <h3 class="adm-subhead is-spaced">Other listings by this owner</h3>
                    <?php foreach ($ownerOther as $other): ?>
                        <p class="adm-secondary adm-stack">
                            <?php echo e(adm_listing_title($other)); ?>
                            <span class="adm-ref"><?php echo e((string) ($other['profile_code'] ?: isla_profile_code((int) $other['id']))); ?></span>
                            — <?php echo e((string) $other['status']); ?>
                        </p>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============ Actions ============ -->
<div class="adm-panel">
    <div class="adm-panel-head">
        <div>
            <h2>Decide</h2>
            <p>Blocking hides the listing from every part of the app. The owner keeps it and can see it is blocked.</p>
        </div>
    </div>

    <div class="adm-panel-body">
        <!-- Blocking. The reason is stored on the listing so the record
             survives even if this report is later dismissed. -->
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
                <span class="adm-secondary">
                    Resolves every open report on <?php echo e((string) ($report['profile_code'] ?: isla_profile_code($providerId))); ?>.
                </span>
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

        <!-- The report's own outcome + the note that explains it. -->
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