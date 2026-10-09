<?php
// ============================================================
// upload_listing_photos.php — a listing's OWN pictures
// The account avatar (upload_profile.php) is one photo for the
// whole person. A listing needs its own:
//   COVER  — providers.profile_picture, the one photo the card
//            shows. Up to the owner to set; while it is NULL the
//            card keeps showing the account avatar, so every
//            listing that predates this feature is unchanged.
//   ALBUM  — provider_album_images, up to ISLA_ALBUM_MAX_PHOTOS
//            extra pictures. A BUSINESS listing is the point of
//            it: a resort selling rooms, a rental selling bikes and
//            a shop selling stock are decided on by their photos.
//
// ONE handler, three actions, because they are one feature and they
// share every check below:
//   action=cover         set (or remove) the listing's cover photo
//   action=album_add     append pictures to the album
//   action=album_remove  drop one picture from the album
//
// The rules that hold for all three:
//   - POST + a valid CSRF token, or nothing happens
//   - the listing must belong to the logged-in user
//   - every file goes through isla_upload_validate() (5 MB, a real
//     image by CONTENT, JPG or PNG) and is stored under a random name
//   - the album never grows past ISLA_ALBUM_MAX_PHOTOS; the files
//     past that limit are refused, not silently dropped
//   - a listing always resolves to at least ONE picture, so the last
//     remaining one cannot be removed
// Nothing is ever trusted from the browser: not the profile id, not
// the photo id being deleted, not the filename.
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

// --- 3. Database connection + upload storage -------------------
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/uploads.php';

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

// --- 5. Flash helper --------------------------------------------
// One-shot banner on the islaFIND Profile panel, which is where the
// photos are managed and where the owner is already looking.
function listingPhotosFlash(string $type, string $msg): void
{
    $_SESSION['flash_isla'] = ['type' => $type, 'msg' => $msg];
    header('Location: ' . sid_append('dashboard.php?tab=isla'));
    exit;
}

// --- 6. Method + CSRF guard -------------------------------------
// A forged cross-site request cannot know the session token, so a
// plain GET link can neither add a photo nor delete one.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    listingPhotosFlash('error', 'Your session expired or the form token is invalid. Please try again.');
}

