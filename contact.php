<?php include 'admin/include/init.php'; ?>
<?php
// Contact form handler — the page needs no models of its own; init.php gives
// the shared nav its session (and the login modal its CSRF token).
$contact_recipient = 'info@wpms.co.ke';

$contact_name = $contact_email = $contact_phone = $contact_message = '';
$contact_error = '';
$contact_sent  = isset($_GET['sent']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    $contact_name    = trim((string) ($_POST['contact_person'] ?? ''));
    $contact_email   = trim((string) ($_POST['contact_email'] ?? ''));
    $contact_phone   = trim((string) ($_POST['contact_phone'] ?? ''));
    $contact_message = trim((string) ($_POST['contact_message'] ?? ''));

    if ($contact_name === '' || $contact_message === '') {
        $contact_error = 'Please provide your name and a message.';
    } elseif (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        $contact_error = 'Please enter a valid email address so we can reply.';
    } elseif (($contact_wait = PublicGuard::attempt(PublicGuard::CONTACT)) > 0) {
        /*
         * The shared abuse guard (v2.14), applied last so a typo in the form
         * does not spend one of the visitor's five messages. Every other
         * anonymous door in the product is behind this same counter; this form
         * was the one that is not a database write and therefore slipped
         * through — while still being the loudest: each submission runs mail()
         * against the operator's own inbox and SMTP quota.
         */
        $contact_error = 'That is a few messages from this connection already. Please try again in '
            . max(1, (int) ceil($contact_wait / 60)) . ' minute(s), or email us directly at '
            . $contact_recipient . '.';
    } else {
        // Strip CR/LF to block header injection through name/email.
        $safe_name  = str_replace(["\r", "\n"], '', $contact_name);
        $safe_email = str_replace(["\r", "\n"], '', $contact_email);

        $subject = 'Website enquiry from ' . $safe_name;
        $body    = "Name:    {$safe_name}\n"
                 . "Email:   {$safe_email}\n"
                 . "Phone:   " . ($contact_phone !== '' ? $contact_phone : '-') . "\n\n"
                 . "Message:\n{$contact_message}\n";
        $headers = "From: Everafter Planner <{$contact_recipient}>\r\n"
                 . "Reply-To: {$safe_name} <{$safe_email}>\r\n";

        $sent = @mail($contact_recipient, $subject, $body, $headers);
        if ($sent) {
            header('Location: contact.php?sent=1');
            exit;
        }
        $contact_error = 'Sorry — the message could not be sent right now. Please email us directly at '
            . $contact_recipient . ' or call +254 700 000 000.';
    }
}

