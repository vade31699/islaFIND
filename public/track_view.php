<?php
// ============================================================
// track_view.php — View tracking for the recommendation engine
// Called via fetch() from the feed when a user looks at a card.
// It does two things:
//   1. Bumps the provider's view_count / interaction_count so
//      popularity metrics stay fresh.
//   2. Inserts a row into user_interactions so the algorithm
//      knows the user's taste (category affinity).
// No output is expected — the browser fires it and moves on.
// ============================================================

// --- 1. Harden + start the session ------------------------------
require_once __DIR__ . '/../include/security.php';
session_harden();
session_start();

// --- 2. Must be a logged-in user --------------------------------
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

// --- 3. The provider id must be present --------------------------
$providerId = (int) ($_GET['provider_id'] ?? 0);
if ($providerId <= 0) {
    exit;
}

// --- 4. Database connection -------------------------------------
require_once __DIR__ . '/../include/db.php';

// --- 5. Load the provider (category is needed for affinity) ------
$stmt = $pdo->prepare('SELECT id, selected_title FROM providers WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $providerId]);
$provider = $stmt->fetch();

if (!$provider) {
    exit;                    // gone already — nothing to track
}

// --- 6. Record the interaction -----------------------------------
$stmt = $pdo->prepare(
    'INSERT INTO user_interactions (user_id, provider_id, category, action)
     VALUES (:uid, :pid, :cat, :act)'
);
$stmt->execute([
    ':uid' => $_SESSION['user_id'],
    ':pid' => $providerId,
    ':cat' => $provider['selected_title'],
    ':act' => 'view',
]);

// --- 7. Bump the aggregate counters ------------------------------
$stmt = $pdo->prepare(
    'UPDATE providers
     SET view_count = view_count + 1, interaction_count = interaction_count + 1
     WHERE id = :id'
);
$stmt->execute([':id' => $providerId]);

// Done — no body needed.
