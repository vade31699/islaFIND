<?php
// ============================================================
// home_feed.php — Recommendation Feed & Cold-Start Algorithm
// (Phase 6 of the islaFIND master plan)
//
// The master plan names this page "home_feed.php". In the built
// app the recommendation engine (cold-start ⭐ Top-Rated sections
// + the ✨ Recommended-for-You affinity ranking) lives inside
// dashboard.php on its HOME tab, because the feed is embedded in
// the logged-in app shell (header, bottom nav, settings hub).
//
// This file is a thin compatibility wrapper: any deep link to
// home_feed.php lands on the dashboard's Home tab, which IS the
// recommendation feed. No logic is duplicated here — the single
// source of truth stays in dashboard.php so the feed can never
// drift between two implementations.
// ============================================================

// --- 1. Make the session-id helper available ------------------
// sid_append() lives in security.php, and it is what keeps the
// session id on this redirect for clients that cannot store our
// cookie. Without this include the line below would call an
// undefined function and fatal instead of redirecting.
require_once __DIR__ . '/../include/security.php';

// --- 2. Redirect to the real feed ----------------------------
// dashboard.php?tab=home opens the app shell on its Home panel,
// where the recommendation engine renders the feed. The browser
// performs this redirect instantly; the visitor never notices.
header('Location: ' . sid_append('dashboard.php?tab=home'));

// --- 3. Stop execution ---------------------------------------
// Nothing below this line should run — the Location header has
// already told the browser where to go, so we exit immediately
// to avoid sending any accidental output after the redirect.
exit;
