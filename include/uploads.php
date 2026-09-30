<?php
// ============================================================
// uploads.php — where profile pictures live
//
// Everything that touches an uploaded picture goes through the three
// functions below, so the app has ONE answer to "where is it?" and
// "what URL does the browser fetch it from?". Today that answer is
// the local disk, which is what WAMP uses and what the app has always
// done; the point of the seam is that moving storage is a change to
// this file, not a hunt through the pages.
//
// WHY THIS MATTERS
// A host that rebuilds its container on every deploy has an EPHEMERAL
// filesystem: public/uploads/ is wiped on each release, so pictures
// would vanish. Such a host keeps them in object storage instead. The
// read side of that is ready here (UPLOADS_URL_BASE, below); the
// write side is not implemented yet, on purpose — see the note at the
// bottom of this file.
// ============================================================

require_once __DIR__ . '/env.php';

/**
 * ISLA_ALBUM_MAX_PHOTOS
 * How many pictures one listing's album may hold. It is a product
 * decision (what a shop front, a set of rooms and a rack of bikes
 * actually need to show), not a technical limit, so it is declared
 * once here — the same place the picture rules below live — instead
 * of being spelled out as a literal in every page that renders the
 * counter and in the handler that enforces it. Raise it here and
 * both move together.
 */
const ISLA_ALBUM_MAX_PHOTOS = 5;

/**
 * isla_uploads_driver()
 * Which backend stores the files. 'local' is the default and the only
 * one implemented; an unrecognised value falls back to 'local' so a
 * typo can never send writes somewhere that does not exist.
 *
 * @return string 'local' or 'remote'
 */
function isla_uploads_driver(): string
{
    return strtolower(trim(env('UPLOADS_DRIVER', 'local'))) === 'remote' ? 'remote' : 'local';
}

/**
 * isla_uploads_dir()
 * The folder on disk that holds the files AND is served to the
 * browser — public/uploads, i.e. inside the document root. It lives
 * under public/ precisely so a picture can be fetched as
 * /uploads/<name> without a route or a proxy.
 *
 * @return string Absolute path, no trailing slash.
 */
function isla_uploads_dir(): string
{
    return dirname(__DIR__) . '/public/uploads';
}

/**
 * isla_upload_store()
 * Moves a validated upload into storage under $filename.
 *
 * The caller has already proved the file is a real JPG/PNG of an
 * acceptable size (see upload_profile.php); this function only moves
 * bytes, and never trusts the original filename — the caller builds
 * $filename from random bytes.
 *
 * @param string $tmpPath  PHP's temporary upload path.
 * @param string $filename Bare filename to store it as.
 * @return bool TRUE on success.
 */
function isla_upload_store(string $tmpPath, string $filename): bool
{
    if (isla_uploads_driver() === 'remote') {
        return isla_upload_store_remote($tmpPath, $filename);
    }

    return move_uploaded_file($tmpPath, isla_uploads_dir() . '/' . $filename);
}

/**
 * isla_upload_validate()
 * The one place that decides whether an upload is an acceptable
 * picture. It exists because the rules must be identical everywhere
 * a file is accepted — the account avatar and a listing's own photo
 * and its album — and a rule that is written out twice is a rule
 * that drifts: the front-end accept list, the size cap and the
 * real-image test are what stand between the uploads folder and a
 * file that merely claims to be a JPEG.
 *
 * getimagesize() is the load-bearing part: it returns FALSE for
 * anything that is not an image, and it reports the TRUE mime type
 * of the CONTENT, so a file renamed from .txt to .png still fails
 * here. The original filename is never read for the decision, and
 * the caller never stores it either.
 *
 * @param array $file    One entry of $_FILES (a single-file field).
 * @param int   $maxBytes Size ceiling in bytes.
 * @return array ['ok' => bool, 'error' => string, 'ext' => string]
 *               'ext' is 'jpg' or 'png' when ok, '' otherwise.
 */
function isla_upload_validate(array $file, int $maxBytes = 5242880): array
{
    $fail = static function (string $msg): array {
        return ['ok' => false, 'error' => $msg, 'ext' => ''];
    };

    // (a) A file must actually be present and arrive without an error.
    //     UPLOAD_ERR_NO_FILE is what a browse button left untouched
    //     reports, which is a message of its own, not a broken upload.
    if (!isset($file['error'])) {
        return $fail('Please choose an image file to upload.');
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return $fail('No file was chosen.');
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        // The server's own upload_max_filesize stopped it first, so the
        // file never even reached the size test below.
        return $fail('That image is larger than the server accepts (see upload_max_filesize in php.ini).');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return $fail('The upload did not finish. Please try again.');
    }

    // (b) Size cap: 5 MB by default (the plan's requirement).
    if (isset($file['size']) && (int) $file['size'] > $maxBytes) {
        $mb = max(1, (int) round($maxBytes / 1048576));
        return $fail('Each image must be ' . $mb . ' MB or smaller.');
    }

    // (c) getimagesize() returns FALSE for non-images, so it both
    //     confirms the file really is an image AND gives us its true
    //     MIME type.
    $imgInfo = @getimagesize($file['tmp_name'] ?? '');
    if ($imgInfo === false) {
        return $fail('The file is not a valid image.');
    }

    // (d) Only JPG and PNG are accepted (matches the front-end accept).
    if (!in_array($imgInfo['mime'], ['image/jpeg', 'image/png'], true)) {
        return $fail('Only JPG and PNG images are allowed.');
    }

    return [
        'ok'    => true,
        'error' => '',
        'ext'   => $imgInfo['mime'] === 'image/png' ? 'png' : 'jpg',
    ];
}

