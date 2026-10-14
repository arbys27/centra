<?php
require_once __DIR__ . '/db.php';

function pin_setting(PDO $pdo, string $key): ?string
{
    $statement = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $statement->execute([$key]);
    $value = $statement->fetchColumn();
    return $value === false ? null : (string)$value;
}

function write_pin_setting(PDO $pdo, string $key, string $value): void
{
    $statement = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $statement->execute([$key, $value]);
}

function verify_pin(string $pin): bool
{
    $pdo = get_pdo_connection();
    $locked_until = (int)(pin_setting($pdo, 'pin_locked_until') ?? '0');
    if ($locked_until > time()) {
        return false;
    }
    if ($locked_until !== 0) {
        write_pin_setting($pdo, 'pin_locked_until', '0');
        write_pin_setting($pdo, 'pin_failed_attempts', '0');
    }

    $hash = pin_setting($pdo, 'pin_hash');
    if ($hash !== null && preg_match('/^\d{6}$/', $pin) === 1 && password_verify($pin, $hash)) {
        write_pin_setting($pdo, 'pin_failed_attempts', '0');
        return true;
    }

    $attempts = (int)(pin_setting($pdo, 'pin_failed_attempts') ?? '0') + 1;
    if ($attempts >= 5) {
        write_pin_setting($pdo, 'pin_failed_attempts', '0');
        write_pin_setting($pdo, 'pin_locked_until', (string)(time() + 300));
    } else {
        write_pin_setting($pdo, 'pin_failed_attempts', (string)$attempts);
    }
    return false;
}

function set_pin(string $pin): void
{
    if (preg_match('/^\d{6}$/', $pin) !== 1) {
        throw new InvalidArgumentException('PIN must contain exactly six digits.');
    }
    write_pin_setting(get_pdo_connection(), 'pin_hash', password_hash($pin, PASSWORD_DEFAULT));
}

function require_pin(string $area): void
{
    if (!in_array($area, ['students', 'officers'], true)) {
        throw new InvalidArgumentException('Unsupported PIN area.');
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $key = 'pin_unlocked_' . $area;
    $last_activity = (int)($_SESSION[$key] ?? 0);
    if ($last_activity > 0 && $last_activity + 600 >= time()) {
        $_SESSION[$key] = time();
        return;
    }
    unset($_SESSION[$key]);

    $prefix = strpos($_SERVER['PHP_SELF'] ?? '', '/modules/') !== false ? '../../' : '';
    $return_to = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header('Location: ' . $prefix . 'pin.php?area=' . $area . '&return=' . $return_to);
    exit;
}
