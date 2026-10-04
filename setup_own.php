<?php
// ============================================================
// setup_own.php — Test data: a user who OWNS a listing
// Creates (or resets) a ready-to-use account so the owner-only
// screens — the islaFIND Profile panel, My Jobs, the "own listing"
// feed card — can be exercised without registering by hand.
//
//   php setup_own.php         (from the project root)
//
// Credentials: own.test@example.com / Testpass1!
//
// COMMAND LINE ONLY. This script DELETES and recreates a user, and
// it sits in the web root, so a browser request must never reach the
// DELETE statements below: the 404 guard makes that impossible.
// ============================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/purge.php';

// Test user who OWNS a listing, so their own profile appears in their feed.
//
// The old fixture was reset with seven hand-ordered DELETEs, which had to be
// kept in step with the schema by hand and still missed two things: the
// login-throttle row holding the address, and the account's uploaded files.
// One call to the same purge the app uses when somebody really does delete
// their account removes all of it, cascade included.
$stale = $pdo->prepare('SELECT id FROM users WHERE email = :email');
$stale->execute([':email' => 'own.test@example.com']);
$staleId = $stale->fetchColumn();
if ($staleId !== false) {
    isla_account_purge($pdo, (int) $staleId);
}

$pdo->prepare("INSERT INTO users (user_id, full_name, email, phone, date_of_birth, password_hash, is_verified) VALUES (?, ?, ?, ?, ?, ?, 1)")
    ->execute([random_int(1, 500000), 'Own Tester', 'own.test@example.com', '09990009004', '1995-06-15', password_hash('Testpass1!', PASSWORD_DEFAULT)]);
$uid = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO providers (user_id, profile_type, selected_title, municipality, barangay, profile_description) VALUES (?, 'individual', 'welder', 'Santa Fe', 'Poblacion', 'Own welding service')")
    ->execute([$uid]);
echo "created own.test user $uid with listing\n";
