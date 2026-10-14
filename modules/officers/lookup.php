<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
header('Content-Type: application/json');

$officer_id = trim($_GET['officer_id'] ?? '');
$response = ['found' => false];

if ($officer_id !== '') {
    $stmt = $conn->prepare("SELECT * FROM officers WHERE officer_id = ?");
    $stmt->bind_param("s", $officer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 1) {
        $row = $res->fetch_assoc();
        $response = [
            'found' => true,
            'officer_id' => $row['officer_id'],
            'full_name' => $row['full_name'],
            'position' => $row['position'],
        ];
    }
}
echo json_encode($response);
