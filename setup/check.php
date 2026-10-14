<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/db.php';

session_start();

$checks = [];

function add_check(string $label, bool $passed, string $fix = ''): void
{
    global $checks;
    $checks[] = ['label' => $label, 'passed' => $passed, 'fix' => $fix];
}

$php_ok = version_compare(PHP_VERSION, '8.1.0', '>=');
add_check('PHP 8.1 or newer', $php_ok, $php_ok ? '' : 'Upgrade PHP to 8.1 or newer and restart Apache.');

foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'iconv'] as $extension) {
    $present = extension_loaded($extension);
    add_check('PHP extension: ' . $extension, $present, $present ? '' : 'Enable the ' . $extension . ' extension in php.ini and restart Apache.');
}

$gd_available = extension_loaded('gd');
add_check('PHP GD extension (if image processing is used)', $gd_available || !in_array('gd', get_loaded_extensions(), true), $gd_available ? '' : 'Install the GD extension if you use image resizing or validation on the server.');

$pdo_ok = false;
try {
    $pdo = get_pdo_connection();
    $pdo_ok = true;
    add_check('Database connection', true, '');
} catch (Throwable $exception) {
    add_check('Database connection', false, 'Update config/db.php with the correct MySQL host, database name, username, and password and ensure MySQL is running.');
}

if ($pdo_ok) {
    $required_tables = [
        'students', 'officers', 'officer_events', 'users', 'app_settings',
        'student_blacklist', 'import_log', 'printan_records', 'iskolend_items',
        'item_categories', 'iskolend_borrow', 'attendance_records'
    ];
    foreach ($required_tables as $table) {
        $exists = false;
        try {
            $exists = (bool) $pdo->query('SELECT 1 FROM `' . str_replace('`', '``', $table) . '` LIMIT 1')->fetchColumn();
        } catch (Throwable $exception) {
            $exists = false;
        }
        add_check('Table present: ' . $table, $exists, $exists ? '' : 'Create or import the missing table in database/install.sql or the project schema.');
    }
}

foreach (['uploads/items', 'logs'] as $folder) {
    $path = __DIR__ . '/../' . $folder;
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    $folder_ok = is_dir($path) && is_writable($path);
    add_check('Writable directory: ' . $folder, $folder_ok, $folder_ok ? '' : 'Create the folder and grant write permissions to the web server user, e.g. chmod 775 on ' . $folder . '.');
}

$timezone_ok = date_default_timezone_get() === 'Asia/Manila';
add_check('Timezone set to Asia/Manila', $timezone_ok, $timezone_ok ? '' : 'Set date_default_timezone_set("Asia/Manila"); in config/db.php or a shared bootstrap file.');

$upload_limit = (int) ini_get('upload_max_filesize');
$post_limit = (int) ini_get('post_max_size');
$limit_ok = $upload_limit >= 8 && $post_limit >= 8;
add_check('upload_max_filesize and post_max_size at least 8M', $limit_ok, $limit_ok ? '' : 'Increase upload_max_filesize and post_max_size to at least 8M in php.ini and restart the web server.');

$status_ok = true;
foreach ($checks as $check) {
    if (!$check['passed']) {
        $status_ok = false;
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System Check</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; background: #f7f7f7; }
        .wrap { max-width: 900px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { padding: 0.7rem 0.8rem; border-bottom: 1px solid #e4e4e4; text-align: left; }
        .pass { color: #0a7d3d; font-weight: bold; }
        .fail { color: #ad1e1e; font-weight: bold; }
        .summary { margin-bottom: 1rem; font-size: 1.2rem; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>CSC System Check</h1>
        <div class="summary">
            <?php if ($status_ok): ?>
                <span class="pass">PASS</span>
            <?php else: ?>
                <span class="fail">FAIL</span>
            <?php endif; ?>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Check</th>
                    <th>Status</th>
                    <th>Fix</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checks as $check): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($check['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="<?php echo $check['passed'] ? 'pass' : 'fail'; ?>"><?php echo $check['passed'] ? 'PASS' : 'FAIL'; ?></td>
                        <td><?php echo htmlspecialchars($check['fix'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
