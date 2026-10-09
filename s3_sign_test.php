<?php
// ============================================================
// s3_sign_test.php — proves the SigV4 signer is correct.
//
// WHY THIS TEST EXISTS
// The object-storage driver (include/s3.php) cannot be exercised
// without a live bucket and credentials, and its failure mode is
// invisible until the moment a user saves a picture: a
// 403 SignatureDoesNotMatch. That is not an acceptable thing to
// ship unverified.
//
// So the signer is checked against the only authority that does not
// need a bucket — AWS's own published example calculations. Four
// complete worked examples appear in the S3 developer guide
// ("Signature Calculations for the Authorization Header: Transferring
// Payload in a Single Chunk"), each with a fixed request and the exact
// signature AWS expects. The access keys there are AWS's public
// example credentials, not real ones.
//
//   GET Object            f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41
//   PUT Object            98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd
//   GET Bucket Lifecycle  fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543
//   Get Bucket (List)     34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7
//
// If every one matches, the canonical request, the string to sign,
// the signing-key derivation and the header ordering are all right —
// the parts that are identical whether the bucket is S3 or R2. What
// this cannot prove is the network round trip or a host-specific
// quirk (R2's region is "auto", its bucket-level visibility), and it
// says so instead of pretending otherwise.
//
// Dependency-free and needs no database, like the other smoke tests:
//   php s3_sign_test.php        (exit 0 = pass, 1 = fail)
// ============================================================

require_once __DIR__ . '/include/s3.php';

$failures = 0;
$checks   = 0;

/**
 * One assertion, in the shared smoke-test style: print, count, never
 * throw, so a run reports every failure it finds instead of stopping
 * at the first.
 */
function check(string $label, string $expected, string $actual): void
{
    global $failures, $checks;
    $checks++;

    if ($expected === $actual) {
        echo "  PASS  $label\n";
        return;
    }

    $failures++;
    echo "  FAIL  $label\n";
    echo "        expected: $expected\n";
    echo "        actual:   $actual\n";
}

/** The signature part of an Authorization header value. */
function signature_of(string $authorization): string
{
    if (preg_match('/Signature=([0-9a-f]+)/', $authorization, $m)) {
        return $m[1];
    }
    return '(no signature in: ' . $authorization . ')';
}

/** The SignedHeaders part of an Authorization header value. */
function signed_headers_of(string $authorization): string
{
    if (preg_match('/SignedHeaders=([^,]+)/', $authorization, $m)) {
        return $m[1];
    }
    return '(no SignedHeaders in: ' . $authorization . ')';
}

// AWS's public example credentials and timestamp (all four cases share
// them), so the expected signatures below are reproducible.
$accessKey = 'AKIAIOSFODNN7EXAMPLE';
$secretKey = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
$region    = 'us-east-1';
$amzDate   = '20130524T000000Z';
$emptyHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

echo "SigV4 — AWS documented example calculations\n";

// --- Example 1: GET Object -------------------------------------
// GET /test.txt, virtual-hosted, with a Range header.
$auth = isla_s3_authorization(
    'GET',
    '/test.txt',
    '',
    [
        'host'                 => 'examplebucket.s3.amazonaws.com',
        'range'                => 'bytes=0-9',
        'x-amz-content-sha256' => $emptyHash,
        'x-amz-date'           => $amzDate,
    ],
    $emptyHash,
    $amzDate,
    $region,
    's3',
    $accessKey,
    $secretKey
);
check('GET Object signature', 'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', signature_of($auth));
check('GET Object SignedHeaders', 'host;range;x-amz-content-sha256;x-amz-date', signed_headers_of($auth));

// --- Example 2: PUT Object -------------------------------------
// PUT /test$file.text with a body, a Date header and a storage class.
// The path arrives pre-encoded ('$' -> %24), which is also a check of
// the URI encoding used to build real keys.
$putBody = 'Welcome to Amazon S3.';
$putHash = '44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072';

check('PUT Object payload hash', $putHash, hash('sha256', $putBody));

