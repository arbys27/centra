<?php
// Copy this file to config/db.php and replace the placeholder values locally.
date_default_timezone_set('Asia/Manila');

$db_config = [
    'host' => 'localhost',
    'port' => '3306',
    'database' => 'your_database_name',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
    'timezone' => '+08:00',
];

$DB_HOST = $db_config['host'];
$DB_USER = $db_config['username'];
$DB_PASS = $db_config['password'];
$DB_NAME = $db_config['database'];
$DB_PORT = (int) $db_config['port'];

$conn = mysqli_init();
if (!$conn->real_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT)) {
    die(sprintf(
        'Database connection failed: %s<br>Configure config/db.php with your local MySQL settings.',
        htmlspecialchars($conn->connect_error ?? 'Unknown error', ENT_QUOTES, 'UTF-8')
    ));
}

$conn->set_charset($db_config['charset']);
if (!$conn->query("SET time_zone = '" . $db_config['timezone'] . "'")) {
    die('Could not set the MySQL session time zone.');
}
