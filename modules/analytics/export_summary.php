<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
require_once '../../includes/db.php';

function val($conn, $sql, $key = 'c') { return $conn->query($sql)->fetch_assoc()[$key]; }

$students_count = (int) val($conn, "SELECT COUNT(*) c FROM students");

$printan_income   = (float) val($conn, "SELECT COALESCE(SUM(amount_paid),0) s FROM printan_records", 's');
$printan_count    = (int) val($conn, "SELECT COUNT(*) c FROM printan_records");
$printan_pages    = (int) val($conn, "SELECT COALESCE(SUM(num_pages),0) s FROM printan_records", 's');
$printan_students = (int) val($conn, "SELECT COUNT(DISTINCT student_no) c FROM printan_records");
$printan_month = $conn->query(
    "SELECT COUNT(*) transactions,
            COALESCE(SUM(num_pages), 0) pages,
            COALESCE(SUM(amount_paid), 0) income
     FROM printan_records
     WHERE date_time >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
       AND date_time < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)"
)->fetch_assoc();
$printan_type = $conn->query(
    "SELECT COALESCE(SUM(CASE WHEN is_colored=1 THEN 1 ELSE 0 END),0) colored,
            COALESCE(SUM(CASE WHEN is_colored=0 THEN 1 ELSE 0 END),0) bw
     FROM printan_records"
)->fetch_assoc();

/* last 6 months income */
$rows = $conn->query(
    "SELECT DATE_FORMAT(date_time, '%Y-%m') ym, COALESCE(SUM(amount_paid),0) income
     FROM printan_records
     WHERE date_time >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
     GROUP BY ym"
);
$map = [];
while ($r = $rows->fetch_assoc()) $map[$r['ym']] = (float)$r['income'];
$months = [];
$maxIncome = 0;
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("first day of -$i month"));
    $v = $map[$key] ?? 0;
    $months[] = ['label' => date('M', strtotime($key . '-01')), 'value' => $v];
    $maxIncome = max($maxIncome, $v);
}

$iskolend_count    = (int) val($conn, "SELECT COUNT(*) c FROM iskolend_borrow");
$iskolend_active   = (int) val($conn, "SELECT COALESCE(SUM(CASE WHEN status='Borrowed' AND date_returned IS NULL
    THEN COALESCE(quantity, qty_borrowed, 1) ELSE 0 END), 0) s FROM iskolend_borrow", 's');
