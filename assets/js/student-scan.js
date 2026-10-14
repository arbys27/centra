(function (global) {
  'use strict';

  function normalizeStudentNumber(value) {
    var cleaned = String(value || '').trim().toUpperCase();
    if (/^\d{9}BN\d$/.test(cleaned)) {
      cleaned = cleaned.slice(0, 4) + '-' + cleaned.slice(4, 9) + '-BN-' + cleaned.slice(11);
    }
    return cleaned;
  }

  function initStudentScanField(inputEl, onValid) {
    if (!inputEl || typeof onValid !== 'function') {
      throw new TypeError('A scan input and callback are required.');
    }
    var errorEl = document.getElementById(inputEl.getAttribute('aria-describedby') || '');
    var timer = null;
    var pending = false;
    var lastSubmitted = '';

    function setError(message) {
      inputEl.classList.toggle('is-invalid', Boolean(message));
      if (errorEl) {
        errorEl.textContent = message;
        errorEl.hidden = !message;
      }
    }

    function focusField() {
      if (document.activeElement !== inputEl) inputEl.focus();
    }

    function submitScan() {
      if (pending) return;
      var studentNumber = normalizeStudentNumber(inputEl.value);
      if (!studentNumber) return;
      if (!/^\d{4}-\d{5}-BN-\d$/.test(studentNumber)) {
        var validationMessage = 'Invalid student number. Scan the 15-character ID barcode.';
        setError(validationMessage);
        if (global.notify) global.notify.error('Invalid student ID', validationMessage, { focusTarget: inputEl });
        inputEl.classList.remove('scan-shake');
        void inputEl.offsetWidth;
        inputEl.classList.add('scan-shake');
        pending = true;
        inputEl.disabled = true;
        window.setTimeout(function () {
          inputEl.value = '';
          inputEl.disabled = false;
          pending = false;
          focusField();
        }, 450);
        return;
      }
      setError('');
      if (lastSubmitted === studentNumber) {
        inputEl.value = '';
        focusField();
        return;
      }
      lastSubmitted = studentNumber;
      window.setTimeout(function () {
        if (lastSubmitted === studentNumber) lastSubmitted = '';
      }, 700);
      pending = true;
      inputEl.disabled = true;
      Promise.resolve(onValid(studentNumber)).catch(function (error) {
        var errorMessage = error && error.message ? error.message : 'Unable to look up this student.';
        setError(errorMessage);
        if (global.notify) global.notify.error('Student lookup failed', errorMessage, { focusTarget: inputEl });
        lastSubmitted = '';
      }).finally(function () {
        inputEl.value = '';
        inputEl.disabled = false;
        pending = false;
        focusField();
      });
    }

    inputEl.addEventListener('input', function () {
      setError('');
      clearTimeout(timer);
      var candidate = normalizeStudentNumber(inputEl.value);
      if (/^\d{4}-\d{5}-BN-\d$/.test(candidate)) {
        timer = setTimeout(submitScan, 60);
      } else if (/^\d{9}BN\d$/.test(candidate)) {
        inputEl.value = candidate;
        timer = setTimeout(submitScan, 60);
      } else if (!candidate) {
        lastSubmitted = '';
      }
    });
    inputEl.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        clearTimeout(timer);
        submitScan();
      }
    });
    inputEl.addEventListener('animationend', function () {
      inputEl.classList.remove('scan-shake');
    });
    focusField();
    return { focus: focusField };
  }

  global.initStudentScanField = initStudentScanField;
  global.normalizeStudentNumber = normalizeStudentNumber;
}(window));
