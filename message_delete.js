// ============================================================
// message_delete.js — Long-press per-message delete
// Holding a chat bubble for ~500ms selects it and reveals a
// small action sheet with a "Delete message" option. Choosing
// it opens a confirmation modal that posts the message id to
// delete_message.php, which soft-deletes THAT message from my
// own thread view only (the other person keeps their copy).
//
// Touch details:
//   - A 500ms hold with the finger still triggers the sheet.
//   - Lifting the finger early, scrolling, or dragging away
//     cancels the hold (so normal chat scrolling is unaffected).
//   - While the sheet is open, tapping anywhere outside it (or
//     the Cancel button) closes it.
// ============================================================
(function () {
    'use strict';

    // --- 1. Grab the elements we need (thread view only) ----------
    const thread   = document.getElementById('chatThread');
    const sheet    = document.getElementById('msgActionSheet');
    const delBtn   = document.getElementById('msgActionDelete');
    const cancelBtn = document.getElementById('msgActionCancel');
    const modal    = document.getElementById('msgDeleteModal');
    const modalId  = document.getElementById('msgDeleteId');

    // Nothing to wire up on the inbox view (no bubbles there).
    if (!thread || !sheet || !delBtn || !cancelBtn || !modal || !modalId) {
        return;
    }

    // --- 2. State --------------------------------------------------
    let holdTimer   = null;      // pending long-press timer
    let holdStart   = { x: 0, y: 0 };  // where the finger landed
    let selectedMsg = null;      // the bubble currently selected
    const HOLD_MS   = 500;       // how long a press must last
    const MOVE_TOL  = 12;        // px of drift that cancels the hold

    // --- 3. Open / close the action sheet --------------------------
    function showSheet(bubble) {
        // Remember which message is selected so the modal can post it.
        selectedMsg = bubble;
        bubble.classList.add('msg-selected');

        // Title says who the message is from, matching the bubble.
        sheetTitle(bubble);
        sheet.hidden = false;

        // Scroll the sheet into view if the bubble is near the edge.
        sheet.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function sheetTitle(bubble) {
        const title = document.getElementById('msgActionTitle');
        if (!title) { return; }
        title.textContent = bubble.dataset.mine === '1' ? 'Your message' : 'Their message';
    }

    function closeSheet() {
        if (holdTimer) { clearTimeout(holdTimer); holdTimer = null; }
        if (selectedMsg) { selectedMsg.classList.remove('msg-selected'); }
        selectedMsg = null;
        sheet.hidden = true;
    }

    // --- 4. Long-press detection ------------------------------------
    // pointerdown starts the 500ms race; if the finger lifts, moves
    // beyond the tolerance, or the browser cancels the touch (e.g. a
    // scroll begins), the race is aborted.
    thread.addEventListener('pointerdown', function (e) {
        // Only react to presses that start on a chat bubble.
        const bubble = e.target.closest('.chat-bubble');
        if (!bubble) { return; }

        // If the sheet is already open, tapping a bubble elsewhere
        // just moves the selection rather than stacking timers.
        closeSheet();

        holdStart = { x: e.clientX, y: e.clientY };
        holdTimer = setTimeout(function () {
            holdTimer = null;
            showSheet(bubble);
        }, HOLD_MS);
    });

    // Lifting the finger (or the browser cancelling the touch, e.g.
    // because a scroll started) only aborts a HOLD THAT HAS NOT FIRED
    // yet. Once the sheet is open, the lift must NOT close it — the
    // user still has to tap "Delete message". That tap is handled by
    // the outside-click dismissal below.
    function cancelHold() {
        if (holdTimer) { clearTimeout(holdTimer); holdTimer = null; }
    }

    thread.addEventListener('pointerup', cancelHold);
    thread.addEventListener('pointercancel', cancelHold);

    thread.addEventListener('pointermove', function (e) {
        // Finger drifted too far — it is a scroll, not a hold.
        if (holdTimer && Math.hypot(e.clientX - holdStart.x, e.clientY - holdStart.y) > MOVE_TOL) {
            cancelHold();
        }
    });

    // --- 5. Delete flow ---------------------------------------------
    delBtn.addEventListener('click', function () {
        if (!selectedMsg) { return; }
        // Pre-fill the hidden field with the selected message id.
        modalId.value = selectedMsg.dataset.msgId;
        sheet.hidden = true;
        modal.hidden = false;
        document.body.classList.add('no-scroll');
    });

    // Cancel button closes the sheet (keeps the selection highlight).
    cancelBtn.addEventListener('click', function () {
        closeSheet();
    });

    // --- 6. Dismiss helpers (matches the app-wide modal convention) --
    // Backdrop click / ✕ / Escape all close the sheet and modal.
    document.addEventListener('click', function (e) {
        if (e.target === modal || e.target.closest('[data-close]')) {
            modal.hidden = true;
            document.body.classList.remove('no-scroll');
        }
        // Tapping outside the sheet dismisses it too.
        if (!sheet.hidden && !e.target.closest('#msgActionSheet')) {
            closeSheet();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (!modal.hidden) {
                modal.hidden = true;
                document.body.classList.remove('no-scroll');
            }
            closeSheet();
        }
    });
})();
