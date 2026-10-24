<?php include 'include/init.php'; ?>
<?php
/**
 * WPMS Kenya v2.17 — Approvals: the human half of a Tier-3 MCP write
 *
 * An AI client can now ask to DO things with this platform's data. Two kinds of
 * things it may do on its own authority, and one kind it may not:
 *
 *   Tier 2 (add a guest, tick a checklist item, mark invitations) runs on
 *   `confirm: true` and is undoable on the page it belongs to.
 *
 *   TIER 3 — an SMS to a guest list, a payment row, an M-Pesa deposit prompt, a
 *   planner assignment — changes somebody's money or reaches real people, and an
 *   assistant cannot undo either. Those tools REFUSE to act on their own. They
 *   resolve the real data, write a row to tblapi_approvals, and answer the model
 *   with a reference and a sentence.
 *
 * THIS PAGE IS THAT SENTENCE'S DESTINATION, and it is deliberately boring: a
 * planner signed in with their own session, a CSRF token on every button, and one
 * decision per row — Approve or Deny. Approving MINTS the approval string: it is
 * HMAC-signed over the tool, the booking, the asking token, the exposed expiry AND
 * a hash of the exact payload (that SMS body, that audience, that amount, that
 * M-Pesa code). It is shown exactly once, it is single-use, and it expires in 15
 * minutes. Change one character of the SMS and it no longer fits — so what you
 * read here is exactly what will happen, or nothing happens at all.
 *
 * WHAT THIS PAGE MUST NEVER DO:
 *   - approve on someone's behalf without their session (there is no link, no
 *     token, no cron path into mint());
 *   - hide the payload behind a tool name ("send_bulk_sms" is not a decision);
 *   - let a request for a booking this planner cannot open be approved — the
 *     same booking_visible_to_session() rule every page uses is applied here too,
 *     which is why per-planner scoping (Helper.php, `WPMS_PLANNER_SCOPING`)
 *     needed no change to this page:
 *     a planner is shown the request for another planner's wedding but is told
 *     it is not one they can open, and the POST is refused the same way.
 *
 * The audit trail of what was approved, used and refused is the second table.
 * Storage: migrations/v2.17_mcp_approvals.sql. Design: docs/MCP_PLAN.md.
 */
require_staff_subscribed();

/** Was a fresh approval just minted on THIS request? Shown once, never stored. */
$minted = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$session->verify_csrf()) {
        $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
        redirect_to('mcp_approvals.php');
        die();
    }

    $approval_id = (int) ($_POST['approval_id'] ?? 0);
    $action      = clean($_POST['action'] ?? '');
    $row         = McpApprovals::find($approval_id);

    // The request must exist, still be waiting, and concern a wedding this
    // planner may open — the same predicate admin/ pages use. Checked here as
    // well as in the template, because a POST is its own request.
    $visible = $row !== null
        && booking_visible_to_session(Booking::find_by_booking_id((int) ($row->booking_id ?? 0)));

    if (!$visible) {
        $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> That request is not waiting for a decision, or its wedding is not one you can open.</div>');
        redirect_to('mcp_approvals.php');
        die();
    }

    if ($action === 're_request') {
        try {
            $renewed = McpApprovals::revive($approval_id, (int) $_SESSION['id']);
            $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-refresh"></i></strong> '
                . htmlspecialchars($renewed['source_reference']) . ' was '
                . ($renewed['reused'] ? 'already waiting as ' : 're-requested as ')
                . '<strong>' . htmlspecialchars($renewed['reference']) . '</strong> in the inbox above, '
                . 'with a fresh ' . McpApprovals::REQUEST_TTL_MINUTES . '-minute window. The old reference stays expired; '
                . 'approve the new one when you are ready.</div>');
        } catch (McpApprovalError $e) {
            $session->message('<div class="alert alert-warning"><strong><i class="mdi mdi-alert"></i></strong> '
                . htmlspecialchars($e->getMessage()) . '</div>');
        }
        redirect_to('mcp_approvals.php');
        die();
    }

    if ($action === 'deny') {
        try {
            McpApprovals::deny($approval_id, (int) $_SESSION['id'], clean($_POST['reason'] ?? ''));
            $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-cancel"></i></strong> ' . McpApprovals::reference($approval_id)
                . ' was refused. The assistant is told it was refused and nothing was sent or recorded.</div>');
        } catch (McpApprovalError $e) {
            $session->message('<div class="alert alert-warning"><strong><i class="mdi mdi-alert"></i></strong> ' . htmlspecialchars($e->getMessage()) . '</div>');
        }
        redirect_to('mcp_approvals.php');
        die();
    }

    try {
        $m = McpApprovals::mint($approval_id, (int) $_SESSION['id']);
        $minted = [
            'reference'  => McpApprovals::reference($approval_id),
            'token'      => $m['token'],
            'expires_at' => $m['expires_at'],
            'tool'       => (string) $row->tool,
            'booking_id' => (int) ($row->booking_id ?? 0),
            'note'       => (string) $row->payload_note,
        ];
    } catch (McpApprovalError $e) {
        $session->message('<div class="alert alert-warning"><strong><i class="mdi mdi-alert"></i></strong> ' . htmlspecialchars($e->getMessage()) . '</div>');
        redirect_to('mcp_approvals.php');
        die();
    }
}

