<?php
// ============================================================
// save_profile.php — islaFIND profile backend handler
// Receives the streamlined form from create_profile.php and:
//   1. Validates the profile type, selected title, barangay,
//      and (for BUSINESS listings) the business name AND the
//      mandatory Google Maps pin
//   2. Inserts a new provider row OR updates the one selected
//      via ?edit=<id>, using PDO prepared statements
//   3. Redirects back to the dashboard with a success banner
// Phone and profile picture are NOT collected here — the cards
// inherit them from the user's account (users table).
// On validation failure it stores the errors + submitted values
// in a one-shot flash and returns to create_profile.php.
// ============================================================

// --- 1. Harden the session cookie, then start the session ------
require_once __DIR__ . '/../include/security.php';
session_harden();
session_start();

// --- 2. Session guard: logged in? ------------------------------
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 3. Database connection + shared category lists ------------
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/categories.php';

// --- 4. Load the current user ----------------------------------
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_unset();
    session_destroy();
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 4b. Which profile is being edited (if any)? -----------------
// The optional ?edit=<id> query (carried through from the form)
// selects ONE of the user's own profiles to UPDATE. Without it the
// handler always INSERTs a brand-new profile, so one person can
// hold several listings (engineer + shop, etc.).
$editId   = (int) ($_GET['edit'] ?? 0);
$provider = false;   // FALSE = create-new mode

if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM providers WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmt->execute([':id' => $editId, ':uid' => $user['id']]);
    $provider = $stmt->fetch();

    // Editing someone else's profile is not allowed.
    if (!$provider) {
        $_SESSION['profile_flash'] = [
            'errors' => ['form' => 'That profile does not exist or is not yours.'],
            'old'    => [],
        ];
        header('Location: ' . sid_append('create_profile.php'));
        exit;
    }
}

// --- 5. Method + CSRF guard ------------------------------------
// Only a POST carrying a valid token may save; a forged request
// cannot know the session token, so it is rejected outright.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    $_SESSION['profile_flash'] = [
        'errors' => ['form' => 'Your session expired or the form token is invalid. Please try again.'],
        'old'    => [],
    ];
    header('Location: ' . sid_append('create_profile.php'));
    exit;
}

// --- 6. Collect the submitted values ---------------------------
$oldInput = [
    'profile_type'        => $_POST['profile_type']   ?? '',
    'selected_title'      => $_POST['selected_title'] ?? '',
    'municipality'        => $_POST['municipality']   ?? '',
    'barangay'            => $_POST['barangay']       ?? '',
    'latitude'            => trim($_POST['latitude']  ?? ''),   // GPS pin (optional)
    'longitude'           => trim($_POST['longitude'] ?? ''),   // GPS pin (optional)
    'name'                => trim($_POST['name']      ?? ''),   // business only
    'profile_description' => trim($_POST['profile_description'] ?? ''),
    'unit_inventory'      => trim($_POST['unit_inventory'] ?? ''),
];
$errors = [];

// --- 7. Validation ---------------------------------------------
// (a) Profile type must be one of the two known values.
if (!isset($profileTypes[$oldInput['profile_type']])) {
    $errors['profile_type'] = 'Please choose Individual Skills or Business.';
}

// (b) Title must exist inside the list of the chosen type.
$typeLists = $providerSubCategories[$oldInput['profile_type']] ?? [];
if (!isset($typeLists[$oldInput['selected_title']])) {
    $errors['selected_title'] = 'Please search and pick a title.';
}

// (c) Location: the municipality must exist AND the barangay must
// belong to that municipality (the cascading dropdown on the form
// enforces this too — this is the server-side backstop).
if (!isset($municipalities[$oldInput['municipality']])) {
    $errors['municipality'] = 'Please choose your municipality.';
} elseif (!in_array($oldInput['barangay'], $municipalities[$oldInput['municipality']], true)) {
    $errors['barangay'] = 'Please choose a barangay under the selected municipality.';
}

// (c2) Location pin: if ONE coordinate was submitted, BOTH must be
// present and be valid numbers within real-world ranges.
// A BUSINESS listing MUST carry a pin — a shop has ONE fixed spot and
// the directory builds its "Get Route" button from those numbers, so
// it can never be published without one. INDIVIDUAL SKILLS may still
// skip the pin: their GPS fix is a convenience, not a requirement.
$isBusiness = ($oldInput['profile_type'] === 'business');

if ($oldInput['latitude'] !== '' || $oldInput['longitude'] !== '') {
    $latOk = is_numeric($oldInput['latitude']) && $oldInput['latitude'] >= -90 && $oldInput['latitude'] <= 90;
    $lngOk = is_numeric($oldInput['longitude']) && $oldInput['longitude'] >= -180 && $oldInput['longitude'] <= 180;
    if (!$latOk || !$lngOk) {
        $errors['latitude'] = 'Please pin a valid location.';
    }
} elseif ($isBusiness) {
    // No coordinates at all on a business listing.
    $errors['latitude'] = 'Please pin your business on Google Maps.';
}

// (d) Business name: required ONLY for BUSINESS listings. An
//     individual listing shows the account holder's name instead.
if ($oldInput['profile_type'] === 'business') {
    if ($oldInput['name'] === '') {
        $errors['name'] = 'Please enter your business name.';
    } elseif (strlen($oldInput['name']) > 120) {
        $errors['name'] = 'Business name must be 120 characters or fewer.';
    } elseif (contains_html_tag($oldInput['name'])) {
        // Markup is refused rather than stored: a name is plain text,
        // and the character is invalid there anyway.
        $errors['name'] = 'The business name cannot contain HTML tags or scripts.';
    }
}

