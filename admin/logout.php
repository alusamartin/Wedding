<?php
/**
 * Created by PhpStorm.
 * User: ACLC
 * Date: 2/20/2018
 * Time: 3:36 AM
 */
session_start();
// Couples sign out to the public login; staff stay on the admin login.
$is_client = isset($_SESSION['privilege_level']) && (string) $_SESSION['privilege_level'] === '0';
session_unset();
session_destroy();
header("Location: " . ($is_client ? '../login.php' : 'login.php'));
