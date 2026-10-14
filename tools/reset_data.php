<?php
$runtime = realpath(__DIR__ . '/..');
if ($runtime === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(1);
}

require_once $runtime . '/config/db.php';

if (!isset($_SERVER['argv']) || !is_array($_SERVER['argv'])) {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$keepInventory = false;
foreach (array_slice($_SERVER['argv'], 1) as $arg) {
    if ($arg === '--keep-inventory') {
        $keepInventory = true;
    }
}

fwrite(STDOUT, "WARNING: This will delete the application data and reset the database.\n");
if (!$keepInventory) {
    fwrite(STDOUT, "It will remove students, attendance logs, officer records, borrowing history, printan records, blacklist history, imports, admin accounts, and all upload images.\n");
} else {
    fwrite(STDOUT, "It will keep inventory items and images, restore stock to full availability, and clear borrow/return history.\n");
}

fwrite(STDOUT, "Type RESET to continue: ");
$input = trim((string) fgets(STDIN));
if ($input !== 'RESET') {
    fwrite(STDOUT, "Cancelled.\n");
    exit(0);
}

try {
    $pdo = new PDO('mysql:host=' . $DB_HOST . ';dbname=' . $DB_NAME . ';charset=utf8mb4', $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+08:00'");

    $tables = [
        'student_blacklist',
        'attendance_records',
        'officer_events',
        'officers',
        'students',
        'printan_records',
        'iskolend_borrow',
        'import_log',
        'users',
        'ecopuntos_records',
        'ecopuntos_balance',
    ];

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $table) {
        $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '``', $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    if ($keepInventory) {
        $pdo->exec("UPDATE iskolend_items SET available_qty = total_qty");
        $pdo->exec("DELETE FROM iskolend_borrow");
    } else {
        $pdo->exec('DELETE FROM iskolend_items');
        $pdo->exec('ALTER TABLE iskolend_items AUTO_INCREMENT = 1');
    }

    $uploadDir = $runtime . '/uploads';
    if (is_dir($uploadDir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $fileInfo) {
            $path = $fileInfo->getPathname();
            if ($fileInfo->isDir()) {
                @rmdir($path);
            } else {
                $name = $fileInfo->getFilename();
                if ($name !== '.htaccess' && $path !== $runtime . '/uploads/items/.htaccess') {
                    @unlink($path);
                }
            }
        }
    }

    $logFiles = [
        $runtime . '/logs/error.log',
        $runtime . '/logs/php_errors.log',
        $runtime . '/logs/app.log',
    ];
    foreach ($logFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    fwrite(STDOUT, "Reset completed successfully.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Reset failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
