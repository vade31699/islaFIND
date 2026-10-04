<?php
// ============================================================
// feed_watch.php — Live Home-feed watcher
// A small JSON API the dashboard polls (every ~15s, and whenever
// the app comes back to the front) so the feed on screen never
// lies about who is on the island.
//
// WHY IT EXISTS
// The Home feed is rendered ONCE, server-side, into .feed-card
// elements, and the search box then filters them in the DOM. That
// is fast and it means one search reaches every listing — but it
// also means the page holds a SNAPSHOT. Somebody who deletes their
// own listing (or whose whole account is deleted, or whose listing
// or account an admin blocks) stays on other people's screens
// until they happen to reload. This endpoint is what makes that
// disappearance live instead.
//
// WHAT IT ANSWERS
//   feed_watch.php?have=<csv of the ids on screen>
//
// `have` is the canonical id list the client is showing (ascending,
// comma-separated, no repeats — see isla_feed_id_list()), which the
// browser rebuilds from the cards in its own DOM. The server re-runs the
// ONE query that decides what a member may discover
// (isla_listing_live_where(): the listing's own status AND its owner's)
// and compares its own list against it:
//
//   the two lists are equal -> {"ok":true,"same":true}     (~25 bytes)
//   they are not          -> {"ok":true,"same":false,
//                             "removed":[gone ids],
//                             "added":[new ids],
//                             "cards":{"<id>":"<card html>"}}
//
// So a poll that finds nothing moved — which is nearly all of them — costs
// one indexed id read and a few dozen bytes, which is what makes polling
// every 15 seconds affordable on a phone.
//
// `cards` carries finished markup only for listings that appeared,
// rendered by the SAME feedCardHtml() the page uses (see
// include/feed_card.php) so a card that arrives live is identical to the
// one a refresh would have produced. Listings that left are removed by id,
// which needs no markup at all.
//
// Because the filter is the visibility rule and not "does the row still
// exist", an admin block disappears live exactly like a self-deletion does.
//
// NOTES
//   * Read-only, so it is a plain GET with no CSRF token — nothing
//     here changes state. Same shape as the other JSON endpoints.
//   * Output is data-only: `cards` is HTML on purpose (it is
//     inserted with innerHTML, which is why the renderer escapes
//     every field), everything else is ids and strings.
//   * `ok:false` means the session is gone — the account was
//     deleted, or the session expired. The client reloads, and
//     dashboard.php's own guard sends it to the login page.
// ============================================================

// --- 1. Harden the session cookie, then start the session ------
require_once __DIR__ . '/../include/security.php';
session_harden(); // must run before session_start()
session_start();

// Never let a proxy or the browser keep a stale answer: the whole
// point of this endpoint is that its reply describes RIGHT NOW.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: application/json');

/**
 * The one way this file ends.
 *
 * @param array $payload Anything json_encode()-able.
 * @return void
 */
function feedWatchReply(array $payload): void
{
    echo json_encode($payload);
    exit;
}

// --- 2. Session guard: logged in? ------------------------------
// No user_id -> not logged in -> nothing to report (never an error,
// so a poller that outlives its session just stops quietly).
if (!isset($_SESSION['user_id'])) {
    feedWatchReply(['ok' => false]);
}

// --- 3. Database connection + the pieces the cards need ---------
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/categories.php';   // the slug => label map a card's badge needs
require_once __DIR__ . '/../include/uploads.php';      // isla_listing_photo_src() / isla_upload_url()
require_once __DIR__ . '/../include/listing_visibility.php'; // who may discover a listing
require_once __DIR__ . '/../include/feed_card.php';     // feedCardHtml() + isla_feed_id_list()

// --- 4. The user row (validates the stored session id) --------
$stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch();

// Account deleted while the feed was open? The page this poll
// belongs to is no longer valid either.
if (!$user) {
    feedWatchReply(['ok' => false]);
}

$myId = (int) $user['id'];

// --- 5. What is on the island right now ------------------------
// The SAME condition dashboard.php feeds its whole Home panel with
// (see the comment above that query): a listing is discoverable
// only while it is active AND its owner is. That is what makes a
// deletion AND an admin block disappear through this one endpoint.
//
// Only ids are read here — this runs every 15 seconds on every
// open app, so it must stay the cheapest possible read. The full
// rows (and the album, and the review aggregates) are fetched only
// for listings that actually appeared, in step 8.
$stmt = $pdo->query(
    'SELECT p.id
       FROM providers p
      ' . isla_listing_live_join('p') . '
      WHERE ' . isla_listing_live_where('p') . '
      ORDER BY p.id ASC'
);
$liveIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));
$liveCsv = isla_feed_id_list($liveIds);

// --- 6. The client's list is the island? Say so in ~25 bytes ----
// The client's `have` list and the island's, both in the same canonical
// shape (isla_feed_id_list), compared as plain strings. Equal strings mean
// the feed on screen is still the feed on the island — and because the
// comparison is the whole list and not a claim about it, there is no way
// for a stale page to talk its way past this check.
//
// Skipping the diff keeps the steady-state cost to one id read and a few
// dozen bytes, which is the entire reason a 15-second poll is affordable.
$have = isset($_GET['have']) ? (string) $_GET['have'] : '';
if ($have === $liveCsv) {
    feedWatchReply(['ok' => true, 'same' => true]);
}

