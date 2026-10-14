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
        'title' => $success ? 'Item returned' : 'Return failed',
        'detail' => $success && isset($extra['student_name'], $extra['item_name'], $extra['quantity'])
            ? $extra['student_name'] . ' · ' . $extra['item_name'] . ' × ' . $extra['quantity']
            : $message,
    ];
    header('Location: return.php?' . ($success ? 'success=return' : 'error=' . urlencode($message)));
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond('Return must be submitted using the form.', false, 405);
}
if (!isset($_SESSION['iskolend_csrf_token']) ||
    !hash_equals($_SESSION['iskolend_csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $respond('Your session has expired. Refresh the page and try again.', false, 403);
}
$raw_borrow_id = $_POST['borrow_id'] ?? null;
$raw_quantity_returned = $_POST['quantity_returned'] ?? null;
$borrow_id = is_string($raw_borrow_id) ? filter_var($raw_borrow_id, FILTER_VALIDATE_INT) : false;
$quantity_returned = is_string($raw_quantity_returned) ? filter_var($raw_quantity_returned, FILTER_VALIDATE_INT) : false;
$condition = $_POST['condition_on_return'] ?? '';
if ($borrow_id === false || $borrow_id === null || $borrow_id < 1 ||
    $quantity_returned === false || $quantity_returned === null || $quantity_returned < 1 ||
    !is_string($condition) || !in_array($condition, ['Good', 'Damaged', 'Lost'], true)) {
    $respond('Choose a valid borrowing, whole-number return quantity, and return condition.', false);
}

$pdo = get_pdo_connection();
try {
    $pdo->beginTransaction();
    $query = $pdo->prepare(
        "SELECT id, item_id, full_name, item_name, COALESCE(quantity, qty_borrowed, 1) AS quantity
         FROM iskolend_borrow WHERE id = ? AND status = 'Borrowed' FOR UPDATE"
    );
    $query->execute([$borrow_id]);
    $record = $query->fetch();
    if ($record === false) {
        $pdo->rollBack();
        $respond('This borrowing has already been returned or no longer exists.', false, 404);
    }
    $outstanding_quantity = max(1, (int)$record['quantity']);
    if ($quantity_returned > $outstanding_quantity) {
        $pdo->rollBack();
        $respond('Return quantity cannot exceed the ' . $outstanding_quantity . ' outstanding unit(s).', false);
    }
    if ($quantity_returned !== $outstanding_quantity) {
        $pdo->rollBack();
        $respond('This borrowing must be returned in full in one transaction (' . $outstanding_quantity . ' unit(s)).', false);
    }
    $item_lock = $pdo->prepare('SELECT id FROM iskolend_items WHERE id = ? FOR UPDATE');
    $item_lock->execute([(int)$record['item_id']]);
    if ($item_lock->fetchColumn() === false) {
        $pdo->rollBack();
        $respond('The borrowed item no longer exists in inventory.', false, 404);
    }
    $update = $pdo->prepare(
        "UPDATE iskolend_borrow
         SET status = 'Returned', date_returned = NOW(), condition_on_return = ?
         WHERE id = ? AND status = 'Borrowed'"
    );
    $update->execute([$condition, $borrow_id]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Borrow record changed while processing the return.');
    }
    if ($condition === 'Good') {
        $restock = $pdo->prepare('UPDATE iskolend_items SET available_qty = available_qty + ? WHERE id = ?');
        $restock->execute([$quantity_returned, (int)$record['item_id']]);
        if ($restock->rowCount() !== 1) {
            throw new RuntimeException('Could not restock the returned item.');
        }
    }
    $pdo->commit();
    $respond('Return logged successfully.', true, 200, [
        'student_name' => $record['full_name'],
        'item_name' => $record['item_name'],
        'quantity' => $quantity_returned,
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('ISKOLEND return failed: ' . $exception->getMessage());
    $respond('Unable to save this return right now.', false, 500);
}
