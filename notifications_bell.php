<?php
// ============================================================
// notifications_bell.php — Global notification bell (partial)
// Renders the header bell button + unread badge + dropdown
// panel, and loads notifications.js (which polls
// notifications.php every ~30s to keep the badge fresh).
//
// Include this INSIDE the <header> of any page that should show
// notifications (dashboard.php is the only one that does today).
// The badge number is filled by JavaScript on load, so there is
// nothing to precompute server-side here.
// ============================================================
?>
<div class="notif-wrap" id="notifWrap">
    <!-- The bell button: same round translucent look as the other
         header icons, with an unread-count badge on its corner -->
    <button type="button" class="header-icon header-bell" id="notifBell"
            aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
        <!-- Bell glyph (Feather-style) -->
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <!-- Unread-count bubble; shown/hidden by notifications.js -->
        <span class="notif-badge" id="notifBadge" hidden>0</span>
    </button>

    <!-- Dropdown panel: populated by notifications.js when tapped -->
    <div class="notif-panel" id="notifPanel" hidden>
        <h4 class="notif-title">Notifications</h4>
        <div class="notif-list" id="notifList"></div>
    </div>
</div>
<script src="notifications.js"></script>
