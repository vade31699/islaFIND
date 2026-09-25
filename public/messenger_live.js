// ============================================================
// messenger_live.js — Live messenger updates (no page reload)
// Polls messenger_poll.php every few seconds so that:
//   - Thread view: new messages from the other person slide in
//     as bubbles (or system notices) the moment they arrive.
//     The page only reloads when the STATE SIGNATURE changes —
//     i.e. a whole server-rendered block must appear or
//     disappear (message request accepted -> input bar unlocks,
//     HIRE! -> ACCEPT/DECLINE, accepted hire -> JOB DONE).
//     Those are rare events, so a single reload is the right
//     trade-off instead of re-implementing every button block.
//   - Inbox view: the conversation list re-renders from JSON,
//     so new conversations, unread badges, and previews update
//     in place.
// Soft-deleted messages are excluded server-side (both the
// thread-level and per-message flags), so nothing the user
// deleted ever reappears via polling.
// ============================================================
(function () {
    'use strict';

    // --- 1. Locate the page we are on -----------------------------
    var thread = document.getElementById('chatThread'); // thread view?
    var list   = document.getElementById('convList');   // inbox view?

    if (!thread && !list) {
        return; // no live areas (e.g. the New Message picker)
    }

    // --- 2. Small helpers (mirror the PHP versions) ---------------
    function friendlyTime(datetime) {
        // Today -> "2:34 PM"; earlier -> "Jun 3" (same as PHP).
        var d = new Date(datetime.replace(' ', 'T'));
        var now = new Date();
        if (isNaN(d.getTime())) { return ''; }
        if (d.toDateString() === now.toDateString()) {
            var h = d.getHours();
            var m = d.getMinutes();
            var ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            return h + ':' + (m < 10 ? '0' + m : m) + ' ' + ampm;
        }
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[d.getMonth()] + ' ' + d.getDate();
    }

    function initialsOf(name) {
        // First letters of the first two words, uppercased.
        var parts = String(name).trim().split(/\s+/).filter(Boolean);
        var out = '';
        for (var i = 0; i < parts.length && out.length < 2; i++) {
            out += parts[i].charAt(0).toUpperCase();
        }
        return out || '?';
    }

    // ============================================================
    // 3. THREAD VIEW — live message appending
    // ============================================================
    if (thread) {
        // Remember the state the server rendered. If the poller
        // ever sees a different signature, server-rendered UI must
        // be rebuilt -> reload once.
        var initialState = thread.getAttribute('data-signature') || '';
        var chatWith     = thread.getAttribute('data-chat-with') || '0';
        var myUserId     = thread.getAttribute('data-me') || '0';
        // Seed with the newest id the server already rendered, so the
        // very first poll only fetches what is genuinely new. A fresh
        // thread (data-max-id=0) starts at 0 and picks up the first
        // message when it lands.
        var lastMsgId = parseInt(thread.getAttribute('data-max-id') || '0', 10);

        var isNearBottom = function () {
            return thread.scrollHeight - thread.scrollTop - thread.clientHeight < 120;
        };
        var stickToBottom = isNearBottom(); // stick only if already there

        // Build one bubble element (chat or system) from a message row.
        function buildBubble(row) {
            var wrap = document.createElement('div');
            if (row.kind === 'system') {
                // Formal notice: icon + text (mirrors messenger.php).
                wrap.className = 'chat-system';
                var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svg.setAttribute('viewBox', '0 0 24 24');
                svg.setAttribute('fill', 'none');
                svg.setAttribute('stroke', 'currentColor');
                svg.setAttribute('stroke-width', '2');
                svg.setAttribute('stroke-linecap', 'round');
                svg.setAttribute('stroke-linejoin', 'round');
                svg.innerHTML = '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>';
                wrap.appendChild(svg);
                wrap.appendChild(document.createTextNode(row.message));
                return wrap;
            }

            // Regular chat bubble: mine (teal, right) vs theirs.
            var mine = String(row.sender_id) === String(myUserId);
            wrap.className = 'chat-bubble ' + (mine ? 'mine' : 'theirs');
            wrap.setAttribute('data-msg-id', row.id);
            wrap.setAttribute('data-mine', mine ? '1' : '0');
            wrap.appendChild(document.createTextNode(row.message));
            var time = document.createElement('span');
            time.className = 'chat-bubble-time';
            time.textContent = friendlyTime(row.created_at);
            wrap.appendChild(time);
            return wrap;
        }

        function pollThread() {
            var url = 'messenger_poll.php?chat=' + encodeURIComponent(chatWith) + '&after=' + lastMsgId;
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .catch(function () { return null; })
                .then(function (data) {
                    if (!data || !data.ok) { return; }

                    // State changed -> server-rendered blocks must be
                    // rebuilt. Reload once (rare: request accepted,
                    // hire answered, job done/cancelled).
                    if (data.signature !== undefined && data.signature !== initialState) {
                        location.reload();
                        return;
                    }

                    // Append any new messages in order.
                    var msgs = data.messages || [];
                    if (msgs.length === 0) { return; }

                    // Remove the "Say hi..." empty state, if shown.
                    var empty = thread.querySelector('.chat-empty');
                    if (empty) { empty.remove(); }

                    var shouldStick = isNearBottom();
                    msgs.forEach(function (row) {
                        // Dedupe: never double-append an id already in the DOM.
                        if (row.kind !== 'system' && parseInt(row.id, 10) <= lastMsgId) { return; }
                        if (row.kind === 'system') {
                            // System rows have no id in the payload; skip
                            // if the same text is already the last child.
                            var last = thread.lastElementChild;
                            if (last && last.classList.contains('chat-system') &&
                                last.textContent === row.message) { return; }
                        }
                        var node = buildBubble(row);
                        thread.appendChild(node);
                        if (row.kind !== 'system') {
                            var id = parseInt(row.id, 10);
                            if (id > lastMsgId) { lastMsgId = id; }
                        }
                    });

                    // Keep the view pinned to the newest message only
                    // if the user was already at the bottom.
                    if (shouldStick || stickToBottom) {
                        thread.scrollTop = thread.scrollHeight;
                    }
                });
        }

        // Poll every 4s; also refresh immediately when the tab gains
        // focus (covers mobile apps being brought back to the front).
        setInterval(pollThread, 4000);
        window.addEventListener('focus', pollThread);
        // Initial poll right away so the thread is fresh on open.
        pollThread();
    }

    // ============================================================
    // 4. INBOX VIEW — live conversation list re-render
    // ============================================================
    if (list) {
        // NOTE: the poll payload sends RAW text (names, message
        // previews), so every field below is inserted as a text node
        // (textContent / createTextNode) — that is what keeps it XSS
        // safe without the server pre-escaping. Do NOT switch these
        // to innerHTML without escaping the values first.
        function buildRow(row) {
            var li = document.createElement('li');
            li.className = 'conv-item';

            // Select checkbox (hidden unless the gear entered select
            // mode — same markup messenger.js expects).
            var label = document.createElement('label');
            label.className = 'conv-check';
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'conv-select';
            cb.value = row.other_id;
            cb.setAttribute('aria-label', 'Select conversation with ' + row.full_name);
            var mark = document.createElement('span');
            mark.className = 'conv-checkmark';
            label.appendChild(cb);
            label.appendChild(mark);
            li.appendChild(label);

            // Main row link into the thread.
            var a = document.createElement('a');
            a.className = 'chat-item';
            a.href = 'messenger.php?chat=' + row.other_id;

            var avatar = document.createElement('span');
            avatar.className = 'chat-avatar';
            avatar.textContent = initialsOf(row.full_name);
            a.appendChild(avatar);

            var meta = document.createElement('span');
            meta.className = 'chat-meta';
            var strong = document.createElement('strong');
            strong.textContent = row.full_name;
            meta.appendChild(strong);

            var preview = document.createElement('span');
            preview.className = 'chat-preview';
            preview.textContent = (row.last_sender_mine ? 'You: ' : '') + row.last_message;
            meta.appendChild(preview);

            // Request / hire tags (same classes as the server).
            if (row.req_tag) {
                var rt = document.createElement('span');
                rt.className = 'chat-tag ' + row.req_tag.cls;
                rt.textContent = row.req_tag.label;
                meta.appendChild(rt);
            }
            if (row.hire_tag) {
                var ht = document.createElement('span');
                ht.className = 'chat-tag ' + row.hire_tag.cls;
                ht.textContent = row.hire_tag.label;
                meta.appendChild(ht);
            }
            a.appendChild(meta);

            var side = document.createElement('span');
            side.className = 'chat-side';
            var time = document.createElement('span');
            time.className = 'chat-time';
            time.textContent = friendlyTime(row.last_time);
            side.appendChild(time);
            if (row.unread > 0) {
                var badge = document.createElement('span');
                badge.className = 'chat-unread';
                badge.textContent = row.unread;
                side.appendChild(badge);
            }
            a.appendChild(side);
            li.appendChild(a);
            return li;
        }

        function renderList(data) {
            var rows = data.conversations || [];
            // If every conversation is gone (e.g. all deleted), the
            // empty state is server-rendered — leave it alone.
            if (rows.length === 0) {
                return;
            }
            // Rebuild the rows in place (keeps the <ul> element, so
            // messenger.js's delegated listeners keep working).
            list.innerHTML = '';
            rows.forEach(function (row) {
                list.appendChild(buildRow(row));
            });
        }

        function pollList() {
            fetch('messenger_poll.php?list=1', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .catch(function () { return null; })
                .then(function (data) {
                    if (data && data.ok) { renderList(data); }
                });
        }

        // Poll every 6s + refresh on tab focus.
        setInterval(pollList, 6000);
        window.addEventListener('focus', pollList);
        pollList();
    }
})();
