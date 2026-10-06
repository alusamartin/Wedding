<?php
declare(strict_types=1);

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
echo "\nDemo reset complete. Sign in at /login.php — wafula@wpms.co.ke / Admin@2024! (staff), brian.wafula@gmail.com / Test@1234 (couple).\n";
