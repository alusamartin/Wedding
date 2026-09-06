<?php include 'include/init.php'; ?>
<?php
    // Staff-only: the notification feed belongs to planners, not couples.
    if (!is_staff()) {
        redirect_to("../");
        die();
    }
    $plannerId = (int) $_SESSION['id'];
    $action = (string) ($_GET['action'] ?? '');

    // Action endpoints (kept GET for deep links from the sidebar dropdown).
    if ($action === 'read' && isset($_GET['id'])) {
        // Mark one notification read, then follow its deep link.
        $id = (int) $_GET['id'];
        global $db;
        $stmt = $db->prepare(
            'SELECT link FROM tblnotifications WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':uid' => $plannerId]);
        $link = (string) ($stmt->fetchColumn() ?: '');
        Notification::mark_read($id, $plannerId);
        redirect_to($link !== '' ? $link : 'dashboard.php');
        die();
    }

    if ($action === 'readall') {
        Notification::mark_all_read($plannerId);
        // Origin-safe return: path (+query) only, never a foreign host.
        $back = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $path = parse_url($back, PHP_URL_PATH) ?: 'dashboard.php';
        redirect_to($path);
        die();
    }

    // No action = the full archive page: paginated, newest first.
    // NOTE: $notifs is deliberately avoided here — include/sidebar.php reuses
    // that name for its 8-item dropdown and would clobber this page's list
    // (includes share scope).
    $perPage     = 25;
    $total       = Notification::count_for($plannerId);
    $pages       = (int) ceil($total / $perPage);
    $pageNo      = min(max((int) ($_GET['page'] ?? 1), 1), max($pages, 1));
    $offset      = ($pageNo - 1) * $perPage;
    $archiveRows = Notification::page($plannerId, $offset, $perPage);
    $unread      = Notification::unread_count($plannerId);
?>
<?php $users_profile = Users::find_by_id($plannerId); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Notifications - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
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
            <a href="dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-arrow-left"></i> Back to Dashboard</a>
        <?php endif; ?>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <div class="card shadow-sm" style="border: none; border-radius: 14px;">
        <?php if (empty($archiveRows)):
?>
            <div class="text-center py-5 text-muted">
                <i class="mdi mdi-bell-sleep" style="font-size: 40px;"></i>
                <div class="mt-2" style="font-size: 14px;">No notifications yet — deposits from your couples will land here.</div>
            </div>
        <?php else: ?>
            <div class="card-body p-0">
                <?php foreach ($archiveRows as $notif): ?>
                    <a class="notif-item <?= ((int) $notif->is_read === 0) ? 'unread' : 'read'; ?>"
                       href="notifications.php?action=read&id=<?= (int) $notif->id; ?>">
                        <span class="notif-dot"></span>
                        <div class="notif-body">
                            <div class="msg"><?= htmlspecialchars((string) $notif->message); ?></div>
                            <div class="meta"><?= htmlspecialchars((string) $notif->created_at); ?></div>
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
                            <a href="notifications.php?page=<?= $pageNo - 1; ?>" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-chevron-left"></i> Newer</a>
                        <?php endif; ?>
                        <?php if ($pageNo < $pages): ?>
                            <a href="notifications.php?page=<?= $pageNo + 1; ?>" class="btn btn-sm btn-outline-secondary">Older <i class="mdi mdi-chevron-right"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script src="js/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>