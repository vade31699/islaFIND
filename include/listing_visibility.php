<?php
// ============================================================
// listing_visibility.php — who is allowed to discover a listing
//
// Two different things take a listing out of the app:
//
//   * the listing is blocked    -> providers.status = 'blocked'
//   * its OWNER is blocked      -> users.status    = 'blocked'
//
// The second one is the account-level block set from the report
// queue (admin/action.php). It hides the account's whole catalogue
// without touching a single providers row, which is what lets
// unblocking restore every listing exactly as it was, with its own
// status, photos and history intact.
//
// The rule is written ONCE, here, because "hidden" has to mean the
// same thing in every public read: the feed and catalogue, the
// reviews endpoint, view tracking, bookmarks, reporting and hiring.
// A listing that vanishes from the feed but can still be hired,
// saved or reported is not hidden — it is merely hard to find, and
// the owner can pass the link around.
//
// Written against the aliases those reads already use: `p` for the
// providers row, `u` for the users row that owns it. The aliases
// are string literals from the caller, never user input.
// ============================================================

/**
 * isla_listing_live_join(string $providerAlias = 'p'): string
 * The JOIN that brings a listing's owning account into the query, so
 * the owner's block state can be filtered on. Use it on a read that
 * does not join users yet; a read that already has the owner joined
 * (as `u`) only needs isla_listing_live_where().
 *
 * @param string $providerAlias Alias of the providers row.
 * @return string SQL fragment.
 */
function isla_listing_live_join(string $providerAlias = 'p'): string
{
    return 'JOIN users u ON u.id = ' . $providerAlias . '.user_id';
}

/**
 * isla_listing_live_where(string $providerAlias = 'p'): string
 * The WHERE condition every public listing read must carry: the
 * listing is live AND so is the account behind it.
 *
 * @param string $providerAlias Alias of the providers row.
 * @return string SQL fragment.
 */
function isla_listing_live_where(string $providerAlias = 'p'): string
{
    return $providerAlias . ".status = 'active' AND u.status = 'active'";
}
