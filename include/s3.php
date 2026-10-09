<?php
// ============================================================
// s3.php — the S3-compatible object-storage client behind the
// upload seam (include/uploads.php).
//
// WHY THIS FILE EXISTS
// A host that rebuilds its container on every deploy has an
// ephemeral filesystem: public/uploads/ is wiped on each release,
// so a picture written to disk is gone by the next release while
// its database row still names it — a card with a broken image.
// The fix is to keep the bytes in object storage and serve them
// from its public URL. uploads.php is the seam every page already
// goes through; this file is the "remote" half of that seam.
//
// WHAT IT SPEAKS
// AWS Signature Version 4 (SigV4), the one scheme every S3-
// compatible service accepts: Amazon S3, Cloudflare R2 (which is
// what Laravel Cloud Object Storage runs on), MinIO, Backblaze
// B2, DigitalOcean Spaces. There is no SDK dependency — the whole
// algorithm is the pure functions below, which is exactly what
// lets it be checked offline against AWS's own published example
// signatures in s3_sign_test.php rather than only against a live
// bucket.
//
// CONFIGURATION (read through env.php, so the process environment
// wins over .env — the normal way a host injects them):
//   AWS_BUCKET             bucket name
//   AWS_ENDPOINT_URL       endpoint URL (AWS_ENDPOINT also accepted)
//   AWS_REGION             signing region; 'auto' on R2
//   AWS_ACCESS_KEY_ID      access key id
//   AWS_SECRET_ACCESS_KEY  secret access key
//   S3_PATH_STYLE          '1' (default) path-style, '0' virtual-hosted
//
// AWS_URL is NOT read here, and not by uploads.php either: it belongs to
// the platform's own bucket binding, not to this app's read path. Only
// UPLOADS_URL_BASE (uploads.php) names an origin this app will trust.
//
// Laravel Cloud injects AWS_BUCKET / AWS_ENDPOINT_URL / AWS_REGION /
// AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY automatically once a
// bucket is attached as the environment's default disk. It also sets
// AWS_URL for its own binding — which this app deliberately ignores as
// a read origin (see uploads.php): a value that does not resolve would
// otherwise break every picture on the site. UPLOADS_URL_BASE is the
// app's own switch, and setting it to a CDN origin is a one-line
// change.
// ============================================================

require_once __DIR__ . '/env.php';

const ISLA_S3_ALGO    = 'AWS4-HMAC-SHA256';
const ISLA_S3_SERVICE = 's3';

/**
 * isla_s3_config()
 * The connection settings, read once from the environment. Empty
 * strings mean "not set" — the caller checks with
 * isla_s3_missing_config() rather than trusting a default, because
 * a wrong default here is a request signed for the wrong bucket.
 *
 * @return array{bucket:string,endpoint:string,region:string,key:string,secret:string,path_style:bool}
 */
function isla_s3_config(): array
{
    $endpoint = trim(env('AWS_ENDPOINT_URL', ''));
    if ($endpoint === '') {
        // The bucket-credentials modal on other S3 hosts calls it
        // AWS_ENDPOINT; accept both so one config works on either.
        $endpoint = trim(env('AWS_ENDPOINT', ''));
    }

    $region = trim(env('AWS_REGION', ''));
    if ($region === '') {
        // Cloudflare R2 signs with the literal region "auto".
        $region = 'auto';
    }

    return [
        'bucket'     => trim(env('AWS_BUCKET', '')),
        'endpoint'   => $endpoint,
        'region'     => $region,
        'key'        => trim(env('AWS_ACCESS_KEY_ID', '')),
        'secret'     => trim(env('AWS_SECRET_ACCESS_KEY', '')),
        'path_style' => env('S3_PATH_STYLE', '1') !== '0',
    ];
}

/**
 * isla_s3_missing_config()
 * Which of the required keys are unset — [] when the client is
 * ready. The list is returned (not just a bool) so the failure can
 * be logged by name: "missing endpoint, bucket" tells an operator
 * exactly what to add, where "storage failed" does not.
 *
 * @return string[]
 */
function isla_s3_missing_config(): array
{
    $cfg     = isla_s3_config();
    $missing = [];

    foreach (['bucket', 'endpoint', 'key', 'secret'] as $key) {
        if ($cfg[$key] === '') {
            $missing[] = $key;
        }
    }

    return $missing;
}

/**
 * isla_s3_is_configured()
 * TRUE when every required key is present.
 *
 * @return bool
 */
function isla_s3_is_configured(): bool
{
    return isla_s3_missing_config() === [];
}

/**
 * isla_s3_uri_encode()
 * The UriEncode() the SigV4 spec demands, written by hand because
 * PHP's rawurlencode()/urlencode() disagree with it in ways that
 * silently change the signature: urlencode() turns a space into '+',
 * and both treat '~' differently from the spec on older versions.
 *
 * Only the unreserved set (A-Z a-z 0-9 - . _ ~) survives; every
 * other byte becomes %XX with UPPERCASE hex. '/' is the one
 * character whose treatment depends on where it is: it stays a '/'
 * inside an object key, and is encoded everywhere else.
 *
 * @param string $input       Raw text (UTF-8 bytes).
 * @param bool   $encodeSlash TRUE to encode '/' too.
 * @return string
 */
