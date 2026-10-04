<?php
// ============================================================
// feed_card.php — the ONE Home-feed card renderer, and the one
// canonical form of "which listings may be discovered".
//
// feedCardHtml() used to live inline in dashboard.php. It is here
// now because a second reader needs the exact same markup:
// feed_watch.php, which answers the Home feed's "did anything
// change while I was looking at it?" poll. When a listing appears
// on the island while somebody's feed is open, the poll renders its
// card from HERE, so a card that arrives live is byte-for-byte the
// card the page would have rendered after a refresh. One renderer,
// two callers, no second source of truth to drift.
//
// Everything the function needs arrives as an argument, so it stays
// a pure function of its inputs (plus isla_listing_photo_src() /
// isla_upload_url(), which are the storage seam's own helpers and
// are included by every caller).
// ============================================================

/**
 * The Home feed's card for one listing.
 *
 * Cards are account-synced: the photo and phone come from users,
 * INDIVIDUAL listings show the account holder's name, BUSINESS
 * listings show their own name. Each card also shows THIS listing's
 * rating (avg stars + review count) aggregated from the reviews
 * table.
 *
 * $catalogue = true renders the card for the Home feed's
 * 📖 All Listings section (what directory.php used to be). It is the
 * ONLY place a card carries id="listing-N", so that anchor stays
 * unique even though the same listing can also sit in a rail —
 * save_listing.php returns the visitor to #listing-N after a toggle,
 * and an anchor that matched two cards would scroll to whichever
 * came first.
 *
 * @param array       $p          One providers row joined to its owner
 *                                 (users AS user_name / user_pic /
 *                                 user_phone) plus the aggregates the
 *                                 card shows: completed_jobs, on_job.
 * @param array       $categories  Category slug => display label.
 * @param int         $myId        The signed-in user's id (own-listing
 *                                 checks: no Save heart on your own card).
 * @param string      $csrf        Session CSRF token for the Save form.
 * @param array       $savedIds    provider id => 1 for bookmarks.
 * @param bool        $catalogue   True for the All Listings catalogue.
 * @param array       $albums      [['id' => int, 'name' => string], ...]
 *
 * @return string Escaped HTML for one .feed-card.
 */
