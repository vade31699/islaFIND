<?php
// ============================================================
// provider_reviews.php — review records for one islaFIND listing
// A lightweight JSON endpoint used by the feed's detail modal.
// Given a provider id it returns:
//   - the overall average + review count
//   - a per-star breakdown (how many 5s, 4s, 3s, 2s, 1s)
//   - every review (reviewer name, star rating, comment, date)
// so the client can render the 5★/4★/3★/2★/1★/ALL tabs and the
// list of records without reloading the page. Only verified
// reviews (those tied to a completed service contract) exist in
// the reviews table, so everything returned is earned.
// ============================================================

// --- 1. Harden the session cookie, then start the session ------
require_once __DIR__ . '/security.php';
session_harden();
session_start();

// --- 2. Session guard: logged in? ------------------------------
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    exit;
}

// --- 3. Database connection -------------------------------------
require_once __DIR__ . '/db.php';

// --- 4. Read + validate the provider id -------------------------
$providerId = (int) ($_GET['provider_id'] ?? 0);
if ($providerId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'No provider given.']);
    exit;
}

// --- 5. Load the listing (must exist) ---------------------------
$stmt = $pdo->prepare('SELECT id, profile_type, name, selected_title FROM providers WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $providerId]);
$provider = $stmt->fetch();

if (!$provider) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Listing not found.']);
    exit;
}

// --- 6. Load every review for this listing ----------------------
// Reviewer names come from the users table; only reviews linked to
// a completed service contract are allowed in the table at all.
$stmt = $pdo->prepare(
    'SELECT r.rating, r.comment, r.created_at, u.full_name AS reviewer
     FROM reviews r
     JOIN users u ON u.id = r.user_id
     WHERE r.provider_id = :pid
     ORDER BY r.created_at DESC'
);
$stmt->execute([':pid' => $providerId]);
$reviews = $stmt->fetchAll();

// --- 7. Build the per-star breakdown ----------------------------
// counts[5] = how many 5-star reviews, etc. plus the overall
// average and total, so the client can label the filter tabs.
$counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
$total  = 0;
$sum    = 0;
foreach ($reviews as $r) {
    $rating = (int) $r['rating'];
    if ($rating >= 1 && $rating <= 5) {
        $counts[$rating]++;
    }
    $total++;
    $sum += (int) $r['rating'];
}

// --- 8. Shape the payload ---------------------------------------
$payload = [
    'ok'      => true,
    'provider' => [
        'id'    => (int) $provider['id'],
        'name'  => $provider['name'] ?: null,
        'title' => $provider['selected_title'],
    ],
    'avg'    => $total > 0 ? round($sum / $total, 1) : 0.0,
    'count'  => $total,
    'counts' => $counts,
    'reviews' => array_map(function ($r) {
        return [
            'rating'  => (int) $r['rating'],
            'comment' => $r['comment'] ?: '',
            'reviewer' => $r['reviewer'],
            'date'    => date('M j, Y', strtotime($r['created_at'])),
        ];
    }, $reviews),
];

header('Content-Type: application/json');
echo json_encode($payload);