/**
 * isla_upload_name()
 * Builds the stored filename: <prefix><id>_<16 random hex>.<ext>.
 *
 * The original filename is NEVER part of it — it could carry path
 * tricks, a null byte or an extension that has nothing to do with
 * the content — and the random suffix removes collisions entirely,
 * so two people uploading "photo.jpg" cannot overwrite each other.
 * The id keeps an owner's files recognisable in the uploads folder,
 * which matters when somebody has to clean it up by hand.
 *
 * @param string $prefix Short owner tag, e.g. 'user' or 'listing'.
 * @param int    $id     Owner id (users.user_id, providers.id).
 * @param string $ext    'jpg' or 'png' from isla_upload_validate().
 * @return string
 */
function isla_upload_name(string $prefix, int $id, string $ext): string
{
    return $prefix . '_' . $id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
}

/**
 * isla_listing_photo_src()
 * The picture a listing card should show, as a URL — or NULL when
 * there is none at all.
 *
 * A listing may now carry a picture of its OWN, but it does not have
 * to: NULL in providers.profile_picture means "no picture yet", and
 * the account avatar (users.profile_picture) is what the listing has
 * always shown. That fallback is the reason this column could be
 * added without touching a single existing row or a single rendered
 * card, and it is why every card in the app resolves its picture
 * through this function instead of reading the two columns itself:
 * one place decides the order, so a card, the detail modal and the
 * owner's own panel can never disagree about which photo is showing.
 *
 * @param string|null $listingPhoto providers.profile_picture.
 * @param string|null $accountPhoto users.profile_picture.
 * @return string|null URL, or NULL when neither is set.
 */
function isla_listing_photo_src(?string $listingPhoto, ?string $accountPhoto): ?string
{
    $name = ($listingPhoto !== null && trim($listingPhoto) !== '') ? $listingPhoto : $accountPhoto;

    return ($name === null || trim($name) === '') ? null : isla_upload_url($name);
}

/**
 * isla_upload_delete()
 * Best-effort removal of a stored file. Never throws and never
 * reports: it runs on the delete-account and replace-picture paths,
 * where a file that is already gone is not an error, and where a
 * failure must not block the database change that follows.
 *
 * @param string $filename Stored name (or path) to remove.
 * @return void
 */
function isla_upload_delete(string $filename): void
{
    // basename() so a stored value can never reach outside the folder.
    $name = basename($filename);
    if ($name === '' || $name === '.' || $name === '..') {
        return;
    }

    if (isla_uploads_driver() === 'remote') {
        isla_upload_delete_remote($name);
        return;
    }

    $path = isla_uploads_dir() . '/' . $name;
    if (is_file($path)) {
        @unlink($path);   // best-effort: ignore failures
    }
}

/**
 * isla_upload_url()
 * The URL a page should put in <img src="...">.
 *
 * With no UPLOADS_URL_BASE set it returns the app-relative path the
 * pages have always used ('uploads/<name>'), because the file really
 * is sitting in the web root. When pictures are served from a bucket
 * or CDN instead, set UPLOADS_URL_BASE to that origin and every page
 * picks it up without a code change.
 *
 * The filename is rawurlencode()d on both paths: uploaded names are
 * random hex today, but encoding is what keeps a name with a space or
 * an ampersand from breaking the URL if that ever stops being true.
 *
 * @param string $filename Stored name (or path) of the picture.
 * @return string URL ready for an attribute (callers still escape it).
 */
function isla_upload_url(string $filename): string
{
    $name = rawurlencode(basename($filename));

    $base = rtrim(trim(env('UPLOADS_URL_BASE', '')), '/');
    if ($base !== '') {
        return $base . '/' . $name;
    }

    return 'uploads/' . $name;
}

/**
 * isla_upload_store_remote()
 * NOT IMPLEMENTED YET — deliberately.
 *
 * Storing to an S3-compatible bucket means signing the request with
 * AWS Signature Version 4. That is deterministic code, but it cannot
 * be exercised without a real bucket and real credentials, and the
 * failure mode of getting it subtly wrong is a 403 SignatureDoesNotMatch
 * at the moment a user tries to save their picture. Shipping it
 * unverified would trade a visible gap for an invisible one.
 *
 * It is therefore the ONE remaining piece of the object-storage
 * switch. The intended shape is a signed PUT of the file followed by
 * reads through UPLOADS_URL_BASE (already supported above), with
 * these variables: UPLOADS_DRIVER=remote, UPLOADS_URL_BASE,
 * S3_ENDPOINT, S3_REGION, S3_BUCKET, S3_KEY, S3_SECRET.
 *
 * Until it exists this logs a precise reason and fails the upload, so
 * a misconfiguration is loud in the log and visible to the uploader —
 * never a silent claim that the picture was saved.
 *
 * @param string $tmpPath
 * @param string $filename
 * @return bool Always FALSE for now.
 */
function isla_upload_store_remote(string $tmpPath, string $filename): bool
{
    error_log(
        'islaFIND: UPLOADS_DRIVER=remote is set but the object-storage driver '
        . 'is not implemented, so ' . $filename . ' was NOT stored. Use '
        . 'UPLOADS_DRIVER=local, or finish include/uploads.php.'
    );

    return false;
}

/**
 * isla_upload_delete_remote()
 * Counterpart of the above; logs and does nothing.
 *
 * @param string $name
 * @return void
 */
function isla_upload_delete_remote(string $name): void
{
    error_log('islaFIND: cannot delete remote upload ' . $name . ' (driver not implemented).');
}
