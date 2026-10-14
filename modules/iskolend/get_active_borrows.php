<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
require_once '../../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

$raw_student_no = $_GET['student_no'] ?? '';
$student_no = is_string($raw_student_no) ? normalize_student_number($raw_student_no) : '';
if (!is_valid_student_number($student_no)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid student number format.']);
    exit;
}
$pdo = get_pdo_connection();
$borrowings = get_active_iskolend_borrowings($pdo, $student_no);
$limit = get_iskolend_unit_limit($pdo);
$borrowed_units = get_iskolend_borrowed_units($borrowings);
$rows = [];
foreach ($borrowings as $row) {
    $row['date_borrowed'] = date('M d, Y g:i A', strtotime($row['date_borrowed']));
    $row['due_date'] = $row['due_date'] ? date('M d, Y', strtotime($row['due_date'])) : '—';
    $rows[] = $row;
}
echo json_encode([
    'rows' => $rows,
    'borrowed_units' => $borrowed_units,
    'limit' => $limit,
    'remaining' => max(0, $limit - $borrowed_units),
    'active_borrowings' => $borrowings,
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
