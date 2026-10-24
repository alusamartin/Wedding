<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * One-command demo reset: rebuild the demo database from the seed files and
 * date-shift it so every seeded wedding is in the future.
 *
 * What it does (in order):
 *   1. Drops and recreates the target database (utf8mb4).
 *   2. Imports, in the README's install order:
 *        DATABASE FILE/dbwedding.sql                       (base schema)
 *        kenyan_schema.sql                                 (KSh + Kenyan columns)
 *        DATABASE FILE/seed_v2.1_full_test_KENYA_ONLY.sql  (Kenyan seed)
 *        migrations/v2.1_phase1_hardening.sql              (idempotent helpers)
 *        migrations/v2.2_subscription_renewals.sql         (renewal columns)
 *        migrations/v2.3_planner_trial.sql                 (trial ENUM + bcrypt width)
 *        migrations/v2.4_signup_abuse_guard.sql            (signup throttle + verification)
 *        migrations/v2.5_trial_nurture.sql                 (trial nurture band marker)
 *        migrations/v2.6_staff_password_reset.sql          (reset-token owner table)
 *        migrations/v2.7_extend_trial_window.sql           (six-month trial backfill)
 *        migrations/v2.8_plan_pricing_audit.sql             (buyable plan keys + price ledger)
 *        migrations/v2.9_venue_headcounts.sql              (one venue headcount plan per function)
 *        migrations/v2.10_vendor_function_scope.sql         (vendor deal function scope)
 *        migrations/v2.12_user_preferences.sql              (per-account preferences table)
 *        migrations/v2.13_notification_recipients.sql      (which account a bell/mail row is for)
 *        migrations/v2.14_public_abuse_guard.sql           (rate limits for the anonymous doors)
 *        migrations/v2.15_job_heartbeats.sql               (scheduled-job heartbeats + the watchdog)
 *        migrations/v2.16_api_tokens.sql                   (MCP API tokens)
 *        migrations/v2.17_mcp_approvals.sql                (MCP write approvals)
 *        migrations/v2.19_mcp_approval_expiry.sql          (approval expiry)
 *        migrations/v2.20_mcp_approval_rerequest.sql       (approval re-request)                   
 *        migrations/v2.21_vendor_ratings.sql                (vendor rating + booking count writers) 
 *        migrations/v2.24_event_vendor_needs.sql            (per-function vendor service requirements)
 *        migrations/v2.25_vendor_threads.sql                (the couple/vendor/planner conversation)
 *        migrations/v2.26_thread_escalation.sql             (the unanswered-thread escalation stamp)
 *        migrations/v2.25_vendor_threads.sql                (three-way vendor conversation)
 *        migrations/v2.27_vendor_services.sql               (service catalogue + per-vendor services)
 *        migrations/v2.28_vendor_preferences.sql            (the couple's taste + budget per category)
 *        migrations/v2.29_vendor_plan.sql                   (which offering a vendor deal is for)
 *        migrations/v2.30_proposals.sql                     (the proposal lifecycle: brief, options, choice)
 *        migrations/v3.1_vendor_service_tiers.sql           (silver/gold/platinum pricing for services and packages)
 *      NOT imported: migrations/v2.18_mcp_grants.sql — that file is an OPERATOR
 *      script (CREATE USER + 67 column/table GRANTs against a fixed `dbwedding`
 *      name), not schema. It has no place in a throwaway demo/test database.
 *   3. Runs the date-shift logic (same rules as scripts/shift_seed_dates.php):
 *      every seed booking moves forward in whole 52-week steps until future,
 *      payments rebase to wedding-30d, calendar widget recomputed. Booking
 *      215 (real user) is never touched.
 *
 * The hardening migration hardcodes `CREATE DATABASE \`dbwedding\`` / `USE
 * \`dbwedding\`` — when a --db override is given, those lines are rewritten
 * to the target database for the import only (the source files on disk are
 * never modified).
 *
 * Usage:
 *   php scripts/demo_reset.php                 # asks before dropping
 *   php scripts/demo_reset.php --yes           # no prompt (cron / CI)
 *   php scripts/demo_reset.php --db=demo_test  # build a scratch DB instead
 *   php scripts/demo_reset.php --dry-run       # show what would happen
 *
 * Composer: composer demo:reset [-- --yes] [-- --db=name]
 */

require __DIR__ . '/../admin/include/config.php';

// ------------------------------------------------------------------ args
$opts = getopt('', ['yes', 'dry-run', 'db::']) ?: [];
$assumeYes = array_key_exists('yes', $opts);
$dryRun = array_key_exists('dry-run', $opts);
$targetDb = isset($opts['db']) && is_string($opts['db']) && trim($opts['db']) !== ''
    ? trim($opts['db'])
    : DB_NAME;

$files = [
    __DIR__ . '/../DATABASE FILE/dbwedding.sql',
    __DIR__ . '/../kenyan_schema.sql',
    __DIR__ . '/../DATABASE FILE/seed_v2.1_full_test_KENYA_ONLY.sql',
    __DIR__ . '/../migrations/v2.1_phase1_hardening.sql',
    __DIR__ . '/../migrations/v2.2_subscription_renewals.sql',
    __DIR__ . '/../migrations/v2.3_planner_trial.sql',
    __DIR__ . '/../migrations/v2.4_signup_abuse_guard.sql',
    __DIR__ . '/../migrations/v2.5_trial_nurture.sql',
    __DIR__ . '/../migrations/v2.6_staff_password_reset.sql',
    __DIR__ . '/../migrations/v2.7_extend_trial_window.sql',
    __DIR__ . '/../migrations/v2.8_plan_pricing_audit.sql',
    __DIR__ . '/../migrations/v2.9_venue_headcounts.sql',
    __DIR__ . '/../migrations/v2.10_vendor_function_scope.sql',
    __DIR__ . '/../migrations/v2.12_user_preferences.sql',
    __DIR__ . '/../migrations/v2.13_notification_recipients.sql',
    __DIR__ . '/../migrations/v2.14_public_abuse_guard.sql',
    __DIR__ . '/../migrations/v2.15_job_heartbeats.sql',
    __DIR__ . '/../migrations/v2.16_api_tokens.sql',
    __DIR__ . '/../migrations/v2.17_mcp_approvals.sql',
    __DIR__ . '/../migrations/v2.19_mcp_approval_expiry.sql',
    __DIR__ . '/../migrations/v2.20_mcp_approval_rerequest.sql',
    __DIR__ . '/../migrations/v2.21_vendor_ratings.sql',
    __DIR__ . '/../migrations/v2.23_business_pulse_snapshots.sql',
    __DIR__ . '/../migrations/v2.24_event_vendor_needs.sql',
    __DIR__ . '/../migrations/v2.25_vendor_threads.sql',
    __DIR__ . '/../migrations/v2.26_thread_escalation.sql',
    __DIR__ . '/../migrations/v2.27_vendor_services.sql',
    __DIR__ . '/../migrations/v2.28_vendor_preferences.sql',
    __DIR__ . '/../migrations/v2.29_vendor_plan.sql',
    __DIR__ . '/../migrations/v2.30_proposals.sql',
    __DIR__ . '/../migrations/v3.1_vendor_service_tiers.sql',
];
foreach ($files as $f) {
    if (!is_file($f)) {
        fwrite(STDERR, "MISSING SQL FILE: $f\n");
        exit(1);
    }
}

echo "Demo reset\n";
echo "  host : " . DB_HOST . "\n";
echo "  db   : $targetDb\n";
if ($targetDb === DB_NAME) {
    echo "  (this is the app's live database — its current contents will be REPLACED)\n";
}

// ------------------------------------------------------------------ guard
if ($dryRun) {
    echo "\n--dry-run: would DROP + CREATE `$targetDb`, import " . count($files)
        . " SQL files, then date-shift. No changes made.\n";
    foreach ($files as $f) {
        echo '  would import: ' . basename($f) . "\n";
    }
    exit(0);
}

if (!$assumeYes && $targetDb === DB_NAME) {
    echo "\nType the database name to confirm replacement (anything else aborts): ";
    $answer = trim((string) fgets(STDIN));
    if ($answer !== $targetDb) {
        echo "Aborted (confirmation did not match).\n";
        exit(1);
    }
}

// ------------------------------------------------------- mysql client
$mysqlCandidates = [
    'C:/xampp/mysql/bin/mysql.exe',
    'C:/Program Files/MySQL/MySQL Server 8.0/bin/mysql.exe',
    'C:/Program Files/MySQL/MySQL Server 8.4/bin/mysql.exe',
    'mysql', // hope it's on PATH for exec()
];
$mysql = null;
foreach ($mysqlCandidates as $c) {
    if ($c !== 'mysql' && !is_file($c)) {
        continue;
    }
    // Actually probe it — PATH and exec() can disagree (esp. Windows).
    exec('"' . addslashes($c) . '" --version 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), $vout, $vcode);
    if ($vcode === 0) {
        $mysql = $c;
        break;
    }
}
if ($mysql === null) {
    fwrite(STDERR, "mysql client not found (looked in PATH + XAMPP defaults).\n");
    exit(1);
}

$esc = static fn(string $s): string => '"' . addslashes($s) . '"';
$baseCmd = $esc($mysql)
    . ' --host=' . $esc(DB_HOST)
    . ' --user=' . $esc(DB_USER);
if (DB_PASS !== '') {
    $baseCmd .= ' --password=' . $esc(DB_PASS);
}
// force utf8mb4 so Kenyan names / emoji survive round-trips
$baseCmd .= ' --default-character-set=utf8mb4';

$run = static function (string $cmd): void {
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "COMMAND FAILED (exit $code):\n  " . preg_replace('/--password=[^ ]+/', '--password=***', $cmd) . "\n");
        fwrite(STDERR, implode("\n", array_slice($out, 0, 20)) . "\n");
        exit(1);
    }
    foreach (array_slice($out, 0, 5) as $line) {
        if (trim($line) !== '') {
            echo '  ' . $line . "\n";
        }
    }
};

