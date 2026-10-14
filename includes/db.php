<?php
require_once __DIR__ . '/../config/db.php';

function get_pdo_connection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '+08:00'");
    return $pdo;
}

function normalize_student_number(string $student_number): string
{
    return strtoupper(trim($student_number));
}

function is_valid_student_number(string $student_number): bool
{
    return preg_match('/^\d{4}-\d{5}-BN-\d$/', normalize_student_number($student_number)) === 1;
}

function get_active_blacklist(PDO $pdo, string $student_number): ?array
{
    $statement = $pdo->prepare(
        'SELECT blacklist_id, student_number, reason, offense_type, blacklisted_by, blacklisted_at
         FROM student_blacklist
         WHERE student_number = ? AND is_active = 1
         ORDER BY blacklisted_at DESC, blacklist_id DESC
         LIMIT 1'
    );
    $statement->execute([normalize_student_number($student_number)]);
    $entry = $statement->fetch();
    return $entry === false ? null : $entry;
}

function ensure_shared_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS import_log (
            import_id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            imported_by VARCHAR(100) NOT NULL,
            imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            total_rows INT NOT NULL DEFAULT 0,
            added INT NOT NULL DEFAULT 0,
            updated INT NOT NULL DEFAULT 0,
            skipped INT NOT NULL DEFAULT 0,
            rejected INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $student_columns = $pdo->query('SHOW COLUMNS FROM students')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('section', $student_columns, true)) {
        $pdo->exec("ALTER TABLE students ADD COLUMN section VARCHAR(20) NOT NULL DEFAULT '' AFTER year_level");
    }
    if (!in_array('contact_number', $student_columns, true)) {
        $pdo->exec("ALTER TABLE students ADD COLUMN contact_number VARCHAR(30) NOT NULL DEFAULT '' AFTER section");
    }

    ensure_officer_schema($pdo);
    ensure_officer_events_schema($pdo);
    ensure_printan_schema($pdo);
    ensure_iskolend_schema($pdo);

    $blacklist_exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'student_blacklist'"
    )->fetchColumn();

    if ((int)$blacklist_exists === 0) {
        $pdo->exec(
            "CREATE TABLE student_blacklist (
                blacklist_id INT AUTO_INCREMENT PRIMARY KEY,
                student_number VARCHAR(30) NOT NULL,
                reason TEXT NOT NULL,
                offense_type ENUM('unpaid_fee','item_damage_or_loss','overdue_items','misconduct','other') NOT NULL,
                blacklisted_by VARCHAR(100) NULL,
                blacklisted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                lifted_at DATETIME NULL,
                lifted_by VARCHAR(100) NULL,
                lift_note VARCHAR(255) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                INDEX idx_student_blacklist_active (student_number, is_active, blacklisted_at),
                CONSTRAINT fk_student_blacklist_student
                    FOREIGN KEY (student_number) REFERENCES students(student_no)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        return;
    }

    $blacklist_columns = $pdo->query('SHOW COLUMNS FROM student_blacklist')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('student_no', $blacklist_columns, true) && !in_array('blacklist_id', $blacklist_columns, true)) {
        $legacy_foreign_keys = $pdo->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'student_blacklist'
               AND COLUMN_NAME = 'student_no'
               AND REFERENCED_TABLE_NAME IS NOT NULL"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($legacy_foreign_keys as $constraint_name) {
            $quoted_name = str_replace('`', '``', $constraint_name);
            $pdo->exec("ALTER TABLE student_blacklist DROP FOREIGN KEY `{$quoted_name}`");
        }

        try {
            $pdo->exec(
                "ALTER TABLE student_blacklist
                 ADD COLUMN blacklist_id INT NOT NULL AUTO_INCREMENT UNIQUE FIRST,
                 CHANGE COLUMN student_no student_number VARCHAR(30) NOT NULL,
                 MODIFY COLUMN reason TEXT NOT NULL,
                 ADD COLUMN offense_type ENUM('unpaid_fee','item_damage_or_loss','overdue_items','misconduct','other') NOT NULL DEFAULT 'other',
                 ADD COLUMN blacklisted_by VARCHAR(100) NULL,
                 ADD COLUMN blacklisted_at DATETIME NULL,
                 ADD COLUMN lifted_at DATETIME NULL,
                 ADD COLUMN lifted_by VARCHAR(100) NULL,
                 ADD COLUMN lift_note VARCHAR(255) NULL,
                 ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1"
            );
            $pdo->exec(
                'UPDATE student_blacklist
                 SET blacklisted_at = COALESCE(created_at, CURRENT_TIMESTAMP),
                     is_active = active'
            );
            $pdo->exec(
                'ALTER TABLE student_blacklist
                 MODIFY COLUMN blacklisted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                 DROP PRIMARY KEY,
                 ADD PRIMARY KEY (blacklist_id),
                 DROP COLUMN active,
                 DROP COLUMN created_at,
                 ADD INDEX idx_student_blacklist_active (student_number, is_active, blacklisted_at),
                 ADD CONSTRAINT fk_student_blacklist_student
                    FOREIGN KEY (student_number) REFERENCES students(student_no)'
            );
        } catch (Throwable $exception) {
            if ($legacy_foreign_keys) {
                $columns_after_failure = $pdo->query('SHOW COLUMNS FROM student_blacklist')->fetchAll(PDO::FETCH_COLUMN);
                $foreign_key_column = in_array('student_number', $columns_after_failure, true)
                    ? 'student_number'
                    : (in_array('student_no', $columns_after_failure, true) ? 'student_no' : null);
                if ($foreign_key_column !== null) {
                    try {
                        $pdo->exec(
                            "ALTER TABLE student_blacklist
                             ADD CONSTRAINT fk_student_blacklist_student
                             FOREIGN KEY (`{$foreign_key_column}`) REFERENCES students(student_no)"
                        );
                    } catch (Throwable $restore_exception) {
                        error_log('Could not restore student blacklist foreign key: ' . $restore_exception->getMessage());
                    }
                }
            }
            throw $exception;
        }
        return;
    }

    if (!in_array('student_number', $blacklist_columns, true) || !in_array('is_active', $blacklist_columns, true)) {
        throw new RuntimeException('The student_blacklist table has an unsupported schema.');
    }
}

