// ============================================================
// admin_nav.js — The collapsed admin menu
//
// admin.css folds the sidebar into a hamburger below 860px, and
// the checkbox in the markup does all the opening and closing on
// its own. This file adds only what CSS cannot express:
//
//   1. An opened menu survives a page load, so a reload (or any
//      link that re-renders the shell) does not fold it back up.
//   2. Tapping a section link folds it again, so arriving on
//      Overview never lands you in a menu you have to dismiss
//      before you can read the page. Signing out folds it too:
//      the state is kept for the tab, and a stale open menu
//      would otherwise meet the next sign-in.
//   3. Tapping anywhere else — or pressing Escape — folds it,
//      the way a dropdown is expected to behave.
//
// sessionStorage, not localStorage: a menu is per-tab UI state,
// and a signed-in panel should not leave a trace behind in a
// browser that is later opened by someone else.
//
// Everything here is an enhancement. With JavaScript off the
// checkbox still opens and closes the menu; it just starts folded.
// ============================================================
(function () {
    'use strict';

    // --- 1. The shell we are wiring (panel pages only) -------------
    const toggle = document.getElementById('adm-menu');
    const nav    = document.querySelector('.adm-nav');
    const side   = document.querySelector('.adm-side');

    if (!toggle || !nav) {
        return;                     // not an admin shell
    }

    const KEY = 'islaAdminMenuOpen';

    // --- 2. Storage, which may simply not be available -------------
    // Some private modes, sandboxed frames and storage-blocked
    // browsers deny it outright, and can throw on read as well as
    // write. The menu must still open and close then, so a failure
    // here just means "no memory".
    let store = null;
    try {
        store = window.sessionStorage;
        store.getItem(KEY);
    } catch (err) {
        store = null;
    }

    function remembered() {
        if (!store) { return false; }
        try {
            return store.getItem(KEY) === '1';
        } catch (err) {
            return false;
        }
    }

    function remember(open) {
        if (!store) { return; }
        try {
            store.setItem(KEY, open ? '1' : '0');
        } catch (err) {
            /* full or denied — the menu still toggles, it just forgets */
        }
    }

    // --- 3. Fold ---------------------------------------------------
    // One way in and one way out, so the checkbox, the stored flag
    // and what the user sees can never disagree.
    function fold() {
        toggle.checked = false;
        remember(false);
    }

    // A fresh page load starts folded (the markup has no `checked`),
    // so this is where a remembered menu comes back.
    if (remembered()) {
        toggle.checked = true;
    }

    // The checkbox is the control the user actually touches; every
    // other path calls fold() directly.
    toggle.addEventListener('change', function () {
        remember(toggle.checked);
    });

    // --- 4. Dismiss: a tap outside, or Escape ----------------------
    // Only while it is open — otherwise every click on a page that
    // never used the menu would write to storage.
    document.addEventListener('click', function (e) {
        if (!toggle.checked) { return; }
        // The checkbox is not a tap on the page. It has pointer-events:
        // none, so no user can ever hit it — but activating the label
        // dispatches a second click on the checkbox itself, and that
        // click bubbles up from outside the sidebar. Without this line
        // the menu would fold itself the instant it was opened.
        if (e.target === toggle) { return; }
        if (side && side.contains(e.target)) { return; }
        fold();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || !toggle.checked) { return; }
        fold();
        // Keep the keyboard where it was. Focus goes back to the
        // checkbox the menu was opened with (the label is not
        // focusable), which re-draws the focus ring on the hamburger
        // instead of dropping the user to the top of the document.
        //
        // preventScroll matters: the hamburger sits at the top of the
        // page, so an admin who scrolled down with the menu open and
        // then pressed Escape would otherwise be yanked back to it.
        // Browsers too old to know the option scroll, which is the
        // pre-existing behaviour and harmless.
        toggle.focus({ preventScroll: true });
    });

    // --- 5. Leaving the menu ---------------------------------------
    // A section link: fold now, so the menu is already closed when
    // the next page paints rather than folding again on arrival.
    nav.addEventListener('click', function (e) {
        if (e.target.closest('a')) {
            fold();
        }
    });

    // Sign out lives in the sidebar footer, not in the nav.
    const footForm = document.querySelector('.adm-side-foot form');
    if (footForm) {
        footForm.addEventListener('submit', fold);
    }
})();