// ------------------------------------------------- drop + create target
echo "\nRecreating database `$targetDb`...\n";
$run($baseCmd . ' --execute=' . $esc("DROP DATABASE IF EXISTS `$targetDb`; CREATE DATABASE `$targetDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"));

// ------------------------------------------------------------ imports
foreach ($files as $i => $f) {
    echo 'Importing (' . ($i + 1) . '/' . count($files) . '): ' . basename($f) . "...\n";
    $sql = file_get_contents($f);
    if ($sql === false) {
        fwrite(STDERR, "READ FAILED: $f\n");
        exit(1);
    }
    // The hardening migration targets `dbwedding` literally — retarget for --db builds.
    if ($targetDb !== 'dbwedding') {
        $sql = str_replace('`dbwedding`', '`' . $targetDb . '`', $sql);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'wpms_reset_');
    file_put_contents($tmp, $sql);
    $run($baseCmd . ' ' . $esc($targetDb) . ' < ' . $esc($tmp));
    unlink($tmp);
}

// ------------------------------------------------------- date-shift
echo "\nDate-shifting seed data so all weddings are future...\n";
require __DIR__ . '/shift_seed_dates.php';
try {
    shift_seed_dates($targetDb);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

// ------------------------------------------------------------ verify
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $targetDb, DB_USER, DB_PASS);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$counts = [];
foreach (['tblweddingbook', 'tblguest', 'tbl_liquidation', 'events', 'tblwedding_websites', 'tblvendor_bookings'] as $t) {
    $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}
