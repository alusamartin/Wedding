<?php
require_once __DIR__ . '/include/init.php';
require_staff();

// Drag-and-drop resize/date change from the calendar. POST-only, CSRF-checked.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$session->verify_csrf()) {
    http_response_code(403);
    die('Forbidden');
}

if (isset($_POST['Event'][0]) && isset($_POST['Event'][1]) && isset($_POST['Event'][2])){

    global $db;
    $bdd = $db->pdo();

    $id    = (int) $_POST['Event'][0];
    $start = trim((string) $_POST['Event'][1]);
    $end   = trim((string) $_POST['Event'][2]);

    if ($id <= 0 || $start === '' || $end === '') {
        http_response_code(400);
        die('Bad request');
    }

    // PDO bound values — previously $start/$end/$id were interpolated raw (SQLi)
    $sql = "UPDATE events SET start = :start, end = :end WHERE id = :id";
    $query = $bdd->prepare($sql);
    $sth = $query->execute([':start' => $start, ':end' => $end, ':id' => $id]);

    if ($sth === false) {
        http_response_code(500);
        die('error execute');
    }
    die('OK');
}
http_response_code(400);
die('Bad request');
