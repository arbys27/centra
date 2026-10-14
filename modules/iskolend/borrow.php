<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
require_once '../../includes/db.php';

$pdo = get_pdo_connection();
$action = $_GET['action'] ?? '';
if ($action === 'catalog' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $raw_student_no = $_GET['student_no'] ?? '';
    $student_no = is_string($raw_student_no) ? normalize_student_number($raw_student_no) : '';
    if (!is_valid_student_number($student_no)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid student number format.']);
        exit;
    }
    $blacklist = get_active_blacklist($pdo, $student_no);
    if ($blacklist !== null) {
        echo json_encode(['blacklisted' => true, 'reason' => $blacklist['reason']]);
        exit;
    }
    $student_query = $pdo->prepare('SELECT student_no FROM students WHERE student_no = ?');
    $student_query->execute([$student_no]);
    if (!$student_query->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Student number not found. Please register the student first.']);
        exit;
    }
    $categories = $pdo->query('SELECT id, category_name FROM item_categories ORDER BY category_name')->fetchAll();
    $items = $pdo->query(
        'SELECT i.id, i.item_name, i.total_qty, i.available_qty, i.borrow_days,
                i.image_path, i.category_id, c.category_name
         FROM iskolend_items i
         LEFT JOIN item_categories c ON c.id = i.category_id
         ORDER BY c.category_name, i.item_name'
    )->fetchAll();
    $active_borrowings = get_active_iskolend_borrowings($pdo, $student_no);
    $borrowed_units = get_iskolend_borrowed_units($active_borrowings);
    $limit = get_iskolend_unit_limit($pdo);
    echo json_encode([
        'categories' => $categories,
        'items' => $items,
        'active_borrowings' => $active_borrowings,
        'borrowed_units' => $borrowed_units,
        'limit' => $limit,
        'remaining' => max(0, $limit - $borrowed_units),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if ($action !== '') {
    http_response_code(404);
    exit;
}

$base = '../../';
$page_title = 'ISKOLEND';
if (empty($_SESSION['iskolend_csrf_token'])) {
    $_SESSION['iskolend_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['iskolend_csrf_token'];
include '../../includes/header.php';
?>
<style>
/* ===== ISKOLEND Borrow – modern gray theme (scoped to .iskolend-page) ===== */
.iskolend-page {
  --pf-bg:        #f4f4f3;
  --pf-surface:   #ffffff;
  --pf-inset:     #f7f7f6;
  --pf-border:    #e3e3e1;
  --pf-border-2:  #d0d0cd;
  --pf-text:      #1c1c1c;
  --pf-muted:     #6c6c69;
  --pf-dark:      #1c1c1c;
  --pf-dark-hover:#333331;
  --pf-radius:    14px;
  --pf-radius-sm: 10px;
  --pf-focus:     0 0 0 3px rgba(28,28,28,.12);
  color: var(--pf-text);
  display: flex; flex-direction: column; gap: 16px;
}
.iskolend-page *, .iskolend-page *::before, .iskolend-page *::after { box-sizing: border-box; }
.iskolend-page .d-none { display: none !important; }
.iskolend-page [hidden] { display: none !important; }
.iskolend-page .text-secondary { color: var(--pf-muted); }
.iskolend-page .small { font-size: .82rem; }
.iskolend-page .mb-0 { margin-bottom: 0; }
.iskolend-page .mb-2 { margin-bottom: 8px; }

/* Cards */
.iskolend-page .card {
  background: var(--pf-surface); border: 1px solid var(--pf-border); border-radius: 18px;
  box-shadow: 0 1px 2px rgba(0,0,0,.04), 0 8px 24px rgba(0,0,0,.04);
}
.iskolend-page .iskolend-nav { padding: 8px; display: inline-flex; width: fit-content; max-width: 100%; border-radius: 14px; }
.iskolend-page .iskolend-borrow { padding: 28px; }

/* Nav (segmented) */
.iskolend-page .d-flex { display: flex; }
.iskolend-page .flex-wrap { flex-wrap: wrap; }
.iskolend-page .gap-2 { gap: 6px; }
.iskolend-page .iskolend-nav .btn {
  height: 38px; padding: 0 18px; font-size: .88rem;
  background: transparent; color: var(--pf-muted); border-color: transparent;
}
.iskolend-page .iskolend-nav .btn:hover { background: var(--pf-inset); color: var(--pf-text); border-color: transparent; }
.iskolend-page .iskolend-nav .btn:not(.btn-outline) { background: var(--pf-dark); color: #fff; }

/* Heading */
.iskolend-page .iskolend-page-heading {
  padding-bottom: 20px; margin-bottom: 24px; border-bottom: 1px solid var(--pf-border);
}
.iskolend-page .iskolend-page-heading h2 { font-size: 1.5rem; font-weight: 650; letter-spacing: -.01em; margin: 0 0 4px; }
.iskolend-page .iskolend-page-heading p { margin: 0; font-size: .92rem; line-height: 1.5; max-width: 60ch; }

/* Layout */
.iskolend-page .iskolend-borrow-layout {
  display: grid; grid-template-columns: minmax(280px, 380px) 1fr; gap: 24px; align-items: start;
}
.iskolend-page .iskolend-scan-panel,
.iskolend-page .iskolend-picker-panel { border-radius: var(--pf-radius); padding: 22px; min-width: 0; }
.iskolend-page .iskolend-scan-panel {
  background: var(--pf-surface); border: 1px solid var(--pf-border); position: sticky; top: 16px;
}
.iskolend-page .iskolend-picker-panel { background: var(--pf-surface); border: 1px solid var(--pf-border); }
.iskolend-page h3 { font-size: 1rem; font-weight: 650; margin: 0 0 16px; letter-spacing: -.005em; }

/* Labels & controls */
.iskolend-page label, .iskolend-page .form-label {
  display: block; font-size: .8rem; font-weight: 600; color: var(--pf-muted); margin-bottom: 6px;
}
.iskolend-page .form-control, .iskolend-page .form-select {
  width: 100%; height: 44px; padding: 0 14px; font: inherit; font-size: .95rem; color: var(--pf-text);
  background: var(--pf-surface); border: 1px solid var(--pf-border-2); border-radius: var(--pf-radius-sm);
  outline: none; transition: border-color .15s, box-shadow .15s;
}
.iskolend-page .form-select {
  appearance: none; -webkit-appearance: none; padding-right: 38px; cursor: pointer;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none' stroke='%236c6c69' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M1 1.5l5 5 5-5'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 14px center;
}
.iskolend-page .form-control:focus, .iskolend-page .form-select:focus { border-color: var(--pf-dark); box-shadow: var(--pf-focus); }
.iskolend-page .form-control::placeholder { color: #a5a5a2; }
.iskolend-page .form-select:disabled { background-color: var(--pf-inset); color: var(--pf-muted); cursor: not-allowed; }
.iskolend-page .scan-input { height: 52px; font-size: 1.05rem; letter-spacing: .04em; font-variant-numeric: tabular-nums; border-width: 1.5px; }
.iskolend-page .scan-error { margin-top: 8px; font-size: .85rem; color: #8a2d2d; }
#studentInfo:not(:empty) { margin-top: 16px; }

/* Alerts */
.iskolend-page .alert {
  display: block; padding: 12px 14px; margin-top: 14px; border-radius: var(--pf-radius-sm);
  font-size: .88rem; line-height: 1.5; border: 1px solid var(--pf-border);
  background: var(--pf-surface); color: var(--pf-muted);
}
.iskolend-page .alert-error { background: #f6eeee; border-color: #e6cfcf; color: #7a2a2a; }
.iskolend-page #borrowScanNote { display: flex; align-items: center; gap: 10px; }
.iskolend-page #borrowScanNote.d-none { display: none !important; }
.iskolend-page #borrowScanNote::before {
  content: ""; width: 8px; height: 8px; border-radius: 50%; background: #a5a5a2; flex: none;
  animation: pf-pulse 1.6s ease-in-out infinite;
}
@keyframes pf-pulse { 50% { opacity: .35; } }
.iskolend-page #borrowLockedNote {
  margin: 0; padding: 40px 20px; text-align: center; background: var(--pf-inset);
  border: 1.5px dashed var(--pf-border-2); border-radius: var(--pf-radius); color: var(--pf-muted);
}

/* Category pills + search */
.iskolend-page .iskolend-picker-tools { display: grid; gap: 14px; margin-bottom: 14px; }
.iskolend-page .iskolend-category-tabs { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 4px; scrollbar-width: thin; }
.iskolend-page .iskolend-category-pill {
  flex: none; height: 34px; padding: 0 16px; font: inherit; font-size: .84rem; font-weight: 600;
  color: var(--pf-muted); background: var(--pf-inset); border: 1px solid var(--pf-border);
  border-radius: 999px; cursor: pointer; transition: background .15s, color .15s, border-color .15s;
}
.iskolend-page .iskolend-category-pill:hover { border-color: var(--pf-dark); color: var(--pf-text); }
.iskolend-page .iskolend-category-pill.active { background: var(--pf-dark); border-color: var(--pf-dark); color: #fff; }
.iskolend-page .iskolend-category-pill:focus-visible { outline: none; box-shadow: var(--pf-focus); }

/* Item grid */
.iskolend-page .iskolend-item-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px;
  max-height: 440px; overflow-y: auto; padding: 4px; margin: -4px;
}
.iskolend-page .iskolend-item-card {
  display: flex; flex-direction: column; align-items: stretch; gap: 4px; text-align: left;
  padding: 10px; font: inherit; color: var(--pf-text); background: var(--pf-surface);
  border: 1px solid var(--pf-border); border-radius: var(--pf-radius); cursor: pointer;
  transition: border-color .15s, box-shadow .15s;
}
.iskolend-page .iskolend-item-card img {
  width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: var(--pf-radius-sm);
  background: var(--pf-inset); margin-bottom: 6px;
}
.iskolend-page .iskolend-item-card strong { font-size: .9rem; font-weight: 650; line-height: 1.3; }
.iskolend-page .iskolend-item-category { font-size: .75rem; color: var(--pf-muted); }
.iskolend-page .iskolend-item-stock {
  align-self: flex-start; margin-top: 4px; padding: 2px 8px; font-size: .72rem; font-weight: 600;
  background: var(--pf-inset); border: 1px solid var(--pf-border); border-radius: 999px; color: var(--pf-text);
}
.iskolend-page .iskolend-item-card:hover:not(:disabled) { border-color: var(--pf-dark); }
.iskolend-page .iskolend-item-card:focus-visible { outline: none; box-shadow: var(--pf-focus); border-color: var(--pf-dark); }
.iskolend-page .iskolend-item-card.selected { border-color: var(--pf-dark); box-shadow: 0 0 0 2px var(--pf-dark); }
.iskolend-page .iskolend-item-card.selected .iskolend-item-stock { background: var(--pf-dark); border-color: var(--pf-dark); color: #fff; }
.iskolend-page .iskolend-item-card:disabled { cursor: not-allowed; opacity: .55; }
.iskolend-page .iskolend-item-card:disabled img { filter: grayscale(1); }

/* Borrow controls */
.iskolend-page .iskolend-borrow-controls {
  display: grid; grid-template-columns: 110px 1fr; gap: 16px; margin-top: 20px; padding-top: 20px;
  border-top: 1px solid var(--pf-border);
}
.iskolend-page .iskolend-due-date {
  display: flex; align-items: center; height: 44px; padding: 0 14px; font-size: .92rem; font-weight: 600;
  background: var(--pf-inset); border: 1px solid var(--pf-border); border-radius: var(--pf-radius-sm);
}

/* Buttons */
.iskolend-page .btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  height: 46px; padding: 0 20px; font: inherit; font-size: .92rem; font-weight: 600;
  color: #fff; background: var(--pf-dark); border: 1px solid var(--pf-dark);
  border-radius: var(--pf-radius-sm); cursor: pointer; text-decoration: none;
  transition: background .15s, transform .1s;
}
.iskolend-page .btn:hover { background: var(--pf-dark-hover); }
.iskolend-page .btn:active { transform: translateY(1px); }
.iskolend-page .btn:focus-visible { outline: none; box-shadow: var(--pf-focus); }
.iskolend-page .btn:disabled { opacity: .45; cursor: not-allowed; }
.iskolend-page .btn.w-100 { width: 100%; }
.iskolend-page .btn.mt-3 { margin-top: 20px; }
.iskolend-page .btn-outline { background: var(--pf-surface); color: var(--pf-text); border-color: var(--pf-border-2); }
.iskolend-page .btn-outline:hover { background: var(--pf-inset); border-color: var(--pf-dark); }
.iskolend-page .spinner-border {
  width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff;
  border-radius: 50%; animation: pf-spin .7s linear infinite;
}
@keyframes pf-spin { to { transform: rotate(360deg); } }

/* Responsive */
@media (max-width: 900px) {
  .iskolend-page .iskolend-borrow-layout { grid-template-columns: 1fr; }
  .iskolend-page .iskolend-scan-panel { position: static; }
}
@media (max-width: 600px) {
  .iskolend-page .iskolend-borrow { padding: 18px; }
  .iskolend-page .iskolend-borrow-controls { grid-template-columns: 1fr; }
  .iskolend-page .iskolend-item-grid { grid-template-columns: repeat(2, 1fr); max-height: 380px; }
}
@media (prefers-reduced-motion: reduce) {
  .iskolend-page * { animation: none !important; transition: none !important; }
}
</style>

<div class="iskolend-page">
  <div class="card fade-in-up iskolend-nav">
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn" href="borrow.php">Borrow</a>
      <a class="btn btn-outline" href="return.php">Return</a>
      <a class="btn btn-outline" href="inventory.php">Inventory</a>
      <a class="btn btn-outline" href="list.php">View Records</a>
    </div>
  </div>

  <section class="card fade-in-up iskolend-borrow">
    <div class="iskolend-page-heading">
      <div><h2>Borrow Items</h2><p class="text-secondary mb-0">Scan a student ID to begin.</p></div>
    </div>
    <div class="iskolend-borrow-layout">
      <section class="iskolend-scan-panel" aria-labelledby="iskolendStudentTitle">
        <h3 id="iskolendStudentTitle">Scan Student ID Barcode</h3>
        <div class="scanner-wrap">
          <label for="studentScan" class="form-label">Student Number</label>
          <input class="form-control scan-input" type="text" id="studentScan" autocomplete="off" autofocus placeholder="0000-00000-BN-0" aria-describedby="scanError">
          <div class="scan-error" id="scanError" aria-live="polite" hidden></div>
        </div>
        <div id="studentInfo" aria-live="polite"></div>
        <div class="alert alert-info" id="borrowScanNote">Waiting for a student ID scan.</div>
        <div class="alert alert-error d-none" id="activeLimitNote" role="alert"></div>
      </section>

      <section class="iskolend-picker-panel" aria-labelledby="iskolendPickerTitle">
        <h3 id="iskolendPickerTitle">Choose an Item</h3>
        <div id="borrowLockedNote" class="alert alert-info">Scan an active student ID before selecting inventory.</div>
        <form id="borrowForm" action="save_borrow.php" method="post" class="d-none">
          <input type="hidden" name="student_no" id="f_student_no">
          <input type="hidden" name="item_id" id="borrowItemId">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
          <div class="iskolend-picker-tools">
            <div id="categoryTabs" class="iskolend-category-tabs" role="tablist" aria-label="Item categories"></div>
            <div>
              <label for="itemSearch" class="form-label">Search items</label>
              <input type="search" class="form-control" id="itemSearch" placeholder="Search inventory">
            </div>
          </div>
          <div class="small text-secondary mb-2" id="itemGridMessage" aria-live="polite"></div>
          <div id="itemGrid" class="iskolend-item-grid"></div>
          <div class="iskolend-borrow-controls">
            <div>
              <label for="borrowQuantity" class="form-label">Quantity</label>
              <input class="form-control" type="number" name="quantity" id="borrowQuantity" min="1" step="1" required disabled placeholder="Choose an item first">
            </div>
            <div>
              <label class="form-label">Due date</label>
              <div class="iskolend-due-date" id="borrowDueDate">Select an item</div>
            </div>
          </div>
          <button class="btn w-100 mt-3" type="submit" id="borrowSubmit" disabled>
            <span class="spinner-border spinner-border-sm me-2 d-none" aria-hidden="true"></span>
            <span class="button-label">Confirm Borrow</span>
          </button>
        </form>
      </section>
    </div>
  </section>
</div>
<script src="<?php echo $base; ?>assets/js/student-scan.js"></script>
<script src="<?php echo $base; ?>assets/js/student-card.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  'use strict';
  var catalog = { categories: [], items: [] };
  var currentCategory = 'all';
  var selectedItem = null;
  var borrowLimit = 0;
  var borrowedUnits = 0;
  var remainingUnits = 0;
  var canBorrow = false;
  var submitting = false;
  var currentStudentNo = '';
  var currentStudent = null;
  var form = document.getElementById('borrowForm');
  var submitButton = document.getElementById('borrowSubmit');

  function escapeText(value) {
    return String(value === null || value === undefined ? '' : value);
  }

  function imageSource(path) {
    var source = escapeText(path).trim();
    if (!source) return '../../assets/img/item-placeholder.svg';
    return /^(https?:)?\/\//i.test(source) || source.charAt(0) === '/'
      ? source
      : '../../' + source.replace(/^(\.\/)+/, '');
  }

  function updateSubmitState() {
    var quantity = document.getElementById('borrowQuantity');
    var value = quantity.valueAsNumber;
    var cap = selectedItem ? Math.min(Number(selectedItem.available_qty), remainingUnits) : 0;
    submitButton.disabled = submitting || !canBorrow || !selectedItem || !Number.isInteger(value) ||
      value < 1 || value > cap || !quantity.validity.valid;
  }

  function drawCategories() {
    var tabs = document.getElementById('categoryTabs');
    tabs.replaceChildren();
    var allCategories = [{ id: 'all', category_name: 'All' }].concat(catalog.categories);
    allCategories.forEach(function (category) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'iskolend-category-pill' + (String(category.id) === String(currentCategory) ? ' active' : '');
      button.textContent = category.category_name;
      button.setAttribute('role', 'tab');
      button.setAttribute('aria-selected', String(String(category.id) === String(currentCategory)));
      button.addEventListener('click', function () {
        currentCategory = String(category.id);
        drawCategories();
        drawItems();
      });
      tabs.appendChild(button);
    });
  }

  function drawItems() {
    var grid = document.getElementById('itemGrid');
    var search = document.getElementById('itemSearch').value.trim().toLocaleLowerCase();
    var items = catalog.items.filter(function (item) {
      var categoryMatches = currentCategory === 'all' || String(item.category_id) === currentCategory;
      var searchMatches = !search || (item.item_name + ' ' + (item.category_name || '')).toLocaleLowerCase().includes(search);
      return categoryMatches && searchMatches;
    });
    grid.replaceChildren();
    document.getElementById('itemGridMessage').textContent = items.length
      ? 'You can borrow up to ' + remainingUnits + ' more item(s).'
      : 'No items match this category or search.';
    items.forEach(function (item) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'iskolend-item-card' + (Number(item.available_qty) < 1 ? ' is-out-of-stock' : '') +
        (selectedItem && Number(selectedItem.id) === Number(item.id) ? ' selected' : '');
      button.disabled = Number(item.available_qty) < 1 || !canBorrow;
      button.setAttribute('aria-pressed', String(selectedItem && Number(selectedItem.id) === Number(item.id)));
      var image = document.createElement('img');
      image.src = imageSource(item.image_path);
      image.alt = '';
      image.loading = 'lazy';
      image.onerror = function () { this.onerror = null; this.src = '../../assets/img/item-placeholder.svg'; };
      var title = document.createElement('strong');
      title.textContent = item.item_name;
      var category = document.createElement('span');
      category.className = 'iskolend-item-category';
      category.textContent = item.category_name || 'Uncategorized';
      var stock = document.createElement('span');
      stock.className = 'iskolend-item-stock';
      stock.textContent = Number(item.available_qty) > 0 ? Number(item.available_qty) + ' available' : 'Out of stock';
      button.append(image, title, category, stock);
      button.addEventListener('click', function () { selectItem(item); });
      grid.appendChild(button);
    });
  }

  function selectItem(item) {
    selectedItem = item;
    document.getElementById('borrowItemId').value = item.id;
    var quantity = document.getElementById('borrowQuantity');
    var cap = Math.min(Number(item.available_qty), remainingUnits);
    quantity.min = '1';
    quantity.max = String(cap);
    quantity.step = '1';
    quantity.value = cap > 0 ? '1' : '';
    quantity.disabled = cap < 1;
    var dueDate = new Date();
    dueDate.setDate(dueDate.getDate() + Number(item.borrow_days || 7));
    document.getElementById('borrowDueDate').textContent = dueDate.toLocaleDateString(undefined, {
      year: 'numeric', month: 'short', day: 'numeric'
    }) + ' (' + Number(item.borrow_days || 7) + ' days)';
    drawItems();
    updateSubmitState();
  }

  function setBorrowAccess(enabled) {
    form.classList.toggle('d-none', !enabled);
    document.getElementById('borrowLockedNote').classList.toggle('d-none', enabled);
  }

  function loadCatalog(studentNo) {
    return fetch('borrow.php?action=catalog&student_no=' + encodeURIComponent(studentNo))
      .then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok) throw new Error(payload.error || 'Could not load inventory.');
          return payload;
        });
      }).then(function (payload) {
        if (payload.blacklisted) {
          showBlacklistAlert(payload.reason);
          setBorrowAccess(false);
          return;
        }
        catalog = payload;
        currentStudentNo = studentNo;
        borrowLimit = Number(payload.limit);
        borrowedUnits = Number(payload.borrowed_units);
        remainingUnits = Number(payload.remaining);
        if (!Number.isInteger(borrowLimit) || borrowLimit < 1 ||
            !Number.isInteger(borrowedUnits) || borrowedUnits < 0 ||
            !Number.isInteger(remainingUnits) || remainingUnits < 0) {
          throw new Error('The student borrowing allowance could not be loaded.');
        }
        canBorrow = remainingUnits > 0;
        if (currentStudent) {
          showStudentCard(Object.assign({}, currentStudent, {
            active_borrowings: payload.active_borrowings,
            borrowed_units: borrowedUnits,
            limit: borrowLimit,
            remaining: remainingUnits
          }));
        }
        document.getElementById('f_student_no').value = studentNo;
        document.getElementById('borrowScanNote').classList.add('d-none');
        var limitNote = document.getElementById('activeLimitNote');
        limitNote.classList.toggle('d-none', remainingUnits > 0);
        if (!canBorrow) {
          limitNote.textContent = 'Limit reached (' + borrowedUnits + ' of ' + borrowLimit +
            '). Return an item before borrowing again.';
        }
        selectedItem = null;
        document.getElementById('borrowItemId').value = '';
        document.getElementById('borrowQuantity').value = '';
        document.getElementById('borrowQuantity').disabled = true;
        document.getElementById('borrowQuantity').removeAttribute('max');
        document.getElementById('borrowDueDate').textContent = 'Select an item';
        currentCategory = 'all';
        drawCategories();
        drawItems();
        setBorrowAccess(true);
      });
  }

  function refreshCurrentStudent() {
    return fetch('<?php echo $base; ?>modules/students/lookup.php?student_no=' + encodeURIComponent(currentStudentNo))
      .then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok || !payload.found) throw new Error(payload.message || 'Student refresh failed.');
          if (payload.blacklisted) throw new Error('This student is blacklisted and cannot borrow CSC items.');
          return payload;
        });
      }).then(function (student) {
        currentStudent = student;
        showStudentCard(student);
        return loadCatalog(currentStudentNo);
      });
  }

  initStudentScanField(document.getElementById('studentScan'), function (studentNo) {
    document.getElementById('studentInfo').replaceChildren();
    document.getElementById('activeLimitNote').classList.add('d-none');
    setBorrowAccess(false);
    return fetch('<?php echo $base; ?>modules/students/lookup.php?student_no=' + encodeURIComponent(studentNo))
      .then(function (response) {
        if (!response.ok) throw new Error('Student lookup failed.');
        return response.json();
      }).then(function (student) {
        if (student.blacklisted) {
          showBlacklistAlert(student.reason, student);
          window.notify.error('Borrowing blocked', 'This student is blacklisted and cannot borrow CSC items.');
          document.getElementById('borrowScanNote').classList.add('d-none');
          return;
        }
        if (!student.found) {
          var error = document.createElement('div');
          error.className = 'alert alert-error mt-3';
          error.textContent = 'Student number not found. Please register the student first.';
          document.getElementById('studentInfo').appendChild(error);
          window.notify.error('Student not found', error.textContent);
          document.getElementById('borrowScanNote').classList.remove('d-none');
          return;
        }
        currentStudent = student;
        showStudentCard(student);
        return loadCatalog(student.student_no);
      });
  });

  document.getElementById('itemSearch').addEventListener('input', drawItems);
  document.getElementById('borrowQuantity').addEventListener('input', updateSubmitState);
  document.getElementById('borrowQuantity').addEventListener('change', updateSubmitState);
  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (submitting || !canBorrow || !selectedItem || !form.reportValidity()) return;
    submitting = true;
    submitButton.disabled = true;
    submitButton.querySelector('.spinner-border').classList.remove('d-none');
    submitButton.querySelector('.button-label').textContent = 'Saving…';
    fetch(form.action, {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: new FormData(form)
    }).then(function (response) {
      return response.json().then(function (payload) {
        if (!response.ok) throw new Error(payload.error || 'Unable to save this borrowing.');
        return payload;
      });
    }).then(function (payload) {
      window.notify.success('Borrow recorded', payload.student_name + ' · ' + payload.item_name +
        ' × ' + payload.quantity + ' · now ' + payload.borrowed_units + ' of ' + payload.limit +
        ' items out', { focusTarget: document.getElementById('studentScan') });
      form.reset();
      document.getElementById('borrowItemId').value = '';
      selectedItem = null;
      return refreshCurrentStudent().catch(function (error) {
        window.notify.error('Borrow recorded, student refresh failed', error.message || 'Unable to refresh the student allowance.', { focusTarget: document.getElementById('studentScan') });
      });
    }).catch(function (error) {
      window.notify.error('Borrow could not be recorded', error.message || 'Unable to save this borrowing.', { focusTarget: document.getElementById('studentScan') });
    }).finally(function () {
      submitting = false;
      submitButton.querySelector('.spinner-border').classList.add('d-none');
      submitButton.querySelector('.button-label').textContent = 'Confirm Borrow';
      updateSubmitState();
    });
  });
});
</script>
<?php include '../../includes/footer.php'; ?>