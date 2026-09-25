<?php
// ============================================================
// rate_modal.php — Job Completion & Verified Rating System
// (Phase 8 of the islaFIND master plan)
//
// The master plan names this page "rate_modal.php". In the built
// app the whole completion flow — the in-chat JOB DONE / CANCEL
// buttons, the star-rating modal (1-5 stars + feedback comment),
// and the POST that recomputes the provider's average_rating —
// lives inside messenger.php (modal markup + client JS) backed
// by rate_service.php (validation + aggregation).
//
// This file is a thin compatibility wrapper: it sends visitors
// to the messenger, which is exactly where a completed job's
// rating flow continues from. No rating logic is duplicated
// here; messenger.php + rate_service.php stay the single source
// of truth.
// ============================================================

// --- 1. Make the session-id helper available ------------------
require_once __DIR__ . '/security.php';

// --- 2. Redirect to the messenger ------------------------------
// The JOB DONE / rating modal appears inside an active chat
// thread, so the messenger is the correct landing page for this
// phase's feature. sid_append() keeps any cookie-less session id
// (used by sandboxed mobile-preview iframes) alive across the hop.
header('Location: ' . sid_append('messenger.php'));

// --- 2. Stop execution ---------------------------------------
// Exit immediately after issuing the redirect so no output is
// emitted after the Location header.
exit;
