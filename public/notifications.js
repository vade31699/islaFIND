// ============================================================
// notifications.js — Global notification bell
// Polls notifications.php every 30 seconds (and whenever the
// tab regains focus) to keep the header bell's badge fresh,
// and fills the dropdown panel with the latest items when the
// bell is tapped. Everything is derived from live data, so
// the badge clears by itself once the user acts: reading a
// thread marks its messages read, accepting/declining a hire
// request moves the contract, completing a job clears it.
// ============================================================
(function () {
    'use strict';

    // --- 1. Grab the bell elements ------------------------------
    // The markup comes from the notifications_bell.php partial,
    // which is included in dashboard.php's app header. If the bell
    // is absent (a page without one), do nothing.
    var bell  = document.getElementById('notifBell');
    var badge = document.getElementById('notifBadge');
    var panel = document.getElementById('notifPanel');
    var list  = document.getElementById('notifList');
    if (!bell) {
        return;
    }

    // --- 2. Fetch the latest payload -----------------------------
    // Same-origin fetch so the PHP session cookie is sent. Any
    // network hiccup returns null and the UI simply keeps the
    // last known values.
    function fetchNotifs() {
        return fetch('notifications.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return null; });
    }

    // --- 3. Render the badge number ------------------------------
    function renderBadge(data) {
        if (!badge) { return; }
        var n = data ? (data.total || 0) : 0;
        badge.hidden = n <= 0;                 // hide when nothing new
        badge.textContent = n > 99 ? '99+' : String(n); // cap at 99+
    }

    // --- 4. Render the dropdown list -----------------------------
    function renderList(data) {
        if (!list) { return; }
        list.innerHTML = '';                   // clear previous rows

        var items = data && data.items ? data.items : [];
        if (items.length === 0) {
            // Nothing pending: show the friendly empty state.
            var empty = document.createElement('p');
            empty.className = 'notif-empty';
            empty.textContent = 'You\u2019re all caught up \u2728';
            list.appendChild(empty);
            return;
        }

        // One link row per item, pointing at the relevant thread.
        items.forEach(function (item) {
            var row = document.createElement('a');
            row.className = 'notif-item notif-' + item.type;
            row.href = item.link;              // e.g. messenger.php?chat=5

            var dot = document.createElement('span');
            dot.className = 'notif-icon';      // colored by CSS per type

            var text = document.createElement('span');
            text.className = 'notif-text';
            text.textContent = item.text;

            row.appendChild(dot);
            row.appendChild(text);
            list.appendChild(row);
        });
    }

    // --- 5. Refresh: update the badge (and the panel if open) ----
    function refresh(openPanel) {
        fetchNotifs().then(function (data) {
            renderBadge(data);
            if (openPanel || (panel && !panel.hidden)) {
                renderList(data);
            }
        });
    }

    // --- 6. Poll every 30s + refresh when the tab is focused -----
    // Keeps the badge live while the user browses. On mobile the
    // focus event fires when the app is brought back to the front.
    setInterval(function () { refresh(false); }, 30000);
    window.addEventListener('focus', function () { refresh(false); });

    // --- 7. Bell tap toggles the dropdown ------------------------
    bell.addEventListener('click', function (e) {
        e.stopPropagation();                   // don't trigger the outside-close
        var willOpen = panel.hidden;
        panel.hidden = !willOpen;
        bell.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen) {
            // Show a visible loading state right away — the panel
            // fills in the moment notifications.php answers.
            if (list) {
                list.innerHTML = '';
                list.appendChild(
                    window.islaLoading ? window.islaLoading.row('Loading…')
                                       : document.createTextNode('Loading…'));
            }
            refresh(true);                     // fill it fresh when opened
        }
    });

    // --- 8. Close on outside click or Escape ---------------------
    document.addEventListener('click', function (e) {
        if (!e.target.closest('#notifWrap')) {
            panel.hidden = true;
            bell.setAttribute('aria-expanded', 'false');
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !panel.hidden) {
            panel.hidden = true;
            bell.setAttribute('aria-expanded', 'false');
        }
    });

    // --- 9. Initial render on page load --------------------------
    refresh(false);
})();
