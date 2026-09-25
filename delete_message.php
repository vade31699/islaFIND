<?php
// ============================================================
// delete_message.php — Per-message soft-delete backend
// Deletes ONE message from the sender/recipient's OWN thread
// view (long-press a bubble in messenger.php). The other
// person keeps the message; nothing is destroyed in the
// messages table.
//
// This is deliberately separate from delete_conversations.php
// (whole-thread delete). Per-message delete uses its own flag
// (messages.msg_deleted_by) so that:
//   - deleting one message never removes the whole conversation
//     from the inbox (the inbox preview is driven by the
//     thread-level deleted_by flag),
//   - sending a new message does NOT resurrect a deleted one
//     (only the thread-level flag is cleared on revive).
// All queries are parameterized PDO; the request must be a
// POST carrying a valid CSRF token.
// ============================================================

// --- 1. Harden the session cookie, then start the session ------
require_once __DIR__ . '/security.php';
session_harden(); // must run before session_start()
session_start();

// --- 2. Session guard: logged in? ------------------------------
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 3. Database connection ------------------------------------
require_once __DIR__ . '/db.php';

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

// --- 6. Collect and sanitize the target message id --------------
// Cast to int so no string can reach the SQL layer.
$msgId = (int) ($_POST['message_id'] ?? 0);

if ($msgId <= 0) {
    $_SESSION['flash_chat'] = ['type' => 'error', 'msg' => 'No message was selected.'];
    header('Location: ' . sid_append('messenger.php'));
    exit;
}

// --- 7. Ownership gate: I must be a participant in that message --
// Fetch the message and check that I am either the sender or the
// recipient. A stranger must never be able to flag a message they
// are not part of.
$stmt = $pdo->prepare(
    'SELECT id, sender_id, recipient_id FROM messages WHERE id = :id LIMIT 1'
);
$stmt->execute([':id' => $msgId]);
$msg = $stmt->fetch();

if (!$msg) {
    $_SESSION['flash_chat'] = ['type' => 'error', 'msg' => 'That message was not found.'];
    header('Location: ' . sid_append('messenger.php'));
    exit;
}

if ((int) $msg['sender_id'] !== $myId && (int) $msg['recipient_id'] !== $myId) {
    $_SESSION['flash_chat'] = ['type' => 'error', 'msg' => 'You are not part of that message.'];
    header('Location: ' . sid_append('messenger.php'));
    exit;
}

// The other participant — we return to their thread after deleting.
$otherId = (int) $msg['sender_id'] === $myId ? (int) $msg['recipient_id'] : (int) $msg['sender_id'];

// --- 8. Soft-delete THIS message for ME --------------------------
// Set msg_deleted_by = my id on exactly one row. The thread view
// query hides messages whose msg_deleted_by matches my id, while
// the other person's copy stays fully intact.
$stmt = $pdo->prepare(
    'UPDATE messages SET msg_deleted_by = :me WHERE id = :id'
);
$stmt->execute([':me' => $myId, ':id' => $msgId]);

// --- 9. Confirm + bounce back to the thread ---------------------
$_SESSION['flash_chat'] = ['type' => 'success', 'msg' => 'Message deleted (only you can no longer see it).'];
header('Location: ' . sid_append('messenger.php?chat=' . $otherId));
exit;
