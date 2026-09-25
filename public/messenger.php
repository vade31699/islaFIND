<?php
// ============================================================
// messenger.php — Messenger (Facebook-style chat)
// A protected page with a green header and two views:
//   1. Conversation list — every person you've chatted with,
//      the last message preview, time, and an unread badge.
//      A "New Message" option lists all users to start a chat.
//   2. Chat thread (?chat=<user id>) — the message bubbles
//      with a sticky input bar to reply. Opening a thread
//      marks all incoming messages from that person as read.
// Messages live in the `messages` table; every send is a POST
// with a CSRF token.
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
$csrf = htmlspecialchars(csrf_token());
$myName = htmlspecialchars($user['full_name']);

// --- 5. Helper: initials for avatar circles ---------------------
function initialsOf(string $name): string
{
    $initials = '';
    foreach (preg_split('/\s+/', trim($name)) as $part) {
        if ($part !== '' && strlen($initials) < 2) {
            $first = function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
            $initials .= function_exists('mb_strtoupper') ? mb_strtoupper($first) : strtoupper($first);
        }
    }
    return $initials ?: '?';
}

// --- 6. Helper: friendly timestamp ------------------------------
// Today -> "2:34 PM"; earlier -> "Jun 3"
function friendlyTime(string $datetime): string
{
    $ts = strtotime($datetime);
    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return date('g:i A', $ts);
    }
    return date('M j', $ts);
}

// --- 7. Handle sending a message (POST + CSRF) ------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $otherId = (int) ($_POST['recipient_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    $convId  = (int) ($_POST['conversation_id'] ?? 0);   // thread from an inquiry

    // The recipient must exist and the message must not be empty
    // (cap the length so nobody floods the inbox).
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $otherId]);
    $recipientExists = $stmt->fetch();

    if ($recipientExists && $message !== '' && mb_strlen($message) <= 2000) {
        // Resolve the conversation(s) for this pair (replies must
        // stay inside the same thread the inquiry opened).
        $stmt = $pdo->prepare(
            'SELECT id, status FROM conversations
             WHERE (provider_id = :me AND client_id = :other)
                OR (provider_id = :other AND client_id = :me)
             ORDER BY id LIMIT 2'
        );
        $stmt->execute([':me' => $myId, ':other' => $otherId]);
        $convRows = $stmt->fetchAll();
        $convId   = null;
        // The chat is open when NO conversation row exists (direct
        // chat) or at least one thread is ACCEPTED. A pending or
        // declined message request does not open a chat yet.
        $convOpen = count($convRows) === 0;
        foreach ($convRows as $cr) {
            $convId = (int) $cr['id'];
            if ($cr['status'] === 'accepted') {
                $convOpen = true;
            }
        }

        // Block replies while the message request is still waiting
        // for acceptance (or was declined): the pair can only talk
        // once the provider opens the thread.
        if (!$convOpen) {
            $_SESSION['flash_chat'] = ['type' => 'info', 'msg' => 'Wait for them to accept your message request first.'];
            header('Location: ' . sid_append('messenger.php?chat=' . $otherId));
            exit;
        }

        // Insert the new message. The old soft-delete flags are
        // deliberately NOT cleared: deletion is independent per user
        // and a re-message starts a FRESH view — the sender sees the
        // new message (and anything after it), while history they
        // deleted stays hidden from THEM. The counterparty keeps
        // their full copy until they delete it too.
        $stmt = $pdo->prepare(
            'INSERT INTO messages (conversation_id, sender_id, recipient_id, message)
             VALUES (:c, :s, :r, :m)'
        );
        $stmt->execute([':c' => $convId, ':s' => $myId, ':r' => $otherId, ':m' => $message]);
    }

    // Redirect back to the thread (PRG: prevents double-send on refresh).
    header('Location: ' . sid_append('messenger.php?chat=' . $otherId));
    exit;
}

// --- 7b. One-shot flash from hire_action.php --------------------
$chatFlash = null;
if (isset($_SESSION['flash_chat'])) {
    $chatFlash = $_SESSION['flash_chat'];
    unset($_SESSION['flash_chat']);
}

// --- 8. Resolve the current view --------------------------------
// messenger.php            -> conversation list
// messenger.php?new=1      -> "New message" user picker
// messenger.php?chat=<id>  -> chat thread with that user
$chatWith = isset($_GET['chat']) ? (int) $_GET['chat'] : 0;
$showNew  = isset($_GET['new']);
$freshConv = isset($_GET['newconv']);   // just created by an inquiry
$otherUser = null;
$messages  = [];
$convIdForForm = 0;

// HIRE-flow state for the thread. IMPORTANT: the roles are NOT
// derived from a single conversation row — a pair can legitimately
// have threads in BOTH directions (each person inquired on the
// other's profile). The HIRE button belongs to the account that
// inquired (the client), so it is driven by the client-side thread
// + the other person being a provider, independently of which
// conversation row sorts first.
$otherIsProvider = false; // the other user owns an islaFIND profile
$canHire         = false; // I am the client of a thread with them
$amWorker        = false; // I am the WORKER in this thread (they hired me)
$workerContract  = null;  // contract where I am the WORKER (they hired me)
$workerContractId = 0;
$clientContract  = null;  // contract where I am the CLIENT (I hired them)

