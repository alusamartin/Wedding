<?php include 'include/init.php'; if (staff_paywall_locked()) { redirect_to('subscription.php'); exit; } ?>
<?php
/**
 * Couple's wedding-website builder — the shareable public page.
 *
 * The model (WeddingWebsite) and the public page (site.php) already existed
 * but nothing let a couple create or edit theirs — this page is the missing
 * half. One website per booking; slug auto-generated from the couple names
 * and stable once created. Publishing flips is_published, which is all the
 * public page checks. Clients only touch their own booking's site.
 */
    $booking_id = (int) ($_GET['booking_id'] ?? 0);
    $user_id    = (int) ($_GET['user_id'] ?? 0);

    if (!isset($_SESSION['id'])) {
        redirect_to("../");
        die();
    }
    $booking = Booking::find_by_booking_id($booking_id);
    // Clients only touch their own booking's site; staff may preview any
    // (a missing booking is refused for everyone by the helper).
    if (!may_open_couple_page($booking, $user_id)) {
        redirect_to("client.php");
        die();
    }
    $links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

    // get_by_booking returns a bare stdClass row; rehydrate it as a real
    // model via the base class instantiation helper so create()/update()
    // work for saving edits.
    $website_row = WeddingWebsite::get_by_booking($booking_id);
    $website = $website_row !== null ? WeddingWebsite::instantiation($website_row) : null;
    $error   = '';
    $notice  = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$session->verify_csrf()) {
            $error = 'Invalid security token. Please try again.';
        } elseif (isset($_POST['toggle_publish'])) {
            // Publish / unpublish — the public page only ever shows published rows.
            // get_by_booking returns a stdClass row (not a model), so flip it
            // with a direct scoped UPDATE instead of $model->update().
            if ($website === null) {
                $error = 'Create your page first, then publish it.';
            } else {
                global $db;
                $new_state = ((int) $website->is_published === 1) ? 0 : 1;
                $stmt = $db->prepare('UPDATE tblwedding_websites SET is_published = :s WHERE id = :id AND booking_id = :bid');
                if ($stmt->execute([':s' => $new_state, ':id' => (int) $website->id, ':bid' => $booking_id])) {
                    $website->is_published = $new_state;
                    $notice = $new_state
                        ? 'Your wedding page is live — share the link!'
                        : 'Your wedding page is hidden from the public.';
                } else {
                    $error = 'Could not save that change. Please try again.';
                }
            }
        } else {
            // Create or update the content.
            $title = clean($_POST['title'] ?? '');
            $story = trim((string) ($_POST['story'] ?? ''));
            $venue = clean($_POST['venue'] ?? '');
            $venue_address = clean($_POST['venue_address'] ?? '');
            $wedding_date  = clean($_POST['wedding_date'] ?? '');

            if ($title === '') {
                $error = 'Give your page a title — most couples use their names, e.g. "Brian & Linda".';
            } elseif (mb_strlen($story) > 5000) {
                $error = 'Your story is a little too long — please keep it under 5,000 characters.';
            } else {
                if ($website === null) {
                    $website = new WeddingWebsite();
                    $website->booking_id   = $booking_id;
                    $website->slug         = WeddingWebsite::generate_slug($booking->bride ?? 'couple', $booking->groom ?? 'wedding');
                    $website->views        = 0;
                    $website->created_at   = date('Y-m-d H:i:s');
                    $website->is_published = 0;
                }
                $website->title         = $title;
                $website->story         = $story;
                $website->venue         = $venue;
                $website->venue_address = $venue_address;
                // Fall back to the booking's own date when left blank.
                $website->wedding_date  = $wedding_date !== '' ? $wedding_date : (string) ($booking->wedding_date ?? '');
                if ($website->id > 0) {
                    $ok = $website->update();
                } else {
                    $ok = $website->create();
                }
                if ($ok) {
                    $notice = 'Your wedding page is saved.';
                } else {
                    $error = 'Could not save your page. Please try again.';
                }
            }
        }
    }

    $public_url = $website !== null && $website->id > 0
        ? site_base_url() . '/site.php?slug=' . rawurlencode((string) $website->slug)
        : '';
    $couple = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''), ' &');
    $is_client_view = !is_staff();
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= $is_client_view ? 'Our Wedding Page - Everafter Planner' : 'Couple Wedding Page - Everafter Planner'; ?></title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .stat-card { background: #fff; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px;
                     box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-left: 4px solid #065F46; }
        .stat-card h6 { font-size: 11px; text-transform: uppercase; color: #64748B; margin: 0; letter-spacing: .06em; }
        .share-box { background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 12px; padding: 16px 18px; margin-bottom: 18px; }
        .share-box code { background: #fff; border: 1px solid #D1FAE5; border-radius: 8px; padding: 6px 10px; font-size: 12px; word-break: break-all; }
        .badge-live { background: #059669; color: #fff; }
        .badge-hidden { background: #64748B; color: #fff; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-web" style="color:#065F46"></i> <?= $is_client_view ? 'Our Wedding Page' : 'Couple Wedding Page'; ?></h4>
            <p class="text-muted mb-0" style="font-size: 13px;">
                <?= $couple !== '' ? htmlspecialchars($couple) . ' — ' : ''; ?>one beautiful page to share on WhatsApp — story, venue, countdown and RSVP
            </p>
        </div>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <strong><i class="mdi mdi-check"></i></strong> <?= htmlspecialchars($notice); ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong><i class="mdi mdi-alert"></i></strong> <?= htmlspecialchars($error); ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
    <?php endif; ?>

    <?php if ($public_url !== ''): ?>
    <div class="share-box d-flex flex-wrap justify-content-between align-items-center">
        <div class="mr-3 mb-2 mb-md-0">
            <span class="badge <?= (int) $website->is_published === 1 ? 'badge-live' : 'badge-hidden'; ?> mr-2">
                <?= (int) $website->is_published === 1 ? 'LIVE' : 'HIDDEN'; ?>
            </span>
            <span style="font-size:12px; color:#64748B;"><?= (int) $website->views; ?> page views</span>
        </div>
        <div class="d-flex align-items-center flex-wrap">
            <code class="mr-2" id="siteUrl"><?= htmlspecialchars($public_url); ?></code>
            <a class="btn btn-sm btn-outline-success mr-2" href="<?= htmlspecialchars($public_url); ?>" target="_blank" rel="noopener">
                <i class="mdi mdi-eye mr-1"></i>Preview
            </a>
            <a class="btn btn-sm btn-wa mr-2" target="_blank" rel="noopener"
               href="https://wa.me/?text=<?= rawurlencode('Karibu! You are invited to our wedding 🎉 ' . $public_url); ?>">
                <i class="mdi mdi-whatsapp mr-1"></i>Share on WhatsApp
            </a>
            <form method="post" class="m-0" action="client_website.php?<?= $links; ?>">
                <?= $session->csrf_field(); ?>
                <button type="submit" name="toggle_publish" value="1" class="btn btn-sm <?= (int) $website->is_published === 1 ? 'btn-outline-secondary' : 'btn-primary'; ?>">
                    <i class="mdi <?= (int) $website->is_published === 1 ? 'mdi-eye-off' : 'mdi-upload'; ?> mr-1"></i><?= (int) $website->is_published === 1 ? 'Unpublish' : 'Publish'; ?>
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card" style="border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.08);">
        <div class="card-body">
            <h6 class="mb-3"><i class="mdi <?= $website === null ? 'mdi-plus-circle-outline' : 'mdi-pencil'; ?> mr-1"></i><?= $website === null ? 'Create your page' : 'Edit your page'; ?></h6>
            <form method="post" action="client_website.php?<?= $links; ?>">
                <?= $session->csrf_field(); ?>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label class="required">Page title</label>
                        <input type="text" name="title" class="form-control" maxlength="200" required
                               value="<?= $website !== null ? htmlspecialchars($website->title) : htmlspecialchars($couple !== '' ? $couple : ''); ?>"
                               placeholder='e.g. "Brian & Linda say I do!"'>
                    </div>
                    <div class="form-group col-md-3">
                        <label>Wedding date shown</label>
                        <input type="date" name="wedding_date" class="form-control"
                               value="<?= $website !== null && !empty($website->wedding_date) ? htmlspecialchars(date('Y-m-d', strtotime((string) $website->wedding_date))) : ($booking->wedding_date ?? '' !== '' ? htmlspecialchars(date('Y-m-d', strtotime((string) $booking->wedding_date))) : ''); ?>">
                    </div>
                    <div class="form-group col-md-3">
                        <label>Venue name</label>
                        <input type="text" name="venue" class="form-control" maxlength="200"
                               value="<?= $website !== null ? htmlspecialchars($website->venue) : ''; ?>"
                               placeholder="e.g. Karen Gardens, Nairobi">
                    </div>
                </div>
                <div class="form-group">
                    <label>Venue address (guests use this for directions)</label>
                    <input type="text" name="venue_address" class="form-control" maxlength="255"
                           value="<?= $website !== null ? htmlspecialchars($website->venue_address) : ''; ?>"
                           placeholder="e.g. Karen Road, Langata — gate opens 9:00 AM">
                </div>
                <div class="form-group">
                    <label>Our story <span class="text-muted" style="font-size:12px;">(how you met, the proposal, the committee — guests love it)</span></label>
                    <textarea name="story" class="form-control" rows="5" maxlength="5000"
                              placeholder="Tell your guests the journey so far…"><?= $website !== null ? htmlspecialchars($website->story) : ''; ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="mdi mdi-content-save mr-1"></i><?= $website === null ? 'Create page' : 'Save changes'; ?>
                </button>
            </form>
        </div>
    </div>

    <div class="alert alert-light border mt-3" style="font-size:12.5px; color:#475569;">
        <i class="mdi mdi-information-outline mr-1"></i>
        Guests RSVP straight from your page — every reply lands in <a href="client_guests.php?<?= $links; ?>">My Guests</a>
        and your planner is alerted instantly. Cover photos from your gallery can be added by your planner.
    </div>
</div>

<?php include 'include/footer.php'; ?>
