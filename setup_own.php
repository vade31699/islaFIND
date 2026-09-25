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
// Test user who OWNS a listing, so their own profile appears in their feed.
$pdo->exec("DELETE FROM user_interactions WHERE user_id IN (SELECT id FROM users WHERE email = 'own.test@example.com')");
$pdo->exec("DELETE FROM service_contracts WHERE provider_id IN (SELECT id FROM users WHERE email = 'own.test@example.com') OR client_id IN (SELECT id FROM users WHERE email = 'own.test@example.com')");
$pdo->exec("DELETE FROM messages WHERE sender_id IN (SELECT id FROM users WHERE email = 'own.test@example.com') OR recipient_id IN (SELECT id FROM users WHERE email = 'own.test@example.com')");
$pdo->exec("DELETE FROM conversations WHERE provider_id IN (SELECT id FROM users WHERE email = 'own.test@example.com') OR client_id IN (SELECT id FROM users WHERE email = 'own.test@example.com')");
$pdo->exec("DELETE FROM user_devices WHERE user_id IN (SELECT id FROM users WHERE email = 'own.test@example.com')");
$pdo->exec("DELETE FROM providers WHERE user_id IN (SELECT id FROM users WHERE email = 'own.test@example.com')");
$pdo->exec("DELETE FROM users WHERE email = 'own.test@example.com'");
$pdo->prepare("INSERT INTO users (user_id, full_name, email, phone, date_of_birth, password_hash, is_verified) VALUES (?, ?, ?, ?, ?, ?, 1)")
    ->execute([random_int(1, 500000), 'Own Tester', 'own.test@example.com', '09990009004', '1995-06-15', password_hash('Testpass1!', PASSWORD_DEFAULT)]);
$uid = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO providers (user_id, profile_type, selected_title, municipality, barangay, profile_description) VALUES (?, 'individual', 'welder', 'Santa Fe', 'Poblacion', 'Own welding service')")
    ->execute([$uid]);
echo "created own.test user $uid with listing\n";