$future = (int) $pdo->query('SELECT COUNT(*) FROM tblweddingbook WHERE booking_id IN (200,201,202,203,204) AND wedding_date >= CURDATE()')->fetchColumn();
echo "\nVerify: " . json_encode($counts) . "\n";
echo "Seed weddings in the future: $future / 5\n";
if ($future !== 5) {
    fwrite(STDERR, "VERIFY FAILED — seed weddings are not all future-dated.\n");
    exit(1);
}
$demoLogins = $pdo->query("SELECT COUNT(*) FROM tblaccounts WHERE user_id IN (200,201,202,203,204)")->fetchColumn();
echo "Demo couple accounts: $demoLogins\n";

/*
 * Truncated-hash guard. If tblusers.password is sized varchar(50) when the seed
 * runs, MySQL silently cuts every 60-char bcrypt hash down to 50 and the whole
 * demo becomes unloggable — with a success exit code. Catch it here instead.
 */
$hashes = $pdo->query(
    "SELECT id, LENGTH(password) AS len FROM tblusers"
    . " WHERE password LIKE '\$2%' AND LENGTH(password) <> 60 ORDER BY id"
)->fetchAll(PDO::FETCH_KEY_PAIR);
if ($hashes !== []) {
    fwrite(STDERR, "VERIFY FAILED — truncated bcrypt hashes (expected 60 chars):\n");
    foreach ($hashes as $id => $len) {
        fwrite(STDERR, "  user $id: $len chars\n");
    }
    fwrite(STDERR, "HINT: tblusers.password must be varchar(255) BEFORE the seed runs.\n");
    exit(1);
}
$usable = (int) $pdo->query(
    "SELECT COUNT(*) FROM tblusers WHERE password LIKE '\$2%' AND LENGTH(password) = 60"
)->fetchColumn();
echo "Seeded staff logins with intact hashes: $usable\n";
if ($usable === 0) {
    fwrite(STDERR, "VERIFY FAILED — no usable staff logins were seeded.\n");
    exit(1);
}

// ------------------------------------------------------- final login check
echo "Verifying a seeded login end-to-end...\n";
$probe = $pdo->query("SELECT id, password FROM tblusers WHERE id = 5")->fetch(PDO::FETCH_ASSOC);
if ($probe === false || !password_verify('Admin@2024!', (string) $probe['password'])) {
    fwrite(STDERR, "VERIFY FAILED — password_verify() rejected the seeded super-admin password.\n");
    exit(1);
}
echo "\nDemo reset complete. Sign in at /login.php — wafula@wpms.co.ke / Admin@2024! (staff), brian.wafula@gmail.com / Test@1234 (couple).\n";