$auth = isla_s3_authorization(
    'PUT',
    '/test%24file.text',
    '',
    [
        'date'                 => 'Fri, 24 May 2013 00:00:00 GMT',
        'host'                 => 'examplebucket.s3.amazonaws.com',
        'x-amz-content-sha256' => $putHash,
        'x-amz-date'           => $amzDate,
        'x-amz-storage-class'  => 'REDUCED_REDUNDANCY',
    ],
    $putHash,
    $amzDate,
    $region,
    's3',
    $accessKey,
    $secretKey
);
check('PUT Object signature', '98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd', signature_of($auth));
check(
    'PUT Object SignedHeaders',
    'date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class',
    signed_headers_of($auth)
);

// --- Example 3: GET Bucket Lifecycle ---------------------------
// GET /?lifecycle — a subresource, i.e. a query string with an empty
// value ('lifecycle='). This is the case a naive query builder gets
// wrong.
$auth = isla_s3_authorization(
    'GET',
    '/',
    'lifecycle=',
    [
        'host'                 => 'examplebucket.s3.amazonaws.com',
        'x-amz-content-sha256' => $emptyHash,
        'x-amz-date'           => $amzDate,
    ],
    $emptyHash,
    $amzDate,
    $region,
    's3',
    $accessKey,
    $secretKey
);
check('GET Bucket Lifecycle signature', 'fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543', signature_of($auth));

// --- Example 4: Get Bucket (List Objects) ----------------------
// GET /?max-keys=2&prefix=J — two query parameters.
$auth = isla_s3_authorization(
    'GET',
    '/',
    'max-keys=2&prefix=J',
    [
        'host'                 => 'examplebucket.s3.amazonaws.com',
        'x-amz-content-sha256' => $emptyHash,
        'x-amz-date'           => $amzDate,
    ],
    $emptyHash,
    $amzDate,
    $region,
    's3',
    $accessKey,
    $secretKey
);
check('Get Bucket (List Objects) signature', '34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', signature_of($auth));

// --- URI encoding ----------------------------------------------
// The rules that differ from rawurlencode()/urlencode() and would
// otherwise change the signature silently.
echo "UriEncode\n";
check('encodes $', 'test%24file.text', isla_s3_uri_encode('test$file.text', false));
check('keeps unreserved set', 'Aa0-._~', isla_s3_uri_encode('Aa0-._~', false));
check('space is %20, not +', 'a%20b', isla_s3_uri_encode('a b', false));
check('keeps / in a key', 'user/5/photo.jpg', isla_s3_uri_encode('user/5/photo.jpg', false));
check('encodes / when asked', 'user%2F5%2Fphoto.jpg', isla_s3_uri_encode('user/5/photo.jpg', true));
check('uppercase hex', '%2B', isla_s3_uri_encode('+', false));

// --- Object location -------------------------------------------
// The request URL and the canonical URI must come from one string;
// this is what a bad signed request looks like when they diverge.
echo "Object location\n";
$cfg = [
    'bucket'     => 'examplebucket',
    'endpoint'   => 'https://abc123.r2.cloudflarestorage.com',
    'region'     => 'auto',
    'key'        => 'x',
    'secret'     => 'y',
    'path_style' => true,
];
$target = isla_s3_target('user_42_deadbeefcafebabe.jpg', $cfg);
check('path-style URL', 'https://abc123.r2.cloudflarestorage.com/examplebucket/user_42_deadbeefcafebabe.jpg', $target['url']);
check('path-style canonical path', '/examplebucket/user_42_deadbeefcafebabe.jpg', $target['path']);
check('path-style host', 'abc123.r2.cloudflarestorage.com', $target['host']);

$cfg['path_style'] = false;
$target = isla_s3_target('user_42_deadbeefcafebabe.jpg', $cfg);
check('virtual-hosted URL', 'https://examplebucket.abc123.r2.cloudflarestorage.com/user_42_deadbeefcafebabe.jpg', $target['url']);
check('virtual-hosted canonical path', '/user_42_deadbeefcafebabe.jpg', $target['path']);
check('virtual-hosted host', 'examplebucket.abc123.r2.cloudflarestorage.com', $target['host']);

// --- Summary ----------------------------------------------------
echo "\n$checks checks, $failures failure(s)\n";

if ($failures > 0) {
    exit(1);
}
exit(0);
