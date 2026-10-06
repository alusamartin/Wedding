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
 *
 * SAFETY MODEL
 *   - The key is required on EVERY call (constant-time compare); with the
 *     placeholder key still set the whole endpoint refuses to run.
 *   - Only the whitelisted job names below can run — the request can never
 *     name an arbitrary script.
 *   - Per-job minimum intervals stop an over-eager pinger from re-running
 *     jobs (SMS jobs cost credits; the backup fills disk). &force=1
 *     overrides for a manual run.
 *   - Every underlying script keeps its own single-flight lock and
 *     idempotency markers, so even a race between two pingers cannot
 *     double-send.
 *   - A long-running job (db backup) may outlive the pinger's timeout —
 *     the job still completes server-side; ignore that one "timed out"
 *     report, &job=status shows the truth.
 */

const CRON_BRIDGE_KEY = 'CHANGE_ME_TO_A_LONG_RANDOM_STRING';

// name => [script, min interval in minutes]
const JOBS = [
    'mail_retry'     => ['scripts/mail_retry.php',      10],
    'rsvp_reminders' => ['scripts/rsvp_reminders.php', 1380], // ~23 h
    'deadline_sms'   => ['scripts/deadline_sms.php',   1380],
    'notify_prune'   => ['scripts/notify_prune.php',   1380],
    'db_backup'      => ['scripts/db_backup.sh',       1380],
];
// job=all runs every real job; each one only when its interval has elapsed.
const ALL_JOBS = ['mail_retry', 'rsvp_reminders', 'deadline_sms', 'notify_prune', 'db_backup'];

const STATE_FILE = __DIR__ . '/.cron_bridge_state.json';

header('Content-Type: text/plain; charset=utf-8');

/* ---------------------------------------------------------------- auth -- */
$expected = (string) (getenv('WPMS_CRON_BRIDGE_KEY') ?: CRON_BRIDGE_KEY);
$given    = (string) ($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '');
if ($expected === '' || $expected === 'CHANGE_ME_TO_A_LONG_RANDOM_STRING'
        || !hash_equals($expected, $given)) {
    http_response_code(403);
    exit("Forbidden — set WPMS_CRON_BRIDGE_KEY in .env (or CRON_BRIDGE_KEY in this file), then call with ?key=...\n");
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
    @file_put_contents(STATE_FILE, json_encode($s, JSON_PRETTY_PRINT), LOCK_EX);
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

/** Locate a working PHP CLI binary (cached in the state file). */
function php_bin(array &$state): string
{
    if (!empty($state['php_bin'])) {
        return (string) $state['php_bin'];
    }
    $candidates = [
        PHP_BINARY,
        'php',
        '/usr/local/bin/php',
        '/usr/bin/php',
    ];
    foreach ($candidates as $bin) {
        if ($bin === '' || $bin === false) {
            continue;
        }
        $out = run_cmd($bin . ' -v', $code);
        if ($code === 0 && stripos((string) $out, 'php') !== false) {
            $state['php_bin'] = $bin;
            return $bin;
        }
    }
    return '';
}

/** Run a command line through the first available exec function. */
function run_cmd(string $cmd, ?int &$code = null): string
{
    $fn = exec_fn();
    $code = 1;
    $out = '';
    if ($fn === '') {
        return 'ERROR: no exec function available on this host (disable_functions)';
    }
    if ($fn === 'proc_open') {
        $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open($cmd, $spec, $pipes);
        if (is_resource($p)) {
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
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
        $out = (string) shell_exec($cmd . ' 2>&1');
        $code = $out === '' ? 1 : 0;
        return $out;
    }
    // passthru / system
    ob_start();
    if ($fn === 'passthru') {
        passthru($cmd . ' 2>&1', $code);
    } else {
        system($cmd . ' 2>&1', $code);
    }
    return ob_get_clean();
}

/* ------------------------------------------------------------ job run -- */
function run_job(string $name, array $jobs, array &$state, bool $force): string
{
    [$rel, $interval] = $jobs[$name];
    $script = __DIR__ . '/' . $rel;
    if (!is_file($script)) {
        return "FAIL {$name}: script missing ({$rel})";
    }

    $now = time();
    $last = $state['jobs'][$name] ?? [];
    $elapsed = isset($last['ts']) ? $now - (int) $last['ts'] : PHP_INT_MAX;
    if (!$force && $elapsed < $interval * 60) {
        $mins = (int) ceil(($interval * 60 - $elapsed) / 60);
        return "SKIP {$name}: ran " . max(1, (int) round($elapsed / 60)) . " min ago — next eligible in ~{$mins} min (&force=1 to override)";
    }

    @set_time_limit(300);
    @ignore_user_abort(true);

    $cmd = $name === 'db_backup'
        ? 'bash ' . escapeshellarg($script) . ' --quiet'
        : php_bin($state) . ' ' . escapeshellarg($script) . ' --quiet';
    if (php_bin($state) === '') {
        return "FAIL {$name}: no PHP CLI binary found on this host";
    }

    $t0   = microtime(true);
    $out  = run_cmd($cmd, $code);
    $ms   = (int) round((microtime(true) - $t0) * 1000);

    $state['jobs'][$name] = [
        'ts'    => $now,
        'exit'  => $code,
        'ms'    => $ms,
        'note'  => mb_substr(trim((string) $out), 0, 200),
    ];
    return sprintf('%s %s: exit=%d (%d ms)%s',
        $code === 0 ? 'OK' : 'FAIL', $name, $code, $ms,
        $code === 0 ? '' : ' — ' . mb_substr(trim((string) $out), 0, 160));
}

/* --------------------------------------------------------------- main -- */
$state  = state_load();
$job    = (string) ($_GET['job'] ?? 'status');
$force  = isset($_GET['force']);

if ($job === 'status') {
    echo "Everafter Planner cron bridge — status\n";
    foreach (ALL_JOBS as $name) {
        $l = $state['jobs'][$name] ?? null;
        if ($l === null) {
            echo sprintf("%-16s never run\n", $name);
        } else {
            echo sprintf("%-16s last %s  exit=%d  (%d ms)\n", $name,
                date('Y-m-d H:i', (int) $l['ts']), (int) $l['exit'], (int) $l['ms']);
        }
    }
    echo "bridge PHP: " . (php_bin($state) ?: 'NOT FOUND') . "\n";
    exit;
}

if ($job === 'all') {
    $ran = 0;
    foreach (ALL_JOBS as $name) {
        echo run_job($name, JOBS, $state, $force) . "\n";
        $ran++;
    }
    state_save($state);
    exit;
}

if (!isset(JOBS[$job])) {
    http_response_code(400);
    exit("Unknown job '{$job}'. Valid: status, all, " . implode(', ', array_keys(JOBS)) . "\n");
}

echo run_job($job, JOBS, $state, $force) . "\n";
state_save($state);