// --- 7. Which listing? (and is it yours?) -----------------------
$profileId = isla_post_int($_POST['profile_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM providers WHERE id = :id AND user_id = :uid LIMIT 1');
$stmt->execute([':id' => $profileId, ':uid' => $user['id']]);
$listing = $stmt->fetch();

if (!$listing) {
    // Deliberately one message for "does not exist" and "not yours":
    // distinguishing them would confirm that somebody else's listing
    // id is real.
    listingPhotosFlash('error', 'That profile no longer exists, or it is not yours.');
}

// --- 8. The listing's photos, for the count-based rules ---------
$albumStmt = $pdo->prepare(
    'SELECT id, image_name, sort_order
       FROM provider_album_images
      WHERE provider_id = :pid
      ORDER BY sort_order ASC, id ASC'
);
$albumStmt->execute([':pid' => $listing['id']]);
$album = $albumStmt->fetchAll();

$albumCount = count($album);
$slots     = ISLA_ALBUM_MAX_PHOTOS - $albumCount;   // room left in the album
$isBusiness = $listing['profile_type'] === 'business';

$action = (string) ($_POST['action'] ?? 'cover');

// ====================================================================
// 9. action=album_remove — drop ONE picture from the album
// ====================================================================
// The photo id is scoped to this listing in the DELETE itself, so a
// tampered id removes nothing: it belongs to a different listing, or
// to nobody. No ownership check can be skipped by editing a number
// in the form.
if ($action === 'album_remove') {
    $imageId = isla_post_int($_POST['image_id'] ?? 0);

    if ($imageId <= 0) {
        listingPhotosFlash('error', 'That photo could not be identified.');
    }

    $stmt = $pdo->prepare('SELECT image_name FROM provider_album_images WHERE id = :id AND provider_id = :pid LIMIT 1');
    $stmt->execute([':id' => $imageId, ':pid' => $listing['id']]);
    $row = $stmt->fetch();

    if (!$row) {
        listingPhotosFlash('error', 'That photo is no longer in this album.');
    }

    // The same one-picture floor as the cover, and it is the ALBUM that
    // can be holding a listing up: a listing with no cover, no account
    // avatar and this its last photo would go pictureless if the row
    // went away.
    if ($albumCount === 1 && trim((string) ($listing['profile_picture'] ?? '')) === ''
        && trim((string) ($user['profile_picture'] ?? '')) === '') {
        listingPhotosFlash(
            'error',
            'A profile needs at least one picture. Add a picture of its own, or set one on your account, before removing the last album photo.'
        );
    }

    // The FILE goes first, and only then the row: a file that cannot
    // be removed is a wasted byte, whereas a row without a file is a
    // broken image every visitor would load. Either way the row has
    // to go, so the file is best-effort (isla_upload_delete never
    // throws) and the row deletion is unconditional.
    isla_upload_delete((string) $row['image_name']);

    $stmt = $pdo->prepare('DELETE FROM provider_album_images WHERE id = :id AND provider_id = :pid');
    $stmt->execute([':id' => $imageId, ':pid' => $listing['id']]);

    // Close the gap left in the ordering so the strip keeps reading
    // first-to-last without a "position 2 of 5, then 1 of 5" wobble.
    renumberAlbum($pdo, (int) $listing['id']);

    $left = $albumCount - 1;
    listingPhotosFlash(
        'success',
        'Photo removed. ' . $left . ' photo' . ($left === 1 ? '' : 's') . ' left in this album.'
    );
}

// ====================================================================
// 10. action=album_add — append pictures to the album
// ====================================================================
if ($action === 'album_add') {
    // The album is a BUSINESS feature. An individual listing is a
    // person's own trade, and the account avatar is that person —
    // there is nothing for a second photo of the same face to add.
    if (!$isBusiness) {
        listingPhotosFlash('error', 'A photo album is for business listings. Your account picture already represents an individual profile.');
    }

    // How many would be left over if every chosen file were kept?
    // Refusing the whole submission is deliberate: accepting the
    // first N and quietly dropping the rest would leave the owner
    // believing every photo they picked was saved.
    $uploads = normaliseUploads($_FILES['album_photos'] ?? null);

    if (!$uploads) {
        listingPhotosFlash('error', 'Please choose at least one image to add.');
    }

    if ($slots <= 0) {
        listingPhotosFlash(
            'error',
            'This listing already has its ' . ISLA_ALBUM_MAX_PHOTOS . ' photos. Remove one before adding another.'
        );
    }

    if (count($uploads) > $slots) {
        listingPhotosFlash(
            'error',
            'You chose ' . count($uploads) . ' images but this listing has room for ' . $slots
            . ' more. Add ' . $slots . ' now, then the rest.'
        );
    }

    $saved = 0;
    $firstError = '';

    // The next position continues the strip rather than counting the
    // rows, so a removed photo's gap is simply not reused.
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order) + 1, 0) FROM provider_album_images WHERE provider_id = :pid');
    $stmt->execute([':pid' => $listing['id']]);
    $nextOrder = (int) $stmt->fetchColumn();

    foreach ($uploads as $upload) {
        $check = isla_upload_validate($upload, 5 * 1024 * 1024);

        if (!$check['ok']) {
            // Remember the first refusal and keep going, so one bad
            // file in a batch of three does not throw away the two
            // that were fine.
            if ($firstError === '') {
                $firstError = $check['error'];
            }
            continue;
        }

        $filename = isla_upload_name('listing', (int) $listing['id'], $check['ext']);

        if (!isla_upload_store($upload['tmp_name'], $filename)) {
            if ($firstError === '') {
                // The seam's own reason when it has one (a storage
                // misconfiguration says exactly what to fix), and the
                // permissions line only as the local-disk fallback.
                $firstError = isla_upload_failed_message('Could not save the file. Check the uploads folder permissions.');
            }
            continue;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO provider_album_images (provider_id, image_name, sort_order)
             VALUES (:pid, :name, :ord)'
        );
        $stmt->execute([
            ':pid'  => $listing['id'],
            ':name' => $filename,
            ':ord'  => $nextOrder++,
        ]);

        $saved++;
    }

    if ($saved === 0) {
        listingPhotosFlash('error', $firstError !== '' ? $firstError : 'No image could be saved.');
    }

    listingPhotosFlash(
        $firstError !== '' ? 'error' : 'success',
        ($saved === 1 ? '1 photo added.' : $saved . ' photos added.')
            . ($firstError !== '' ? ' ' . $firstError : '')
            . ' This album now holds ' . ($albumCount + $saved) . ' of ' . ISLA_ALBUM_MAX_PHOTOS . ' photos.'
    );
}

// ====================================================================
// 11. action=cover — set or clear the listing's own photo
// ====================================================================
$currentCover = (string) ($listing['profile_picture'] ?? '');
$hasCover     = trim($currentCover) !== '';

