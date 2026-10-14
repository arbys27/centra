<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
require_once '../../includes/db.php';

$base = '../../';
$page_title = 'Analytics';

/* ---------- helpers ---------- */
function one($conn, $sql) { return $conn->query($sql)->fetch_assoc(); }
function all($conn, $sql) {
    $out = [];
    $r = $conn->query($sql);
    while ($row = $r->fetch_assoc()) $out[] = $row;
    return $out;
}
function pct_change($now, $prev) {
    if ($prev <= 0) return $now > 0 ? 100 : 0;
    return (($now - $prev) / $prev) * 100;
}

/* ---------- totals ---------- */
$students_count   = (int) one($conn, "SELECT COUNT(*) c FROM students")['c'];
$printan_income   = (float) one($conn, "SELECT COALESCE(SUM(amount_paid),0) s FROM printan_records")['s'];
$printan_count    = (int) one($conn, "SELECT COUNT(*) c FROM printan_records")['c'];
$printan_pages    = (int) one($conn, "SELECT COALESCE(SUM(num_pages),0) s FROM printan_records")['s'];
$iskolend_count   = (int) one($conn, "SELECT COUNT(*) c FROM iskolend_borrow")['c'];
$attendance_count = (int) one($conn, "SELECT COUNT(*) c FROM attendance_records")['c'];

/* ---------- this month / last month ---------- */
$monthSql = function ($offset) {
    return "SELECT COUNT(*) transactions,
                   COALESCE(SUM(num_pages), 0) pages,
                   COALESCE(SUM(amount_paid), 0) income
            FROM printan_records
            WHERE date_time >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL $offset MONTH)
              AND date_time <  DATE_ADD(DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL $offset MONTH), INTERVAL 1 MONTH)";
};
$printanMonth = one($conn, $monthSql(0));
$printanPrev  = one($conn, $monthSql(1));
$incomeDelta  = pct_change((float)$printanMonth['income'], (float)$printanPrev['income']);
$txDelta      = pct_change((int)$printanMonth['transactions'], (int)$printanPrev['transactions']);

/* ---------- printan: color vs b&w ---------- */
$printanType = one($conn, "SELECT COALESCE(SUM(CASE WHEN is_colored=1 THEN 1 ELSE 0 END),0) colored,
                                  COALESCE(SUM(CASE WHEN is_colored=0 THEN 1 ELSE 0 END),0) bw
                           FROM printan_records");

/* ---------- printan: last 6 months income ---------- */
$monthRows = all($conn, "SELECT DATE_FORMAT(date_time, '%Y-%m') ym,
                                COALESCE(SUM(amount_paid),0) income,
                                COALESCE(SUM(num_pages),0) pages
                         FROM printan_records
                         WHERE date_time >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
                         GROUP BY ym");
$monthMap = [];
foreach ($monthRows as $m) $monthMap[$m['ym']] = $m;
$monthLabels = $monthIncome = $monthPages = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("first day of -$i month"));
    $monthLabels[] = date('M Y', strtotime($key . '-01'));
    $monthIncome[] = isset($monthMap[$key]) ? (float)$monthMap[$key]['income'] : 0;
    $monthPages[]  = isset($monthMap[$key]) ? (int)$monthMap[$key]['pages'] : 0;
}

/* ---------- printan: last 14 days transactions ---------- */
$dayRows = all($conn, "SELECT DATE(date_time) d, COUNT(*) tx
                       FROM printan_records
                       WHERE date_time >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                       GROUP BY d");
$dayMap = [];
foreach ($dayRows as $d) $dayMap[$d['d']] = (int)$d['tx'];
$dayLabels = $dayTx = [];
for ($i = 13; $i >= 0; $i--) {
    $key = date('Y-m-d', strtotime("-$i day"));
    $dayLabels[] = date('M j', strtotime($key));
    $dayTx[] = $dayMap[$key] ?? 0;
}

/* ---------- iskolend ---------- */
$topItems = all($conn, "SELECT item_name, SUM(COALESCE(quantity, qty_borrowed, 1)) total FROM iskolend_borrow GROUP BY item_name ORDER BY total DESC LIMIT 5");
$borrowStatus = one($conn, "SELECT COALESCE(SUM(CASE WHEN status='Borrowed' AND date_returned IS NULL
                                          THEN COALESCE(quantity, qty_borrowed, 1) ELSE 0 END),0) out_now,
                                   COALESCE(SUM(CASE WHEN status='Returned'
                                          THEN COALESCE(quantity, qty_borrowed, 1) ELSE 0 END),0) returned
                            FROM iskolend_borrow");