function feedCardHtml(array $p, array $categories, int $myId, string $csrf, array $savedIds = [], bool $catalogue = false, array $albums = []): string
{
    $catLabel = htmlspecialchars($categories[$p['selected_title']] ?? $p['selected_title']);
    // Business listings keep their own name; individuals use the account name.
    $name     = ($p['profile_type'] === 'business' && $p['name'] !== null && $p['name'] !== '')
        ? $p['name']
        : $p['user_name'];
    $nameHtml = htmlspecialchars($name);
    // A listing can carry a picture of its OWN (providers.profile_picture,
    // set from Settings -> islaFIND Profile); when it has none the account
    // avatar is shown, exactly as before that column existed. The fallback
    // lives in one function so this card, the detail modal and the owner's
    // own panel can never disagree about which photo is on the listing.
    $pic      = isla_listing_photo_src($p['profile_picture'] ?? null, $p['user_pic'] ?? null);
    $jobs     = (int) ($p['completed_jobs'] ?? 0);   // hired + finished jobs
    $avgR     = round((float) ($p['avg_rating'] ?? 0), 1);
    $revN     = (int) ($p['review_count'] ?? 0);
    // "On the Job" applies ONLY to Individual Skills listings, and
    // only once the employer + worker agreed (an accepted hire).
    // Business profiles never carry the badge; inquiries stay open
    // either way.
    $onJob = !empty($p['on_job']) && $p['profile_type'] === 'individual';

    // Full-width strip at the very top of the card so the status is
    // visible in the feed itself, not only after opening the detail.
    $onJobBadge = $onJob
        ? '<div class="feed-card-onthejob"><span class="otj-dot"></span>On the Job</div>'
        : '';

    // Avatar: account photo or initials fallback.
    $initials = '';
    foreach (preg_split('/\s+/', trim($name)) as $part) {
        if ($part !== '' && strlen($initials) < 2) {
            $first = function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
            $initials .= function_exists('mb_strtoupper') ? mb_strtoupper($first) : strtoupper($first);
        }
    }
    $avatar = $pic
        ? '<img src="' . htmlspecialchars($pic) . '" alt="' . $nameHtml . '" class="feed-card-pic">'
        : '<span class="feed-card-pic feed-card-pic-placeholder">' . htmlspecialchars($initials ?: '?') . '</span>';

    // Per-listing rating row (stars + number + review count).
    if ($revN > 0) {
        $ratingRow = '<div class="card-rating"><span class="stars" aria-hidden="true">'
            . str_repeat('★', max(1, min(5, (int) round($avgR))))
            . '</span><span class="rating-num">' . number_format($avgR, 1)
            . '</span><span class="rating-count">(' . $revN . ' review' . ($revN === 1 ? '' : 's') . ')</span></div>';
    } else {
        $ratingRow = '<div class="card-rating"><span class="rating-none">No reviews yet</span></div>';
    }

    // NOTE: the account phone is deliberately NOT shown on the
    // public profile card. Contact details are sensitive, so the
    // provider decides when (and whether) to share their number
    // inside the messenger conversation — never on a public feed.

    // CONTEXTUAL FIELD: the profile type decides what extra detail
    // the card shows.
    //   - INDIVIDUAL SKILLS -> a short description snippet (the
    //     full text lives in the detail modal).
    //   - BUSINESS -> an "N units available" badge (rooms, bikes...).
    $isBusiness = $p['profile_type'] === 'business';
    $desc       = trim((string) ($p['profile_description'] ?? ''));
    $hasUnits   = isset($p['unit_inventory']) && $p['unit_inventory'] !== null;
    $units      = $hasUnits ? (int) $p['unit_inventory'] : 0;
    if ($isBusiness && $hasUnits) {
        // Only render the badge when the owner actually set a count
        // (a business that never entered units shows nothing).
        $contextBlock = '<div class="card-units">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>'
            . '<span>' . $units . ' unit' . ($units === 1 ? '' : 's') . ' available</span>'
            . '</div>';
    } elseif ($desc !== '') {
        $snippet = function_exists('mb_substr')
            ? (mb_strlen($desc) > 110 ? mb_substr($desc, 0, 110) . '…' : $desc)
            : (strlen($desc) > 110 ? substr($desc, 0, 110) . '…' : $desc);
        $contextBlock = '<p class="card-desc">' . htmlspecialchars($snippet) . '</p>';
    } else {
        $contextBlock = '';
    }

    // Compact location chip on every feed card: "Barangay, Municipality"
    // (e.g. "Poblacion, Madridejos") so the municipality is visible
    // right in the Home feed — same style as the Directory badge.
    $locMun  = trim((string) ($p['municipality'] ?? ''));
    $locBgy  = trim((string) ($p['barangay'] ?? ''));
    $locChip = ($locMun !== '' || $locBgy !== '')
        ? '<span class="card-location">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>'
            . '<span>' . htmlspecialchars(trim($locBgy . ', ' . $locMun, ", \n\r\t")) . '</span>'
            // Proximity slot: filled by the Home feed's script while
            // search results are ranked nearest-first, and left empty +
            // hidden otherwise so the pill never grows for a card (or a
            // sort) that has no distance to show.
            . '<span class="card-distance" hidden></span>'
            . '</span>'
        : '';

    // --- Pinned location (fed to the detail modal) ---------------
    // Only a BUSINESS listing whose owner pinned GPS coordinates on
    // the create/edit form is routable. The values ride along as
    // data-map-* attributes on the card; the modal's "Get Route"
    // button is filled from them when the card is tapped, so Google
    // Maps shows the path from the visitor's current position to the
    // business's pin.
    $mapLat = $p['latitude']  ?? null;
    $mapLng = $p['longitude'] ?? null;
    $hasPin = $isBusiness && $mapLat !== null && $mapLng !== null;

    // Distance ordering (the nearest-first search results) needs a pin
    // on EVERY listing, not just the businesses the detail modal can
    // route to: an INDIVIDUAL skill carries its own GPS pin from the
    // create form too. Keep a separate pair so the modal's
    // business-only route data above stays exactly as it was.
    $geoLat = $p['latitude']  ?? null;
    $geoLng = $p['longitude'] ?? null;
    $hasGeo = $geoLat !== null && $geoLat !== '' && $geoLng !== null && $geoLng !== '';

    // The feed card is COMPACT by design: it shows only the profile
    // details (photo, name, badge, jobs, rating, phone, and the
    // contextual description / units). Every action — including the
    // "Get Route" link for a pinned business — lives in the detail
    // modal that opens when the card is tapped. All the detail fields
    // are carried as data attributes so the modal can be filled
    // without a reload.
    //
    // NOTE: the card does NOT carry data-map-url. The stored
    // google_maps_url is only the destination-only deep link built at
    // save time; the modal builds a better one from the coordinates
    // below (with the visitor's own origin), so shipping the stored
    // copy would just be a misleading second source of truth.
    $own = (int) $p['user_id'] === $myId ? '1' : '0';

    // The album rides along as a pipe-separated list of URLs so the
    // detail modal can build its gallery the instant the card is
    // tapped — no request, no spinner. '|' cannot occur in a URL that
    // isla_upload_url() builds (it rawurlencodes the stored name), so
    // it is a safe separator, and the whole attribute is escaped once
    // as the browser hands it back verbatim through dataset.album.
    $albumUrls = [];
    foreach ($albums as $albumPhoto) {
        $albumUrls[] = isla_upload_url((string) $albumPhoto['name']);
    }
    $albumAttr = $albumUrls === [] ? '' : htmlspecialchars(implode('|', $albumUrls));

    // --- Save heart, BESIDE the name ----------------------------
    // The same POST toggle the directory uses (save_listing.php), so a
    // listing can be bookmarked straight from the Home feed without
    // opening the detail modal first. Rendered for OTHER people's
    // listings only: your own are managed in Settings and the server
    // refuses to bookmark them. The card's own click / keydown handler
    // deliberately steps aside for this control (see the .inline-save
    // guard in the feed wiring), so the heart never opens the modal by
    // accident on the way to the POST.
    $isSaved     = isset($savedIds[(int) $p['id']]);
    $saveControl = $own === '1' ? '' : '<form action="save_listing.php" method="POST" class="inline-save">'
        . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
        . '<input type="hidden" name="provider_id" value="' . (int) $p['id'] . '">'
        . '<input type="hidden" name="return_to" value="home">'
        . '<button type="submit" class="save-btn' . ($isSaved ? ' is-saved' : '') . '"'
        . ' aria-pressed="' . ($isSaved ? 'true' : 'false') . '"'
        . ' title="' . ($isSaved ? 'Remove from your saved listings' : 'Save this listing for later') . '">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>'
        . '<span>' . ($isSaved ? 'Saved' : 'Save') . '</span>'
        . '</button>'
        . '</form>';

    $html = '<div class="feed-card feed-card-clickable" role="button" tabindex="0"'
        // The catalogue's cards are the deep-linkable ones (see above).
        . ($catalogue ? ' id="listing-' . (int) $p['id'] . '"' : '')
        . ' data-id="' . (int) $p['id'] . '"'
        . ' data-name="' . $nameHtml . '"'
        . ' data-title="' . $catLabel . '"'
        // Category SLUG (not the label): the catalogue's category chips
        // filter on it, and the label is already rendered as the badge.
        . ' data-cat="' . htmlspecialchars($p['selected_title']) . '"'
        . ' data-type="' . htmlspecialchars($p['profile_type']) . '"'
        . ' data-barangay="' . htmlspecialchars($p['barangay'] ?? '') . '"'
        . ' data-municipality="' . htmlspecialchars($p['municipality'] ?? '') . '"'
        . ' data-rating="' . number_format($avgR, 1) . '"'
        . ' data-reviews="' . $revN . '"'
        . ' data-onjob="' . ($onJob ? '1' : '0') . '"'
        . ' data-pic="' . ($pic ? htmlspecialchars($pic) : '') . '"'
        // Only a BUSINESS listing ever has album rows (upload_listing_photos.php
        // refuses the album for an individual one), so an empty attribute here
        // simply means "no extra photos".
        . ' data-album="' . $albumAttr . '"'
        . ' data-desc="' . htmlspecialchars($desc) . '"'
        . ' data-units="' . ($isBusiness && $hasUnits ? (int) $units : '') . '"'
        . ' data-map-lat="' . ($hasPin ? htmlspecialchars((string) $mapLat) : '') . '"'
        . ' data-map-lng="' . ($hasPin ? htmlspecialchars((string) $mapLng) : '') . '"'
        // The search ranking's coordinates: present on every pinned
        // listing (business OR individual), so a search can measure and
        // order results by distance from the visitor.
        . ' data-geo-lat="' . ($hasGeo ? htmlspecialchars((string) $geoLat) : '') . '"'
        . ' data-geo-lng="' . ($hasGeo ? htmlspecialchars((string) $geoLng) : '') . '"'
        . ' data-saved="' . (isset($savedIds[(int) $p['id']]) ? '1' : '0') . '"'
        . ' data-own="' . $own . '">'
        . $onJobBadge
        . '<div class="feed-card-top">'
        . $avatar
        . '<div class="feed-card-head">'
        . '<h5>' . $nameHtml . '</h5>'
        . '<span class="provider-badge">' . $catLabel . '</span>'
        . '</div>'
        . $saveControl
        . '</div>'
        . $ratingRow
        . $locChip
        . $contextBlock
        . '<span class="feed-card-hint">Tap for details &#8250;</span>'
        . '</div>';
    return $html;
}