function ensure_iskolend_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS item_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(100) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    foreach ([
        'Technology Equipment',
        'Academic Equipment/Materials',
        'Sports Gear/Equipment',
        'Cleaning Materials',
    ] as $category_name) {
        $statement = $pdo->prepare('INSERT IGNORE INTO item_categories (category_name) VALUES (?)');
        $statement->execute([$category_name]);
    }

    $item_columns = $pdo->query('SHOW COLUMNS FROM iskolend_items')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('category_id', $item_columns, true)) {
        $pdo->exec('ALTER TABLE iskolend_items ADD COLUMN category_id INT NULL AFTER item_name');
    }
    if (!in_array('image_path', $item_columns, true)) {
        $pdo->exec('ALTER TABLE iskolend_items ADD COLUMN image_path VARCHAR(255) NULL AFTER category_id');
        $item_columns[] = 'image_path';
    }
    if (!in_array('description', $item_columns, true)) {
        $pdo->exec('ALTER TABLE iskolend_items ADD COLUMN description TEXT NULL AFTER image_path');
        $item_columns[] = 'description';
    }
    if (!in_array('borrow_days', $item_columns, true)) {
        $pdo->exec('ALTER TABLE iskolend_items ADD COLUMN borrow_days INT NOT NULL DEFAULT 3 AFTER available_qty');
    }

    $pdo->exec(
        "UPDATE iskolend_items i
         JOIN item_categories c ON c.category_name = CASE
           WHEN LOWER(i.item_name) LIKE '%basketball%' OR LOWER(i.item_name) LIKE '%volleyball%'
             OR LOWER(i.item_name) LIKE '%badminton%' OR LOWER(i.item_name) LIKE '%table tennis%'
             OR LOWER(i.item_name) LIKE '%chess%' OR LOWER(i.item_name) LIKE '%sports%'
             THEN 'Sports Gear/Equipment'
           WHEN LOWER(i.item_name) LIKE '%clean%' OR LOWER(i.item_name) LIKE '%mop%'
             OR LOWER(i.item_name) LIKE '%broom%' OR LOWER(i.item_name) LIKE '%waste%'
             THEN 'Cleaning Materials'
           WHEN LOWER(i.item_name) LIKE '%mic%' OR LOWER(i.item_name) LIKE '%speaker%'
             OR LOWER(i.item_name) LIKE '%hdmi%' OR LOWER(i.item_name) LIKE '%projector%'
             OR LOWER(i.item_name) LIKE '%computer%' OR LOWER(i.item_name) LIKE '%laptop%'
             OR LOWER(i.item_name) LIKE '%technology%'
             THEN 'Technology Equipment'
           ELSE 'Academic Equipment/Materials'
         END
         SET i.category_id = COALESCE(i.category_id, c.id)"
    );

    $borrow_columns = $pdo->query('SHOW COLUMNS FROM iskolend_borrow')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('quantity', $borrow_columns, true)) {
        $pdo->exec('ALTER TABLE iskolend_borrow ADD COLUMN quantity INT NOT NULL DEFAULT 1 AFTER qty_borrowed');
        $pdo->exec('UPDATE iskolend_borrow SET quantity = qty_borrowed');
    }
    if (!in_array('due_date', $borrow_columns, true)) {
        $pdo->exec('ALTER TABLE iskolend_borrow ADD COLUMN due_date DATE NULL AFTER date_borrowed');
    }
    if (!in_array('officer', $borrow_columns, true)) {
        $pdo->exec('ALTER TABLE iskolend_borrow ADD COLUMN officer VARCHAR(100) NULL DEFAULT NULL AFTER due_date');
        $borrow_columns[] = 'officer';
    }
    $borrow_column_details = [];
    foreach ($pdo->query('SHOW COLUMNS FROM iskolend_borrow')->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $borrow_column_details[$column['Field']] = $column;
    }
    foreach (['officer', 'officer_in_charge'] as $column_name) {
        if (!isset($borrow_column_details[$column_name]) || $borrow_column_details[$column_name]['Null'] === 'YES') {
            continue;
        }
        $column_type = (string)$borrow_column_details[$column_name]['Type'];
        if (!preg_match('/^(?:varchar|char)\(\d+\)$/i', $column_type)) {
            throw new RuntimeException('The retained ISKOLEND staff column has an unsupported type.');
        }
        $pdo->exec("ALTER TABLE iskolend_borrow MODIFY COLUMN `{$column_name}` {$column_type} NULL DEFAULT NULL");
    }
    $pdo->exec(
        "UPDATE iskolend_borrow b
         LEFT JOIN iskolend_items i ON i.id = b.item_id
         SET b.due_date = DATE_ADD(DATE(b.date_borrowed), INTERVAL COALESCE(i.borrow_days, 7) DAY)
         WHERE b.due_date IS NULL"
    );
    $pdo->exec('UPDATE iskolend_items SET borrow_days = 3 WHERE borrow_days NOT IN (2, 3)');

    $pdo->exec(
        "INSERT IGNORE INTO app_settings (setting_key, setting_value)
         VALUES ('MAX_ACTIVE_BORROWS', '3')"
    );
    $migration = $pdo->prepare(
        "SELECT setting_value FROM app_settings
         WHERE setting_key = 'ISKOLEND_UNIT_LIMIT_MIGRATION'"
    );
    $migration->execute();
    if ($migration->fetchColumn() === false) {
        $pdo->exec(
            "UPDATE app_settings SET setting_value = '3'
             WHERE setting_key = 'MAX_ACTIVE_BORROWS' AND setting_value = '1'"
        );
        $pdo->exec(
            "INSERT IGNORE INTO app_settings (setting_key, setting_value)
             VALUES ('ISKOLEND_UNIT_LIMIT_MIGRATION', '1')"
        );
    }
    $pdo->exec("DELETE FROM app_settings WHERE setting_key = 'MAX_BORROW_QTY'");
}

