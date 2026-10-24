<?php include 'include/init.php'; if (staff_paywall_locked()) { redirect_to('subscription.php'); exit; } ?>
<?php
    // Any signed-in user: planners and vendors get staff alerts, couples get
    // their own (booking confirmed/cancelled, vendor enquiry updates). The feed
    // is scoped to the session's id AND to the account table that id belongs to
    // — staff live in tblusers (`id`) and couples in tblaccounts (`user_id`) on
    // separate auto-increment sequences, so the number alone does not identify
    // the account it belongs to (notification_recipient_for_session).
    if (!isset($_SESSION['id'])) {
        redirect_to("../");
        die();
    }
    $plannerId = (int) $_SESSION['id'];
    [$recipientType, $accountId] = notification_recipient_for_session();
    $action = (string) ($_GET['action'] ?? '');

    // Action endpoints (kept GET for deep links from the sidebar dropdown).
    if ($action === 'read' && isset($_GET['id'])) {
        // Mark one notification read, then follow its deep link. Both steps name
        // the account table: a collapsed id can only ever read its own row, and
        // the link comes back only when the row belongs to this account.
        $id = (int)($_GET['id'] ?? 0);
        $link = Notification::link_for($recipientType, $id, $accountId);
        Notification::mark_read($recipientType, $id, $accountId);
        redirect_to($link !== '' ? $link : (is_staff() ? 'dashboard.php' : 'client.php'));
        die();
    }

    if ($action === 'readall') {
        Notification::mark_all_read($recipientType, $accountId);
        // Origin-safe return: path (+query) only, never a foreign host.
        $back = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $path = parse_url($back, PHP_URL_PATH) ?: (is_staff() ? 'dashboard.php' : 'client.php');
        redirect_to($path);
        die();
    }

    // No action = the full archive page: paginated, newest first.
    // NOTE: $notifs is deliberately avoided here — include/sidebar.php reuses
    // that name for its 8-item dropdown and would clobber this page's list
    // (includes share scope).
    $perPage  = 25;
    $qRaw     = trim((string) ($_GET['q'] ?? ''));
    $q        = mb_substr($qRaw, 0, 100);
    $unreadOnly = (($_GET['filter'] ?? '') === 'unread');
    $total    = Notification::count_for($recipientType, $accountId, $unreadOnly, $q);
    $pages    = (int) ceil($total / $perPage);
    $pageNo   = min(max((int) ($_GET['page'] ?? 1), 1), max($pages, 1));
    $offset   = ($pageNo - 1) * $perPage;
    $archiveRows = Notification::page($recipientType, $accountId, $offset, $perPage, $unreadOnly, $q);
    $unread   = Notification::unread_count($recipientType, $accountId);
    // Couples land here from their own bell; staff deep-link from the sidebar.
    $isStaffView = is_staff();
    $homeLink    = $isStaffView ? 'dashboard.php' : 'client.php';
    // Filter-preserving pagination links (page is swapped per link).
    $qs = static function (int $p) use ($unreadOnly, $q): string {
        $params = array_filter([
            'page'   => $p > 1 ? $p : null,
            'filter' => $unreadOnly ? 'unread' : null,
            'q'      => $q !== '' ? $q : null,
        ], static fn($v) => $v !== null);
        return 'notifications.php' . ($params === [] ? '' : '?' . http_build_query($params));
    };
