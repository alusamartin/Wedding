<?php include 'include/init.php'; ?>
<?php
    if (!is_staff()) {
        redirect_to("../");
        die();
    }
    $plannerId = (int) $_SESSION['id'];
    $action = (string) ($_GET['action'] ?? '');

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

    redirect_to('dashboard.php');
    die();