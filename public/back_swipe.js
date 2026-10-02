// ============================================================
// back_swipe.js — the app's back gesture
//
// A drag that starts at the left edge and travels right goes back a
// screen, the way a phone's own back gesture does. Loaded by every
// page: the member app through include/head_meta.php, the superadmin
// panel beside admin_nav.js.
//
// Calibrated so it does NOT take a long swipe: a slow drag commits at
// COMMIT pixels, and a quick flick commits much sooner (FLICK). That
// is the phone's own rule — distance OR speed, whichever the finger
// gives first — so a short flick back is enough, as on iOS.
//
// The superadmin panel does NOT load this: it has a Back button in the
// page header instead, which is unambiguous on a desktop-width tool.
// The member app loads it from include/head_meta.php.
//
// Purely an enhancement — the browser's back button and the phone's
// own gesture keep working without it.
// ============================================================
(function () {
    'use strict';

    var EDGE    = 28;   // the drag must begin this close to the left edge
    var COMMIT  = 40;   // a slow drag this far right counts
    var FLICK   = 0.35; // px per ms — a quick flick counts sooner...
    var FLICK_D = 20;   // ...but a flick still has to travel this far
    var DRIFT   = 0.6;  // vertical drift must stay under this share of dx

    var active = false;
    var startX = 0;
    var startY = 0;
    var startT = 0;

    // A drag inside something that scrolls sideways belongs to that
    // element, not to the gesture.
    function scrollsSideways(el) {
        while (el && el !== document.body) {
            if (el.scrollWidth > el.clientWidth + 4) {
                var ox = window.getComputedStyle(el).overflowX;
                if (ox === 'auto' || ox === 'scroll') { return true; }
            }
            el = el.parentElement;
        }
        return false;
    }

    // With the admin panel's menu open, a swipe folds it instead.
    function dismissMenu() {
        var menu = document.getElementById('adm-menu');
        if (!menu || !menu.checked) { return false; }
        menu.click();   // toggles it off and fires the change listener
        return true;
    }

    document.addEventListener('touchstart', function (e) {
        active = false;
        if (e.touches.length !== 1 || !(e.target instanceof Element)) { return; }
        var t = e.touches[0];
        if (t.clientX > EDGE || scrollsSideways(e.target)) { return; }
        active = true;
        startX = t.clientX;
        startY = t.clientY;
        startT = Date.now();
    }, { passive: true });

    // Watch the drag only to tell a sideways gesture from a vertical
    // scroll: the moment it is clearly vertical, this is not a back
    // swipe and the page scrolls as normal.
    document.addEventListener('touchmove', function (e) {
        if (!active) { return; }
        var t = e.touches[0];
        if (!t) { return; }
        if (Math.abs(t.clientY - startY) > Math.abs(t.clientX - startX) * 1.5) {
            active = false;
        }
    }, { passive: true });

    function finish(x, y) {
        active = false;

        var dx = x - startX;
        if (dx <= 0) { return; }

        var dy = Math.abs(y - startY);
        if (dy > dx * DRIFT) { return; }

        // Distance OR speed. A flick is short and fast, a drag is long
        // and slow; either one finishes the gesture the instant the
        // finger lifts, so a short flick back is enough.
        var speed = dx / Math.max(1, Date.now() - startT);   // px per ms
        var flick = dx >= FLICK_D && speed >= FLICK;
        if (dx < COMMIT && !flick) { return; }

        if (dismissMenu()) { return; }
        if (window.history.length > 1) { window.history.back(); }
    }

    document.addEventListener('touchend', function (e) {
        if (!active) { return; }
        var t = e.changedTouches[0];
        finish(t.clientX, t.clientY);
    }, { passive: true });

    // A cancelled touch is not a swipe — drop it rather than leaving the
    // gesture armed for the next lift.
    document.addEventListener('touchcancel', function () {
        active = false;
    }, { passive: true });
})();
