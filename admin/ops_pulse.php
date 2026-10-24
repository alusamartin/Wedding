<?php include 'include/init.php'; ?>
<?php
/**
 * Business Pulse — the operator's statistics, and the findings worth acting on.
 *
 * WHAT THIS IS. The rest of the planner answers questions about one wedding.
 * This page answers the owner's question instead: how is the business doing,
 * what needs attention first, and what should we change? It renders exactly what
 * the MCP tools `system_status` and `list_insights` return (admin/include/
 * OpsPulse.php owns every query, the thresholds and the privacy contract), so
 * the person reading the screen and the assistant reading the API can never
 * disagree about the numbers.
 *
 * TWO THINGS ARE TUNED HERE, PER ACCOUNT.
 *
 *   1. THE WINDOWS. A rule is only useful if its horizon matches the way this
 *      operator works: 45 days to "wedding soon", 7 days for a stale vendor
 *      deal, 5 weddings as a planner's load. Every threshold is stored against
 *      the signed-in account (UserPrefs → tbluser_prefs, v2.12), validated by
 *      OpsPulse before it is written, and re-validated on the way back out — so
 *      a hand-edited row reads as the default rather than widening a query
 *      nobody asked for. The assistant's tools read the same values: tune once,
 *      both surfaces agree.
 *
 *   2. THE MUTES. A rule that keeps firing on something the operator has already
 *      accepted trains them to ignore the whole list, which is worse than not
 *      having the rule. Muting that ONE code removes it from the findings and
 *      from what the AI is told — and it stays visible on this page with its
 *      current count and an Unmute button, so the decision is always reversible
 *      and always reviewable.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not act: no booking is confirmed,
 * no guest is chased, no reminder is sent. Each finding names the page where a
 * human does that. It also names internal ids only — the evidence beside a
 * finding is a booking id and numbers, never a couple's name or a phone number,
 * because these payloads are built the same way the AI's are. The page resolves
 * nothing about the people involved; the linked planner page does that.
 *
 * WHO SEES IT. Super admins only (tblusers.access_level 2), the same gate as Job
 * Health and Plan Definitions: platform revenue, every colleague's workload and
 * the business's own risk list are the operator's business, not a planner's.
 *
 * Storage: thresholds and mutes are two preferences in tbluser_prefs
 * (migrations/v2.12_user_preferences.sql); a database that has not run it still
 * serves the page at the defaults and says it cannot remember. The weekly-brief
 * card reads one indexed row from tblops_snapshots (v2.23) to name the week the
 * email will compare against — absent, it says the comparison is off, and the
 * page is otherwise unchanged.
 */
    require_staff_subscribed();

    if (!is_super_admin()) {
        redirect_to('dashboard.php');
        die();
    }

    require_once __DIR__ . '/include/OpsPulse.php';
    require_once __DIR__ . '/include/BusinessPulse.php'; // the Monday email, built from the same calls

    $user_id = (int) ($_SESSION['id'] ?? 0);

    // ==================================================================
    // Writes — all POST, all CSRF-checked, all per-account
    // ==================================================================

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><i class="mdi mdi-alert"></i> Invalid security token. Please try again.</div>');
            redirect_to('ops_pulse.php');
            die();
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'send_pulse') {
            /*
             * A manual copy of the scheduled brief to the operator reading this
             * page. It stamps the week, exactly like the cron job does, so the
             * Monday email is not duplicated because somebody wanted to check
             * the format on Thursday.
             */
            $me    = Users::find_by_id($user_id);
            $email = trim((string) ($me->email ?? ''));
            $sent  = BusinessPulse::send($user_id, $email);
            $session->message(match ($sent['status']) {
                'sent'    => '<div class="alert alert-success"><i class="mdi mdi-email-check"></i> Your business pulse is on its way to ' . htmlspecialchars($email) . '.</div>',
                'pending' => '<div class="alert alert-info"><i class="mdi mdi-email-clock"></i> The mail transport is off on this install, so the brief is queued in the mail outbox for ' . htmlspecialchars($email) . '. It will go out when mail_retry runs with WPMS_MAIL_ENABLED=1.</div>',
                'skipped' => '<div class="alert alert-danger"><i class="mdi mdi-alert"></i> Your account has no email address on file, so there is nowhere to send it.</div>',
                default   => '<div class="alert alert-danger"><i class="mdi mdi-alert"></i> The brief could not be sent: ' . htmlspecialchars((string) $sent['note']) . '</div>',
            });
            redirect_to('ops_pulse.php');
            die();
        }

        if ($action === 'save_thresholds') {
            $saved = OpsPulse::save_settings($user_id, $_POST);
            $session->message($saved['ok']
                ? '<div class="alert alert-success"><i class="mdi mdi-check"></i> Thresholds saved for your account. The findings and the AI tools now use them.</div>'
                : '<div class="alert alert-danger"><i class="mdi mdi-alert"></i> Nothing was saved — ' . htmlspecialchars(
                    $saved['errors']['_form'] ?? 'check the values marked below.'
                ) . '</div>');
            redirect_to('ops_pulse.php');
            die();
        }

        if ($action === 'mute' || $action === 'unmute') {
            $code = (string) ($_POST['code'] ?? '');
            $ok   = $action === 'mute' ? OpsPulse::mute($user_id, $code) : OpsPulse::unmute($user_id, $code);
            $session->message($ok
                ? '<div class="alert alert-success"><i class="mdi mdi-check"></i> ' . ($action === 'mute'
                    ? htmlspecialchars(OpsPulse::rule_label($code)) . ' is muted for your account — it stays listed under Muted below.'
                    : htmlspecialchars(OpsPulse::rule_label($code)) . ' is live again.') . '</div>'
                : '<div class="alert alert-danger"><i class="mdi mdi-alert"></i> That change could not be saved. '
                    . (UserPrefs::available()
                        ? 'Only a known finding can be muted.'
                        : 'This database has not run migrations/v2.12_user_preferences.sql yet.') . '</div>');
            redirect_to('ops_pulse.php');
            die();
        }

        redirect_to('ops_pulse.php');
        die();
    }

    // ==================================================================
    // Reads
    // ==================================================================

    $settings  = OpsPulse::settings($user_id);
    $snapshot  = OpsPulse::snapshot($user_id);
    $insights  = OpsPulse::insights('', 50, $user_id);
    $findings  = $insights['findings'];

    /*
     * The muted list is built from the CODES, not from the findings: a mute has
     * to stay visible and reversible even when its rule is quiet this week,
     * which is exactly the case in which an operator is most likely to forget
     * they ever muted it. muted_findings() adds the count for the ones firing.
     */
    /*
     * The scheduled brief. Its preview is built only when asked for (?preview=1)
     * because rendering runs the snapshot and every rule again — no reason to pay
     * that on a page that already did.
     */
    $pulseWeek    = BusinessPulse::week_key();
    $pulseRange   = BusinessPulse::week_range();
    $pulseSent    = BusinessPulse::already_sent($user_id, $pulseWeek);
    $pulseLast    = BusinessPulse::last_sent($user_id);
    $pulseOwners  = count(BusinessPulse::recipients());
    $pulsePreview = isset($_GET['preview']) ? BusinessPulse::build($user_id) : null;
    $pulseHistory = BusinessPulse::history_available();
    $pulseBaseline = $pulseHistory ? BusinessPulse::baseline($user_id, $pulseWeek) : null;

    $mutedCodes = $settings['muted_codes'];
    $mutedNow   = [];
    foreach (OpsPulse::muted_findings($user_id) as $mutedFinding) {
        $mutedNow[(string) $mutedFinding['code']] = $mutedFinding;
    }

    $soonKey   = 'next_' . (int) $settings['soon_days'] . '_days';
    $soonCount = (int) ($snapshot['weddings'][$soonKey] ?? 0);

    /*
     * Names here are prefixed because include/sidebar.php is pulled in further
     * down the page and owns `$badge` for the nav counters — a page-local
     * closure called by that name is an array by the time the findings render.
     */
    $pulseBadge = static fn(string $severity): string => match ($severity) {
        'high'   => 'danger',
        'medium' => 'warning',
        default  => 'secondary',
    };

    /** How many findings at a severity, from the by_severity tally. */
    $pulseAt = static fn(string $severity): int => (int) ($insights['by_severity'][$severity] ?? 0);

    /** The evidence objects, flattened to "key value" chips — numbers and ids only. */
    $evidenceChips = static function (array $finding): array {
        $chips = [];
        foreach ((array) ($finding['evidence']['subjects'] ?? []) as $subject) {
            if (!is_array($subject)) {
                continue;
            }
            $parts = [];
            foreach ($subject as $key => $value) {
                $parts[] = str_replace('_', ' ', (string) $key) . ' ' . (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
            }
            $chips[] = implode(' · ', $parts);
        }
        return $chips;
    };

    /** One line explaining what a threshold actually does, for the form hint. */
    $hints = [
        'soon_days'       => 'A wedding this close with an unconfirmed account is flagged.',
        'watch_days'      => 'How far ahead the RSVP and balance rules look.',
        'deal_stale_days' => 'A vendor deal pending longer than this is worth chasing.',
        'planner_load'    => 'More upcoming weddings than this on one planner is an overload finding.',
        'renewal_days'    => 'Subscriptions and trials ending inside this window are flagged.',
        'capacity_share'  => 'How close to a booked package limit counts as tight.',
    ];

    $labels = [
        'soon_days'       => 'Wedding is "soon" within (days)',
        'watch_days'      => 'RSVP and balance window (days)',
        'deal_stale_days' => 'Stale vendor deal after (days)',
        'planner_load'    => 'Planner load ceiling (weddings)',
        'renewal_days'    => 'Renewal warning window (days)',
        'capacity_share'  => 'Capacity watch band (% of limit)',
    ];
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); // what include/sidebar.php chips the reader with ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Business Pulse - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .stat-card { background: #fff; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px;
                     box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-left: 4px solid #0F172A; }
        .stat-card h6 { font-size: 11px; text-transform: uppercase; color: #64748B; margin: 0; letter-spacing: .06em; }
        .stat-card .value { font-size: 24px; font-weight: 700; color: #0F172A; }
        .stat-card .hint { font-size: 11.5px; color: #64748B; }
        .stat-card.bad { border-left-color: var(--danger); }
        .stat-card.warn { border-left-color: var(--warning); }
        .stat-card.good { border-left-color: var(--success); }
        .pulse-finding { border: 1px solid #E2E8F0; border-left-width: 4px; border-radius: 10px; padding: 14px 16px; margin-bottom: 12px; background: #fff; }
        .pulse-finding.high { border-left-color: var(--danger); }
        .pulse-finding.medium { border-left-color: var(--warning); }
        .pulse-finding.low { border-left-color: var(--stone-300); }
        .pulse-finding h6 { font-size: 14px; margin: 0 0 4px; color: #0F172A; }
        .pulse-finding p { font-size: 12.5px; color: #475569; margin: 0 0 8px; }
        .pulse-chip { display: inline-block; font-size: 11.5px; color: #334155; background: #F1F5F9;
                      border-radius: 999px; padding: 2px 10px; margin: 0 6px 6px 0; }
        .pulse-action { font-size: 12.5px; color: #0F172A; }
        .pulse-meta { font-size: 11.5px; color: #64748B; }
        .pulse-thresholds label { font-size: 12.5px; font-weight: 600; color: #0F172A; margin-bottom: 2px; }
        .pulse-thresholds .hint { font-size: 11.5px; color: #64748B; margin-bottom: 8px; }
        .pulse-preview { background: #0F172A; color: #E2E8F0; border-radius: 10px; padding: 16px 18px;
                         font-size: 12px; line-height: 1.5; white-space: pre; overflow: auto;
                         max-height: 520px; margin: 0; }
        .pulse-brief-list { font-size: 12px; color: #475569; padding-left: 18px; margin-bottom: 10px; }
        .pulse-brief-list li { margin-bottom: 3px; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-chart-donut-variant" style="color:#0F172A"></i> Business Pulse</h4>
            <p class="text-muted mb-0" style="font-size: 13px;">
                the platform's own numbers and the findings worth acting on — as of <?= htmlspecialchars((string) $snapshot['generated_at']); ?>.
                The same figures the AI assistant reads through <code>system_status</code> and <code>list_insights</code>.
            </p>
        </div>
        <div class="text-muted" style="font-size:12px;">
            <i class="mdi mdi-shield-check"></i> aggregates and internal ids only — no names, phones or payment references
        </div>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <?php if (!UserPrefs::available()): ?>
        <div class="alert alert-warning">
            <strong><i class="mdi mdi-database-alert"></i> tbluser_prefs is not installed.</strong>
            Apply <code>migrations/v2.12_user_preferences.sql</code> to tune thresholds or mute findings for your account.
            The page works at the defaults meanwhile; nothing else breaks.
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-3">
            <div class="stat-card">
                <h6>Weddings upcoming</h6>
                <div class="value"><?= (int) $snapshot['weddings']['upcoming']; ?></div>
                <div class="hint"><?= $soonCount; ?> inside the next <?= (int) $settings['soon_days']; ?> days</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card <?= (int) $snapshot['weddings']['awaiting_confirmation'] > 0 ? 'warn' : ''; ?>">
                <h6>Unconfirmed / unassigned</h6>
                <div class="value"><?= (int) $snapshot['weddings']['awaiting_confirmation']; ?> <span class="text-muted" style="font-size:15px;">/ <?= (int) $snapshot['weddings']['unassigned_planner']; ?></span></div>
                <div class="hint">couple accounts still pending / weddings with no planner</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <h6>Package value upcoming</h6>
                <div class="value"><?= htmlspecialchars(format_ksh((float) $snapshot['money']['package_value_upcoming'])); ?></div>
                <div class="hint"><?= htmlspecialchars(format_ksh((float) $snapshot['money']['collected_upcoming'])); ?> collected ·
                    <?= htmlspecialchars(format_ksh((float) $snapshot['money']['outstanding_upcoming'])); ?> due</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <h6>Subscription MRR</h6>
                <div class="value"><?= htmlspecialchars(format_ksh((float) $snapshot['money']['subscription_mrr'])); ?></div>
                <div class="hint"><?= (int) $snapshot['money']['active_subscriptions']; ?> active ·
                    <?= (int) $snapshot['money']['subscriptions_expiring_soon']; ?> expiring in <?= (int) $settings['renewal_days']; ?> days</div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3">
            <div class="stat-card">
                <h6>RSVP response</h6>
                <div class="value"><?= (int) round(((float) $snapshot['guests']['rsvp_response_rate']) * 100); ?>%</div>
                <div class="hint"><?= (int) $snapshot['guests']['rsvp_answered']; ?> of <?= (int) $snapshot['guests']['total']; ?> guests answered</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card <?= (int) $snapshot['tasks']['overdue'] > 0 ? 'warn' : 'good'; ?>">
                <h6>Overdue checklist tasks</h6>
                <div class="value"><?= (int) $snapshot['tasks']['overdue']; ?></div>
                <div class="hint"><?= (int) $snapshot['tasks']['open']; ?> open in total</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card <?= (int) $snapshot['operations']['jobs_troubled'] > 0 ? 'bad' : 'good'; ?>">
                <h6>Jobs troubled</h6>
                <div class="value"><?= (int) $snapshot['operations']['jobs_troubled']; ?></div>
                <div class="hint"><?= (int) $snapshot['operations']['approvals_pending']; ?> AI request(s) awaiting your decision</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card <?= $pulseAt('high') > 0 ? 'bad' : 'good'; ?>">
                <h6>Findings</h6>
                <div class="value"><?= (int) $insights['finding_count']; ?></div>
                <div class="hint"><?= $pulseAt('high'); ?> high · <?= $pulseAt('medium'); ?> medium · <?= $pulseAt('low'); ?> low<?= (int) $insights['muted_count'] > 0 ? ' · ' . (int) $insights['muted_count'] . ' muted' : ''; ?></div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4" style="border:none; border-radius:14px;">
                <div class="card-body">
                    <h5 class="mb-1">What needs attention</h5>
                    <p class="text-muted mb-3" style="font-size:12.5px;">
                        Every finding comes from a fixed rule and carries the numbers that fired it. Nothing here is generated by a model,
                        and nothing here can be changed from this page — each one links to where a person acts.
                    </p>

                    <?php if ($findings === []): ?>
                        <div class="text-muted" style="font-size:13px;">
                            <i class="mdi mdi-check-circle-outline" style="color:#059669"></i>
                            No rule is firing at these thresholds. Widen a window on the right to look further ahead.
                        </div>
                    <?php else: ?>
                        <?php foreach ($findings as $finding): ?>
                            <?php $chips = $evidenceChips($finding); ?>
                            <div class="pulse-finding <?= htmlspecialchars((string) $finding['severity']); ?>">
                                <div class="d-flex justify-content-between align-items-start flex-wrap">
                                    <div class="pr-3">
                                        <h6>
                                            <span class="badge badge-<?= $pulseBadge((string) $finding['severity']); ?>"><?= htmlspecialchars(strtoupper((string) $finding['severity'])); ?></span>
                                            <?= htmlspecialchars((string) $finding['title']); ?>
                                        </h6>
                                        <p><?= htmlspecialchars((string) $finding['detail']); ?></p>
                                        <?php foreach ($chips as $chip): ?>
                                            <span class="pulse-chip"><?= htmlspecialchars($chip); ?></span>
                                        <?php endforeach; ?>
                                        <div class="pulse-action mt-1">
                                            <i class="mdi mdi-arrow-right-bold-circle-outline"></i>
                                            <?= htmlspecialchars((string) $finding['suggested_action']); ?>
                                        </div>
                                        <div class="mt-2">
                                            <a href="<?= htmlspecialchars((string) $finding['link']); ?>" class="btn btn-sm btn-outline-dark">
                                                Open <?= htmlspecialchars((string) $finding['link']); ?>
                                            </a>
                                        </div>
                                    </div>
                                    <form method="post" action="ops_pulse.php" class="mb-0">
                                        <?= $session->csrf_field(); ?>
                                        <input type="hidden" name="action" value="mute">
                                        <input type="hidden" name="code" value="<?= htmlspecialchars((string) $finding['code']); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                title="Hide this finding from the list and from the AI, until you unmute it">
                                            <i class="mdi mdi-bell-off-outline mr-1"></i> Mute
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($mutedCodes !== []): ?>
                        <hr>
                        <h6 class="mb-1">Muted — accepted, not forgotten</h6>
                        <p class="text-muted mb-2" style="font-size:12px;">
                            These rules still run; their findings are withheld from this list and from the assistant.
                            A muted rule stays listed here even when it is quiet, so an old decision is always reviewable.
                        </p>
                        <?php foreach ($mutedCodes as $mutedCode): ?>
                            <?php $mutedFinding = $mutedNow[$mutedCode] ?? null; ?>
                            <div class="d-flex justify-content-between align-items-center flex-wrap py-2" style="border-bottom:1px solid #E2E8F0;">
                                <div style="font-size:12.5px;">
                                    <?php if ($mutedFinding !== null): ?>
                                        <span class="badge badge-<?= $pulseBadge((string) $mutedFinding['severity']); ?>"><?= htmlspecialchars(strtoupper((string) $mutedFinding['severity'])); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-light">quiet</span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars(OpsPulse::rule_label((string) $mutedCode)); ?>
                                    <span class="pulse-meta">
                                        <?= $mutedFinding !== null
                                            ? '— ' . (int) $mutedFinding['count'] . ' right now'
                                            : '— not firing at these thresholds'; ?>
                                    </span>
                                </div>
                                <form method="post" action="ops_pulse.php" class="mb-0">
                                    <?= $session->csrf_field(); ?>
                                    <input type="hidden" name="action" value="unmute">
                                    <input type="hidden" name="code" value="<?= htmlspecialchars((string) $mutedCode); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                        <i class="mdi mdi-bell-ring-outline mr-1"></i> Unmute
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm mb-4" style="border:none; border-radius:14px;">
                <div class="card-body">
                    <h5 class="mb-1">Thresholds</h5>
                    <p class="text-muted mb-3" style="font-size:12.5px;">
                        Saved for your account only, and used by both this page and the AI tools your token reaches.
                        A value outside the allowed range is refused rather than clamped, and an unsaved field refuses the whole save.
                    </p>
                    <form method="post" action="ops_pulse.php" class="pulse-thresholds">
                        <?= $session->csrf_field(); ?>
                        <input type="hidden" name="action" value="save_thresholds">
                        <?php foreach ($labels as $key => $label): ?>
                            <div class="form-group mb-3">
                                <label for="th_<?= htmlspecialchars($key); ?>"><?= htmlspecialchars($label); ?></label>
                                <?php if ($key === 'capacity_share'): ?>
                                    <select class="form-control form-control-sm" id="th_<?= htmlspecialchars($key); ?>" name="<?= htmlspecialchars($key); ?>">
                                        <?php foreach (array_keys(PackageCapacity::shares()) as $share): ?>
                                            <option value="<?= (int) $share; ?>" <?= (int) $settings[$key] === (int) $share ? 'selected' : ''; ?>>
                                                <?= (int) $share; ?>%
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <?php [$min, $max] = OpsPulse::SETTING_RANGES[$key]; ?>
                                    <input type="number" class="form-control form-control-sm" id="th_<?= htmlspecialchars($key); ?>"
                                           name="<?= htmlspecialchars($key); ?>" value="<?= (int) $settings[$key]; ?>"
                                           min="<?= (int) $min; ?>" max="<?= (int) $max; ?>" step="1">
                                <?php endif; ?>
                                <div class="hint"><?= htmlspecialchars($hints[$key]); ?></div>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-sm btn-dark">
                            <i class="mdi mdi-content-save mr-1"></i> Save thresholds
                        </button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm mb-4" id="weekly-brief" style="border:none; border-radius:14px;">
                <div class="card-body">
                    <h5 class="mb-1">Weekly brief — Mondays</h5>
                    <p class="text-muted mb-2" style="font-size:12.5px;">
                        One plain-text email every Monday at 06:00, rendered here from these same aggregates.
                        When an earlier snapshot exists, every figure carries its change since then.
                        No model writes it, so no customer data leaves the platform.
                    </p>
                    <ul class="pulse-brief-list">
                        <li>Goes to <strong><?= (int) $pulseOwners; ?> owner<?= $pulseOwners === 1 ? '' : 's'; ?></strong> — super admins with an address on file.
                            Set <code>WPMS_PULSE_TO</code> for a shared mailbox instead.</li>
                        <li>This week (<?= htmlspecialchars($pulseRange['label']); ?>):
                            <?= $pulseSent ? '<strong>sent</strong>' : 'not sent yet'; ?>.</li>
                        <?php if ($pulseLast !== ''): ?>
                            <li>Last delivered week: <code><?= htmlspecialchars($pulseLast); ?></code>.</li>
                        <?php endif; ?>
                        <li>
                            <?php if (!$pulseHistory): ?>
                                Week-over-week change is off — apply
                                <code>migrations/v2.23_business_pulse_snapshots.sql</code>.
                            <?php elseif ($pulseBaseline !== null):
                                $pulsePrevRange = BusinessPulse::range_for_week($pulseBaseline['week']); ?>
                                Compares against week <?= htmlspecialchars(substr($pulseBaseline['week'], -2)); ?>
                                (<?= htmlspecialchars($pulsePrevRange['label']); ?>), the last snapshot taken before this week.
                            <?php else: ?>
                                No earlier snapshot on record yet — next week's brief will show the change.
                            <?php endif; ?>
                        </li>
                        <li>Uses <em>your</em> thresholds and mutes. Each owner's brief can differ.</li>
                    </ul>

                    <form method="post" action="ops_pulse.php" class="mb-2">
                        <?= $session->csrf_field(); ?>
                        <input type="hidden" name="action" value="send_pulse">
                        <button type="submit" class="btn btn-sm btn-dark">
                            <i class="mdi mdi-email-fast-outline mr-1"></i> Email my brief now
                        </button>
                    </form>

                    <a href="ops_pulse.php?preview=1#weekly-brief-preview" class="btn btn-sm btn-outline-secondary">
                        <i class="mdi mdi-file-eye-outline mr-1"></i>
                        <?= $pulsePreview === null ? 'Preview this week\'s brief' : 'Refresh the preview'; ?>
                    </a>

                </div>
            </div>

            <div class="card shadow-sm mb-4" style="border:none; border-radius:14px;">
                <div class="card-body">
                    <h6 class="mb-2">Where the numbers come from</h6>
                    <p class="text-muted mb-0" style="font-size:12px;">
                        Weddings from <code>tblweddingbook</code>, money from <code>tbl_liquidation</code>,
                        subscriptions from <code>tblsubscriptions</code>, guests from <code>tblguest</code>,
                        checklist from <code>tblchecklist</code>, vendor deals from <code>tblvendor_bookings</code>,
                        AI activity from <code>tblapi_audit</code> and <code>tblapi_approvals</code>, jobs from
                        <code>tbljob_heartbeats</code>. Time arithmetic is done in SQL, so a server timezone change does not move a window.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <?php if ($pulsePreview !== null): ?>
        <?php /* Full width on purpose: the brief is aligned plain text (68 columns),
                 and a preview squeezed into the right-hand column wraps it into
                 something that looks nothing like the email the owner receives. */ ?>
        <div class="row" id="weekly-brief-preview">
            <div class="col-12">
                <div class="card shadow-sm mb-4" style="border:none; border-radius:14px;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                            <div>
                                <h6 class="mb-0">This week's brief, exactly as it will be emailed</h6>
                                <div class="pulse-meta">Subject: <?= htmlspecialchars($pulsePreview['subject']); ?></div>
                            </div>
                            <a href="ops_pulse.php" class="btn btn-sm btn-outline-secondary">
                                <i class="mdi mdi-close mr-1"></i> Close preview
                            </a>
                        </div>
                        <pre class="pulse-preview"><?= htmlspecialchars($pulsePreview['text']); ?></pre>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'include/footer.php'; ?>
</body>
</html>
