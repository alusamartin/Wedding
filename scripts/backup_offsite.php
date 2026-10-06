<?php
declare(strict_types=1);

/**
 * Everafter Planner — Weekly OFF-SITE backup upload (S3-compatible)
 * =================================================================
 * Copies the newest local backup (from scripts/db_backup.sh) to an
 * S3-compatible object store, so backups survive a total server loss:
 *
 *   Cloudflare R2   10 GB free, ZERO egress fees   <- recommended
 *   Backblaze B2    10 GB free (S3-compatible endpoint)
 *   AWS S3          5 GB free tier
 *   (Wasabi, iDrive E2, Minio — anything speaking the S3 API works)
 *
 * SETUP (once, in .env):
 *   WPMS_S3_BUCKET=my-bucket-name
 *   WPMS_S3_REGION=auto            (R2/B2: auto; AWS: e.g. us-east-1)
 *   WPMS_S3_ENDPOINT=https://<accountid>.r2.cloudflarestorage.com
 *   WPMS_S3_ACCESS_KEY=...
 *   WPMS_S3_SECRET_KEY=...
 *   WPMS_S3_PREFIX=everafter       (folder inside the bucket)
 *   WPMS_OFFSITE_KEEP=8            (objects kept remotely; weekly runs ≈ 8 weeks)
 *   WPMS_BACKUP_DIR=...            (optional override of the local backup dir)
 *
 * CRON (weekly, alongside the other jobs):
 *   0 3 * * 1  php /home/USER/public_html/scripts/backup_offsite.php --quiet
 * (The cron bridge also knows this job as `backup_offsite`.)
 *
 * BEHAVIOUR
 *   - Uploads the NEWEST *.sql.gz in the local backup dir (or --file=<path>).
 *   - Verifies the upload server-side (HEAD, size match) before pruning.
 *   - Remote retention: deletes the oldest objects beyond WPMS_OFFSITE_KEEP.
 *   - Without S3 credentials: deliberate no-op, exit 0 (cron-safe).
 *   - Single-flight lock; logs to offsite.log next to the backups.
 */

require_once __DIR__ . '/lib_sigv4.php';

$options  = getopt('', ['quiet', 'file::', 'keep::']) ?: [];
$quiet    = isset($options['quiet']);
$explicit = (string) ($options['file'] ?? '');
$keepOpt  = isset($options['keep']) ? max(1, (int) $options['keep']) : null;

/* --------------------------------------------------------- env (no deps) */
function offsite_env(string $key, string $default = ''): string
{
    $v = getenv($key);
    if (is_string($v) && $v !== '') {
        return $v;
    }
    static $parsed = null;
    if ($parsed === null) {
        $parsed = [];
        $root   = dirname(__DIR__);
        $files  = [$root . '/.env', dirname($root) . '/.env'];
        $home   = getenv('HOME');
        if (is_string($home) && $home !== '') {
            $files[] = $home . '/.env';
        }
        foreach ($files as $f) {
            if (!is_file($f) || !is_readable($f)) {
                continue;
            }
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                $k   = trim(substr($line, 0, strpos($line, '=')));
                $val = trim(substr($line, strpos($line, '=') + 1));
                $len = strlen($val);
                if ($len >= 2
                    && (($val[0] === '"' && $val[$len - 1] === '"')
                        || ($val[0] === "'" && $val[$len - 1] === "'"))) {
                    $val = substr($val, 1, -1);
                }
                if ($k !== '' && !isset($parsed[$k])) {
                    $parsed[$k] = $val;
                }
            }
        }
    }
    return $parsed[$key] ?? $default;
}

/* --------------------------------------------------------------- helpers */
/** Parse a "Header: value" block into lowercase name => value array. */
function http_headers_of(string $raw): array
{
    $out = [];
    foreach (explode("\r\n", $raw) as $line) {
        if (str_contains($line, ':')) {
            [$n, $v] = explode(':', $line, 2);
            $out[strtolower(trim($n))] = trim($v);
        }
    }
    return $out;
}

/** Extract the ETag from a PutObject XML response. */
function etag_of(string $body): string
{
    return preg_match('~<ETag>\s*"?([^"<]+)"?\s*</ETag>~i', $body, $m) ? $m[1] : '';
}

/**
 * Single S3 API call over cURL. Returns [status, headers, body].
 * The request URL's query string is built by the SAME canonical encoder
 * the signature uses, so URL and signature can never disagree.
 */
function s3_call(
    string $method,
    string $url,
    array  $query,
    string $host,
    string $canonicalUri,
    string $payloadHash,
    string $body = ''
): array {
    $now     = gmdate('Ymd\THis\Z');
    $today   = substr($now, 0, 8);
    $headers = [
        'host'                 => $host,
        'x-amz-date'           => $now,
        'x-amz-content-sha256' => $payloadHash,
    ];
    if ($body !== '') {
        $headers['content-length'] = (string) strlen($body);
    }
    $auth = sigv4_auth_header(
        $method, $canonicalUri, $query, $headers, $payloadHash,
        offsite_env('WPMS_S3_ACCESS_KEY'), offsite_env('WPMS_S3_SECRET_KEY'),
        $now, $today, offsite_env('WPMS_S3_REGION', 'auto'), 's3'
    );

    $curlHeaders = ["Authorization: $auth", "x-amz-date: $now", "x-amz-content-sha256: $payloadHash"];
    foreach ($headers as $n => $v) {
        if ($n !== 'host' && $n !== 'content-length') { // curl sets content-length itself
            $curlHeaders[] = "$n: $v";
        }
    }

    $qs = sigv4_canonical_query($query);
    $ch = curl_init($url . ($qs !== '' ? '?' . $qs : ''));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_HTTPHEADER     => $curlHeaders,
    ]);
    if ($body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw   = (string) curl_exec($ch);
    $errno = curl_errno($ch);
    $info  = curl_getinfo($ch);   // MUST be read BEFORE curl_close
    curl_close($ch);
    if ($errno) {
        return [0, [], 'curl error ' . $errno];
    }
    $hlen = (int) ($info['header_size'] ?? 0);
    return [
        (int) ($info['http_code'] ?? 0),
        http_headers_of(substr($raw, 0, $hlen)),
        substr($raw, $hlen),
    ];
}

