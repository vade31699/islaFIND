<?php
// ============================================================
// notifications.php — Notification bell data endpoint
// A small JSON API used by notifications.js on the dashboard
// and directory pages. Logged-in users poll it (every ~30s and
// on tab focus) and it returns:
//   total  — the badge number: unread messages + pending hire
//            requests + active ("On the Job") contracts
//   items  — the ordered list shown when the bell is tapped
//            (new messages per sender, pending hire requests,
//            and accepted hires)
// The items are DERIVED from live data (never stored), so each
// notification clears itself the moment the user acts on it:
// reading a thread marks its messages read, accepting or
// declining a hire request moves the contract out of
// pending_hire, and completing a job clears the accepted one.
// ============================================================

// --- 1. Harden the session cookie, then start the session ------
require_once __DIR__ . '/security.php';
session_harden(); // must run before session_start()
session_start();

// --- 2. Session guard: logged in? ------------------------------
// No user_id in the session -> not logged in -> nothing to show.
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['total' => 0, 'items' => []]);
    exit;
}

// --- 3. Database connection -------------------------------------
require_once __DIR__ . '/db.php';

// --- 4. Load the user row (validates the stored session id) -----
$stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch();

// Account deleted while logged in? Treat as logged out.
if (!$user) {
    header('Content-Type: application/json');
    echo json_encode(['total' => 0, 'items' => []]);
    exit;
}

$myId = (int) $user['id'];

// --- 5. Collect the notification items --------------------------
// Every item: [created_at, type, text, link]. They come from
// three live queries, are merged, then sorted newest first.
$items = [];

// (a) Unread messages, grouped per sender ------------------------
// One item per person who messaged me while is_read = 0. Opening
// the thread marks those messages read (messenger.php), so the
// item disappears on the next poll automatically.
$stmt = $pdo->prepare(
    'SELECT u.id AS sender_id, u.full_name, COUNT(*) AS n, MAX(m.created_at) AS latest
     FROM messages m
     JOIN users u ON u.id = m.sender_id
     WHERE m.recipient_id = :me AND m.is_read = 0
       AND (m.deleted_by IS NULL OR m.deleted_by <> :me)
       AND (m.deleted_by2 IS NULL OR m.deleted_by2 <> :me)
       AND (m.msg_deleted_by IS NULL OR m.msg_deleted_by <> :me)
     GROUP BY u.id, u.full_name'
);
$stmt->execute([':me' => $myId]);
foreach ($stmt->fetchAll() as $row) {
    $count = (int) $row['n'];
    $items[] = [
        'created_at' => $row['latest'],
        'type'       => 'message',
        'text'       => ($count === 1)
            ? 'New message from ' . $row['full_name']
            : 'New messages from ' . $row['full_name'] . ' (' . $count . ')',
        'link'       => 'messenger.php?chat=' . (int) $row['sender_id'],
    ];
}

// (b) Pending hire requests (I am the provider) -------------------
// A pending_hire contract means the client clicked HIRE! and I
// have not answered yet — this is the "needs action" alert that
// pairs with the Accept / Decline banner in the thread.
$stmt = $pdo->prepare(
    'SELECT sc.created_at, u.id AS client_id, u.full_name
     FROM service_contracts sc
     JOIN users u ON u.id = sc.client_id
     WHERE sc.provider_id = :me AND sc.status = \'pending_hire\''
);
$stmt->execute([':me' => $myId]);
foreach ($stmt->fetchAll() as $row) {
    $items[] = [
        'created_at' => $row['created_at'],
        'type'       => 'hire',
        'text'       => 'Hire request from ' . $row['full_name'],
        'link'       => 'messenger.php?chat=' . (int) $row['client_id'],
    ];
}

// (c) Accepted hires (I am the client — "On the Job") -------------
// The provider accepted my hire request; the job stays active
// until it is completed or cancelled, so it remains listed.
$stmt = $pdo->prepare(
    'SELECT sc.created_at, u.id AS provider_id, u.full_name
     FROM service_contracts sc
     JOIN users u ON u.id = sc.provider_id
     WHERE sc.client_id = :me AND sc.status = \'accepted\''
);
$stmt->execute([':me' => $myId]);
foreach ($stmt->fetchAll() as $row) {
    $items[] = [
        'created_at' => $row['created_at'],
        'type'       => 'job',
        'text'       => $row['full_name'] . ' accepted your hire — On the Job',
        'link'       => 'messenger.php?chat=' . (int) $row['provider_id'],
    ];
}

// --- 6. Sort newest first + cap the list -------------------------
// created_at is an ISO timestamp, so plain string comparison puts
// the most recent item first. The list is capped to keep the
// dropdown fast even with heavy activity.
usort($items, function ($a, $b) {
    return strcmp((string) $b['created_at'], (string) $a['created_at']);
});
$items = array_slice($items, 0, 20);

// --- 7. Compute the badge total ----------------------------------
// One badge point per unread MESSAGE (not per sender), plus one
// per pending hire request and one per active job. The badge
// renderer caps the display at "99+".
$total = 0;

$stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = :me AND is_read = 0 AND (deleted_by IS NULL OR deleted_by <> :me) AND (deleted_by2 IS NULL OR deleted_by2 <> :me) AND (msg_deleted_by IS NULL OR msg_deleted_by <> :me)');
$stmt->execute([':me' => $myId]);
$total += (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM service_contracts WHERE provider_id = :me AND status = \'pending_hire\'');
$stmt->execute([':me' => $myId]);
$total += (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM service_contracts WHERE client_id = :me AND status = \'accepted\'');
$stmt->execute([':me' => $myId]);
$total += (int) $stmt->fetchColumn();

// --- 8. Return the payload as JSON -------------------------------
header('Content-Type: application/json');
echo json_encode(['total' => $total, 'items' => $items]);
