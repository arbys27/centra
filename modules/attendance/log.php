<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
header('Content-Type: application/json');

$officer_id = trim($_POST['officer_id'] ?? '');
if ($officer_id === '') { echo json_encode(['success'=>false,'message'=>'No officer ID provided.']); exit; }

$stmt = $conn->prepare("SELECT * FROM officers WHERE officer_id = ?");
$stmt->bind_param("s", $officer_id);
$stmt->execute();
$officer = $stmt->get_result()->fetch_assoc();

if (!$officer) {
    http_response_code(404);
    echo json_encode(['success'=>false,'message'=>'Officer ID not found. Please register them first in the Officers module.']);
    exit;
}

$today = date('Y-m-d');
$stmt = $conn->prepare("SELECT * FROM attendance_records WHERE officer_id = ? AND log_date = ?");
$stmt->bind_param("ss", $officer_id, $today);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();

if (!$record) {
    // First scan today -> TIME IN
    $ins = $conn->prepare("INSERT INTO attendance_records (officer_id, full_name, position, log_date, time_in) VALUES (?,?,?,?,NOW())");
    $ins->bind_param("ssss", $officer['officer_id'], $officer['full_name'], $officer['position'], $today);
    $ins->execute();
    $action = 'TIME IN';
} elseif ($record['time_in'] && !$record['time_out']) {
    // Second scan today -> TIME OUT
    $upd = $conn->prepare("UPDATE attendance_records SET time_out = NOW() WHERE id = ?");
    $upd->bind_param("i", $record['id']);
    $upd->execute();
    $duration_query = $conn->prepare(
        "SELECT TIMESTAMPDIFF(MINUTE, time_in, time_out) AS elapsed_minutes
         FROM attendance_records WHERE id = ?"
    );
    $duration_query->bind_param("i", $record['id']);
    $duration_query->execute();
    $elapsed_minutes = (int)$duration_query->get_result()->fetch_assoc()['elapsed_minutes'];
    $action = 'TIME OUT';
} else {
    http_response_code(409);
    echo json_encode(['success'=>false,'message'=>$officer['full_name'].' has already timed in and out today.']);
    exit;
}

echo json_encode([
    'success' => true,
    'full_name' => $officer['full_name'],
    'position' => $officer['position'],
    'action' => $action,
    'time' => date('g:i A'),
    'elapsed_minutes' => $elapsed_minutes ?? null
]);