// --- 7. Work out what moved, in both directions ---------------
// Parse the client's list into ints: they only ever end up as array keys,
// JSON values or (in step 8) SQL placeholders that are bound, never
// concatenated. Anything that is not a plain integer is dropped, so a
// hand-edited ?have= cannot smuggle anything into the query.
$clientIds = [];
foreach (explode(',', $have) as $rawId) {
    if ($rawId !== '' && ctype_digit($rawId)) {
        $clientIds[(int) $rawId] = true;
    }
}

$liveSet = array_flip($liveIds);
$removed = [];   // on screen, no longer on the island
$added   = [];   // on the island, not on screen
foreach ($clientIds as $id => $_) {
    if (!isset($liveSet[$id])) {
        $removed[] = $id;
    }
}
foreach ($liveIds as $id) {
    if (!isset($clientIds[$id])) {
        $added[] = $id;
    }
}

// --- 8. Render the cards for listings that APPEARED ------------
// Only these need markup, and there are normally none. The query is
// the dashboard's discovery read restricted to the new ids, so a card
// that arrives live carries the same rating, job count and account
// photo a refresh would have given it.
$cards = [];
if ($added) {
    $placeholders = [];
    $params       = [];
    foreach ($added as $i => $id) {
        $key             = ':n' . $i;
        $placeholders[]  = $key;
        $params[$key]    = $id;
    }

    $stmt = $pdo->prepare(
        'SELECT p.*,
                u.full_name  AS user_name,
                u.profile_picture AS user_pic,
                u.phone      AS user_phone,
                p.average_rating AS avg_rating,
                p.review_count   AS review_count,
                (SELECT COUNT(sc.id) FROM service_contracts sc
                  WHERE sc.provider_id = p.user_id AND sc.status = \'completed\')      AS completed_jobs,
                EXISTS (SELECT 1 FROM service_contracts oj
                         WHERE oj.provider_id = p.user_id AND oj.status = \'accepted\') AS on_job
           FROM providers p
           JOIN users u ON u.id = p.user_id
          WHERE p.id IN (' . implode(', ', $placeholders) . ')
            AND ' . isla_listing_live_where() . '
          ORDER BY p.created_at DESC'
    );
    $stmt->execute($params);
    $newRows = $stmt->fetchAll();

    // The album for those listings, in one read (the same preload the
    // dashboard does, narrowed to the new ids).
    $albumsByProvider = [];
    if ($newRows) {
        $albumPlaceholders = [];
        $albumParams       = [];
        foreach ($newRows as $i => $row) {
            $key                  = ':a' . $i;
            $albumPlaceholders[]  = $key;
            $albumParams[$key]    = (int) $row['id'];
        }
        $albumStmt = $pdo->prepare(
            'SELECT provider_id, id, image_name
               FROM provider_album_images
              WHERE provider_id IN (' . implode(', ', $albumPlaceholders) . ')
              ORDER BY provider_id ASC, sort_order ASC, id ASC'
        );
        $albumStmt->execute($albumParams);
        foreach ($albumStmt->fetchAll() as $albumRow) {
            $albumsByProvider[(int) $albumRow['provider_id']][] = [
                'id'   => (int) $albumRow['id'],
                'name' => (string) $albumRow['image_name'],
            ];
        }
    }

    // Which of the new listings the visitor has bookmarked, so a
    // card that arrives live shows the right heart.
    $savedIds = [];
    if ($newRows) {
        $stmt = $pdo->prepare(
            'SELECT provider_id FROM saved_listings
              WHERE user_id = :me AND provider_id IN (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params + [':me' => $myId]);
        foreach ($stmt->fetchAll() as $savedRow) {
            $savedIds[(int) $savedRow['provider_id']] = 1;
        }
    }

    $csrf = csrf_token();
    foreach ($newRows as $row) {
        $id = (int) $row['id'];
        // The catalogue variant (true): a listing that appears while
        // the page is open joins the 📖 All Listings grid, which is
        // the complete, uncapped list — so the catalogue is always
        // correct again immediately. The rails above it are a curated
        // top-N chosen server-side from a full ranking pass (affinity,
        // jobs, engagement), which this cheap poll deliberately does
        // not re-run: a new listing waits for the next visit to earn a
        // place in a rail rather than jumping in unranked.
        $cards[(string) $id] = feedCardHtml(
            $row, $providerCategories, $myId, $csrf, $savedIds, true,
            $albumsByProvider[$id] ?? []
        );
    }
}

// --- 9. Report the diff ----------------------------------------
// A listing can be added AND another removed in the same poll, so
// both lists ship every time rather than as separate events.
feedWatchReply([
    'ok'      => true,
    'same'    => false,
    'removed' => $removed,
    'added'   => $added,
    'cards'   => $cards,
]);