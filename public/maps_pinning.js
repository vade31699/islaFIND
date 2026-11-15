// ============================================================
// maps_pinning.js — islaFIND location logic (create_profile.php)
// Powers step 3 of the profile form:
//   1. Cascading address: choosing a MUNICIPALITY reloads the
//      BARANGAY dropdown with only that municipality's barangays
//      (including the offshore islet barangays).
//   2. INDIVIDUAL SKILLS — "Pin My Current Location": asks the
//      user's permission first (an in-app dialog), then calls the
//      browser Geolocation API (which also fires its own browser/OS
//      permission prompt for the exact GPS fix) and captures the
//      coordinates into hidden form fields, with a friendly status
//      message + Google Maps preview link.
//   3. BUSINESS — a GOOGLE MAPS PIN (REQUIRED): with a browser API key
//      (GOOGLE_MAPS_API_KEY) the form embeds an interactive Google Map
//      centred on the chosen municipality. The owner searches for the
//      shop, taps the map or drags the pin; the coordinates go into the
//      same hidden fields and a submit with no pin is blocked. Without a
//      key the older flow is used: open Google Maps in a new tab, drop a
//      pin, then paste the link or the "lat, lng" numbers back. GPS is the wrong tool here: a shop has ONE fixed
//      address, and the person listing it may not even be standing in
//      it, so a business is NEVER auto-pinned from the owner's current
//      location.
// The TWO capture flows are mutually exclusive and switched by the
// profile-type radio. Both write the SAME hidden latitude/longitude
// inputs, so save_profile.php cannot tell (and does not care) which
// one produced the numbers.
// The municipality -> barangays map and the municipality map centres
// are rendered into the page by create_profile.php from
// categories.php, so the client and the server always agree on the
// same lists (single source of truth). The centres are what the
// "open Google Maps" link uses to land near the owner's shop.
// ============================================================

