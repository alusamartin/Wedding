<?php include_once 'admin/include/init.php'; ?>
<?php
/**
 * Public vendor self-registration — the marketplace's front door.
 *
 * A wedding business creates its own account and listing here: the person we
 * talk to, the business, up to three priced services and one package. The
 * listing is created UNAPPROVED and stays out of vendors.php until an operator
 * approves it in admin/vendors.php; the account itself is usable immediately,
 * so the vendor can add services, packages and a logo from their dashboard
 * while they wait.
 *
 * Guards, in the order they run: a signed-in visitor is sent to their own home
 * (never shown the form twice), CSRF, the per-email/per-IP signup throttle, and
 * validation + uniqueness in VendorSignup::create(). Every attempt is recorded
 * with SignupGuard, success or not — a flood is the attack.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_vendor'])) {
    if (!$session->verify_csrf()) {
        $session->message('
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
              <strong><i class="mdi mdi-alert"></i></strong>  Invalid security token. Please try again.
              <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>');
        redirect_to('vendor_signup.php');
        die();
    }

    $email = strtolower(clean($_POST['email'] ?? ''));

    $wait = SignupGuard::secondsRemaining($email);
    if ($wait > 0) {
        $mins = max(1, (int) ceil($wait / 60));
        $session->message('
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
              <strong><i class="mdi mdi-clock-alert-outline"></i></strong>  Too many sign-up attempts. Please try again in ' . $mins . ' minute(s).
              <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>');
        redirect_to('vendor_signup.php');
        die();
    }

    $result = VendorSignup::create([
        'firstname'      => $_POST['firstname'] ?? '',
        'lastname'       => $_POST['lastname'] ?? '',
        'email'          => $_POST['email'] ?? '',
        'password'       => $_POST['password'] ?? '',
        'phone'          => $_POST['phone'] ?? '',
        'business_name'  => $_POST['business_name'] ?? '',
        'category'       => $_POST['category'] ?? '',
        'description'    => $_POST['description'] ?? '',
        'business_email' => $_POST['business_email'] ?? '',
        'location'       => $_POST['location'] ?? '',
        'county'         => $_POST['county'] ?? '',
        'services'       => $_POST['services'] ?? [],
        'package'        => $_POST['package'] ?? [],
    ]);
    SignupGuard::recordAttempt($email, (bool) $result['ok']);

    if (!$result['ok']) {
        $session->message('
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
              <i class="mdi mdi-alert mr-2"></i>' . htmlspecialchars($result['error']) . '
              <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>');
        redirect_to('vendor_signup.php');
        die();
    }

    /*
     * Sign them in: the account works now, the listing waits for approval.
     * account_type is what makes is_vendor() true — vendors are tblusers rows
     * with access_level 0, and couples are a DIFFERENT table whose ids look
     * alike, so the flag is the only reliable discriminator.
     */
    $user = Users::find_by_id((int) $result['user_id']);
    if ($user !== false && $user !== null) {
        $session->login($user);
        $_SESSION['account_type'] = 'staff';
    }

    $notes = [];
    $notes[] = '<strong><i class="mdi mdi-check"></i> Your account is ready.</strong> You are signed in.';
    $notes[] = 'Your listing is <strong>waiting for approval</strong> — it is not visible to couples yet. '
        . 'Add your services, packages and photos meanwhile; we will switch it on after a quick look.';
    if ((int) $result['services'] > 0) {
        $notes[] = (int) $result['services'] . ' service' . ((int) $result['services'] === 1 ? '' : 's') . ' saved.';
    }
    if ($result['services_skipped']) {
        $notes[] = 'Services could not be saved on this install yet — add them from your dashboard once the services table is in place.';
    }
    $session->message('<div class="alert alert-success">' . implode(' ', $notes) . '</div>');

    redirect_to(vendor_redirect_target((int) $result['user_id']));
    die();
}

// Already signed in? To the home their role owns — never show the form twice.
if ($session->is_signed_in() && isset($_SESSION['id'])) {
    if (is_vendor()) {
        redirect_to(vendor_redirect_target((int) $_SESSION['id']));
    } elseif (is_staff()) {
        redirect_to(login_redirect_target(true, (int) $_SESSION['id']));
    } else {
        redirect_to('admin/client.php');
    }
    die();
}

