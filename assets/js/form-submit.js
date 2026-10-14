(function () {
  'use strict';
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || (form.method || 'get').toLowerCase() === 'get') return;
    var button = form.querySelector('button[type="submit"]:not([disabled]), input[type="submit"]:not([disabled])');
    if (!button) return;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    var spinner = document.createElement('span');
    spinner.className = 'spinner-border spinner-border-sm me-2';
    spinner.setAttribute('role', 'status');
    spinner.setAttribute('aria-hidden', 'true');
    button.prepend(spinner);
  });
}());
