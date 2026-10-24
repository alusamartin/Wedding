<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * AWS Signature Version 4 — minimal pure-PHP signer (no dependencies).
 *
 * The crypto core lives here so it can be unit-verified against the
 * official AWS documentation test vector ("GET Object" example from
 * "Signature Calculations for the Authorization Header") in isolation.
 * sigv4_selftest() asserts EVERY documented intermediate stage:
 * canonical request text -> its SHA-256 -> string-to-sign -> signature.
 */

/** SHA-256 of the empty string (GET/HEAD/DELETE payloads). */
const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

/**
 * S3-style canonical URI: encode each segment once, keep "/" literal.
 */
function sigv4_canonical_uri(string $path): string
{
    $path = '/' . ltrim($path, '/');
    $segments = explode('/', $path);
    foreach ($segments as $i => $seg) {
        if ($seg !== '') {
            $segments[$i] = str_replace('%2F', '/', rawurlencode($seg));
        }
    }
    return implode('/', $segments);
}

/**
 * Canonical query string: keys+values URI-encoded once, '/' kept literal,
 * sorted by key name (byte order).
 */
function sigv4_canonical_query(array $query): string
{
    uksort($query, 'strcmp');
    $pairs = [];
    foreach ($query as $k => $v) {
        $pairs[] = str_replace('%2F', '/', rawurlencode((string) $k))
            . '=' . str_replace('%2F', '/', rawurlencode((string) $v));
    }
    return implode('&', $pairs);
}

/**
 * Build the canonical request and string-to-sign (exposed for testing).
 * $headers: lowercase name => value, exactly the set being signed.
 *
 * @return array{0:string,1:string,2:string} [canonicalRequest, stringToSign, signedHeaders]
 */
function sigv4_string_to_sign(
    string $method,
    string $canonicalUri,
    array  $query,
    array  $headers,
    string $payloadHash,
    string $amzDate,
    string $date,
    string $region,
    string $service
): array {
    ksort($headers);
    $canonicalHeaders = '';
    $signedNames      = [];
    foreach ($headers as $name => $value) {
        $name = strtolower($name);
        $canonicalHeaders .= $name . ':' . trim((string) $value) . "\n";
        $signedNames[]    = $name;
    }
    sort($signedNames);
    $signedHeaders = implode(';', $signedNames);

    $canonicalRequest = implode("\n", [
        $method,
        sigv4_canonical_uri($canonicalUri),
        sigv4_canonical_query($query),
        $canonicalHeaders,
        $signedHeaders,
        $payloadHash,
    ]);

    $scope = implode('/', [$date, $region, $service, 'aws4_request']);
    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $scope,
        hash('sha256', $canonicalRequest),
    ]);

    return [$canonicalRequest, $stringToSign, $signedHeaders];
}

/** Full Authorization header for a request. */
function sigv4_auth_header(
    string $method,
    string $canonicalUri,
    array  $query,
    array  $headers,
    string $payloadHash,
    string $accessKey,
    string $secretKey,
    string $amzDate,
    string $date,
    string $region,
    string $service
): string {
    [, $stringToSign] = sigv4_string_to_sign(
        $method, $canonicalUri, $query, $headers, $payloadHash,
        $amzDate, $date, $region, $service
    );

    $kDate    = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
    $kRegion  = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    [, , $signedHeaders] = sigv4_string_to_sign(
        $method, $canonicalUri, $query, $headers, $payloadHash,
        $amzDate, $date, $region, $service
    );

    $scope = implode('/', [$date, $region, $service, 'aws4_request']);

    return 'AWS4-HMAC-SHA256 '
        . 'Credential=' . $accessKey . '/' . $scope
        . ', SignedHeaders=' . $signedHeaders
        . ', Signature=' . $signature;
}

/**
 * Self-test against the official AWS docs "GET Object" example:
 * https://docs.aws.amazon.com/AmazonS3/latest/API/sig-v4-header-based-auth.html
 *
 * Every asserted constant below is quoted from that page.
 */
function sigv4_selftest(): bool
{
    $amzDate = '20130524T000000Z';
    $date    = '20130524';
    $headers = [
        'host'                 => 'examplebucket.s3.amazonaws.com',
        'range'                => 'bytes=0-9',
        'x-amz-content-sha256' => EMPTY_SHA256,
        'x-amz-date'           => $amzDate,
    ];

    [$canonicalRequest, $stringToSign, $signedHeaders] = sigv4_string_to_sign(
        'GET', '/test.txt', [], $headers, EMPTY_SHA256,
        $amzDate, $date, 'us-east-1', 's3'
    );

    // 1) Canonical request — quoted verbatim from the docs page
    $expectedCanonical = "GET\n"
        . "/test.txt\n"
        . "\n"
        . "host:examplebucket.s3.amazonaws.com\n"
        . "range:bytes=0-9\n"
        . "x-amz-content-sha256:" . EMPTY_SHA256 . "\n"
        . "x-amz-date:20130524T000000Z\n"
        . "\n"
        . "host;range;x-amz-content-sha256;x-amz-date\n"
        . EMPTY_SHA256;
    if ($canonicalRequest !== $expectedCanonical) {
        return false;
    }

    // 2) Hash of the canonical request
    if (hash('sha256', $canonicalRequest)
        !== '7344ae5b7ee6c3e7e6b0fe0640412a37625d1fbfff95c48bbb2dc43964946972') {
        return false;
    }

    // 3) Signed headers fragment
    if ($signedHeaders !== 'host;range;x-amz-content-sha256;x-amz-date') {
        return false;
    }

    // 4) String to sign — quoted verbatim from the docs page
    $expectedSts = "AWS4-HMAC-SHA256\n"
        . "20130524T000000Z\n"
        . "20130524/us-east-1/s3/aws4_request\n"
        . "7344ae5b7ee6c3e7e6b0fe0640412a37625d1fbfff95c48bbb2dc43964946972";
    if ($stringToSign !== $expectedSts) {
        return false;
    }

    // 5) Final signature (Authorization header) — quoted from the docs page
    $auth = sigv4_auth_header(
        'GET', '/test.txt', [], $headers, EMPTY_SHA256,
        'AKIAIOSFODNN7EXAMPLE',
        'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        $amzDate, $date, 'us-east-1', 's3'
    );

    return str_contains(
        $auth,
        'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41'
    );
}