/* ------------------------------------------------------------------ main */
$root      = dirname(__DIR__);
$homeDir   = getenv('HOME');
$backupDir = offsite_env('WPMS_BACKUP_DIR',
    (is_string($homeDir) && $homeDir !== '' ? $homeDir : $root) . '/backups/everafter');
$prefix     = trim(offsite_env('WPMS_S3_PREFIX', 'everafter'), '/');
$keepRemote = $keepOpt ?? max(1, (int) offsite_env('WPMS_OFFSITE_KEEP', '8'));

$bucket   = offsite_env('WPMS_S3_BUCKET');
$endpoint = rtrim(offsite_env('WPMS_S3_ENDPOINT'), '/');
$ak       = offsite_env('WPMS_S3_ACCESS_KEY');
$sk       = offsite_env('WPMS_S3_SECRET_KEY');

if ($bucket === '' || $endpoint === '' || $ak === '' || $sk === '') {
    if (!$quiet) {
        echo "backup_offsite: no S3 credentials configured — off-site copy disabled (this is fine).\n";
    }
    exit(0);
}

/* lock + log -------------------------------------------------------------- */
if (!is_dir($backupDir)) {
    fwrite(STDERR, "backup_offsite: backup dir not found: $backupDir\n");
    exit(1);
}
$logPath = $backupDir . '/offsite.log';
$logf = function (string $msg) use ($logPath, $quiet): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    @file_put_contents($logPath, $line . "\n", FILE_APPEND);
    if (!$quiet) {
        echo $line . "\n";
    }
};
$lockFile = $backupDir . '/.offsite.lock';
$lock = @fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    $logf('SKIP: another run holds the lock');
    exit(0);
}

$fail = function (string $msg) use ($logf, $lock): never {
    $logf('FAIL: ' . $msg);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(1);
};

/* pick the local file ------------------------------------------------------ */
if ($explicit !== '') {
    $file = $explicit;
} else {
    $candidates = glob($backupDir . '/*.sql.gz') ?: [];
    if ($candidates === []) {
        $fail("no *.sql.gz found in $backupDir — run db_backup first");
    }
    usort($candidates, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $file = $candidates[0];
}
if (!is_file($file) || !is_readable($file)) {
    $fail("cannot read $file");
}
$size  = (int) filesize($file);
$name  = basename($file);
$key   = ($prefix !== '' ? $prefix . '/' : '') . $name;
$logf("uploading $name ($size bytes) → $bucket/$key");

/* upload (single read — dumps are a few MB) -------------------------------- */
$body     = (string) file_get_contents($file);
$bodyHash = hash('sha256', $body);
$host     = parse_url($endpoint, PHP_URL_HOST) ?? $fail('bad endpoint URL');
$url      = $endpoint . '/' . $bucket . '/' . $key;

[$status, $respHeaders, $respBody] = s3_call(
    'PUT', $url, [], $host, '/' . $bucket . '/' . $key, $bodyHash, $body
);
unset($body);
if ($status !== 200) {
    $fail("PUT failed HTTP $status: " . substr(trim($respBody), 0, 300));
}
$etag = etag_of($respBody);
if ($etag === '' && isset($respHeaders['etag'])) {
    $etag = trim($respHeaders['etag'], '"');
}
if ($etag === '') {
    $fail('PUT returned no ETag');
}

/* verify server-side (HEAD) — size must match before we prune anything ---- */
[$hStatus, $hHeaders] = s3_call('HEAD', $url, [], $host, '/' . $bucket . '/' . $key, EMPTY_SHA256);
if ($hStatus !== 200) {
    $fail("verification HEAD failed HTTP $hStatus");
}
$remoteLen = (int) ($hHeaders['content-length'] ?? 0);
if ($remoteLen !== $size) {
    $fail("size mismatch after upload: local $size vs remote $remoteLen");
}

/* remote retention ---------------------------------------------------------- */
[$lStatus, , $lBody] = s3_call(
    'GET',
    $endpoint . '/' . $bucket,
    ['list-type' => '2', 'prefix' => ($prefix !== '' ? $prefix . '/' : '')],
    $host,
    '/' . $bucket,
    EMPTY_SHA256
);
$remaining = 1; // the object we just uploaded
$deleted   = 0;
if ($lStatus === 200 && preg_match_all('~<Key>([^<]+)</Key>~', $lBody, $m)) {
    $objects = [];
    foreach ($m[1] as $k) {
        if (str_ends_with($k, '.sql.gz')) {
            $objects[] = $k;
        }
    }
    sort($objects); // same-prefix + timestamped names → oldest first
    $excess = count($objects) - $keepRemote;
    for ($i = 0; $i < $excess; $i++) {
        $old = $objects[$i];
        [$dStatus] = s3_call('DELETE', $endpoint . '/' . $bucket . '/' . $old, [], $host, '/' . $bucket . '/' . $old, EMPTY_SHA256);
        if ($dStatus === 204 || $dStatus === 200) {
            $deleted++;
        }
    }
    $remaining = count($objects) - $deleted;
}

$logf("OK: $name stored remotely (etag $etag, verified $remoteLen bytes); remote copies now $remaining, pruned $deleted");
flock($lock, LOCK_UN);
fclose($lock);
exit(0);
