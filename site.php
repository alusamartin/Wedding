<?php
/**
 * Wedding Website Builder - Simple public-facing wedding page
 * Access at: /wedding/site/slug
 */
include_once '../admin/include/init.php';

$slug = isset($_GET['slug']) ? clean($_GET['slug']) : '';

if (empty($slug)) {
    header("Location: ../");
    exit;
}

$website = WeddingWebsite::get_by_slug($slug);

if (!$website) {
    header("Location: ../");
    exit;
}

// Track view
WeddingWebsite::increment_views($website->id);

// Get booking details for RSVP
$booking = Booking::find_by_booking_id($website->booking_id);
$gallery = Gallery::find_by_event_all($website->booking_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= htmlspecialchars($website->title ?: 'Our Wedding'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/modern.css">
    <style>
        :root{ --kenya-green:#065F46; --kenya-gold:#D97706; --radius:16px; --shadow:0 8px 24px rgba(0,0,0,.07); }
        body { font-family:'Inter','Plus Jakarta Sans',sans-serif; background:#FAFAF8; color:#1C1917; }
        .hero-section {
            background: linear-gradient(180deg, rgba(11,15,20,.22) 0%, rgba(11,15,20,.68) 100%), url('../admin/<?= $website->cover_image ?: "upload/gallery/default.jpg"; ?>') center/cover;
            color: #fff; padding: 96px 20px 72px; text-align: center; position:relative;
        }
        .hero-section::after{ content:"Kenya · KSh · M-Pesa"; position:absolute; top:18px; left:50%; transform:translateX(-50%); background:rgba(255,255,255,.14); backdrop-filter:blur(8px); border:1px solid rgba(255,255,255,.22); color:#fff; font-size:11px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; padding:5px 12px; border-radius:999px; }
        .hero-section h1 { font-family:'Plus Jakarta Sans',sans-serif; font-size:clamp(28px,5vw,46px); font-weight:800; letter-spacing:-.02em; margin-bottom:8px; text-shadow:0 2px 12px rgba(0,0,0,.24); }
        .hero-section .date { font-size:15px; opacity:.92; font-weight:600; }
        .hero-section .venue { font-size:13px; opacity:.82; margin-top:4px; }
        .section { padding:48px 20px; max-width:820px; margin:0 auto; }
        .section h2 { font-family:'Plus Jakarta Sans',sans-serif; text-align:center; margin-bottom:22px; color:var(--kenya-green); font-weight:800; letter-spacing:-.01em; }
        .story-text { line-height:1.75; color:#57534E; text-align:center; font-size:15px; max-width:64ch; margin:0 auto; }
        .gallery-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(180px,1fr)); gap:12px; }
        .gallery-grid img { width:100%; height:200px; object-fit:cover; border-radius:12px; border:1px solid #E7E5E4; box-shadow:0 1px 3px rgba(0,0,0,.06); transition:transform .2s; }
        .gallery-grid img:hover{ transform:translateY(-2px); }
        .rsvp-form { background:#fff; padding:28px; border-radius:16px; box-shadow:0 8px 24px rgba(0,0,0,.07); border:1px solid #E7E5E4; }
        .rsvp-form .form-control{ border-radius:10px; border-color:#E7E5E4; }
        .rsvp-form .btn-danger{ background:var(--kenya-green); border-color:var(--kenya-green); border-radius:999px; font-weight:700; padding:.65rem 1.2rem; }
        footer { text-align:center; padding:28px; color:#78716C; font-size:13px; border-top:1px solid #E7E5E4; margin-top:24px; }
        .countdown { display:flex; justify-content:center; gap:18px; margin:28px 0 0; flex-wrap:wrap; }
        .countdown div { background:rgba(255,255,255,.12); backdrop-filter:blur(8px); border:1px solid rgba(255,255,255,.18); border-radius:14px; padding:14px 18px; min-width:88px; }
        .countdown .number { font-size:28px; font-weight:800; color:#fff; line-height:1; }
        .countdown .label { font-size:10px; text-transform:uppercase; letter-spacing:.08em; color:rgba(255,255,255,.82); margin-top:4px; }
    </style>
</head>
<body>
    <div class="hero-section">
        <h1><?= htmlspecialchars($website->title ?: 'Our Wedding'); ?></h1>
        <div class="date"><?= date('F j, Y', strtotime($website->wedding_date)); ?></div>
        <div class="venue"><?= htmlspecialchars($website->venue); ?></div>
        <div class="countdown">
            <div><span class="number" id="cd-days">0</span><div class="label">Days</div></div>
            <div><span class="number" id="cd-hours">0</span><div class="label">Hours</div></div>
            <div><span class="number" id="cd-mins">0</span><div class="label">Minutes</div></div>
        </div>
    </div>

    <?php if ($website->story): ?>
    <div class="section">
        <h2>Our Story</h2>
        <p class="story-text"><?= nl2br(htmlspecialchars($website->story)); ?></p>
    </div>
    <?php endif; ?>

    <?php if (!empty($gallery)): ?>
    <div class="section">
        <h2>Gallery</h2>
        <div class="gallery-grid">
            <?php foreach (array_slice($gallery, 0, 8) as $photo): ?>
                <img src="../admin/<?= $photo->picture_path(); ?>" alt="<?= htmlspecialchars($photo->caption ?: 'Wedding photo'); ?>">
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="section">
        <h2>RSVP</h2>
        <div class="rsvp-form">
            <form method="post" action="rsvp.php">
                <input type="hidden" name="website_id" value="<?= $website->id; ?>">
                <div class="form-group">
                    <label>Your Name</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Will you attend?</label>
                    <select name="attending" class="form-control" required>
                        <option value="yes">Yes, I'll be there!</option>
                        <option value="no">Sorry, can't make it</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Number of Guests</label>
                    <input type="number" name="guests" class="form-control" value="1" min="1" max="5">
                </div>
                <div class="form-group">
                    <label>Dietary Requirements</label>
                    <input type="text" name="dietary" class="form-control" placeholder="e.g. Vegetarian, Halal">
                </div>
                <button type="submit" class="btn btn-danger btn-block">Send RSVP</button>
            </form>
        </div>
    </div>

    <footer>
        <p>Powered by <strong>Everafter Planner</strong> — Wedding Planning Made Easy</p>
        <p style="font-size: 11px; margin-top: 10px;">Page views: <?= number_format($website->views); ?></p>
    </footer>

    <script>
        // Countdown timer
        var weddingDate = new Date('<?= date('c', strtotime($website->wedding_date)); ?>').getTime();
        setInterval(function() {
            var now = new Date().getTime();
            var diff = weddingDate - now;
            if (diff > 0) {
                document.getElementById('cd-days').textContent = Math.floor(diff / (1000*60*60*24));
                document.getElementById('cd-hours').textContent = Math.floor((diff % (1000*60*60*24)) / (1000*60*60));
                document.getElementById('cd-mins').textContent = Math.floor((diff % (1000*60*60)) / (1000*60));
            }
        }, 1000);
    </script>
</body>
</html>