(function () {
    'use strict';

    // --- 1. Grab the form elements this script drives ----------
    // Each element is looked up by id; the guards (if/return) keep
    // the script harmless if the markup ever changes.
    const munSelect = document.getElementById('municipalitySelect'); // municipality dropdown
    const bgySelect = document.getElementById('barangaySelect');     // barangay dropdown (cascades)
    const latInput  = document.getElementById('latitudeInput');      // hidden latitude field
    const lngInput  = document.getElementById('longitudeInput');     // hidden longitude field
    const pinBtn    = document.getElementById('pinLocationBtn');     // "Pin My Current Location" button
    const pinStatus = document.getElementById('pinStatus');          // status box (hidden until used)
    const pinStatusText = document.getElementById('pinStatusText');   // status message span
    const gpsModal  = document.getElementById('gpsPermissionModal'); // in-app GPS permission dialog
    const gpsGroup  = document.getElementById('gpsPinGroup');        // GPS button wrapper (INDIVIDUAL)
    const gmapsGroup = document.getElementById('gmapsPinGroup');      // Google Maps pin block (BUSINESS)
    const openGmapsBtn = document.getElementById('openGmapsBtn');     // "open Google Maps" link
    const gmapsPaste = document.getElementById('gmapsPaste');         // paste-back input

    // The pin button's ORIGINAL markup. The busy state swaps the
    // label but keeps the pin icon, and reset restores the exact
    // idle markup afterwards.
    const pinIdleHtml = pinBtn ? pinBtn.innerHTML : '';

    // The municipality -> barangay map injected by create_profile.php
    // (from $municipalities in categories.php). Fall back to {} so a
    // missing global never crashes the script.
    const locations = window.ISLAFIND_LOCATIONS || {};

    // --- 2. Helper: repopulate the barangay dropdown ------------
    // Empties the barangay <select> and refills it with the
    // barangays that belong to the currently chosen municipality.
    function rebuildBarangays() {
        // The municipality that is currently selected ('' if none).
        const mun = munSelect ? munSelect.value : '';

        // Clear every existing <option> so stale barangays from a
        // previous municipality cannot be submitted by mistake.
        bgySelect.innerHTML = '';

        // Always keep a blank placeholder first ("Select barangay"),
        // which also acts as the required-field prompt.
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select barangay';
        bgySelect.appendChild(placeholder);

        // Add one <option> per barangay under the chosen municipality
        // (locations[mun] is undefined for unknown values -> []).
        (locations[mun] || []).forEach(function (name) {
            const opt = document.createElement('option');
            opt.value = name;              // stored value == visible label
            opt.textContent = name;        // human-readable name
            bgySelect.appendChild(opt);
        });

        // A municipality switch invalidates any previously picked
        // barangay, so reset the selection to the placeholder.
        bgySelect.value = '';
    }

    // --- 3. Cascade wiring: municipality change -> rebuild ------
    if (munSelect && bgySelect) {
        // 'change' fires when the user picks a municipality from the
        // dropdown (after the value has already been updated).
        munSelect.addEventListener('change', function () {
            rebuildBarangays();
            // The GPS pin is independent of the address dropdowns, so
            // captured coordinates are intentionally left untouched.
            //
            // The "open Google Maps" link DOES follow the municipality,
            // so the owner lands near their shop without retyping it.
            updateGmapsHref();
            // The embedded map follows it too, while no pin is placed.
            syncMapToMunicipality();
        });
    }

    // --- 4. Helpers: status + button state ----------------------
    // Show a status message in the pin box. Replaces whatever was
    // shown before (textContent assignment wipes old children, so
    // preview links never stack up).
    function showStatus(text) {
        if (!pinStatus || !pinStatusText) { return; }
        pinStatusText.textContent = text;
        pinStatus.hidden = false;   // reveal the mint-green status box
    }

    // Put the pin button back to its idle state after a GPS request
    // finishes (success OR error) so the user can pin again.
    function resetPinButton() {
        if (!pinBtn) { return; }
        pinBtn.disabled = false;
        pinBtn.classList.remove('is-loading');
        pinBtn.innerHTML = pinIdleHtml;   // restore the icon + label
    }

    // --- 5. Success callback: GPS position received -------------
    // Called by the browser with the user's position once permission
    // is granted. Stores the coordinates into the hidden inputs so
    // they ride along with the form, then shows a confirmation that
    // includes the place name (e.g. "Poblacion, Madridejos") when the
    // address can be resolved.
    function onPosition(pos) {
        // Round to 6 decimals (~0.1 m precision) — enough for map
        // navigation and keeps the stored values tidy.
        const lat = pos.coords.latitude.toFixed(6);
        const lng = pos.coords.longitude.toFixed(6);

        // Drop the coordinates into the hidden fields the form posts
        // to save_profile.php.
        if (latInput) { latInput.value = lat; }
        if (lngInput) { lngInput.value = lng; }

        // Remember that these coordinates were captured automatically
        // from GPS, so switching to BUSINESS can discard them instead
        // of treating the owner's current location as the shop's pin.
        gpsCaptured = true;

        // Show the captured spot immediately (with whatever
        // municipality/barangay is currently picked), then enrich it
        // with the real place name when the reverse lookup returns.
        setPinStatus(lat, lng, currentPlace());

        // Reverse geocode the pin so the Municipality + Barangay
        // dropdowns are filled from the GPS fix too. This is purely
        // a convenience: if the lookup fails the user can still pick
        // the address manually, and the coordinates remain saved.
        reverseGeocode(lat, lng).then(function (place) {
            if (place && place.mun && place.bgy) {
                // Update the two dropdowns to the matched place.
                if (munSelect && munSelect.value !== place.mun) {
                    munSelect.value = place.mun;
                    munSelect.dispatchEvent(new Event('change'));
                }
                if (bgySelect) {
                    bgySelect.value = place.bgy;
                }
                // Refresh the status text with the resolved name.
                setPinStatus(lat, lng, place.bgy + ', ' + place.mun);
            }
        });

        resetPinButton();                // the pin request is done
    }

    // Show "<label>: lat, lng — Poblacion, Madridejos — View on map".
    // The label names the flow that captured the point ("Coordinates
    // captured" for GPS, "Pinned on map" for the business map), and
    // the place label is appended only when it is known.
    function setPinStatus(lat, lng, placeLabel, label) {
        let msg = (label || 'Coordinates captured') + ': ' + lat + ', ' + lng;
        if (placeLabel) { msg += ' — ' + placeLabel; }
        showStatus(msg + ' — ');

        // Append a tappable "View on map" preview link. This is a
        // PREVIEW of the point the owner just pinned, not a route, so
        // it opens the spot itself. (A directions link here would also
        // have to guess a starting point — that belongs to whoever
        // later taps "Get Directions" on the listing.)
        const url = 'https://www.google.com/maps/search/?api=1&query='
            + encodeURIComponent(lat) + ',' + encodeURIComponent(lng);
        const link = document.createElement('a');
        link.href = url;                 // the deep link to Google Maps
        link.target = '_blank';          // open in a new tab (keep the form)
        link.rel = 'noopener';           // security: no window handle access
        link.textContent = 'View on map';
        pinStatusText.appendChild(link);
    }

    // The place currently selected in the address dropdowns, if any.
    function currentPlace() {
        const mun = munSelect ? munSelect.value : '';
        const bgy = bgySelect ? bgySelect.value : '';
        return (bgy && mun) ? bgy + ', ' + mun : '';
    }

    // --- 5b. Reverse geocoding (free OSM/Nominatim, no API key) ---
    // Converts the captured coordinates back into a place name and
    // matches it against the app's own municipality -> barangay list
    // (window.ISLAFIND_LOCATIONS) so dropdown selection never invents
    // an address the app does not know. Returns a Promise of
    // { mun, bgy } or null.
    function normalizeName(s) {
        return (s || '').toLowerCase()
            .replace(/[’']/g, '')
            .replace(/[^a-zñ0-9 ]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function reverseGeocode(lat, lng) {
        return new Promise(function (resolve) {
            const ctrl = new AbortController();
            const timer = setTimeout(function () { ctrl.abort(); }, 8000);

            fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2'
                    + '&accept-language=en'
                    + '&lat=' + encodeURIComponent(lat)
                    + '&lon=' + encodeURIComponent(lng),
                { signal: ctrl.signal })
                .then(function (res) {
                    clearTimeout(timer);
                    if (!res.ok) { throw new Error('reverse geocode status ' + res.status); }
                    return res.json();
                })
                .then(function (data) {
                    let full = '';
                    if (data && data.address && typeof data.address === 'object') {
                        full = Object.keys(data.address)
                            .map(function (k) { return data.address[k]; })
                            .filter(Boolean)
                            .join(' ').toLowerCase();
                    }

                    const muns = Object.keys(window.ISLAFIND_LOCATIONS || {});
                    let mun = '';
                    for (let i = 0; i < muns.length; i++) {
                        if (full.indexOf(muns[i].toLowerCase()) !== -1) { mun = muns[i]; break; }
                    }

                    let bgy = '';
                    if (mun) {
                        const list = window.ISLAFIND_LOCATIONS[mun] || [];
                        for (let j = 0; j < list.length; j++) {
                            const n = normalizeName(list[j]);
                            if (n !== '' && full.indexOf(n) !== -1) { bgy = list[j]; break; }
                        }
                    }

                    if (mun && bgy) { resolve({ mun: mun, bgy: bgy }); }
                    else { resolve(null); }
                })
                .catch(function () {
                    clearTimeout(timer);
                    resolve(null);        // offline / blocked -> user picks manually
                });
        });
    }

    // --- 5c. BUSINESS: pin on Google Maps (no API key) ----------
    // BUSINESS-ONLY: this hand-pasted pin is never offered to an
    // INDIVIDUAL listing, which is pinned from the device GPS (5a/5b).
    // A business has ONE fixed address, so instead of reading the
    // owner's GPS we have them mark the exact spot on the REAL Google
    // Maps. Google does not let a page read a dropped pin back without
    // the paid JavaScript API, so the flow is: open Google Maps in a new
    // tab (centred on the chosen municipality), drop a pin on the shop,
    // then paste the link — or the plain "lat, lng" numbers — into the
    // box below. We parse whatever they paste into the SAME hidden
    // inputs the GPS flow writes, so save_profile.php cannot tell (and
    // does not care) which one produced the numbers.

    // TRUE once the INDIVIDUAL GPS flow has written a coordinate pair.
    // Used to drop that automatic fix if the user then switches to
    // BUSINESS, which must be pinned by hand.
    let gpsCaptured = false;

    // Fallback view: the whole of Bantayan Island, used when no
    // municipality is chosen yet (or its centre is unknown).
    const ISLAND_CENTER = { lat: 11.19, lng: 123.76 };

    // Is BUSINESS the selected profile type right now?
    function isBusinessType() {
        const radio = document.querySelector('input[name="profile_type"]:checked');
        return !!radio && radio.value === 'business';
    }

    // Where Google Maps should open: the chosen municipality's town
    // centre (window.ISLAFIND_CENTERS, rendered from categories.php), or
    // the island centre when nothing is chosen yet.
    function currentCenter() {
        const mun = munSelect ? munSelect.value : '';
        const c   = (window.ISLAFIND_CENTERS || {})[mun];
        return (c && typeof c.lat === 'number' && typeof c.lng === 'number')
            ? c
            : ISLAND_CENTER;
    }

    // Keep the "Pin my business on Google Maps" link pointed at the
    // chosen municipality, so the owner lands near their shop.
    function updateGmapsHref() {
        if (!openGmapsBtn) { return; }
        const c = currentCenter();
        openGmapsBtn.href =
            'https://www.google.com/maps/@' + c.lat + ',' + c.lng + ',14z';
    }

    // Pull a lat/lng pair out of whatever the owner pasted. Accepts
    // plain numbers ("11.297029, 123.730595") and the shapes a Google
    // Maps link uses:
    //   .../@11.297029,123.730595,15z        (the visible viewport)
    //   ...?q=11.297029,123.730595           (a searched point)
    //   ...!3d11.297029!4d123.730595         (a dropped pin's real spot)
    // Returns { lat, lng } or null.
    function parseLatLng(text) {
        if (!text) { return null; }
        const t = String(text).trim();

        // Accept only real-world coordinates; anything else is ignored.
        function ok(lat, lng) {
            lat = parseFloat(lat);
            lng = parseFloat(lng);
            if (!isFinite(lat) || !isFinite(lng)) { return null; }
            if (lat < -90 || lat > 90 || lng < -180 || lng > 180) { return null; }
            return { lat: lat, lng: lng };
        }

        let m;
        // A dropped pin's exact spot (!3dLAT!4dLNG) — the best match.
        if ((m = t.match(/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/))) {
            return ok(m[1], m[2]);
        }
        // The @lat,lng,zoom viewport in a Maps URL.
        if ((m = t.match(/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/))) {
            return ok(m[1], m[2]);
        }
        // A ?q= / ?query= / ?ll= / ?destination= parameter.
        if ((m = t.match(/[?&](?:q|query|ll|destination)=(-?\d+(?:\.\d+)?)(?:,|%2C)\s*(-?\d+(?:\.\d+)?)/i))) {
            return ok(m[1], m[2]);
        }
        // Plain "lat, lng" (comma- or space-separated) numbers.
        if ((m = t.match(/(-?\d{1,3}(?:\.\d+)?)\s*[, ]\s*(-?\d{1,3}(?:\.\d+)?)/))) {
            return ok(m[1], m[2]);
        }
        return null;
    }

    // Read the paste box and store the resulting pin. Clearing the box
    // clears the pin too, so the owner can remove one they mis-pasted.
    function applyPastedPin() {
        if (!gmapsPaste) { return; }

        if (gmapsPaste.value.trim() === '') {
            if (latInput) { latInput.value = ''; }
            if (lngInput) { lngInput.value = ''; }
            if (pinStatus) { pinStatus.hidden = true; }
            hideMapPin();
            return;
        }

        const pin = parseLatLng(gmapsPaste.value);
        if (!pin) {
            showStatus('Could not read that link. Paste a Google Maps link or the "lat, lng" numbers.');
            return;
        }

        // 6 decimals (~0.1 m) — the same precision the GPS flow stores.
        // Reuse the map's own writer, so a pasted pin also moves the
        // marker when the embedded map is present.
        setMapPin(pin.lat, pin.lng, true);
    }

    // --- 5c2. BUSINESS: embedded Google Map (when a key exists) --
    // The modern replacement for the paste-a-link flow. When
    // GOOGLE_MAPS_API_KEY is configured, create_profile.php renders
    // #gmapsMap and loads the Maps JavaScript API with the
    // islaInitGmaps callback below. Without a key the container does
    // not exist, every function here is a no-op, and the paste box
    // keeps working exactly as before.
    //
    // The map is only built while a BUSINESS listing is selected: the
    // whole block is display:none for an INDIVIDUAL listing, and a map
    // created inside a hidden box would measure 0×0 and render blank.

    const mapEl = document.getElementById('gmapsMap');
    let gmap = null;            // the google.maps.Map instance
    let gmarker = null;         // the draggable pin
    let gmapsReady = false;     // the JS API called us back yet?
    let gmapsBuilding = false;  // re-entry guard for buildMap()

    // Far enough out to see the barangay, close enough to place a pin
    // on one shop.
    const MAP_ZOOM = 15;

    // Where the map should open: the saved/picked pin when there is
    // one, otherwise the chosen municipality's town centre.
    function mapCentre() {
        const lat = latInput ? parseFloat(latInput.value) : NaN;
        const lng = lngInput ? parseFloat(lngInput.value) : NaN;
        if (isFinite(lat) && isFinite(lng)
                && Math.abs(lat) <= 90 && Math.abs(lng) <= 180) {
            return { lat: lat, lng: lng };
        }
        return currentCenter();
    }

    // Write a coordinate pair from the map into the SAME hidden fields
    // the GPS and paste flows use, then show the same status line.
    function setMapPin(lat, lng, moveMap) {
        const lat6 = Number(lat).toFixed(6);
        const lng6 = Number(lng).toFixed(6);
        if (latInput) { latInput.value = lat6; }
        if (lngInput) { lngInput.value = lng6; }

        setPinStatus(lat6, lng6, currentPlace(), 'Pinned on Google Maps');
        if (pinError) { pinError.hidden = true; }

        if (gmarker) {
            gmarker.setPosition({ lat: Number(lat), lng: Number(lng) });
            gmarker.setVisible(true);
        }
        if (gmap && moveMap) {
            gmap.panTo({ lat: Number(lat), lng: Number(lng) });
        }
    }

    // Remove the marker when the coordinates are cleared (switching
    // away from an automatic GPS fix, or emptying the paste box).
    function hideMapPin() {
        if (gmarker) { gmarker.setVisible(false); }
    }

    // Build the map, the pin and the place search once. Safe to call
    // repeatedly; it also shows an existing saved pin in edit mode.
    function buildMap() {
        if (!mapEl || gmap || gmapsBuilding) { return; }
        if (!gmapsReady || !(window.google && google.maps) || !isBusinessType()) { return; }

        gmapsBuilding = true;
        try {
            const start = mapCentre();
            gmap = new google.maps.Map(mapEl, {
                center: start,
                zoom: MAP_ZOOM,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
                clickableIcons: false,
            });

            gmarker = new google.maps.Marker({
                map: gmap,
                position: start,
                draggable: true,
                visible: false,
                title: 'Your business location',
            });

            // Tap anywhere on the map to drop (or move) the pin there.
            gmap.addListener('click', function (e) {
                setMapPin(e.latLng.lat(), e.latLng.lng(), false);
            });

            // Dragging the pin updates the stored coordinates.
            gmarker.addListener('dragend', function () {
                const p = gmarker.getPosition();
                if (p) { setMapPin(p.lat(), p.lng(), false); }
            });

            // A saved pin (edit mode) opens already shown.
            const lat = latInput ? parseFloat(latInput.value) : NaN;
            const lng = lngInput ? parseFloat(lngInput.value) : NaN;
            if (isFinite(lat) && isFinite(lng)) {
                setMapPin(lat, lng, false);
            }

            initPlaceSearch();
        } catch (err) {
            // A map that fails must never stop the form: the paste
            // fallback under the map still works.
            console.error('islaFIND: map failed to start', err);
        } finally {
            gmapsBuilding = false;
        }
    }

    // Type a business/address, pick a suggestion, and the pin jumps
    // there. Uses the current PlaceAutocompleteElement when the key has
    // access to it, and the legacy Autocomplete widget otherwise.
    function initPlaceSearch() {
        const wrap = document.getElementById('gmapsSearch');
        if (!wrap) { return; }

        // With async loading the `places` library can land a moment
        // after the map callback; importLibrary() waits for it. If
        // neither path is available the map still pins by tap/drag.
        if (!google.maps.places && google.maps.importLibrary) {
            google.maps.importLibrary('places')
                .then(function () { if (google.maps.places) { addPlaceSearch(wrap); } })
                .catch(function () {});
            return;
        }
        if (!google.maps.places) { return; }
        addPlaceSearch(wrap);
    }

    function addPlaceSearch(wrap) {
        function usePlace(place) {
            if (!place) { return; }
            const loc = place.location || (place.geometry && place.geometry.location);
            if (!loc) { return; }
            const lat = typeof loc.lat === 'function' ? loc.lat() : loc.lat;
            const lng = typeof loc.lng === 'function' ? loc.lng() : loc.lng;
            if (typeof lat !== 'number' || typeof lng !== 'number') { return; }
            setMapPin(lat, lng, true);
            if (gmap) { gmap.setZoom(17); }
        }

        if (google.maps.places.PlaceAutocompleteElement) {
            const el = new google.maps.places.PlaceAutocompleteElement();
            el.placeholder = 'Search your business name or address';
            wrap.appendChild(el);

            const onPick = function (ev) {
                const pred = ev.placePrediction
                    || (ev.detail && ev.detail.placePrediction);
                if (!pred) { return; }
                const place = pred.toPlace ? pred.toPlace() : pred;
                if (place.fetchFields) {
                    place.fetchFields({ fields: ['location'] })
                        .then(function () { usePlace(place); })
                        .catch(function () {});
                } else {
                    usePlace(place);
                }
            };

            el.addEventListener('gmp-select', onPick);
            el.addEventListener('gmp-placeselect', onPick);
            return;
        }

        if (google.maps.places.Autocomplete) {
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'gmaps-search-input';
            input.placeholder = 'Search your business name or address';
            input.autocomplete = 'off';
            wrap.appendChild(input);

            const ac = new google.maps.places.Autocomplete(input, {
                fields: ['geometry', 'name', 'formatted_address'],
            });
            ac.addListener('place_changed', function () {
                usePlace(ac.getPlace());
            });
        }
    }

    // The Maps JS API calls this once it has loaded (see the script
    // tag in create_profile.php). Building here — rather than on page
    // load — keeps an INDIVIDUAL listing from creating a map inside a
    // hidden box.
    window.islaInitGmaps = function () {
        gmapsReady = true;
        buildMap();
    };

    // Google calls this when the key is rejected — wrong HTTP referrer,
    // billing not enabled, or the Maps JavaScript API not enabled on the
    // project. Open the paste fallback instead of leaving a blank map.
    window.gm_authFailure = function () {
        if (!mapEl) { return; }
        const fallback = document.querySelector('.gmaps-fallback');
        if (fallback) { fallback.open = true; }
        showStatus('The map could not load. Paste your Google Maps link instead.');
    };

    // Keep the map's view in step with the municipality dropdown while
    // no pin has been placed yet. A pin, once dropped or saved, is
    // never overwritten by a municipality change.
    function syncMapToMunicipality() {
        if (!gmap) { return; }
        const pinned = (latInput && latInput.value.trim() !== '')
                    && (lngInput && lngInput.value.trim() !== '');
        if (pinned) { return; }
        gmap.setCenter(currentCenter());
    }

    // --- 5d. Reveal the capture flow that matches the type -------
    // BUSINESS -> the (business-only) Google Maps pin block.
    // INDIVIDUAL -> the GPS button; the paste box never applies to an
    // individual listing, so it is hidden for that type. A business is NEVER auto-pinned from the owner's current
    // location, so the GPS button is hidden for every business listing.
    function applyPinFlow() {
        const business = isBusinessType();

        // The two flows are mutually exclusive: businesses pin on Google
        // Maps, individuals get the GPS button.
        if (gpsGroup)   { gpsGroup.style.display   = business ? 'none' : ''; }
        if (gmapsGroup) { gmapsGroup.style.display = business ? '' : 'none'; }

        if (!business) { return; }

        // Choosing BUSINESS after the INDIVIDUAL GPS button ran must not
        // leave that automatic fix behind as the shop's location, or the
        // listing would look auto-pinned. Drop it so the owner pins by
        // hand. (A saved business pin arrives with gpsCaptured = false
        // and is therefore kept — edit mode keeps its coordinates.)
        if (gpsCaptured) {
            if (latInput) { latInput.value = ''; }
            if (lngInput) { lngInput.value = ''; }
            if (gmapsPaste) { gmapsPaste.value = ''; }
            if (pinStatus) { pinStatus.hidden = true; }
            hideMapPin();
            gpsCaptured = false;
        }

        updateGmapsHref();

        // The map loads asynchronously; buildMap() is a no-op until the
        // API callback has fired, and the callback builds for a
        // BUSINESS selection itself.
        buildMap();
    }

    // The profile-type radios drive the switch (profile_script.js
    // reacts to the same event for its own fields).
    document.querySelectorAll('input[name="profile_type"]').forEach(function (radio) {
        radio.addEventListener('change', applyPinFlow);
    });

    // The paste box turns a Google Maps link (or raw coordinates) into
    // the hidden pin as soon as the text changes — a normal Ctrl+V fires
    // 'input', so a paste is picked up without any extra button.
    if (gmapsPaste) {
        gmapsPaste.addEventListener('input', applyPastedPin);
    }

    // --- 5e. Guard: a BUSINESS listing must carry a pin ---------
    // The form is novalidate, so the requirement is enforced here: a
    // submit with no coordinates is blocked and the inline message
    // under the pin block says what to paste. save_profile.php applies
    // the same rule again, so a browser with scripting off still
    // cannot publish a pinless business.
    const pinError = document.getElementById('businessPinError');
    const profileForm = document.querySelector('form');

    // Captured on the document (capture phase) so the block runs
    // BEFORE busy.js's own submit listener: busy.js only skips its
    // spinner when defaultPrevented is already set, and a blocked
    // form must never look like it is still posting.
    document.addEventListener('submit', function (e) {
        if (!profileForm || e.target !== profileForm) { return; }
        if (!isBusinessType()) { return; }        // INDIVIDUAL may skip the pin

        const lat = latInput ? latInput.value.trim() : '';
        const lng = lngInput ? lngInput.value.trim() : '';
        if (lat !== '' && lng !== '') {
            if (pinError) { pinError.hidden = true; }
            return;                                // pinned -> let it post
        }

        e.preventDefault();                        // no pin, no submit
        if (pinError) { pinError.hidden = false; }
        if (gmapsPaste) { gmapsPaste.focus(); }
    }, true);

    // A successful paste clears the block message (applyPastedPin runs
    // first on the same event, so the hidden pins are already updated).
    if (gmapsPaste) {
        gmapsPaste.addEventListener('input', function () {
            if (!pinError) { return; }
            const pinned = (latInput && latInput.value.trim() !== '')
                        && (lngInput && lngInput.value.trim() !== '');
            if (pinned) { pinError.hidden = true; }
        });
    }

    // --- 6. Error callback: GPS request failed ------------------
    // The Geolocation API passes an error object with a numeric code;
    // map each code to a clear, actionable message for the user.
    function onError(err) {
        const messages = {
            1: 'Location permission was denied. Enable location access to pin your spot.',
            2: 'Your location is unavailable right now. Try again in a moment.',
            3: 'Location request timed out. Please try again.'
        };
        // Unknown codes (or a missing error object) get a generic line.
        showStatus(messages[err && err.code] || 'Could not get your location. Please try again.');
        resetPinButton();                // let the user try again
    }

    // --- 7. GPS permission dialog + pin button wiring -----------
    // The Geolocation API only exists in secure contexts (HTTPS or
    // localhost); guard against older/unsupported browsers so the
    // button gives feedback instead of failing.
    function requestLocation() {
        if (!('geolocation' in navigator)) {
            showStatus('Your browser does not support location services.');
            return;
        }

        // Disable + relabel the button while the fix is pending so
        // the user cannot fire multiple simultaneous requests. The
        // .is-loading class draws the spinner (style.css); the icon
        // is kept and only the label swaps.
        pinBtn.disabled = true;
        pinBtn.classList.add('is-loading');
        const labelNodes = Array.prototype.slice.call(pinBtn.childNodes);
        const lastText = labelNodes[pinBtn.childNodes.length - 1];
        if (lastText && lastText.nodeType === 3) {
            lastText.nodeValue = ' Locating…';
        } else {
            pinBtn.appendChild(document.createTextNode(' Locating…'));
        }

        // Request the current position; if the browser/OS permission
        // is still undecided it prompts the user at this point.
        navigator.geolocation.getCurrentPosition(onPosition, onError, {
            enableHighAccuracy: true, // prefer GPS over wifi/cell on phones
            timeout: 10000,           // give up after 10 seconds
            maximumAge: 0             // always request a fresh fix
        });
    }

    function openGpsPrompt() {
        if (gpsModal) { gpsModal.hidden = false; }
    }

    function closeGpsPrompt() {
        if (gpsModal) { gpsModal.hidden = true; }
    }

    if (pinBtn) {
        // Tapping the button asks for permission first, then (on
        // "Allow") triggers the actual GPS fix.
        pinBtn.addEventListener('click', openGpsPrompt);
    }

    if (gpsModal) {
        // "Allow GPS access" -> close the dialog and request a fix.
        const allowBtn = document.getElementById('gpsAllowBtn');
        if (allowBtn) {
            allowBtn.addEventListener('click', function () {
                closeGpsPrompt();
                requestLocation();
            });
        }

        // "Not now" -> close the dialog and leave the form as-is.
        const denyBtn = document.getElementById('gpsDenyBtn');
        if (denyBtn) {
            denyBtn.addEventListener('click', function () {
                closeGpsPrompt();
                showStatus('Location pin skipped. You can allow it anytime.');
            });
        }

        // Backdrop tap or the X button = dismiss, no GPS request.
        gpsModal.addEventListener('click', function (e) {
            if (e.target === gpsModal || e.target.closest('[data-gps-close]')) {
                closeGpsPrompt();
            }
        });
    }

    // --- 8. On load: resolve the cascade for edit mode ----------
    // PHP already pre-renders the barangays of a saved municipality,
    // but rebuilding from the same data keeps a single code path.
    // Remember the PHP-marked selection first so it is not lost.
    if (munSelect && bgySelect && munSelect.value !== '') {
        const savedBarangay = bgySelect.value;  // read BEFORE rebuild
        rebuildBarangays();                      // refill from JS data
        bgySelect.value = savedBarangay;         // restore the saved one
    }

    // --- 9. On load: reveal the matching capture flow ------------
    // PHP already rendered the right one (the map/paste block for
    // BUSINESS, GPS button for INDIVIDUAL), but running the same switch
    // keeps a single code path. For BUSINESS it also starts the
    // embedded map (when a key is configured) and points the keyless
    // "open Google Maps" link at the chosen municipality.
    applyPinFlow();
})();