// --- 11a. Remove the cover -------------------------------------
// The one thing that cannot happen: a listing left with NO picture at
// all. The account avatar counts — a listing without a cover has been
// showing it all along — so this is refused only when removing the
// cover would strip the last picture in the building, album included.
if (isset($_POST['remove_cover'])) {
    if (!$hasCover) {
        listingPhotosFlash('error', 'This profile is already using your account picture.');
    }

    if (trim((string) ($user['profile_picture'] ?? '')) === '' && $albumCount === 0) {
        listingPhotosFlash(
            'error',
            'A profile needs at least one picture. Add a photo to the album, or set a picture on your account, before removing this one.'
        );
    }

    // Best-effort file removal, then the column goes back to NULL so
    // the card falls through to the account avatar again.
    isla_upload_delete($currentCover);

    $stmt = $pdo->prepare('UPDATE providers SET profile_picture = NULL WHERE id = :id');
    $stmt->execute([':id' => $listing['id']]);

    listingPhotosFlash('success', 'Profile picture removed. This profile now uses your account picture.');
}

// --- 11b. Replace (or set) the cover ----------------------------
$file = $_FILES['cover_photo'] ?? null;
$check = isla_upload_validate(is_array($file) ? $file : [], 5 * 1024 * 1024);

if (!$check['ok']) {
    listingPhotosFlash('error', $check['error']);
}

$filename = isla_upload_name('listing', (int) $listing['id'], $check['ext']);

if (!isla_upload_store($file['tmp_name'], $filename)) {
    listingPhotosFlash('error', isla_upload_failed_message('Could not save the file. Check the uploads folder permissions.'));
}

// The previous cover is removed only once the new file is safely in
// storage, so a failed write never leaves the listing with no picture
// at all. It is NOT the account avatar: a listing that never had its
// own picture must not delete the file the whole account is using.
if ($hasCover) {
    isla_upload_delete($currentCover);
}

$stmt = $pdo->prepare('UPDATE providers SET profile_picture = :pic WHERE id = :id');
$stmt->execute([':pic' => $filename, ':id' => $listing['id']]);

listingPhotosFlash('success', 'Profile picture updated. Your listings and your account now show different photos.');

// ====================================================================
// 12. Helpers (declared after use so the flow above reads top-down)
// ====================================================================

/**
 * renumberAlbum(PDO $pdo, int $providerId)
 * Rewrites sort_order as 0, 1, 2 … in the order the album is
 * currently displayed, after a removal left a hole in the sequence.
 * Best-effort: a failure here changes nothing a visitor can see, the
 * strip just keeps its gap.
 *
 * @param PDO $pdo
 * @param int $providerId
 * @return void
 */
function renumberAlbum(PDO $pdo, int $providerId): void
{
    $stmt = $pdo->prepare('SELECT id FROM provider_album_images WHERE provider_id = :pid ORDER BY sort_order ASC, id ASC');
    $stmt->execute([':pid' => $providerId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $update = $pdo->prepare('UPDATE provider_album_images SET sort_order = :ord WHERE id = :id');
    foreach ($ids as $i => $id) {
        $update->execute([':ord' => $i, ':id' => $id]);
    }
}

/**
 * normaliseUploads(?array $field)
 * Turns a $_FILES field into a plain list of single-file arrays,
 * whether the browser sent one file or several.
 *
 * A multiple-file input arrives as a field of ARRAYS
 * (['name' => [...], 'error' => [...], ...]) while a single-file one
 * arrives flat, and the two shapes have to be walked differently.
 * Slots the browser left empty (the user picked three files and then
 * deselected one) are dropped here rather than failing validation
 * later with a confusing message.
 *
 * @param array|null $field One entry of $_FILES, or null.
 * @return array List of single-file arrays.
 */
function normaliseUploads(?array $field): array
{
    if ($field === null) {
        return [];
    }

    // Single-file field: keep the whole array as one upload.
    if (!isset($field['name']) || !is_array($field['name'])) {
        return ($field['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$field];
    }

    $out = [];
    foreach (array_keys($field['name']) as $i) {
        $error = $field['error'][$i] ?? UPLOAD_ERR_NO_FILE;

        // An empty slot is not a failed upload; skip it silently.
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $out[] = [
            'name'     => $field['name'][$i]     ?? '',
            'type'     => $field['type'][$i]     ?? '',
            'tmp_name' => $field['tmp_name'][$i] ?? '',
            'error'    => $error,
            'size'     => $field['size'][$i]     ?? 0,
        ];
    }

    return $out;
}