?>
<?php $users_profile = Users::find_by_id($plannerId); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Notifications - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body { margin-bottom: 2%; }
        .notif-item { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-bottom: 1px solid #eef2f7; text-decoration: none; color: #1f2937; }
        .notif-item:hover { background: #f8fafc; text-decoration: none; color: #1f2937; }
        .notif-item:last-child { border-bottom: none; }
        .notif-item.unread { background: #f0fdf4; }
        .notif-item.unread:hover { background: #ecfdf5; }
        .notif-dot { width: 8px; height: 8px; border-radius: 50%; background: #059669; flex: 0 0 auto; }
        .notif-item.read .notif-dot { background: #d1d5db; }
        .notif-body { flex: 1 1 auto; min-width: 0; }
        .notif-body .msg { font-size: 14px; line-height: 1.4; }
        .notif-item.unread .msg { font-weight: 600; }
        .notif-body .meta { font-size: 12px; color: #6b7280; margin-top: 2px; }
        .notif-badge { flex: 0 0 auto; font-size: 10px; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4" style="border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
        <h4><i class="mdi mdi-bell-outline"></i> Notifications
            <?php if ($total > 0): ?>
                <span class="badge badge-secondary align-middle" style="font-size: 12px;"><?= (int) $total; ?></span>
            <?php endif; ?>
        </h4>
        <?php if ($unread > 0): ?>
            <a href="notifications.php?action=readall" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-check-all"></i> Mark all read</a>
        <?php else: ?>
            <a href="<?= $homeLink; ?>" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-arrow-left"></i> Back</a>
        <?php endif; ?>
    </div>

    <!-- Filter bar: unread-only toggle + message search -->
    <form method="get" action="notifications.php" class="form-inline d-flex flex-wrap align-items-center mb-3" style="gap: 8px;">
        <div class="custom-control custom-switch mr-2">
            <input type="checkbox" class="custom-control-input" id="filterUnread" name="filter"
                   value="unread" <?= $unreadOnly ? 'checked' : ''; ?> onchange="this.form.submit()">
            <label class="custom-control-label" for="filterUnread" style="font-size: 13px;">
                Unread only
                <?php if ($unread > 0): ?><span class="badge badge-success ml-1" style="font-size: 10px;"><?= (int) $unread; ?></span><?php endif; ?>
            </label>
        </div>
        <input type="search" class="form-control form-control-sm" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES); ?>"
               placeholder="Search messages…" style="max-width: 240px; flex: 1 1 160px;">
        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-magnify"></i> Search</button>
        <?php if ($unreadOnly || $q !== ''): ?>
            <a href="notifications.php" class="btn btn-sm btn-link text-muted" style="font-size: 12px;"><i class="mdi mdi-close"></i> Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <div class="card shadow-sm" style="border: none; border-radius: 14px;">
        <?php if (empty($archiveRows)): ?>
            <div class="text-center py-5 text-muted">
                <i class="mdi mdi-<?= ($unreadOnly || $q !== '') ? 'magnify' : 'bell-sleep'; ?>" style="font-size: 40px;"></i>
                <div class="mt-2" style="font-size: 14px;"><?= ($unreadOnly || $q !== '')
                    ? 'Nothing matches this filter — try clearing the search or the unread toggle.'
                    : ($isStaffView
                        ? 'No notifications yet — deposits from your couples will land here.'
                        : 'No notifications yet — updates about your wedding will land here.'); ?></div>
            </div>
        <?php else: ?>
            <div class="card-body p-0">
                <?php foreach ($archiveRows as $notif): ?>
                    <a class="notif-item <?= ((int) $notif->is_read === 0) ? 'unread' : 'read'; ?>"
                       href="notifications.php?action=read&id=<?= (int) $notif->id; ?>">
                        <span class="notif-dot"></span>
                        <div class="notif-body">
                            <div class="msg"><?= htmlspecialchars((string) $notif->message); ?></div>
                            <div class="meta"><?= htmlspecialchars(format_date_kd((string) $notif->created_at, true)); ?></div>
                        </div>
                        <?php if ((int) $notif->is_read === 0): ?>
                            <span class="badge badge-success notif-badge">New</span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($pages > 1): ?>
                <div class="card-footer d-flex justify-content-between align-items-center" style="border-top: 1px solid #eef2f7; border-radius: 0 0 14px 14px;">
                    <span class="text-muted" style="font-size: 12px;">Page <?= $pageNo; ?> of <?= $pages; ?> (<?= $total; ?> total)</span>
                    <div>
                        <?php if ($pageNo > 1): ?>
                            <a href="<?= $qs($pageNo - 1); ?>" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-chevron-left"></i> Newer</a>
                        <?php endif; ?>
                        <?php if ($pageNo < $pages): ?>
                            <a href="<?= $qs($pageNo + 1); ?>" class="btn btn-sm btn-outline-secondary">Older <i class="mdi mdi-chevron-right"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script src="js/jquery-3.7.1.min.js"></script>
<script src="js/bootstrap.min.js?v=4.6.2"></script>
</body>
</html>