$pending = McpApprovals::pending();
$decided = McpApprovals::decided();

/**
 * Only the rows for weddings this planner can open are actionable; the rest are
 * shown greyed with the reason, because hiding them would leave a planner
 * wondering why a reference the assistant quoted is nowhere on the page.
 */
$open_for = static function (object $row): bool {
    $bookingId = (int) ($row->booking_id ?? 0);
    return $bookingId <= 0 || (bool) booking_visible_to_session(Booking::find_by_booking_id($bookingId));
};

/** The label a state is shown under, and how it looks. */
$state_badge = static function (string $state, object $row): array {
    return match ($state) {
        'pending'  => ['Waiting for you', 'badge-warning'],
        'approved' => ['Approved — waiting to be used', 'badge-info'],
        'used'     => ['Used', 'badge-success'],
        'denied'   => ['Refused', 'badge-danger'],
        // Two kinds of expiry, and both can be revived: nobody answered, or a
        // planner approved and the assistant never used the string in time.
        default    => [empty($row->approved_at) ? 'Expired unanswered' : 'Expired unused', 'badge-secondary'],
    };
};
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>AI Approvals - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body { margin-bottom: 2%; }
        .box-shadow { box-shadow: 0 0 2px 1px rgba(0,0,0,0.1); }
        .form-control, .btn { font-size: 13px; }
        .payload { white-space: pre-wrap; font-size: 13px; }
        .approval-string { font-family: Menlo, Monaco, Consolas, monospace; font-size: 12px; }
        table.approvals td, table.approvals th { font-size: 13px; vertical-align: top; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>
<div class="container">
    <div class="row">
        <div class="col-lg-11 offset-1 pl-3 pb-3 box-shadow mt-4">
            <h4 class="h4 mt-4 pb-2" style="border-bottom: 1px solid #dee2e6;">
                <i class="mdi mdi-shield-check-outline"></i> AI Approvals
                <?php if (count($pending) > 0): ?>
                    <span class="badge badge-warning"><?= count($pending); ?> waiting</span>
                <?php endif; ?>
            </h4>

            <?php if ($session->message()): ?>
                <div class="form-group col-md-12 p-0"><?= $session->message(); ?></div>
            <?php endif; ?>

            <?php if (!McpApprovals::available()): ?>
                <div class="alert alert-warning">
                    <strong><i class="mdi mdi-database-alert"></i> Not installed yet.</strong>
                    This page needs <code>migrations/v2.17_mcp_approvals.sql</code>. Until it is applied, an
                    assistant cannot perform any money or message action at all — the Tier-3 tools refuse.
                </div>
            <?php endif; ?>

            <?php if ($minted !== null): ?>
                <div class="alert alert-success">
                    <h5 class="mb-2"><i class="mdi mdi-key-variant"></i> Approval minted: <?= htmlspecialchars($minted['reference']); ?></h5>
                    <p class="mb-2" style="font-size:13px;">
                        <strong><?= htmlspecialchars($minted['tool']); ?></strong>
                        <?= $minted['booking_id'] > 0 ? 'on wedding #' . (int) $minted['booking_id'] : ''; ?> — expires
                        <strong><?= htmlspecialchars($minted['expires_at']); ?></strong>. Copy it now and paste it back into the
                        assistant's call as <code>approval</code>:
                    </p>
                    <input type="text" class="form-control approval-string" readonly
                           value="<?= htmlspecialchars($minted['token']); ?>"
                           onclick="this.select();">
                    <p class="mt-2 mb-0" style="font-size:13px;">
                        <strong>This is the only time it is shown.</strong> Only its hash is stored, so it cannot be re-displayed.
                        It works once, for exactly this payload, and only on the token that asked.
                    </p>
                    <p class="mt-2 mb-0 payload text-muted"><?= htmlspecialchars($minted['note']); ?></p>
                </div>
            <?php endif; ?>

            <h5 class="h5 mt-4">Waiting for a decision</h5>
            <?php if ($pending === []): ?>
                <p class="text-muted" style="font-size:13px;">
                    Nothing is waiting. An assistant that asks to send an SMS, record a payment, prompt M-Pesa or assign a
                    planner appears here first — with the exact wording, audience or amount it wants — and does nothing
                    until you decide.
                </p>
            <?php else: ?>
                <table class="table table-striped approvals">
                    <thead>
                        <tr>
                            <th style="width:12%;">Request</th>
                            <th style="width:30%;">What it will do</th>
                            <th style="width:16%;">Asked by</th>
                            <th style="width:42%;">Your decision</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pending as $row): ?>
                        <?php $can = $open_for($row); ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars(McpApprovals::reference((int) $row->id)); ?></strong><br>
                                <span class="badge badge-warning">Tier 3</span><br>
                                <code><?= htmlspecialchars((string) $row->tool); ?></code><br>
                                <?php if ((int) ($row->booking_id ?? 0) > 0): ?>
                                    <a href="client.php">wedding #<?= (int) $row->booking_id; ?></a>
                                <?php else: ?>
                                    <span class="text-muted">no booking</span>
                                <?php endif; ?>
                                <br><span class="text-muted" style="font-size:12px;">expires <?= htmlspecialchars((string) $row->expires_at); ?></span>
                                <?php if ((int) ($row->re_request_of ?? 0) > 0): ?>
                                    <br><span class="badge badge-light">re-requested from <?= htmlspecialchars(McpApprovals::reference((int) $row->re_request_of)); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="payload"><?= htmlspecialchars((string) $row->payload_note); ?></div>
                                <div class="text-muted mt-1" style="font-size:12px;">
                                    payload hash <code><?= htmlspecialchars(substr((string) $row->payload_hash, 0, 16)); ?>…</code>
                                    — the approval is bound to it, so a changed message or amount cannot be approved by this decision.
                                </div>
                            </td>
                            <td>
                                <?= htmlspecialchars((string) ($row->token_name ?? 'deleted token')); ?><br>
                                <span class="text-muted" style="font-size:12px;">prefix <?= htmlspecialchars((string) ($row->token_prefix ?? '—')); ?>…</span><br>
                                <span class="text-muted" style="font-size:12px;">asked <?= htmlspecialchars((string) $row->created_at); ?></span>
                            </td>
                            <td>
                                <?php if (!$can): ?>
                                    <span class="text-muted">This wedding is not one you can open, so you cannot decide this request.</span>
                                <?php else: ?>
                                    <form method="post" action="" class="mb-2">
                                        <?= $session->csrf_field(); ?>
                                        <input type="hidden" name="approval_id" value="<?= (int) $row->id; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="mdi mdi-check"></i> Approve this exact payload
                                        </button>
                                        <span class="text-muted" style="font-size:12px;">Mints a one-use string, valid 15 minutes.</span>
                                    </form>
                                    <form method="post" action="">
                                        <?= $session->csrf_field(); ?>
                                        <input type="hidden" name="approval_id" value="<?= (int) $row->id; ?>">
                                        <input type="hidden" name="action" value="deny">
                                        <div class="input-group input-group-sm">
                                            <input type="text" name="reason" class="form-control" maxlength="255"
                                                   placeholder="Why not? (the assistant is told, so it can do better)">
                                            <div class="input-group-append">
                                                <button type="submit" class="btn btn-outline-danger">
                                                    <i class="mdi mdi-cancel"></i> Refuse
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h5 class="h5 mt-5">Decisions and expiries</h5>
            <?php if ($decided === []): ?>
                <p class="text-muted" style="font-size:13px;">Nothing has been approved, refused or expired yet.</p>
            <?php else: ?>
                <table class="table table-sm approvals">
                    <thead>
                        <tr>
                            <th>Request</th>
                            <th>What it was</th>
                            <th>State</th>
                            <th>Decided</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($decided as $row): ?>
                        <?php
                        $state = McpApprovals::state_of($row);
                        [$label, $badge] = $state_badge($state, $row);
                        $can = $open_for($row);
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars(McpApprovals::reference((int) $row->id)); ?></strong><br>
                                <code><?= htmlspecialchars((string) $row->tool); ?></code>
                                <?php if ((int) ($row->booking_id ?? 0) > 0): ?>
                                    <br><span class="text-muted">wedding #<?= (int) $row->booking_id; ?></span>
                                <?php endif; ?>
                            </td>
                            <td><div class="payload"><?= htmlspecialchars((string) $row->payload_note); ?></div></td>
                            <td>
                                <span class="badge <?= $badge; ?>"><?= htmlspecialchars($label); ?></span>
                                <?php if (!empty($row->revived_as_id)): ?>
                                    <br><span class="text-muted" style="font-size:12px;">revived as <?= htmlspecialchars(McpApprovals::reference((int) $row->revived_as_id)); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;">
                                <?php if (!empty($row->approved_at)): ?>
                                    approved <?= htmlspecialchars((string) $row->approved_at); ?><br>
                                    <span class="text-muted">by <?= htmlspecialchars((string) ($row->approved_by_name ?? ('user #' . (int) $row->approved_by))); ?></span>
                                <?php elseif (!empty($row->denied_at)): ?>
                                    refused <?= htmlspecialchars((string) $row->denied_at); ?><br>
                                    <span class="text-muted"><?= htmlspecialchars((string) ($row->deny_reason !== '' ? '“' . $row->deny_reason . '”' : 'no reason given')); ?></span>
                                <?php else: ?>
                                    no decision inside the 30-minute window<br>
                                    <span class="text-muted">
                                        window closed <?= htmlspecialchars((string) $row->expires_at); ?>
                                        — nothing was sent or recorded, and the assistant was told.
                                    </span>
                                <?php endif; ?>
                                <?php if ($state === 'expired'): ?>
                                    <?php if (!empty($row->approved_at)): ?>
                                        <br><span class="text-muted">approved, but the string was never used before its window closed</span>
                                    <?php endif; ?>
                                    <?php if ($can && McpApprovals::available()): ?>
                                        <form method="post" action="" class="mt-2">
                                            <?= $session->csrf_field(); ?>
                                            <input type="hidden" name="approval_id" value="<?= (int) $row->id; ?>">
                                            <input type="hidden" name="action" value="re_request">
                                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                                <i class="mdi mdi-refresh"></i> Re-request this payload
                                            </button>
                                            <div class="text-muted" style="font-size:12px;">
                                                Opens a fresh <?= McpApprovals::REQUEST_TTL_MINUTES; ?>-minute request for the same payload.
                                                The assistant will not have to ask again.
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="text-muted mt-4" style="font-size:12px;">
                Refusing here is not a failure: the assistant is told the request was refused and can propose something else.
                Approving is not a blanket permission either — it authorises ONE payload, once, for fifteen minutes. What each
                token may ask for at all is set on the
                <a href="api_tokens.php">AI Assistant</a> page, and what it actually did is in that page's call log.
            </p>
        </div>
    </div>
</div>
<?php include_once 'include/footer.php'; ?>
</body>
</html>
