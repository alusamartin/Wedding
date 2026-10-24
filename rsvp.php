<?php
/**
 * Public RSVP page — token-based, no login required.
 *
 * Reached via links couples share over WhatsApp: /rsvp.php?g=<32-hex token>.
 * The token IS the capability: it scopes the page to exactly one guest of
 * one booking, so no id guessing and no enumeration are possible. Guests
 * can confirm, decline or answer "maybe" (with a note) and re-answer until
 * the wedding — the couple's guest list and the assigned planner's bell
 * update live.
 */
include 'admin/include/init.php';

// NOTE: init.php is included for DB + helpers only. The session is left
// untouched so a planner who opens an RSVP link while logged in keeps
// their admin session. This page renders no admin chrome.

$token = (string) ($_GET['g'] ?? $_POST['token'] ?? '');
$guest = $token !== '' ? Guest::find_by_rsvp_token($token) : null;
$flash = '';
// Toned, not always green: a refusal or a failed save is not good news, and a
// success-coloured box saying "try again later" reads as a bug.
$flashTone = 'success';

if ($guest) {
    $booking = Booking::find_by_booking_id((int) $guest->booking_id);
    if (!$booking) {
        $guest = null; // booking deleted — treat token as invalid
    }
}

if ($guest !== null && isset($_POST['rsvp'], $_POST['status'])) {
    $status = (string)($_POST['status'] ?? '');
    $note   = trim((string) ($_POST['note'] ?? ''));

    /*
     * The shared abuse guard (PublicGuard, v2.14). This is the one anonymous
     * POST that becomes a bell AND an email for a planner, so a loop over one
     * invitation link must not be a loop over their inbox: over the ceiling,
     * nothing is written at all — no answer, no notification, no outbox row —
     * and the guest is told to try again. The token is counted as its hash,
     * never in the clear: a link is a capability, and a counter table is not
     * somewhere to keep one.
     */
    $wait = PublicGuard::attempt(PublicGuard::RSVP, PublicGuard::subjectHash($token));

    if ($wait > 0) {
        $flashTone = 'warning';
        $flash = 'That is a lot of answers from this connection. Please try again in '
            . max(1, (int) ceil($wait / 60)) . ' minute(s) — or ask the couple to take your answer directly.';
    } elseif (Guest::record_rsvp($token, $status, $note)) {
        // Refresh for the confirmation view.
        $guest = Guest::find_by_rsvp_token($token);

        // Best-effort in-app alert for the assigned planner (first answer or change).
        $plannerId = (int) ($booking->organizer_id ?? 0);
        if ($plannerId > 0) {
            $answer  = ['yes' => 'confirmed', 'no' => 'declined', 'maybe' => 'answered maybe'][$status] ?? $status;
            $guestLabel = trim((string) $guest->fullname) !== '' ? $guest->fullname : 'A guest';
            Notification::create_for_staff(
                $plannerId,
                (int) $guest->booking_id,
                $guestLabel . ' ' . $answer . ' their invitation (RSVP).',
                'guest_list.php?booking_id=' . (int) $guest->booking_id . '&user_id=' . (int) $booking->user_id,
                'RSVP ' . $answer . ' — booking #' . (int) $guest->booking_id
            );
        }

        $flash = $status === 'yes'
            ? 'Asante! Your attendance is confirmed. We can\'t wait to celebrate with you.'
            : ($status === 'no'
                ? 'Sorry you can\'t make it — thank you for letting us know.'
                : 'Thank you — we\'ve noted that you might attend.');
    } else {
        $flashTone = 'warning';
        $flash = 'We couldn\'t save that answer. Please try again.';
    }
}

