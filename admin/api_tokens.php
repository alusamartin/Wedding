<?php include 'include/init.php'; if (staff_paywall_locked()) { redirect_to('subscription.php'); exit; } ?>
<?php
/**
 * Connect an AI assistant — the page that mints, lists and audits MCP tokens.
 *
 * The MCP endpoint (mcp.php) is only half of the feature. This is the other
 * half: WITHOUT this page there is no way for anybody to obtain a token, which
 * means the endpoint is unreachable for every account on the platform — a
 * feature that exists in the code and nowhere in the product. So the page does
 * three things and nothing else:
 *
 *   1. MINT a token for THIS signed-in account, with the scopes chosen here and
 *      an expiry chosen here. The secret is shown ONCE, with the endpoint URL
 *      and a ready-to-paste config block, because it is stored only as a hash
 *      and can never be shown again.
 *   2. LIST this account's tokens — live, expired and revoked — with when each
 *      was last used and from where, and one button to switch one off.
 *   3. SHOW what the AI has been doing: the last calls from tblapi_audit, with
 *      the refusals included. "What has the AI seen" has to be answerable by the
 *      person whose wedding it is, not only by the operator.
 *
 * WHO MAY USE IT: any signed-in account, for its OWN tokens. A planner mints for
 * their own planner account, a couple for their own wedding account, and a
 * vendor for their own listing account. There is no cross-account minting here:
 * ApiToken::mint() is called with the session's own kind and id, ApiToken::
 * for_owner() lists only that pair, and revoke() is owner-scoped in the model —
 * three independent reasons a token can never be minted FOR somebody else from
 * this page. A super admin is not an exception: the operator's own token is what
 * reaches the operator-only tool, and it is still theirs.
 *
 * THE SCOPE CHECKBOXES ARE THE CEILING, NOT THE PERMISSION. Ticking
 * `write:guests` does not let the AI change a guest list: writes also need
 * WPMS_MCP_WRITES=1 on the install, and the tool then still only reaches the
 * bookings this account may open — the same predicate the pages use. The panel
 * at the top says which of those two switches is on right now, because a token
 * that quietly cannot do what its owner asked for is worse than a token that
 * cannot be minted.
 *
 * Storage: migrations/v2.16_api_tokens.sql (tblapi_tokens, tblapi_audit). Read
 * admin/include/ApiToken.php for the model and admin/include/McpServer.php for
 * the protocol.
 */
    /*
     * The MCP layer is required here explicitly rather than from models.php:
     * only this page and mcp.php itself need it, and requiring it in the shared
     * model set would make every page in the platform parse it. These two files
     * are the platform's whole web-visible surface for AI access.
     */
    require_once __DIR__ . '/include/ApiToken.php';
    require_once __DIR__ . '/include/McpServer.php';

    if (!isset($_SESSION['id'])) {
        redirect_to("../");
        die();
    }

    /*
     * The account this page acts for. `kind` names the table its id indexes
     * (tblusers for staff/vendors, tblaccounts for couples) — the two id spaces
     * of v2.13, which never get compared to each other.
     */
    $kind    = ApiToken::kind_for_session();
    $ownerId = (int) $_SESSION['id'];
    $isCouple = $kind === ApiToken::KIND_ACCOUNT;

    $installed = ApiToken::available();
    $mcpOn     = McpServer::enabled();
    $writesOn  = McpServer::writes_enabled();
    $endpoint  = site_base_url() . '/mcp.php';

    /**
     * The paste-ready config for the common clients. Plain text, shown once,
     * with the caller's own secret in it — never rendered anywhere else. A
     * closure, not a function: this is page-local wording, and a global function
     * named from a page is a collision waiting to happen. It is defined up here
     * because the mint handler below runs on POST, long before the markup — a
     * closure declared beside its only caller is null when that caller fires.
     */
    $configSnippet = static function (string $endpoint, string $secret): string {
        return (string) json_encode([
            'mcpServers' => [
                'everafter-planner' => [
                    'type'    => 'http',
                    'url'     => $endpoint,
                    'headers' => ['Authorization' => 'Bearer ' . $secret],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            . "\n\n# or with curl:\ncurl -s " . $endpoint
            . " -H 'Authorization: Bearer " . $secret . "' -d "
            . "'{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}'";
    };

    // ---- Mint a token (POST + CSRF) ---------------------------------------
    if (isset($_POST['mint_token'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
            redirect_to('api_tokens.php');
            die();
        }
        if (!$installed) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-database-alert"></i></strong> The token tables are not installed yet — apply <code>migrations/v2.16_api_tokens.sql</code> first.</div>');
            redirect_to('api_tokens.php');
            die();
        }

        $name   = trim((string) ($_POST['token_name'] ?? ''));
        $scopes = array_values(array_filter((array) ($_POST['scopes'] ?? []), 'is_string'));
        $days   = (int) ($_POST['expires_in'] ?? ApiToken::DEFAULT_TTL_DAYS);

        if ($name === '') {
            $name = $isCouple ? 'Wedding assistant' : 'AI assistant';
        }
        if ($scopes === []) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Tick at least one thing the assistant may do — a token with no scopes can only ask who it is.</div>');
            redirect_to('api_tokens.php');
            die();
        }

        try {
            // The principal is the SESSION, never a posted id.
            $minted = ApiToken::mint($kind, $ownerId, $name, $scopes, null, $days, $isCouple ? null : $ownerId);
            $secret = (string) $minted['secret'];
            $session->message(
                '<div class="alert alert-success"><strong><i class="mdi mdi-check-decagram"></i> Your token is ready — copy it now.</strong>'
                . '<p class="mt-2 mb-2" style="font-size:13px;">This secret is stored only as a hash, so this is the <strong>one and only time</strong> it can be shown. '
                . 'If you lose it, revoke the token below and mint another.</p>'
                . '<div class="input-group mb-2"><input type="text" class="form-control mono" id="fresh-token" value="'
                . htmlspecialchars($secret, ENT_QUOTES) . '" readonly onclick="this.select()">'
                . '<div class="input-group-append"><button class="btn btn-dark" type="button" onclick="document.getElementById(\'fresh-token\').select();document.execCommand(\'copy\');">'
                . '<i class="mdi mdi-content-copy"></i> Copy</button></div></div>'
                . '<p class="mb-1" style="font-size:12.5px;"><strong>Point the assistant at</strong> <code class="mono">' . htmlspecialchars($endpoint, ENT_QUOTES) . '</code></p>'
                . '<pre class="mb-0" style="font-size:11.5px; white-space:pre-wrap;">' . htmlspecialchars($configSnippet($endpoint, $secret), ENT_QUOTES) . '</pre>'
                . '</div>'
            );
        } catch (Throwable $e) {
            // mint() refuses an unknown principal and an admin scope a non-operator
            // asked for; both are the user's to fix, so the message says which.
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> '
                . htmlspecialchars($e->getMessage()) . '</div>');
        }
        redirect_to('api_tokens.php');
        die();
    }

    // ---- Switch a token off (POST + CSRF) ----------------------------------
    if (isset($_POST['revoke_token'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
            redirect_to('api_tokens.php');
            die();
        }
        $id = (int) ($_POST['token_id'] ?? 0);
        $session->message(ApiToken::revoke($id, $kind, $ownerId)
            ? '<div class="alert alert-success"><strong><i class="mdi mdi-lock"></i></strong> Token revoked. Any assistant still holding it is refused from its next call on.</div>'
            : '<div class="alert alert-warning"><strong><i class="mdi mdi-alert"></i></strong> That token does not belong to this account, or it was already revoked.</div>');
        redirect_to('api_tokens.php');
        die();
    }

    // ---- What to render ---------------------------------------------------
    $tokens = $installed ? ApiToken::for_owner($kind, $ownerId) : [];
    $tokenIds = array_map(static fn($t): int => (int) $t->id, $tokens);
    $audit    = $installed ? ApiToken::recent_audit($tokenIds, 20) : [];
    $totals   = $installed ? ApiToken::audit_totals($tokenIds, 7) : ['calls' => 0, 'refusals' => 0];
    $liveCount = $installed ? ApiToken::live_count($kind, $ownerId) : 0;

    /*
     * Which scopes this account may hold at all: everything except the operator
     * surface, which only a super admin may carry (and mint() enforces that
     * again, so the hidden checkbox is a courtesy, not the rule).
     */
    $offerable = [];
    foreach (ApiToken::scope_keys() as $scope) {
        if (in_array($scope, ApiToken::ADMIN_SCOPES, true) && !is_super_admin()) {
            continue;
        }
        $offerable[] = $scope;
    }
?>
<?php $users_profile = is_staff() ? Users::find_by_id($ownerId) : false; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>AI Assistant Access - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body { margin-bottom: 2%; }
        .mono { font-family: ui-monospace, Menlo, Consolas, monospace; }
        .tok-card { border-radius: 10px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.08); padding: 16px 18px; margin-bottom: 14px; border-left: 4px solid #4C6EF5; }
        .tok-card.dead { border-left-color: var(--stone-300); background: #F8FAFC; }
        .pill { font-size: 11px; padding: 3px 10px; border-radius: 20px; }
        .pill-live { background: #E7F4EE; color: #065F46; }
        .pill-dead { background: #EEF2F7; color: #475569; }
        .pill-scope { background: #EEF2F7; color: #334155; font-family: ui-monospace, Menlo, Consolas, monospace; }
        .pill-read { background: #EEF2F7; color: #334155; }
        .pill-write { background: #FEF3C7; color: #92400E; }
        .pill-admin { background: #FEE2E2; color: #991B1B; }
        .scope-row { display: flex; gap: 8px; font-size: 12.5px; padding: 3px 0; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3" style="border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
        <h4 class="mb-0"><i class="mdi mdi-robot-outline"></i> AI Assistant Access</h4>
        <span class="badge <?= $mcpOn ? 'badge-success' : 'badge-secondary'; ?>" style="font-size:11px;">
            <i class="mdi mdi-<?= $mcpOn ? 'power-plug' : 'power-plug-off'; ?>"></i>
            Endpoint <?= $mcpOn ? 'ON' : 'OFF'; ?> (WPMS_MCP)
        </span>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <?php if (!$installed): ?>
        <div class="alert alert-warning">
            <strong><i class="mdi mdi-database-alert"></i> The token tables are not installed.</strong>
            Apply <code>migrations/v2.16_api_tokens.sql</code> (it creates <code>tblapi_tokens</code> and
            <code>tblapi_audit</code>). Until then the endpoint answers 503 and this page cannot mint anything —
            on purpose: an auth layer with no table to check a token against must refuse, not welcome.
        </div>
    <?php endif; ?>

    <div class="alert alert-info" role="alert" style="font-size:13px;">
        <strong><i class="mdi mdi-information-outline"></i> What this is.</strong>
        An assistant (Claude, ChatGPT, Cursor, an internal agent) can be pointed at this platform to read
        <strong>your own</strong> wedding data and summarise it. It acts as <strong>you<?= $isCouple ? '' : 'r account'; ?></strong>:
        it can open exactly the weddings you can open in the planner, and nothing else — a wedding id it invents,
        or that somebody talks it into asking for, is refused. Reads are always available; <strong>changing data</strong>
        is a separate switch on the server (<code>WPMS_MCP_WRITES=1</code>) and is
        <strong><?= $writesOn ? 'ON for this install' : 'OFF for this install'; ?></strong>.
        <br>
        <strong>Everything the assistant asks for is logged below</strong> — including what it was refused.
    </div>

    <div class="row">
        <div class="col-md-3"><div class="stat-card"><h6>Live tokens</h6><div class="value"><?= (int) $liveCount; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card"><h6>All tokens</h6><div class="value"><?= count($tokens); ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--stone-700)"><h6>Calls (7 days)</h6><div class="value mono"><?= (int) $totals['calls']; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--warning)"><h6>Refused (7 days)</h6><div class="value mono"><?= (int) $totals['refusals']; ?></div></div></div>
    </div>

    <!-- Mint -->
    <div class="tok-card" style="border-left-color:var(--success);">
        <h6 class="mb-3"><i class="mdi mdi-plus-circle-outline"></i> Give an assistant access</h6>
        <form method="post">
            <?= $session->csrf_field(); ?>
            <input type="hidden" name="mint_token" value="1">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label style="font-size:11.5px; color:#64748B; text-transform:uppercase; letter-spacing:.04em;">Name</label>
                    <input type="text" name="token_name" class="form-control form-control-sm" maxlength="<?= (int) ApiToken::MAX_NAME; ?>"
                           placeholder="<?= $isCouple ? 'Wedding assistant' : 'AI assistant'; ?>">
                    <small class="text-muted">Only ever shown to you — how you tell two tokens apart.</small>
                </div>
                <div class="form-group col-md-3">
                    <label style="font-size:11.5px; color:#64748B; text-transform:uppercase; letter-spacing:.04em;">Expires in</label>
                    <select name="expires_in" class="form-control form-control-sm">
                        <option value="30">30 days</option>
                        <option value="<?= (int) ApiToken::DEFAULT_TTL_DAYS; ?>" selected><?= (int) ApiToken::DEFAULT_TTL_DAYS; ?> days (default)</option>
                        <option value="365">1 year</option>
                        <option value="0">Never expires</option>
                    </select>
                    <small class="text-muted">A token that expires is a token nobody has to remember to revoke.</small>
                </div>
                <div class="form-group col-md-5">
                    <label style="font-size:11.5px; color:#64748B; text-transform:uppercase; letter-spacing:.04em;">Endpoint (read-only)</label>
                    <input type="text" class="form-control form-control-sm mono" value="<?= htmlspecialchars($endpoint, ENT_QUOTES); ?>" readonly onclick="this.select()">
                    <small class="text-muted">The URL you give the assistant, with the secret below.</small>
                </div>
            </div>

            <label style="font-size:11.5px; color:#64748B; text-transform:uppercase; letter-spacing:.04em;">What it may do</label>
            <div class="row">
                <?php
                // Two columns: the reads this account will expect the assistant to
                // summarise, and the writes (off on this install unless the switch
                // above says otherwise — the label says which).
                $chunks = array_chunk($offerable, (int) ceil(count($offerable) / 2));
                foreach ($chunks as $chunk):
                ?>
                <div class="col-md-6">
                    <?php foreach ($chunk as $scope): ?>
                        <?php
                            $isWrite = ApiToken::is_write_scope($scope);
                            $isAdmin = in_array($scope, ApiToken::ADMIN_SCOPES, true);
                            $default = !$isWrite; // reads on; writes and the operator surface are opt-in
                        ?>
                        <div class="scope-row">
                            <input type="checkbox" name="scopes[]" value="<?= htmlspecialchars($scope, ENT_QUOTES); ?>"
                                   id="scope-<?= htmlspecialchars(str_replace(':', '-', $scope), ENT_QUOTES); ?>" <?= $default ? 'checked' : ''; ?>>
                            <label for="scope-<?= htmlspecialchars(str_replace(':', '-', $scope), ENT_QUOTES); ?>" class="mb-0">
                                <span class="pill <?= $isAdmin ? 'pill-admin' : ($isWrite ? 'pill-write' : 'pill-read'); ?>"><?= htmlspecialchars($scope); ?></span>
                                <?= htmlspecialchars((string) ApiToken::SCOPES[$scope]); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-sm btn-success"><i class="mdi mdi-key-plus"></i> Create token</button>
                <span class="text-muted ml-2" style="font-size:12px;">
                    The secret is shown once. Scopes are a ceiling — the assistant still only reaches the weddings this account may open.
                </span>
            </div>
        </form>
    </div>

    <!-- Existing tokens -->
    <h5 class="mt-4 mb-3"><i class="mdi mdi-key-variant"></i> Your tokens</h5>
    <?php if ($tokens === []): ?>
        <div class="alert alert-light border" role="alert">
            No tokens yet. Nothing can reach the endpoint on your behalf until you create one above.
        </div>
    <?php else: ?>
        <?php foreach ($tokens as $t): ?>
            <?php
                $revoked = !empty($t->revoked_at);
                $expired = !empty($t->expired);
                $dead    = $revoked || $expired;
                $scopes  = array_values(array_filter(explode(',', (string) $t->scopes)));
            ?>
            <div class="tok-card <?= $dead ? 'dead' : ''; ?>">
                <div class="d-flex justify-content-between align-items-start flex-wrap">
                    <div>
                        <strong><?= htmlspecialchars((string) $t->name); ?></strong>
                        <span class="pill <?= $dead ? 'pill-dead' : 'pill-live'; ?> ml-1">
                            <?= $revoked ? 'Revoked' : ($expired ? 'Expired' : 'Live'); ?>
                        </span>
                        <span class="mono text-muted ml-1" style="font-size:11.5px;"><?= htmlspecialchars((string) $t->token_prefix); ?>…</span>
                        <?php if (!empty($t->booking_id)): ?>
                            <span class="pill pill-scope ml-1" title="Narrowed to one wedding">wedding #<?= (int) $t->booking_id; ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!$revoked): ?>
                    <form method="post" onsubmit="return confirm('Revoke this token? Any assistant still holding it is refused immediately.');">
                        <?= $session->csrf_field(); ?>
                        <input type="hidden" name="revoke_token" value="1">
                        <input type="hidden" name="token_id" value="<?= (int) $t->id; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size:11.5px;">
                            <i class="mdi mdi-lock"></i> Revoke
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <div class="mt-2">
                    <?php foreach ($scopes as $scope): ?>
                        <span class="pill <?= in_array($scope, ApiToken::ADMIN_SCOPES, true) ? 'pill-admin' : (ApiToken::is_write_scope($scope) ? 'pill-write' : 'pill-read'); ?> mr-1"><?= htmlspecialchars($scope); ?></span>
                    <?php endforeach; ?>
                    <?php if ($scopes === []): ?><span class="text-muted" style="font-size:12px;">no scopes — it can only ask who it is</span><?php endif; ?>
                </div>

                <div class="mt-2 text-muted" style="font-size:11.5px;">
                    <i class="mdi mdi-calendar-clock"></i> Created <?= htmlspecialchars(format_date_kd((string) $t->created_at, true)); ?>
                    · <?= empty($t->expires_at) ? 'never expires' : 'expires ' . htmlspecialchars(format_date_kd((string) $t->expires_at, true)); ?>
                    <?php if (!empty($t->last_used_at)): ?>
                        · last used <?= htmlspecialchars(format_date_kd((string) $t->last_used_at, true)); ?>
                        <span class="mono">(<?= htmlspecialchars((string) $t->last_used_ip); ?>)</span>
                    <?php else: ?>
                        · never used yet
                    <?php endif; ?>
                    <?php if ($revoked): ?>· revoked <?= htmlspecialchars(format_date_kd((string) $t->revoked_at, true)); ?><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- What the assistant did -->
    <?php if ($audit !== []): ?>
        <h5 class="mt-4 mb-3"><i class="mdi mdi-history"></i> What the assistant asked for
            <span class="text-muted" style="font-size:12px; font-weight:400;">— the last <?= count($audit); ?> call<?= count($audit) === 1 ? '' : 's'; ?>, newest first</span>
        </h5>
        <div class="card shadow-sm mb-4" style="border:none; border-radius:14px;">
            <div class="card-body">
                <div class="table-responsive">
                <table class="table table-hover" style="margin-bottom:0; font-size:12.5px;">
                    <thead>
                        <tr><th>When</th><th>Token</th><th>Tool</th><th>Scope</th><th>Result</th><th>Wedding</th><th>Arguments</th><th>ms</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($audit as $a): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?= htmlspecialchars(format_date_kd((string) $a->created_at, true)); ?></td>
                            <td><?= htmlspecialchars((string) ($a->token_name ?? '—')); ?></td>
                            <td class="mono"><?= htmlspecialchars((string) $a->tool); ?></td>
                            <td class="mono" style="font-size:11px;"><?= htmlspecialchars((string) $a->scope); ?></td>
                            <td>
                                <?php if ((int) $a->ok === 1): ?>
                                    <span class="badge badge-success">shown</span>
                                <?php else: ?>
                                    <span class="badge badge-danger" title="<?= htmlspecialchars((string) $a->error); ?>">refused</span>
                                    <span class="text-muted" style="font-size:11px;"><?= htmlspecialchars((string) $a->error); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= empty($a->booking_id) ? '' : '#' . (int) $a->booking_id; ?></td>
                            <td class="mono text-muted" style="font-size:11px;"><?= htmlspecialchars((string) $a->args_digest); ?></td>
                            <td class="mono"><?= (int) $a->ms; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="text-muted mb-0 mt-2" style="font-size:11.5px;">
                    Argument <em>names and sizes</em> are recorded, never their values — your guests' phone numbers and
                    notes are not copied into the log. Rows older than <?= (int) ApiToken::AUDIT_KEEP_DAYS; ?> days are pruned
                    by the scheduled maintenance job.
                </p>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include_once 'include/footer.php'; ?>
</body>
</html>
