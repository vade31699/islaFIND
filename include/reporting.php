<?php
// ============================================================
// reporting.php — listing reference + the report reason catalogue
//
// Three small things that the public report form, the submit
// handler and the superadmin queue all have to agree on. Keeping
// them here is what stops those three screens from drifting: a
// reason the form offers but the handler rejects (or a reason the
// queue cannot name) is a bug this file exists to make impossible.
//
//   - isla_profile_code()     : the "IslaProfile ID" shown on a
//                               listing, e.g. ISLA-000142
//   - isla_report_reasons()   : the whitelist of report reasons
//   - isla_report_reason_label(): one reason's human label
//
// Dependency-free and idempotent, like every other include here.
// ============================================================

/**
 * isla_profile_code(int $providerId): string
 * The public reference for a listing: ISLA- + its id, zero-padded
 * to six digits.
 *
 * WHY IT IS DERIVED RATHER THAN STORED SEPARATELY: the id is
 * already unique and never reused (auto-increment), so a code
 * computed from it is unique by construction — there is no sequence
 * to keep in step, no collision to retry, and no way for two
 * listings to end up sharing a reference. The value is also stored
 * in providers.profile_code so it can be indexed and searched; this
 * function is the single definition of what it looks like, and the
 * backfill in include/db.php writes the same format.
 *
 * Zero-padding is purely cosmetic — it makes a list of codes sort in
 * the same order as the ids behind them, which matters in the admin
 * queue. Ids above 999999 simply print with more digits.
 *
 * @param int $providerId providers.id.
 * @return string e.g. 'ISLA-000142'.
 */
function isla_profile_code(int $providerId): string
{
    return 'ISLA-' . str_pad((string) max(0, $providerId), 6, '0', STR_PAD_LEFT);
}

/**
 * isla_report_reasons(): array
 * The complete set of reasons a member may pick from, as
 * code => label.
 *
 * This array IS the validation: report_listing.php accepts a reason
 * only when its key is in here, and the admin queue prints the label
 * from the same table. A new reason is added in exactly one place,
 * and an unexpected value from a tampered-with form is rejected
 * instead of being stored and later displayed as raw text.
 *
 * The codes are stable strings, not array positions, because they are
 * written to profile_reports.reason_code and must still be readable
 * after this list is reordered.
 *
 * @return array<string, string> Reason code => label shown to members.
 */
function isla_report_reasons(): array
{
    return [
        'fake_listing'  => 'Fake or misleading listing',
        'fake_photos'   => 'Fake or stolen photos',
        'wrong_pricing' => 'Wrong or misleading prices',
        'scam_request'  => 'Asked me for money outside the app',
        'abusive'       => 'Harassment or abusive behaviour',
        'duplicate'     => 'Duplicate of another listing',
        'not_a_service' => 'Not actually offering a service here',
        'other'         => 'Something else',
    ];
}

/**
 * isla_report_reason_label(string $code): string
 * The label for one reason code, with a safe fallback.
 *
 * The fallback matters more than it looks: rows written by a future
 * version of this file (or by hand during an investigation) may hold
 * a code this build does not know. The admin queue must still render
 * the row — showing the raw code beats rendering nothing, and it
 * never echoes attacker-supplied markup because the value came from
 * the database, not the request. Callers escape it on output anyway.
 *
 * @param string $code Reason code from the form.
 * @return string Human label, or the code itself when unknown.
 */
function isla_report_reason_label(string $code): string
{
    $reasons = isla_report_reasons();
    return $reasons[$code] ?? $code;
}