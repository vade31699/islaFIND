<?php
// ============================================================
// rate_service.php — Post-service rating handler
// A review may ONLY be submitted by the client of a service
// contract that has been marked 'completed' (and not rated yet).
// This prevents fake/unverified reviews: there is no general
// \"rate any provider\" endpoint anymore.
// ============================================================

// --- 1. Harden + start the session ------------------------------
require_once __DIR__ . '/security.php';
session_harden();
session_start();

// --- 2. Session guard: logged in? ------------------------------
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . sid_append('login.php'));
    exit;
}

// --- 3. Only POST + a valid CSRF token may write a review ------
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    header('Location: ' . sid_append('dashboard.php?tab=home'));
    exit;
}

// --- 4. Database connection -------------------------------------
require_once __DIR__ . '/db.php';

// --- 5. Collect + validate the fields ----------------------------
$contractId = (int) ($_POST['contract_id'] ?? 0);
$rating     = (int) ($_POST['rating'] ?? 0);
$comment    = trim($_POST['comment'] ?? '');
$clientId   = (int) $_SESSION['user_id'];

// Where to send the user afterwards: the messenger thread when the
// rating came from the chat (JOB DONE), otherwise the Home feed.
$returnChat = (int) ($_POST['return_chat'] ?? 0);

$flash = ['type' => 'error', 'msg' => 'Could not submit your rating.'];
$ok    = false;

// The rating must be a real 1-5 star value.
if ($rating < 1 || $rating > 5) {
    $flash['msg'] = 'Please choose a rating from 1 to 5 stars.';
} else {
    // --- 6. THE VALIDATION GATE ---------------------------------
    // The contract must exist and belong to THIS user as the
    // client. It must be rateable: either 'accepted' (the active
    // job — this request completes it and unlocks the review) or
    // 'completed' (the worker marked it done from the dashboard).
    // A job that is not at one of these stages cannot be rated,
    // and it must not already carry a review.
    $stmt = $pdo->prepare(
        'SELECT sc.*, p.id AS provider_listing_id, p.user_id AS provider_user_id
         FROM service_contracts sc
         JOIN providers p ON p.id = sc.provider_listing_id
         WHERE sc.id = :cid AND sc.client_id = :me
         LIMIT 1'
    );
    $stmt->execute([':cid' => $contractId, ':me' => $clientId]);
    $contract = $stmt->fetch();

    if (!$contract) {
        $flash['msg'] = 'No such job found for your account.';
    } elseif (!in_array($contract['status'], ['accepted', 'completed'], true)) {
        // A pending / pending_hire / declined / cancelled job
        // cannot be rated yet.
        $flash['msg'] = 'You can only rate a service after the job is completed.';
    } elseif ((int) $contract['is_rated'] === 1) {
        $flash['msg'] = 'You have already rated this service.';
    } else {
        // --- 7. Complete the job (if it was still active) --------
        // JOB DONE from the chat: flipping 'accepted' -> 'completed'
        // here means the contract is completed by the time the
        // review row is written, satisfying the eligibility gate.
        if ($contract['status'] === 'accepted') {
            $stmt = $pdo->prepare("UPDATE service_contracts SET status = 'completed' WHERE id = :id");
            $stmt->execute([':id' => $contractId]);
        }

        // --- 8. Insert the review (one per contract, UNIQUE) -----
        $stmt = $pdo->prepare(
            'INSERT INTO reviews (service_contract_id, provider_id, user_id, rating, comment)
             VALUES (:cid, :pid, :uid, :rating, :comment)'
        );
        $stmt->execute([
            ':cid'     => $contractId,
            ':pid'     => (int) $contract['provider_listing_id'],
            ':uid'     => $clientId,
            ':rating'  => $rating,
            ':comment' => $comment !== '' ? $comment : null,
        ]);

        // --- 9. Mark the contract as rated -----------------------
        $stmt = $pdo->prepare('UPDATE service_contracts SET is_rated = 1 WHERE id = :id');
        $stmt->execute([':id' => $contractId]);

        // --- 10. Refresh the provider's aggregate metrics --------
        // Recompute average_rating + review_count from the reviews
        // table so profile cards always show the latest numbers.
        $stmt = $pdo->prepare(
            'UPDATE providers p
             SET p.average_rating = COALESCE((SELECT AVG(r.rating) FROM reviews r WHERE r.provider_id = p.id), 0),
                 p.review_count   = (SELECT COUNT(*) FROM reviews r WHERE r.provider_id = p.id)
             WHERE p.id = :pid'
        );
        $stmt->execute([':pid' => (int) $contract['provider_listing_id']]);

        // --- 11. Log the interaction for recommendations ---------
        $stmt = $pdo->prepare(
            'INSERT INTO user_interactions (user_id, provider_id, category, action)
             SELECT :uid, :pid, selected_title, :act FROM providers WHERE id = :pid'
        );
        $stmt->execute([':uid' => $clientId, ':pid' => (int) $contract['provider_listing_id'], ':act' => 'review']);

        // --- 12. Notify the worker inside the chat thread --------
        $stmt = $pdo->prepare(
            'INSERT INTO messages (conversation_id, sender_id, recipient_id, message, kind)
             SELECT id, :me, :other, :msg, \'system\'
             FROM conversations
             WHERE (provider_id = :me AND client_id = :other)
                OR (provider_id = :other AND client_id = :me)
             LIMIT 1'
        );
        $stmt->execute([
            ':me'    => $clientId,
            ':other' => (int) $contract['provider_user_id'],
            ':msg'   => 'Job completed — you received a ' . $rating . '-star rating.',
        ]);

        $ok = true;
        $flash = ['type' => 'success', 'msg' => 'Thanks! Your rating for the completed job was saved.'];
    }
}

// --- 13. One-shot flash + return where the rating came from -----
if ($ok && $returnChat > 0) {
    $_SESSION['flash_chat'] = $flash;
    header('Location: ' . sid_append('messenger.php?chat=' . $returnChat));
    exit;
}
$_SESSION['flash_feed'] = $flash;
header('Location: ' . sid_append('dashboard.php?tab=home'));
exit;
