<?php
declare(strict_types=1);

/**
 * Everafter Planner — Cron Bridge
 * ===============================
 * For hosts whose plan has NO Cron Jobs icon (or as a backup to cron):
 * an external pinger hits this URL on a schedule and the jobs run here.
 *
 *   Free pingers that work:  cron-job.org (recommended, 1-min granularity),
 *   UptimeRobot (free 5-min HTTP monitor), GitHub Actions schedule, etc.
 *
 * SETUP
 *   1. Choose a long random key. Either set WPMS_CRON_BRIDGE_KEY in .env
 *      (preferred) or replace CHANGE_ME below.
 *   2. In your pinger create ONE monitor:
 *         https://yourdomain.co.ke/cron_bridge.php?key=YOUR_KEY&job=all
 *      every 15 minutes. job=all runs each job on its own schedule
 *      (mail retry ~every 15 min, the daily jobs once a day) — the
 *      per-job throttle below does the scheduling, not the pinger.
 *   3. Optional manual runs in the browser (same URL, different job):
 *         &job=status          last-run dashboard (no jobs run)
 *         &job=mail_retry      force one job now (add &force=1 to bypass
 *                              the throttle)
 *         &job=job_watch       judge every scheduled job's heartbeat and tell
 *                              the operator when one of them has gone quiet
 *
 * SAFETY MODEL
 *   - The key is required on EVERY call (constant-time compare); with the
 *     placeholder key still set the whole endpoint refuses to run.
 *   - Only the whitelisted job names below can run — the request can never
 *     name an arbitrary script.
 *   - Per-job minimum intervals stop an over-eager pinger from re-running
 *     jobs (SMS jobs cost credits; the backup fills disk). &force=1
 *     overrides for a manual run.
 *   - A single-flight lock makes two overlapping requests serialize: the
 *     second returns BUSY (HTTP 200, not a failure) instead of racing.
 *   - Every underlying script keeps its own single-flight lock too, so even
 *     a race cannot double-send; when that inner lock is already held the
 *     job is reported SKIP (benign), never FAIL.
 *   - A long-running job (db backup) may outlive the pinger's timeout —
 *     the job still completes server-side; ignore that one "timed out"
 *     report, &job=status shows the truth.
 *
 * FAILURE REPORTING
 *   - Each run prints one line per job: OK / SKIP / FAIL with the real exit
 *     code and the first 160 chars of output.
 *   - job=all ends with a RESULT summary (ok=/skip=/fail=). If any job
 *     FAILED the response is HTTP 500 (2xx otherwise), so a keyword- or
 *     status-monitoring pinger alerts you on real breakage only.
 *   - exit=1 with "holds the lock" is classified SKIP, not FAIL.
 */

const CRON_BRIDGE_KEY = 'CHANGE_ME_TO_A_LONG_RANDOM_STRING';

// name => [script, min interval in minutes]
const JOBS = [
    'mail_retry'            => ['scripts/mail_retry.php',            10],
    'rsvp_reminders'        => ['scripts/rsvp_reminders.php',        1380], // ~23 h
    'deadline_sms'          => ['scripts/deadline_sms.php',          1380],
    'notify_prune'          => ['scripts/notify_prune.php',          1380],
    'subscription_renewals' => ['scripts/subscription_renewals.php', 1380],
    // v2.19: closes Tier-3 MCP approval requests whose 30-minute window passed
    // unanswered, rings the planner once and records it for the assistant. Every
    // 10 minutes, like mail_retry — the window it closes is half an hour, so a
    // daily tick would tell a planner about a dead SMS request a day later.
    'mcp_approval_sweep'    => ['scripts/mcp_approval_sweep.php',    10],
    // The owner's weekly brief. Daily, like the other daily jobs: the script
    // itself decides whether today is Monday and whether this week was already
    // delivered, so a skipped Monday is caught the next day instead of costing
    // the owner a whole week's brief.
    'business_pulse_email'  => ['scripts/business_pulse_email.php',  1380],
    // v2.26: the couple's planner is told when a vendor has left the thread
    // unanswered for a day. Daily, like the other daily jobs — the trigger is a
    // day of silence, so a faster tick would only re-ask a question nobody has
    // had the chance to answer. The script stamps each message it reports, so a
    // second pinger cannot ring the same bell twice.
    'thread_escalation'     => ['scripts/thread_escalation.php',     1380],
    'db_backup'             => ['scripts/db_backup.sh',              1380],
    // The watchdog (v2.15). It reads the heartbeats the jobs above leave, so it
    // runs LAST — and it exits 1 when one of them is silent,
    // failing, or ran on time without doing its work, which is what turns this
    // response into HTTP 500 and makes the pinger that runs the jobs report
    // their silence too. Without it, a scheduler whose jobs stopped looks
    // exactly like a scheduler with nothing to do.
    'job_watch'             => ['scripts/job_watch.php',             60],
];
// job=all runs every real job; each one only when its interval has elapsed.
const ALL_JOBS = ['mail_retry', 'rsvp_reminders', 'deadline_sms', 'notify_prune', 'subscription_renewals', 'mcp_approval_sweep', 'business_pulse_email', 'thread_escalation', 'db_backup', 'job_watch'];