$weddingHuman = !empty($booking->wedding_date ?? '') ? format_date_kd($booking->wedding_date) : '';
$couple = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $guest ? 'RSVP — ' . htmlspecialchars($couple) : 'Invitation Link'; ?> - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Montserrat:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Montserrat', sans-serif; background: #F8FAFC; }
        .rsvp-card { max-width: 480px; margin: 6vh auto; background: #fff; border-radius: 22px;
                     box-shadow: 0 12px 40px rgba(15,23,42,.12); overflow: hidden; }
        .rsvp-head { background: linear-gradient(135deg, #065F46 0%, #059669 100%); color: #fff;
                     padding: 34px 28px 26px; text-align: center; }
        .rsvp-head h1 { font-family: 'Playfair Display', serif; font-size: 26px; margin: 12px 0 4px; }
        .rsvp-head .date { opacity: .9; font-size: 14px; letter-spacing: .04em; }
        .rsvp-body { padding: 28px; }
        .rsvp-note { font-size: 13px; color: #64748B; }
        .btn-kenya { border-radius: 30px; font-weight: 600; }
        .badge-answer { font-size: 13px; padding: 6px 14px; border-radius: 20px; }
    </style>
</head>
<body>
    <div class="rsvp-card">
        <div class="rsvp-head">
            <img src="images/logo/everafter-crest.png" alt="" style="height:64px;">
            <h1><?= $guest ? htmlspecialchars($couple) : 'Invitation Not Found'; ?></h1>
            <?php if ($guest): ?><div class="date"><i class="mdi mdi-calendar-heart mr-1"></i><?= htmlspecialchars($weddingHuman); ?></div><?php endif; ?>
        </div>
        <div class="rsvp-body">
            <?php if (!$guest): ?>
                <p class="text-center text-muted mb-0">
                    <i class="mdi mdi-link-off-variant" style="font-size:34px;"></i><br>
                    This invitation link is not valid. Please ask the couple to resend it.
                </p>
            <?php else: ?>
                <?php if ($flash !== ''): ?>
                    <div class="alert alert-<?= $flashTone; ?>" style="font-size:14px;"><?= htmlspecialchars($flash); ?></div>
                <?php endif; ?>

                <?php $answered = in_array($guest->rsvp_status, ['yes', 'no', 'maybe'], true); ?>
                <?php if ($answered && !isset($_POST['rsvp'])): ?>
                    <p class="rsvp-note text-center mb-2">You previously responded:</p>
                    <p class="text-center">
                        <?php if ($guest->rsvp_status === 'yes'): ?>
                            <span class="badge-answer badge-success"><i class="mdi mdi-check-circle mr-1"></i>Attending</span>
                        <?php elseif ($guest->rsvp_status === 'no'): ?>
                            <span class="badge-answer badge-secondary">Not attending</span>
                        <?php else: ?>
                            <span class="badge-answer badge-warning">Maybe</span>
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($guest->rsvp_note)): ?>
                        <p class="rsvp-note text-center mb-3">Your note: “<?= htmlspecialchars($guest->rsvp_note); ?>”</p>
                    <?php endif; ?>
                    <p class="rsvp-note text-center mb-3">You can still change your answer below.</p>
                <?php else: ?>
                    <p class="mb-1"><strong>Hello, <?= htmlspecialchars($guest->fullname); ?></strong></p>
                    <p class="rsvp-note mb-3">Will you attend<?= $weddingHuman !== '' ? ' on ' . htmlspecialchars($weddingHuman) : '' ?>?
                        Please answer below — it takes ten seconds.</p>
                <?php endif; ?>

                <form method="post" action="rsvp.php">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token); ?>">
                    <input type="hidden" name="rsvp" value="1">
                    <div class="btn-group btn-group-toggle d-flex mb-3" data-toggle="buttons">
                        <?php foreach (['yes' => ['mdi-check', 'Joyfully accept', 'btn-success'],
                                             'maybe' => ['mdi-help', 'Maybe', 'btn-warning'],
                                             'no' => ['mdi-close', 'Regretfully decline', 'btn-secondary']] as $st => [$icon, $label, $cls]): ?>
                            <label class="btn btn-kenya <?= $cls; ?> <?= ($guest->rsvp_status ?? '') === $st ? 'active' : ''; ?>" style="flex:1;">
                                <input type="radio" name="status" value="<?= $st; ?>" <?= ($guest->rsvp_status ?? '') === $st ? 'checked' : ''; ?> required>
                                <i class="mdi <?= $icon; ?> mr-1"></i><?= $label; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-group">
                        <label class="rsvp-note">Any note for the couple (dietary needs, arriving with family…)</label>
                        <input type="text" name="note" class="form-control" maxlength="255"
                               value="<?= htmlspecialchars($guest->rsvp_note ?? ''); ?>" placeholder="Optional">
                    </div>
                    <button type="submit" class="btn btn-kenya btn-success btn-block btn-lg">
                        <i class="mdi mdi-send mr-1"></i> Send RSVP
                    </button>
                </form>
                <p class="rsvp-note text-center mt-3 mb-0">Powered by Everafter Planner</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