function isla_s3_uri_encode(string $input, bool $encodeSlash): string
{
    $out = '';
    $len = strlen($input);

    for ($i = 0; $i < $len; $i++) {
        $ch = $input[$i];

        if (($ch >= 'A' && $ch <= 'Z')
            || ($ch >= 'a' && $ch <= 'z')
            || ($ch >= '0' && $ch <= '9')
            || $ch === '-' || $ch === '.' || $ch === '_' || $ch === '~'
        ) {
            $out .= $ch;
        } elseif ($ch === '/') {
            $out .= $encodeSlash ? '%2F' : '/';
        } else {
            $out .= '%' . strtoupper(bin2hex($ch));
        }
    }

    return $out;
}

/**
 * isla_s3_authorization()
 * The signing algorithm itself — a pure function, so it can be
 * tested against a fixed request and a known answer (see
 * s3_sign_test.php) instead of only against a live bucket.
 *
 * It returns the whole Authorization header value, because that is
 * the only place the access key id and the scope are spelled out;
 * the caller sends it verbatim.
 *
 * The inputs are the already-canonicalised pieces: the caller has
 * encoded the URI and sorted the query. Headers arrive as a name =>
 * value map and are lower-cased, trimmed and sorted HERE, because
 * getting that order wrong is the classic silent SigV4 failure.
 *
 * @param string   $method         HTTP verb, e.g. 'PUT'.
 * @param string   $canonicalUri   Encoded path, starting with '/'.
 * @param string   $canonicalQuery Encoded query string, '' when none.
 * @param array    $headers        name => value (Host included).
 * @param string   $payloadHash    hex sha256 of the body.
 * @param string   $amzDate        'YYYYMMDDTHHMMSSZ'.
 * @param string   $region         Signing region ('auto' on R2).
 * @param string   $service        's3'.
 * @param string   $accessKey      Access key id.
 * @param string   $secretKey      Secret access key.
 * @return string Authorization header value.
 */
function isla_s3_authorization(
    string $method,
    string $canonicalUri,
    string $canonicalQuery,
    array $headers,
    string $payloadHash,
    string $amzDate,
    string $region,
    string $service,
    string $accessKey,
    string $secretKey
): string {
    // 1. Canonical headers: lowercase names, trimmed values, sorted.
    $canonical = [];
    foreach ($headers as $name => $value) {
        $canonical[strtolower((string) $name)] = trim((string) $value);
    }
    ksort($canonical, SORT_STRING);

    $headerLines  = '';
    $signedNames  = [];
    foreach ($canonical as $name => $value) {
        $headerLines .= $name . ':' . $value . "\n";
        $signedNames[] = $name;
    }
    $signedHeaders = implode(';', $signedNames);

    // 2. Canonical request. NOTE the "\n" after $headerLines: because
    //    every header line already ends in "\n", that extra newline is
    //    what separates the header block from SigningHeaders — the
    //    blank line the spec shows when the query string is empty.
    $canonicalRequest = $method . "\n"
        . $canonicalUri . "\n"
        . $canonicalQuery . "\n"
        . $headerLines . "\n"
        . $signedHeaders . "\n"
        . $payloadHash;

    // 3. String to sign: the scope binds the signature to one day,
    //    one region and one service.
    $dateStamp = substr($amzDate, 0, 8);
    $scope     = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';

    $stringToSign = ISLA_S3_ALGO . "\n"
        . $amzDate . "\n"
        . $scope . "\n"
        . hash('sha256', $canonicalRequest);

    // 4. Derive the signing key — a HMAC chain that never exposes the
    //    secret, then sign the string to sign with it.
    $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
    $kRegion  = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    return ISLA_S3_ALGO
        . ' Credential=' . $accessKey . '/' . $scope
        . ',SignedHeaders=' . $signedHeaders
        . ',Signature=' . $signature;
}

/**
 * isla_s3_target()
 * Where an object lives, as both the URL to send and the pieces the
 * signature needs. The canonical URI and the request path are built
 * from the SAME string on purpose: signing one path and sending
 * another is the other classic silent SigV4 failure.
 *
 * Path-style (endpoint/bucket/key) is the default because it works
 * on every S3-compatible host including R2; virtual-hosted
 * (bucket.endpoint/key) is opt-in with S3_PATH_STYLE=0.
 *
 * @param string $key Object key (bare filename here).
 * @param array  $cfg From isla_s3_config().
 * @return array{url:string,host:string,path:string}
 */
