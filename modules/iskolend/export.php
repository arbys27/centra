<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';

$status = $_GET['status'] ?? '';
$where = "1=1"; $params = []; $types = "";
if ($status === 'Borrowed' || $status === 'Returned') { $where .= " AND status = ?"; $params[] = $status; $types .= "s"; }

$stmt = $conn->prepare("SELECT * FROM iskolend_borrow WHERE $where ORDER BY date_borrowed ASC");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>ISKOLEND - Report</title>
<link rel="stylesheet" href="../../assets/css/style.css">
<style>@page{size:portrait;}</style></head>
<body onload="window.print()">
<div style="max-width:1000px;margin:30px auto;padding:0 20px;">
  <div class="print-header">
    <h2>14th Central Student Council - PUP Binan Campus</h2>
    <p>ISKOLEND (The Hiraman Hub) — Borrow / Return Report <?php echo $status ? "($status)" : ''; ?></p>
    <p>Generated: <?php echo date('M d, Y g:i A'); ?></p>
  </div>
  <table border="1" cellpadding="6" style="border-collapse:collapse;width:100%;font-size:11px;">
    <tr style="background:#eee;"><th>Student</th><th>Program/Year</th><th>Item</th><th>Qty</th><th>Borrowed</th><th>Returned</th><th>Condition</th><th>Status</th></tr>
    <?php while ($row = $res->fetch_assoc()): ?>
    <tr>
      <td><?php echo htmlspecialchars($row['full_name']).' ('.htmlspecialchars($row['student_no']).')'; ?></td>
      <td><?php echo htmlspecialchars($row['program'].' / '.$row['year_level']); ?></td>
      <td><?php echo htmlspecialchars($row['item_name']); ?></td>
      <td><?php echo (int)($row['quantity'] ?? $row['qty_borrowed']); ?></td>
      <td><?php echo date('M d, Y g:i A', strtotime($row['date_borrowed'])); ?></td>
      <td><?php echo $row['date_returned'] ? date('M d, Y g:i A', strtotime($row['date_returned'])) : '—'; ?></td>
      <td><?php echo htmlspecialchars($row['condition_on_return'] ?? '—'); ?></td>
      <td><?php echo $row['status']; ?></td>
    </tr>
    <?php endwhile; ?>
  </table>
  <p style="margin-top:40px;font-size:12px;">Prepared by: ____________________ &nbsp;&nbsp;&nbsp; Checked by: ____________________</p>
</div>
</body></html>
