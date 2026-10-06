<?php
declare(strict_types=1);

/**
 * Minimal S3-compatible test double — for local verification of
 * scripts/backup_offsite.php ONLY. Never deploy this.
 *
 * Run:  php -S 127.0.0.1:8899 tests/fake_s3_server.php
 * Stop: curl http://127.0.0.1:8899/__shutdown
 * Store: env FAKE_S3_ROOT (default <temp>/fake_s3_data)
 *
 * Supported: PUT /b/key (returns ETag), HEAD /b/key (Content-Length),
 * GET /b?list-type=2&prefix= (ListBucketResult), DELETE /b/key (204),
 * GET /__shutdown (stops the server).
 */

$root   = getenv('FAKE_S3_ROOT') ?: sys_get_temp_dir() . '/fake_s3_data';
$uri    = $_SERVER['REQUEST_URI'] ?? '/';
$path   = parse_url($uri, PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/__shutdown') {
    echo 'bye';
    exit; // ends php -S in single-process mode
}

if (!is_dir($root)) {
    mkdir($root, 0777, true);
}

$parts  = explode('/', ltrim($path, '/'), 2);
$bucket = $parts[0] ?? '';
$key    = $parts[1] ?? '';
$fsKey  = $root . '/' . $bucket . '/' . $key;

if ($method === 'PUT') {
    if ($key === '') {
        http_response_code(400);
        exit('no key');
    }
    @mkdir(dirname($fsKey), 0777, true);
    file_put_contents($fsKey, (string) file_get_contents('php://input'));
    http_response_code(200);
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><PutObjectResult><ETag>"fake-etag"</ETag></PutObjectResult>';
    exit;
}

if ($method === 'HEAD') {
    if (is_file($fsKey)) {
        header('Content-Length: ' . filesize($fsKey));
        http_response_code(200);
    } else {
        http_response_code(404);
    }
    exit;
}

if ($method === 'DELETE') {
    if (is_file($fsKey)) {
        unlink($fsKey);
        http_response_code(204);
    } else {
        http_response_code(404);
    }
    exit;
}

if ($method === 'GET' && $key === '') {
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
    $prefix = (string) ($q['prefix'] ?? '');
    $dir    = $root . '/' . $bucket;
    $out    = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult>';
    foreach (glob($dir . '/' . $prefix . '*') ?: [] as $f) {
        if (is_file($f)) {
            $k = ltrim(substr($f, strlen($dir)), '/');
            $out .= '<Contents><Key>' . htmlspecialchars($k, ENT_XML1) . '</Key>'
                . '<LastModified>' . date('c', (int) filemtime($f)) . '</LastModified>'
                . '<Size>' . filesize($f) . '</Size></Contents>';
        }
    }
    $out .= '</ListBucketResult>';
    header('Content-Type: application/xml');
    echo $out;
    exit;
}

http_response_code(400);
echo 'unsupported request';