function isla_s3_target(string $key, array $cfg): array
{
    $endpoint = $cfg['endpoint'];
    if (strpos($endpoint, '://') === false) {
        $endpoint = 'https://' . $endpoint;
    }

    $parts    = parse_url($endpoint) ?: [];
    $scheme   = $parts['scheme'] ?? 'https';
    $host     = $parts['host'] ?? '';
    if (isset($parts['port'])) {
        $host .= ':' . $parts['port'];
    }
    $basePath = rtrim($parts['path'] ?? '', '/');

    // '/' is left intact inside the key: object keys may contain
    // slashes and encoding them would look up a different object.
    $encodedKey = isla_s3_uri_encode($key, false);

    if ($cfg['path_style']) {
        $path = $basePath . '/' . $cfg['bucket'] . '/' . $encodedKey;
    } else {
        $host = $cfg['bucket'] . '.' . $host;
        $path = $basePath . '/' . $encodedKey;
    }

    $path = '/' . ltrim($path, '/');

    return [
        'url'  => $scheme . '://' . $host . $path,
        'host' => $host,
        'path' => $path,
    ];
}

/**
 * isla_s3_send()
 * Signs and performs one request. Every failure is returned as a
 * reason, never thrown and never swallowed: the caller decides
 * whether it is fatal (an upload) or best-effort (a delete), and
 * error_log() gets the detail either way.
 *
 * @param string $method      'PUT' or 'DELETE'.
 * @param string $key         Object key.
 * @param string $body        Request body ('' for DELETE).
 * @param string $contentType Stored content type ('' to send none).
 * @return array{ok:bool,status:int,error:string,body:string}
 */
function isla_s3_send(string $method, string $key, string $body, string $contentType): array
{
    $missing = isla_s3_missing_config();
    if ($missing !== []) {
        return [
            'ok'     => false,
            'status' => 0,
            'error'  => 'Object storage is not configured; missing ' . implode(', ', $missing) . '.',
            'body'   => '',
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'ok'     => false,
            'status' => 0,
            'error'  => 'The cURL extension is required for object storage.',
            'body'   => '',
        ];
    }

    $cfg         = isla_s3_config();
    $target      = isla_s3_target($key, $cfg);
    $payloadHash = hash('sha256', $body);
    $amzDate     = gmdate('Ymd\THis\Z');

    // Only these three headers are signed. Content-Type is sent but
    // left out of SignedHeaders, which SigV4 allows (and which means
    // a host that normalises it cannot invalidate the signature).
    $signed = [
        'host'                 => $target['host'],
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date'           => $amzDate,
    ];

    $authorization = isla_s3_authorization(
        $method,
        $target['path'],
        '',
        $signed,
        $payloadHash,
        $amzDate,
        $cfg['region'],
        ISLA_S3_SERVICE,
        $cfg['key'],
        $cfg['secret']
    );

    $httpHeaders = [
        'Authorization: ' . $authorization,
        'x-amz-content-sha256: ' . $payloadHash,
        'x-amz-date: ' . $amzDate,
        'Host: ' . $target['host'],
    ];
    if ($contentType !== '') {
        $httpHeaders[] = 'Content-Type: ' . $contentType;
    }

    $ch = curl_init($target['url']);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $httpHeaders,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $ok = ($curlErr === '' && $status >= 200 && $status < 300);

    return [
        'ok'     => $ok,
        'status' => $status,
        'error'  => $curlErr,
        'body'   => is_string($response) ? $response : '',
    ];
}

/**
 * isla_s3_put()
 * Stores $body under $key, replacing any object already there.
 *
 * @param string $key         Object key.
 * @param string $body        Raw bytes.
 * @param string $contentType Content type to store with the object.
 * @return array{ok:bool,status:int,error:string,body:string}
 */
function isla_s3_put(string $key, string $body, string $contentType = 'application/octet-stream'): array
{
    return isla_s3_send('PUT', $key, $body, $contentType);
}

/**
 * isla_s3_get()
 * Reads an object's bytes. It goes through the SAME signed request
 * as the writes above — only the verb changes — so the read path
 * inherits the SigV4 implementation the tests already pin to AWS's
 * published examples (the GET Object case among them), rather than
 * introducing a second, unverified signer.
 *
 * It exists for public/image.php. A bucket with no public URL of its
 * own cannot be read by a browser, but it can be read by whoever
 * holds the credentials, and the app does. That signed read is what
 * lets an upload be stored and displayed even when AWS_URL was never
 * set — the variable is a fast path (and a CDN's cache), not a
 * requirement.
 *
 * @param string $key Object key (bare filename here).
 * @return array{ok:bool,status:int,error:string,body:string}
 */
function isla_s3_get(string $key): array
{
    return isla_s3_send('GET', $key, '', '');
}

/**
 * isla_s3_delete()
 * Removes $key. A key that is already gone answers 204 on most
 * S3-compatible services (it is not an error), so callers only
 * need to look at `ok` for real failures.
 *
 * @param string $key Object key.
 * @return array{ok:bool,status:int,error:string,body:string}
 */
function isla_s3_delete(string $key): array
{
    return isla_s3_send('DELETE', $key, '', '');
}
