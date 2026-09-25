<?php
// ============================================================
// create_profile.php — islaFIND Provider Profile (streamlined)
// A fast, three-part wizard for listing a service:
//   1. Profile type — INDIVIDUAL SKILLS or BUSINESS
//   2. Instant search — a live-filtering search bar of job
//      titles (individual) or business types (business)
//   3. Location + identity — barangay selector, plus a business
//      name field that only appears for BUSINESS listings.
// The account's name (for individuals), photo and phone are
// inherited automatically — the user never re-enters them.
// Submits to save_profile.php; this page only renders the form
// and any validation errors flashed back by the handler.
// ============================================================

// --- 1. Harden the session cookie, then start the session ------
require_once __DIR__ . '/security.php';
session_harden();
session_start();

// --- 2. Session guard: logged in? ------------------------------
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 3. Database connection + shared category lists ------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/categories.php';

// --- 4. Load the current user ----------------------------------
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch();

// If the account vanished, log the session out for safety.
if (!$user) {
    session_unset();
    session_destroy();
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 5. Which profile is being edited (if any)? -----------------
// The optional ?edit=<id> query chooses ONE of the user's own
// profiles to edit. A user can own several islaFIND profiles
// (e.g. an engineer who also runs a shop), so without an edit id
// this page is always in CREATE-NEW mode.
$editId = (int) ($_GET['edit'] ?? 0);
$provider = false;   // FALSE = create-new mode

if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM providers WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmt->execute([':id' => $editId, ':uid' => $user['id']]);
    $provider = $stmt->fetch();

    // The id must point at one of the user's OWN profiles.
    if (!$provider) {
        header('Location: ' . sid_append('dashboard.php?tab=isla'));
        exit;
    }
}

$csrf = htmlspecialchars(csrf_token());

// --- 6. Form state: prefill from the profile being edited -------
// save_profile.php stores rejected input + errors in a one-shot
// flash so this page can re-render them after a failed submit.
$errors   = [];
$oldInput = [
    'profile_type'        => $provider['profile_type']  ?? 'individual',
    'selected_title'      => $provider['selected_title'] ?? '',
    // Location: municipality cascades the barangay dropdown; the
    // coordinates come from the GPS "Pin My Current Location" button.
    // A saved profile prefills all of them in edit mode.
    'municipality'        => $provider['municipality'] ?? '',
    'barangay'            => $provider['barangay']      ?? '',
    'latitude'            => $provider['latitude']      ?? '',
    'longitude'           => $provider['longitude']     ?? '',
    'name'                => $provider['name']          ?? '',   // business only
    'profile_description' => $provider['profile_description'] ?? '',
    'unit_inventory'      => $provider ? (string) $provider['unit_inventory'] : '',
];

if (isset($_SESSION['profile_flash'])) {
    $flash = $_SESSION['profile_flash'];
    unset($_SESSION['profile_flash']);          // one-shot: consume it
    $errors   = $flash['errors']   ?? [];
    $oldInput = array_merge($oldInput, $flash['old'] ?? []);
}

// Helper: resolve the stored title slug back to its label so edit
// mode can prefill the search box with the human name.
$titleLabel = $providerCategories[$oldInput['selected_title']] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php
// Shared metadata (title, description, favicon, manifest, social
// preview) + style.css and busy.js live in one partial so every
// page ships the same head.
$headTitle = ($provider ? 'Edit' : 'Create') . ' Profile';
$headDesc  = 'List your skill or business on the islaFIND directory for Bantayan Island.';
include __DIR__ . '/head_meta.php';
?>
    <!-- No map library is loaded: the BUSINESS pin is placed on the
         real Google Maps in a separate tab (keyless), and the resulting
         coordinates are pasted back into the form. See maps_pinning.js. -->
