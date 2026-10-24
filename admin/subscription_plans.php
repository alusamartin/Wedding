<?php include 'include/init.php'; ?>
<?php
/**
 * Plan definitions — the operator's plan editor.
 *
 * tblsubscription_plans used to be READ twice (Subscription::get_all_plans()
 * for the purchase list, Subscription::get_plan() for the price) and WRITTEN
 * nowhere at all: every row existed only in the seed dump. So subscription.php
 * could truthfully print "Plans will appear here once the team defines them"
 * while no screen, and no role, could define one — a fresh install without the
 * seed had a paywall nobody could satisfy, and even with the seed no price,
 * wedding limit, feature list or is_active flag could ever change without
 * hand-written SQL. This page is the writer.
 *
 * Operator-only (super admin), for the same reason vendor approval is: a plan's
 * price is the platform's revenue, not a planner's setting.
 *
 * RETIRE, DON'T DELETE. tblsubscriptions.plan stores the plan_slug, so a plan
 * somebody has already bought must keep resolving — for their subscription
 * history, for the renewal texts and for the M-Pesa callback that activates it.
 * Retiring flips is_active to 0, which takes the plan off the purchase list
 * (Subscription::get_all_plans) and off the purchase path
 * (Subscription::get_purchasable_plan) while leaving every historical
 * subscription readable. There is deliberately no delete action.
 *
 * ACCOUNTABLE, NOT ANONYMOUS. A plan's price is the platform's revenue, so
 * every change to this table is written to tblsubscription_plan_changes with
 * the field, the before/after values and the operator's user id (v2.8). Until
 * then a price was simply overwritten: the current value was all that survived,
 * and no one could say who last moved it or when. Each card below shows the
 * last price change — who, when — and the full history is one click away.
 */
    require_staff_subscribed();

    if (!is_super_admin()) {
        /*
         * Land them on the plan list they were looking for, with the reason —
         * subscription.php renders session messages (dashboard.php does not, so
         * a refusal sent there would be silent). The gate sits BEFORE the POST
         * handlers below, so a non-operator never reaches one.
         */
        $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-shield-alert"></i></strong> Only the operator can define subscription plans.</div>');
        redirect_to('subscription.php');
        die();
    }

    $plans = Subscription::all_plans();

    // ---- Create or edit a plan definition (POST + CSRF) -------------------
    if (isset($_POST['save_plan'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
            redirect_to('subscription_plans.php');
            die();
        }

        $id       = (int) ($_POST['plan_id'] ?? 0);
        $slug     = strtolower(trim((string) ($_POST['plan_slug'] ?? '')));
        $name     = trim((string) ($_POST['name'] ?? ''));
        $price    = (float) ($_POST['price'] ?? 0);
        $limit    = (int) ($_POST['wedding_limit'] ?? 0);
        $features = trim((string) ($_POST['features'] ?? ''));

        $errors = [];
        if ($name === '') {
            $errors[] = 'Give the plan a name.';
        }
        if ($price < 0) {
            $errors[] = 'The monthly price cannot be negative.';
        }
        if ($limit < 0) {
            $errors[] = 'The wedding limit cannot be negative.';
        }
        if ($id === 0) {
            // The key is only chosen at creation — it is what subscriptions store.
            if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,19}$/', $slug)) {
                $errors[] = 'The plan key must be 2–20 characters: lowercase letters, numbers and dashes.';
            } elseif (array_filter($plans, static fn($p) => (string) $p->plan_slug === $slug) !== []) {
                $errors[] = 'The plan key "' . htmlspecialchars($slug) . '" is already taken — a key that has been sold cannot be redefined.';
            }
        }

        if ($errors !== []) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> '
                . implode(' ', $errors) . '</div>');
            redirect_to('subscription_plans.php');
            die();
        }

        if ($id === 0) {
            $newId = Subscription::create_plan($slug, $name, $price, $limit, $features, (int) $_SESSION['id']);
            $session->message($newId > 0
                ? '<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> <strong>'
                    . htmlspecialchars($name) . '</strong> is live — planners can buy it from the subscription page now.</div>'
                : '<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> That plan key is already in use.</div>');
        } else {
            Subscription::update_plan($id, $name, $price, $limit, $features, (int) $_SESSION['id']);
            $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> <strong>'
                . htmlspecialchars($name) . '</strong> has been updated.</div>');
        }
        redirect_to('subscription_plans.php');
        die();
    }

    // ---- Retire / put back on sale ----------------------------------------
    if (isset($_POST['set_plan_active'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
            redirect_to('subscription_plans.php');
            die();
        }
        $id     = (int) ($_POST['plan_id'] ?? 0);
        $active = ((int) ($_POST['active'] ?? 0) === 1);
        $row    = null;
        foreach ($plans as $p) {
            if ((int) $p->id === $id) {
                $row = $p;
                break;
            }
        }
        if ($row === null) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> That plan no longer exists.</div>');
            redirect_to('subscription_plans.php');
            die();
        }
        Subscription::set_plan_active($id, $active, (int) $_SESSION['id']);
        $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> <strong>'
            . htmlspecialchars((string) $row->name) . '</strong> '
            . ($active
                ? 'is back on sale.'
                : 'has been retired — it is off the purchase list and cannot be bought, while existing subscribers keep their plan.')
            . '</div>');
        redirect_to('subscription_plans.php');
        die();
    }
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Plan Definitions - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .plan-def { border-radius: 10px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 18px 20px; margin-bottom: 16px; border-left: 4px solid #10B981; }
        .plan-def.retired { border-left-color: var(--stone-300); background: #F8FAFC; }
        .plan-key { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; background: #EEF2F7; color: #334155; padding: 2px 8px; border-radius: 20px; }
        .pill { font-size: 11px; padding: 3px 10px; border-radius: 20px; }
        .pill-live { background: #E7F4EE; color: #065F46; }
        .pill-retired { background: #EEF2F7; color: #475569; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4" style="border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
        <h4><i class="mdi mdi-tune-variant"></i> Plan Definitions</h4>
        <a href="subscription.php" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-arrow-left"></i> Subscription page</a>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <div class="alert alert-info" role="alert">
        <strong><i class="mdi mdi-information-outline"></i> How plans behave.</strong>
        A plan is <em>live</em> the moment you save it: it appears on the subscription page, where planners buy it through M-Pesa.
        <strong>Retiring</strong> a plan takes it off that page and refuses new purchases, but every planner who already bought it keeps it —
        their subscription, its history and its renewals all keep resolving. That is why plans are retired rather than deleted.
        <br>
        <strong>Every change is recorded.</strong> This page writes an entry for each field you change, with your name and the time,
        so the price a planner paid under stays answerable long after it changed. Each card below shows the last price change and
        the whole history is one click away.
    </div>

    <!-- Existing definitions -->
    <?php if (empty($plans)): ?>
        <div class="alert alert-warning" role="alert">
            <strong>No plans are defined yet.</strong> The subscription page is showing planners
            "Plans will appear here once the team defines them" with nothing to buy — define the first plan below.
        </div>
    <?php else: ?>
        <?php
            /*
             * Ledger rows are data; these two closures are this page's language.
             * "Retired", "unlimited" and "defined as" are wording decisions, so
             * they live beside the markup rather than in the model, and a
             * ledger row with no author reads as the system rather than blank.
             */
            $who = static function (object $c): string {
                $name = trim((string) ($c->firstname ?? '') . ' ' . (string) ($c->lastname ?? ''));
                return $name !== '' ? $name : 'the system (no signed-in operator)';
            };
            $changeLine = static function (object $c): string {
                $text = static function (string $v, string $field): string {
                    return match ($field) {
                        'price'         => format_ksh((float) $v, 2),
                        'wedding_limit' => ((int) $v === 0 ? 'unlimited' : (int) $v . ' weddings/year'),
                        'is_active'     => ((int) $v === 1 ? 'on sale' : 'retired'),
                        'features'      => trim_body($v, 44),
                        default         => $v,
                    };
                };
                $field = (string) $c->field_name;
                $new   = $text((string) $c->new_value, $field);
                if ($c->old_value === null) {
                    return 'defined as ' . $new;
                }
                return $text((string) $c->old_value, $field) . ' → ' . $new;
            };
        ?>
        <?php foreach ($plans as $plan): ?>
            <?php
                $live    = (int) $plan->is_active === 1;
                $subs    = Subscription::subscriber_count((string) $plan->plan_slug);
                $liveNow = Subscription::active_subscriber_count((string) $plan->plan_slug);
                // One read, two views: the whole history for the disclosure, and
                // the price rows for the "who moved the money" sentence above it.
                $changes = Subscription::plan_changes((int) $plan->id);
                $prices  = array_values(array_filter($changes, static fn($c): bool => (string) $c->field_name === 'price'));
                $lastPrice = $prices[0] ?? null;
                $defined   = null;
                foreach ($prices as $p) {          // the row that created the plan
                    if ($p->old_value === null) {
                        $defined = $p;
                    }
                }
            ?>
            <div class="plan-def <?= $live ? '' : 'retired'; ?>">
                <form method="post" class="form-inline flex-wrap" style="gap: 10px;">
                    <?= $session->csrf_field(); ?>
                    <input type="hidden" name="save_plan" value="1">
                    <input type="hidden" name="plan_id" value="<?= (int) $plan->id; ?>">

                    <div class="mr-2">
                        <label class="d-block mb-0" style="font-size: 11px; color: #64748B; text-transform: uppercase; letter-spacing: .04em;">Plan key</label>
                        <span class="plan-key"><?= htmlspecialchars((string) $plan->plan_slug); ?></span>
                    <span class="pill <?= $live ? 'pill-live' : 'pill-retired'; ?> ml-1"><?= $live ? 'Live' : 'Retired'; ?></span>
                        <span class="text-muted ml-1" style="font-size: 11px;"
                              title="<?= $subs; ?> planner<?= $subs === 1 ? '' : 's'; ?> have ever paid on this plan; <?= $liveNow; ?> subscription<?= $liveNow === 1 ? '' : 's'; ?> live today">
                            <?= $subs; ?> subscriber<?= $subs === 1 ? '' : 's'; ?> · <?= $liveNow; ?> live now
                        </span>
                    </div>

                    <div class="mr-2" style="min-width: 180px;">
                        <label class="d-block mb-0" style="font-size: 11px; color: #64748B;">Name</label>
                        <input type="text" name="name" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $plan->name); ?>" required>
                    </div>
                    <div class="mr-2" style="width: 130px;">
                        <label class="d-block mb-0" style="font-size: 11px; color: #64748B;">KSh / month</label>
                        <input type="number" name="price" class="form-control form-control-sm" min="0" step="1" value="<?= htmlspecialchars((string) (float) $plan->price); ?>" required>
                    </div>
                    <div class="mr-2" style="width: 130px;">
                        <label class="d-block mb-0" style="font-size: 11px; color: #64748B;">Weddings / year</label>
                        <input type="number" name="wedding_limit" class="form-control form-control-sm" min="0" value="<?= (int) $plan->wedding_limit; ?>">
                        <small class="text-muted" style="font-size: 10px;">0 = unlimited</small>
                    </div>
                    <div class="mr-2 flex-grow-1" style="min-width: 240px;">
                        <label class="d-block mb-0" style="font-size: 11px; color: #64748B;">Features (comma separated)</label>
                        <input type="text" name="features" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $plan->features); ?>">
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-sm btn-success"><i class="mdi mdi-content-save"></i> Save</button>
                    </div>
                </form>

                <form method="post" class="mt-2">
                    <?= $session->csrf_field(); ?>
                    <input type="hidden" name="set_plan_active" value="1">
                    <input type="hidden" name="plan_id" value="<?= (int) $plan->id; ?>">
                    <input type="hidden" name="active" value="<?= $live ? '0' : '1'; ?>">
                    <button type="submit" class="btn btn-sm <?= $live ? 'btn-outline-secondary' : 'btn-outline-success'; ?>">
                        <i class="mdi <?= $live ? 'mdi-eye-off' : 'mdi-eye'; ?>"></i> <?= $live ? 'Retire this plan' : 'Put back on sale'; ?>
                    </button>
                </form>

                <!-- Who moved the price, and when (tblsubscription_plan_changes) -->
                <div class="mt-2" style="font-size: 11.5px; color: #64748B;">
                    <?php if ($lastPrice === null): ?>
                        <i class="mdi mdi-lock-outline"></i> No price change on record for this plan.
                    <?php elseif ($lastPrice->old_value === null): ?>
                        <i class="mdi mdi-tag-outline"></i> Price <strong><?= format_ksh((float) $lastPrice->new_value, 2); ?></strong>
                        unchanged since <?= htmlspecialchars($who($lastPrice)); ?> defined it
                        on <?= htmlspecialchars(format_date_kd((string) $lastPrice->changed_at, true)); ?>.
                    <?php else: ?>
                        <i class="mdi mdi-tag-outline"></i> Price last changed by <strong><?= htmlspecialchars($who($lastPrice)); ?></strong>
                        on <?= htmlspecialchars(format_date_kd((string) $lastPrice->changed_at, true)); ?>
                        — from <?= format_ksh((float) $lastPrice->old_value, 2); ?> to
                        <strong><?= format_ksh((float) $lastPrice->new_value, 2); ?></strong>.
                        <?php if ($defined !== null && $defined !== $lastPrice): ?>
                            Defined at <?= format_ksh((float) $defined->new_value, 2); ?> by
                            <?= htmlspecialchars($who($defined)); ?> on <?= htmlspecialchars(format_date_kd((string) $defined->changed_at)); ?>.
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if ($changes !== []): ?>
                <details class="mt-1">
                    <summary style="font-size: 11.5px; color: #4C6EF5; cursor: pointer;">
                        Full change history (<?= count($changes); ?> entr<?= count($changes) === 1 ? 'y' : 'ies'; ?>, newest first)
                    </summary>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm mb-0" style="font-size: 11.5px;">
                            <thead>
                                <tr>
                                    <th style="border-top:none;">When</th>
                                    <th style="border-top:none;">Who</th>
                                    <th style="border-top:none;">Field</th>
                                    <th style="border-top:none;">Change</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($changes as $c): ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?= htmlspecialchars(format_date_kd((string) $c->changed_at, true)); ?></td>
                                    <td><?= htmlspecialchars($who($c)); ?></td>
                                    <td><code style="font-size:10.5px;"><?= htmlspecialchars((string) $c->field_name); ?></code></td>
                                    <td><?= htmlspecialchars($changeLine($c)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($changes) >= 25): ?>
                        <small class="text-muted">Showing the 25 most recent changes.</small>
                    <?php endif; ?>
                </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- New definition -->
    <h5 class="mt-5 mb-3"><i class="mdi mdi-plus-circle-outline"></i> Define a new plan</h5>
    <div class="plan-def" style="border-left-color: var(--info);">
        <form method="post" class="form-inline flex-wrap" style="gap: 10px;">
            <?= $session->csrf_field(); ?>
            <input type="hidden" name="save_plan" value="1">
            <input type="hidden" name="plan_id" value="0">

            <div class="mr-2" style="width: 160px;">
                <label class="d-block mb-0" style="font-size: 11px; color: #64748B;">Plan key</label>
                <input type="text" name="plan_slug" class="form-control form-control-sm" placeholder="e.g. premium" pattern="[a-z0-9][a-z0-9\-]{1,19}" required>
                <small class="text-muted" style="font-size: 10px;">Permanent — subscriptions store it</small>
            </div>
            <div class="mr-2" style="min-width: 180px;">
                <label class="d-block mb-0" style="font-size: 11px; color: #64748B;">Name</label>
                <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Premium" required>
            </div>
            <div class="mr-2" style="width: 130px;">
                <label class="d-block mb-0" style="font-size: 11px; color: #64748B;">KSh / month</label>
                <input type="number" name="price" class="form-control form-control-sm" min="0" step="1" placeholder="4999" required>
            </div>
            <div class="mr-2" style="width: 130px;">
                <label class="d-block mb-0" style="font-size: 11px; color: #64748B;">Weddings / year</label>
                <input type="number" name="wedding_limit" class="form-control form-control-sm" min="0" placeholder="0">
                <small class="text-muted" style="font-size: 10px;">0 = unlimited</small>
            </div>
            <div class="mr-2 flex-grow-1" style="min-width: 240px;">
                <label class="d-block mb-0" style="font-size: 11px; color: #64748B;">Features (comma separated)</label>
                <input type="text" name="features" class="form-control form-control-sm" placeholder="Guest list, Budget tracker">
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-sm btn-primary"><i class="mdi mdi-plus"></i> Create plan</button>
            </div>
        </form>
    </div>
</div>

<?php include_once 'include/footer.php'; ?>
</body>
</html>
