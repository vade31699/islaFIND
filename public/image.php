<?php
// ============================================================
// image.php — serves a stored picture when the bucket has no
// public URL of its own.
//
// WHY THIS FILE EXISTS
// Uploaded pictures live in object storage, and the browser normally
// fetches them straight from a public origin the operator names in
// UPLOADS_URL_BASE (see include/uploads.php). That variable is optional
// and is not injected by anything, so a deployment could hold perfectly
// good uploads that no page could display. This script closes that gap:
// it takes ?f=<stored filename>,
// signs a read of that one object with the credentials the app already
// has (include/s3.php — the same SigV4 code that stored the file), and
// streams the bytes back.
//
// It is a READER, never a writer, and deliberately narrow: its only
// input is a single filename, checked against a whitelist BEFORE
// anything is signed (isla_upload_servable_name), so no query string
// can turn into a signed read of some other object in the bucket.
//
// WHAT IT COSTS
// One PHP request per picture, where a public URL would cost none.
// isla_upload_url() therefore prefers the public URL when the operator
// has configured one, and uses this file otherwise — including when the
// platform's own AWS_URL is set, which this app deliberately does not
// treat as an origin (pointing the app at an origin that does not
// resolve is a broken image on every page). The bytes are cached at the
// browser afterwards (the names are random and never reused, so what
// sits behind a URL never changes), which keeps this path honest even on
// a listing page full of photos.
// ============================================================

require_once __DIR__ . '/../include/uploads.php';

/**
 * isla_image_fail()
 * One exit for every refusal: a status, a plain body, and no caching.
 * Kept in one place so a new failure path cannot accidentally answer
 * 200 with an error page in it, which is what a broken <img> hides.
 *
 * @param int $status HTTP status to send.
 * @return void
 */
function isla_image_fail(int $status): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    $body = 'Not found';
    if ($status === 400) {
        $body = 'Bad request';
    } elseif ($status === 503) {
        // The object may well exist; the app could not ask for it.
        $body = 'Temporarily unavailable';
    }

    echo $body . "\n";
}

// On the local driver the files really are in the web root and Apache
// serves them; a request here is a mistake or a probe, not a picture.
if (isla_uploads_driver() !== 'remote') {
    isla_image_fail(404);
    exit;
}

// ?f[]=x arrives as an array; anything that is not a plain string is
// not a stored filename, and isla_upload_servable_name() decides the
// rest (no separators, no leading dot, an image extension).
$name = isset($_GET['f']) && is_string($_GET['f']) ? $_GET['f'] : '';

if (!isla_upload_servable_name($name)) {
    isla_image_fail(400);
    exit;
}

require_once __DIR__ . '/../include/s3.php';

$result = isla_s3_get($name);

if (!$result['ok']) {
    // The reason belongs to the operator, not the visitor: the log gets
    // the status and the service's own error body, the page gets a
    // plain 404. A status of 0 means the request never left (missing
    // configuration or no cURL), which is the app's problem, not a
    // missing object — worth the different code.
    error_log(
        'islaFIND: image.php could not read ' . $name . ' from object storage (HTTP '
        . $result['status'] . '): ' . $result['error'] . ' ' . substr($result['body'], 0, 300)
    );

    isla_image_fail($result['status'] === 0 ? 503 : 404);
    exit;
}

$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$type = [
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
][$ext] ?? 'image/jpeg';

header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($result['body']));
// A stored name is random hex and never reused, so the bytes behind
// one URL never change and the browser may keep them.
header('Cache-Control: public, max-age=604800');
header('X-Content-Type-Options: nosniff');

echo $result['body'];
