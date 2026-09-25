// ============================================================
// messenger.js — Inbox multi-select & conversation deletion
// The settings gear (header) toggles "select mode": checkboxes
// appear next to every conversation, and checking at least one
// reveals a floating action bar with a Delete button. Deleting
// opens a confirmation modal that submits the selected ids to
// delete_conversations.php. The thread-view scroll behavior is
// intentionally kept in messenger.php.
// ============================================================
(function () {
    'use strict';

    // --- 1. Grab the elements we need (only on the inbox view) ---
    const list      = document.getElementById('convList');
    const gear      = document.getElementById('msgGear');
    const actionBar = document.getElementById('convActionBar');
    const selCount  = document.getElementById('convSelCount');
    const delBtn    = document.getElementById('convDeleteBtn');
    const cancelBtn = document.getElementById('convCancelBtn');
    const delModal  = document.getElementById('convDeleteModal');
    const delText   = document.getElementById('convDeleteText');
    const delIds    = document.getElementById('convDeleteIds');

    // Nothing to wire up on the thread view (no list/gear there).
    if (!list || !gear) {
        return;
    }

    let selectMode = false;

    // --- 2. Toggle select mode -----------------------------------
    // The gear flips the whole list into selection mode. While
    // active, tapping a conversation selects it instead of opening
    // the thread (the link is suppressed via CSS pointer-events).
    function setSelectMode(on) {
        selectMode = on;
        gear.classList.toggle('active', on);
        gear.setAttribute('aria-pressed', on ? 'true' : 'false');
        list.classList.toggle('select-mode', on);
        if (!on) {
            // Leaving select mode clears every checkbox.
            list.querySelectorAll('.conv-select').forEach((cb) => { cb.checked = false; });
            updateActionBar();
        }
    }

    gear.addEventListener('click', () => setSelectMode(!selectMode));

    // --- 3. Selection state --------------------------------------
    function selectedIds() {
        const ids = [];
        list.querySelectorAll('.conv-select:checked').forEach((cb) => ids.push(cb.value));
        return ids;
    }

    function updateActionBar() {
        const n = selectedIds().length;
        if (actionBar) {
            actionBar.hidden = !(selectMode && n > 0);
            if (selCount) {
                selCount.textContent = n + ' selected';
            }
        }
    }

    // Checkbox changes + a tap on the whole row toggle the box.
    list.addEventListener('change', (e) => {
        if (e.target.classList.contains('conv-select')) {
            updateActionBar();
        }
    });

    list.addEventListener('click', (e) => {
        if (!selectMode) {
            return; // normal navigation when not selecting
        }
        const item = e.target.closest('.conv-item');
        if (!item) {
            return;
        }
        // In select mode, tapping anywhere on the row flips its box
        // instead of navigating into the thread.
        e.preventDefault();
        const cb = item.querySelector('.conv-select');
        if (cb) {
            cb.checked = !cb.checked;
            updateActionBar();
        }
    });

    // --- 4. Cancel: leave select mode, hide the bar ---------------
    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => setSelectMode(false));
    }

    // --- 5. Delete: open the confirmation modal -------------------
    function openDeleteModal() {
        const ids = selectedIds();
        if (ids.length === 0) {
            return;
        }
        // Rebuild the hidden inputs the backend expects (ids[]).
        delIds.innerHTML = '';
        ids.forEach((id) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = id;
            delIds.appendChild(input);
        });
        delText.textContent =
            'This hides ' + ids.length +
            (ids.length === 1 ? ' conversation' : ' conversations') +
            ' from your inbox. The other person can still see the thread.';
        delModal.hidden = false;
    }

    if (delBtn) {
        delBtn.addEventListener('click', openDeleteModal);
    }

    // --- 6. Close helpers (matches the app-wide modal convention) --
    function closeDeleteModal() {
        delModal.hidden = true;
    }

    // Backdrop click / ✕ / Cancel all close the modal.
    document.addEventListener('click', (e) => {
        if (e.target === delModal || e.target.closest('[data-close]')) {
            closeDeleteModal();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !delModal.hidden) {
            closeDeleteModal();
        }
    });
})();