const STATE_FILE = __DIR__ . '/.cron_bridge_state.json';
const LOCK_FILE  = __DIR__ . '/.cron_bridge_state.lock';

header('Content-Type: text/plain; charset=utf-8');

/* ---------------------------------------------------------------- auth -- */
$given = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
$given = is_string($given) ? $given : '';
$expected = (string) (getenv('WPMS_CRON_BRIDGE_KEY') ?: CRON_BRIDGE_KEY);
if ($expected === '' || $expected === 'CHANGE_ME_TO_A_LONG_RANDOM_STRING'
        || !hash_equals($expected, $given)) {
    http_response_code(403);
    exit("Forbidden — set WPMS_CRON_BRIDGE_KEY in .env (or CRON_BRIDGE_KEY in this file), then call with ?key=...\n");
}

/* ------------------------------------------------ single-flight lock -- */
// Hold the lock for the whole request so two pingers can never run jobs at
// once. A second concurrent request returns BUSY (200) instead of racing.
$lock = @fopen(LOCK_FILE, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("BUSY: another cron_bridge run is in progress — this request did nothing.\n");
}

/* --------------------------------------------------------------- state -- */
function state_load(): array
{
    $raw = @file_get_contents(STATE_FILE);
    $s = $raw === false ? [] : json_decode($raw, true);
    return is_array($s) ? $s : [];
}
function state_save(array $s): void
{
    // Atomic: write a temp file then rename, so a crash mid-write can never
    // leave a truncated/corrupt state file behind.
    $json = json_encode($s, JSON_PRETTY_PRINT);
    if ($json === false) {
        return;
    }
    $tmp = STATE_FILE . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, STATE_FILE);
    } else {
        @unlink($tmp);
    }
}

/* ------------------------------------------------------- exec plumbing -- */
function disable_list(): array
{
    $ini = (string) ini_get('disable_functions');
    return array_map('trim', explode(',', strtolower($ini)));
}

function exec_fn(): string
{
    $disabled = disable_list();
    foreach (['proc_open', 'exec', 'shell_exec', 'passthru', 'system'] as $fn) {
        if (function_exists($fn) && !in_array($fn, $disabled, true)) {
            return $fn;
        }
    }
    return '';
}

/**
 * Run a command line through the first available exec function.
 * Always returns a RELIABLE exit code (0 = success) and the combined
 * stdout+stderr, or a leading "ERROR: ..." string with code 1 when this
 * host has no usable exec function.
 */
function run_cmd(string $cmd, ?int &$code = null): string
{
    $fn = exec_fn();
    $code = 1;
    if ($fn === '') {
        return 'ERROR: no exec function available on this host (disable_functions)';
    }

    if ($fn === 'proc_open') {
        $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open($cmd, $spec, $pipes);
        if (is_resource($p)) {
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = (int) proc_close($p);
        }
        return $out;
    }
    if ($fn === 'exec') {
        $lines = [];
        exec($cmd . ' 2>&1', $lines, $code);
        return implode("\n", $lines);
    }
    if ($fn === 'shell_exec') {
        // shell_exec has no exit code of its own — append a sentinel so a
        // genuine non-zero exit is reported instead of guessed from output.
        $raw = (string) shell_exec($cmd . ' 2>&1; printf "\n__WPMS_EXIT__%d" $?');
        if (preg_match('/\n__WPMS_EXIT__(\d+)\s*$/', $raw, $m)) {
            $code = (int) $m[1];
            $raw  = substr($raw, 0, -strlen($m[0]));
        }
        return $raw;
    }
    // passthru / system
    ob_start();
    if ($fn === 'passthru') {
        passthru($cmd . ' 2>&1', $code);
    } else {
        system($cmd . ' 2>&1', $code);
    }
    return (string) ob_get_clean();
}

/** Locate a working PHP CLI binary (cached in the state file). */
function php_bin(array &$state): string
{
    if (!empty($state['php_bin'])) {
        return (string) $state['php_bin'];
    }
    $candidates = [PHP_BINARY, 'php', '/usr/local/bin/php', '/usr/bin/php'];
    foreach ($candidates as $bin) {
        if ($bin === '' || $bin === false) {
            continue;
        }
        $out = run_cmd(escapeshellarg($bin) . ' -v', $code);
        if ($code === 0 && stripos($out, 'php') !== false) {
            $state['php_bin'] = $bin; // cache only on success
            return $bin;
        }
    }
    return '';
}

