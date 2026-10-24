<?php
/**
 * The shared conversation panel (v2.25) — one design for all three sides of a
 * vendor deal, so the couple, the vendor and the planner are looking at the
 * same conversation rather than at three different renderings of one table.
 *
 * Include it with $vt set (and nothing else assumed):
 *
 *   'role'     'couple' | 'vendor' | 'planner' — who is reading this page
 *   'deal'     the deal row: needs id, status, business_name, bride, groom,
 *              and (optionally) notes + created_at for the opening bubble
 *   'messages' VendorThread::for_deal()/for_deals() output, oldest first
 *
 * The panel is presentation only: read state is stamped by the page (the
 * couple's list stamps on render, the ledgers stamp through
 * vendor_thread_open.php), and the write goes to vendor_thread_post.php, which
 * re-derives the role, re-checks ownership and re-verifies the CSRF token.
 *
 * Styles: the .vt-* block in css/modern-admin.css (loaded by all three pages).
 */
if (!isset($vt['role'], $vt['deal']) || !is_object($vt['deal'])) {
    return;
}
$vt_role     = (string) $vt['role'];
$vt_deal     = $vt['deal'];
$vt_messages = is_array($vt['messages'] ?? null) ? $vt['messages'] : [];
$vt_id       = (int) ($vt_deal->id ?? 0);

if (!VendorThread::installed()) {
    echo '<div class="vt-disabled"><i class="mdi mdi-message-text-outline"></i> '
        . 'The conversation is not enabled on this database yet. '
        . '<span class="text-muted">Apply <code>migrations/v2.25_vendor_threads.sql</code>.</span></div>';
    return;
}

$vt_vendor = trim((string) ($vt_deal->business_name ?? '')) ?: 'the vendor';
$vt_couple = trim((string) ($vt_deal->bride ?? '') . ' & ' . (string) ($vt_deal->groom ?? ''), ' &');
$vt_couple = $vt_couple !== '' ? $vt_couple : 'the couple';
$vt_stage  = VendorThread::stage($vt_deal, $vt_messages, $vt_role);
$vt_quick  = VendorThread::quick_replies($vt_role);
$vt_original = VendorThread::original_brief($vt_deal->notes ?? '');

$vt_placeholder = match ($vt_role) {
    VendorThread::COUPLE  => 'Ask ' . $vt_vendor . ' anything — dates, price, what is included…',
    VendorThread::VENDOR  => 'Reply to ' . $vt_couple . ' — availability, price, next step…',
    VendorThread::PLANNER => 'Write to ' . $vt_couple . ' and ' . $vt_vendor . '…',
    default               => 'Write a message…',
};

/** A friendly stamp: time for today, day + time otherwise. */
$vt_when = static function (?string $stamp): string {
    if ($stamp === null || $stamp === '') {
        return '';
    }
    $ts = strtotime($stamp);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d', $ts) === date('Y-m-d') ? 'Today ' . date('H:i', $ts) : date('j M, H:i', $ts);
};
?>
<div class="vt-panel vt-role-<?= htmlspecialchars($vt_role); ?>" data-deal="<?= $vt_id; ?>">
    <div class="vt-stage vt-tone-<?= htmlspecialchars($vt_stage['tone']); ?>">
        <span class="vt-stage-icon"><i class="mdi mdi-<?= htmlspecialchars($vt_stage['icon']); ?>"></i></span>
        <div class="vt-stage-copy">
            <div class="vt-stage-label"><?= htmlspecialchars($vt_stage['label']); ?></div>
            <div class="vt-stage-detail"><?= htmlspecialchars($vt_stage['detail']); ?></div>
        </div>
    </div>

    <div class="vt-thread" aria-live="polite">
        <?php if ($vt_messages === []): ?>
            <div class="vt-empty">
                <i class="mdi mdi-message-text-outline"></i>
                <span><?= $vt_role === VendorThread::COUPLE ? 'No replies yet — your message is below and your planner is copied.' : 'No messages yet. A short first line is all it takes.'; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($vt_original !== ''): ?>
            <div class="vt-msg vt-from-couple vt-theirs">
                <div class="vt-avatar"><i class="mdi mdi-heart"></i></div>
                <div class="vt-bubble">
                    <div class="vt-meta">
                        <strong><?= htmlspecialchars($vt_couple); ?></strong>
                        <span class="vt-tag">Original enquiry</span>
                    </div>
                    <div class="vt-body"><?= nl2br(htmlspecialchars($vt_original)); ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($vt_messages as $vt_m):
            $vt_author = (string) ($vt_m->author_role ?? '');
            $vt_mine   = $vt_author === $vt_role;
            $vt_name   = $vt_mine ? 'You' : (trim((string) ($vt_m->author_name ?? '')) ?: VendorThread::role_label($vt_author));
            ?>
            <div class="vt-msg vt-from-<?= htmlspecialchars($vt_author); ?> <?= $vt_mine ? 'vt-mine' : 'vt-theirs'; ?>">
                <div class="vt-avatar"><i class="mdi mdi-<?= htmlspecialchars(VendorThread::role_icon($vt_author)); ?>"></i></div>
                <div class="vt-bubble">
                    <div class="vt-meta">
                        <strong><?= htmlspecialchars($vt_name); ?></strong>
                        <span class="vt-tag"><?= htmlspecialchars(VendorThread::role_label($vt_author)); ?></span>
                        <time datetime="<?= htmlspecialchars((string) $vt_m->created_at); ?>"><?= htmlspecialchars($vt_when((string) $vt_m->created_at)); ?></time>
                    </div>
                    <div class="vt-body"><?= nl2br(htmlspecialchars((string) $vt_m->body)); ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <form method="post" action="vendor_thread_post.php" class="vt-compose">
        <?= $session->csrf_field(); ?>
        <input type="hidden" name="deal_id" value="<?= $vt_id; ?>">

        <?php if ($vt_quick !== [] && !in_array((string) ($vt_deal->status ?? ''), ['cancelled'], true)): ?>
            <div class="vt-quick-row">
                <?php foreach ($vt_quick as $vt_q): ?>
                    <button type="button" class="vt-quick" data-text="<?= htmlspecialchars($vt_q, ENT_QUOTES); ?>"
                            onclick="var f=this.closest('form').querySelector('textarea[name=body]');f.value=this.dataset.text;f.focus();"><?= htmlspecialchars($vt_q); ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <label class="sr-only" for="vt-body-<?= $vt_id; ?>-<?= htmlspecialchars($vt_role); ?>">Message</label>
        <textarea id="vt-body-<?= $vt_id; ?>-<?= htmlspecialchars($vt_role); ?>" name="body" rows="2" maxlength="<?= VendorThread::MAX_BODY; ?>"
                  class="form-control vt-input" placeholder="<?= htmlspecialchars($vt_placeholder); ?>" required></textarea>
        <div class="vt-compose-bar">
            <span class="vt-hint">
                <?= $vt_role === VendorThread::VENDOR
                    ? 'The couple and their planner both read this.'
                    : 'Everyone on this deal sees this — no need to ring the planner separately.'; ?>
            </span>
            <button type="submit" class="btn btn-sm btn-primary"><i class="mdi mdi-send mr-1"></i> Send</button>
        </div>
    </form>
</div>