// (e) Contextual field: each profile type carries its OWN field.
//     - INDIVIDUAL SKILLS stores a free-text description.
//     - BUSINESS stores an available-units count.
//     The field for the OTHER type is ignored (and cleared on save).
if ($oldInput['profile_type'] === 'individual') {
    if ($oldInput['profile_description'] === '') {
        $errors['profile_description'] = 'Please tell clients about your service.';
    } elseif (mb_strlen($oldInput['profile_description']) > 1000) {
        $errors['profile_description'] = 'Description must be 1000 characters or fewer.';
    } elseif (contains_html_tag($oldInput['profile_description'])) {
        // No markup in the description. Escaping already makes this
        // safe to display — refusing it here means a tag like
        // <script>alert('XSS')</script> is never stored or listed at
        // all. Plain text such as "units < 2 tons" still passes.
        $errors['profile_description'] = 'The description cannot contain HTML tags or scripts.';
    }
} else {
    // BUSINESS: the unit count must be a non-negative whole number.
    if ($oldInput['unit_inventory'] === '') {
        $errors['unit_inventory'] = 'Please enter how many units are available.';
    } elseif (!ctype_digit($oldInput['unit_inventory'])) {
        $errors['unit_inventory'] = 'Units available must be a whole number.';
    } elseif ((int) $oldInput['unit_inventory'] > 999999) {
        $errors['unit_inventory'] = 'Units available is too large.';
    }
}

// --- 8. Validation failed? Return to the form with errors ------
if (!empty($errors)) {
    $_SESSION['profile_flash'] = ['errors' => $errors, 'old' => $oldInput];
    header('Location: ' . sid_append('create_profile.php'));
    exit;
}

// --- 9. Insert OR update the profile (PDO prepared statements) -
// The contextual field is stored in the column that matches the
// profile type: profile_description for INDIVIDUAL SKILLS, and
// unit_inventory for BUSINESS. The OTHER column is always cleared
// to NULL so a listing never carries a stale field from a type
// switch. For INDIVIDUAL listings the name stays NULL — cards fall
// back to the account holder's full name. All values are bound as
// parameters, so user input can never be interpreted as SQL.
// ($isBusiness was resolved during validation above.)
$description = $isBusiness ? null : ($oldInput['profile_description'] !== '' ? $oldInput['profile_description'] : null);
$units       = $isBusiness ? (int) $oldInput['unit_inventory'] : null;

// (9b) Resolve the location columns. Coordinates are stored as-is
// (NULL when an INDIVIDUAL listing skipped the pin — a BUSINESS one
// always carries them at this point), and a Google Maps directions
// deep link is built from them so the directory's "Get Directions"
// button works even with JavaScript off.
//
// The stored link is DESTINATION-ONLY on purpose: a starting point is
// personal to whoever taps it, so the live page rebuilds the URL with
// the visitor's own origin (see openProviderDetail in dashboard.php)
// rather than freezing one visitor's position into the database.
$latitude  = $oldInput['latitude']  !== '' ? (float) $oldInput['latitude']  : null;
$longitude = $oldInput['longitude'] !== '' ? (float) $oldInput['longitude'] : null;
$mapsUrl   = ($latitude !== null && $longitude !== null)
    ? 'https://www.google.com/maps/dir/?api=1&destination=' . $latitude . ',' . $longitude
    : null;

if ($provider) {
    $stmt = $pdo->prepare(
        'UPDATE providers
         SET profile_type = :ptype, name = :name,
             profile_description = :desc, unit_inventory = :units,
             selected_title = :title, barangay = :barangay,
             municipality = :mun, latitude = :lat, longitude = :lng,
             google_maps_url = :maps
         WHERE id = :id'
    );
    $stmt->execute([
        ':ptype'    => $oldInput['profile_type'],
        ':name'     => $isBusiness ? $oldInput['name'] : null,
        ':desc'     => $description,
        ':units'    => $units,
        ':title'    => $oldInput['selected_title'],
        ':barangay' => $oldInput['barangay'],
        ':mun'      => $oldInput['municipality'],
        ':lat'      => $latitude,
        ':lng'      => $longitude,
        ':maps'     => $mapsUrl,
        ':id'       => $provider['id'],
    ]);
    $msg = 'Your islaFIND profile was updated.';
} else {
    $stmt = $pdo->prepare(
        'INSERT INTO providers
         (user_id, profile_type, name, profile_description, unit_inventory,
          selected_title, barangay, municipality, latitude, longitude, google_maps_url)
         VALUES (:uid, :ptype, :name, :desc, :units, :title, :barangay,
                 :mun, :lat, :lng, :maps)'
    );
    $stmt->execute([
        ':uid'      => $user['id'],
        ':ptype'    => $oldInput['profile_type'],
        ':name'     => $isBusiness ? $oldInput['name'] : null,
        ':desc'     => $description,
        ':units'    => $units,
        ':title'    => $oldInput['selected_title'],
        ':barangay' => $oldInput['barangay'],
        ':mun'      => $oldInput['municipality'],
        ':lat'      => $latitude,
        ':lng'      => $longitude,
        ':maps'     => $mapsUrl,
    ]);
    $msg = 'Your islaFIND profile was created!';
}

// --- 10. Success flash + redirect back to the dashboard ---------
$_SESSION['flash_isla'] = ['type' => 'success', 'msg' => $msg];
header('Location: ' . sid_append('dashboard.php?tab=isla'));
exit;
