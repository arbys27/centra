<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
require_once '../../includes/db.php';

$is_ajax = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
$respond = static function (string $message, bool $success, int $status = 422, array $extra = []) use ($is_ajax): void {
    if ($is_ajax) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($success
            ? ['success' => true, 'message' => $message] + $extra
            : ['error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION['notification_flash'] = [
        'type' => $success ? 'success' : 'error',
        'title' => $success ? 'Borrow recorded' : 'Borrow failed',
        'detail' => $success && isset($extra['student_name'], $extra['item_name'], $extra['quantity'], $extra['due_date'])
            ? $extra['student_name'] . ' · ' . $extra['item_name'] . ' × ' . $extra['quantity'] .
                ' · due ' . date('M j', strtotime($extra['due_date']))
            : $message,
    ];
    header('Location: borrow.php?' . ($success ? 'success=borrow' : 'error=' . urlencode($message)));
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond('Borrowing must be submitted using the form.', false, 405);
}
if (!isset($_SESSION['iskolend_csrf_token']) ||
    !hash_equals($_SESSION['iskolend_csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $respond('Your session has expired. Refresh the page and try again.', false, 403);
}

$raw_student_no = $_POST['student_no'] ?? '';
$student_no = is_string($raw_student_no) ? normalize_student_number($raw_student_no) : '';
$raw_item_id = $_POST['item_id'] ?? null;
$raw_quantity = $_POST['quantity'] ?? null;
$item_id = is_string($raw_item_id) ? filter_var($raw_item_id, FILTER_VALIDATE_INT) : false;
$quantity = is_string($raw_quantity) ? filter_var($raw_quantity, FILTER_VALIDATE_INT) : false;
if (!is_valid_student_number($student_no) || $item_id === false || $item_id === null || $item_id < 1 ||
    $quantity === false || $quantity === null || $quantity < 1) {
    $respond('Provide a valid student, item, and whole-number quantity of at least 1.', false);
}
$pdo = get_pdo_connection();
if (get_active_blacklist($pdo, $student_no) !== null) {
    $respond('This student is blacklisted and cannot borrow CSC items.', false, 403);
}

try {
    $pdo->beginTransaction();

    $student_query = $pdo->prepare('SELECT student_no, full_name, program, year_level FROM students WHERE student_no = ? FOR UPDATE');
    $student_query->execute([$student_no]);
    $student = $student_query->fetch();
    if ($student === false) {
        $pdo->rollBack();
        $respond('Student number not found. Please register the student first.', false, 404);
    }

    $blacklist = get_active_blacklist($pdo, $student_no);
    if ($blacklist !== null) {
        $pdo->rollBack();
        $respond('This student is blacklisted and cannot borrow CSC items.', false, 403);
    }

    $limit = get_iskolend_unit_limit($pdo);
    $active_borrowings = get_active_iskolend_borrowings($pdo, $student_no);
    $borrowed_units = get_iskolend_borrowed_units($active_borrowings);
    $remaining = max(0, $limit - $borrowed_units);
    if ($quantity > $remaining) {
        $pdo->rollBack();
        $message = $remaining === 0
            ? 'Limit reached (' . $borrowed_units . ' of ' . $limit . '). Return an item before borrowing again.'
            : 'This student already has ' . $borrowed_units . ' of ' . $limit .
                ' items out and can borrow ' . $remaining . ' more.';
        $respond($message, false, 422);
    }

    $item_query = $pdo->prepare(
        'SELECT id, item_name, available_qty, borrow_days FROM iskolend_items WHERE id = ? FOR UPDATE'
    );
    $item_query->execute([$item_id]);
    $item = $item_query->fetch();
    if ($item === false) {
        $pdo->rollBack();
        $respond('The selected item no longer exists.', false, 404);
    }
    if ($quantity > (int)$item['available_qty']) {
        $pdo->rollBack();
        $respond('Only ' . max(0, (int)$item['available_qty']) . ' unit(s) are available for this item.', false, 422);
    }

    $due_date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
        ->modify('+' . max(1, (int)$item['borrow_days']) . ' days')
        ->format('Y-m-d');
    $insert = $pdo->prepare(
        "INSERT INTO iskolend_borrow
            (student_no, full_name, program, year_level, item_id, item_name,
             qty_borrowed, quantity, due_date, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Borrowed')"
    );
    $insert->execute([
        $student['student_no'], $student['full_name'], $student['program'], $student['year_level'],
        $item_id, $item['item_name'], $quantity, $quantity, $due_date,
    ]);

    $stock_update = $pdo->prepare(
        'UPDATE iskolend_items SET available_qty = available_qty - ? WHERE id = ? AND available_qty >= ?'
    );
    $stock_update->execute([$quantity, $item_id, $quantity]);
    if ($stock_update->rowCount() !== 1) {
        throw new RuntimeException('Inventory changed while processing the borrowing.');
    }
    $pdo->commit();
    $respond('Item borrowed successfully.', true, 200, [
        'student_name' => $student['full_name'],
        'item_name' => $item['item_name'],
        'quantity' => $quantity,
        'due_date' => $due_date,
        'borrowed_units' => $borrowed_units + $quantity,
        'limit' => $limit,
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('ISKOLEND borrow failed: ' . $exception->getMessage());
    $respond('Unable to save this borrowing right now.', false, 500);
}
