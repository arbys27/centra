<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$script_path = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? ''));
$is_students_area = strpos($script_path, '/modules/students/') !== false;
$is_officers_area = strpos($script_path, '/modules/officers/') !== false ||
    strpos($script_path, '/modules/attendance/officers.php') !== false;
if (!$is_students_area) {
    unset($_SESSION['pin_unlocked_students']);
}
if (!$is_officers_area) {
    unset($_SESSION['pin_unlocked_officers']);
}

if (!isset($_SESSION['user_id'])) {
    header("Location: " . (strpos($_SERVER['PHP_SELF'], '/modules/') !== false ? str_repeat('../', 2) : '') . "login.php");
    exit;
}
