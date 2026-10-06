<?php
/**
 * Deployment health check — open once in the browser after uploading, then
 * DELETE this file (or rename it with a leading dot) on a live site.
 *
 * It verifies the five things that break most cPanel deploys:
 *   1. PHP version + required extensions
 *   2. .env discovery (HOME copy or project-root copy)
 *   3. Database connection + all expected tables
 *   4. Writable upload directories
 *   5. Core pages reachable via the app's own bootstrap (no fatals)
 *
 * Usage:  https://yourdomain.co.ke/deploy_check.php?key=<value of DEPLOY_CHECK_KEY below>
 */

// Change this to any long random string BEFORE uploading, then pass ?key=...
const DEPLOY_CHECK_KEY = 'CHANGE_ME_TO_A_LONG_RANDOM_STRING';

if (DEPLOY_CHECK_KEY === 'CHANGE_ME_TO_A_LONG_RANDOM_STRING' || ($_GET['key'] ?? '') !== DEPLOY_CHECK_KEY) {
    http_response_code(403);
    exit('Forbidden — set DEPLOY_CHECK_KEY in this file and pass ?key=...');
}

header('Content-Type: text/plain; charset=utf-8');
$fail = 0;
$ok   = function (string $label, bool $pass, string $detail = '') use (&$fail) {
    echo ($pass ? '[ OK ] ' : '[FAIL] ') . $label . ($detail !== '' ? " — {$detail}" : '') . "\n";
    if (!$pass) { $fail++; }
};

echo "=== Everafter Planner deployment check ===\n\n";

// 1. PHP platform
$ok('PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>='), 'running ' . PHP_VERSION);
foreach (['pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl', 'curl'] as $ext) {
    $ok("ext {$ext}", extension_loaded($ext));
}

// 2. .env discovery + DB constants materialize
require __DIR__ . '/admin/include/config.php';
$envFileHome  = dirname(__DIR__) . '/.env';
$envFileRoot  = __DIR__ . '/.env';
$envFound     = is_file($envFileHome) || is_file($envFileRoot);
$ok('.env file found (HOME or project root)', $envFound,
    $envFound ? (is_file($envFileHome) ? $envFileHome : $envFileRoot) : 'deployment will use built-in defaults');
$ok('DB_NAME configured', defined('DB_NAME') && DB_NAME !== '' && DB_NAME !== 'dbwedding',
    defined('DB_NAME') ? DB_NAME : 'still the dev default');

// 3. Database + tables
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $ok('Database connection', true, DB_NAME . '@' . DB_HOST);
    $have = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $need = ['tblusers','tblaccounts','tblaccounts_detail','tblweddingbook','tblguest','tblsubscriptions',
             'tblsubscription_plans','tblsubscription_payments','tblvendors','tblvendor_bookings',
             'tblvendor_packages','tblnotifications','tbl_mail_logs','tblsms_logs','tblsms_credits',
             'tbllogin_throttle','tblsignup_throttle','tblplanner_verifications','tbl_password_resets',
             'tblsession_audits','tblmpesa_callbacks','tblmpesa_callback_log','tblmpesa_stk_pushes',
             'tblgallery','tblpostwedding','tblweddingcategories','tbl_features','tblwedding_websites',
             'tblchecklist','tbl_liquidation','tbl_installment_plans','tblorganizer','events','calendar'];
    $missing = array_diff($need, $have);
    $ok('All ' . count($need) . ' expected tables present', $missing === [],
        $missing === [] ? count($have) . ' tables total' : 'missing: ' . implode(', ', $missing));
    $staff = (int) $pdo->query("SELECT COUNT(*) FROM tblusers")->fetchColumn();
    $clients = (int) $pdo->query("SELECT COUNT(*) FROM tblaccounts")->fetchColumn();
    $ok('Staff + client accounts exist', $staff > 0 && $clients > 0, "{$staff} staff / {$clients} clients");
} catch (Throwable $e) {
    $ok('Database connection', false, $e->getMessage());
}

// 4. Writable uploads
foreach (['admin/upload/users', 'admin/upload/gallery', 'admin/upload/events',
          'admin/upload/categories', 'admin/upload/vendors'] as $dir) {
    $path = __DIR__ . '/' . $dir;
    $ok("writable {$dir}", is_dir($path) && is_writable($path));
}

// 5. Core pages through the real bootstrap
$pages = ['index.php', 'login.php', 'pricing.php', 'gallery.php', 'contact.php', 'thank_you.php'];
foreach ($pages as $page) {
    ob_start();
    $err = null;
    try { (function () use ($page) { include __DIR__ . '/' . $page; })(); }
    catch (Throwable $t) { $err = $t->getMessage(); }
    $out = ob_get_clean();
    $leak = (bool) preg_match('~(Fatal error|Parse error|Warning:|Deprecated:)~i', $out);
    $ok("page {$page}", $err === null && !$leak, $err ?? ($leak ? 'PHP error text in output' : ''));
}

echo "\n=== " . ($fail === 0 ? 'ALL CHECKS PASSED — delete this file now' : "{$fail} check(s) failed") . " ===\n";
