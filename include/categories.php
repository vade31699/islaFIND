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

// --- 5. Flat list of every barangay (backward compatibility) ----
// Some older code paths expect a plain array of all barangays; this
// is the flattened union of the cascading map above.
$providerBarangays = [];
foreach ($municipalities as $list) {
    foreach ($list as $b) {
        $providerBarangays[] = $b;
    }
}
