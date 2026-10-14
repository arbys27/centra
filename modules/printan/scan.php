<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
require_once '../../includes/db.php';

if (empty($_SESSION['printan_csrf_token'])) {
    $_SESSION['printan_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['printan_csrf_token'];
$base = '../../';
$page_title = 'Printan ng Bayan';
include '../../includes/header.php';
?>
<style>
/* ===== Printan ng Bayan – modern gray theme (scoped to .printan-request) ===== */
.printan-request {
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

  background: var(--pf-surface);
  border: 1px solid var(--pf-border);
  border-radius: 18px;
  padding: 28px;
  color: var(--pf-text);
  box-shadow: 0 1px 2px rgba(0,0,0,.04), 0 8px 24px rgba(0,0,0,.04);
}
.printan-request *, .printan-request *::before, .printan-request *::after { box-sizing: border-box; }
.printan-request .d-none { display: none !important; }
.printan-request [hidden] { display: none !important; }

/* Heading */
.printan-request .printan-section-heading {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 16px; flex-wrap: wrap;
  padding-bottom: 20px; margin-bottom: 24px;
  border-bottom: 1px solid var(--pf-border);
}
.printan-request .printan-section-heading h2 {
  font-size: 1.5rem; font-weight: 650; letter-spacing: -.01em; margin: 0 0 4px;
}
.printan-request .printan-section-heading p {
  margin: 0; color: var(--pf-muted); font-size: .92rem; max-width: 56ch; line-height: 1.5;
}

/* Layout */
.printan-request .printan-request-grid {
  display: grid; grid-template-columns: minmax(280px, 380px) 1fr; gap: 24px; align-items: start;
}
.printan-request .printan-student-panel,
.printan-request .printan-form-panel {
  border-radius: var(--pf-radius); padding: 22px;
  background: var(--pf-surface); border: 1px solid var(--pf-border);
}
.printan-request .printan-student-panel { position: sticky; top: 16px; }
.printan-request h3 {
  font-size: 1rem; font-weight: 650; margin: 0 0 16px; letter-spacing: -.005em; line-height: 1.3;
}

/* Labels & controls */
.printan-request label, .printan-request .pf-label {
  display: block; font-size: .8rem; font-weight: 600; color: var(--pf-muted); margin-bottom: 6px;
}
.printan-request .form-control {
  width: 100%; height: 44px; padding: 0 14px;
  font: inherit; font-size: .95rem; color: var(--pf-text);
  background: var(--pf-surface); border: 1px solid var(--pf-border-2);
  border-radius: var(--pf-radius-sm); outline: none;
  transition: border-color .15s, box-shadow .15s;
}
.printan-request .form-control:focus { border-color: var(--pf-dark); box-shadow: var(--pf-focus); }
.printan-request .form-control::placeholder { color: #a5a5a2; }

/* Scan input */
.printan-request .scan-input {
  height: 52px; font-size: 1.05rem; letter-spacing: .04em;
  font-variant-numeric: tabular-nums; border-width: 1.5px; background: var(--pf-surface);
}
.printan-request .scan-error { margin-top: 8px; font-size: .85rem; color: #8a2d2d; }
#studentInfo:not(:empty) { margin-top: 16px; }

/* Alerts / notes */
.printan-request .alert {
  display: block; padding: 12px 14px; border-radius: var(--pf-radius-sm);
  font-size: .88rem; line-height: 1.5; border: 1px solid var(--pf-border);
  background: var(--pf-surface); color: var(--pf-muted); margin-top: 14px;
}
.printan-request .alert.mb-0 { margin-bottom: 0; }
.printan-request .alert.mt-3 { margin-top: 14px; }
.printan-request .alert-error { background: #f6eeee; border-color: #e6cfcf; color: #7a2a2a; }
.printan-request #scanNote { display: flex; align-items: center; gap: 10px; }
.printan-request #scanNote::before {
  content: ""; width: 8px; height: 8px; border-radius: 50%; background: #a5a5a2; flex: none;
  animation: pf-pulse 1.6s ease-in-out infinite;
}
@keyframes pf-pulse { 50% { opacity: .35; } }
/* Locked state = empty placeholder */
.printan-request #lockedNote {
  margin: 0; padding: 40px 20px; text-align: center;
  background: var(--pf-inset); border: 1.5px dashed var(--pf-border-2);
  border-radius: var(--pf-radius); color: var(--pf-muted);
}

/* ---- Request form layout (own class names, so Bootstrap's .row/.col-* can't interfere) ---- */
.printan-request .pf-grid {
  display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px 16px;
}
.printan-request .pf-field { min-width: 0; }
.printan-request .pf-span-2 { grid-column: 1 / -1; }
.printan-request .pf-hint { margin: 6px 0 0; font-size: .76rem; color: var(--pf-muted); line-height: 1.4; }

/* Segmented control (radio buttons) */
.printan-request .pf-seg {
  position: relative; display: grid; grid-template-columns: repeat(var(--n, 2), minmax(0, 1fr));
  gap: 4px; padding: 4px; background: var(--pf-inset);
  border: 1px solid var(--pf-border); border-radius: var(--pf-radius-sm);
}
.printan-request .pf-seg input { position: absolute; opacity: 0; pointer-events: none; }
.printan-request .pf-seg label {
  display: flex; align-items: center; justify-content: center;
  height: 38px; margin: 0; border-radius: 8px; cursor: pointer;
  font-size: .88rem; font-weight: 600; color: var(--pf-muted);
  transition: background .15s, color .15s;
}
.printan-request .pf-seg label:hover { color: var(--pf-text); }
.printan-request .pf-seg input:checked + label { background: var(--pf-dark); color: #fff; }
.printan-request .pf-seg input:focus-visible + label { box-shadow: var(--pf-focus); }
.printan-request .pf-seg input:disabled + label { opacity: .4; cursor: not-allowed; }
.printan-request .pf-seg input:disabled + label:hover { color: var(--pf-muted); }

/* Pages stepper */
.printan-request .pf-stepper {
  display: flex; align-items: stretch; background: var(--pf-surface);
  border: 1px solid var(--pf-border-2); border-radius: var(--pf-radius-sm); overflow: hidden;
  transition: border-color .15s, box-shadow .15s;
}
.printan-request .pf-stepper:focus-within { border-color: var(--pf-dark); box-shadow: var(--pf-focus); }
.printan-request .pf-stepper button {
  flex: none; width: 46px; height: 44px; padding: 0; margin: 0;
  border: 0; border-radius: 0; background: var(--pf-inset); color: var(--pf-text);
  font-size: 1.25rem; line-height: 1; cursor: pointer; transition: background .15s;
}
.printan-request .pf-stepper button:hover { background: var(--pf-border); }
.printan-request .pf-stepper button:focus-visible { outline: 2px solid var(--pf-dark); outline-offset: -2px; }
.printan-request .pf-stepper input {
  flex: 1; min-width: 0; height: 44px; padding: 0 8px; margin: 0;
  border: 0; border-radius: 0; outline: none; box-shadow: none; background: transparent;
  font: inherit; font-size: 1.05rem; font-weight: 650; text-align: center; color: var(--pf-text);
  font-variant-numeric: tabular-nums; -moz-appearance: textfield; appearance: textfield;
}
.printan-request .pf-stepper input::-webkit-outer-spin-button,
.printan-request .pf-stepper input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

/* Own-paper toggle tile */
.printan-request .pf-toggle { position: relative; }
.printan-request .pf-toggle input {
  position: absolute; inset: 0; width: 100%; height: 100%; margin: 0;
  opacity: 0; cursor: pointer; z-index: 1;
}
.printan-request .pf-toggle label {
  display: flex; align-items: center; gap: 12px; margin: 0; min-height: 46px; padding: 0 14px;
  background: var(--pf-surface); color: var(--pf-text);
  border: 1px solid var(--pf-border-2); border-radius: var(--pf-radius-sm);
  font-size: .9rem; font-weight: 600; line-height: 1.3;
  transition: background .15s, border-color .15s, color .15s;
}
.printan-request .pf-toggle:hover label { border-color: var(--pf-dark); }
.printan-request .pf-toggle label::before {
  content: ""; flex: none; width: 20px; height: 20px;
  border: 1.5px solid var(--pf-border-2); border-radius: 6px; background: var(--pf-surface) center / 12px no-repeat;
  transition: background-color .15s, border-color .15s;
}
.printan-request .pf-toggle .pf-toggle-hint { display: block; font-size: .78rem; font-weight: 400; color: var(--pf-muted); }
.printan-request .pf-toggle input:checked + label { background: var(--pf-dark); border-color: var(--pf-dark); color: #fff; }
.printan-request .pf-toggle input:checked + label .pf-toggle-hint { color: #bdbdb9; }
.printan-request .pf-toggle input:checked + label::before {
  border-color: #fff; background-color: #fff;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' fill='none' stroke='%231c1c1c' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M2.5 6.5l2.5 2.5 4.5-5'/%3E%3C/svg%3E");
}
.printan-request .pf-toggle input:focus-visible + label { box-shadow: var(--pf-focus); }

/* Price summary */
.printan-request .printan-price-summary {
  display: grid; grid-template-columns: 1fr 1fr 1.3fr; gap: 1px;
  background: var(--pf-border); border: 1px solid var(--pf-border);
  border-radius: var(--pf-radius); overflow: hidden; margin-top: 24px;
}
.printan-request .printan-price-summary > div {
  background: var(--pf-inset); padding: 14px 16px; display: flex; flex-direction: column; gap: 4px;
}
.printan-request .printan-price-summary span { font-size: .75rem; color: var(--pf-muted); font-weight: 600; }
.printan-request .printan-price-summary strong { font-size: 1.1rem; font-variant-numeric: tabular-nums; }
.printan-request .printan-price-summary .printan-total { background: var(--pf-dark); }
.printan-request .printan-price-summary .printan-total span { color: #bdbdb9; }
.printan-request .printan-price-summary .printan-total strong { color: #fff; font-size: 1.5rem; letter-spacing: -.01em; }
.printan-request .pf-rate-note { margin: 10px 2px 0; font-size: .8rem; color: var(--pf-muted); }

/* Buttons */
.printan-request .btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  height: 46px; padding: 0 20px; font: inherit; font-size: .92rem; font-weight: 600;
  color: #fff; background: var(--pf-dark); border: 1px solid var(--pf-dark);
  border-radius: var(--pf-radius-sm); cursor: pointer; text-decoration: none;
  transition: background .15s, transform .1s;
}
.printan-request .btn:hover { background: var(--pf-dark-hover); }
.printan-request .btn:active { transform: translateY(1px); }
.printan-request .btn:focus-visible { outline: none; box-shadow: var(--pf-focus); }
.printan-request .btn:disabled { opacity: .6; cursor: not-allowed; }
.printan-request .btn.w-100 { width: 100%; }
.printan-request .btn.mt-3 { margin-top: 20px; }
.printan-request .btn-outline { background: var(--pf-surface); color: var(--pf-text); border-color: var(--pf-border-2); }
.printan-request .btn-outline:hover { background: var(--pf-inset); border-color: var(--pf-dark); }
.printan-request .btn-sm { height: 38px; padding: 0 16px; font-size: .85rem; }
.printan-request .spinner-border {
  width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff;
  border-radius: 50%; animation: pf-spin .7s linear infinite;
}
@keyframes pf-spin { to { transform: rotate(360deg); } }

/* Responsive */
@media (max-width: 900px) {
  .printan-request .printan-request-grid { grid-template-columns: 1fr; }
  .printan-request .printan-student-panel { position: static; }
}
@media (max-width: 560px) {
  .printan-request { padding: 18px; }
  .printan-request .pf-grid { grid-template-columns: 1fr; }
  .printan-request .pf-span-2 { grid-column: auto; }
  .printan-request .printan-price-summary { grid-template-columns: 1fr 1fr; }
  .printan-request .printan-price-summary .printan-total { grid-column: span 2; }
}
@media (prefers-reduced-motion: reduce) {
  .printan-request * { animation: none !important; transition: none !important; }
}
</style>

<div class="card fade-in-up printan-request">
  <div class="printan-section-heading">
    <div>
      <h2>Log a Request</h2>
      <p>Scan the student ID to begin. The student record will be checked before the request form unlocks.</p>
    </div>
    <a class="btn btn-outline btn-sm" href="list.php">View Records</a>
  </div>

  <div class="printan-request-grid">
    <!-- Left: scan + student profile -->
    <section class="printan-student-panel" aria-labelledby="printanStudentHeading">
      <h3 id="printanStudentHeading">Scan Student ID Barcode</h3>
      <div class="scanner-wrap">
        <label for="studentScan">Student Number</label>
        <input class="form-control scan-input" type="text" id="studentScan" autocomplete="off" autofocus placeholder="0000-00000-BN-0" aria-describedby="scanError">
        <div class="scan-error" id="scanError" aria-live="polite" hidden></div>
      </div>
      <div id="studentInfo" aria-live="polite"></div>
      <div class="alert alert-info mb-0" id="scanNote">Waiting for a student ID scan.</div>
    </section>

    <!-- Right: request details -->
    <section class="printan-form-panel" aria-labelledby="printanFormHeading">
      <h3 id="printanFormHeading">Request Details</h3>
      <div class="alert alert-info" id="lockedNote">Scan an active student ID before entering a request.</div>

      <form method="post" action="save.php" id="printForm" class="d-none">
        <input type="hidden" name="student_no" id="f_student_no">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <!-- Service is stored for compatibility; copies are fixed to one by save.php. -->
        <input type="hidden" name="service_type" value="print">

        <div class="pf-grid">
          <div class="pf-field">
            <label for="numPages">Pages</label>
            <div class="pf-stepper">
              <button type="button" data-step="-1" aria-label="Decrease pages">&minus;</button>
              <input type="number" id="numPages" name="num_pages" min="1" max="1000" value="1" inputmode="numeric" required>
              <button type="button" data-step="1" aria-label="Increase pages">+</button>
            </div>
            <p class="pf-hint">Total number of pages to charge.</p>
          </div>

          <div class="pf-field">
            <span class="pf-label" id="colorLabel">Color</span>
            <div class="pf-seg" role="radiogroup" aria-labelledby="colorLabel">
              <input type="radio" id="colorBw" name="is_colored" value="0" checked>
              <label for="colorBw">Black &amp; white</label>
              <input type="radio" id="colorFull" name="is_colored" value="1">
              <label for="colorFull">Color</label>
            </div>
          </div>

          <div class="pf-field">
            <span class="pf-label" id="sidesLabel">Sides</span>
            <div class="pf-seg" role="radiogroup" aria-labelledby="sidesLabel">
              <input type="radio" id="sidesSingle" name="back_to_back" value="0" checked>
              <label for="sidesSingle">Single-sided</label>
              <input type="radio" id="sidesDouble" name="back_to_back" value="1">
              <label for="sidesDouble">Back-to-back</label>
            </div>
            <p class="pf-hint" id="sidesHint" hidden>No back-to-back rate for the student's own bond paper.</p>
          </div>

          <div class="pf-field">
            <span class="pf-label" id="paperLabel">Paper</span>
            <div class="pf-toggle">
              <input type="checkbox" id="ownPaper" name="own_paper" value="1" aria-labelledby="paperLabel">
              <label for="ownPaper">Use own bond paper</label>
            </div>
            <p class="pf-hint">Lower rate when the student brings their own paper.</p>
          </div>
        </div>

        <div class="printan-price-summary">
          <div><span>Rate per page</span><strong>₱<span id="rateDisplay">2.00</span></strong></div>
          <div><span>Pages</span><strong id="billablePages">1</strong></div>
          <div class="printan-total"><span>Total</span><strong>₱<span id="totalDisplay">2.00</span></strong></div>
        </div>
        <p class="pf-rate-note" id="rateNote" aria-live="polite"></p>

        <button class="btn w-100 mt-3" type="submit" id="savePrintRequest">
          <span class="spinner-border d-none" aria-hidden="true"></span>
          <span class="button-label">Save Request</span>
        </button>
      </form>
    </section>
  </div>
</div>

<script src="<?php echo $base; ?>assets/js/student-scan.js"></script>
<script src="<?php echo $base; ?>assets/js/student-card.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  var MAX_PAGES = 1000;
  var scanner = document.getElementById('studentScan');
  var requestForm = document.getElementById('printForm');
  var studentNumberField = document.getElementById('f_student_no');
  var saveButton = document.getElementById('savePrintRequest');
  var pagesInput = document.getElementById('numPages');
  var studentInfo = document.getElementById('studentInfo');
  var scanNote = document.getElementById('scanNote');
  var lockedNote = document.getElementById('lockedNote');
  var currentStudentName = '';

  function setFormAccess(enabled) {
    requestForm.classList.toggle('d-none', !enabled);
    lockedNote.classList.toggle('d-none', enabled);
    scanNote.classList.toggle('d-none', enabled);
  }

  initStudentScanField(scanner, function (studentNumber) {
    return fetch('<?php echo $base; ?>modules/students/lookup.php?student_no=' + encodeURIComponent(studentNumber))
      .then(function (response) {
        if (!response.ok) throw new Error('Student lookup failed.');
        return response.json();
      })
      .then(function (data) {
        studentNumberField.value = '';
        if (data.blacklisted) {
          showBlacklistAlert(data.reason, data);
          scanNote.classList.add('d-none');
          setFormAccess(false);
          return;
        }
        if (!data.found) {
          studentInfo.replaceChildren();
          var message = document.createElement('div');
          message.className = 'alert alert-error mt-3';
          message.textContent = 'Student number not found. Please register the student first.';
          studentInfo.appendChild(message);
          scanNote.classList.remove('d-none');
          setFormAccess(false);
          return;
        }
        showStudentCard(data);
        currentStudentName = data.full_name;
        studentNumberField.value = data.student_no;
        scanNote.classList.add('d-none');
        setFormAccess(true);
      });
  });

  // Back-to-back has no listed rate for the student's own paper, so that option is locked out.
  function updateSides() {
    var ownPaper = document.getElementById('ownPaper').checked;
    if (ownPaper) document.getElementById('sidesSingle').checked = true;
    document.getElementById('sidesDouble').disabled = ownPaper;
    document.getElementById('sidesHint').hidden = !ownPaper;
  }

  // Price per page (same for print, xerox and scan), total = rate x pages.
  //   Own paper:  B&W 1, Color 3
  //   CSC paper:  B&W 2 (back-to-back 3), Color 4 (back-to-back 7)
  function calculatePrice() {
    updateSides();
    var ownPaper = document.getElementById('ownPaper').checked;
    var colored = requestForm.elements['is_colored'].value === '1';
    var backToBack = requestForm.elements['back_to_back'].value === '1';
    var pages = Math.min(MAX_PAGES, Math.max(1, parseInt(pagesInput.value, 10) || 1));
    var rate = ownPaper ? (colored ? 3 : 1) : (colored ? (backToBack ? 7 : 4) : (backToBack ? 3 : 2));

    document.getElementById('rateDisplay').textContent = rate.toFixed(2);
    document.getElementById('billablePages').textContent = String(pages);
    document.getElementById('totalDisplay').textContent = (rate * pages).toFixed(2);
    document.getElementById('rateNote').textContent =
      (colored ? 'Color' : 'Black & white') + (backToBack ? ', back-to-back' : '') +
      ' on ' + (ownPaper ? "the student's own bond paper" : 'CSC paper') + '.';
  }

  requestForm.addEventListener('input', calculatePrice);
  requestForm.addEventListener('change', calculatePrice);

  // Keep the pages box tidy once the officer leaves it.
  pagesInput.addEventListener('change', function () {
    pagesInput.value = Math.min(MAX_PAGES, Math.max(1, parseInt(pagesInput.value, 10) || 1));
    calculatePrice();
  });

  requestForm.querySelectorAll('[data-step]').forEach(function (button) {
    button.addEventListener('click', function () {
      var next = (parseInt(pagesInput.value, 10) || 1) + Number(button.getAttribute('data-step'));
      pagesInput.value = Math.min(MAX_PAGES, Math.max(1, next));
      calculatePrice();
    });
  });

  requestForm.addEventListener('submit', function (event) {
    event.preventDefault();
    if (!requestForm.reportValidity() || !studentNumberField.value) {
      window.notify.error('Check the request details', 'Select a student and complete the required request fields.', { focusTarget: scanner });
      return;
    }

    saveButton.disabled = true;
    saveButton.querySelector('.spinner-border').classList.remove('d-none');
    saveButton.querySelector('.button-label').textContent = 'Saving…';

    fetch(requestForm.action, {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: new FormData(requestForm)
    }).then(function (response) {
      return response.json().then(function (payload) {
        if (!response.ok) throw new Error(payload.error || 'Unable to save this request.');
        return payload;
      });
    }).then(function (payload) {
      window.notify.success('Request saved', currentStudentName + ' · ' + pagesInput.value +
        ' pages · ₱' + document.getElementById('totalDisplay').textContent, { focusTarget: scanner });
      requestForm.reset();
      currentStudentName = '';
      studentNumberField.value = '';
      studentInfo.replaceChildren();
      scanNote.classList.remove('d-none');
      setFormAccess(false);
      calculatePrice();
      scanner.value = '';
      scanner.focus();
    }).catch(function (error) {
      window.notify.error('Request could not be saved', error.message || 'Unable to save this request.', { focusTarget: scanner });
    }).finally(function () {
      saveButton.disabled = false;
      saveButton.querySelector('.spinner-border').classList.add('d-none');
      saveButton.querySelector('.button-label').textContent = 'Save Request';
    });
  });

  calculatePrice();
});
</script>
<?php include '../../includes/footer.php'; ?>