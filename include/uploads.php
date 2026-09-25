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