$currently_borrowing_students = (int) one($conn, "SELECT COUNT(DISTINCT student_no) c
    FROM iskolend_borrow WHERE status='Borrowed' AND date_returned IS NULL")['c'];
$returned_transactions = (int) one($conn, "SELECT COUNT(*) c FROM iskolend_borrow WHERE status='Returned'")['c'];
$returnRate = $iskolend_count > 0 ? ($returned_transactions / $iskolend_count) * 100 : 0;

/* ---------- attendance ---------- */
$attendSummary = all($conn, "SELECT full_name, COUNT(*) days_logged,
    COALESCE(SUM(CASE WHEN time_in IS NOT NULL AND time_out IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, time_in, time_out) ELSE 0 END),0)/60 hours
    FROM attendance_records GROUP BY full_name ORDER BY hours DESC LIMIT 5");
$maxHours = 0;
foreach ($attendSummary as $a) $maxHours = max($maxHours, (float)$a['hours']);

$attDayRows = all($conn, "SELECT DATE(time_in) d, COUNT(*) logs
                          FROM attendance_records
                          WHERE time_in >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                          GROUP BY d");
$attMap = [];
foreach ($attDayRows as $d) $attMap[$d['d']] = (int)$d['logs'];
$attDayVals = [];
for ($i = 13; $i >= 0; $i--) {
    $key = date('Y-m-d', strtotime("-$i day"));
    $attDayVals[] = $attMap[$key] ?? 0;
}

$chartData = [
    'monthLabels' => $monthLabels,
    'monthIncome' => $monthIncome,
    'monthPages'  => $monthPages,
    'dayLabels'   => $dayLabels,
    'dayTx'       => $dayTx,
    'colored'     => (int)$printanType['colored'],
    'bw'          => (int)$printanType['bw'],
    'borrowOut'   => (int)$borrowStatus['out_now'],
    'borrowRet'   => (int)$borrowStatus['returned'],
    'topLabels'   => array_column($topItems, 'item_name'),
    'topValues'   => array_map('intval', array_column($topItems, 'total')),
    'attNames'    => array_column($attendSummary, 'full_name'),
    'attHours'    => array_map(function ($a) { return round((float)$a, 1); }, array_column($attendSummary, 'hours')),
    'attDayVals'  => $attDayVals,
];

include '../../includes/header.php';
?>

<style>
  .an { --g50:#f9fafb; --g100:#f3f4f6; --g200:#e5e7eb; --g300:#d1d5db; --g400:#9ca3af;
        --g500:#6b7280; --g600:#4b5563; --g700:#374151; --g800:#1f2937; --g900:#111827;
        color: var(--g800); }
  .an * { box-sizing: border-box; }

  .an-hero { background: linear-gradient(135deg, var(--g900), var(--g700)); color:#fff; border-radius:20px;
             padding:28px 32px; display:flex; justify-content:space-between; align-items:center; gap:20px;
             flex-wrap:wrap; box-shadow:0 10px 30px rgba(17,24,39,.18); }
  .an-hero h2 { margin:0 0 6px; font-size:1.6rem; letter-spacing:-.02em; }
  .an-hero p  { margin:0; color:var(--g300); max-width:560px; font-size:.95rem; line-height:1.5; }
  .an-btn { background:#fff; color:var(--g900); border:0; border-radius:12px; padding:12px 20px; font-weight:600;
            cursor:pointer; font-size:.9rem; transition:transform .15s, box-shadow .15s; }
  .an-btn:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(0,0,0,.25); }

  .an-grid { display:grid; gap:18px; margin-top:20px; }
  .an-kpis { grid-template-columns:repeat(4,1fr); }
  .an-2    { grid-template-columns:repeat(2,1fr); }
  .an-3    { grid-template-columns:2fr 1fr; }
  .an-3b   { grid-template-columns:1fr 2fr; }
  @media (max-width:1100px){ .an-kpis{grid-template-columns:repeat(2,1fr);} .an-3,.an-3b{grid-template-columns:1fr;} }
  @media (max-width:640px){ .an-kpis,.an-2{grid-template-columns:1fr;} .an-hero{padding:22px;} }

  .an-card { background:#fff; border:1px solid var(--g200); border-radius:18px; padding:22px;
             box-shadow:0 1px 2px rgba(17,24,39,.04); transition:box-shadow .2s, transform .2s; min-width:0; }
  .an-card:hover { box-shadow:0 10px 24px rgba(17,24,39,.08); }
  .an-card h3 { margin:0; font-size:1rem; color:var(--g900); letter-spacing:-.01em; }
  .an-sub { margin:2px 0 16px; font-size:.8rem; color:var(--g500); }
  .an-chart { position:relative; height:260px; }
  .an-chart.sm { height:220px; }

  .an-kpi { display:flex; flex-direction:column; gap:8px; position:relative; overflow:hidden; }
  .an-kpi .ic { width:40px; height:40px; border-radius:12px; background:var(--g100); display:grid; place-items:center; font-size:1.15rem; }
  .an-kpi .lbl { font-size:.78rem; text-transform:uppercase; letter-spacing:.06em; color:var(--g500); font-weight:600; }
  .an-kpi .num { font-size:1.9rem; font-weight:700; color:var(--g900); letter-spacing:-.03em; line-height:1.1; }
  .an-badge { display:inline-flex; align-items:center; gap:4px; font-size:.75rem; font-weight:600; padding:3px 9px;
              border-radius:999px; width:fit-content; }
  .an-badge.up   { background:var(--g900); color:#fff; }
  .an-badge.down { background:var(--g200); color:var(--g700); }
  .an-badge.flat { background:var(--g100); color:var(--g600); }

  .an-mini { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-top:16px; }
  .an-mini div { background:var(--g50); border:1px solid var(--g200); border-radius:12px; padding:12px 14px; }
  .an-mini b { display:block; font-size:1.15rem; color:var(--g900); }
  .an-mini span { font-size:.72rem; color:var(--g500); text-transform:uppercase; letter-spacing:.05em; }

  .an-table { width:100%; border-collapse:collapse; font-size:.9rem; }
  .an-table th { text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:var(--g500);
                 padding:10px 12px; border-bottom:1px solid var(--g200); }
  .an-table td { padding:12px; border-bottom:1px solid var(--g100); }
  .an-table tr:last-child td { border-bottom:0; }
  .an-table tr:hover td { background:var(--g50); }
  .an-rank { display:inline-grid; place-items:center; width:24px; height:24px; border-radius:8px; background:var(--g100);
             font-size:.75rem; font-weight:700; color:var(--g700); margin-right:10px; }
  .an-bar { height:6px; background:var(--g100); border-radius:99px; overflow:hidden; min-width:90px; }
  .an-bar i { display:block; height:100%; background:linear-gradient(90deg,var(--g600),var(--g900)); border-radius:99px; }
  .an-empty { text-align:center; color:var(--g400); padding:24px 0; font-size:.9rem; }
  .an-wrap { overflow-x:auto; }
</style>

<div class="an">

  <!-- HERO -->
  <div class="an-hero">
    <div>
      <h2>Services Analytics</h2>
      <p>Consolidated data across all CSC service modules — use it for your accomplishment report or State of the Student Council address.</p>
    </div>
    <button class="an-btn" type="button" id="exportSummaryPdf">⬇ Export Summary as PDF</button>
  </div>

  <!-- KPIs -->
  <div class="an-grid an-kpis">
    <div class="an-card an-kpi">
      <div class="ic">🎓</div>
      <div class="lbl">Registered Students</div>
      <div class="num"><?php echo number_format($students_count); ?></div>
    </div>
    <div class="an-card an-kpi">
      <div class="ic">💰</div>
      <div class="lbl">Printan Income (All Time)</div>
      <div class="num">₱<?php echo number_format($printan_income, 2); ?></div>
      <?php $cls = $incomeDelta > 0.5 ? 'up' : ($incomeDelta < -0.5 ? 'down' : 'flat'); ?>
      <span class="an-badge <?php echo $cls; ?>">
        <?php echo $incomeDelta >= 0 ? '▲' : '▼'; ?> <?php echo number_format(abs($incomeDelta), 1); ?>% vs last month
      </span>
    </div>
    <div class="an-card an-kpi">
      <div class="ic">🖨️</div>
      <div class="lbl">Pages Printed</div>
      <div class="num"><?php echo number_format($printan_pages); ?></div>
      <?php $cls = $txDelta > 0.5 ? 'up' : ($txDelta < -0.5 ? 'down' : 'flat'); ?>
      <span class="an-badge <?php echo $cls; ?>">
        <?php echo $txDelta >= 0 ? '▲' : '▼'; ?> <?php echo number_format(abs($txDelta), 1); ?>% transactions MoM
      </span>
    </div>
    <div class="an-card an-kpi">
      <div class="ic">📦</div>
      <div class="lbl">ISKOLEND Transactions</div>
      <div class="num"><?php echo number_format($iskolend_count); ?></div>
      <span class="an-badge flat"><?php echo number_format($returnRate, 0); ?>% returned</span>
      <span class="an-badge flat"><?php echo number_format($currently_borrowing_students); ?> students currently borrowing</span>
    </div>
  </div>

  <!-- PRINTAN -->
  <div class="an-grid an-3">
    <div class="an-card">
      <h3>Printan ng Bayan — Income Trend</h3>
      <p class="an-sub">Income collected and pages printed, last 6 months</p>
      <div class="an-chart"><canvas id="chMonthly"></canvas></div>
    </div>
    <div class="an-card">
      <h3>Print Type</h3>
      <p class="an-sub">Colored vs black &amp; white transactions</p>
      <div class="an-chart"><canvas id="chType"></canvas></div>
    </div>
  </div>

  <div class="an-grid an-3b">
    <div class="an-card">
      <h3>This Month</h3>
      <p class="an-sub"><?php echo date('F Y'); ?></p>
      <div class="an-mini" style="grid-template-columns:1fr;margin-top:0;">
        <div><b><?php echo number_format((int)$printanMonth['transactions']); ?></b><span>Transactions</span></div>
        <div><b><?php echo number_format((int)$printanMonth['pages']); ?></b><span>Pages Printed</span></div>
        <div><b>₱<?php echo number_format((float)$printanMonth['income'], 2); ?></b><span>Income Collected</span></div>
      </div>
    </div>
    <div class="an-card">
      <h3>Daily Printing Activity</h3>
      <p class="an-sub">Transactions per day, last 14 days</p>
      <div class="an-chart sm"><canvas id="chDaily"></canvas></div>
    </div>
  </div>

  <!-- ISKOLEND -->
  <div class="an-grid an-3b">
    <div class="an-card">
      <h3>ISKOLEND Status</h3>
      <p class="an-sub">Units currently out vs units returned</p>
      <div class="an-chart sm"><canvas id="chBorrow"></canvas></div>
    </div>
    <div class="an-card">
      <h3>Top Borrowed Items</h3>
      <p class="an-sub">By total quantity borrowed</p>
      <?php if ($topItems): ?>
        <div class="an-chart sm"><canvas id="chTop"></canvas></div>
      <?php else: ?>
        <div class="an-empty">No borrow records yet.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ATTENDANCE -->
  <div class="an-grid an-3b">
    <div class="an-card">
      <h3>Attendance Activity</h3>
      <p class="an-sub">Officer logs per day, last 14 days</p>
      <div class="an-chart sm"><canvas id="chAttDay"></canvas></div>
    </div>
    <div class="an-card">
      <h3>Officer Attendance</h3>
      <p class="an-sub">Top 5 by hours rendered</p>
      <div class="an-wrap">
        <table class="an-table">
          <thead><tr><th>Officer</th><th>Days</th><th style="width:34%">Hours</th></tr></thead>
          <tbody>
            <?php if (!$attendSummary): ?>
              <tr><td colspan="3" class="an-empty">No attendance logs yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($attendSummary as $i => $row):
                $w = $maxHours > 0 ? ((float)$row['hours'] / $maxHours) * 100 : 0; ?>
              <tr>
                <td><span class="an-rank"><?php echo $i + 1; ?></span><?php echo htmlspecialchars($row['full_name']); ?></td>
                <td><?php echo (int)$row['days_logged']; ?></td>
                <td>
                  <div style="display:flex;align-items:center;gap:10px;">
                    <div class="an-bar" style="flex:1"><i style="width:<?php echo round($w); ?>%"></i></div>
                    <span style="font-size:.8rem;color:#4b5563;white-space:nowrap;"><?php echo number_format((float)$row['hours'], 1); ?> h</span>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<script src="<?php echo $base; ?>assets/vendor/chart.js/chart.umd.min.js"></script>
<script src="<?php echo $base; ?>assets/vendor/jspdf/jspdf.umd.min.js"></script>
<script src="<?php echo $base; ?>assets/vendor/jspdf/jspdf.plugin.autotable.min.js"></script>
<script src="<?php echo $base; ?>assets/js/pdf-export.js"></script>
<script>
const D = <?php echo json_encode($chartData, JSON_NUMERIC_CHECK | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

const G = { 900:'#111827', 700:'#374151', 600:'#4b5563', 500:'#6b7280', 400:'#9ca3af', 300:'#d1d5db', 200:'#e5e7eb', 100:'#f3f4f6' };

Chart.defaults.font.family = "'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif";
Chart.defaults.color = G[500];
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.boxWidth = 8;
Chart.defaults.plugins.tooltip.backgroundColor = G[900];
Chart.defaults.plugins.tooltip.padding = 10;
Chart.defaults.plugins.tooltip.cornerRadius = 8;
Chart.defaults.maintainAspectRatio = false;

const grid = { color: G[100], drawBorder: false };
const noGrid = { display: false };

function gradient(ctx, from, to) {
  const g = ctx.createLinearGradient(0, 0, 0, 260);
  g.addColorStop(0, from); g.addColorStop(1, to);
  return g;
}

function emptyNote(id, msg) {
  const c = document.getElementById(id);
  if (!c) return;
  c.parentElement.innerHTML = '<div class="an-empty" style="padding-top:90px">' + msg + '</div>';
}

/* Monthly income + pages */
(function () {
  const el = document.getElementById('chMonthly');
  const ctx = el.getContext('2d');
  new Chart(el, {
    data: {
      labels: D.monthLabels,
      datasets: [
        { type:'bar', label:'Income (₱)', data:D.monthIncome, backgroundColor:G[900], borderRadius:8, maxBarThickness:36, yAxisID:'y' },
        { type:'line', label:'Pages', data:D.monthPages, borderColor:G[400], backgroundColor:gradient(ctx,'rgba(156,163,175,.35)','rgba(156,163,175,0)'),
          fill:true, tension:.4, pointBackgroundColor:'#fff', pointBorderColor:G[500], pointRadius:4, yAxisID:'y1' }
      ]
    },
    options: {
      interaction:{ mode:'index', intersect:false },
      scales: {
        x:{ grid:noGrid },
        y:{ grid:grid, beginAtZero:true, ticks:{ callback:v => '₱' + v.toLocaleString() } },
        y1:{ position:'right', grid:{ display:false }, beginAtZero:true }
      }
    }
  });
})();

/* Color vs B&W */
(function () {
  if (!D.colored && !D.bw) return emptyNote('chType', 'No print records yet.');
  new Chart(document.getElementById('chType'), {
    type:'doughnut',
    data:{ labels:['Colored','Black & White'], datasets:[{ data:[D.colored, D.bw], backgroundColor:[G[400], G[900]], borderWidth:3, borderColor:'#fff', hoverOffset:6 }] },
    options:{ cutout:'70%', plugins:{ legend:{ position:'bottom' } } }
  });
})();

/* Daily printing */
(function () {
  const el = document.getElementById('chDaily');
  const ctx = el.getContext('2d');
  new Chart(el, {
    type:'line',
    data:{ labels:D.dayLabels, datasets:[{ label:'Transactions', data:D.dayTx, borderColor:G[900], borderWidth:2.5,
      backgroundColor:gradient(ctx,'rgba(55,65,81,.28)','rgba(55,65,81,0)'), fill:true, tension:.4, pointRadius:0, pointHoverRadius:5 }] },
    options:{ interaction:{ mode:'index', intersect:false }, plugins:{ legend:{ display:false } },
      scales:{ x:{ grid:noGrid, ticks:{ maxTicksLimit:7 } }, y:{ grid:grid, beginAtZero:true, ticks:{ precision:0 } } } }
  });
})();

/* ISKOLEND status */
(function () {
  if (!D.borrowOut && !D.borrowRet) return emptyNote('chBorrow', 'No borrow records yet.');
  new Chart(document.getElementById('chBorrow'), {
    type:'doughnut',
    data:{ labels:['Units Currently Out','Units Returned'], datasets:[{ data:[D.borrowOut, D.borrowRet], backgroundColor:[G[900], G[300]], borderWidth:3, borderColor:'#fff', hoverOffset:6 }] },
    options:{ cutout:'68%', plugins:{ legend:{ position:'bottom' } } }
  });
})();

/* Top items */
(function () {
  const el = document.getElementById('chTop');
  if (!el) return;
  new Chart(el, {
    type:'bar',
    data:{ labels:D.topLabels, datasets:[{ label:'Qty borrowed', data:D.topValues,
      backgroundColor:D.topValues.map((_, i) => i === 0 ? G[900] : (i === 1 ? G[700] : G[400])), borderRadius:8, maxBarThickness:22 }] },
    options:{ indexAxis:'y', plugins:{ legend:{ display:false } },
      scales:{ x:{ grid:grid, beginAtZero:true, ticks:{ precision:0 } }, y:{ grid:noGrid } } }
  });
})();

/* Attendance per day */
(function () {
  new Chart(document.getElementById('chAttDay'), {
    type:'bar',
    data:{ labels:D.dayLabels, datasets:[{ label:'Logs', data:D.attDayVals, backgroundColor:G[700], borderRadius:6, maxBarThickness:18 }] },
    options:{ plugins:{ legend:{ display:false } },
      scales:{ x:{ grid:noGrid, ticks:{ maxTicksLimit:7 } }, y:{ grid:grid, beginAtZero:true, ticks:{ precision:0 } } } }
  });
})();

/* PDF export */
document.getElementById('exportSummaryPdf').addEventListener('click', function () {
  exportTablePdf({
    title: 'CSC Accomplishment Summary',
    subtitle: 'Generated <?php echo date('M d, Y g:i A'); ?>',
    columns: ['Area', 'Metric', 'Value'],
    rows: [
      ['Overview', 'Registered Students', '<?php echo $students_count; ?>'],
      ['Overview', 'ISKOLEND Transactions', '<?php echo $iskolend_count; ?>'],
      ['Printan ng Bayan', 'Transactions', '<?php echo $printan_count; ?>'],
      ['Printan ng Bayan', 'Pages Printed', '<?php echo $printan_pages; ?>'],
      ['Printan ng Bayan', 'Revenue Collected', 'PHP <?php echo number_format($printan_income, 2); ?>'],
      ['Printan ng Bayan — This Month', 'Transactions', '<?php echo (int)$printanMonth['transactions']; ?>'],
      ['Printan ng Bayan — This Month', 'Pages Printed', '<?php echo (int)$printanMonth['pages']; ?>'],
      ['Printan ng Bayan — This Month', 'Income Collected', 'PHP <?php echo number_format((float)$printanMonth['income'], 2); ?>'],
      ['ISKOLEND', 'Units Currently Borrowed', '<?php echo (int)$borrowStatus['out_now']; ?>'],
      ['ISKOLEND', 'Units Returned', '<?php echo (int)$borrowStatus['returned']; ?>'],
      ['ISKOLEND', 'Students Currently Borrowing', '<?php echo $currently_borrowing_students; ?>'],
      ['Officer Attendance', 'Total Logs', '<?php echo $attendance_count; ?>']
    ],
    filename: 'csc-accomplishment-summary.pdf',
    orientation: 'portrait'
  }).catch(function (error) {
    if (window.notify) {
      notify.error('PDF export failed', error.message || 'Unable to prepare the PDF.');
    } else {
      console.error(error);
    }
  });
});
</script>

<?php include '../../includes/footer.php'; ?>