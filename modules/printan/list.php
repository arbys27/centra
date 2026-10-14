<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
require_once '../../includes/db.php';

$pdo = get_pdo_connection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
$valid_date = static function ($value): bool {
    if (!is_string($value) || $value === '') {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

if ($method === 'GET' && $action === 'metrics') {
    $selected_date = $_GET['date'] ?? '';
    if (!$valid_date($selected_date)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Select a valid date.']);
        exit;
    }

    if ($selected_date === (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d')) {
        $statement = $pdo->query(
            "SELECT COUNT(*) AS transactions,
                    COALESCE(SUM(num_pages), 0) AS pages,
                    COALESCE(SUM(amount_paid), 0) AS income
             FROM printan_records
             WHERE date_time >= CURDATE() AND date_time < CURDATE() + INTERVAL 1 DAY"
        );
        $metrics = $statement->fetch();
    } else {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) AS transactions,
                    COALESCE(SUM(num_pages), 0) AS pages,
                    COALESCE(SUM(amount_paid), 0) AS income
             FROM printan_records WHERE DATE(date_time) = ?'
        );
        $statement->execute([$selected_date]);
        $metrics = $statement->fetch();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['date' => $selected_date, 'metrics' => $metrics], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'GET' && $action === 'records') {
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    if (($from !== '' && !$valid_date($from)) || ($to !== '' && !$valid_date($to)) ||
        ($from !== '' && $to !== '' && $from > $to)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Choose a valid date range.']);
        exit;
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
    $query = $pdo->prepare(
        "SELECT records.student_no, records.full_name, records.program, records.year_level,
                students.section, records.is_colored, records.own_paper, records.amount_paid, records.date_time
         FROM printan_records AS records
         LEFT JOIN students ON students.student_no = records.student_no
         {$where_sql}
         ORDER BY records.date_time DESC, records.id DESC"
    );
    $query->execute($params);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['records' => $query->fetchAll()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($method !== 'GET' || $action !== '') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unsupported request.']);
    exit;
}

$base = '../../';
$page_title = 'Printan ng Bayan';
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
include '../../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo $base; ?>assets/css/printan-records.css">
<div class="printan-records-page">
  <section class="card fade-in-up printan-daily-card is-loading" id="dailyCard">
    <div class="printan-daily-heading">
      <div>
        <h2 class="mb-1">Daily Summary</h2>
        <p class="text-secondary mb-0">Totals for one day. Pick another date to look back.</p>
      </div>
      <div>
        <label for="dailySummaryDate" class="form-label">Summary date</label>
        <input class="form-control" type="date" id="dailySummaryDate" max="<?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>">
      </div>
    </div>
    <div class="grid grid-3">
      <article class="stat-card"><div class="num" id="dailyTransactions">—</div><div class="lbl">Transactions</div></article>
      <article class="stat-card"><div class="num" id="dailyPages">—</div><div class="lbl">Pages printed</div></article>
      <article class="stat-card"><div class="num" id="dailyIncome">—</div><div class="lbl">Income collected</div></article>
    </div>
    <div class="small text-secondary mt-2" id="dailyMetricsMessage" aria-live="polite"></div>
  </section>

  <section class="card fade-in-up">
    <div class="printan-records-heading">
      <div>
        <h2 class="mb-1">Printan Records</h2>
        <p class="text-secondary mb-0">Search, filter, and export the selected records.</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline" href="scan.php">+ Log a Request</a>
        <a class="btn btn-outline" id="printanLedgerExport" href="export.php" target="_blank" rel="noopener">Ledger / Print</a>
        <button class="btn" type="button" id="exportPrintanPdf">Export PDF</button>
      </div>
    </div>
    <?php if (isset($_GET['success'])): ?><div class="alert alert-success">Print request saved.</div><?php endif; ?>
    <form id="printRecordsFilters" class="printan-record-filters">
      <div>
        <label for="recordsSearch" class="form-label">Search records</label>
        <input class="form-control" type="search" id="recordsSearch" placeholder="Student number, name, program, or year/section">
      </div>
      <div>
        <label for="recordsFrom" class="form-label">From</label>
        <input class="form-control" type="date" id="recordsFrom">
      </div>
      <div>
        <label for="recordsTo" class="form-label">To</label>
        <input class="form-control" type="date" id="recordsTo">
      </div>
      <div>
        <label for="recordsPageSize" class="form-label">Rows</label>
        <select class="form-select" id="recordsPageSize">
          <option value="10">10 / page</option><option value="25">25 / page</option><option value="50">50 / page</option>
        </select>
      </div>
    </form>
    <div class="printan-quick-row" role="group" aria-label="Quick date ranges">
      <button type="button" class="printan-chip" data-range="today">Today</button>
      <button type="button" class="printan-chip" data-range="7">Last 7 days</button>
      <button type="button" class="printan-chip" data-range="month">This month</button>
      <button type="button" class="printan-chip is-active" data-range="all">All dates</button>
      <button type="button" class="printan-clear" id="clearFilters">Clear all filters</button>
    </div>
    <div class="table-wrap">
      <table class="table table-hover align-middle printan-record-table" id="printRecordsTable">
        <thead>
          <tr><th>Date / Time</th><th>Student Number</th><th>Name</th><th>Program</th><th>Year</th><th>Section</th><th>Color</th><th>Own Paper</th><th>Amount</th></tr>
        </thead>
        <tbody id="printRecordsBody"><tr><td colspan="9" class="pr-state">Loading records…</td></tr></tbody>
      </table>
    </div>
    <div class="printan-pagination">
      <span class="text-secondary" id="recordsCount" aria-live="polite"></span>
      <div class="btn-group" role="group" aria-label="Print records pages">
        <button class="btn btn-outline btn-sm" type="button" id="recordsPrevious">Previous</button>
        <button class="btn btn-outline btn-sm" type="button" id="recordsNext">Next</button>
      </div>
    </div>
  </section>
</div>
<script src="<?php echo $base; ?>assets/vendor/jspdf/jspdf.umd.min.js"></script>
<script src="<?php echo $base; ?>assets/vendor/jspdf/jspdf.plugin.autotable.min.js"></script>
<script src="<?php echo $base; ?>assets/js/pdf-export.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  'use strict';
  var TODAY = <?php echo json_encode($today); ?>;
  var COLUMN_COUNT = 9;
  var records = [];
  var page = 1;
  var pageSize = 10;
  var searchTimer = null;
  var currentFrom = '';
  var currentTo = '';
  function flag(value) { return value === 1 || value === '1' || value === true; }

  function requestJson(url) {
    return fetch(url).then(function (response) {
      return response.json().then(function (payload) {
        if (!response.ok) throw new Error(payload.error || 'Unable to load records.');
        return payload;
      });
    });
  }

  function getVisibleRecords() {
    var query = document.getElementById('recordsSearch').value.trim().toLocaleLowerCase();
    return records.filter(function (record) {
      return !query || [
        record.student_no, record.full_name, record.program, record.year_level,
        record.section, flag(record.is_colored) ? 'colored color' : 'black and white b&w',
        flag(record.own_paper) ? 'own paper brought' : 'csc paper'
      ].join(' ').toLocaleLowerCase().includes(query);
    });
  }

  function money(value) { return '₱' + Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  /* ----- DOM helpers ----- */
  function makeCell(row, label, className) {
    var cell = document.createElement('td');
    cell.setAttribute('data-label', label);
    if (className) cell.className = className;
    row.appendChild(cell);
    return cell;
  }
  function span(className, text) {
    var el = document.createElement('span');
    el.className = className;
    el.textContent = text;
    return el;
  }
  function stateRow(body, title, detail, extraClass) {
    var row = document.createElement('tr');
    var cell = document.createElement('td');
    cell.colSpan = COLUMN_COUNT;
    cell.className = 'pr-state ' + (extraClass || '');
    var strong = document.createElement('strong');
    strong.textContent = title;
    cell.appendChild(strong);
    if (detail) cell.appendChild(document.createTextNode(detail));
    row.appendChild(cell);
    body.appendChild(row);
  }
  function showSkeleton() {
    var body = document.getElementById('printRecordsBody');
    body.replaceChildren();
    for (var i = 0; i < 5; i += 1) {
      var row = document.createElement('tr');
      row.className = 'pr-skeleton';
      for (var j = 0; j < COLUMN_COUNT; j += 1) {
        var cell = document.createElement('td');
        cell.appendChild(document.createElement('span'));
        row.appendChild(cell);
      }
      body.appendChild(row);
    }
  }

  function renderRecords() {
    var body = document.getElementById('printRecordsBody');
    var matching = getVisibleRecords();
    var pageCount = Math.max(1, Math.ceil(matching.length / pageSize));
    page = Math.min(page, pageCount);
    body.replaceChildren();
    if (!matching.length) {
      if (records.length) stateRow(body, 'No matching records', 'Try a different search term.');
      else stateRow(body, 'No print records found', 'Change the date range or log a new request.');
    } else {
      matching.slice((page - 1) * pageSize, page * pageSize).forEach(function (record) {
        var row = document.createElement('tr');

        var dateCell = makeCell(row, 'Date / Time');
        var date = new Date(String(record.date_time).replace(' ', 'T'));
        if (isNaN(date.getTime())) {
          dateCell.textContent = record.date_time || '—';
        } else {
          dateCell.appendChild(span('pr-date', date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })));
          dateCell.appendChild(span('pr-time', date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })));
        }

        makeCell(row, 'Student Number').textContent = record.student_no || '—';
        makeCell(row, 'Name').textContent = record.full_name || '—';
        makeCell(row, 'Program').textContent = record.program || '—';
        makeCell(row, 'Year').textContent = record.year_level || '—';
        makeCell(row, 'Section').textContent = record.section || '—';
        makeCell(row, 'Color').appendChild(span('pr-color', flag(record.is_colored) ? 'Colored' : 'B&W'));
        makeCell(row, 'Own Paper').textContent = flag(record.own_paper) ? 'Brought own' : 'CSC paper';
        makeCell(row, 'Amount', 'is-amount').textContent = money(record.amount_paid);
        body.appendChild(row);
      });
    }
    var first = matching.length ? (page - 1) * pageSize + 1 : 0;
    document.getElementById('recordsCount').textContent = matching.length
      ? 'Showing ' + first + '–' + Math.min(page * pageSize, matching.length) + ' of ' + matching.length + ' records'
      : '0 records';
    document.getElementById('recordsPrevious').disabled = page <= 1;
    document.getElementById('recordsNext').disabled = page >= pageCount;
  }

  function loadRecords() {
    currentFrom = document.getElementById('recordsFrom').value;
    currentTo = document.getElementById('recordsTo').value;
    syncChips();
    var ledgerParams = new URLSearchParams();
    if (currentFrom) ledgerParams.set('from', currentFrom);
    if (currentTo) ledgerParams.set('to', currentTo);
    document.getElementById('printanLedgerExport').href = 'export.php' + (ledgerParams.toString() ? '?' + ledgerParams.toString() : '');
    var params = new URLSearchParams({ action: 'records', from: currentFrom, to: currentTo });
    showSkeleton();
    return requestJson('list.php?' + params.toString()).then(function (payload) {
      records = payload.records;
      page = 1;
      renderRecords();
    }).catch(function (error) {
      records = [];
      var body = document.getElementById('printRecordsBody');
      body.replaceChildren();
      stateRow(body, 'Could not load records', error.message, 'is-error');
      document.getElementById('recordsCount').textContent = '';
    });
  }

  function loadDailyMetrics() {
    var date = document.getElementById('dailySummaryDate').value;
    if (!date) return;
    var card = document.getElementById('dailyCard');
    card.classList.add('is-loading');
    document.getElementById('dailyMetricsMessage').textContent = 'Loading daily totals…';
    requestJson('list.php?action=metrics&date=' + encodeURIComponent(date))
      .then(function (payload) {
        document.getElementById('dailyTransactions').textContent = Number(payload.metrics.transactions).toLocaleString();
        document.getElementById('dailyPages').textContent = Number(payload.metrics.pages).toLocaleString();
        document.getElementById('dailyIncome').textContent = money(payload.metrics.income);
        document.getElementById('dailyMetricsMessage').textContent = date === TODAY ? 'Showing totals for today.' : 'Showing totals for ' + date + '.';
      })
      .catch(function (error) {
        document.getElementById('dailyMetricsMessage').textContent = error.message;
      })
      .finally(function () { card.classList.remove('is-loading'); });
  }

  /* ----- Quick date ranges ----- */
  function shiftDate(isoDate, days) {
    var d = new Date(isoDate + 'T00:00:00Z');
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
  }
  function rangeFor(key) {
    if (key === 'today') return [TODAY, TODAY];
    if (key === '7') return [shiftDate(TODAY, -6), TODAY];
    if (key === 'month') return [TODAY.slice(0, 8) + '01', TODAY];
    return ['', ''];
  }
  function syncChips() {
    var from = document.getElementById('recordsFrom').value;
    var to = document.getElementById('recordsTo').value;
    document.querySelectorAll('.printan-chip').forEach(function (chip) {
      var range = rangeFor(chip.dataset.range);
      chip.classList.toggle('is-active', range[0] === from && range[1] === to);
    });
  }
  document.querySelectorAll('.printan-chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
      var range = rangeFor(chip.dataset.range);
      document.getElementById('recordsFrom').value = range[0];
      document.getElementById('recordsTo').value = range[1];
      loadRecords();
    });
  });
  document.getElementById('clearFilters').addEventListener('click', function () {
    document.getElementById('recordsSearch').value = '';
    document.getElementById('recordsFrom').value = '';
    document.getElementById('recordsTo').value = '';
    loadRecords();
  });

  document.getElementById('dailySummaryDate').addEventListener('change', loadDailyMetrics);
  document.getElementById('printRecordsFilters').addEventListener('submit', function (event) {
    event.preventDefault();
  });
  document.getElementById('recordsFrom').addEventListener('change', loadRecords);
  document.getElementById('recordsTo').addEventListener('change', loadRecords);
  document.getElementById('recordsSearch').addEventListener('input', function () {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(function () {
      page = 1;
      renderRecords();
    }, 120);
  });
  document.getElementById('recordsPageSize').addEventListener('change', function (event) {
    pageSize = Number(event.target.value);
    page = 1;
    renderRecords();
  });
  document.getElementById('recordsPrevious').addEventListener('click', function () {
    page -= 1;
    renderRecords();
  });
  document.getElementById('recordsNext').addEventListener('click', function () {
    page += 1;
    renderRecords();
  });

  document.getElementById('exportPrintanPdf').addEventListener('click', function () {
    var matching = getVisibleRecords();
    if (!matching.length) {
      window.notify.info('No records to export', 'There are no matching records to export.');
      return;
    }
    var rows = matching.map(function (record) {
      return [
        record.date_time,
        record.student_no,
        record.full_name,
        record.program,
        record.year_level,
        record.section,
        flag(record.is_colored) ? 'Colored' : 'B&W',
        flag(record.own_paper) ? 'Brought own' : 'CSC paper',
        money(record.amount_paid)
      ];
    });
    var dateRange = currentFrom || currentTo
      ? (currentFrom || 'All dates') + ' to ' + (currentTo || 'All dates')
      : 'All dates';
    var subtitle = 'Date range: ' + dateRange;
    Promise.resolve().then(function () {
      return window.exportTablePdf({
        title: 'Printan ng Bayan Records',
        subtitle: subtitle,
        columns: ['Date / Time', 'Student Number', 'Name', 'Program', 'Year', 'Section', 'Color', 'Own Paper', 'Amount'],
        rows: rows,
        filename: 'printan-records.pdf',
        orientation: 'portrait'
      });
    }).catch(function (error) {
      window.notify.error('PDF export failed', error.message || 'Unable to prepare the PDF.');
    });
  });

  loadDailyMetrics();
  loadRecords();
});
</script>
<?php include '../../includes/footer.php'; ?>