if ($chatWith > 0 && $chatWith !== $myId) {
    $stmt = $pdo->prepare('SELECT id, full_name, profile_picture FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $chatWith]);
    $otherUser = $stmt->fetch();

    // Resolve the conversation row(s) for this pair. A pair can have
    // a thread in EITHER direction (each person may have inquired on
    // the other's profile), and each direction carries its own
    // message-request status. The thread view merges both, but the
    // request gate is per-direction:
    //   $myRequestStatus    — status of MY request to them (I am the
    //                          client, they are the provider)
    //   $theirRequestStatus — status of THEIR request to me (I am the
    //                          provider, they are the client)
    $myRequestStatus    = null;
    $theirRequestStatus = null;
    $stmt = $pdo->prepare(
        'SELECT id, provider_id, client_id, status FROM conversations
         WHERE (provider_id = :me AND client_id = :other)
            OR (provider_id = :other AND client_id = :me)
         ORDER BY id LIMIT 2'
    );
    $stmt->execute([':me' => $myId, ':other' => $chatWith]);
    foreach ($stmt->fetchAll() as $cr) {
        $convIdForForm = (int) $cr['id'];
        if ((int) $cr['provider_id'] === $chatWith) {
            // They are the provider -> this is MY request to them.
            $myRequestStatus = $cr['status'];
        } else {
            // I am the provider -> this is THEIR request to me.
            $theirRequestStatus = $cr['status'];
        }
    }

    // The thread is OPEN (they can talk) when at least one direction
    // is accepted — or when no conversation row exists at all (a
    // direct "New Message" chat). A pending request on BOTH sides
    // still locks the thread until one side accepts.
    $requestOpen = ($myRequestStatus === 'accepted')
        || ($theirRequestStatus === 'accepted')
        || ($myRequestStatus === null && $theirRequestStatus === null);

    if ($otherUser) {
        // Is the other user an INDIVIDUAL SKILLS provider? Only then
        // can a client send them a formal HIRE request. The hire flow
        // (HIRE! -> ACCEPT/DECLINE -> JOB DONE -> rating) exists ONLY
        // for hiring a person's skill — BUSINESS listings (resorts,
        // motor rentals) are inquired about and chatted with, but are
        // never "hired" as a worker. So this count filters by
        // profile_type = 'individual'.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM providers
             WHERE user_id = :uid AND profile_type = 'individual'"
        );
        $stmt->execute([':uid' => $chatWith]);
        $otherIsProvider = (int) $stmt->fetchColumn() > 0;

        // Did I inquire on them? I am the client of a thread with
        // this person when a conversation has them as the provider
        // and me as the client — that is the account who asked about
        // availability, and it gets the HIRE! button — but ONLY once
        // my message request has been accepted (no hiring through a
        // request the provider hasn't opened yet).
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM conversations
             WHERE provider_id = :other AND client_id = :me AND status = \'accepted\''
        );
        $stmt->execute([':other' => $chatWith, ':me' => $myId]);
        $canHire = $otherIsProvider
            && (int) $stmt->fetchColumn() > 0
            && $myRequestStatus === 'accepted';

        // Contract where I am the WORKER (they sent ME a hire
        // request) — this drives the Accept/Decline controls. With
        // sequential contracts allowed (re-hire support), the LATEST
        // row wins: an older completed/cancelled contract must never
        // shadow a fresh pending hire.
        $stmt = $pdo->prepare(
            'SELECT id, status, is_rated FROM service_contracts
             WHERE provider_id = :me AND client_id = :other
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':me' => $myId, ':other' => $chatWith]);
        $workerContract = $stmt->fetch();
        if ($workerContract) {
            $workerContractId = (int) $workerContract['id'];
            // The one being hired never gets a HIRE! button — they
            // answer with ACCEPT / DECLINE instead. The HIRE! button
            // belongs strictly to the account seeking the service.
            // Only REAL hire states count here: a legacy 'pending'
            // row (from a plain inquiry) is not a hire, so it must
            // not strip the client-side HIRE button.
            $amWorker = in_array($workerContract['status'], ['pending_hire', 'accepted', 'declined'], true);
        }

        // Contract where I am the CLIENT (I hired them) — this
        // drives the HIRE! button state (pending / hired / hire
        // again). The LATEST contract decides: after a completed
        // job, the HIRE! button re-enables and the next hire opens
        // a new sequential contract for this same pair.
        $stmt = $pdo->prepare(
            'SELECT id, status, is_rated FROM service_contracts
             WHERE provider_id = :other AND client_id = :me
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':other' => $chatWith, ':me' => $myId]);
        $clientContract = $stmt->fetch();

        // Thread state signature — mirrors messenger_poll.php exactly.
        // It captures every server-rendered UI dependency (request
        // status in both directions + contract status in both
        // directions). The live poller compares its current value to
        // this one: equal => only new bubbles to append (no reload);
        // different => a whole button block changed (HIRE!, ACCEPT/
        // DECLINE, JOB DONE, the request gate) so the page reloads
        // once to re-render it.
        $threadStateSig = implode('|', [
            (string) $myRequestStatus,
            (string) $theirRequestStatus,
            (string) ($workerContract ? $workerContract['status'] : ''),
            (string) ($clientContract ? $clientContract['status'] : ''),
        ]);

        // Mark every incoming message from this person as read
        // (this is what clears the unread badge on the list).
        $stmt = $pdo->prepare(
            'UPDATE messages SET is_read = 1
             WHERE recipient_id = :me AND sender_id = :other AND is_read = 0'
        );
        $stmt->execute([':me' => $myId, ':other' => $chatWith]);

        // Load the most recent 200 messages of the thread (newest
        // first, then reversed so they display oldest -> newest).
        // Messages I soft-deleted are hidden from MY thread view —
        // the other person still sees them (their copy is intact).
        // Three flags are honoured, all checked against MY id:
        //   deleted_by  = whole-conversation delete, slot 1
        //   deleted_by2 = whole-conversation delete, slot 2 (the
        //                 two slots make deletion independent per
        //                 user — each side hides their own copy
        //                 without overwriting the other's)
        //   msg_deleted_by = single-message delete (long-press)
        $stmt = $pdo->prepare(
            'SELECT * FROM messages
             WHERE ((sender_id = :me AND recipient_id = :other)
                 OR (sender_id = :other AND recipient_id = :me))
               AND (deleted_by IS NULL OR deleted_by <> :me)
               AND (deleted_by2 IS NULL OR deleted_by2 <> :me)
               AND (msg_deleted_by IS NULL OR msg_deleted_by <> :me)
             ORDER BY id DESC LIMIT 200'
        );
        $stmt->execute([':me' => $myId, ':other' => $chatWith]);
        $messages = array_reverse($stmt->fetchAll());
    }
}