/**
 * isla_feed_id_list(array $ids): string
 * The canonical wire form of "which listings a member may discover":
 * the ids, numerically ascending, comma-separated, no repeats.
 *
 * This is the whole contract of the live feed (public/feed_watch.php). The
 * server sends its own list in this shape and compares it, as a plain
 * string, against the list the browser sends back; equal strings mean the
 * feed on screen is still the island, and the poll answers in a few bytes
 * instead of diffing. dashboard.php's script rebuilds the same string from
 * the cards in the DOM (see feedShownList() there), which is why the sort
 * is numeric and ascending rather than however the database returned the
 * rows.
 *
 * A string, deliberately, and not a hash: the browser cannot compute a
 * hash without shipping an md5 implementation, so a fingerprint here would
 * mean trusting the client's claim about itself instead of reading it.
 *
 * @param array $ids Listing ids in any order.
 * @return string e.g. "12,48,907"; "" when there are none.
 */
function isla_feed_id_list(array $ids): string
{
    // Anything that is not a listing id is dropped rather than coerced: a
    // stray value must not become id 0 and put a phantom listing in the
    // list both sides agree on.
    $ints = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ints[$id] = true;
        }
    }
    $ints = array_keys($ints);
    sort($ints, SORT_NUMERIC);
    return implode(',', $ints);
}
