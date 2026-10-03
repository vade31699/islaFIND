<?php
// ============================================================
// categories.php — Shared islaFIND provider lists
// Included by create_profile.php and dashboard.php so every page
// uses the SAME category slugs and display labels (single source
// of truth).
// ============================================================

// --- 1. Profile types (step 1 of profile creation) -------------
// slug => human label shown on the two big selector cards.
$profileTypes = [
    'individual' => 'Individual Skills',
    'business'   => 'Business',
];

// --- 2. Sub-categories per profile type (step 2) ---------------
// Each type has its own searchable list. The slug is stored in
// providers.selected_title; the label is what users see.
$providerSubCategories = [
    'individual' => [
        'mechanic'            => 'Mechanic',
        'electrician'         => 'Electrician',
        'carpenter'           => 'Carpenter',
        'plumber'             => 'Plumber',
        'welder'              => 'Welder',
        'aircon_tech'         => 'Aircon Technician',
        'va_freelancer'       => 'VA / Freelancer',
        'construction_worker' => 'Construction Worker',
        'tour_guide'          => 'Local Tour Guide',
    ],
    'business' => [
        'beach_resort'   => 'Beach Resort',
        'boarding_house' => 'Boarding House',
        'motor_rental'   => 'Motor Rental',
        'hardware'       => 'Hardware',
        'sari_sari_store' => 'Sari-Sari Store',
        'transient_house' => 'Transient House',
    ],
];

// --- 3. Flat slug => label map (display pages) ------------------
// dashboard.php (the feed, the catalogue and the save forms) reads
// this single map
// to turn a stored slug into its human label, so the union of
// every sub-category is merged here.
$providerCategories = [];
foreach ($providerSubCategories as $list) {
    foreach ($list as $slug => $label) {
        $providerCategories[$slug] = $label;
    }
}

// --- 4. Bantayan Island municipalities + barangays (step 3) ----
// Cascading address: choosing a municipality in the create/edit
// form loads ONLY that municipality's barangays into the second
// dropdown. The lists are the official PSA/PhilAtlas barangays and
// include the offshore islet barangays (e.g. Hilantagaan and
// Kinatarkan under Santa Fe; Hilotongan, Lipayran, Doong and
// Botigues under Bantayan).
$municipalities = [
    'Santa Fe' => [
        'Balidbid', 'Hagdan', 'Hilantagaan', 'Kinatarkan', 'Langub',
        'Maricaban', 'Okoy', 'Poblacion', 'Pooc', 'Talisay',
    ],
    'Bantayan' => [
        'Atop-atop', 'Baigad', 'Bantigue', 'Baod', 'Binaobao',
        'Botigues', 'Doong', 'Guiwanon', 'Hilotongan', 'Kabac',
        'Kabangbang', 'Kampingganon', 'Kangkaibe', 'Lipayran',
        'Luyongbaybay', 'Mojon', 'Obo-ob', 'Patao', 'Putian',
        'Sillon', 'Suba', 'Sulangan', 'Sungko', 'Tamiao', 'Ticad',
    ],
    'Madridejos' => [
        'Bunakan', 'Kangwayan', 'Kaongkod', 'Kodia', 'Maalat',
        'Malbago', 'Mancilang', 'Pili', 'Poblacion', 'San Agustin',
        'Tabagak', 'Talangnan', 'Tarong', 'Tugas',
    ],
];

// --- 4b. Map centres per municipality (business pin picker) -----
// Where the BUSINESS tap-to-pin map opens before the owner has
// picked a spot: the town centre of each municipality (approximate
// OpenStreetMap town nodes). These are only a starting VIEW — the
// owner pans/zooms and taps the exact spot, and whatever they tap
// is what gets saved. The keys MUST stay identical to the
// municipality names in $municipalities above; a missing key just
// falls back to the whole-island view in maps_pinning.js.
$municipalityCenters = [
    'Santa Fe'   => ['lat' => 11.153795, 'lng' => 123.806863],
    'Bantayan'   => ['lat' => 11.166691, 'lng' => 123.718882],
    'Madridejos' => ['lat' => 11.296208, 'lng' => 123.732926],
];

/**
 * isla_listing_label(?string $categorySlug, ?string $name,
 *                    ?string $municipality, string $fallback = 'this listing'): string
 * How ONE listing is named in a sentence: a business by its own name
 * ("Sea Breeze Resort"), an individual by the skill they offer
 * ("Electrician, Santa Fe").
 * A listing is what a member inquires about and what a job is pinned
 * to, so the messenger and the My Jobs panel both need the same short
 * name for it — this is the single definition, so those two can never
 * describe the same listing differently.
 *
 * $fallback covers the listing that has been deleted: the stored
 * label (or the listing row) is gone and there is nothing left to
 * name, so the caller supplies the words it wants instead
 * ("a listing that is no longer available").
 *
 * NOT the same string as the admin panel's adm_listing_title(),
 * which writes "Name — Category, Place" for a business. This one
 * goes inside a chat sentence, where the extra dash would read as
 * punctuation rather than as part of the name. Both name the same
 * listing for different readers: the admin triaging the queue, and
 * the member reading who they are talking to.
 *
 * @param string|null $categorySlug providers.selected_title (a key of $providerCategories).
 * @param string|null $name          providers.name (businesses only; NULL for individuals).
 * @param string|null $municipality  providers.municipality.
 * @param string      $fallback      Used when nothing is filled in at all.
 * @return string
 */
function isla_listing_label(?string $categorySlug, ?string $name, ?string $municipality, string $fallback = 'this listing'): string
{
    global $providerCategories;

    $label = trim((string) ($providerCategories[(string) $categorySlug] ?? ''));
    if ($label === '') {
        $label = trim((string) $categorySlug);
    }

    $businessName = trim((string) $name);
    $place        = trim((string) $municipality);

    // A business is its own name. Only when it has none (which the
    // form prevents, but a legacy row may still be) does it fall
    // back to the category, like an individual listing does.
    $subject = $businessName !== '' ? $businessName : $label;

    if ($subject === '') {
        return $fallback;
    }

    // "Electrician, Santa Fe" — the place only when there is one, so
    // a listing saved before the address was filled in reads as just
    // its skill rather than "Electrician, ".
    return $place !== '' ? $subject . ', ' . $place : $subject;
}

// --- 5. Flat list of every barangay (backward compatibility) ----
// Some older code paths expect a plain array of all barangays; this
// is the flattened union of the cascading map above.
$providerBarangays = [];
foreach ($municipalities as $list) {
    foreach ($list as $b) {
        $providerBarangays[] = $b;
    }
}
