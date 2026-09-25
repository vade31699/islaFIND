<?php
// ============================================================
// send_message.php — Inquiry-to-Messenger handler
// When a user clicks \"Inquire Availability\" on a provider
// card, this page:
//   1. Finds (or creates) the conversation thread between the
//      client and the provider in the conversations table.
//   2. Sends the inquiry as the FIRST chat message in that
//      thread (so it lands inside messenger.php).
//   3. Redirects the client straight into the chat thread.
// The client and provider then continue talking back and
// forth inside the in-app messenger.
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

// --- 3. Only POSTs with a valid CSRF token are processed --------
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    header('Location: ' . sid_append('dashboard.php?tab=home'));
    exit;
}

// --- 4. Database connection ------------------------------------
require_once __DIR__ . '/../include/db.php';

// --- 5. Collect + validate the fields ---------------------------
$providerId = (int) ($_POST['provider_id'] ?? 0);
$message    = trim($_POST['message_text'] ?? '');
$clientId   = (int) $_SESSION['user_id'];

$ok = false;
$msg = '';

if ($providerId <= 0) {
    $msg = 'Please choose a provider to message.';
} elseif ($message === '') {
    $msg = 'Please enter a message before sending.';
} elseif (mb_strlen($message) > 2000) {
    $msg = 'Message must be 2000 characters or fewer.';
} else {
    // --- 6. Load the provider listing (need its owner) ----------
    // profile_type is read too: the contract row below is only
    // created for INDIVIDUAL SKILLS listings — business listings are
    // inquired/chatted with but never enter the hire -> rating chain.
    $stmt = $pdo->prepare('SELECT id, user_id, name, profile_type FROM providers WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $providerId]);
    $provider = $stmt->fetch();

    if (!$provider) {
        $msg = 'That listing no longer exists.';
    } elseif ((int) $provider['user_id'] === $clientId) {
        $msg = 'You cannot inquire about your own listing.';
    } else {
        // --- 7. Find or create the conversation thread ----------
        // One thread per provider-client pair (UNIQUE key). A NEW
        // inquiry is a MESSAGE REQUEST: the conversation starts as
        // 'pending' and only becomes an open chat once the provider
        // accepts it. If the pair is already talking (accepted), a
        // re-inquiry is just another message; if the previous
        // request was declined, this fresh inquiry re-opens it as a
        // new pending request.
        $providerUserId = (int) $provider['user_id'];
        $stmt = $pdo->prepare(
            'SELECT id, status FROM conversations
             WHERE provider_id = :pid AND client_id = :cid LIMIT 1'
        );
        $stmt->execute([':pid' => $providerUserId, ':cid' => $clientId]);
        $conv = $stmt->fetch();

        if ($conv) {
            $convId = (int) $conv['id'];           // existing thread
            // Not an open chat yet? This inquiry is a new (or
            // renewed) message request awaiting the provider.
            if ($conv['status'] !== 'accepted') {
                $stmt = $pdo->prepare(
                    "UPDATE conversations SET status = 'pending' WHERE id = :id"
                );
                $stmt->execute([':id' => $convId]);
            }
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO conversations (provider_id, client_id, status) VALUES (:pid, :cid, 'pending')"
            );
            $stmt->execute([':pid' => $providerUserId, ':cid' => $clientId]);
            $convId = (int) $pdo->lastInsertId(); // fresh pending request
        }

        // --- 8. Send the inquiry as the request message ---------
        $stmt = $pdo->prepare(
            'INSERT INTO messages (conversation_id, sender_id, recipient_id, message)
             VALUES (:cid, :sender, :recipient, :msg)'
        );
        $stmt->execute([
            ':cid'       => $convId,
            ':sender'    => $clientId,
            ':recipient' => $providerUserId,
            ':msg'       => $message,
        ]);

        // NOTE: no soft-delete flags are cleared here. Deletion is
        // independent per user, and a fresh inquiry starts a FRESH
        // view — the inquirer sees the new message while history they
        // deleted stays hidden from THEM; the provider keeps their
        // full copy until they delete it too.

        // --- 9. Record a service contract (pending hire) --------
        // This is what later unlocks the rating: once the provider
        // marks it completed, the client may submit a review. It is
        // created ONLY for INDIVIDUAL SKILLS listings — business
        // profiles are inquired about and chatted with, but never
        // enter the hire -> job -> rating chain (the HIRE! button,
        // ACCEPT/DECLINE and JOB DONE are all individual-only).
        // provider_listing_id pins the contract to THIS exact
        // listing (providers.id): a person can own many listings,
        // and only the one the client actually hired may be rated.
        if (($provider['profile_type'] ?? '') === 'individual') {
            $stmt = $pdo->prepare(
                'INSERT INTO service_contracts (provider_id, provider_listing_id, client_id, status)
                 VALUES (:pid, :lid, :cid, :st)
                 ON DUPLICATE KEY UPDATE id = id'
            );
            $stmt->execute([
                ':pid' => $providerUserId,
                ':lid' => (int) $provider['id'],
                ':cid' => $clientId,
                ':st'  => 'pending',
            ]);
        }

        // --- 10. Feed the recommendation engine -----------------
        $stmt = $pdo->prepare(
            'INSERT INTO user_interactions (user_id, provider_id, category, action)
             SELECT :uid, :pid, selected_title, :act FROM providers WHERE id = :pid'
        );
        $stmt->execute([':uid' => $clientId, ':pid' => $providerId, ':act' => 'inquiry']);

        $stmt = $pdo->prepare(
            'UPDATE providers SET interaction_count = interaction_count + 1 WHERE id = :id'
        );
        $stmt->execute([':id' => $providerId]);

        $ok = true;
    }
}

// --- 11. Redirect into the chat thread --------------------------
if ($ok && isset($convId)) {
    // Straight into the messenger thread with the provider.
    header('Location: ' . sid_append('messenger.php?chat=' . $providerUserId . '&newconv=1'));
    exit;
}

// Failure: leave a flash on the Home feed and go back there. That is
// where every inquiry starts now — the feed cards' detail modal is the
// one place an "Inquire Availability" form lives — so the feed flash is
// the only one this endpoint has to write.
$_SESSION['flash_feed'] = ['type' => 'error', 'msg' => $msg];
header('Location: ' . sid_append('dashboard.php?tab=home'));
exit;