$iskolend_returned = (int) val($conn, "SELECT COUNT(*) c FROM iskolend_borrow WHERE status='Returned'");
$iskolend_students = (int) val($conn, "SELECT COUNT(DISTINCT student_no) c FROM iskolend_borrow");
$iskolend_current_students = (int) val($conn, "SELECT COUNT(DISTINCT student_no) c
    FROM iskolend_borrow WHERE status='Borrowed' AND date_returned IS NULL");
$iskolend_lost     = (int) val($conn, "SELECT COUNT(*) c FROM iskolend_borrow WHERE condition_on_return='Lost'");
$iskolend_damaged  = (int) val($conn, "SELECT COUNT(*) c FROM iskolend_borrow WHERE condition_on_return='Damaged'");

$topItems = [];
$r = $conn->query("SELECT item_name, SUM(COALESCE(quantity, qty_borrowed, 1)) total
                   FROM iskolend_borrow GROUP BY item_name ORDER BY total DESC LIMIT 5");
while ($row = $r->fetch_assoc()) $topItems[] = $row;
$maxItem = 0;
foreach ($topItems as $t) $maxItem = max($maxItem, (int)$t['total']);

$officers_count = (int) val($conn, "SELECT COUNT(*) c FROM officers");
$attend_total   = (int) val($conn, "SELECT COUNT(*) c FROM attendance_records");

$colorTotal = (int)$printan_type['colored'] + (int)$printan_type['bw'];
$colorPct   = $colorTotal > 0 ? round(($printan_type['colored'] / $colorTotal) * 100) : 0;
$returnPct  = $iskolend_count > 0 ? round(($iskolend_returned / $iskolend_count) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CSC Accomplishment Summary</title>
<style>
  @page { size: A4 portrait; margin: 14mm; }
  :root { --g50:#f9fafb; --g100:#f3f4f6; --g200:#e5e7eb; --g300:#d1d5db; --g400:#9ca3af;
          --g500:#6b7280; --g600:#4b5563; --g700:#374151; --g800:#1f2937; --g900:#111827; }
  * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { margin:0; background:var(--g100); color:var(--g800);
         font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif; font-size:13px; line-height:1.5; }
  .sheet { max-width:860px; margin:24px auto; background:#fff; border-radius:16px; overflow:hidden;
           box-shadow:0 10px 40px rgba(17,24,39,.12); }

  .head { background:linear-gradient(135deg,var(--g900),var(--g700)); color:#fff; padding:30px 36px; }
  .head small { text-transform:uppercase; letter-spacing:.14em; color:var(--g300); font-size:10.5px; }
  .head h1 { margin:6px 0 4px; font-size:24px; letter-spacing:-.02em; }
  .head p { margin:0; color:var(--g300); font-size:12.5px; }
  .meta { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-top:16px;
          padding-top:14px; border-top:1px solid rgba(255,255,255,.18); font-size:11.5px; color:var(--g300); }

  .body { padding:28px 36px 34px; }
  section { margin-bottom:26px; break-inside:avoid; page-break-inside:avoid; }
  .sec-title { display:flex; align-items:center; gap:10px; margin:0 0 12px; font-size:14px; color:var(--g900); }
  .sec-title b { display:inline-grid; place-items:center; width:26px; height:26px; border-radius:8px;
                 background:var(--g900); color:#fff; font-size:11px; }

  .kpis { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; }
  .kpis.three { grid-template-columns:repeat(3,1fr); }
  .kpis.five { grid-template-columns:repeat(5,1fr); }
  .kpi { background:var(--g50); border:1px solid var(--g200); border-radius:12px; padding:12px 14px; }
  .kpi .n { font-size:20px; font-weight:700; color:var(--g900); letter-spacing:-.02em; line-height:1.15; }
  .kpi .l { font-size:10px; text-transform:uppercase; letter-spacing:.06em; color:var(--g500); margin-top:3px; }
  .kpi.dark { background:var(--g900); border-color:var(--g900); }
  .kpi.dark .n { color:#fff; }
  .kpi.dark .l { color:var(--g300); }

  .two { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:12px; }
  .panel { border:1px solid var(--g200); border-radius:12px; padding:14px 16px; }
  .panel h4 { margin:0 0 10px; font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--g500); }

  .cols { display:flex; align-items:flex-end; gap:8px; height:90px; }
  .col { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%; }
  .col i { width:100%; max-width:34px; background:var(--g800); border-radius:5px 5px 0 0; min-height:2px; }
  .col span { font-size:10px; color:var(--g500); margin-top:5px; }

  .hbar { margin-bottom:9px; }
  .hbar:last-child { margin-bottom:0; }
  .hbar .top { display:flex; justify-content:space-between; font-size:11.5px; margin-bottom:3px; }
  .track { height:7px; background:var(--g100); border-radius:99px; overflow:hidden; }
  .track i { display:block; height:100%; background:linear-gradient(90deg,var(--g600),var(--g900)); border-radius:99px; }
  .track.light i { background:var(--g400); }

  table { width:100%; border-collapse:collapse; font-size:12.5px; }
  td { padding:9px 12px; border-bottom:1px solid var(--g200); }
  tr:last-child td { border-bottom:0; }
  td:last-child { text-align:right; font-weight:700; color:var(--g900); }
  .tbl { border:1px solid var(--g200); border-radius:12px; overflow:hidden; margin-top:12px; }

  .sign { display:grid; grid-template-columns:1fr 1fr; gap:40px; margin-top:44px; break-inside:avoid; }
  .sign div { text-align:center; font-size:11.5px; color:var(--g600); }
  .sign .line { border-top:1px solid var(--g800); padding-top:6px; margin-top:38px; color:var(--g900); font-weight:600; }
  .foot { text-align:center; font-size:10px; color:var(--g400); padding:0 36px 22px; }

  .toolbar { position:fixed; right:20px; bottom:20px; display:flex; gap:8px; }
  .toolbar button { border:0; border-radius:12px; padding:11px 18px; font-weight:600; cursor:pointer;
                    background:var(--g900); color:#fff; box-shadow:0 8px 20px rgba(0,0,0,.25); }
  .toolbar button.alt { background:#fff; color:var(--g900); }

  @media print {
    body { background:#fff; }
    .sheet { margin:0; max-width:none; border-radius:0; box-shadow:none; }
    .toolbar { display:none; }
  }
  @media (max-width:640px) {
    .kpis, .kpis.three, .kpis.five, .two { grid-template-columns:1fr 1fr; }
    .head, .body { padding-left:20px; padding-right:20px; }
  }
</style>
</head>
<body onload="window.print()">

<div class="sheet">
  <div class="head">
    <small>Central Student Council</small>
    <h1>Consolidated Accomplishment Summary</h1>
    <p>14th Central Student Council — PUP Biñan Campus</p>
    <div class="meta">
      <span>All Programs &amp; Services</span>
      <span>Generated <?php echo date('M d, Y g:i A'); ?></span>
    </div>
  </div>

  <div class="body">

    <!-- I. Overview -->
    <section>
      <h3 class="sec-title"><b>I</b> Overview</h3>
      <div class="kpis three">
        <div class="kpi dark"><div class="n"><?php echo number_format($students_count); ?></div><div class="l">Registered Students</div></div>
        <div class="kpi"><div class="n"><?php echo number_format($officers_count); ?></div><div class="l">CSC Officers</div></div>
        <div class="kpi"><div class="n"><?php echo number_format($printan_students + $iskolend_students); ?></div><div class="l">Student Service Uses*</div></div>
      </div>
      <div style="font-size:10px;color:var(--g400);margin-top:6px;">*Unique students served by Printan plus unique students served by ISKOLEND (a student using both is counted twice).</div>
    </section>

    <!-- II. Printan -->
    <section>
      <h3 class="sec-title"><b>II</b> Printan ng Bayan</h3>
      <div class="kpis">
        <div class="kpi dark"><div class="n">₱<?php echo number_format($printan_income, 2); ?></div><div class="l">Total Revenue</div></div>
        <div class="kpi"><div class="n"><?php echo number_format($printan_count); ?></div><div class="l">Transactions</div></div>
        <div class="kpi"><div class="n"><?php echo number_format($printan_pages); ?></div><div class="l">Pages Printed</div></div>
        <div class="kpi"><div class="n"><?php echo number_format($printan_students); ?></div><div class="l">Unique Students</div></div>
      </div>

      <div class="two">
        <div class="panel">
          <h4>Revenue — Last 6 Months</h4>
          <div class="cols">
            <?php foreach ($months as $m):
                $h = $maxIncome > 0 ? max(2, round(($m['value'] / $maxIncome) * 100)) : 2; ?>
              <div class="col" title="₱<?php echo number_format($m['value'], 2); ?>">
                <i style="height:<?php echo $h; ?>%"></i>
                <span><?php echo $m['label']; ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="panel">
          <h4>This Month — <?php echo date('F Y'); ?></h4>
          <div class="hbar"><div class="top"><span>Transactions</span><b><?php echo number_format((int)$printan_month['transactions']); ?></b></div></div>
          <div class="hbar"><div class="top"><span>Pages Printed</span><b><?php echo number_format((int)$printan_month['pages']); ?></b></div></div>
          <div class="hbar"><div class="top"><span>Income Collected</span><b>₱<?php echo number_format((float)$printan_month['income'], 2); ?></b></div></div>
          <div class="hbar" style="margin-top:14px;">
            <div class="top"><span>Colored prints</span><b><?php echo $colorPct; ?>%</b></div>
            <div class="track"><i style="width:<?php echo $colorPct; ?>%"></i></div>
          </div>
        </div>
      </div>
    </section>

    <!-- III. ISKOLEND -->
    <section>
      <h3 class="sec-title"><b>III</b> ISKOLEND (The Hiraman Hub)</h3>
      <div class="kpis five">
        <div class="kpi dark"><div class="n"><?php echo number_format($iskolend_count); ?></div><div class="l">Borrow Transactions</div></div>
        <div class="kpi"><div class="n"><?php echo number_format($iskolend_students); ?></div><div class="l">Unique Students</div></div>
        <div class="kpi"><div class="n"><?php echo number_format($iskolend_current_students); ?></div><div class="l">Students Currently Borrowing</div></div>
        <div class="kpi"><div class="n"><?php echo number_format($iskolend_active); ?></div><div class="l">Units Currently Out</div></div>
        <div class="kpi"><div class="n"><?php echo $returnPct; ?>%</div><div class="l">Return Rate</div></div>
      </div>

      <div class="two">
        <div class="panel">
          <h4>Top Borrowed Items</h4>
          <?php if (!$topItems): ?>
            <div style="color:var(--g400);">No borrow records yet.</div>
          <?php endif; ?>
          <?php foreach ($topItems as $t):
              $w = $maxItem > 0 ? round(((int)$t['total'] / $maxItem) * 100) : 0; ?>
            <div class="hbar">
              <div class="top"><span><?php echo htmlspecialchars($t['item_name']); ?></span><b><?php echo (int)$t['total']; ?></b></div>
              <div class="track"><i style="width:<?php echo $w; ?>%"></i></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="panel">
          <h4>Item Condition</h4>
          <div class="hbar"><div class="top"><span>Returned</span><b><?php echo number_format($iskolend_returned); ?></b></div>
            <div class="track light"><i style="width:<?php echo $returnPct; ?>%"></i></div></div>
          <div class="hbar"><div class="top"><span>Returned Damaged</span><b><?php echo number_format($iskolend_damaged); ?></b></div></div>
          <div class="hbar"><div class="top"><span>Reported Lost</span><b><?php echo number_format($iskolend_lost); ?></b></div></div>
        </div>
      </div>
    </section>

    <!-- IV. Attendance -->
    <section>
      <h3 class="sec-title"><b>IV</b> Officer Attendance</h3>
      <div class="tbl">
        <table>
          <tr><td>Total Attendance Logs Recorded</td><td><?php echo number_format($attend_total); ?></td></tr>
        </table>
      </div>
    </section>

    <div class="sign">
      <div><div class="line">Prepared by</div>Signature over printed name</div>
      <div><div class="line">Attested by</div>President, Central Student Council</div>
    </div>
  </div>

  <div class="foot">14th Central Student Council · PUP Biñan Campus · System-generated report</div>
</div>

<div class="toolbar">
  <button class="alt" onclick="history.back()">← Back</button>
  <button onclick="window.print()">🖨 Print / Save as PDF</button>
</div>

</body>
</html>