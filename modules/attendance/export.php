<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$where = "1=1"; $params = []; $types = "";
if ($from) { $where .= " AND log_date >= ?"; $params[] = $from; $types .= "s"; }
if ($to)   { $where .= " AND log_date <= ?"; $params[] = $to; $types .= "s"; }

$stmt = $conn->prepare("SELECT * FROM attendance_records WHERE $where ORDER BY log_date ASC, time_in ASC");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Attendance - Report</title>
<link rel="stylesheet" href="../../assets/css/style.css">
<style>@page{size:portrait;}</style></head>
<body onload="window.print()">
<div style="max-width:900px;margin:30px auto;padding:0 20px;">
  <div class="print-header">
    <h2>14th Central Student Council - PUP Binan Campus</h2>
    <p>Officer Attendance Report</p>
    <p><?php echo $from ? "From ".date('M d, Y', strtotime($from)) : 'All records'; ?><?php echo $to ? " to ".date('M d, Y', strtotime($to)) : ''; ?></p>
    <p>Generated: <?php echo date('M d, Y g:i A'); ?></p>
  </div>
  <table border="1" cellpadding="6" style="border-collapse:collapse;width:100%;font-size:12px;">
    <tr style="background:#eee;"><th>Date</th><th>Officer</th><th>Position</th><th>Time In</th><th>Time Out</th><th>Hours</th></tr>
    <?php while ($row = $res->fetch_assoc()):
        $hours = '—';
        if ($row['time_in'] && $row['time_out']) {
            $diff = (strtotime($row['time_out']) - strtotime($row['time_in'])) / 3600;
            $hours = number_format($diff, 2);
        }
    ?>
    <tr>
      <td><?php echo date('M d, Y', strtotime($row['log_date'])); ?></td>
      <td><?php echo htmlspecialchars($row['full_name']); ?></td>
      <td><?php echo htmlspecialchars($row['position']); ?></td>
      <td><?php echo $row['time_in'] ? date('g:i A', strtotime($row['time_in'])) : '—'; ?></td>
      <td><?php echo $row['time_out'] ? date('g:i A', strtotime($row['time_out'])) : 'Still In'; ?></td>
      <td><?php echo $hours; ?></td>
    </tr>
    <?php endwhile; ?>
  </table>
  <p style="margin-top:40px;font-size:12px;">Prepared by: ____________________ &nbsp;&nbsp;&nbsp; Checked by: ____________________</p>
</div>
</body></html>
