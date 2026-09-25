<?php
// ============================================================
// messenger_poll.php — Live messenger polling endpoint
// A small JSON API consumed by messenger_live.js every few
// seconds so new messages appear in the open thread WITHOUT a
// page reload, and the inbox list refreshes by itself.
//
// Two modes (logged-in users only):
//   messenger_poll.php?chat=<id>&after=<msgId>
//       Thread mode. Returns any messages newer than <msgId>
//       between me and <chat>, PLUS a state signature that
//       captures everything that drives server-rendered UI
//       (message-request status, contract status, HIRE/accept
//       visibility). When the signature changes, the client
//       reloads ONCE — appending chat bubbles is not enough
//       when whole button blocks (ACCEPT/DECLINE, JOB DONE)
//       must appear or disappear.
//       Also marks the just-polled messages as read, so the
//       unread badge clears while the user sits in the thread.
//
//   messenger_poll.php?list=1
//       Inbox mode. Returns the same conversation list the
//       inbox view renders, as JSON, so messenger_live.js can
//       rebuild the rows (new conversations, unread counts,
//       previews, hire/request tags) without a reload.
//
// Deleted messages are excluded exactly like messenger.php:
// both the thread-level flag (deleted_by, whole-conversation
// delete) and the per-message flag (msg_deleted_by) hide a
// message from MY view, so nothing deleted ever reappears via
// polling.
//
// OUTPUT CONTRACT: every payload is data-only — message text and
// names are returned RAW (never HTML-escaped). Escaping belongs
// at the render site, and messenger_live.js does it by building
// text nodes (createTextNode/textContent). Pre-escaping here
// would double-encode the text and show entities in the UI.
// ============================================================

// --- 1. Harden the session cookie, then start the session ------
require_once __DIR__ . '/security.php';
session_harden(); // must run before session_start()
session_start();

// --- 2. Session guard: logged in? ------------------------------
// No user_id -> not logged in -> empty payload (never an error,
// so the client's fetch simply shows nothing).
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false]);
    exit;
}

// --- 3. Database connection -------------------------------------
require_once __DIR__ . '/db.php';

// --- 4. Load the user row (validates the stored session id) -----
$stmt = $pdo->prepare('SELECT id, full_name FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false]);
    exit;
}

$myId = (int) $user['id'];

