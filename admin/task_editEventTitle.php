<?php
require_once __DIR__ . '/include/init.php';
require_staff();

// POST-only + CSRF — previously raw POST values were interpolated into SQL.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$session->verify_csrf()) {
    http_response_code(403);
    die('Forbidden');
}

global $db;
$bdd = $db->pdo();

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('Bad request');
}

if (isset($_POST['delete'])) {
    $sql = "DELETE FROM events WHERE id = :id";
    $query = $bdd->prepare($sql);
    if ($query === false) {
        http_response_code(500);
        die('Erreur prepare');
    }
    $res = $query->execute([':id' => $id]);
    if ($res === false) {
        http_response_code(500);
        die('Erreur execute');
    }
} elseif (isset($_POST['title']) && isset($_POST['color'])) {
    $title    = clean((string) ($_POST['title'] ?? ''));
    $color    = clean((string) ($_POST['color'] ?? ''));
    $location = clean((string) ($_POST['location'] ?? ''));

    $sql = "UPDATE events SET title = :title, location = :location, color = :color WHERE id = :id";
    $query = $bdd->prepare($sql);
    if ($query === false) {
        http_response_code(500);
        die('Erreur prepare');
    }
    $sth = $query->execute([':title' => $title, ':location' => $location, ':color' => $color, ':id' => $id]);
    if ($sth === false) {
        http_response_code(500);
        die('Erreur execute');
    }
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'task_all_calendar.php'));
exit;
