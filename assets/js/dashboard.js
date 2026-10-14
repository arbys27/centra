(function () {
  'use strict';

  var dateElement = document.getElementById('liveDate');
  var timeElement = document.getElementById('liveTime');

  function updateClock() {
    var now = new Date();
    dateElement.textContent = new Intl.DateTimeFormat('en-US', {
      timeZone: 'Asia/Manila',
      weekday: 'long',
      month: 'long',
      day: 'numeric',
      year: 'numeric'
    }).format(now);
    timeElement.textContent = new Intl.DateTimeFormat('en-US', {
      timeZone: 'Asia/Manila',
      hour: 'numeric',
      minute: '2-digit',
      second: '2-digit',
      hour12: true
    }).format(now);
  }

  updateClock();
  window.setInterval(updateClock, 1000);

  function showAgendaReminder() {
    var modal = document.getElementById('agendaReminderModal');
    if (modal && window.bootstrap) {
      window.bootstrap.Modal.getOrCreateInstance(modal).show();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', showAgendaReminder, { once: true });
  } else {
    showAgendaReminder();
  }
}());