// --- 9. Build the conversation list -----------------------------
// One entry per person we've exchanged messages with, showing the
// latest message and the unread count for that sender.
// The preview intentionally skips per-message-deleted rows (the
// newest message I still see drives the preview text), while the
// whole-conversation deleted_by flag still hides the thread from
// my inbox entirely.
$convStmt = $pdo->prepare(
    'SELECT c.other_id, u.full_name, u.profile_picture,
            m.message AS last_message, m.created_at AS last_time, m.sender_id AS last_sender
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
$convStmt->execute([':me' => $myId, ':me2' => $myId]);
$conversations = $convStmt->fetchAll();

// Unread counts per sender (badge on each conversation).
// Messages I soft-deleted are ignored here too, so a hidden
// thread never leaves an orphan unread badge behind.
$unread = [];
$rows = $pdo->prepare('SELECT sender_id, COUNT(*) AS n FROM messages WHERE recipient_id = :me AND is_read = 0 AND (deleted_by IS NULL OR deleted_by <> :me) AND (deleted_by2 IS NULL OR deleted_by2 <> :me) AND (msg_deleted_by IS NULL OR msg_deleted_by <> :me) GROUP BY sender_id');
$rows->execute([':me' => $myId]);
foreach ($rows->fetchAll() as $r) {
    $unread[(int) $r['sender_id']] = (int) $r['n'];
}

// HIRE/job tags for the conversation list: every active job state
// (hire request, on the job, done, cancelled) gets a small label
// next to the conversation with that person, so the job status is
// spotted in the inbox without opening every thread.
$hireTags = [];
$stmt = $pdo->query(
    "SELECT provider_id, client_id, status FROM service_contracts
     WHERE status IN ('pending_hire','accepted','completed','cancelled')"
);
foreach ($stmt->fetchAll() as $row) {
    $amProvider = (int) $row['provider_id'] === $myId;
    $other      = $amProvider ? (int) $row['client_id'] : (int) $row['provider_id'];
    $status     = $row['status'];
    if ($amProvider) {
        // Worker's view of their own job.
        $map = [
            'pending_hire' => ['label' => 'Hire request', 'cls' => 'hire'],
            'accepted'     => ['label' => 'On the Job',   'cls' => 'onthejob'],
            'completed'    => ['label' => 'Job done',     'cls' => 'done'],
            'cancelled'    => ['label' => 'Cancelled',    'cls' => 'cancel'],
        ];
    } else {
        // Client's view of the worker they hired.
        $map = [
            'pending_hire' => ['label' => 'Hire pending', 'cls' => 'hire'],
            'accepted'     => ['label' => 'Hired',        'cls' => 'onthejob'],
            'completed'    => ['label' => 'Job done',     'cls' => 'done'],
            'cancelled'    => ['label' => 'Cancelled',    'cls' => 'cancel'],
        ];
    }
    $hireTags[$other] = $map[$status];
}

// Message-request tags for the conversation list: any thread that
// is still a pending (or declined) message request gets a small
// label, so both sides spot requests that need attention without
// opening every thread. Accepted threads need no tag (they are
// normal open chats).
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

// --- 10. "New message" picker: every other user ------------------
$allUsers = [];
if ($showNew) {
    $stmt = $pdo->prepare(
        'SELECT id, full_name, profile_picture FROM users WHERE id != :me ORDER BY full_name'
    );
    $stmt->execute([':me' => $myId]);
    $allUsers = $stmt->fetchAll();
}

// --- 11. Values for the header -----------------------------------
$headerTitle = 'Messenger';
$headerBack  = 'dashboard.php';   // list view: back to the dashboard
$headerBackLabel = 'Dashboard';
if ($otherUser) {
    $headerTitle = htmlspecialchars($otherUser['full_name']);
    $headerBack  = 'messenger.php'; // thread view: back to the list
    $headerBackLabel = 'Messages';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php
// Shared metadata (title, description, favicon, manifest, social
// preview) + style.css and busy.js live in one partial so every
// page ships the same head.
$headTitle = 'Messenger';
$headDesc  = 'Chat with islaFIND clients and providers about a job or a booking.';
include __DIR__ . '/../include/head_meta.php';
?>
</head>
<body class="chat-body">

    <!-- ============ Green header with back arrow ============ -->
    <header class="app-header">
        <a href="<?php echo $headerBack; ?>" class="chat-back" aria-label="<?php echo htmlspecialchars($headerBackLabel); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
        <span class="app-header-title"><?php echo $headerTitle; ?></span>

        <?php if (!$otherUser && $conversations): ?>
            <!-- Top-right settings gear (inbox view only). Toggles
                 the multi-select mode that lets the user pick one
                 or more conversations and delete them together. -->
            <button type="button" id="msgGear" class="msg-gear" aria-label="Messenger settings" aria-pressed="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            </button>
        <?php endif; ?>

        <?php if ($otherUser && $canHire && !$amWorker): ?>
            <!-- Top-right HIRE! action — shown ONLY to the account
                 seeking the service (the one who inquired). The one
                 being hired never sees it: they answer with the
                 ACCEPT / DECLINE controls in the thread instead. -->
            <?php $clientStatus = $clientContract['status'] ?? null; ?>
            <?php if ($clientStatus === 'accepted'): ?>
                <span class="chat-hire chat-hire-done">Hired &#10003;</span>
            <?php elseif ($clientStatus === 'pending_hire'): ?>
                <span class="chat-hire chat-hire-pending">Hire Pending&#8230;</span>
            <?php else: ?>
                <form action="hire_action.php" method="POST" class="chat-hire-form"
                      onsubmit="return confirm(<?php echo js_string('Send a formal hire request to ' . $otherUser['full_name'] . '?'); ?>);">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="hire">
                    <input type="hidden" name="recipient_id" value="<?php echo (int) $chatWith; ?>">
                    <button type="submit" class="chat-hire-btn" data-loading-label="Sending…">HIRE!</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </header>

    <main class="chat-main">

        <?php if ($otherUser): ?>
            <!-- ============ CHAT THREAD VIEW ============ -->

            <?php if ($chatFlash): ?>
                <!-- One-shot flash (hire sent / accepted / declined) -->
                <div class="alert alert-<?php echo $chatFlash['type'] === 'error' ? 'error' : ($chatFlash['type'] === 'info' ? 'info' : 'success'); ?>"
                     role="<?php echo $chatFlash['type'] === 'error' ? 'alert' : 'status'; ?>">
                    <?php echo htmlspecialchars($chatFlash['msg']); ?>
                </div>
            <?php endif; ?>

            <?php
                // Newest rendered message id (messages are oldest->newest,
                // so the LAST element is the newest). Seeded to the poller
                // so it never re-fetches what is already on screen.
                $threadMaxMsgId = 0;
                if ($messages) {
                    $lastMsg = $messages[count($messages) - 1];
                    $threadMaxMsgId = (int) $lastMsg['id'];
                }
            ?>
            <div class="chat-thread" id="chatThread"
                 data-signature="<?php echo htmlspecialchars($threadStateSig); ?>"
                 data-chat-with="<?php echo (int) $chatWith; ?>"
                 data-me="<?php echo (int) $myId; ?>"
                 data-max-id="<?php echo (int) $threadMaxMsgId; ?>">

                <?php if (!$requestOpen): ?>
                    <!-- ======== MESSAGE REQUEST GATE ========
                         A new "Inquire Availability" is a PENDING
                         message request, not an open chat. Until the
                         provider accepts it, the thread shows the
                         request state and NO input bar, so neither
                         side can talk yet.
                         - Provider side: Accept / Decline buttons.
                         - Client side: "waiting for acceptance". -->
                    <?php if ($theirRequestStatus === 'pending'): ?>
                        <!-- I am the PROVIDER: they asked to talk. -->
                        <div class="msg-request">
                            <div class="msg-request-icon" aria-hidden="true">&#128172;</div>
                            <div class="msg-request-body">
                                <strong><?php echo htmlspecialchars($otherUser['full_name']); ?> sent you a message request</strong>
                                <p>They want to talk about your service. Accept to open the chat, or decline.</p>
                                <div class="msg-request-actions">
                                    <form action="hire_action.php" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="action" value="accept_request">
                                        <input type="hidden" name="client_id" value="<?php echo (int) $otherUser['id']; ?>">
                                        <button type="submit" class="btn btn-small btn-accept">Accept</button>
                                    </form>
                                    <form action="hire_action.php" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="action" value="decline_request">
                                        <input type="hidden" name="client_id" value="<?php echo (int) $otherUser['id']; ?>">
                                        <button type="submit" class="btn btn-small btn-decline">Decline</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($myRequestStatus === 'pending'): ?>
                        <!-- I am the CLIENT: waiting for acceptance. -->
                        <div class="msg-request">
                            <div class="msg-request-icon" aria-hidden="true">&#128197;</div>
                            <div class="msg-request-body">
                                <strong>Message request sent</strong>
                                <p>Waiting for <?php echo htmlspecialchars($otherUser['full_name']); ?> to accept your message request. You can talk about the job once they accept.</p>
                            </div>
                        </div>
                    <?php elseif ($myRequestStatus === 'declined' || $theirRequestStatus === 'declined'): ?>
                        <div class="msg-request">
                            <div class="msg-request-icon" aria-hidden="true">&#10060;</div>
                            <div class="msg-request-body">
                                <strong>Message request declined</strong>
                                <p>This conversation is closed. You can send a new message request from the profile.</p>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- ======== OPEN CHAT ======== -->

                    <?php if ($theirRequestStatus === 'pending'): ?>
                        <!-- Reverse-direction message request: the thread
                             is already open (I accepted THEIR request
                             earlier), but they have now sent a NEW request
                             the other way — e.g. Claire inquiring Dave's
                             profile. Show a compact Accept/Decline banner
                             so THIS direction can open too, without
                             locking the existing chat. -->
                        <div class="hire-decision">
                            <strong>&#128172; <?php echo htmlspecialchars($otherUser['full_name']); ?> sent you a message request</strong>
                            <div class="hire-decision-actions">
                                <form action="hire_action.php" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="action" value="accept_request">
                                    <input type="hidden" name="client_id" value="<?php echo (int) $otherUser['id']; ?>">
                                    <button type="submit" class="btn btn-small btn-accept">Accept</button>
                                </form>
                                <form action="hire_action.php" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="action" value="decline_request">
                                    <input type="hidden" name="client_id" value="<?php echo (int) $otherUser['id']; ?>">
                                    <button type="submit" class="btn btn-small btn-decline">Decline</button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($freshConv): ?>
                        <!-- Banner shown right after an "Inquire Availability"
                             created this thread (the inquiry message is below). -->
                        <p class="chat-banner">You sent an inquiry to <?php echo htmlspecialchars($otherUser['full_name']); ?> — wait for their reply here.</p>
                    <?php endif; ?>

                    <?php if (!$messages): ?>
                        <p class="chat-empty">Say hi to <?php echo htmlspecialchars($otherUser['full_name']); ?> — start the conversation below.</p>
                    <?php endif; ?>
                    <?php
                    // With sequential re-hires the thread can hold several
                    // "X sent you a hire request" system notices (one per
                    // past hire cycle). Only the LATEST one belongs to the
                    // current pending contract, so it is the only one that
                    // becomes a clickable Accept/Decline button — the older
                    // ones (already accepted / declined / completed) stay
                    // as plain notices. Find its message id up front so the
                    // loop below can tell them apart.
                    $workerStatus = $workerContract['status'] ?? null;
                    $latestHireReqMsgId = null;
                    if ($workerStatus === 'pending_hire') {
                        foreach ($messages as $m) {
                            if (($m['kind'] ?? 'chat') === 'system'
                                && stripos((string) $m['message'], 'sent you a hire request') !== false) {
                                $latestHireReqMsgId = (int) $m['id'];
                            }
                        }
                    }
                    ?>
                    <?php foreach ($messages as $msg): ?>
                        <?php if (($msg['kind'] ?? 'chat') === 'system'): ?>
                            <?php
                            // A HIRE-REQUEST system notice (the worker
                            // side of "X sent you a hire request") gets
                            // a distinct visual treatment so the worker
                            // immediately spots that a new hire needs
                            // their ACCEPT / DECLINE decision — the
                            // other system notices keep the plain look.
                            // While the hire is still pending, the
                            // notice becomes a clickable button that
                            // opens the Accept/Decline modal, so the
                            // worker never has to scroll to the top of
                            // the thread to decide.
                            $isHireReq = stripos((string) $msg['message'], 'sent you a hire request') !== false;
                            // Clickable ONLY while a hire is pending AND this
                            // is the latest (current) hire-request notice —
                            // older requests from past cycles stay plain.
                            $hireOpen = $isHireReq
                                && $workerStatus === 'pending_hire'
                                && (int) $msg['id'] === $latestHireReqMsgId;
                            ?>
                            <?php if ($hireOpen): ?>
                                <!-- Clickable hire-request notice: opens
                                     the Accept/Decline modal for this
                                     pending hire. data-contract carries
                                     the contract id into the modal. -->
                                <button type="button" class="chat-system chat-system-hire chat-system-hire-btn"
                                        data-hire-contract="<?php echo (int) $workerContractId; ?>"
                                        data-hire-name="<?php echo htmlspecialchars($otherUser['full_name']); ?>"
                                        id="hireRequestCta">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                                    <span><?php echo htmlspecialchars($msg['message']); ?></span>
                                    <svg class="chat-system-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                                </button>
                            <?php else: ?>
                                <!-- Formal system notice (hire sent / answered) -->
                                <div class="chat-system<?php echo $isHireReq ? ' chat-system-hire' : ''; ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                                    <?php echo htmlspecialchars($msg['message']); ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- Regular chat bubble. data-msg-id lets
                                 the long-press delete script target
                                 exactly this message; data-mine marks
                                 my own bubbles for styling. -->
                            <div class="chat-bubble <?php echo (int) $msg['sender_id'] === $myId ? 'mine' : 'theirs'; ?>"
                                 data-msg-id="<?php echo (int) $msg['id']; ?>"
                                 data-mine="<?php echo (int) $msg['sender_id'] === $myId ? '1' : '0'; ?>">
                                <?php echo htmlspecialchars($msg['message']); ?>
                                <span class="chat-bubble-time"><?php echo htmlspecialchars(friendlyTime($msg['created_at'])); ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($workerStatus === 'accepted'): ?>
                        <p class="chat-banner chat-banner-onthejob">&#128338; Job accepted — you are On the Job. Wait for the client to finish the job.</p>
                    <?php elseif ($workerStatus === 'declined'): ?>
                        <p class="chat-banner">You declined this hire request.</p>
                    <?php elseif ($workerStatus === 'cancelled'): ?>
                        <p class="chat-banner">This job was cancelled by the client.</p>
                    <?php elseif ($workerStatus === 'completed'): ?>
                        <p class="chat-banner chat-banner-onthejob">&#10003; Job completed<?php echo (int) ($workerContract['is_rated'] ?? 0) === 1 ? ' and rated' : ''; ?>.</p>
                    <?php elseif ($clientContract && $clientContract['status'] === 'pending_hire'): ?>
                        <p class="chat-banner">Hire request sent — waiting for <?php echo htmlspecialchars($otherUser['full_name']); ?> to accept or decline.</p>
                    <?php elseif ($clientContract && $clientContract['status'] === 'accepted'): ?>
                        <!-- Client view of an ACTIVE job: the seeker can
                             finish it (JOB DONE -> opens the rating modal)
                             or cancel it. Both only apply while the job is
                             accepted — the worker's acceptance is what
                             unlocks these controls. -->
                        <div class="chat-banner chat-banner-onthejob">
                            <span>&#10003; <?php echo htmlspecialchars($otherUser['full_name']); ?> accepted your hire — they are On the Job.</span>
                            <div class="chat-job-actions">
                                <button type="button" class="btn btn-small btn-job-done" id="jobDoneBtn"
                                        data-contract="<?php echo (int) $clientContract['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($otherUser['full_name']); ?>">&#10003; JOB DONE</button>
                                <form action="hire_action.php" method="POST"
                                      onsubmit="return confirm(<?php echo js_string('Cancel this job? This ends the agreement with ' . $otherUser['full_name'] . '.'); ?>);">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="contract_id" value="<?php echo (int) $clientContract['id']; ?>">
                                    <button type="submit" class="btn btn-small btn-job-cancel">&#10007; CANCEL</button>
                                </form>
                            </div>
                        </div>
                    <?php elseif ($clientContract && $clientContract['status'] === 'cancelled'): ?>
                        <p class="chat-banner">You cancelled this job.</p>
                    <?php elseif ($clientContract && $clientContract['status'] === 'completed'): ?>
                        <p class="chat-banner chat-banner-onthejob">&#10003; Job completed<?php echo (int) ($clientContract['is_rated'] ?? 0) === 1 ? ' — you rated this service' : ''; ?>.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($requestOpen): ?>
                <!-- Long-press action sheet: appears when the user
                     holds a chat bubble. Offers deleting that single
                     message from their own view. -->
                <div class="msg-action-sheet" id="msgActionSheet" hidden>
                    <span class="msg-action-sheet-title" id="msgActionTitle"></span>
                    <button type="button" class="msg-action-sheet-delete" id="msgActionDelete">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                        Delete message
                    </button>
                    <button type="button" class="msg-action-sheet-cancel" id="msgActionCancel">Cancel</button>
                </div>

                <!-- Delete-message confirmation modal (mirrors the
                     conversation-delete modal pattern). -->
                <div class="modal" id="msgDeleteModal" hidden>
                    <div class="modal-backdrop" data-close></div>
                    <div class="modal-card">
                        <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
                        <h3>Delete message?</h3>
                        <p>This removes the message from <strong>your</strong> view only. The other person can still see it.</p>
                        <form action="delete_message.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="message_id" id="msgDeleteId" value="">
                            <div class="modal-actions">
                                <button type="button" class="btn btn-small btn-outline" data-close>Cancel</button>
                                <button type="submit" class="btn btn-small btn-danger">Delete</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Sticky input bar (hidden while the message request
                     is still pending — they can only talk after the
                     provider accepts it). -->
                <form action="messenger.php" method="POST" class="chat-input" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="recipient_id" value="<?php echo (int) $otherUser['id']; ?>">
                    <?php if (!empty($convIdForForm)): ?>
                        <input type="hidden" name="conversation_id" value="<?php echo (int) $convIdForForm; ?>">
                    <?php endif; ?>
                    <input type="text" name="message" placeholder="Message…" aria-label="Message" maxlength="2000" required>
                    <button type="submit" class="chat-send" aria-label="Send">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                </form>
            <?php endif; ?>

            <!-- ============ Rate modal (JOB DONE) ============
                 Opens when the client taps JOB DONE on an accepted
                 job. Submits to rate_service.php, which completes
                 the contract and saves the 1-5 star review. -->
            <div class="modal" id="rateModal" hidden>
                <div class="modal-backdrop" data-close></div>
                <div class="modal-card">
                    <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
                    <h4>Rate this service</h4>
                    <p class="sec-hint" id="rateTarget"></p>
                    <form action="rate_service.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="contract_id" id="rateContractId" value="">
                        <input type="hidden" name="return_chat" value="<?php echo (int) $chatWith; ?>">
                        <div class="star-picker" id="starPicker">
                            <input type="hidden" name="rating" id="ratingValue" value="5">
                            <button type="button" class="star" data-v="1" aria-label="1 star">★</button>
                            <button type="button" class="star" data-v="2" aria-label="2 stars">★</button>
                            <button type="button" class="star" data-v="3" aria-label="3 stars">★</button>
                            <button type="button" class="star" data-v="4" aria-label="4 stars">★</button>
                            <button type="button" class="star" data-v="5" aria-label="5 stars">★</button>
                        </div>
                        <div class="form-group">
                            <textarea name="comment" rows="3" maxlength="500"
                                      placeholder="Optional: how was the service?"
                                      aria-label="Review comment"></textarea>
                        </div>
                        <button type="submit" class="btn" data-loading-label="Submitting…">Submit Rating</button>
                    </form>
                </div>
            </div>

            <!-- ============ Hire decision modal ============
                 Opens when the worker taps the clickable
                 "X sent you a hire request" notice in the thread.
                 It carries the same Accept / Decline actions that
                 used to sit in a banner at the top of the chat —
                 now the decision happens right where the worker
                 is reading, with no scrolling. The contract id and
                 the requester's name are filled from the clicked
                 notice's data attributes. -->
            <div class="modal" id="hireModal" hidden>
                <div class="modal-backdrop" data-close></div>
                <div class="modal-card">
                    <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
                    <h4>&#128203; Hire request</h4>
                    <p class="sec-hint" id="hireModalText"></p>
                    <div class="modal-actions">
                        <form action="hire_action.php" method="POST" class="modal-action-form">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="action" value="decline">
                            <input type="hidden" name="contract_id" id="hireModalDeclineId" value="">
                            <button type="submit" class="btn btn-small btn-decline">Decline</button>
                        </form>
                        <form action="hire_action.php" method="POST" class="modal-action-form">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="action" value="accept">
                            <input type="hidden" name="contract_id" id="hireModalAcceptId" value="">
                            <button type="submit" class="btn btn-small btn-accept">Accept</button>
                        </form>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- ============ CONVERSATION LIST VIEW ============ -->

            <!-- New Message / refresh actions -->
            <div class="chat-list-toolbar">
                <a href="messenger.php?new=1" class="btn btn-small <?php echo $showNew ? 'btn-outline' : ''; ?>">New Message</a>
            </div>

            <?php if ($chatFlash): ?>
                <!-- One-shot flash (conversations deleted) -->
                <div class="alert alert-<?php echo $chatFlash['type'] === 'error' ? 'error' : ($chatFlash['type'] === 'info' ? 'info' : 'success'); ?>"
                     role="<?php echo $chatFlash['type'] === 'error' ? 'alert' : 'status'; ?>">
                    <?php echo htmlspecialchars($chatFlash['msg']); ?>
                </div>
            <?php endif; ?>

            <?php if ($showNew): ?>
                <!-- User picker to start a new chat -->
                <h4 class="sec-section">Choose a person</h4>
                <?php if (!$allUsers): ?>
                    <p class="sec-hint">No other users yet — register a second account to start chatting.</p>
                <?php else: ?>
                    <ul class="chat-list">
                        <?php foreach ($allUsers as $u): ?>
                            <li>
                                <a class="chat-item" href="messenger.php?chat=<?php echo (int) $u['id']; ?>">
                                    <span class="chat-avatar"><?php echo htmlspecialchars(initialsOf($u['full_name'])); ?></span>
                                    <span class="chat-meta">
                                        <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>
                                        <span class="chat-preview">Start a conversation</span>
                                    </span>
                                    <span class="chat-chevron">&#8250;</span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php elseif (!$conversations): ?>
                <!-- Empty inbox -->
                <div class="chat-empty-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <p><strong>No messages yet</strong></p>
                    <p>Tap "New Message" to start chatting with someone.</p>
                </div>
            <?php else: ?>
                <!-- Conversation list -->
                <ul class="chat-list" id="convList">
                    <?php foreach ($conversations as $conv): ?>
                        <?php $otherId = (int) $conv['other_id']; ?>
                        <li class="conv-item">
                            <!-- Select checkbox — only visible while
                                 the settings gear put the list into
                                 multi-select mode. -->
                            <label class="conv-check">
                                <input type="checkbox" class="conv-select" value="<?php echo $otherId; ?>" aria-label="Select conversation with <?php echo htmlspecialchars($conv['full_name']); ?>">
                                <span class="conv-checkmark"></span>
                            </label>
                            <a class="chat-item" href="messenger.php?chat=<?php echo $otherId; ?>">
                                <span class="chat-avatar"><?php echo htmlspecialchars(initialsOf($conv['full_name'])); ?></span>
                                <span class="chat-meta">
                                    <strong><?php echo htmlspecialchars($conv['full_name']); ?></strong>
                                    <span class="chat-preview">
                                        <?php if ((int) $conv['last_sender'] === $myId): ?>You: <?php endif; ?>
                                        <?php echo htmlspecialchars($conv['last_message']); ?>
                                    </span>
                                    <?php if (!empty($reqTags[$otherId])): ?>
                                        <span class="chat-tag <?php echo $reqTags[$otherId]['cls']; ?>">
                                            <?php echo htmlspecialchars($reqTags[$otherId]['label']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($hireTags[$otherId])): ?>
                                        <span class="chat-tag <?php echo $hireTags[$otherId]['cls']; ?>">
                                            <?php echo htmlspecialchars($hireTags[$otherId]['label']); ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                                <span class="chat-side">
                                    <span class="chat-time"><?php echo htmlspecialchars(friendlyTime($conv['last_time'])); ?></span>
                                    <?php if (!empty($unread[$otherId])): ?>
                                        <span class="chat-unread"><?php echo $unread[$otherId]; ?></span>
                                    <?php endif; ?>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <!-- Floating delete action bar — revealed by the JS
                     when at least one conversation is checked. -->
                <div class="conv-actionbar" id="convActionBar" hidden>
                    <span class="conv-actionbar-count" id="convSelCount">0 selected</span>
                    <button type="button" class="btn btn-small btn-danger" id="convDeleteBtn">Delete</button>
                    <button type="button" class="btn btn-small btn-outline" id="convCancelBtn">Cancel</button>
                </div>

                <!-- Delete confirmation modal -->
                <div class="modal" id="convDeleteModal" hidden>
                    <div class="modal-backdrop" data-close></div>
                    <div class="modal-card">
                        <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
                        <h3>Delete conversations?</h3>
                        <p id="convDeleteText">This hides the selected conversation(s) from your inbox. The other person can still see the thread.</p>
                        <form action="delete_conversations.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <div id="convDeleteIds"></div>
                            <div class="modal-actions">
                                <button type="button" class="btn btn-small btn-outline" data-close>Cancel</button>
                                <button type="submit" class="btn btn-small btn-danger">Delete</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </main>

    <script>
        // Scroll the chat thread to the newest message on load.
        const thread = document.getElementById('chatThread');
        if (thread) {
            thread.scrollTop = thread.scrollHeight;
        }

        // ---- JOB DONE -> rating modal ------------------------------
        // The client's "JOB DONE" button (only rendered on an
        // accepted job) opens the star-rating modal pre-filled with
        // the contract id. Star taps paint the picker and store the
        // chosen value in the hidden field before submitting.
        const jobDoneBtn = document.getElementById('jobDoneBtn');
        const rateModal  = document.getElementById('rateModal');
        if (jobDoneBtn && rateModal) {
            jobDoneBtn.addEventListener('click', function () {
                document.getElementById('rateContractId').value = jobDoneBtn.dataset.contract;
                document.getElementById('rateTarget').textContent =
                    'Rate ' + jobDoneBtn.dataset.name + ' (completed job)';
                rateModal.hidden = false;
                document.body.classList.add('no-scroll');
            });

            // Star picker: tap a star to choose 1-5; the row fills
            // gold up to that star (same behavior as the dashboard).
            const starButtons = document.querySelectorAll('#starPicker .star');
            function paintStars(value) {
                starButtons.forEach(function (s) {
                    s.classList.toggle('on', parseInt(s.dataset.v, 10) <= value);
                });
            }
            starButtons.forEach(function (s) {
                s.addEventListener('click', function () {
                    document.getElementById('ratingValue').value = s.dataset.v;
                    paintStars(parseInt(s.dataset.v, 10));
                });
            });
            paintStars(5);   // default selection

            // Close via backdrop / X / Escape.
            rateModal.querySelectorAll('[data-close]').forEach(function (el) {
                el.addEventListener('click', function () {
                    rateModal.hidden = true;
                    document.body.classList.remove('no-scroll');
                });
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !rateModal.hidden) {
                    rateModal.hidden = true;
                    document.body.classList.remove('no-scroll');
                }
            });
        }

        // ---- Clickable hire request -> Accept/Decline modal --------
        // The "X sent you a hire request" system notice is a button
        // while the hire is pending. Tapping it opens this modal so
        // the worker can Accept or Decline without scrolling up to a
        // banner. The contract id and the requester's name come from
        // the clicked notice's data attributes. Only the LATEST hire
        // request renders as a button (older requests from past hire
        // cycles are plain notices), and the handler is wired with
        // querySelectorAll so repeated hires can never collide on a
        // single element id.
        const hireModal   = document.getElementById('hireModal');
        const hireCtas    = document.querySelectorAll('.chat-system-hire-btn');
        if (hireModal) {
            hireCtas.forEach(function (hireCta) {
                hireCta.addEventListener('click', function () {
                    document.getElementById('hireModalAcceptId').value  = hireCta.dataset.hireContract;
                    document.getElementById('hireModalDeclineId').value = hireCta.dataset.hireContract;
                    document.getElementById('hireModalText').textContent =
                        hireCta.dataset.hireName + ' wants to hire you. Accept the job or decline it?';
                    hireModal.hidden = false;
                    document.body.classList.add('no-scroll');
                });
            });

            // Close via backdrop / X / Escape (same convention as the
            // other modals on this page).
            hireModal.querySelectorAll('[data-close]').forEach(function (el) {
                el.addEventListener('click', function () {
                    hireModal.hidden = true;
                    document.body.classList.remove('no-scroll');
                });
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !hireModal.hidden) {
                    hireModal.hidden = true;
                    document.body.classList.remove('no-scroll');
                }
            });
        }
    </script>
    <script src="messenger.js"></script>
    <script src="message_delete.js"></script>
    <script src="messenger_live.js"></script>
</body>
</html>
