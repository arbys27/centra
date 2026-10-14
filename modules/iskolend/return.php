<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
require_once '../../includes/db.php';

$base = '../../';
$page_title = 'ISKOLEND';
if (empty($_SESSION['iskolend_csrf_token'])) {
    $_SESSION['iskolend_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['iskolend_csrf_token'];
include '../../includes/header.php';
?>
<style>
/* ===== ISKOLEND Return – modern gray theme (scoped to .iskolend-page) ===== */
.iskolend-page {
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
.iskolend-page .mb-0 { margin-bottom: 0; }
.iskolend-page .mb-1 { margin-bottom: 4px; }
.iskolend-page .mb-2 { margin-bottom: 8px; }
.iskolend-page .me-2 { margin-right: 8px; }

/* Cards */
.iskolend-page .card {
  background: var(--pf-surface); border: 1px solid var(--pf-border); border-radius: 18px;
  box-shadow: 0 1px 2px rgba(0,0,0,.04), 0 8px 24px rgba(0,0,0,.04);
}
.iskolend-page .iskolend-nav { padding: 8px; display: inline-flex; width: fit-content; max-width: 100%; border-radius: 14px; }
.iskolend-page .iskolend-return { padding: 28px; }

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
.iskolend-page .iskolend-page-heading { padding-bottom: 20px; margin-bottom: 24px; border-bottom: 1px solid var(--pf-border); }
.iskolend-page .iskolend-page-heading h2 { font-size: 1.5rem; font-weight: 650; letter-spacing: -.01em; margin: 0 0 4px; }
.iskolend-page .iskolend-page-heading p { margin: 0; font-size: .92rem; line-height: 1.5; max-width: 60ch; }

/* Layout */
.iskolend-page .iskolend-return-layout {
  display: grid; grid-template-columns: minmax(280px, 380px) 1fr; gap: 24px; align-items: start;
}
.iskolend-page .iskolend-scan-panel,
.iskolend-page .iskolend-return-panel { border-radius: var(--pf-radius); padding: 22px; min-width: 0; }
.iskolend-page .iskolend-scan-panel { background: var(--pf-surface); border: 1px solid var(--pf-border); position: sticky; top: 16px; }
.iskolend-page .iskolend-return-panel { background: var(--pf-surface); border: 1px solid var(--pf-border); }
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
.iskolend-page #returnScanNote { display: flex; align-items: center; gap: 10px; }
.iskolend-page #returnScanNote.d-none { display: none !important; }
.iskolend-page #returnScanNote::before {
  content: ""; width: 8px; height: 8px; border-radius: 50%; background: #a5a5a2; flex: none;
  animation: pf-pulse 1.6s ease-in-out infinite;
}
@keyframes pf-pulse { 50% { opacity: .35; } }
/* Empty / loading message inside the borrowings list */
.iskolend-page #borrowedList > .alert {
  margin: 0; padding: 40px 20px; text-align: center; background: var(--pf-inset);
  border: 1.5px dashed var(--pf-border-2); border-radius: var(--pf-radius); color: var(--pf-muted);
}

/* Borrowed item cards */
.iskolend-page #borrowedList { display: grid; gap: 14px; }
.iskolend-page .iskolend-return-card {
  display: grid; grid-template-columns: 112px 1fr; gap: 18px; padding: 14px;
  background: var(--pf-surface); border: 1px solid var(--pf-border); border-radius: var(--pf-radius);
}
.iskolend-page .iskolend-return-image {
  width: 112px; height: 112px; object-fit: cover; border-radius: var(--pf-radius-sm);
  background: var(--pf-inset); border: 1px solid var(--pf-border);
}
.iskolend-page .iskolend-return-detail { min-width: 0; display: flex; flex-direction: column; align-items: flex-start; }
.iskolend-page .iskolend-return-detail h4 { margin: 0 0 6px; font-size: 1rem; font-weight: 650; letter-spacing: -.005em; }
.iskolend-page .iskolend-return-detail p { margin: 0; font-size: .85rem; color: var(--pf-muted); line-height: 1.5; }
.iskolend-page .iskolend-return-detail p.mb-2 { margin-bottom: 8px; }
.iskolend-page .iskolend-return-detail p.text-danger { color: #8a2d2d; font-weight: 600; }
.iskolend-page .badge {
  display: inline-block; padding: 3px 10px; margin-bottom: 4px; font-size: .72rem; font-weight: 600;
  border-radius: 999px; background: var(--pf-inset); border: 1px solid var(--pf-border); color: var(--pf-text);
}
.iskolend-page .badge-red { background: #f6eeee; border-color: #e6cfcf; color: #7a2a2a; }
.iskolend-page .iskolend-return-form {
  display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; width: 100%;
  margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--pf-border);
}
.iskolend-page .iskolend-return-form label { flex: 1 1 180px; margin: 0; }
.iskolend-page .iskolend-return-form .form-select { margin-top: 6px; }
.iskolend-page .return-quantity { flex: 0 0 auto; padding-bottom: 12px; color: var(--pf-muted); font-size: .82rem; }

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
.iskolend-page .btn:disabled { opacity: .55; cursor: not-allowed; }
.iskolend-page .btn-outline { background: var(--pf-surface); color: var(--pf-text); border-color: var(--pf-border-2); }
.iskolend-page .btn-outline:hover { background: var(--pf-inset); border-color: var(--pf-dark); }
.iskolend-page .btn-sm { height: 44px; padding: 0 20px; font-size: .88rem; }
.iskolend-page .spinner-border {
  width: 14px; height: 14px; margin: 0; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff;
  border-radius: 50%; animation: pf-spin .7s linear infinite;
}
@keyframes pf-spin { to { transform: rotate(360deg); } }

/* Responsive */
@media (max-width: 900px) {
  .iskolend-page .iskolend-return-layout { grid-template-columns: 1fr; }
  .iskolend-page .iskolend-scan-panel { position: static; }
}
@media (max-width: 560px) {
  .iskolend-page .iskolend-return { padding: 18px; }
  .iskolend-page .iskolend-return-card { grid-template-columns: 1fr; }
  .iskolend-page .iskolend-return-image { width: 100%; height: 160px; }
  .iskolend-page .iskolend-return-form .btn { width: 100%; }
}
@media (prefers-reduced-motion: reduce) {
  .iskolend-page * { animation: none !important; transition: none !important; }
}
</style>

<div class="iskolend-page">
  <div class="card fade-in-up iskolend-nav">
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-outline" href="borrow.php">Borrow</a>
      <a class="btn" href="return.php">Return</a>
      <a class="btn btn-outline" href="inventory.php">Inventory</a>
      <a class="btn btn-outline" href="list.php">View Records</a>
    </div>
  </div>

  <section class="card fade-in-up iskolend-return">
    <div class="iskolend-page-heading">
      <div><h2>Return Borrowed Items</h2><p class="text-secondary mb-0">Scan a student ID to view current borrowings and log each return.</p></div>
    </div>
    <div class="iskolend-return-layout">
      <section class="iskolend-scan-panel" aria-labelledby="iskolendReturnStudentTitle">
        <h3 id="iskolendReturnStudentTitle">Scan Student ID Barcode</h3>
        <div class="scanner-wrap">
          <label for="studentScan" class="form-label">Student Number</label>
          <input class="form-control scan-input" type="text" id="studentScan" autocomplete="off" autofocus placeholder="0000-00000-BN-0" aria-describedby="scanError">
          <div class="scan-error" id="scanError" aria-live="polite" hidden></div>
        </div>
        <div id="studentInfo" aria-live="polite"></div>
        <div class="alert alert-info" id="returnScanNote">Waiting for a student ID scan.</div>
      </section>
      <section class="iskolend-return-panel" aria-labelledby="activeBorrowingsTitle">
        <h3 id="activeBorrowingsTitle">Active Borrowings</h3>
        <div id="borrowedList" aria-live="polite"><div class="alert alert-info">Scan a student ID to see current borrowings.</div></div>
      </section>
    </div>
  </section>
</div>
<script src="<?php echo $base; ?>assets/js/student-scan.js"></script>
<script src="<?php echo $base; ?>assets/js/student-card.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  'use strict';
  var activeStudentNo = '';
  var activeStudent = null;
  var csrfToken = <?php echo json_encode($csrf_token); ?>;
  var list = document.getElementById('borrowedList');

  function appendText(parent, tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    node.textContent = text;
    parent.appendChild(node);
    return node;
  }

  function imageSource(path) {
    var source = String(path || '').trim();
    if (!source) return '../../assets/img/item-placeholder.svg';
    return /^(https?:)?\/\//i.test(source) || source.charAt(0) === '/'
      ? source
      : '../../' + source.replace(/^(\.\/)+/, '');
  }

  function renderBorrowed(rows) {
    list.replaceChildren();
    if (!rows.length) {
      appendText(list, 'div', 'alert alert-info', 'This student has no items currently borrowed.');
      return;
    }
    rows.forEach(function (row) {
      var card = document.createElement('article');
      card.className = 'iskolend-return-card fade-in-up';
      var image = document.createElement('img');
      image.className = 'iskolend-return-image';
      image.src = imageSource(row.image_path);
      image.alt = '';
      image.loading = 'lazy';
      image.onerror = function () { this.onerror = null; this.src = '../../assets/img/item-placeholder.svg'; };
      card.appendChild(image);
      var detail = document.createElement('div');
      detail.className = 'iskolend-return-detail';
      appendText(detail, 'h4', '', row.item_name + ' × ' + row.quantity);
      appendText(detail, 'p', 'mb-1', 'Borrowed: ' + row.date_borrowed);
      var due = appendText(detail, 'p', 'mb-2', 'Due: ' + row.due_date);
      if (row.overdue) {
        due.classList.add('text-danger');
        var badge = appendText(detail, 'span', 'badge badge-red', 'Overdue');
        badge.setAttribute('role', 'status');
      }
      var form = document.createElement('form');
      form.className = 'iskolend-return-form';
      form.action = 'save_return.php';
      form.method = 'post';
      form.dataset.borrowId = row.id;
      var id = document.createElement('input');
      id.type = 'hidden'; id.name = 'borrow_id'; id.value = row.id;
      form.appendChild(id);
      var token = document.createElement('input');
      token.type = 'hidden'; token.name = 'csrf_token'; token.value = csrfToken;
      form.appendChild(token);
      var quantity = document.createElement('input');
      quantity.type = 'hidden'; quantity.name = 'quantity_returned'; quantity.value = row.quantity;
      form.appendChild(quantity);
      appendText(form, 'span', 'return-quantity', 'Returning all ' + row.quantity + ' unit(s)');
      var label = document.createElement('label');
      label.className = 'form-label';
      label.textContent = 'Condition on return';
      var condition = document.createElement('select');
      condition.className = 'form-select';
      condition.name = 'condition_on_return';
      [['Good', 'Good'], ['Damaged', 'Damaged'], ['Lost', 'Lost']].forEach(function (choice) {
        var option = document.createElement('option');
        option.value = choice[0]; option.textContent = choice[1];
        condition.appendChild(option);
      });
      label.appendChild(condition);
      form.appendChild(label);
      var button = document.createElement('button');
      button.className = 'btn btn-sm';
      button.type = 'submit';
      button.innerHTML = '<span class="spinner-border spinner-border-sm me-2 d-none" aria-hidden="true"></span><span>Log return</span>';
      form.appendChild(button);
      form.addEventListener('submit', submitReturn);
      detail.appendChild(form);
      card.appendChild(detail);
      list.appendChild(card);
    });
  }

  function loadBorrowings(studentNo) {
    list.innerHTML = '<div class="alert alert-info">Loading borrowings…</div>';
    return fetch('get_active_borrows.php?student_no=' + encodeURIComponent(studentNo))
      .then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok) throw new Error(payload.error || 'Borrowed items lookup failed.');
          return payload;
        });
      })
      .then(function (payload) {
        renderBorrowed(payload.rows || []);
        if (activeStudent) {
          activeStudent = Object.assign({}, activeStudent, {
            active_borrowings: payload.active_borrowings,
            borrowed_units: payload.borrowed_units,
            limit: payload.limit,
            remaining: payload.remaining
          });
          showStudentCard(activeStudent);
        }
      });
  }

  async function submitReturn(event) {
    event.preventDefault();
    var form = event.currentTarget;
    var button = form.querySelector('button[type="submit"]');
    if (button.disabled) return;
    var condition = form.elements.condition_on_return.value;
    if ((condition === 'Damaged' || condition === 'Lost') && form.dataset.confirmed !== 'true') {
      if (form.dataset.confirming === 'true') return;
      form.dataset.confirming = 'true';
      var confirmed = await window.notify.confirm({
        title: condition === 'Damaged' ? 'Mark item as damaged?' : 'Mark item as lost?',
        text: 'This item will not be returned to available stock.',
        confirmText: 'Log return',
        cancelText: 'Cancel',
        danger: true
      });
      form.dataset.confirming = 'false';
      if (!confirmed) return;
      form.dataset.confirmed = 'true';
      form.requestSubmit();
      return;
    }
    delete form.dataset.confirmed;
    button.disabled = true;
    button.querySelector('.spinner-border').classList.remove('d-none');
    fetch(form.action, {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: new FormData(form)
    }).then(function (response) {
      return response.json().then(function (payload) {
        if (!response.ok) throw new Error(payload.error || 'Unable to log this return.');
        return payload;
      });
    }).then(function (payload) {
      window.notify.success('Item returned', payload.student_name + ' · ' + payload.item_name +
        ' × ' + payload.quantity, { focusTarget: document.getElementById('studentScan') });
      return loadBorrowings(activeStudentNo);
    }).catch(function (error) {
      window.notify.error('Return could not be recorded', error.message || 'Unable to log this return.', { focusTarget: document.getElementById('studentScan') });
      button.disabled = false;
      button.querySelector('.spinner-border').classList.add('d-none');
    });
  }

  initStudentScanField(document.getElementById('studentScan'), function (studentNo) {
    activeStudentNo = '';
    activeStudent = null;
    document.getElementById('studentInfo').replaceChildren();
    return fetch('<?php echo $base; ?>modules/students/lookup.php?student_no=' + encodeURIComponent(studentNo))
      .then(function (response) {
        if (!response.ok) throw new Error('Student lookup failed.');
        return response.json();
      }).then(function (student) {
        if (student.blacklisted) {
          activeStudent = null;
          showBlacklistAlert(student.reason, student);
          window.notify.error('Student is blacklisted', 'Borrowing is blocked, but existing items can still be returned.', { focusTarget: document.getElementById('studentScan') });
          document.getElementById('returnScanNote').classList.add('d-none');
          activeStudentNo = student.student_no || studentNo;
          return loadBorrowings(activeStudentNo);
        }
        if (!student.found) {
          var error = document.createElement('div');
          error.className = 'alert alert-error mt-3';
          error.textContent = 'Student number not found. Please register the student first.';
          document.getElementById('studentInfo').appendChild(error);
          window.notify.error('Student not found', error.textContent, { focusTarget: document.getElementById('studentScan') });
          document.getElementById('returnScanNote').classList.remove('d-none');
          list.replaceChildren();
          return;
        }
        showStudentCard(student);
        activeStudent = student;
        document.getElementById('returnScanNote').classList.add('d-none');
        activeStudentNo = student.student_no;
        return loadBorrowings(activeStudentNo);
      });
  });
});
</script>
<?php include '../../includes/footer.php'; ?>