function get_iskolend_unit_limit(PDO $pdo): int
{
    $statement = $pdo->prepare(
        "SELECT setting_value FROM app_settings WHERE setting_key = 'MAX_ACTIVE_BORROWS'"
    );
    $statement->execute();
    $value = $statement->fetchColumn();
    if (!is_string($value) || !ctype_digit($value) || (int)$value < 1) {
        throw new RuntimeException('The ISKOLEND unit limit setting is missing or invalid.');
    }
    return (int)$value;
}

function get_active_iskolend_borrowings(PDO $pdo, string $student_number): array
{
    $statement = $pdo->prepare(
        "SELECT b.id, b.item_name,
                COALESCE(b.quantity, b.qty_borrowed, 1) AS quantity,
                b.date_borrowed, b.due_date, (b.due_date < CURDATE()) AS overdue,
                i.image_path
         FROM iskolend_borrow b
         LEFT JOIN iskolend_items i ON i.id = b.item_id
         WHERE b.student_no = ? AND b.status = 'Borrowed' AND b.date_returned IS NULL
         ORDER BY b.date_borrowed ASC, b.id ASC"
    );
    $statement->execute([normalize_student_number($student_number)]);
    $borrowings = $statement->fetchAll();
    foreach ($borrowings as &$borrowing) {
        $borrowing['quantity'] = (int)$borrowing['quantity'];
        $borrowing['overdue'] = (bool)$borrowing['overdue'];
    }
    unset($borrowing);
    return $borrowings;
}

function get_iskolend_borrowed_units(array $borrowings): int
{
    return array_sum(array_map(
        static fn(array $borrowing): int => max(0, (int)$borrowing['quantity']),
        $borrowings
    ));
}

