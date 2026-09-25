<?php
// ============================================================
// hire_action.php — HIRE workflow backend
// Handles the three contract actions of the hire flow:
//   hire    (client)   upsert a service contract for this
//                      provider-client pair as pending_hire and
//                      drop a formal system message in the chat
//   accept  (worker)   flip a pending_hire contract to accepted
//                      (puts the worker "On the Job")
//   decline (worker)   flip a pending_hire contract to declined
// Both sides also get a system message in the thread so the
// conversation records the decision. Everything is a POST with
// a CSRF token; ownership is re-checked server-side.
// ============================================================

// --- 1. Harden the session cookie, then start the session ------
require_once __DIR__ . '/../include/security.php';
session_harden();
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

// --- 5. Helper: flash message for the next messenger load ------
function chatFlash(string $type, string $msg): void
{
    $_SESSION['flash_chat'] = ['type' => $type, 'msg' => $msg];
}

// --- 6. Method + CSRF guard ------------------------------------
// A forged cross-site request cannot know the session token, so
// anything that is not a valid POST is bounced.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    chatFlash('error', 'Your session expired or the form token is invalid.');
header('Location: ' . sid_append('messenger.php'));
    exit;
}

$action = $_POST['action'] ?? '';

// ============================================================
// 7. ACTION: hire (client -> worker)
// ============================================================
if ($action === 'hire') {
    $providerId = (int) ($_POST['recipient_id'] ?? 0);

    // The recipient must exist AND own an INDIVIDUAL SKILLS
    // islaFIND profile — you can only HIRE a person's skill. The
    // formal hire flow (hire -> accept/decline -> job done -> rating)
    // exists ONLY for individual listings; BUSINESS listings (beach
    // resorts, motor rentals...) are inquired about and chatted with,
    // but are never hired as a worker. A crafted request trying to
    // hire a business profile is rejected right here.
    $stmt = $pdo->prepare(
        "SELECT u.id, u.full_name
         FROM users u
         JOIN providers p ON p.user_id = u.id AND p.profile_type = 'individual'
         WHERE u.id = :id LIMIT 1"
    );
    $stmt->execute([':id' => $providerId]);
    $provider = $stmt->fetch();

    if (!$provider) {
        chatFlash('error', 'That person has no Individual Skills profile to hire.');
    } elseif ($providerId === $myId) {
        chatFlash('error', 'You cannot hire yourself.');
    } else {
        // HIRING REQUIRES AN OPEN CHAT: the provider must have
        // accepted the message request first. A pending or declined
        // request cannot be hired through — the pair talks about the
        // job only after the request is accepted.
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM conversations
             WHERE (provider_id = :p AND client_id = :c AND status = \'accepted\')
                OR (provider_id = :c AND client_id = :p AND status = \'accepted\')'
        );
        $stmt->execute([':p' => $providerId, ':c' => $myId]);
        if ((int) $stmt->fetchColumn() === 0) {
            chatFlash('error', 'They have not accepted your message request yet — wait for them to open the chat first.');
        } else {
        // The messenger's HIRE! button knows the PROVIDER USER, not
        // which of their listings is being hired. A user can own many
        // islaFIND profiles, and only the exact listing the client
        // hired may be rated later. Resolve the listing id: prefer
        // the one tied to this pair's most recent contract (created
        // by the inquiry that opened this chat), falling back to the
        // provider's latest individual listing.
        $stmt = $pdo->prepare(
            'SELECT provider_listing_id FROM service_contracts
             WHERE provider_id = :p AND client_id = :c
               AND provider_listing_id IS NOT NULL
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':p' => $providerId, ':c' => $myId]);
        $listingId = (int) $stmt->fetchColumn();
        if ($listingId === 0) {
            $stmt = $pdo->prepare(
                "SELECT id FROM providers
                 WHERE user_id = :uid AND profile_type = 'individual'
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([':uid' => $providerId]);
            $listingId = (int) $stmt->fetchColumn();
        }

        // Record the hire request pinned to that specific listing.
        $stmt = $pdo->prepare(
            'INSERT INTO service_contracts (provider_id, provider_listing_id, client_id, status)
             VALUES (:p, :lid, :c, \'pending_hire\')'
        );
        $stmt->execute([':p' => $providerId, ':lid' => $listingId, ':c' => $myId]);

        // Ensure a conversation thread exists for the pair, so the
        // formal system message always lands in a thread (a client
        // might hire without a prior chat).
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO conversations (provider_id, client_id) VALUES (:p, :c)'
        );
        $stmt->execute([':p' => $providerId, ':c' => $myId]);

        // Formal system message in the chat so the worker sees the
        // request inside the thread.
        $stmt = $pdo->prepare(
            'INSERT INTO messages (conversation_id, sender_id, recipient_id, message, kind)
             SELECT id, :me, :other, :msg, \'system\'
             FROM conversations
             WHERE (provider_id = :me AND client_id = :other)
                OR (provider_id = :other AND client_id = :me)
             LIMIT 1'
        );
        $stmt->execute([
            ':me'    => $myId,
            ':other' => $providerId,
            ':msg'   => $user['full_name'] . ' sent you a hire request.',
        ]);

        // NOTE: no soft-delete flags are cleared here. Deletion is
        // independent per user — the hire system message is a NEW
        // row (visible to both), but history a user deleted stays
        // hidden from that user while the counterparty keeps their
        // full copy until they delete it too.

        chatFlash('success', 'Hire request sent to ' . $provider['full_name'] . '!');
        header('Location: ' . sid_append('messenger.php?chat=' . $providerId));
        exit;
        }
    }
}

// ============================================================
// 8. ACTION: accept / decline (worker decision)
// ============================================================
if ($action === 'accept' || $action === 'decline') {
    $contractId = (int) ($_POST['contract_id'] ?? 0);
    $newStatus  = $action === 'accept' ? 'accepted' : 'declined';

    // The contract must exist and belong to THIS user as the
    // worker (provider_id) — a client cannot accept their own hire.
    $stmt = $pdo->prepare(
        'SELECT sc.*, u.full_name AS client_name
         FROM service_contracts sc
         JOIN users u ON u.id = sc.client_id
         WHERE sc.id = :id AND sc.provider_id = :uid
         LIMIT 1'
    );
    $stmt->execute([':id' => $contractId, ':uid' => $myId]);
    $contract = $stmt->fetch();

    if (!$contract) {
        chatFlash('error', 'Hire request not found.');
    } elseif ($contract['status'] !== 'pending_hire') {
        chatFlash('error', 'This request was already answered.');
    } else {
        $stmt = $pdo->prepare('UPDATE service_contracts SET status = :s WHERE id = :id');
        $stmt->execute([':s' => $newStatus, ':id' => $contractId]);

        // Tell the client what happened, inside the same thread.
        $stmt = $pdo->prepare(
            'INSERT INTO messages (conversation_id, sender_id, recipient_id, message, kind)
             SELECT id, :me, :other, :msg, \'system\'
             FROM conversations
             WHERE (provider_id = :me AND client_id = :other)
                OR (provider_id = :other AND client_id = :me)
             LIMIT 1'
        );
        $stmt->execute([
            ':me'    => $myId,
            ':other' => (int) $contract['client_id'],
            ':msg'   => $newStatus === 'accepted'
                ? $user['full_name'] . ' accepted your hire request — you are hired!'
                : $user['full_name'] . ' declined your hire request.',
        ]);

        chatFlash(
            $newStatus === 'accepted' ? 'success' : 'info',
            $newStatus === 'accepted'
                ? 'Job accepted — you are now On the Job.'
                : 'Hire request declined.'
        );
        header('Location: ' . sid_append('messenger.php?chat=' . (int) $contract['client_id']));
        exit;
    }
}

// ============================================================
// 9. ACTION: accept_request / decline_request (provider opens
//    or rejects a MESSAGE REQUEST)
// A new "Inquire Availability" creates a pending message
// request — the pair can only chat once the provider accepts
// it. These actions flip the conversation status for the pair
// (provider = me, client = the inquirer) and drop a system
// message so both sides see the decision in the thread.
// ============================================================
if ($action === 'accept_request' || $action === 'decline_request') {
    $clientId = (int) ($_POST['client_id'] ?? 0);

    // The target conversation must exist with THIS user as the
    // provider — only the provider being asked can accept or
    // decline a message request.
    $stmt = $pdo->prepare(
        'SELECT id, status FROM conversations
         WHERE provider_id = :me AND client_id = :other LIMIT 1'
    );
    $stmt->execute([':me' => $myId, ':other' => $clientId]);
    $conv = $stmt->fetch();

    if (!$conv) {
        chatFlash('error', 'Message request not found.');
    } elseif ($conv['status'] !== 'pending') {
        chatFlash('error', 'This message request was already answered.');
    } else {
        $newStatus = $action === 'accept_request' ? 'accepted' : 'declined';
        $stmt = $pdo->prepare('UPDATE conversations SET status = :s WHERE id = :id');
        $stmt->execute([':s' => $newStatus, ':id' => (int) $conv['id']]);

        // Tell the client inside the same thread.
        $stmt = $pdo->prepare(
            'INSERT INTO messages (conversation_id, sender_id, recipient_id, message, kind)
             SELECT id, :me, :other, :msg, \'system\'
             FROM conversations
             WHERE (provider_id = :me AND client_id = :other)
                OR (provider_id = :other AND client_id = :me)
             LIMIT 1'
        );
        $stmt->execute([
            ':me'    => $myId,
            ':other' => $clientId,
            ':msg'   => $newStatus === 'accepted'
                ? $user['full_name'] . ' accepted your message request — you can now talk about the job.'
                : $user['full_name'] . ' declined your message request.',
        ]);

        chatFlash(
            $newStatus === 'accepted' ? 'success' : 'info',
            $newStatus === 'accepted'
                ? 'Message request accepted — the chat is now open.'
                : 'Message request declined.'
        );
        header('Location: ' . sid_append('messenger.php?chat=' . $clientId));
        exit;
    }
}

// ============================================================
// 10. ACTION: cancel (client cancels an accepted job)
// The seeker may cancel while the job is still 'accepted' (the
// worker accepted the hire but the work has not been marked
// done yet). Cancelling flips the contract to 'cancelled' and
// tells the worker inside the same thread.
// ============================================================
if ($action === 'cancel') {
    $contractId = (int) ($_POST['contract_id'] ?? 0);

    // The contract must exist and belong to THIS user as the
    // client — only the seeker can cancel their own hire.
    $stmt = $pdo->prepare(
        'SELECT sc.*, u.full_name AS provider_name
         FROM service_contracts sc
         JOIN users u ON u.id = sc.provider_id
         WHERE sc.id = :id AND sc.client_id = :uid
         LIMIT 1'
    );
    $stmt->execute([':id' => $contractId, ':uid' => $myId]);
    $contract = $stmt->fetch();

    if (!$contract) {
        chatFlash('error', 'Job not found.');
    } elseif ($contract['status'] !== 'accepted') {
        chatFlash('error', 'Only an accepted job can be cancelled.');
    } else {
        $stmt = $pdo->prepare('UPDATE service_contracts SET status = \'cancelled\' WHERE id = :id');
        $stmt->execute([':id' => $contractId]);

        // Tell the worker what happened, inside the same thread.
        $stmt = $pdo->prepare(
            'INSERT INTO messages (conversation_id, sender_id, recipient_id, message, kind)
             SELECT id, :me, :other, :msg, \'system\'
             FROM conversations
             WHERE (provider_id = :me AND client_id = :other)
                OR (provider_id = :other AND client_id = :me)
             LIMIT 1'
        );
        $stmt->execute([
            ':me'    => $myId,
            ':other' => (int) $contract['provider_id'],
            ':msg'   => $user['full_name'] . ' cancelled the job.',
        ]);

        chatFlash('info', 'Job cancelled.');
        header('Location: ' . sid_append('messenger.php?chat=' . (int) $contract['provider_id']));
        exit;
    }
}

// Unknown action: go back to the inbox.
chatFlash('error', 'Unknown action.');
header('Location: ' . sid_append('messenger.php'));
exit;
