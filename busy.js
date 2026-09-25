// ============================================================
// busy.js — Global loading states for every islaFIND action
// Every form in the app posts the traditional way (submit ->
// PHP redirect), which otherwise gives the user zero feedback
// between the tap and the next page. This script wires EVERY
// <form> on the page so that:
//
//   1. The moment one submits, its submit button is disabled,
//      marked busy (.is-loading) and shows a running spinner,
//      with an optional friendlier label (data-loading-label
//      e.g. "Logging in…", "Sending…").
//   2. Repeat submits (double tap, Enter key while the first
//      request is still in flight) are blocked, so no action
//      can ever run twice.
//   3. An inline onsubmit that cancels itself (the app's
//      confirm(...) dialogs) is respected: if the event was
//      already prevented, nothing is marked busy.
//
// The only actions that are NOT plain forms are the handful of
// fetch() calls (reviews, notifications) — those get their own
// visible loading states in their own scripts, reusing the
// .spinner / .loading-row styles from style.css.
//
// Safe to include more than once (guarded below), and it runs
// from <head> so the submit listener exists before any form
// can possibly fire.
// ============================================================
(function () {
    'use strict';

    // Double-include guard: wiring the same forms twice would
    // register duplicate listeners, which must never happen.
    if (window.__islaBusyLoaded) {
        return;
    }
    window.__islaBusyLoaded = true;

    // --- 1. Mark a submit button busy -------------------------
    // Adds the spinner class the CSS draws a spinner from, marks
    // the button busy, and optionally swaps in a clearer label.
    // The CSS spinner is a pseudo-element, so nothing has to be
    // injected into the button and icons are simply hidden.
    function busyButton(btn, label) {
        if (!btn || btn.getAttribute('data-busy') === '1') {
            return;
        }
        btn.setAttribute('data-busy', '1');
        // Deliberately NOT btn.disabled = true: a disabled control
        // is excluded from the form data set (built AFTER the
        // submit event fires), which silently drops this button's
        // name=value from the POST and breaks any PHP handler that
        // routes on isset($_POST['<button name>']) — e.g. reset
        // password, resend code, change password, MFA toggle.
        // Double submits stay blocked by the form's data-busy
        // guard above, and .is-loading's pointer-events: none
        // already makes the button unclickable.
        btn.classList.add('is-loading');
        btn.setAttribute('aria-busy', 'true');
        if (label) {
            if (btn.tagName === 'INPUT') {
                btn.value = label;
            } else {
                btn.textContent = label;
            }
        }
    }

    // Helper for scripts: an <p class="loading-row"> with a
    // spinner, for containers that fill in asynchronously.
    function loadingRow(text) {
        var row = document.createElement('p');
        row.className = 'loading-row';
        var sp = document.createElement('span');
        sp.className = 'spinner';
        sp.setAttribute('aria-hidden', 'true');
        row.appendChild(sp);
        row.appendChild(document.createTextNode(' ' + (text || 'Loading…')));
        return row;
    }

    // --- 2. Wire one form --------------------------------------
    function wireForm(form) {
        if (form.__islaWired) {
            return;
        }
        form.__islaWired = true;

        form.addEventListener('submit', function (event) {
            // A cancelled submit (onsubmit returning confirm(...)
            // == false, or client-side validation) never shows a
            // spinner — defaultPrevented is already set.
            if (event.defaultPrevented) {
                return;
            }

            // Second submit while the first is still flying: block
            // it outright so the action cannot run twice.
            if (form.getAttribute('data-busy') === '1') {
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }
            form.setAttribute('data-busy', '1');

            // The button that triggered the submit (falls back to
            // the form's first submit button for Enter-key posts).
            var btn = event.submitter
                && (event.submitter.tagName === 'BUTTON'
                    || (event.submitter.tagName === 'INPUT'
                        && /submit/i.test(event.submitter.type)))
                ? event.submitter
                : form.querySelector(
                    'button[type="submit"], input[type="submit"], button:not([type])');

            if (btn) {
                busyButton(btn, btn.getAttribute('data-loading-label'));
            }

            // Forms without their own submit button (the hidden
            // avatar upload form) point at the element that should
            // visibly load instead.
            var targetSel = form.getAttribute('data-busy-target');
            if (targetSel) {
                var target = document.querySelector(targetSel);
                if (target) {
                    target.classList.add('is-uploading');
                    target.setAttribute('aria-busy', 'true');
                }
            }
        });
    }

    // --- 3. Wire every form once the DOM exists ----------------
    function wireAll() {
        document.querySelectorAll('form').forEach(wireForm);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireAll);
    } else {
        wireAll();
    }

    // Exposed for the few async (fetch) actions that manage
    // their own busy states — see the reviews / notifications
    // call sites.
    window.islaLoading = {
        button: busyButton,
        row: loadingRow
    };
})();
