<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
$base = '../../';
$page_title = 'Attendance';
include '../../includes/header.php';
?>
<div class="card" style="margin-bottom:0;"><div style="display:flex;gap:10px;flex-wrap:wrap;">
  <a class="btn" href="scan.php">🕒 Time In / Out</a><a class="btn btn-outline" href="list.php">📄 View Records</a>
</div></div>
<div class="grid grid-2" style="margin-top:20px;">
  <div class="card">
    <h2>Scan Officer ID Barcode</h2>
    <div class="scanner-wrap">
      <label for="manualEntry">Officer ID</label>
      <input class="scan-input" type="text" id="manualEntry" maxlength="15" autocomplete="off" autofocus placeholder="0000-00000-BN-0" aria-describedby="scanError">
      <div class="scan-error" id="scanError" aria-live="polite" hidden></div>
      <button class="btn btn-gold" id="logAttendance" type="button" style="margin-top:8px;width:100%;" onclick="handleScan()">Log Attendance</button>
    </div>
  </div>
  <div class="card"><h2>Result</h2><div id="resultBox"><div class="alert alert-info">Scan an officer ID to log time-in or time-out.</div></div></div>
</div>
<script src="<?php echo $base; ?>assets/js/student-scan.js"></script>
<script>
var scanInput = document.getElementById('manualEntry');
initStudentScanField(scanInput, function (officerId) {
  return handleScan(officerId);
});
function handleScan(officerId){
  officerId = normalizeStudentNumber(officerId || scanInput.value);
  var button = document.getElementById('logAttendance');
  if (!officerId || button.disabled) return;
  if (!/^\d{4}-\d{5}-BN-\d$/.test(officerId)) {
    var error = document.getElementById('scanError');
    error.textContent = 'Invalid officer ID. Scan the student ID barcode.';
    error.hidden = false;
    scanInput.focus();
    return Promise.resolve();
  }
  document.getElementById('scanError').textContent = '';
  document.getElementById('scanError').hidden = true;
  button.disabled = true;
  var spinner = document.createElement('span');
  spinner.className = 'spinner-border spinner-border-sm me-2';
  button.prepend(spinner);
  return fetch('log.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'officer_id=' + encodeURIComponent(officerId)
  })
  .then(function (response) {
    return response.json().then(function (payload) {
      if (!response.ok) throw new Error(payload.message || payload.error || 'Attendance request failed.');
      return payload;
    });
  })
  .then(function (data) {
    var box = document.getElementById('resultBox');
    box.replaceChildren();
    var result = document.createElement('div');
    result.className = data.success ? 'scan-result' : 'alert alert-error';
    if (data.success) {
      var name = document.createElement('div'); name.className = 'name'; name.textContent = data.full_name;
      var position = document.createElement('div'); position.textContent = data.position;
      var action = document.createElement('div'); action.className = 'mt-2 fs-5'; action.textContent = data.action + ' recorded at ' + data.time;
      result.append(name, position, action);
      var title = data.action === 'TIME IN' ? 'Timed in' : 'Timed out';
      var detail = data.full_name + ' · ' + data.time;
      if (data.action === 'TIME OUT' && Number.isFinite(Number(data.elapsed_minutes))) {
        var totalMinutes = Number(data.elapsed_minutes);
        var hours = Math.floor(totalMinutes / 60);
        var minutes = totalMinutes % 60;
        detail += ' · Total ' + (hours ? hours + 'h ' : '') + minutes + 'm';
      }
      window.notify.success(title, detail, { focusTarget: scanInput });
    } else {
      result.textContent = data.message;
      window.notify.error('Attendance not recorded', data.message || 'Unable to record attendance.', { focusTarget: scanInput });
    }
    box.appendChild(result);
  })
  .catch(function (error) {
    document.getElementById('resultBox').textContent = error.message;
    window.notify.error('Attendance request failed', error.message || 'Unable to record attendance.', { focusTarget: scanInput });
  })
  .finally(function () {
    spinner.remove();
    button.disabled = false;
    scanInput.value = '';
    scanInput.focus();
  });
}
scanInput.focus();
</script>
<?php include '../../includes/footer.php'; ?>