function ensure_printan_schema(PDO $pdo): void
{
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'printan_records'"
    )->fetchColumn();
    if ((int)$exists === 0) {
        return;
    }

    $columns = $pdo->query('SHOW COLUMNS FROM printan_records')->fetchAll(PDO::FETCH_COLUMN);
    $additions = [
        'service_type' => "ADD COLUMN service_type ENUM('print','xerox','scan') NOT NULL DEFAULT 'print'",
        'copies' => 'ADD COLUMN copies INT NOT NULL DEFAULT 1',
        'reason' => 'ADD COLUMN reason TEXT NULL',
        'officer' => 'ADD COLUMN officer VARCHAR(100) NOT NULL DEFAULT \'\'',
        'status' => "ADD COLUMN status ENUM('completed','pending','cancelled') NOT NULL DEFAULT 'completed'",
    ];
    foreach ($additions as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec('ALTER TABLE printan_records ' . $definition);
            $columns[] = $column;
        }
    }
}

function ensure_officer_schema(PDO $pdo): void
{
    $columns = $pdo->query('SHOW COLUMNS FROM officers')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('birthday', $columns, true)) {
        $pdo->exec('ALTER TABLE officers ADD COLUMN birthday DATE NULL AFTER position');
    }
    if (in_array('date_added', $columns, true)) {
        $pdo->exec('ALTER TABLE officers DROP COLUMN date_added');
    }
}

function ensure_officer_events_schema(PDO $pdo): void
{
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'officer_events'"
    )->fetchColumn();
    if ((int)$exists === 0) {
        $pdo->exec(
            "CREATE TABLE officer_events (
                event_id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(150) NOT NULL,
                event_type ENUM('meeting','event','agenda','deadline','other') NOT NULL DEFAULT 'meeting',
                description TEXT NULL,
                start_at DATETIME NOT NULL,
                end_at DATETIME NULL,
                all_day TINYINT(1) NOT NULL DEFAULT 0,
                created_by VARCHAR(100) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        return;
    }

    $columns = $pdo->query('SHOW COLUMNS FROM officer_events')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('id', $columns, true) && !in_array('event_id', $columns, true)) {
        $pdo->exec('ALTER TABLE officer_events CHANGE COLUMN id event_id INT NOT NULL AUTO_INCREMENT');
        $columns = $pdo->query('SHOW COLUMNS FROM officer_events')->fetchAll(PDO::FETCH_COLUMN);
    }

    foreach ([
        'event_type' => "ADD COLUMN event_type ENUM('meeting','event','agenda','deadline','other') NOT NULL DEFAULT 'meeting'",
        'description' => 'ADD COLUMN description TEXT NULL',
        'start_at' => 'ADD COLUMN start_at DATETIME NULL',
        'end_at' => 'ADD COLUMN end_at DATETIME NULL',
        'all_day' => 'ADD COLUMN all_day TINYINT(1) NOT NULL DEFAULT 0',
        'created_by' => 'ADD COLUMN created_by VARCHAR(100) NULL',
        'created_at' => 'ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ] as $column => $alter) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec('ALTER TABLE officer_events ' . $alter);
            $columns[] = $column;
        }
    }

    if (in_array('event_date', $columns, true)) {
        $time_expression = in_array('event_time', $columns, true)
            ? "COALESCE(event_time, '00:00:00')"
            : "'00:00:00'";
        $pdo->exec(
            "UPDATE officer_events
             SET start_at = COALESCE(start_at, TIMESTAMP(event_date, {$time_expression})),
                 all_day = CASE WHEN " .
                 (in_array('event_time', $columns, true) ? 'event_time IS NULL' : '1 = 1') .
                 ' THEN 1 ELSE all_day END'
        );
    }
    if (in_array('event_datetime', $columns, true)) {
        $pdo->exec('UPDATE officer_events SET start_at = COALESCE(start_at, event_datetime)');
    }
    $pdo->exec("UPDATE officer_events SET start_at = CURRENT_TIMESTAMP WHERE start_at IS NULL");
    $pdo->exec('ALTER TABLE officer_events MODIFY COLUMN start_at DATETIME NOT NULL');

    if (in_array('event_date', $columns, true)) {
        $pdo->exec('ALTER TABLE officer_events DROP COLUMN event_date');
    }
    if (in_array('event_time', $columns, true)) {
        $pdo->exec('ALTER TABLE officer_events DROP COLUMN event_time');
    }
    if (in_array('event_datetime', $columns, true)) {
        $pdo->exec('ALTER TABLE officer_events DROP COLUMN event_datetime');
    }
}

ensure_shared_schema(get_pdo_connection());
