(function (global) {
  'use strict';

  function showStudentCard(student) {
    var container = document.getElementById('studentInfo');
    if (!container) return;
    container.replaceChildren();
    var card = document.createElement('div');
    card.className = 'student-card alert fade';
    card.setAttribute('role', 'status');

    var name = document.createElement('strong');
    name.className = 'student-card-name';
    name.textContent = student.full_name || '';
    card.appendChild(name);

    [
      ['Student Number', student.student_no],
      ['Program', student.program || student.course],
      ['Year', student.year_level],
      ['Section', student.section]
    ].forEach(function (field) {
      var line = document.createElement('div');
      line.textContent = field[0] + ': ' + (field[1] || '—');
      card.appendChild(line);
    });
    if (Number.isInteger(Number(student.limit)) && Number.isInteger(Number(student.borrowed_units))) {
      var allowance = document.createElement('div');
      allowance.className = 'student-borrow-allowance';
      var allowanceText = document.createElement('strong');
      allowanceText.textContent = 'Borrowed ' + Number(student.borrowed_units) + ' / ' + Number(student.limit);
      allowance.appendChild(allowanceText);

      var segments = document.createElement('div');
      segments.className = 'student-borrow-segments';
      segments.setAttribute('role', 'img');
      segments.setAttribute('aria-label', Number(student.borrowed_units) + ' of ' + Number(student.limit) + ' item units borrowed');
      for (var index = 0; index < Number(student.limit); index += 1) {
        var segment = document.createElement('span');
        segment.className = 'student-borrow-segment' +
          (index < Number(student.borrowed_units) ? ' is-filled' : '');
        segments.appendChild(segment);
      }
      allowance.appendChild(segments);
      card.appendChild(allowance);

      var activeBorrowings = Array.isArray(student.active_borrowings) ? student.active_borrowings : [];
      var holdings = document.createElement('div');
      holdings.className = 'student-borrow-holdings';
      holdings.textContent = activeBorrowings.length
        ? 'Currently held: ' + activeBorrowings.map(function (borrowing) {
          return borrowing.item_name + ' × ' + borrowing.quantity;
        }).join(', ')
        : 'Currently held: None';
      card.appendChild(holdings);
    }
    container.appendChild(card);
    requestAnimationFrame(function () { card.classList.add('show'); });
  }

  function showBlacklistAlert(reason, student) {
    var container = document.getElementById('studentInfo');
    if (!container) return;
    container.replaceChildren();
    var alert = document.createElement('div');
    alert.className = 'student-card alert alert-danger fade blacklist-alert';
    alert.setAttribute('role', 'alert');
    var heading = document.createElement('strong');
    heading.className = 'blacklist-heading';
    heading.textContent = 'BLACKLISTED';
    alert.appendChild(heading);
    if (student) {
      var identity = document.createElement('div');
      identity.textContent = (student.full_name || '') + ' · ' + (student.student_no || '');
      alert.appendChild(identity);
    }
    var reasonLine = document.createElement('div');
    reasonLine.textContent = 'Reason: ' + (reason || 'No reason recorded.');
    alert.appendChild(reasonLine);
    container.appendChild(alert);
    requestAnimationFrame(function () { alert.classList.add('show'); });
  }

  global.showStudentCard = showStudentCard;
  global.showBlacklistAlert = showBlacklistAlert;
}(window));
