<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
$base = '../../';
$page_title = 'Attendance';

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$where = "1=1"; $params = []; $types = "";
if ($from) { $where .= " AND log_date >= ?"; $params[] = $from; $types .= "s"; }
if ($to)   { $where .= " AND log_date <= ?"; $params[] = $to; $types .= "s"; }

$stmt = $conn->prepare("SELECT * FROM attendance_records WHERE $where ORDER BY log_date DESC, time_in DESC");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

include '../../includes/header.php';
?>
<div class="card" style="margin-bottom:0;">
  <div style="display:flex;gap:10px;">
    <a class="btn btn-outline" href="scan.php">🕒 Time In / Out</a>
    <a class="btn" href="list.php">📄 View Records</a>
  </div>
</div>

<div class="card" style="margin-top:20px;">
  <div class="topbar" style="margin-bottom:10px;">
    <h2 style="margin:0;">Attendance Records</h2>
    <button class="btn btn-gold" type="button" data-export-table-pdf data-table="#attendanceRecordsTable" data-title="Officer Attendance Report" data-subtitle="Filtered attendance records" data-filename="officer-attendance.pdf">⬇ Export as PDF</button>
  </div>
  <form method="GET" class="form-row" style="margin-bottom:14px;">
    <div><label>From</label><input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>"></div>
    <div><label>To</label><input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>"></div>
    <div style="align-self:flex-end;"><button class="btn" type="submit">Filter</button></div>
  </form>
  <div class="table-wrap">
  <table id="attendanceRecordsTable">
    <tr><th>Date</th><th>Officer</th><th>Position</th><th>Time In</th><th>Time Out</th><th>Hours</th></tr>
    <?php while ($row = $res->fetch_assoc()):
        $hours = '—';
        if ($row['time_in'] && $row['time_out']) {
            $diff = (strtotime($row['time_out']) - strtotime($row['time_in'])) / 3600;
            $hours = number_format($diff, 2) . ' hrs';
        }
    ?>
    <tr>
      <td><?php echo date('M d, Y', strtotime($row['log_date'])); ?></td>
      <td><?php echo htmlspecialchars($row['full_name']); ?></td>
      <td><?php echo htmlspecialchars($row['position']); ?></td>
      <td><?php echo $row['time_in'] ? date('g:i A', strtotime($row['time_in'])) : '—'; ?></td>
      <td><?php echo $row['time_out'] ? date('g:i A', strtotime($row['time_out'])) : '<span class="badge badge-gold">Still In</span>'; ?></td>
      <td><?php echo $hours; ?></td>
    </tr>
    <?php endwhile; ?>
  </table>
  </div>
</div>
<script src="<?php echo $base; ?>assets/vendor/jspdf/jspdf.umd.min.js"></script>
<script src="<?php echo $base; ?>assets/vendor/jspdf/jspdf.plugin.autotable.min.js"></script>
<script src="<?php echo $base; ?>assets/js/pdf-export.js"></script>
<?php include '../../includes/footer.php'; ?>
