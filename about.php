<?php include 'admin/include/init.php'; ?>
<?php $users_profile = Users::find_by_id($_SESSION['id'] ?? 0); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>About Everafter Planner — Wedding Planning Management System</title>
    <link href="https://fonts.googleapis.com/css?family=Playfair+Display:400,700|Montserrat:300,400,500,600" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        :root {
            --kenya-red: #A34A2F;
            --kenya-green: #A3B899;
            --kenya-cream: #FAF6EF;
            --kenya-gold: #C9A227;
        }
        body {
            font-family: 'Montserrat', sans-serif;
            background: #fafafa;
            color: #333;
        }
        .hero-section {
            background: linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)), url('images/bg/about-hero.jpg') center/cover;
            color: #fff;
            padding: 100px 20px;
            text-align: center;
        }
        .hero-section h1 {
            font-family: 'Playfair Display', serif;
            font-size: 48px;
            margin-bottom: 15px;
        }
        .hero-section p {
            font-size: 18px;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
        }
        .section {
            padding: 60px 20px;
            max-width: 1100px;
            margin: 0 auto;
        }
        .section h2 {
            font-family: 'Playfair Display', serif;
            text-align: center;
            margin-bottom: 40px;
            color: var(--kenya-red);
            font-size: 32px;
        }
        .section h2:after {
            content: '';
            display: block;
            width: 60px;
            height: 3px;
            background: var(--kenya-gold);
            margin: 15px auto 0;
        }
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }
        .feature-card {
            background: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        .feature-card .icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--kenya-red), #e74c3c);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 28px;
        }
        .feature-card h4 {
            color: #333;
            margin-bottom: 10px;
        }
        .feature-card p {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }
        .stats-bar {
            background: linear-gradient(135deg, var(--kenya-red), #8e44ad);
            color: #fff;
            padding: 50px 20px;
            text-align: center;
        }
        .stats-bar .stat-item {
            display: inline-block;
            margin: 20px 40px;
        }
        .stats-bar .stat-number {
            font-size: 42px;
            font-weight: 700;
            display: block;
        }
        .stats-bar .stat-label {
            font-size: 14px;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .mission-card {
            background: var(--kenya-cream);
            border-left: 5px solid var(--kenya-gold);
            padding: 30px;
            border-radius: 0 10px 10px 0;
            margin: 30px 0;
        }
        .mission-card h3 {
            color: var(--kenya-red);
            margin-bottom: 15px;
        }
        .mission-card p {
            color: #555;
            line-height: 1.8;
            font-size: 15px;
        }
        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }
        .team-member {
            text-align: center;
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .team-member .avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--kenya-green), #2ecc71);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 32px;
        }
        .team-member h5 {
            color: #333;
            margin-bottom: 5px;
        }
        .team-member small {
            color: #888;
        }
        .values-list {
            list-style: none;
            padding: 0;
            max-width: 600px;
            margin: 0 auto;
        }
        .values-list li {
            padding: 15px 20px;
            margin-bottom: 10px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
        }
        .values-list li i {
            color: var(--kenya-green);
            font-size: 20px;
            margin-right: 15px;
        }
        .cta-section {
            background: linear-gradient(135deg, var(--kenya-red), #8e44ad);
            color: #fff;
            padding: 60px 20px;
            text-align: center;
        }
        .cta-section h2 {
            color: #fff;
            margin-bottom: 20px;
        }
        .cta-section h2:after {
            background: #fff;
        }
        .cta-section p {
            opacity: 0.9;
            margin-bottom: 30px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        .cta-section .btn {
            padding: 12px 30px;
            font-size: 16px;
            border-radius: 25px;
            margin: 5px;
        }
        .contact-info {
            background: #fff;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            max-width: 500px;
            margin: 0 auto;
        }
        .contact-info .info-item {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        .contact-info .info-item i {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--kenya-cream);
            color: var(--kenya-red);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        footer {
            background: #131A26;
            color: #fff;
            padding: 30px 20px;
            text-align: center;
        }
        footer p {
            margin: 0;
            opacity: 0.8;
            font-size: 14px;
        }
        @media (max-width: 768px) {
            .hero-section h1 { font-size: 32px; }
            .stats-bar .stat-item { margin: 15px 20px; display: block; }
            .section h2 { font-size: 26px; }
        }
    </style>
</head>
<body>

<?php include 'include/nav.php'; ?>

<!-- Hero Section -->
<div class="hero-section">
    <h1><i class="mdi mdi-ring"></i> Everafter Planner</h1>
    <p>The complete wedding planning management system built for Kenya — from ruracio to reception, we've got you covered.</p>
</div>

<!-- Mission Section -->
<div class="section">
    <h2>Our Mission</h2>
    <div class="mission-card">
        <h3><i class="mdi mdi-bullseye-arrow"></i> Simplifying Wedding Planning Across Kenya</h3>
        <p>
            Everafter Planner was born from a simple observation: planning a Kenyan wedding involves countless moving parts — 
            from coordinating ruracio ceremonies and church blessings to managing hundreds of upcountry guests, 
            tracking harambee contributions, and ensuring every vendor is paid on time. Traditional methods using 
            WhatsApp groups, Excel spreadsheets, and phone calls simply don't scale.
        </p>
        <p style="margin-top: 15px;">
            Our platform brings everything together in one place. Whether you're a professional wedding planner 
            managing multiple events, a couple organizing your dream wedding, or a vendor looking to grow your 
            business — Everafter Planner provides the tools you need to succeed.
        </p>
    </div>
</div>

<!-- Stats Bar -->
<div class="stats-bar">
    <div class="stat-item">
        <span class="stat-number">5</span>
        <span class="stat-label">Wedding Packages</span>
    </div>
    <div class="stat-item">
        <span class="stat-number">9</span>
        <span class="stat-label">Vendor Categories</span>
    </div>
    <div class="stat-item">
        <span class="stat-number">47</span>
        <span class="stat-label">Counties Covered</span>
    </div>
    <div class="stat-item">
        <span class="stat-number">100%</span>
        <span class="stat-label">Kenyan Focus</span>
    </div>
</div>

<!-- Features Section -->
<div class="section">
    <h2>What We Offer</h2>
    <div class="feature-grid">
        <div class="feature-card">
            <div class="icon"><i class="mdi mdi-calendar-multiple-check"></i></div>
            <h4>Event Management</h4>
            <p>Plan every phase of the wedding journey — ruracio, church, reception, after-party — with our color-coded calendar system.</p>
        </div>
        <div class="feature-card">
            <div class="icon"><i class="mdi mdi-account-multiple"></i></div>
            <h4>Guest Management</h4>
            <p>Track harambee contributions, upcountry accommodation needs, transport, dietary requirements, and send bulk SMS invitations.</p>
        </div>
        <div class="feature-card">
            <div class="icon"><i class="mdi mdi-cash-multiple"></i></div>
            <h4>Budget & Payments</h4>
            <p>Record payments via Cash, M-Pesa, or Bank Transfer. Auto-track balances and reconcile with M-Pesa transaction codes.</p>
        </div>
        <div class="feature-card">
            <div class="icon"><i class="mdi mdi-store"></i></div>
            <h4>Vendor Marketplace</h4>
            <p>Connect with trusted Kenyan vendors across 9 categories — catering, decor, photography, music, transport, and more.</p>
        </div>
        <div class="feature-card">
            <div class="icon"><i class="mdi mdi-web"></i></div>
            <h4>Wedding Websites</h4>
            <p>Create beautiful public wedding pages with RSVP, photo gallery, countdown timer, and venue details for guests.</p>
        </div>
        <div class="feature-card">
            <div class="icon"><i class="mdi mdi-message-text"></i></div>
            <h4>SMS Center</h4>
            <p>Send bulk invitations and reminders via Africa's Talking. Track delivery and manage SMS credits easily.</p>
        </div>
        <div class="feature-card">
            <div class="icon"><i class="mdi mdi-image-multiple"></i></div>
            <h4>Photo Gallery</h4>
            <p>Upload and organize wedding photos by event. Share with couples and use on their public wedding websites.</p>
        </div>
        <div class="feature-card">
            <div class="icon"><i class="mdi mdi-shield-check"></i></div>
            <h4>Secure & Reliable</h4>
            <p>BCrypt password hashing, CSRF protection, prepared statements, and session hardening keep your data safe.</p>
        </div>
    </div>
</div>

<!-- Values Section -->
<div class="section" style="background: #f8f9fa; border-radius: 15px; padding: 50px 30px;">
    <h2>Our Values</h2>
    <ul class="values-list">
        <li>
            <i class="mdi mdi-check-circle"></i>
            <div><strong>Cultural Relevance</strong> — Built specifically for Kenyan wedding traditions, from ruracio to church to reception.</div>
        </li>
        <li>
            <i class="mdi mdi-check-circle"></i>
            <div><strong>Simplicity</strong> — Replace WhatsApp chaos, Excel sheets, and phone calls with one unified platform.</div>
        </li>
        <li>
            <i class="mdi mdi-check-circle"></i>
            <div><strong>Transparency</strong> — Clear pricing in KSh, M-Pesa payment tracking, and honest vendor ratings.</div>
        </li>
        <li>
            <i class="mdi mdi-check-circle"></i>
            <div><strong>Accessibility</strong> — Works on any device, anywhere in Kenya, with support for local languages.</div>
        </li>
        <li>
            <i class="mdi mdi-check-circle"></i>
            <div><strong>Community</strong> — Connecting couples with trusted vendors and experienced wedding planners.</div>
        </li>
    </ul>
</div>

<!-- How It Works -->
<div class="section">
    <h2>How It Works</h2>
    <div class="feature-grid">
        <div class="feature-card">
            <div class="icon" style="background: linear-gradient(135deg, #3498db, #2980b9);"><i class="mdi mdi-account-plus"></i></div>
            <h4>1. Register</h4>
            <p>Couples register on our frontend with basic details. Wedding planners get admin access with subscription plans.</p>
        </div>
        <div class="feature-card">
            <div class="icon" style="background: linear-gradient(135deg, #9b59b6, #8e44ad);"><i class="mdi mdi-clipboard-account"></i></div>
            <h4>2. Get Assigned</h4>
            <p>Admin reviews and assigns a wedding planner who fills in all the details — couple names, wedding type, date, location.</p>
        </div>
        <div class="feature-card">
            <div class="icon" style="background: linear-gradient(135deg, #27ae60, #2ecc71);"><i class="mdi mdi-calendar-text"></i></div>
            <h4>3. Plan & Manage</h4>
            <p>Add guests, track budget, schedule events, upload photos, send SMS invitations — all from one dashboard.</p>
        </div>
        <div class="feature-card">
            <div class="icon" style="background: linear-gradient(135deg, #f39c12, #e67e22);"><i class="mdi mdi-party-popper"></i></div>
            <h4>4. Celebrate!</h4>
            <p>On the big day, everything is organized. Share the wedding website with guests and enjoy the celebration.</p>
        </div>
    </div>
</div>

<!-- Subscription Plans Preview -->
<div class="section" style="background: #fff; border-radius: 15px; box-shadow: 0 4px 20px rgba(0,0,0,0.08);">
    <h2>Plans for Wedding Planners</h2>
    <p style="text-align: center; color: #666; margin-bottom: 30px;">Choose a plan that fits your business size</p>
    <div class="feature-grid">
        <div class="feature-card" style="border-top: 4px solid #3498db;">
            <h4>Starter</h4>
            <div style="font-size: 36px; font-weight: 700; color: #3498db; margin: 15px 0;">KSh 2,999</div>
            <p style="color: #888; font-size: 13px;">per month</p>
            <ul style="text-align: left; font-size: 13px; color: #555; margin-top: 20px; list-style: none; padding: 0;">
                <li style="padding: 8px 0; border-bottom: 1px solid #eee;"><i class="mdi mdi-check text-success"></i> 3 weddings/year</li>
                <li style="padding: 8px 0; border-bottom: 1px solid #eee;"><i class="mdi mdi-check text-success"></i> Guest list management</li>
                <li style="padding: 8px 0; border-bottom: 1px solid #eee;"><i class="mdi mdi-check text-success"></i> Budget tracker</li>
                <li style="padding: 8px 0;"><i class="mdi mdi-check text-success"></i> Basic calendar</li>
            </ul>
        </div>
        <div class="feature-card" style="border-top: 4px solid #27ae60; transform: scale(1.03);">
            <div style="background: #27ae60; color: #fff; padding: 5px 15px; border-radius: 20px; font-size: 11px; display: inline-block; margin-bottom: 10px;">MOST POPULAR</div>
            <h4>Professional</h4>
            <div style="font-size: 36px; font-weight: 700; color: #27ae60; margin: 15px 0;">KSh 7,999</div>
            <p style="color: #888; font-size: 13px;">per month</p>
            <ul style="text-align: left; font-size: 13px; color: #555; margin-top: 20px; list-style: none; padding: 0;">
                <li style="padding: 8px 0; border-bottom: 1px solid #eee;"><i class="mdi mdi-check text-success"></i> Unlimited weddings</li>
                <li style="padding: 8px 0; border-bottom: 1px solid #eee;"><i class="mdi mdi-check text-success"></i> M-Pesa integration</li>
                <li style="padding: 8px 0; border-bottom: 1px solid #eee;"><i class="mdi mdi-check text-success"></i> SMS notifications</li>
                <li style="padding: 8px 0; border-bottom: 1px solid #eee;"><i class="mdi mdi-check text-success"></i> Full calendar + gallery</li>
                <li style="padding: 8px 0;"><i class="mdi mdi-check text-success"></i> Wedding websites</li>
            </ul>
        </div>
        <div class="feature-card" style="border-top: 4px solid #c0392b;">
            <h4>Agency</h4>
            <div style="font-size: 36px; font-weight: 700; color: #c0392b; margin: 15px 0;">KSh 14,999</div>
            <p style="color: #888; font-size: 13px;">per month</p>
            <ul style="text-align: left; font-size: 13px; color: #555; margin-top: 20px; list-style: none; padding: 0;">
                <li style="padding: 8px 0; border-bottom: 1px solid #eee;"><i class="mdi mdi-check text-success"></i> Multi-user access</li>
                <li style="padding: 8px 0; border-bottom: 1px solid #eee;"><i class="mdi mdi-check text-success"></i> White-label reports</li>
                <li style="padding: 8px 0; border-bottom: 1px solid #eee;"><i class="mdi mdi-check text-success"></i> API access</li>
                <li style="padding: 8px 0;"><i class="mdi mdi-check text-success"></i> Priority support</li>
            </ul>
        </div>
    </div>
</div>

<!-- Contact Section -->
<div class="section">
    <h2>Contact Us</h2>
    <div class="contact-info">
        <div class="info-item">
            <i class="mdi mdi-map-marker"></i>
            <div>
                <strong>Location</strong><br>
                <span style="color: #666;">Nairobi, Kenya</span>
            </div>
        </div>
        <div class="info-item">
            <i class="mdi mdi-email"></i>
            <div>
                <strong>Email</strong><br>
                <span style="color: #666;">info@wpms.co.ke</span>
            </div>
        </div>
        <div class="info-item">
            <i class="mdi mdi-phone"></i>
            <div>
                <strong>Phone</strong><br>
                <span style="color: #666;">+254 700 000 000</span>
            </div>
        </div>
        <div class="info-item">
            <i class="mdi mdi-web"></i>
            <div>
                <strong>Website</strong><br>
                <span style="color: #666;">www.wpms.co.ke</span>
            </div>
        </div>
        <div class="info-item">
            <i class="mdi mdi-whatsapp"></i>
            <div>
                <strong>WhatsApp</strong><br>
                <span style="color: #666;">+254 700 000 000</span>
            </div>
        </div>
    </div>
</div>

<!-- CTA Section -->
<div class="cta-section">
    <h2>Ready to Plan Your Dream Wedding?</h2>
    <p>Join hundreds of Kenyan couples and wedding planners who trust Everafter Planner to make their special day unforgettable.</p>
    <a href="index.php" class="btn btn-light"><i class="mdi mdi-ring"></i> Register Now</a>
    <a href="pricing.php" class="btn btn-outline-light"><i class="mdi mdi-cash"></i> View Pricing</a>
</div>

<!-- Footer -->
<footer>
    <p><strong>Everafter Planner</strong> — Wedding Planning Made Easy</p>
    <p style="margin-top: 10px; font-size: 12px;">
        &copy; <?php echo date('Y'); ?> Everafter Planner. All amounts in KSh. M-Pesa, Africa's Talking integrated.
    </p>
    <p style="margin-top: 10px; font-size: 11px; opacity: 0.6;">
        Crafted for ruracio, church & reception — with love for Kenyan weddings.
    </p>
</footer>

<script src="js/jquery-3.2.1.slim.min.js"></script>
<script src="js/jquery.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>