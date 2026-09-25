// ============================================================
// profile_script.js — islaFIND profile creation UI logic
// Powers the streamlined form on create_profile.php:
//   1. Toggling between INDIVIDUAL SKILLS and BUSINESS
//   2. Live search filtering of the title dropdown
//   3. Picking a title (fills the hidden selected_title input)
//   4. Showing/hiding the BUSINESS NAME field and the account
//      sync notice depending on the chosen type
// No page reloads are needed for any of this — it is all
// plain DOM manipulation on the client side.
// ============================================================

(function () {
    'use strict';

    // --- 1. Grab the form elements ---------------------------------
    const typeCards     = document.querySelectorAll('.type-card'); // big selector cards
    const subcatFields  = document.querySelectorAll('.subcat-field'); // one search field per type
    const searchBoxes   = document.querySelectorAll('.subcat-search'); // the search inputs
    const optionLists   = document.querySelectorAll('.subcat-options'); // dropdown option lists
    const titleInput    = document.getElementById('titleInput');   // hidden final value
    const bizNameGroup  = document.getElementById('businessNameGroup'); // business name field
    const syncNotice    = document.getElementById('syncNoticeText');    // account-sync hint
    // Contextual fields: the INDIVIDUAL description and the BUSINESS
    // unit inventory. Exactly one is visible at a time, matching the
    // active profile type (see create_profile.php markup).
    const contextFields = document.querySelectorAll('.contextual-field');

    // --- 2. Helper: which profile type is currently selected? -------
    function activeType() {
        const radio = document.querySelector('input[name="profile_type"]:checked');
        return radio ? radio.value : '';
    }

    // --- 3. Helper: show/hide a field with a soft reveal animation ---
    // display:none can't be transitioned, so a freshly shown field gets
    // a short fade+slide keyframe (see .field-in in style.css) to make
    // profile-type switches feel smooth instead of abrupt.
    function showField(el, show) {
        if (!el) { return; }
        el.style.display = show ? '' : 'none';
        if (show) {
            el.classList.remove('field-in');
            void el.offsetWidth;   // restart the animation
            el.classList.add('field-in');
        }
    }

    // --- 3. (cont.) Helper: apply the type-dependent UI -------------
    // Shows the business name field only for BUSINESS and rewrites
    // the sync notice so it always describes what will be inherited.
    // Also reveals the matching contextual field (description for
    // INDIVIDUAL SKILLS, unit inventory for BUSINESS) and hides the
    // other one — all with a quick reveal, no page reload.
    function applyType(type) {
        showField(bizNameGroup, type === 'business');
        if (syncNotice) {
            syncNotice.textContent = type === 'business'
                ? 'Your account photo and contact number will be shown on this listing.'
                : 'Your name, photo and contact number from your account will be shown on this listing.';
        }
        contextFields.forEach(function (field) {
            showField(field, field.dataset.context === type);
        });
    }

    // --- 4. Helper: filter one dropdown by its search text -----------
    // Compares the typed text (lower-cased) against each option label
    // so "motor" instantly narrows the list down to Motor Rental.
    function applySearch(field) {
        const searchBox = field.querySelector('.subcat-search');
        const list      = field.querySelector('.subcat-options');
        const term      = searchBox.value.trim().toLowerCase();
        list.querySelectorAll('.subcat-option').forEach(function (opt) {
            opt.style.display = (!term || opt.textContent.toLowerCase().indexOf(term) !== -1)
                ? '' : 'none';
        });
        list.classList.add('open'); // keep results visible while typing
    }

    // --- 5. Type switch: drive everything off the radio's 'change' ---
    // The radio input is stretched over its whole card, so a tap
    // anywhere on a card checks that radio. We react to 'change'
    // (which fires AFTER the browser settles the checked state) —
    // a plain 'click' handler here would run too early, still see
    // the OLD checked value, and bail out, so the form would never
    // switch. Keyboard users get the same behavior for free, since
    // arrow-keying the radios also fires 'change'.
    function switchType(type) {
        // Highlight the active card, dim the other one.
        typeCards.forEach(function (card) {
            const cardRadio = card.querySelector('input[name="profile_type"]');
            card.classList.toggle('active', cardRadio && cardRadio.value === type);
        });

        // A type switch resets the title choice + search text.
        titleInput.value = '';
        searchBoxes.forEach(function (sb) { sb.value = ''; });

        // Show only this type's search field; hide the other.
        subcatFields.forEach(function (field) {
            const show = field.dataset.type === type;
            showField(field, show);
            if (show) {
                field.querySelectorAll('.subcat-option').forEach(function (opt) {
                    opt.style.display = ''; // un-hide every option
                });
                field.querySelector('.subcat-options').classList.remove('open');
            }
        });

        // Business name field + notice + contextual field follow the type.
        applyType(type);
    }

    document.querySelectorAll('input[name="profile_type"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            switchType(radio.value);
        });
    });

    // --- 6. Live filtering as the user types -------------------------
    searchBoxes.forEach(function (searchBox) {
        const field = searchBox.closest('.subcat-field');
        searchBox.addEventListener('input', function () {
            // Typing invalidates any previously picked option.
            titleInput.value = '';
            applySearch(field);
        });
        // Opening the box shows the full list again.
        searchBox.addEventListener('focus', function () {
            applySearch(field);
        });
    });

    // --- 7. Picking an option from the dropdown ----------------------
    optionLists.forEach(function (list) {
        list.querySelectorAll('.subcat-option').forEach(function (opt) {
            opt.addEventListener('click', function () {
                const field = list.closest('.subcat-field');
                // Store the chosen slug in the hidden input.
                titleInput.value = opt.dataset.value;
                // Mirror the human label into the search box.
                field.querySelector('.subcat-search').value = opt.textContent.trim();
                list.classList.remove('open'); // close the dropdown
            });
        });
    });

    // --- 8. Close the dropdown when clicking anywhere else -----------
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.subcat-field')) {
            optionLists.forEach(function (list) { list.classList.remove('open'); });
        }
    });

    // --- 9. On load: reveal the field for the pre-selected type ------
    // (Edit mode: PHP marks the correct card as active, so just show
    //  its search field + business name state without a click.)
    const initialType = activeType() || 'individual';
    subcatFields.forEach(function (field) {
        field.style.display = field.dataset.type === initialType ? '' : 'none';
    });
    applyType(initialType);
})();
