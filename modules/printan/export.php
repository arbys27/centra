<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
require_once '../../includes/db.php';

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$is_valid_date = static function ($value): bool {
    if (!is_string($value) || $value === '') {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
if (($from !== '' && !$is_valid_date($from)) || ($to !== '' && !$is_valid_date($to)) ||
    ($from !== '' && $to !== '' && $from > $to)) {
    http_response_code(400);
    exit('Choose a valid date range.');
}

$where = [];
$params = [];
if ($from !== '') {
    $where[] = 'records.date_time >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'records.date_time < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $to . ' 00:00:00';
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $conn->prepare(
    "SELECT records.student_no, records.full_name, records.program, records.year_level,
            students.section, records.is_colored, records.own_paper, records.amount_paid, records.date_time
     FROM printan_records AS records
     LEFT JOIN students ON students.student_no = records.student_no
     {$where_sql}
     ORDER BY records.date_time ASC"
);
if ($params) {
    $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$escape = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Printan ng Bayan - Ledger</title>
<link rel="stylesheet" href="../../assets/css/style.css">
<style>
  body{background:#fff;color:#111;}
  .ledger-report{max-width:1200px;margin:30px auto;padding:0 20px;}
  .ledger-header{text-align:center;margin-bottom:22px;}
  .ledger-header h1{font-size:20px;margin:0 0 5px;}
  .ledger-header p{margin:3px 0;font-size:12px;}
  .ledger-table{border-collapse:collapse;width:100%;font-size:11px;}
  .ledger-table th,.ledger-table td{border:1px solid #888;padding:6px 7px;vertical-align:top;}
  .ledger-table th{background:#e8e8e5;}
  .ledger-signatures{margin-top:35px;font-size:12px;}
  .print-art-header,.print-art-footer{display:none;}
  @media print{
    @page{size:portrait;margin:34mm 10mm 30mm;}
    body{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .print-art-header{position:fixed;display:block;top:-30mm;left:0;width:100%;height:25mm;object-fit:contain;}
    .print-art-footer{position:fixed;display:block;bottom:-26mm;left:0;width:100%;height:22mm;object-fit:contain;}
    .ledger-report{max-width:none;margin:0;padding:0;}
    .ledger-header{margin-bottom:10px;}
    .ledger-table thead{display:table-header-group;}
    .ledger-table tr{break-inside:avoid;page-break-inside:avoid;}
    .ledger-signatures{break-inside:avoid;page-break-inside:avoid;}
  }
</style>
</head>
<body>
<img class="print-art-header" src="../../assets/img/csc-header.png" alt="">
<img class="print-art-footer" src="../../assets/img/csc-footer.png" alt="">
<main class="ledger-report">
  <header class="ledger-header">
    <h1>Printan ng Bayan — Transaction Ledger</h1>
    <p><?php echo $from ? 'From ' . $escape(date('M d, Y', strtotime($from))) : 'All records'; ?><?php echo $to ? ' to ' . $escape(date('M d, Y', strtotime($to))) : ''; ?></p>
    <p>Generated: <?php echo $escape(date('M d, Y g:i A')); ?></p>
  </header>
  <table class="ledger-table">
    <thead><tr>
      <th>Date / Time</th><th>Student No.</th><th>Name</th><th>Program</th>
      <th>Year</th><th>Section</th><th>Color</th><th>Own Paper</th><th>Amount</th>
    </tr></thead>
    <tbody>
    <?php while ($row = $res->fetch_assoc()): ?>
      <tr>
        <td><?php echo $escape(date('M d, Y g:i A', strtotime($row['date_time']))); ?></td>
        <td><?php echo $escape($row['student_no']); ?></td>
        <td><?php echo $escape($row['full_name']); ?></td>
        <td><?php echo $escape($row['program']); ?></td>
        <td><?php echo $escape($row['year_level']); ?></td>
        <td><?php echo $escape($row['section']); ?></td>
        <td><?php echo $row['is_colored'] ? 'Colored' : 'B&amp;W'; ?></td>
        <td><?php echo $row['own_paper'] ? 'Brought own' : 'CSC paper'; ?></td>
        <td>₱<?php echo number_format((float)$row['amount_paid'], 2); ?></td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
  <p class="ledger-signatures">Prepared by: ____________________ &nbsp;&nbsp;&nbsp; Checked by: ____________________</p>
</main>
<script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