$units      = VendorService::units();
$categories = VendorCoverage::categories();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="List your Kenyan wedding business on Everafter Planner — catering, decor, photography, venues and more. Create your free listing, add your services and packages.">
    <title>List Your Wedding Business — Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/modern.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Inter:400,500,600,700,800|Plus+Jakarta+Sans:600,700,800" rel="stylesheet">
    <style>
        :root { --kenya-green:#065F46; --kenya-black:#0F172A; --muted:#64748B; --line:#E2E8F0; }
        body {
            min-height: 100vh;
            background:
                radial-gradient(1200px 500px at 85% -10%, rgba(6,95,70,.14), transparent 60%),
                linear-gradient(160deg, #FAF9F6 0%, #F1F5F3 100%);
            padding: 28px 12px 48px;
            font-family: 'Inter', system-ui, sans-serif;
            color: var(--kenya-black);
        }
        .signup-shell { width: 100%; max-width: 860px; margin: 0 auto; }
        .signup-card {
            background: #fff; border-radius: 24px;
            box-shadow: 0 24px 56px rgba(0,0,0,.14), 0 8px 20px rgba(0,0,0,.06);
            padding: 32px 34px 28px;
        }
        .brand-row { display:flex; align-items:center; gap:10px; margin-bottom: 22px; }
        .brand-row img { height: 34px; width: auto; }
        .brand-row span { font-family:'Plus Jakarta Sans','Inter',sans-serif; font-weight:800; letter-spacing:-.02em; font-size:1.15rem; }
        .brand-row span em { font-style: normal; color: var(--kenya-green); }
        .signup-card h1 { font-family:'Plus Jakarta Sans','Inter',sans-serif; font-weight:800; letter-spacing:-.02em; font-size:1.7rem; margin-bottom: 6px; }
        .signup-sub { color: var(--muted); font-size: 14px; margin-bottom: 22px; }
        .section-label {
            font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
            color: var(--kenya-green); margin: 26px 0 10px; padding-bottom: 6px;
            border-bottom: 1px solid var(--line); display: flex; align-items: center; gap: 8px;
        }
        .section-label:first-of-type { margin-top: 6px; }
        .section-label .hint { color: var(--muted); font-weight: 500; letter-spacing: 0; text-transform: none; font-size: 11.5px; }
        label { font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 4px; }
        .form-control { border-radius: 10px; border: 1px solid var(--line); font-size: 14px; height: calc(1.5em + .7rem + 2px); }
        .form-control:focus { border-color: var(--kenya-green); box-shadow: 0 0 0 3px rgba(6,95,70,.14); }
        textarea.form-control { height: auto; }
        .service-row { background: #F8FAFC; border: 1px solid var(--line); border-radius: 12px; padding: 12px 12px 4px; margin-bottom: 10px; }
        .service-row .row-num { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 8px; display: block; }
        .btn-submit {
            background: var(--kenya-green); color: #fff; border: 0; border-radius: 999px;
            font-weight: 700; padding: .8rem 1.8rem; font-size: 15px; width: 100%; margin-top: 22px;
        }
        .btn-submit:hover { background: #054C38; color: #fff; }
        .fine { font-size: 11.5px; color: var(--muted); margin-top: 14px; }
        .signup-foot { text-align: center; margin-top: 18px; font-size: 13px; color: var(--muted); }
        .signup-foot a { color: var(--kenya-green); font-weight: 600; }
        .pending-note {
            background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 12px;
            padding: 10px 12px; font-size: 12.5px; color: #92400E; margin-bottom: 20px;
        }
        @media (max-width: 575.98px) { .signup-card { padding: 24px 18px 22px; border-radius: 18px; } }
    </style>
</head>
<body>
<div class="signup-shell">
    <div class="signup-card">
        <div class="brand-row">
            <img src="images/logo/everafter-crest.png" alt="Everafter Planner">
            <span>Everafter<em> Planner</em></span>
        </div>

        <h1>List your wedding business</h1>
        <p class="signup-sub">
            Catering, decor, photography, music, transport, venues, cakes, flowers — if you serve weddings in Kenya,
            create your listing here. It is free to apply, and couples' planners find you by what you actually sell.
        </p>

        <div class="pending-note">
            <i class="mdi mdi-clock-outline mr-1"></i>
            Your account works straight away, but your listing only appears in the marketplace
            <strong>after a quick approval</strong> — so the couples here are looking at businesses we have checked.
        </div>

        <?php if ($session->message()): ?>
            <div class="mb-3"><?= $session->message(); ?></div>
        <?php endif; ?>

        <form method="post" action="vendor_signup.php" id="vendor-signup-form">
            <?= $session->csrf_field(); ?>
            <input type="hidden" name="register_vendor" value="1">

            <div class="section-label"><i class="mdi mdi-account-outline"></i> Your account
                <span class="hint">— how you sign in</span>
            </div>
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="firstname">First name</label>
                    <input type="text" class="form-control" id="firstname" name="firstname" maxlength="32" required
                           value="<?= htmlspecialchars((string) ($_POST['firstname'] ?? '')); ?>">
                </div>
                <div class="form-group col-md-6">
                    <label for="lastname">Last name</label>
                    <input type="text" class="form-control" id="lastname" name="lastname" maxlength="32" required
                           value="<?= htmlspecialchars((string) ($_POST['lastname'] ?? '')); ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="email">Email — this is your login</label>
                    <input type="email" class="form-control" id="email" name="email" maxlength="100" required
                           value="<?= htmlspecialchars((string) ($_POST['email'] ?? '')); ?>">
                </div>
                <div class="form-group col-md-3">
                    <label for="phone">Phone</label>
                    <input type="text" class="form-control" id="phone" name="phone" maxlength="20" required
                           placeholder="0712345678" value="<?= htmlspecialchars((string) ($_POST['phone'] ?? '')); ?>">
                </div>
                <div class="form-group col-md-3">
                    <label for="password">Password</label>
                    <input type="password" class="form-control" id="password" name="password" minlength="8" required
                           autocomplete="new-password" placeholder="8+ characters">
                </div>
            </div>

            <div class="section-label"><i class="mdi mdi-store-outline"></i> Your business</div>
            <div class="form-row">
                <div class="form-group col-md-7">
                    <label for="business_name">Business name</label>
                    <input type="text" class="form-control" id="business_name" name="business_name" maxlength="200" required
                           placeholder="e.g. Nyama Choma Masters" value="<?= htmlspecialchars((string) ($_POST['business_name'] ?? '')); ?>">
                </div>
                <div class="form-group col-md-5">
                    <label for="category">What kind of vendor are you?</label>
                    <select class="form-control" id="category" name="category" required>
                        <option value="">Choose one…</option>
                        <?php foreach ($categories as $slug => [$label]): ?>
                            <option value="<?= htmlspecialchars($slug); ?>" <?= (string) ($_POST['category'] ?? '') === $slug ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="description">What you do, in your words</label>
                <textarea class="form-control" id="description" name="description" rows="3" maxlength="2000"
                          placeholder="Years in business, what you are known for, anything a couple should know"><?= htmlspecialchars((string) ($_POST['description'] ?? '')); ?></textarea>
            </div>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="location">Town</label>
                    <select class="form-control" id="location" name="location" required>
                        <option value="">Select town</option>
                        <?php foreach (VendorSignup::LOCATIONS as $town): ?>
                            <option <?= (string) ($_POST['location'] ?? '') === $town ? 'selected' : ''; ?>><?= htmlspecialchars($town); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-4">
                    <label for="county">County</label>
                    <input type="text" class="form-control" id="county" name="county" maxlength="50" required
                           placeholder="e.g. Nairobi County" value="<?= htmlspecialchars((string) ($_POST['county'] ?? '')); ?>">
                </div>
                <div class="form-group col-md-4">
                    <label for="business_email">Business email <span class="text-muted font-weight-normal">(optional)</span></label>
                    <input type="email" class="form-control" id="business_email" name="business_email" maxlength="100"
                           placeholder="info@business.co.ke" value="<?= htmlspecialchars((string) ($_POST['business_email'] ?? '')); ?>">
                </div>
            </div>

            <div class="section-label"><i class="mdi mdi-format-list-bulleted"></i> What you offer
                <span class="hint">— up to <?= VendorSignup::MAX_SERVICES; ?> now, more later</span>
            </div>
            <p class="fine mt-0 mb-2">
                A service is one thing at one price, with the unit it is quoted in — <em>a buffet at KSh 1,200 per guest</em>,
                <em>a photographer at KSh 45,000 per day</em>. Planners compare services across vendors, so the unit matters.
                Tags are the words couples use for taste: <em>rustic, halal, outdoor, live band</em>.
            </p>
            <?php
            // One guarded read of the posted rows, re-used by every row below:
            // a second direct index would warn when the form posts nothing.
            $posted_services = $_POST['services'] ?? null;
            $posted_services = is_array($posted_services) ? $posted_services : [];
            for ($i = 0; $i < VendorSignup::MAX_SERVICES; $i++):
                $svc = is_array($posted_services[$i] ?? null) ? $posted_services[$i] : [];
            ?>
                <div class="service-row">
                    <span class="row-num">Service <?= $i + 1; ?><?= $i === 0 ? '' : ' (optional)'; ?></span>
                    <div class="form-row">
                        <div class="form-group col-md-5">
                            <input type="text" class="form-control" name="services[<?= $i; ?>][title]" maxlength="160"
                                   placeholder="<?= $i === 0 ? 'e.g. Classic buffet' : 'Another service'; ?>"
                                   value="<?= htmlspecialchars((string) ($svc['title'] ?? '')); ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <select class="form-control" name="services[<?= $i; ?>][unit]">
                                <?php foreach ($units as $unit => $meta): ?>
                                    <option value="<?= htmlspecialchars($unit); ?>" <?= (string) ($svc['unit'] ?? 'flat') === $unit ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($meta[0]); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <input type="number" class="form-control" name="services[<?= $i; ?>][price]" min="0" step="1"
                                   placeholder="KSh" value="<?= htmlspecialchars((string) ($svc['price'] ?? '')); ?>">
                        </div>
                        <div class="form-group col-md-2">
                            <input type="number" class="form-control" name="services[<?= $i; ?>][min_qty]" min="0" step="1"
                                   placeholder="Min qty" value="<?= htmlspecialchars((string) ($svc['min_qty'] ?? '')); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <input type="text" class="form-control" name="services[<?= $i; ?>][includes]" maxlength="500"
                                   placeholder="Included: staff, crockery, setup…"
                                   value="<?= htmlspecialchars((string) ($svc['includes'] ?? '')); ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <input type="text" class="form-control" name="services[<?= $i; ?>][tags]" maxlength="255"
                                   placeholder="Tags: rustic, halal, outdoor"
                                   value="<?= htmlspecialchars((string) ($svc['tags'] ?? '')); ?>">
                        </div>
                    </div>
                </div>
            <?php endfor; ?>

            <div class="section-label"><i class="mdi mdi-package-variant-closed"></i> A package
                <span class="hint">— optional: everything together at one price</span>
            </div>
            <p class="fine mt-0 mb-2">
                If you sell a bundle — "Gold buffet for 150 guests, KSh 320,000" — put it here. A planner can then pick
                the whole package instead of pricing your services one by one.
            </p>
            <div class="form-row">
                <div class="form-group col-md-6">
                    <input type="text" class="form-control" name="package[title]" maxlength="200"
                           placeholder="e.g. Gold package" value="<?= htmlspecialchars((string) ($_POST['package']['title'] ?? '')); ?>">
                </div>
                <div class="form-group col-md-3">
                    <input type="number" class="form-control" name="package[price]" min="0" step="1"
                           placeholder="KSh" value="<?= htmlspecialchars((string) ($_POST['package']['price'] ?? '')); ?>">
                </div>
                <div class="form-group col-md-3">
                    <input type="number" class="form-control" name="package[capacity]" min="0" step="1"
                           placeholder="Guests (optional)" value="<?= htmlspecialchars((string) ($_POST['package']['capacity'] ?? '')); ?>">
                </div>
            </div>
            <div class="form-group">
                <textarea class="form-control" name="package[description]" rows="2" maxlength="2000"
                          placeholder="What the package includes"><?= htmlspecialchars((string) ($_POST['package']['description'] ?? '')); ?></textarea>
            </div>

            <button type="submit" class="btn-submit">
                <i class="mdi mdi-store-plus-outline mr-1"></i> Create my listing
            </button>
            <p class="fine">
                By applying you agree to be contacted by couples and their planners about enquiries. You can edit or hide
                your listing at any time from your dashboard.
            </p>
        </form>
    </div>

    <p class="signup-foot">
        Already listed? <a href="login.php">Sign in</a> ·
        <a href="vendors.php">Browse the marketplace</a> ·
        Run the wedding instead? <a href="planner_register.php">Planner sign-up</a>
    </p>
</div>

<script src="js/jquery-3.7.1.min.js"></script>
<script src="js/bootstrap.min.js?v=4.6.2"></script>
</body>
</html>