// ============================================================
// 5. THREAD MODE (?chat=<id>&after=<msgId>)
// ============================================================
if (isset($_GET['chat'])) {
    $otherId = (int) $_GET['chat'];
    $afterId = max(0, (int) ($_GET['after'] ?? 0));

    // The other user must exist (and cannot be me).
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $otherId]);
    $other = $stmt->fetch();

    if (!$other || $otherId === $myId) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
        exit;
    }

    // ---- (a) New messages since the client's last id ------------        // Same pair-filter and soft-delete rules as messenger.php's
        // thread query, plus id > after. Both per-user delete slots
        // (deleted_by + deleted_by2) are checked against my id, so a
        // conversation hidden by EITHER side never leaks back via
        // polling. Newest first, then reversed for display.
        $stmt = $pdo->prepare(
            'SELECT id, sender_id, message, kind, created_at
             FROM messages
             WHERE ((sender_id = :me AND recipient_id = :other)
                 OR (sender_id = :other AND recipient_id = :me))
               AND id > :after
               AND (deleted_by IS NULL OR deleted_by <> :me)
               AND (deleted_by2 IS NULL OR deleted_by2 <> :me)
               AND (msg_deleted_by IS NULL OR msg_deleted_by <> :me)
             ORDER BY id ASC'
        );
    $stmt->execute([':me' => $myId, ':other' => $otherId, ':after' => $afterId]);
    $newMessages = $stmt->fetchAll();

    // Mark every incoming message from this person as read. The
    // user is sitting in the thread right now, so polling acts
    // exactly like a page load would — the unread badge (inbox
    // list + notification bell) clears on the next poll.
    $stmt = $pdo->prepare(
        'UPDATE messages SET is_read = 1
         WHERE recipient_id = :me AND sender_id = :other AND is_read = 0'
    );
    $stmt->execute([':me' => $myId, ':other' => $otherId]);

    // ---- (b) State signature ------------------------------------
    // Everything that changes which server-rendered UI blocks
    // appear in the thread: the message-request status in both
    // directions (locks/unlocks the chat + input bar), and the
    // contract status in both directions (HIRE! button, ACCEPT/
    // DECLINE controls, JOB DONE / CANCEL, On-the-Job banner).
    // This mirrors the computation in messenger.php so the two
    // always agree.
    $myRequestStatus    = null;
    $theirRequestStatus = null;
    $stmt = $pdo->prepare(
        'SELECT id, provider_id, client_id, status FROM conversations
         WHERE (provider_id = :me AND client_id = :other)
            OR (provider_id = :other AND client_id = :me)
         ORDER BY id LIMIT 2'
    );
    $stmt->execute([':me' => $myId, ':other' => $otherId]);
    foreach ($stmt->fetchAll() as $cr) {
        if ((int) $cr['provider_id'] === $otherId) {
            $myRequestStatus = $cr['status'];
        } else {
            $theirRequestStatus = $cr['status'];
        }
    }

    $workerStatus = null;   // contract where I am the WORKER
    $clientStatus = null;   // contract where I am the CLIENT
    // The LATEST contract wins in both directions (sequential
    // re-hire support): an old completed/cancelled row must never
    // shadow a fresh pending hire for the same pair.
    $stmt = $pdo->prepare(
        'SELECT id, status FROM service_contracts
         WHERE provider_id = :me AND client_id = :other
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([':me' => $myId, ':other' => $otherId]);
    if ($row = $stmt->fetch()) {
        $workerStatus = $row['status'];
    }
    $stmt = $pdo->prepare(
        'SELECT id, status FROM service_contracts
         WHERE provider_id = :other AND client_id = :me
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([':me' => $myId, ':other' => $otherId]);
    if ($row = $stmt->fetch()) {
        $clientStatus = $row['status'];
    }

    $signature = implode('|', [
        (string) $myRequestStatus,
        (string) $theirRequestStatus,
        (string) $workerStatus,
        (string) $clientStatus,
    ]);

    header('Content-Type: application/json');
    echo json_encode([
        'ok'       => true,
        'signature' => $signature,
        'messages' => $newMessages,
        'server_time' => date('Y-m-d H:i:s'),
    ]);
    exit;
}

// ============================================================
// 6. INBOX MODE (?list=1)
// ============================================================
// Rebuild the conversation list exactly like messenger.php's
// section 9: one row per person I exchanged messages with,
// showing the newest message I can still see (per-message
// deletes are skipped), the unread count, and the hire /
// message-request tags. The client re-renders the <ul> from
// this JSON, so a new conversation or a fresh unread badge
// appears without reloading the page.
if (isset($_GET['list'])) {
    $stmt = $pdo->prepare(
        'SELECT c.other_id, u.full_name,
                m.message AS last_message, m.created_at AS last_time,
                m.sender_id AS last_sender, m.is_read AS last_read
         FROM (
            SELECT
                CASE WHEN sender_id = :me THEN recipient_id ELSE sender_id END AS other_id,
                MAX(id) AS last_id
            FROM messages
            WHERE (sender_id = :me OR recipient_id = :me)
              AND (deleted_by IS NULL OR deleted_by <> :me)
              AND (deleted_by2 IS NULL OR deleted_by2 <> :me)
              AND (msg_deleted_by IS NULL OR msg_deleted_by <> :me)
            GROUP BY other_id
         ) c
         JOIN messages m ON m.id = c.last_id
         JOIN users u ON u.id = c.other_id
         WHERE (m.deleted_by IS NULL OR m.deleted_by <> :me2)
           AND (m.deleted_by2 IS NULL OR m.deleted_by2 <> :me2)
         ORDER BY m.id DESC'
    );
    $stmt->execute([':me' => $myId, ':me2' => $myId]);
    $conversations = $stmt->fetchAll();

    // Unread counts per sender (badge on each row).
    $stmt = $pdo->prepare(
        'SELECT sender_id, COUNT(*) AS n FROM messages
         WHERE recipient_id = :me AND is_read = 0
           AND (deleted_by IS NULL OR deleted_by <> :me)
           AND (deleted_by2 IS NULL OR deleted_by2 <> :me)
           AND (msg_deleted_by IS NULL OR msg_deleted_by <> :me)
         GROUP BY sender_id'
    );
    $stmt->execute([':me' => $myId]);
    $unread = [];
    foreach ($stmt->fetchAll() as $r) {
        $unread[(int) $r['sender_id']] = (int) $r['n'];
    }

    // Hire/job tags — same maps as messenger.php.
    $hireTags = [];
    $stmt = $pdo->query(
        "SELECT provider_id, client_id, status FROM service_contracts
         WHERE status IN ('pending_hire','accepted','completed','cancelled')"
    );
    foreach ($stmt->fetchAll() as $row) {
        $amProvider = (int) $row['provider_id'] === $myId;
        $other      = $amProvider ? (int) $row['client_id'] : (int) $row['provider_id'];
        $map        = $amProvider
            ? ['pending_hire' => ['label' => 'Hire request', 'cls' => 'hire'],
               'accepted'     => ['label' => 'On the Job',   'cls' => 'onthejob'],
               'completed'    => ['label' => 'Job done',     'cls' => 'done'],
               'cancelled'    => ['label' => 'Cancelled',    'cls' => 'cancel']]
            : ['pending_hire' => ['label' => 'Hire pending', 'cls' => 'hire'],
               'accepted'     => ['label' => 'Hired',        'cls' => 'onthejob'],
               'completed'    => ['label' => 'Job done',     'cls' => 'done'],
               'cancelled'    => ['label' => 'Cancelled',    'cls' => 'cancel']];
        $hireTags[$other] = $map[$row['status']];
    }

    // Message-request tags.
    $reqTags = [];
    $stmt = $pdo->query(
        "SELECT provider_id, client_id, status FROM conversations
         WHERE status IN ('pending','declined')"
    );
    foreach ($stmt->fetchAll() as $row) {
        $amProvider = (int) $row['provider_id'] === $myId;
        $other      = $amProvider ? (int) $row['client_id'] : (int) $row['provider_id'];
        $reqTags[$other] = $row['status'] === 'pending'
            ? ($amProvider
                ? ['label' => 'Message request', 'cls' => 'req']
                : ['label' => 'Request pending', 'cls' => 'req'])
            : ['label' => 'Declined', 'cls' => 'cancel'];
    }

    // Serialize the rows with the RAW text values (same contract as
    // thread mode above): this is a JSON API, so the payload carries
    // data, not markup — the client escapes it at the moment it
    // renders. messenger_live.js inserts every one of these strings
    // with textContent (never innerHTML), so escaping here as well
    // would reach the screen as literal entities — a name like
    // "O'Brien" showed up as "O&#039;Brien" and any stored HTML tag
    // was displayed as visible &lt;tag&gt; text.
    // Escape for HTML only if a consumer ever writes these values
    // into innerHTML.
    $rows = [];
    foreach ($conversations as $conv) {
        $otherId = (int) $conv['other_id'];
        $rows[] = [
            'other_id'   => $otherId,
            'full_name'  => $conv['full_name'],
            'last_message' => $conv['last_message'],
            'last_sender_mine' => (int) $conv['last_sender'] === $myId,
            'last_time'  => $conv['last_time'],
            'unread'     => $unread[$otherId] ?? 0,
            'req_tag'    => $reqTags[$otherId] ?? null,
            'hire_tag'   => $hireTags[$otherId] ?? null,
        ];
    }

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'conversations' => $rows]);
    exit;
}

// Unknown mode: harmless empty payload.
header('Content-Type: application/json');
echo json_encode(['ok' => false]);
