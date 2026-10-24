<?php include 'include/init.php'; ?>
<?php
/**
 * Login Security — admin view over the login brute-force throttle.
 *
 * Mirrors what tbllogin_throttle is doing right now:
 *   - which (email, IP) pairs are LOCKED (5+ failures inside their current
 *     escalation window — anyone targeting an account),
 *   - which source IPs are under PRESSURE (failure sums climbing toward the
 *     per-IP cap of 20 — distributed guessing across many accounts),
 *   - the raw recent failed-attempt rows.
 *
 * Escalation: each pair's lock window grows with its lock_count — first lock
 * 15 min, then 1 h, then 6 h (LoginThrottle::LOCKOUT_STEPS). Every lockout
 * also texts the account owner (system-billed SMS).
 *
 * Actions (POST + CSRF, logged to error_log for audit):
 *   unlock      — delete one pair's counters (forgive the lock),
 *   release_ip  — delete every pair from one IP (e.g. a shared office NAT
 *                 that tripped the per-IP cap legitimately),
 *   prune_now   — delete rows idle beyond the 7-day horizon early.
 */
    require_staff_subscribed();

    $throttle_on = LoginThrottle::enabled();

    // ---- POST actions (CSRF-protected) -------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $throttle_on) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token.</div>');
            redirect_to('login_activity.php');
            die();
        }
        $who = (string) ($_SESSION['user_email'] ?? 'staff');
        $action = $_POST['action'] ?? '';

        if ($action === 'unlock') {
            $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
            $ip    = trim((string) ($_POST['ip'] ?? ''));
            if ($email !== '' && $ip !== '') {
                $db->execute(
                    'DELETE FROM tbllogin_throttle WHERE throttle_email = :e AND throttle_ip = :i',
                    [':e' => $email, ':i' => $ip]
                );
                error_log("[WPMS][LoginSecurity] unlock pair email={$email} ip={$ip} by {$who}");
                $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-lock-open-variant"></i></strong> Pair unlocked — ' . htmlspecialchars($email) . ' from ' . htmlspecialchars($ip) . ' can sign in again.</div>');
            }
        } elseif ($action === 'release_ip') {
            $ip = trim((string) ($_POST['ip'] ?? ''));
            if ($ip !== '') {
                $stmt = $db->execute('DELETE FROM tbllogin_throttle WHERE throttle_ip = :i', [':i' => $ip]);
                error_log("[WPMS][LoginSecurity] release ip={$ip} pairs=" . $stmt->rowCount() . " by {$who}");
                $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-lock-open-variant"></i></strong> Released ' . (int) $stmt->rowCount() . ' pair(s) from ' . htmlspecialchars($ip) . '.</div>');
            }
        } elseif ($action === 'prune_now') {
            $stmt = $db->execute(
                'DELETE FROM tbllogin_throttle WHERE updated_at < NOW() - INTERVAL :d DAY',
                [':d' => LoginThrottle::PRUNE_AFTER_DAYS]
            );
            error_log("[WPMS][LoginSecurity] prune rows=" . $stmt->rowCount() . " by {$who}");
            $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-delete-sweep"></i></strong> Pruned ' . (int) $stmt->rowCount() . ' idle row(s).</div>');
        }
        redirect_to('login_activity.php');
        die();
    }

    // Render helpers ---------------------------------------------------------
    $remaining = function (object $row): int {
        // Seconds left on this pair's lock. Each pair serves ITS OWN window
        // from the escalation ladder (same math as LoginThrottle::secondsRemaining).
        $window = LoginThrottle::lockoutForCount((int) ($row->lock_count ?? 0));
        $age = max(0, (time()) - strtotime((string) $row->updated_at));
        return max(0, $window - $age);
    };
    $isLocked = function (object $row) use ($remaining): bool {
        return (int) $row->fail_count >= LoginThrottle::MAX_FAILURES && $remaining($row) > 0;
    };
    $windowMins = function (object $row): int {
        return (int) round(LoginThrottle::lockoutForCount(max(1, (int) ($row->lock_count ?? 1))) / 60);
    };

    // ---- Read model --------------------------------------------------------
    $tableMissing = false;
    $stats = null;
    $lockedPairs = [];
    $ipPressure  = [];
    $recent      = [];
    $staleCount  = 0;

    try {
        // Per-pair lock window == LOCKOUT_SECONDS; pressure window == the same.
        $win = LoginThrottle::LOCKOUT_SECONDS;

        // Active pairs + 24h failure total; "locked" is computed per row
        // below (each pair serves its OWN escalation window).
        $stats = $db->fetchOne(
            "SELECT
                SUM(CASE WHEN fail_count > 0 AND updated_at >= NOW() - INTERVAL {$win} SECOND THEN 1 ELSE 0 END) AS active_pairs,
                COALESCE(SUM(CASE WHEN updated_at >= NOW() - INTERVAL 86400 SECOND THEN fail_count ELSE 0 END), 0) AS fails_24h
             FROM tbllogin_throttle"
        );

        $hotIps = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM (
                SELECT throttle_ip FROM tbllogin_throttle
                WHERE updated_at >= NOW() - INTERVAL {$win} SECOND
                GROUP BY throttle_ip
                HAVING SUM(fail_count) >= :maxip
             ) t",
            [':maxip' => LoginThrottle::MAX_FAILURES_PER_IP]
        );
        $stats->hot_ips = $hotIps;

        // Candidates for "locked": enough failures and recent enough for even
        // the LONGEST escalation window; $isLocked (per-row window) filters
        // the expired ones out. lock_count is re-fetched after each claim so
        // the view reflects the live ladder.
        $lockCandidates = $db->fetchAll(
            "SELECT * FROM tbllogin_throttle
             WHERE fail_count >= :maxf
               AND updated_at >= NOW() - INTERVAL :maxwin SECOND
             ORDER BY updated_at DESC
             LIMIT 500",
            [
                ':maxf'   => LoginThrottle::MAX_FAILURES,
                ':maxwin' => max(LoginThrottle::LOCKOUT_STEPS),
            ]
        );
        $stats->locked_pairs = 0;
        $lockedPairs = [];
        foreach ($lockCandidates as $row) {
            if ($isLocked($row)) {
                $lockedPairs[] = $row;
                $stats->locked_pairs++;
            }
        }

        $ipPressure = $db->fetchAll(
            "SELECT throttle_ip, COUNT(*) AS accounts, SUM(fail_count) AS recent_fails,
                    MAX(fail_count) AS worst_pair
             FROM tbllogin_throttle
             WHERE updated_at >= NOW() - INTERVAL {$win} SECOND
             GROUP BY throttle_ip
             ORDER BY recent_fails DESC, accounts DESC
             LIMIT 25"
        );

        $recent = $db->fetchAll(
            'SELECT * FROM tbllogin_throttle ORDER BY updated_at DESC LIMIT 100'
        );

        $staleCount = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM tbllogin_throttle WHERE updated_at < NOW() - INTERVAL :d DAY',
            [':d' => LoginThrottle::PRUNE_AFTER_DAYS]
        );
    } catch (Throwable $e) {
        $tableMissing = true; // migration not run yet
        error_log('[WPMS][LoginSecurity] ' . $e->getMessage());
    }
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Login Security - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/7.4.47/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .stat-card { background: #fff; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px;
                     box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-left: 4px solid #059669; }
        .stat-card h6 { font-size: 11px; text-transform: uppercase; color: #64748B; margin: 0; letter-spacing: .06em; }
        .stat-card .value { font-size: 24px; font-weight: 700; color: #0F172A; }
        .ledger-table td, .ledger-table th { font-size: 12.5px; vertical-align: middle; }
        .mono { font-variant-numeric: tabular-nums; font-family: SFMono-Regular, Menlo, Consolas, monospace; }
        .pill { font-size: 10px; padding: 3px 9px; border-radius: 999px; }
        .heat { font-weight: 700; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-shield-lock-outline" style="color:#059669"></i> Login Security</h4>
            <p class="text-muted mb-0" style="font-size: 13px;">
                Brute-force throttle on <code>login.php</code> —
                <?= (int) LoginThrottle::MAX_FAILURES; ?> failures per (email, IP) pair locks it
                <?= (int) (LoginThrottle::LOCKOUT_STEPS[0] / 60); ?> min, escalating to
                <?= (int) (LoginThrottle::LOCKOUT_STEPS[1] / 60); ?> h and
                <?= (int) (LoginThrottle::LOCKOUT_STEPS[2] / 60); ?> h for repeat offenders; per-IP cap
                <?= (int) LoginThrottle::MAX_FAILURES_PER_IP; ?> catches distributed guessing. Every lockout
                texts the account owner.
            </p>
        </div>
        <div class="text-right">
            <span class="badge <?= $throttle_on ? 'badge-success' : 'badge-secondary'; ?>" style="font-size: 11px;">
                <i class="mdi mdi-<?= $throttle_on ? 'shield-check' : 'shield-off'; ?>"></i>
                Throttle <?= $throttle_on ? 'ON' : 'OFF'; ?> (WPMS_LOGIN_THROTTLE_OFF)
            </span>
        </div>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <?php if ($tableMissing): ?>
        <div class="alert alert-warning">
            <strong><i class="mdi mdi-database-alert"></i> Throttle table not found.</strong>
            Run <code>migrations/v2.1_phase1_hardening.sql</code> to create <code>tbllogin_throttle</code> —
            until then login.php skips throttling silently.
        </div>
    <?php else: ?>

    <div class="row">
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--danger)"><h6>Locked pairs now</h6><div class="value"><?= (int) ($stats->locked_pairs ?? 0); ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--warning)"><h6>IPs at the cap</h6><div class="value"><?= (int) ($stats->hot_ips ?? 0); ?></div></div></div>
        <div class="col-md-3"><div class="stat-card"><h6>Active pairs (window)</h6><div class="value"><?= (int) ($stats->active_pairs ?? 0); ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--stone-700)"><h6>Failures (24h)</h6><div class="value mono"><?= (int) ($stats->fails_24h ?? 0); ?></div></div></div>
    </div>

    <?php if (!empty($lockedPairs)): ?>
    <h5 class="mt-2 mb-3"><i class="mdi mdi-lock-alert mr-1" style="color:#DC2626"></i> Locked pairs
        <span class="text-muted" style="font-size:12px; font-weight:400;">— locked accounts CANNOT sign in, even with the correct password</span>
    </h5>
    <div class="card shadow-sm mb-4" style="border:none; border-radius:14px;">
        <div class="card-body">
            <div class="table-responsive">
            <table class="table table-hover ledger-table" style="margin-bottom:0;">
                <thead>
                    <tr><th>Email attempted</th><th>IP</th><th>Failures</th><th>Lock #</th><th>Unlocks in</th><th>Last attempt</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($lockedPairs as $r): ?>
                    <tr>
                        <td class="mono" style="font-size:12.5px;"><strong><?= htmlspecialchars((string) $r->throttle_email); ?></strong></td>
                        <td class="mono" style="font-size:12.5px;"><?= htmlspecialchars((string) $r->throttle_ip); ?></td>
                        <td class="mono"><span class="pill badge-danger"><?= (int) $r->fail_count; ?></span></td>
                        <td class="mono"><span class="pill badge-dark">#<?= (int) $r->lock_count; ?> · <?= (int) $windowMins($r); ?>m window</span></td>
                        <td class="mono" style="font-size:12.5px;"><?= (int) ceil($remaining($r) / 60); ?> min</td>
                        <td style="font-size:12.5px; white-space:nowrap;"><?= htmlspecialchars(format_date_kd((string) $r->updated_at, true)); ?> <span class="text-muted">(<?= htmlspecialchars(time_elapsed_string((string) $r->updated_at)); ?>)</span></td>
                        <td>
                            <form method="post" class="form-inline">
                                <?= $session->csrf_field(); ?>
                                <input type="hidden" name="action" value="unlock">
                                <input type="hidden" name="email" value="<?= htmlspecialchars((string) $r->throttle_email); ?>">
                                <input type="hidden" name="ip" value="<?= htmlspecialchars((string) $r->throttle_ip); ?>">
                                <button type="submit" class="btn btn-sm btn-outline-success" style="font-size:11px;"
                                        onclick="return confirm('Unlock <?= htmlspecialchars((string) $r->throttle_email); ?> from <?= htmlspecialchars((string) $r->throttle_ip); ?>? Their failure counters are forgiven.');">
                                    <i class="mdi mdi-lock-open-variant"></i> Unlock
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <h5 class="mt-2 mb-3"><i class="mdi mdi-ip-network mr-1" style="color:#D97706"></i> IP pressure
        <span class="text-muted" style="font-size:12px; font-weight:400;">— failures per source IP inside the <?= (int) (LoginThrottle::LOCKOUT_SECONDS / 60); ?>-minute window</span>
    </h5>
    <div class="card shadow-sm mb-4" style="border:none; border-radius:14px;">
        <div class="card-body">
            <?php if (empty($ipPressure)): ?>
                <p class="text-muted mb-0" style="font-size:13px;">No failed sign-ins in the current window — all quiet.</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-hover ledger-table" style="margin-bottom:0;">
                <thead>
                    <tr><th>Source IP</th><th>Accounts targeted</th><th>Recent failures</th><th>Worst pair</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($ipPressure as $r): $atCap = (int) $r->recent_fails >= LoginThrottle::MAX_FAILURES_PER_IP; ?>
                    <tr>
                        <td class="mono" style="font-size:12.5px;"><strong><?= htmlspecialchars((string) $r->throttle_ip); ?></strong></td>
                        <td class="mono"><?= (int) $r->accounts; ?></td>
                        <td class="mono heat" style="color: <?= $atCap ? '#DC2626' : ((int) $r->recent_fails >= LoginThrottle::MAX_FAILURES_PER_IP / 2 ? '#D97706' : '#0F172A'); ?>;"><?= (int) $r->recent_fails; ?> / <?= (int) LoginThrottle::MAX_FAILURES_PER_IP; ?></td>
                        <td class="mono"><?= (int) $r->worst_pair; ?></td>
                        <td>
                            <?php if ($atCap): ?>
                                <span class="pill badge-danger">at cap — blocking</span>
                            <?php else: ?>
                                <span class="pill badge-warning">watching</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" class="form-inline">
                                <?= $session->csrf_field(); ?>
                                <input type="hidden" name="action" value="release_ip">
                                <input type="hidden" name="ip" value="<?= htmlspecialchars((string) $r->throttle_ip); ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" style="font-size:11px;"
                                        onclick="return confirm('Release ALL pairs from <?= htmlspecialchars((string) $r->throttle_ip); ?>? Use for shared-office NATs that tripped the cap legitimately.');">
                                    <i class="mdi mdi-lock-open"></i> Release
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-4 mb-3">
        <h5 class="mb-0"><i class="mdi mdi-history mr-1"></i> Recent failed attempts
            <span class="text-muted" style="font-size:12px; font-weight:400;">— last <?= count($recent); ?> rows</span>
        </h5>
        <?php if ($staleCount > 0): ?>
        <form method="post" class="form-inline">
            <?= $session->csrf_field(); ?>
            <input type="hidden" name="action" value="prune_now">
            <button type="submit" class="btn btn-sm btn-outline-secondary" style="font-size:11px;">
                <i class="mdi mdi-delete-sweep"></i> Prune <?= (int) $staleCount; ?> idle row(s) now
            </button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card shadow-sm" style="border:none; border-radius:14px;">
        <div class="card-body">
            <div class="table-responsive">
            <table class="table table-hover ledger-table" style="margin-bottom:0;">
                <thead>
                    <tr><th>Email attempted</th><th>IP</th><th>Failures</th><th>Status</th><th>Last attempt</th></tr>
                </thead>
                <tbody>
                <?php if (empty($recent)): ?>
                    <?php empty_state_row(5, 'Nothing here yet', 'Failed sign-ins appear the moment someone mistypes a password on login.php.', 'mdi-shield-lock-outline'); ?>
                <?php else: ?>
                    <?php foreach ($recent as $r): ?>
                    <tr>
                        <td class="mono" style="font-size:12.5px;"><?= htmlspecialchars((string) $r->throttle_email); ?></td>
                        <td class="mono" style="font-size:12.5px;"><?= htmlspecialchars((string) $r->throttle_ip); ?></td>
                        <td class="mono"><?= (int) $r->fail_count; ?> / <?= (int) LoginThrottle::MAX_FAILURES; ?></td>
                        <td>
                            <?php if ($isLocked($r)): ?>
                                <span class="pill badge-danger">locked <?= (int) ceil($remaining($r) / 60); ?>m left · #<?= (int) $r->lock_count; ?></span>
                            <?php elseif ((int) $r->fail_count > 0): ?>
                                <span class="pill badge-warning">watching</span>
                            <?php else: ?>
                                <span class="pill badge-secondary">cleared</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12.5px; white-space:nowrap;"><?= htmlspecialchars(format_date_kd((string) $r->updated_at, true)); ?> <span class="text-muted">(<?= htmlspecialchars(time_elapsed_string((string) $r->updated_at)); ?>)</span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <p class="text-muted mt-3" style="font-size: 12px;">
        Unlocking forgives the counters — for a genuine user you recognize, not for an attacker mid-campaign
        (they simply resume; each new failure re-locks the pair). Set <code>WPMS_LOGIN_THROTTLE_OFF=1</code> to
        disable the throttle entirely (this page becomes read-only while it is off). Blocked attempts are also
        written to the PHP error log (<code>[WPMS][LoginThrottle]</code>) for fail2ban-style alerting.
        Repeat offenders climb the escalation ladder (<?= (int) (LoginThrottle::LOCKOUT_STEPS[0] / 60); ?> min →
        <?= (int) (LoginThrottle::LOCKOUT_STEPS[1] / 60); ?> h → <?= (int) (LoginThrottle::LOCKOUT_STEPS[2] / 60); ?> h);
        a successful sign-in forgives the ladder, the 7-day prune ages it out, and every lockout texts the
        account owner (system SMS, not billed to planner credits — audit row in <code>tblsms_logs</code>).
    </p>

    <?php endif; /* $tableMissing */ ?>
</div>

<?php include_once 'include/footer.php'; ?>
</body>
</html>
