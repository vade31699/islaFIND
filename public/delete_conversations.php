<?php
// ============================================================
// delete_conversations.php — Messenger soft-delete backend
// Receives the ids of the people whose threads the user wants
// to remove from THEIR OWN inbox, verifies the user really
// exchanged messages with each of them, and marks every message
// of that pair as deleted BY THIS USER (messages.deleted_by).
//
// This is a soft delete on purpose:
//   - The other participant keeps the full thread.
//   - Nothing is destroyed in the messages table.
//   - Sending a new message to that person (or them messaging
//     you) clears the flag and the thread revives naturally.
// All queries are parameterized PDO statements; the request
// must be a POST carrying a valid CSRF token.
// ============================================================

// --- 1. Harden the session cookie, then start the session ------
require_once __DIR__ . '/../include/security.php';
session_harden(); // must run before session_start()
session_start();

// --- 2. Session guard: logged in? ------------------------------
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 3. Database connection ------------------------------------
require_once __DIR__ . '/../include/db.php';

// --- 4. Load the current user ----------------------------------
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_unset();
    session_destroy();
    header('Location: ' . sid_append('login.php'));
    exit;
}

$myId = (int) $user['id'];

// --- 5. Method + CSRF guard ------------------------------------
// A forged cross-site request cannot know the session token, so
// anything that is not a valid POST is bounced back to the inbox.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    $_SESSION['flash_chat'] = ['type' => 'error', 'msg' => 'Your session expired or the form token is invalid.'];
    header('Location: ' . sid_append('messenger.php'));
    exit;
}

// --- 6. Collect and sanitize the target person ids --------------
// The form posts hidden inputs named ids[] (one per checked row).
// Each value is cast to int — no string can reach the SQL layer.
$ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
$ids = array_values(array_unique($ids));

if (!$ids) {
    $_SESSION['flash_chat'] = ['type' => 'error', 'msg' => 'No conversations were selected.'];
    header('Location: ' . sid_append('messenger.php'));
    exit;
}

// --- 7. Verify each target is a real user I actually chatted with --
// Build a placeholder list for the IN() clause (all NAMED params —
// PDO forbids mixing named and positional ones), then check that
// every requested id has at least one message between us. This is
// the ownership gate: I can only soft-delete threads I am part of,
// and only for users who exist.
$idParams = [];
foreach ($ids as $i => $id) {
    $idParams[':id' . $i] = $id;
}
$placeholders = implode(',', array_keys($idParams));
$stmt = $pdo->prepare(
    "SELECT DISTINCT
        CASE WHEN sender_id = :me THEN recipient_id ELSE sender_id END AS other_id
     FROM messages
     WHERE (sender_id = :me OR recipient_id = :me)
       AND CASE WHEN sender_id = :me THEN recipient_id ELSE sender_id END IN ($placeholders)"
);
$stmt->execute(array_merge([':me' => $myId], $idParams));
$verified = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

if (!$verified) {
    $_SESSION['flash_chat'] = ['type' => 'error', 'msg' => 'Those conversations were not found in your inbox.'];
    header('Location: ' . sid_append('messenger.php'));
    exit;
}

// --- 8. Soft-delete every message of those pairs for ME ----------
// Deletion is INDEPENDENT per user: two flag slots exist on every
// message (deleted_by + deleted_by2), one per side of the pair.
// This handler writes MY id into the free slot:
//   - if deleted_by is empty (or already mine) it becomes mine,
//   - otherwise (the OTHER user owns that slot) deleted_by2 gets
//     my id.
// The conversation list query ignores threads whose last message
// carries my id in EITHER slot, so the thread vanishes from MY
// inbox while the other person's copy stays fully intact — and
// stays intact even if they delete it too (their flag lands in
// the other slot instead of overwriting mine).
$idParams = [];
foreach ($verified as $i => $id) {
    $idParams[':id' . $i] = $id;
}
$placeholders = implode(',', array_keys($idParams));
$stmt = $pdo->prepare(
    "UPDATE messages SET
        deleted_by = CASE
            WHEN deleted_by IS NULL OR deleted_by = :me THEN :me
            ELSE deleted_by
        END,
        deleted_by2 = CASE
            WHEN deleted_by IS NOT NULL AND deleted_by <> :me
                 AND (deleted_by2 IS NULL OR deleted_by2 = :me) THEN :me
            ELSE deleted_by2
        END
     WHERE (sender_id = :me OR recipient_id = :me)
       AND CASE WHEN sender_id = :me THEN recipient_id ELSE sender_id END IN ($placeholders)"
);
$stmt->execute(array_merge([':me' => $myId], $idParams));

// --- 9. Confirm + bounce back to the inbox ----------------------
$_SESSION['flash_chat'] = [
    'type' => 'success',
    'msg'  => count($verified) . ' conversation' . (count($verified) === 1 ? '' : 's') . ' removed from your inbox.',
];
header('Location: ' . sid_append('messenger.php'));
exit;