/* ------------------------------------------------------------ job run -- */
/**
 * @return array{status:string,line:string} status is OK, SKIP or FAIL.
 */
function run_job(string $name, array $jobs, array &$state, bool $force): array
{
    [$rel, $interval] = $jobs[$name];
    $script = __DIR__ . '/' . $rel;
    if (!is_file($script)) {
        return ['status' => 'FAIL', 'line' => "FAIL {$name}: script missing ({$rel})"];
    }

    $now = time();
    $last = $state['jobs'][$name] ?? [];
    $elapsed = isset($last['ts']) ? $now - (int) $last['ts'] : PHP_INT_MAX;
    if (!$force && $elapsed < $interval * 60) {
        $mins = (int) ceil(($interval * 60 - $elapsed) / 60);
        return ['status' => 'SKIP', 'line' => "SKIP {$name}: ran " . max(1, (int) round($elapsed / 60))
            . " min ago — next eligible in ~{$mins} min (&force=1 to override)"];
    }

    // db_backup is a shell script; every other job needs the PHP CLI.
    if ($name === 'db_backup') {
        $bin = '';
        $cmd = 'bash ' . escapeshellarg($script) . ' --quiet';
    } else {
        $bin = php_bin($state);
        if ($bin === '') {
            return ['status' => 'FAIL', 'line' => "FAIL {$name}: no PHP CLI binary found on this host"];
        }
        $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($script) . ' --quiet';
    }

    @set_time_limit(300);
    @ignore_user_abort(true);

    $t0   = microtime(true);
    $out  = run_cmd($cmd, $code);
    $ms   = (int) round((microtime(true) - $t0) * 1000);
    $note = mb_substr(trim((string) $out), 0, 200);

    $state['jobs'][$name] = [
        'ts'   => $now,
        'exit' => $code,
        'ms'   => $ms,
        'note' => $note,
    ];

    if ($code === 0) {
        return ['status' => 'OK', 'line' => sprintf('OK %s: exit=0 (%d ms)', $name, $ms)];
    }

    // Benign concurrency: the script's own single-flight lock was held by a
    // concurrent run. That is not a failure — report it as a skip.
    if ($code === 1 && preg_match('/holds the lock/i', (string) $out)) {
        return ['status' => 'SKIP', 'line' => "SKIP {$name}: another run held the lock (exit=1, {$ms} ms)"];
    }

    return ['status' => 'FAIL', 'line' => sprintf('FAIL %s: exit=%d (%d ms) — %s',
        $name, $code, $ms, mb_substr(trim((string) $out), 0, 160))];
}

/* --------------------------------------------------------------- main -- */
$state = state_load();
$job   = $_GET['job'] ?? 'status';
$job   = is_string($job) ? $job : '';
$force = isset($_GET['force']);
$lines = [];
$fail  = 0;
$skip  = 0;
$ok    = 0;
$statusCode = null; // set explicitly for request-level errors (e.g. 400)

if ($job === 'status') {
    $lines[] = 'Everafter Planner cron bridge — status';
    foreach (ALL_JOBS as $name) {
        $l = $state['jobs'][$name] ?? null;
        if ($l === null) {
            $lines[] = sprintf('%-22s never run', $name);
        } else {
            $lines[] = sprintf('%-22s last %s  exit=%d  (%d ms)%s', $name,
                date('Y-m-d H:i', (int) $l['ts']), (int) $l['exit'], (int) $l['ms'],
                ((int) $l['exit'] === 0) ? '' : '  <-- last run FAILED');
        }
    }
    $lines[] = 'bridge PHP: ' . (php_bin($state) ?: 'NOT FOUND');
    state_save($state); // persist the php_bin cache so we do not re-probe
} elseif ($job === 'all') {
    foreach (ALL_JOBS as $name) {
        $r = run_job($name, JOBS, $state, $force);
        $lines[] = $r['line'];
        if ($r['status'] === 'FAIL') {
            $fail++;
        } elseif ($r['status'] === 'SKIP') {
            $skip++;
        } else {
            $ok++;
        }
    }
    $lines[] = sprintf('RESULT: ok=%d skip=%d fail=%d', $ok, $skip, $fail);
    state_save($state);
} elseif (isset(JOBS[$job])) {
    $r = run_job($job, JOBS, $state, $force);
    $lines[] = $r['line'];
    if ($r['status'] === 'FAIL') {
        $fail = 1;
    }
    state_save($state);
} else {
    $statusCode = 400;
    $lines[] = "Unknown job '{$job}'. Valid: status, all, " . implode(', ', array_keys(JOBS));
}

// Only a real FAILED job turns this into an HTTP error, so a status-code
// pinger alerts on breakage and stays quiet on skips. An explicit
// request-level error (unknown job) takes precedence.
http_response_code($statusCode ?? ($fail > 0 ? 500 : 200));
echo implode("\n", $lines) . "\n";
