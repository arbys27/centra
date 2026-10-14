<?php
// Copy this file to config/db.php and adjust values for your local machine.
// The app is designed to run from any folder under htdocs or a local web root.
date_default_timezone_set('Asia/Manila');

$db_config = [
    'host' => getenv('CSC_DB_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('CSC_DB_PORT') ?: getenv('DB_PORT') ?: '3306',
    'database' => getenv('CSC_DB_NAME') ?: getenv('DB_NAME') ?: 'csc_system',
    'username' => getenv('CSC_DB_USER') ?: getenv('DB_USER') ?: 'root',
    'password' => getenv('CSC_DB_PASS') ?: getenv('DB_PASS') ?: '',
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
    die('Database connection failed. Update config/db.php with your local MySQL credentials and ensure the database exists.');
}

$conn->set_charset($db_config['charset']);
$conn->query("SET time_zone = '" . $db_config['timezone'] . "'");