$whatsapp_url = 'https://wa.me/254700000000?text='
    . rawurlencode('Hi Everafter Planner! I am planning my wedding and would like to know more about your packages.');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Contact Everafter Planner — Wedding Planning in Kenya</title>
    <meta name="description" content="Talk to a Kenyan wedding planner about packages, availability and M-Pesa payments. Serving couples in Nairobi, Mombasa, Kisumu and Nakuru.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/7.4.47/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="css/bootstrap.min.css?v=4.6.2">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modern.css">
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "ProfessionalService",
        "name": "Everafter Planner",
        "description": "Wedding planning in Kenya — packages, guest lists, budgets and vendor coordination, priced in KSh and M-Pesa ready.",
        "url": "https://www.wpms.co.ke/contact.php",
        "email": "info@wpms.co.ke",
        "telephone": "+254700000000",
        "areaServed": ["Nairobi", "Mombasa", "Kisumu", "Nakuru"]
    }
    </script>
    <style>
        /* Contact page — scoped to .contact-page so the shared public styles
           (nav, footer, tokens) stay exactly as the rest of the site uses them. */
        .contact-page { background: var(--bg) !important; color: var(--text); }

        .contact-shell { width: min(1180px, calc(100% - 44px)); margin-left: auto; margin-right: auto; }

        /* ---------- Hero ---------- */
        .contact-hero {
            position: relative;
            overflow: hidden;
            isolation: isolate;
            padding: clamp(54px, 8vw, 94px) 0 clamp(112px, 13vw, 158px);
            background:
                radial-gradient(900px 440px at 84% 4%, rgba(217, 119, 6, .30), transparent 62%),
                radial-gradient(720px 440px at 4% 96%, rgba(16, 185, 129, .20), transparent 62%),
                linear-gradient(135deg, #0A3B2E 0%, #064E3B 52%, #0B5F49 100%);
            color: #fff;
        }
        .contact-hero::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: -1;
            background-image: radial-gradient(rgba(255, 255, 255, .16) 1px, transparent 1px);
            background-size: 24px 24px;
            opacity: .28;
            -webkit-mask-image: linear-gradient(105deg, transparent 35%, #000 100%);
            mask-image: linear-gradient(105deg, transparent 35%, #000 100%);
        }
        .contact-hero::after {
            content: "";
            position: absolute;
            right: -160px;
            bottom: -220px;
            width: 520px;
            height: 520px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, .14);
            box-shadow: 0 0 0 70px rgba(255, 255, 255, .035), 0 0 0 150px rgba(255, 255, 255, .02);
            z-index: -1;
            pointer-events: none;
        }
        .contact-hero__inner { position: relative; z-index: 1; max-width: 840px; }
        .contact-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, .18);
            background: rgba(255, 255, 255, .08);
            -webkit-backdrop-filter: blur(8px);
            backdrop-filter: blur(8px);
            color: #FDE68A;
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: .15em;
            text-transform: uppercase;
        }
        .contact-eyebrow__dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #FBBF24;
            box-shadow: 0 0 0 4px rgba(251, 191, 36, .18);
        }
        .contact-hero h1 {
            margin: 22px 0 18px;
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            font-size: clamp(2.35rem, 5.2vw, 4.1rem);
            font-weight: 800;
            line-height: 1.03;
            letter-spacing: -.04em;
            color: #fff;
        }
        .contact-hero__accent { color: #FCD34D; }
        .contact-hero__lead {
            max-width: 630px;
            margin: 0 0 28px;
            color: rgba(255, 255, 255, .78);
            font-size: clamp(1rem, 1.15vw, 1.1rem);
            line-height: 1.7;
        }
        .contact-hero__meta { display: flex; flex-wrap: wrap; gap: 10px; }
        .contact-hero__meta span {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 13px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, .16);
            background: rgba(255, 255, 255, .07);
            color: rgba(255, 255, 255, .88);
            font-size: 12.5px;
            font-weight: 500;
        }
        .contact-hero__meta i { font-size: 15px; color: #FDE68A; }

        /* ---------- The cards that overlap the hero ---------- */
        .contact-main {
            position: relative;
            z-index: 2;
            margin-top: clamp(-132px, -9vw, -96px);
            padding-bottom: 56px;
        }
        .contact-grid {
            display: grid;
            grid-template-columns: minmax(0, .92fr) minmax(0, 1.08fr);
            gap: 22px;
            align-items: start;
        }
        .contact-panel {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 26px;
            box-shadow: var(--shadow);
        }
        .contact-info { padding: clamp(24px, 3vw, 34px); }
        .contact-form-card {
            padding: clamp(24px, 3.2vw, 38px);
            border-radius: 28px;
            box-shadow: var(--shadow-lg);
        }
        .contact-kicker {
            display: block;
            margin-bottom: 10px;
            color: var(--kenya-green);
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: .15em;
            text-transform: uppercase;
        }
        .contact-panel h2 {
            margin: 0 0 10px;
            color: var(--kenya-black);
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            font-size: clamp(1.35rem, 2.2vw, 1.7rem);
            font-weight: 800;
            line-height: 1.18;
            letter-spacing: -.03em;
        }
        .contact-panel__lead {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: 14.5px;
            line-height: 1.65;
        }

        /* ---------- Contact methods ---------- */
        .contact-methods { display: grid; gap: 10px; }
        .contact-method {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 13px 14px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: #FCFCFB;
            color: inherit;
            text-decoration: none !important;
            transition: border-color .18s ease, background .18s ease, transform .18s ease, box-shadow .18s ease;
        }
        a.contact-method:hover {
            border-color: rgba(6, 95, 70, .32);
            background: #fff;
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        .contact-method__icon {
            flex: 0 0 42px;
            width: 42px;
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #FDE68A;
            border-radius: 13px;
            background: var(--kenya-cream);
            color: var(--kenya-green);
            font-size: 20px;
        }
        .contact-method--wa .contact-method__icon {
            border-color: #BBF7D0;
            background: #F0FDF4;
            color: #16A34A;
        }
        .contact-method__body { display: flex; flex-direction: column; min-width: 0; line-height: 1.35; }
        .contact-method__label {
            color: var(--muted-2);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
        }
        .contact-method__value {
            color: var(--text);
            font-size: 14.5px;
            font-weight: 600;
            overflow-wrap: anywhere;
        }
        .contact-method__hint { margin-top: 2px; color: var(--muted); font-size: 12px; }
        .contact-method__arrow { margin-left: auto; color: var(--muted-2); font-size: 18px; }
        .contact-method--static { cursor: default; }
        .contact-method--static:hover { transform: none; box-shadow: none; background: #FCFCFB; border-color: var(--line); }

        .contact-signin {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 18px;
            padding: 15px;
            border: 1px solid #FDE68A;
            border-radius: 16px;
            background: linear-gradient(135deg, #FFFBEB, #F5F5F4);
        }
        .contact-signin > i { color: var(--kenya-gold); font-size: 22px; }
        .contact-signin__copy { display: flex; flex-direction: column; min-width: 0; color: var(--muted); font-size: 12.5px; line-height: 1.4; }
        .contact-signin__copy strong { color: var(--text); font-size: 13px; }
        .contact-signin__link {
            margin-left: auto;
            color: var(--kenya-green);
            font-size: 12.5px;
            font-weight: 700;
            white-space: nowrap;
            text-decoration: none;
        }
        .contact-signin__link:hover { text-decoration: underline; }

        /* ---------- Form ---------- */
        .contact-form-card__head { margin-bottom: 22px; }
        .contact-form-card__head p { margin: 0; color: var(--muted); font-size: 14.5px; line-height: 1.65; }
        .contact-form { margin: 0; }
        .contact-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .contact-field { display: flex; flex-direction: column; min-width: 0; }
        .contact-field--full { grid-column: 1 / -1; }
        .contact-field label {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 7px;
            color: var(--text);
            font-size: 12.5px;
            font-weight: 700;
            letter-spacing: .01em;
        }
        .contact-required { color: var(--kenya-red); font-weight: 800; }
        .contact-optional {
            margin-left: 2px;
            color: var(--muted-2);
            font-size: 10px;
            font-weight: 600;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .contact-input {
            width: 100%;
            min-height: 50px;
            padding: 13px 15px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #fff;
            color: var(--text);
            font-family: inherit;
            font-size: 15px;
            line-height: 1.4;
            box-shadow: inset 0 1px 1px rgba(0, 0, 0, .02);
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .contact-input::placeholder { color: var(--muted-2); }
        .contact-input:hover { border-color: #D6D3D1; }
        .contact-input:focus {
            outline: none;
            border-color: var(--kenya-green);
            box-shadow: 0 0 0 4px rgba(6, 95, 70, .12);
        }
        textarea.contact-input { min-height: 138px; resize: vertical; padding-top: 14px; }
        .contact-hint { margin-top: 6px; color: var(--muted-2); font-size: 11.5px; }
        .contact-form__foot { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-top: 22px; }
        .contact-submit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 14px 24px;
            border: 0;
            border-radius: 999px;
            background: var(--kenya-green);
            color: #fff;
            font-family: inherit;
            font-size: 14.5px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 10px 24px rgba(6, 95, 70, .22);
            transition: transform .18s ease, background .18s ease, box-shadow .18s ease;
        }
        .contact-submit:hover { background: #0A7A5C; transform: translateY(-1px); box-shadow: 0 14px 28px rgba(6, 95, 70, .26); }
        .contact-submit:active { transform: translateY(0); }
        .contact-submit i { font-size: 18px; transition: transform .18s ease; }
        .contact-submit:hover i { transform: translateX(2px); }
        .contact-privacy {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin: 0;
            color: var(--muted-2);
            font-size: 11.5px;
        }

        /* ---------- Feedback ---------- */
        .contact-alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 20px;
            padding: 14px 16px;
            border: 1px solid;
            border-radius: 16px;
            font-size: 13.5px;
            line-height: 1.5;
        }
        .contact-alert__icon {
            flex: 0 0 30px;
            width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 17px;
        }
        .contact-alert strong { display: block; margin-bottom: 1px; font-size: 13.5px; }
        .contact-alert span { display: block; }
        .contact-alert--success { border-color: #A7F3D0; background: #ECFDF5; color: #065F46; }
        .contact-alert--success .contact-alert__icon { background: #D1FAE5; color: #047857; }
        .contact-alert--error { border-color: #FECACA; background: #FEF2F2; color: #991B1B; }
        .contact-alert--error .contact-alert__icon { background: #FEE2E2; color: #B91C1C; }

        /* ---------- What happens next ---------- */
        .contact-steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 28px; }
        .contact-step {
            padding: 22px;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: rgba(255, 255, 255, .62);
        }
        .contact-step__num {
            display: inline-block;
            margin-bottom: 10px;
            color: var(--kenya-gold);
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .12em;
        }
        .contact-step h3 {
            margin: 0 0 5px;
            color: var(--kenya-black);
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: -.01em;
        }
        .contact-step p { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.6; }

        @media (max-width: 980px) {
            .contact-grid { grid-template-columns: 1fr; }
            .contact-form-card { order: 1; }
            .contact-info { order: 2; }
            .contact-main { margin-top: -96px; }
            .contact-steps { grid-template-columns: 1fr; }
        }
        @media (max-width: 620px) {
            .contact-shell { width: calc(100% - 32px); }
            .contact-hero { padding-bottom: 104px; }
            .contact-main { margin-top: -78px; }
            .contact-fields { grid-template-columns: 1fr; }
            .contact-form__foot { flex-direction: column; align-items: stretch; }
            .contact-submit { width: 100%; }
            .contact-privacy { justify-content: center; }
            .contact-method__arrow { display: none; }
            .contact-signin { flex-wrap: wrap; }
            .contact-signin__link { margin-left: 34px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .contact-method, .contact-submit, .contact-submit i { transition: none !important; }
            a.contact-method:hover, .contact-submit:hover, .contact-submit:hover i { transform: none; }
        }
    </style>
</head>
<body class="contact-page">
<?php include "include/nav.php"; ?>

<main>
    <section class="contact-hero" aria-labelledby="contact-title">
        <div class="contact-shell">
            <div class="contact-hero__inner">
                <span class="contact-eyebrow"><span class="contact-eyebrow__dot"></span> Contact Everafter</span>
                <h1 id="contact-title">Let&rsquo;s plan something<br><span class="contact-hero__accent">unforgettable.</span></h1>
                <p class="contact-hero__lead">
                    Your date, your venue, your people. Tell us what you&rsquo;re imagining and a real planner
                    will help you shape it &mdash; with KSh pricing and M-Pesa ready from day one.
                </p>
                <div class="contact-hero__meta">
                    <span><i class="mdi mdi-map-marker-outline"></i> Nairobi &middot; Mombasa &middot; Kisumu &middot; Nakuru</span>
                    <span><i class="mdi mdi-cash-multiple"></i> KSh pricing &middot; M-Pesa ready</span>
                </div>
            </div>
        </div>
    </section>

    <section class="contact-shell contact-main" aria-label="Contact details and message form">
        <div class="contact-grid">
            <aside class="contact-panel contact-info">
                <span class="contact-kicker">Talk to us</span>
                <h2>A planner, not a phone tree.</h2>
                <p class="contact-panel__lead">
                    Questions about packages, availability or how the planning works? Reach us however
                    suits you &mdash; every message lands with the team.
                </p>

                <div class="contact-methods">
                    <a class="contact-method" href="mailto:info@wpms.co.ke">
                        <span class="contact-method__icon"><i class="mdi mdi-email-outline"></i></span>
                        <span class="contact-method__body">
                            <span class="contact-method__label">Email</span>
                            <span class="contact-method__value">info@wpms.co.ke</span>
                            <span class="contact-method__hint">Best for dates, details and documents</span>
                        </span>
                        <i class="mdi mdi-arrow-top-right contact-method__arrow"></i>
                    </a>

                    <a class="contact-method" href="tel:+254700000000">
                        <span class="contact-method__icon"><i class="mdi mdi-phone-outline"></i></span>
                        <span class="contact-method__body">
                            <span class="contact-method__label">Phone</span>
                            <span class="contact-method__value">+254 700 000 000</span>
                            <span class="contact-method__hint">Call during working hours</span>
                        </span>
                        <i class="mdi mdi-arrow-top-right contact-method__arrow"></i>
                    </a>

                    <a class="contact-method contact-method--wa" href="<?= htmlspecialchars($whatsapp_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                        <span class="contact-method__icon"><i class="mdi mdi-whatsapp"></i></span>
                        <span class="contact-method__body">
                            <span class="contact-method__label">WhatsApp</span>
                            <span class="contact-method__value">Chat with us on WhatsApp</span>
                            <span class="contact-method__hint">Fastest for quick questions</span>
                        </span>
                        <i class="mdi mdi-arrow-top-right contact-method__arrow"></i>
                    </a>

                    <div class="contact-method contact-method--static">
                        <span class="contact-method__icon"><i class="mdi mdi-map-marker-outline"></i></span>
                        <span class="contact-method__body">
                            <span class="contact-method__label">Where we work</span>
                            <span class="contact-method__value">Nairobi, Kenya</span>
                            <span class="contact-method__hint">Serving couples across Kenya</span>
                        </span>
                    </div>
                </div>

                <div class="contact-signin">
                    <i class="mdi mdi-calendar-check-outline"></i>
                    <span class="contact-signin__copy">
                        <strong>Already planning with us?</strong>
                        <span>Open your booking, guests and budget.</span>
                    </span>
                    <a class="contact-signin__link" href="login.php">Sign in <i class="mdi mdi-arrow-right"></i></a>
                </div>
            </aside>

            <div class="contact-panel contact-form-card">
                <div class="contact-form-card__head">
                    <span class="contact-kicker">Send a message</span>
                    <h2>Tell us about your wedding</h2>
                    <p>Share your date, guest count or the venue you have in mind. If you&rsquo;re still exploring, that&rsquo;s welcome too.</p>
                </div>

                <?php if ($contact_sent) : ?>
                    <div class="contact-alert contact-alert--success" role="status" aria-live="polite">
                        <span class="contact-alert__icon"><i class="mdi mdi-check"></i></span>
                        <div>
                            <strong>Thank you &mdash; your message is on its way.</strong>
                            <span>We&rsquo;ll get back to you soon. If it&rsquo;s urgent, WhatsApp is the fastest way to reach us.</span>
                        </div>
                    </div>
                <?php elseif ($contact_error !== '') : ?>
                    <div class="contact-alert contact-alert--error" role="alert">
                        <span class="contact-alert__icon"><i class="mdi mdi-alert-circle-outline"></i></span>
                        <div>
                            <strong>We couldn&rsquo;t send that yet.</strong>
                            <span><?= htmlspecialchars($contact_error, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <form action="contact.php" method="post" class="contact-form" id="contact-form">
                    <div class="contact-fields">
                        <div class="contact-field">
                            <label for="contact_person">Full name <span class="contact-required" aria-hidden="true">*</span></label>
                            <input type="text" class="contact-input" id="contact_person" name="contact_person"
                                   value="<?= htmlspecialchars($contact_name, ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="e.g. Wanjiku Mwangi" autocomplete="name" maxlength="120" required>
                        </div>
                        <div class="contact-field">
                            <label for="contact_email">Email address <span class="contact-required" aria-hidden="true">*</span></label>
                            <input type="email" class="contact-input" id="contact_email" name="contact_email"
                                   value="<?= htmlspecialchars($contact_email, ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="you@example.com" autocomplete="email" inputmode="email" maxlength="190" required>
                        </div>
                        <div class="contact-field contact-field--full">
                            <label for="contact_phone">Phone <span class="contact-optional">Optional</span></label>
                            <input type="tel" class="contact-input" id="contact_phone" name="contact_phone"
                                   value="<?= htmlspecialchars($contact_phone, ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="+254 7XX XXX XXX" autocomplete="tel" inputmode="tel" maxlength="40"
                                   aria-describedby="contact_phone_hint">
                            <span class="contact-hint" id="contact_phone_hint">So we can call or WhatsApp you back.</span>
                        </div>
                        <div class="contact-field contact-field--full">
                            <label for="contact_message">How can we help? <span class="contact-required" aria-hidden="true">*</span></label>
                            <textarea class="contact-input" id="contact_message" name="contact_message" rows="5"
                                      maxlength="2000" required
                                      placeholder="Wedding date, venue, guest count &mdash; or just say hello."><?= htmlspecialchars($contact_message, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    </div>

                    <div class="contact-form__foot">
                        <button type="submit" name="contact_submit" class="contact-submit">
                            Send message <i class="mdi mdi-arrow-right"></i>
                        </button>
                        <p class="contact-privacy"><i class="mdi mdi-lock-outline"></i> Your details are only used to reply to this enquiry.</p>
                    </div>
                </form>
            </div>
        </div>

        <div class="contact-steps" aria-label="What happens next">
            <div class="contact-step">
                <span class="contact-step__num">01</span>
                <h3>You write</h3>
                <p>Tell us the date, the place and what matters most to you.</p>
            </div>
            <div class="contact-step">
                <span class="contact-step__num">02</span>
                <h3>We reply</h3>
                <p>A planner comes back with availability, packages and clear next steps.</p>
            </div>
            <div class="contact-step">
                <span class="contact-step__num">03</span>
                <h3>You plan</h3>
                <p>Guests, budget and vendors in one place &mdash; in KSh, with M-Pesa ready.</p>
            </div>
        </div>
    </section>
</main>

<footer class="pt-3">
    <div style="max-width:1180px; margin:0 auto; padding:0 16px;">
        <div class="d-flex flex-wrap justify-content-between align-items-center" style="gap:12px; border-top:1px solid rgba(255,255,255,.12); padding-top:18px;">
            <div class="d-flex align-items-center" style="gap:.6rem;">
                <img src="images/logo/everafter-crest.png" alt="Everafter Planner" style="height:24px;">
                <span style="font-weight:700; letter-spacing:-.02em;">Everafter Planner</span>
                <span style="color:#A8A29E; font-size:12px;">&middot; KSh pricing &middot; M-Pesa &middot; Africa's Talking</span>
            </div>
            <small style="color:#A8A29E;">&copy; <?= date('Y'); ?> Everafter Planner &mdash; Crafted for ruracio, church &amp; reception. All amounts in KSh.</small>
        </div>
    </div>
</footer>

<!-- Optional JavaScript -->
<!-- jQuery first, then Popper.js, then Bootstrap JS -->
<script src="js/jquery-3.7.1.slim.min.js"></script>
<script src="js/jquery-3.7.1.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js?v=4.6.2"></script>
</body>
</html>
