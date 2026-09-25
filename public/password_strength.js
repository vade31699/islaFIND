// ============================================================
// password_strength.js — live password strength meter
//
// Gives the user instant feedback while they type a NEW password,
// on the three screens that set one: the sign-up panel and the
// reset panel (login.php) and Change Password (dashboard.php).
//
// HOW TO USE IT
//   Add the boolean attribute data-pw-strength to any new-password
//   input and this script does the rest — it builds the meter
//   markup inside the field's own .form-group, so no HTML has to be
//   written twice and a page can never ship a meter without a
//   script (or a script without a meter).
//
// SCORING (five signals, clamped to a 1-3 visual scale)
//   length >= 8, length >= 12, mixed upper+lower case, a digit, a
//   symbol. Anything under 6 characters can never read better than
//   "Weak". The meter is HINT ONLY: the server keeps the real
//   policy, so a browser with scripting off still cannot save a
//   password that fails validation.
// ============================================================

(function () {
    'use strict';

    // --- 1. Factory: score one password -----------------------
    // Returns null for an empty box (the meter hides), otherwise
    // { level, label } where level is weak | fair | good.
    function scorePassword(value) {
        if (value === '') { return null; }

        var score = 0;
        if (value.length >= 8)  { score++; }
        if (value.length >= 12) { score++; }
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) { score++; }
        if (/\d/.test(value)) { score++; }
        if (/[^A-Za-z0-9]/.test(value)) { score++; }

        // A very short password is never better than Weak, however
        // mixed its characters are.
        if (value.length < 6) { score = Math.min(score, 1); }

        var level = score <= 1 ? 'weak' : (score <= 3 ? 'fair' : 'good');
        var label = level === 'weak'
            ? 'Weak — aim for 8+ characters with letters, numbers and a symbol.'
            : (level === 'fair'
                ? 'Fair — longer or mixed case would make it stronger.'
                : 'Strong password.');
        return { level: level, label: label, segments: level === 'weak' ? 1 : (level === 'fair' ? 2 : 3) };
    }

    // --- 2. Build the meter markup for one input --------------
    // The meter is inserted AFTER the .input-icon wrapper so the
    // field's absolutely-positioned SVG icon keeps its own box.
    function buildMeter(input) {
        if (input.__islaPwMeter) { return input.__islaPwMeter; }

        var meter = document.createElement('div');
        meter.className = 'pw-meter';
        meter.hidden = true;               // nothing to show until typing

        var track = document.createElement('div');
        track.className = 'pw-meter-track';
        for (var i = 0; i < 3; i++) {
            var seg = document.createElement('span');
            seg.className = 'pw-meter-seg';
            track.appendChild(seg);
        }

        var label = document.createElement('p');
        label.className = 'pw-meter-label';
        // Announced politely so a screen reader hears the change
        // without interrupting the typing.
        label.setAttribute('role', 'status');
        label.setAttribute('aria-live', 'polite');

        meter.appendChild(track);
        meter.appendChild(label);

        // Where to attach: after the icon wrapper when the field uses
        // one (the app's standard look), else straight after the input.
        var anchor = input.closest('.input-icon') || input;
        if (anchor.parentNode) {
            anchor.parentNode.insertBefore(meter, anchor.nextSibling);
        }

        var parts = { meter: meter, segs: track.querySelectorAll('.pw-meter-seg'), label: label };
        input.__islaPwMeter = parts;
        return parts;
    }

    // --- 3. Paint one input's meter ---------------------------
    function render(input) {
        var parts = buildMeter(input);
        var result = scorePassword(input.value);

        if (!result) {                     // empty box -> hide
            parts.meter.hidden = true;
            parts.segs.forEach(function (seg) {
                seg.className = 'pw-meter-seg';
            });
            parts.label.className = 'pw-meter-label';
            parts.label.textContent = '';
            return;
        }

        parts.meter.hidden = false;
        parts.segs.forEach(function (seg, i) {
            seg.className = 'pw-meter-seg'
                + (i < result.segments ? ' is-' + result.level : '');
        });
        parts.label.className = 'pw-meter-label is-' + result.level;
        parts.label.textContent = result.label;
    }

    // --- 4. Wire every marked input on the page ---------------
    // 'input' covers typing, pasting and autofill; running once on
    // load paints a meter for a field the browser has already
    // filled (e.g. a re-rendered form after a validation error).
    function wireAll() {
        document.querySelectorAll('input[data-pw-strength]').forEach(function (input) {
            if (input.__islaPwWired) { return; }
            input.__islaPwWired = true;
            input.addEventListener('input', function () { render(input); });
            render(input);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireAll);
    } else {
        wireAll();
    }
})();
