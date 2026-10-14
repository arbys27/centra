<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
require_once '../../includes/db.php';
header('Content-Type: application/json');

$raw_student_no = $_GET['student_no'] ?? '';
$student_no = is_string($raw_student_no) ? normalize_student_number($raw_student_no) : '';
$response = ['found' => false];

if (!is_valid_student_number($student_no)) {
    $response['message'] = 'Invalid student number format.';
} else {
    $stmt = $conn->prepare("SELECT * FROM students WHERE student_no = ?");
    $stmt->bind_param("s", $student_no);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 1) {
        $row = $res->fetch_assoc();
        $pdo = get_pdo_connection();
        $blacklist = get_active_blacklist($pdo, $student_no);
        $active_borrowings = get_active_iskolend_borrowings($pdo, $student_no);
        $limit = get_iskolend_unit_limit($pdo);
        $borrowed_units = get_iskolend_borrowed_units($active_borrowings);
        $response = [
            'found' => true,
            'blacklisted' => $blacklist !== null,
            'student_no' => $row['student_no'],
            'full_name' => $row['full_name'],
            'program' => $row['program'],
            'year_level' => $row['year_level'],
            'section' => $row['section'] ?? '',
            'borrowed_units' => $borrowed_units,
            'limit' => $limit,
            'remaining' => max(0, $limit - $borrowed_units),
            'active_borrowings' => $active_borrowings,
        ];
        if ($blacklist !== null) {
            $response['reason'] = $blacklist['reason'];
        }
    }
}
echo json_encode($response);
