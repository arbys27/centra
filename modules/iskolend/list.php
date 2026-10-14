<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/db.php';

$pdo = get_pdo_connection();
$valid_date = static function ($value): bool {
    if (!is_string($value) || $value === '') {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

if (($_GET['action'] ?? '') === 'records') {
    header('Content-Type: application/json; charset=utf-8');
    $search = $_GET['q'] ?? '';
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    $status = $_GET['status'] ?? 'all';
    $raw_page = $_GET['page'] ?? '1';
    $raw_page_size = $_GET['page_size'] ?? '10';
    $page = is_string($raw_page) ? filter_var($raw_page, FILTER_VALIDATE_INT) : false;
    $page_size = is_string($raw_page_size) ? filter_var($raw_page_size, FILTER_VALIDATE_INT) : false;
    $export_all = ($_GET['export'] ?? '') === '1';
    $allowed_statuses = ['all', 'borrowed', 'overdue', 'returned', 'damaged', 'lost'];

    if (!is_string($search) || mb_strlen($search) > 200 ||
        !is_string($from) || ($from !== '' && !$valid_date($from)) ||
        !is_string($to) || ($to !== '' && !$valid_date($to)) ||
        ($from !== '' && $to !== '' && $from > $to) ||
        !is_string($status) || !in_array($status, $allowed_statuses, true) ||
        $page === false || $page < 1 ||
        $page_size === false || !in_array($page_size, [10, 25, 50], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Choose a valid search, status, date range, and page size.']);
        exit;
    }

    $status_expression = "CASE
        WHEN b.status = 'Borrowed' AND b.date_returned IS NULL AND b.due_date < CURDATE() THEN 'overdue'
        WHEN b.status = 'Borrowed' AND b.date_returned IS NULL THEN 'borrowed'
        WHEN b.condition_on_return = 'Damaged' THEN 'damaged'
        WHEN b.condition_on_return = 'Lost' THEN 'lost'
        ELSE 'returned'
    END";
    $conditions = [];
    $parameters = [];
    if ($search !== '') {
        $conditions[] = '(b.student_no LIKE ? OR b.full_name LIKE ?)';
        $pattern = '%' . $search . '%';
        $parameters[] = $pattern;
        $parameters[] = $pattern;
    }
    if ($from !== '') {
        $conditions[] = 'b.date_borrowed >= ?';
        $parameters[] = $from . ' 00:00:00';
    }
    if ($to !== '') {
        $conditions[] = 'b.date_borrowed < DATE_ADD(?, INTERVAL 1 DAY)';
        $parameters[] = $to . ' 00:00:00';
    }
    if ($status !== 'all') {
        $conditions[] = $status_expression . ' = ?';
        $parameters[] = $status;
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    try {
        $count_query = $pdo->prepare(
            "SELECT COUNT(*)
             FROM iskolend_borrow b
             LEFT JOIN students s ON s.student_no = b.student_no
             {$where}"
        );
        $count_query->execute($parameters);
        $total = (int)$count_query->fetchColumn();
        $page_count = max(1, (int)ceil($total / $page_size));
        $page = min($page, $page_count);

        $query = $pdo->prepare(
            "SELECT b.student_no, b.full_name, b.program, b.year_level,
                    COALESCE(s.section, '') AS section, b.item_name,
                    COALESCE(b.quantity, b.qty_borrowed, 1) AS quantity,
                    b.date_borrowed, b.due_date, b.date_returned,
                    b.condition_on_return, {$status_expression} AS record_status
             FROM iskolend_borrow b
             LEFT JOIN students s ON s.student_no = b.student_no
             {$where}
             ORDER BY b.date_borrowed DESC, b.id DESC" .
             ($export_all ? '' : ' LIMIT ? OFFSET ?')
        );
        foreach ($parameters as $index => $parameter) {
            $query->bindValue($index + 1, $parameter, PDO::PARAM_STR);
        }
        if (!$export_all) {
            $query->bindValue(count($parameters) + 1, $page_size, PDO::PARAM_INT);
            $query->bindValue(count($parameters) + 2, ($page - 1) * $page_size, PDO::PARAM_INT);
        }
        $query->execute();
        $records = $query->fetchAll();
        foreach ($records as &$record) {
            $record['quantity'] = (int)$record['quantity'];
            $record['record_status'] = (string)$record['record_status'];
        }
        unset($record);
    } catch (PDOException $exception) {
        error_log('ISKOLEND records query failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Unable to load borrowing records right now.']);
        exit;
    }

    echo json_encode([
        'records' => $records,
        'total' => $total,
        'page' => $page,
        'page_size' => $page_size,
        'page_count' => $page_count,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$base = '../../';
$page_title = 'ISKOLEND Records';
include __DIR__ . '/../../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo $base; ?>assets/css/iskolend-records.css">
<style>
/* Page-level layout fixes. Scoped to .iskolend-records-page and placed after the
   external stylesheet so they win. Table and badge styling stays in iskolend-records.css. */

/* Heading card: title + subtitle truly centered, Export PDF on the right */
.iskolend-records-page .iskolend-records-heading {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  gap: 16px;
  padding: 26px 28px;
  text-align: center;
}
.iskolend-records-page .iskolend-records-heading::before { content: ""; }
.iskolend-records-page .iskolend-records-title { min-width: 0; }
.iskolend-records-page .iskolend-records-title h2 {
  margin: 0 0 6px;
  font-size: 1.5rem;
  font-weight: 650;
  letter-spacing: -.01em;
  line-height: 1.25;
}
.iskolend-records-page .iskolend-records-title p {
  margin: 0;
  color: #6c6c69;
  font-size: .95rem;
  line-height: 1.5;
}
.iskolend-records-page .iskolend-export-btn { justify-self: end; }

/* Filter row: one baseline, equal control heights, consistent labels */
.iskolend-records-page .iskolend-record-filters {
  display: grid;
  grid-template-columns: minmax(220px, 2fr) minmax(140px, 1fr) minmax(140px, 1fr) minmax(140px, 1fr) minmax(120px, .8fr);
  gap: 16px;
  align-items: end;
  margin-bottom: 20px;
}
.iskolend-records-page .iskolend-record-filters > div { min-width: 0; }
.iskolend-records-page .iskolend-record-filters .form-label {
  display: block;
  margin: 0 0 6px;
  font-size: .8rem;
  font-weight: 600;
  line-height: 1.2;
  color: #6c6c69;
}
.iskolend-records-page .iskolend-record-filters .form-control,
.iskolend-records-page .iskolend-record-filters .form-select {
  width: 100%;
  height: 44px;
  margin: 0;
  padding-top: 0;
  padding-bottom: 0;
  font-size: .92rem;
}

@media (max-width: 1240px) {
  .iskolend-records-page .iskolend-record-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .iskolend-records-page .iskolend-record-search { grid-column: 1 / -1; }
}
@media (max-width: 720px) {
  .iskolend-records-page .iskolend-records-heading { grid-template-columns: 1fr; padding: 20px; }
  .iskolend-records-page .iskolend-records-heading::before { display: none; }
  .iskolend-records-page .iskolend-export-btn { justify-self: center; }
}
@media (max-width: 560px) {
  .iskolend-records-page .iskolend-record-filters { grid-template-columns: 1fr; }
}
</style>

<div class="iskolend-records-page">
  <div class="card fade-in-up iskolend-records-heading">
    <div class="iskolend-records-title">
      <h2>ISKOLEND View Records</h2>
      <p>Search, filter, and export borrowing and return history.</p>
    </div>
    <button class="btn iskolend-export-btn" type="button" id="exportIskolendPdf">Export PDF</button>
  </div>

  <section class="card fade-in-up">
    <form id="recordsFilters" class="iskolend-record-filters">
      <div class="iskolend-record-search">
        <label for="recordsSearch" class="form-label">Student number or name</label>
        <input class="form-control" type="search" id="recordsSearch" placeholder="Search student number or name" maxlength="200">
      </div>
      <div>
        <label for="recordsStatus" class="form-label">Status</label>
        <select class="form-select" id="recordsStatus">
          <option value="all">All statuses</option>
          <option value="borrowed">Borrowed</option>
          <option value="overdue">Overdue</option>
          <option value="returned">Returned</option>
          <option value="damaged">Damaged</option>
          <option value="lost">Lost</option>
        </select>
      </div>
      <div>
        <label for="recordsFrom" class="form-label">Borrowed from</label>
        <input class="form-control" type="date" id="recordsFrom">
      </div>
      <div>
        <label for="recordsTo" class="form-label">Borrowed to</label>
        <input class="form-control" type="date" id="recordsTo">
      </div>
      <div>
        <label for="recordsPageSize" class="form-label">Rows</label>
        <select class="form-select" id="recordsPageSize">
          <option value="10">10 / page</option>
          <option value="25">25 / page</option>
          <option value="50">50 / page</option>
        </select>
      </div>
    </form>
    <div class="table-wrap">
      <table class="table table-hover align-middle iskolend-record-table">
        <thead>
          <tr>
            <th>Student number</th><th>Name</th><th>Program</th><th>Year and section</th>
            <th>Item</th><th>Quantity</th><th>Date borrowed</th><th>Due date</th>
            <th>Date returned</th><th>Condition</th><th>Status</th>
          </tr>
        </thead>
        <tbody id="recordsBody"><tr><td colspan="11" class="iskolend-record-state">Loading records…</td></tr></tbody>
      </table>
    </div>
    <div class="iskolend-record-pagination">
      <span class="text-secondary" id="recordsCount" aria-live="polite"></span>
      <div class="btn-group" role="group" aria-label="Borrowing record pages">
        <button class="btn btn-outline btn-sm" type="button" id="recordsPrevious" disabled>Previous</button>
        <button class="btn btn-outline btn-sm" type="button" id="recordsNext" disabled>Next</button>
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
  var records = [];
  var page = 1;
  var pageSize = 10;
  var pageCount = 1;
  var searchTimer = null;
  var latestRequest = 0;
  var body = document.getElementById('recordsBody');

  function statusLabel(status) {
    return String(status || '').replace(/\b\w/g, function (letter) { return letter.toUpperCase(); });
  }

  function formatDate(value, includeTime) {
    if (!value) return '—';
    var source = String(value);
    var date = new Date(source.length === 10 ? source + 'T00:00:00' : source.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return source;
    return date.toLocaleString(undefined, includeTime
      ? { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }
      : { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function filteredUrl(exportAll) {
    var params = new URLSearchParams({
      action: 'records',
      q: document.getElementById('recordsSearch').value.trim(),
      status: document.getElementById('recordsStatus').value,
      from: document.getElementById('recordsFrom').value,
      to: document.getElementById('recordsTo').value,
      page: String(page),
      page_size: String(pageSize)
    });
    if (exportAll) params.set('export', '1');
    return 'list.php?' + params.toString();
  }

  function appendCell(row, value) {
    var cell = document.createElement('td');
    cell.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
    row.appendChild(cell);
    return cell;
  }

  function renderRows() {
    body.replaceChildren();
    if (!records.length) {
      var emptyRow = document.createElement('tr');
      var emptyCell = appendCell(emptyRow, 'No borrowing records match these filters.');
      emptyCell.colSpan = 11;
      emptyCell.className = 'iskolend-record-state';
      body.appendChild(emptyRow);
      return;
    }
    records.forEach(function (record) {
      var row = document.createElement('tr');
      appendCell(row, record.student_no);
      appendCell(row, record.full_name);
      appendCell(row, record.program);
      appendCell(row, [record.year_level, record.section].filter(Boolean).join(' / '));
      appendCell(row, record.item_name);
      appendCell(row, record.quantity);
      appendCell(row, formatDate(record.date_borrowed, true));
      appendCell(row, formatDate(record.due_date, false));
      appendCell(row, formatDate(record.date_returned, true));
      appendCell(row, record.condition_on_return);
      var statusCell = document.createElement('td');
      var badge = document.createElement('span');
      badge.className = 'iskolend-status-badge is-' + record.record_status;
      badge.textContent = statusLabel(record.record_status);
      statusCell.appendChild(badge);
      row.appendChild(statusCell);
      body.appendChild(row);
    });
  }

  function showLoadError(error, userAction) {
    body.replaceChildren();
    var row = document.createElement('tr');
    var cell = appendCell(row, error.message || 'Unable to load borrowing records.');
    cell.colSpan = 11;
    cell.className = 'iskolend-record-state is-error';
    body.appendChild(row);
    if (userAction) window.notify.error('Records could not be loaded', error.message || 'Unable to load borrowing records.');
  }

  function loadRecords(userAction) {
    var requestNumber = ++latestRequest;
    body.innerHTML = '<tr><td colspan="11" class="iskolend-record-state">Loading records…</td></tr>';
    return fetch(filteredUrl(false)).then(function (response) {
      return response.json().then(function (payload) {
        if (!response.ok) throw new Error(payload.error || 'Unable to load borrowing records.');
        return payload;
      });
    }).then(function (payload) {
      if (requestNumber !== latestRequest) return;
      records = payload.records || [];
      page = Number(payload.page) || 1;
      pageCount = Number(payload.page_count) || 1;
      renderRows();
      document.getElementById('recordsCount').textContent =
        payload.total + ' record(s) · Page ' + page + ' of ' + pageCount;
      document.getElementById('recordsPrevious').disabled = page <= 1;
      document.getElementById('recordsNext').disabled = page >= pageCount;
    }).catch(function (error) {
      if (requestNumber === latestRequest) showLoadError(error, userAction);
    });
  }

  function filterChanged() {
    page = 1;
    loadRecords(true);
  }

  document.getElementById('recordsFilters').addEventListener('submit', function (event) {
    event.preventDefault();
  });
  document.getElementById('recordsSearch').addEventListener('input', function () {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(filterChanged, 180);
  });
  ['recordsStatus', 'recordsFrom', 'recordsTo', 'recordsPageSize'].forEach(function (id) {
    document.getElementById(id).addEventListener('change', function (event) {
      if (id === 'recordsPageSize') pageSize = Number(event.target.value);
      filterChanged();
    });
  });
  document.getElementById('recordsPrevious').addEventListener('click', function () {
    if (page > 1) { page -= 1; loadRecords(true); }
  });
  document.getElementById('recordsNext').addEventListener('click', function () {
    if (page < pageCount) { page += 1; loadRecords(true); }
  });
  document.getElementById('exportIskolendPdf').addEventListener('click', function () {
    var paramsUrl = new URL(filteredUrl(true), window.location.href);
    paramsUrl.searchParams.set('page', '1');
    fetch(paramsUrl.toString()).then(function (response) {
      return response.json().then(function (payload) {
        if (!response.ok) throw new Error(payload.error || 'Unable to load records for PDF export.');
        return payload;
      });
    }).then(function (payload) {
      if (!payload.records.length) {
        window.notify.info('No records to export', 'There are no matching records to export.');
        return;
      }
      var rows = payload.records.map(function (record) {
        return [
          record.student_no,
          record.full_name,
          record.program,
          [record.year_level, record.section].filter(Boolean).join(' / '),
          record.item_name,
          String(record.quantity),
          formatDate(record.date_borrowed, true),
          formatDate(record.due_date, false),
          formatDate(record.date_returned, true),
          record.condition_on_return || '—',
          statusLabel(record.record_status)
        ];
      });
      return window.exportTablePdf({
        title: 'ISKOLEND Borrowing Records',
        subtitle: 'Search: ' + (document.getElementById('recordsSearch').value.trim() || 'All students') +
          ' · Status: ' + statusLabel(document.getElementById('recordsStatus').value) +
          ' · Borrowed: ' + (document.getElementById('recordsFrom').value || 'Any date') +
          ' to ' + (document.getElementById('recordsTo').value || 'Any date'),
        columns: [
          'Student number', 'Name', 'Program', 'Year and section', 'Item', 'Quantity',
          'Date borrowed', 'Due date', 'Date returned', 'Condition', 'Status'
        ],
        rows: rows,
        filename: 'iskolend-records.pdf',
        orientation: 'landscape'
      });
    }).catch(function (error) {
      window.notify.error('PDF export failed', error.message || 'Unable to prepare the PDF.');
    });
  });

  loadRecords(false);
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>