</head>
<body class="auth-body">

    <header class="app-header">
        <a href="dashboard.php?tab=isla" class="chat-back" aria-label="Back to dashboard">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
        <span class="app-header-title"><?php echo $provider ? 'Edit' : 'Create'; ?> islaFIND Profile</span>
    </header>

    <main class="auth-section">
        <div class="auth-card">

            <!-- Top-level error banner (form-level failures) -->
            <?php if (isset($errors['form'])): ?>
                <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($errors['form']); ?></div>
            <?php endif; ?>

            <!-- The edit id rides along so save_profile.php knows
                 which profile to update (create mode sends none). -->
            <form action="save_profile.php<?php echo $editId ? '?edit=' . $editId : ''; ?>" method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

                <!-- ============ STEP 1 — PROFILE TYPE ============ -->
                <h4 class="sec-section">What are you listing?</h4>
                <p class="sec-hint">Choose between your personal skill or a business.</p>
                <?php if (isset($errors['profile_type'])): ?>
                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['profile_type']); ?></p>
                <?php endif; ?>
                <div class="type-grid">
                    <?php foreach ($profileTypes as $slug => $label): ?>
                        <label class="type-card<?php echo $oldInput['profile_type'] === $slug ? ' active' : ''; ?>">
                            <input type="radio" name="profile_type" value="<?php echo htmlspecialchars($slug); ?>"
                                   <?php echo $oldInput['profile_type'] === $slug ? 'checked' : ''; ?>>
                            <span class="type-card-title"><?php echo htmlspecialchars($label); ?></span>
                            <span class="type-card-sub">
                                <?php echo $slug === 'individual'
                                    ? 'Your personal trade or skill'
                                    : 'A company, shop or rental'; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <!-- ============ STEP 2 — INSTANT SEARCH TITLE ============ -->
                <h4 class="sec-section">Your title</h4>
                <p class="sec-hint">Search and pick the job title or business type.</p>
                <?php if (isset($errors['selected_title'])): ?>
                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['selected_title']); ?></p>
                <?php endif; ?>

                <!-- One searchable field per profile type; JS shows only the active one -->
                <?php foreach ($providerSubCategories as $type => $list): ?>
                    <div class="subcat-field" data-type="<?php echo htmlspecialchars($type); ?>"
                         style="<?php echo $oldInput['profile_type'] === $type ? '' : 'display:none'; ?>">
                        <div class="form-group subcat-field-group">
                            <div class="input-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                <input type="text" class="subcat-search" data-type="<?php echo htmlspecialchars($type); ?>"
                                       data-placeholder="Search <?php echo $type === 'individual' ? 'a skill' : 'a business type'; ?>…"
                                       placeholder="Search <?php echo $type === 'individual' ? 'a skill' : 'a business type'; ?>…"
                                       value="<?php echo $oldInput['profile_type'] === $type ? htmlspecialchars($titleLabel) : ''; ?>"
                                       autocomplete="off" aria-label="Search title">
                            </div>
                            <ul class="subcat-options" data-type="<?php echo htmlspecialchars($type); ?>">
                                <?php foreach ($list as $slug => $label): ?>
                                    <li>
                                        <button type="button" class="subcat-option" data-value="<?php echo htmlspecialchars($slug); ?>">
                                            <?php echo htmlspecialchars($label); ?>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- The hidden final value of step 2 (filled by JS) -->
                <input type="hidden" name="selected_title" id="titleInput"
                       value="<?php echo htmlspecialchars($oldInput['selected_title']); ?>">

                <!-- ============ STEP 3 — LOCATION & IDENTITY ============ -->
                <h4 class="sec-section">Location</h4>
                <p class="sec-hint">Pick your municipality, then its barangay — then mark the exact spot below.</p>

                <!-- Municipality: choosing one reloads the barangay
                     list below via maps_pinning.js (no page reload). -->
                <?php if (isset($errors['municipality'])): ?>
                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['municipality']); ?></p>
                <?php endif; ?>
                <div class="form-group">
                    <div class="input-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18"/><path d="M5 21V7l7-4v18"/><path d="M19 21V11l-7-4"/></svg>
                        <select name="municipality" id="municipalitySelect" aria-label="Municipality" required>
                            <option value="">Select municipality</option>
                            <?php foreach ($municipalities as $mun => $munBarangays): ?>
                                <option value="<?php echo htmlspecialchars($mun); ?>"
                                        <?php echo $oldInput['municipality'] === $mun ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($mun); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Barangay: the option list cascades from the
                     municipality above. In edit mode PHP pre-renders
                     the saved municipality's barangays so the form
                     is already resolved without JavaScript. -->
                <?php if (isset($errors['barangay'])): ?>
                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['barangay']); ?></p>
                <?php endif; ?>
                <div class="form-group">
                    <div class="input-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <select name="barangay" id="barangaySelect" aria-label="Barangay" required>
                            <option value="">Select barangay</option>
                            <?php $munBarangays = $municipalities[$oldInput['municipality']] ?? []; ?>
                            <?php foreach ($munBarangays as $b): ?>
                                <option value="<?php echo htmlspecialchars($b); ?>"
                                        <?php echo $oldInput['barangay'] === $b ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- ============ THE PIN ============
                     Two mutually exclusive ways to mark the spot, switched
                     by the profile type (maps_pinning.js):
                       - INDIVIDUAL SKILLS -> "Pin My Current Location":
                         the worker moves around, so their live GPS fix is
                         the answer.
                       - BUSINESS -> pin on Google Maps: a shop has ONE
                         fixed address, and the person listing it may not
                         even be standing there, so GPS is the wrong tool.
                         The owner opens Google Maps in a new tab, drops a
                         pin on the shop, then pastes the link or the
                         "lat, lng" numbers back here.
                     Both flows write the SAME hidden inputs below, so
                     save_profile.php cannot tell (and does not care)
                     which one produced the numbers. The BUSINESS pin is
                     REQUIRED — the listing's route button is built from
                     it, so the form refuses to save a pinless business.
                     The INDIVIDUAL GPS pin stays optional. BUSINESS
                     NEVER auto-pins from the owner's current location. -->
                <?php if (isset($errors['latitude']) || isset($errors['longitude'])): ?>
                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['latitude'] ?? $errors['longitude']); ?></p>
                <?php endif; ?>
                <input type="hidden" name="latitude" id="latitudeInput"
                       value="<?php echo htmlspecialchars((string) ($oldInput['latitude'] ?? '')); ?>">
                <input type="hidden" name="longitude" id="longitudeInput"
                       value="<?php echo htmlspecialchars((string) ($oldInput['longitude'] ?? '')); ?>">

                <!-- INDIVIDUAL SKILLS ONLY: GPS capture (the manual
                     Google Maps pin below is a business option). -->
                <div class="form-group pin-group" id="gpsPinGroup"
                     style="<?php echo $oldInput['profile_type'] === 'individual' ? '' : 'display:none'; ?>">
                    <button type="button" class="btn btn-pin" id="pinLocationBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Pin My Current Location
                    </button>
                    <!-- Reminder: the HAND-PASTED Google Maps pin is a
                         BUSINESS-only option, so an individual listing
                         never gets that box — its pin comes from the
                         device GPS (and may be skipped). maps_pinning.js
                         hides this line once a fix is captured; it is
                         already hidden here when an edit loads a pin. -->
                    <p class="map-picker-hint" id="gpsPinHint"<?php echo (($oldInput['latitude'] ?? '') !== '' && ($oldInput['longitude'] ?? '') !== '') ? ' hidden' : ''; ?>>Your pin comes from your device&rsquo;s GPS. Pasting a Google Maps pin by hand is a business-listing option only, so it is not offered here. You can also skip the pin and still save your listing.</p>
                </div>

                <!-- BUSINESS ONLY: manual pin on Google Maps. The link
                     opens Google Maps in a new tab (maps_pinning.js
                     points it at the chosen municipality); the owner
                     drops a pin, then pastes the link or the numbers
                     into the box. An INDIVIDUAL listing never gets this
                     block — see the GPS hint above. -->
                <div class="form-group" id="gmapsPinGroup"
                     style="<?php echo $oldInput['profile_type'] === 'business' ? '' : 'display:none'; ?>">
                    <a href="https://www.google.com/maps" target="_blank" rel="noopener"
                       class="btn btn-pin" id="openGmapsBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 20l-5.447-2.724A1 1 0 0 1 3 16.382V5.618a1 1 0 0 1 1.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0 0 21 18.382V7.618a1 1 0 0 0-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>
                        Pin my business on Google Maps
                    </a>
                    <label class="field-label" for="gmapsPaste" style="margin-top:12px;">Paste the Google Maps link or coordinates (required)</label>
                    <div class="input-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                        <input type="text" id="gmapsPaste" autocomplete="off"
                               placeholder="e.g. 11.297029, 123.730595"
                               aria-label="Google Maps link or coordinates">
                    </div>
                    <p class="map-picker-hint">Open Google Maps, find your shop and mark the exact spot &mdash; long-press or right-click it to copy the coordinates, or drop a pin and use Share &rarr; Copy link. Paste the link or the &quot;lat, lng&quot; numbers above and we&rsquo;ll save the pin and add a route button to your listing. A business listing must be pinned before it can be saved.</p>
                    <p class="map-picker-hint">This hand-pasted Google Maps pin is for business listings only &mdash; individual skills listings pin themselves with the device&rsquo;s GPS instead, so this box is never shown on them.</p>
                    <!-- Inline block message: maps_pinning.js reveals it
                         when a BUSINESS form is submitted with no pin.
                         save_profile.php enforces the same rule again. -->
                    <p class="field-error" id="businessPinError" hidden>
                        A business listing needs a location. Paste your Google Maps link or the &quot;lat, lng&quot; numbers above.
                    </p>
                </div>

                <!-- Shared status box: filled by whichever flow ran. -->
                <div class="pin-status" id="pinStatus"
                     <?php echo ($oldInput['latitude'] ?? '') !== '' ? '' : 'hidden'; ?>>
                    <span class="pin-status-icon" aria-hidden="true">&#9989;</span>
                    <span id="pinStatusText">
                        <?php if (($oldInput['latitude'] ?? '') !== ''): ?>
                            Pinned: <?php echo htmlspecialchars($oldInput['latitude']); ?>, <?php echo htmlspecialchars($oldInput['longitude']); ?>
                        <?php endif; ?>
                    </span>
                </div>

                <!-- Business name: only for BUSINESS listings (JS shows it) -->
                <?php if (isset($errors['name'])): ?>
                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['name']); ?></p>
                <?php endif; ?>
                <div class="form-group" id="businessNameGroup"
                     style="<?php echo $oldInput['profile_type'] === 'business' ? '' : 'display:none'; ?>">
                    <label class="field-label" for="bizName">Business name</label>
                    <div class="input-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l1-5h16l1 5"/><path d="M3 9v10a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V9"/><path d="M3 9h18"/><path d="M9 21v-4a3 3 0 0 1 6 0v4"/></svg>
                        <input type="text" name="name" id="bizName" maxlength="120" placeholder="e.g. KOTA PARK"
                               value="<?php echo htmlspecialchars($oldInput['name']); ?>" aria-label="Business name">
                    </div>
                </div>

                <!-- ============ CONTEXTUAL FIELD ============
                     The profile type decides which field is shown:
                     - INDIVIDUAL SKILLS -> a free-text description
                       (experience, rates, job specifics)
                     - BUSINESS -> an available-units count (rooms,
                       motorbikes, beds, hardware stock, ...)
                     The JS toggles the two below instantly when the
                     user switches the type card, no reload needed. -->

                <!-- Description (INDIVIDUAL SKILLS) -->
                <?php if (isset($errors['profile_description'])): ?>
                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['profile_description']); ?></p>
                <?php endif; ?>
                <div class="form-group contextual-field" id="descriptionGroup"
                     data-context="individual"
                     style="<?php echo $oldInput['profile_type'] === 'individual' ? '' : 'display:none'; ?>">
                    <label class="field-label" for="profileDescription">About your service</label>
                    <div class="input-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h10"/></svg>
                        <textarea name="profile_description" id="profileDescription" rows="3" maxlength="1000"
                                  placeholder="Tell clients about your experience, rates and what the job involves…"
                                  aria-label="About your service"><?php echo htmlspecialchars($oldInput['profile_description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Unit inventory (BUSINESS) -->
                <?php if (isset($errors['unit_inventory'])): ?>
                    <p class="field-error" role="alert"><?php echo htmlspecialchars($errors['unit_inventory']); ?></p>
                <?php endif; ?>
                <div class="form-group contextual-field" id="unitGroup"
                     data-context="business"
                     style="<?php echo $oldInput['profile_type'] === 'business' ? '' : 'display:none'; ?>">
                    <label class="field-label" for="unitInventory">Units available</label>
                    <div class="input-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                        <input type="number" name="unit_inventory" id="unitInventory" min="0" max="999999"
                               placeholder="e.g. 12 rooms, 8 motorbikes, 20 beds"
                               value="<?php echo htmlspecialchars($oldInput['unit_inventory'] ?? ''); ?>"
                               aria-label="Units available">
                    </div>
                </div>

                <!-- Account sync notice: what the card will inherit -->
                <div class="sync-notice">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    <span id="syncNoticeText">
                        <?php echo $oldInput['profile_type'] === 'business'
                            ? 'Your account photo and contact number will be shown on this listing.'
                            : 'Your name, photo and contact number from your account will be shown on this listing.'; ?>
                    </span>
                </div>

                <button type="submit" class="btn btn-isla" data-loading-label="Saving…">
                    <?php echo $provider ? 'Save Changes' : 'Create islaFIND Profile'; ?>
                </button>
                <a href="dashboard.php?tab=isla" class="btn btn-outline">Cancel</a>
            </form>
        </div>
    </main>

    <!-- GPS permission dialog: the app asks the user BEFORE the
         browser fires its own geolocation prompt. It explains why
         the exact location is used and nothing is captured unless
         the user allows both this dialog and the browser/OS prompt. -->
    <div class="modal" id="gpsPermissionModal" hidden>
        <div class="modal-backdrop" data-gps-close></div>
        <div class="modal-card">
            <button type="button" class="modal-close" data-gps-close aria-label="Close">&times;</button>
            <h4>&#128205; Pin my current location</h4>
            <p class="sec-hint">islaFIND would like to use your device's GPS to pin your
                <strong>exact location</strong> on your listing map. No location is captured
                unless you allow it — you can skip this now and still save your listing.</p>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" id="gpsDenyBtn">Not now</button>
                <button type="button" class="btn btn-isla" id="gpsAllowBtn">Allow GPS access</button>
            </div>
        </div>
    </div>

    <!-- Step 1 / Step 2 interactivity (type toggle + live search) -->
    <script src="profile_script.js"></script>

    <!-- Cascading address data (step 3) — rendered from the same
         $municipalities array in categories.php, so the JS dropdown
         always matches server-side validation. maps_pinning.js reads
         this global and rebuilds the barangay list on change. -->
    <script>
        window.ISLAFIND_LOCATIONS = <?php echo json_encode($municipalities, JSON_UNESCAPED_UNICODE); ?>;
        // Where the BUSINESS "open Google Maps" link lands before
        // anything is pinned: the chosen municipality's town centre.
        // Rendered from the same categories.php array as the dropdowns
        // above, so the client never keeps a second hardcoded copy.
        window.ISLAFIND_CENTERS = <?php echo json_encode($municipalityCenters, JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <!-- Step 3 location logic (municipality cascade + GPS pin +
         business Google Maps pin) -->
    <script src="maps_pinning.js"></script>

</body>
</html>
