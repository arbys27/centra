<?php
require_once '../../includes/auth_check.php';
require_once '../../includes/pin_gate.php';
require_pin('officers');
require_once '../../config/db.php';
require_once '../../includes/db.php';

$pdo = get_pdo_connection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function officers_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function officer_valid_time(string $value): bool
{
    $time = DateTimeImmutable::createFromFormat('!H:i', $value);
    return $time !== false && $time->format('H:i') === $value;
}

function officer_posted_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function officer_parse_date(string $value): string|false|null
{
    if ($value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : false;
}

function officer_parse_event_payload(): array
{
    $title = officer_posted_string('title');
    $event_type = officer_posted_string('event_type');
    $description = officer_posted_string('description');
    $all_day = ($_POST['all_day'] ?? '') === '1';
    $start_date = officer_parse_date(officer_posted_string('start_date'));
    $end_date = officer_parse_date(officer_posted_string('end_date'));
    $start_time = officer_posted_string('start_time');
    $end_time = officer_posted_string('end_time');
    $event_types = ['meeting', 'event', 'agenda', 'deadline', 'other'];

    if ($title === '' || mb_strlen($title) > 150 || !in_array($event_type, $event_types, true) ||
        $start_date === false || $end_date === false || $start_date === null) {
        throw new InvalidArgumentException('Enter a valid title, event type, and start date.');
    }

    if ($all_day) {
        $start_at = $start_date . ' 00:00:00';
        $end_at = $end_date === null ? null : $end_date . ' 00:00:00';
        if ($end_at !== null && $end_at <= $start_at) {
            throw new InvalidArgumentException('The all-day end date must be after the start date.');
        }
    } else {
        if (!officer_valid_time($start_time) ||
            ($end_time !== '' && !officer_valid_time($end_time)) ||
            (($end_date === null) !== ($end_time === ''))) {
            throw new InvalidArgumentException('Enter a valid start time and, if supplied, both end date and time.');
        }
        $start_at = $start_date . ' ' . $start_time . ':00';
        $end_at = $end_date === null ? null : $end_date . ' ' . $end_time . ':00';
        if ($end_at !== null && $end_at <= $start_at) {
            throw new InvalidArgumentException('The event end must be after its start.');
        }
    }

    return [
        'title' => $title,
        'event_type' => $event_type,
        'description' => $description,
        'all_day' => $all_day ? 1 : 0,
        'start_at' => $start_at,
        'end_at' => $end_at,
    ];
}

if ($method === 'GET' && $action === 'officers') {
    $officers = $pdo->query(
        'SELECT officer_id, full_name, position, birthday FROM officers ORDER BY full_name, officer_id'
    )->fetchAll();
    officers_json_response(['officers' => $officers]);
}

if ($method === 'GET' && $action === 'student') {
    $raw_number = $_GET['student_no'] ?? '';
    $student_number = is_string($raw_number) ? normalize_student_number($raw_number) : '';
    if (!is_valid_student_number($student_number)) {
        officers_json_response(['found' => false, 'error' => 'Student number must match 0000-00000-BN-0.'], 422);
    }
    $student = $pdo->prepare('SELECT student_no, full_name FROM students WHERE student_no = ?');
    $student->execute([$student_number]);
    $row = $student->fetch();
    officers_json_response($row ? ['found' => true, 'student' => $row] : ['found' => false]);
}

if ($method === 'GET' && $action === 'events') {
    $raw_start = $_GET['start'] ?? '';
    $raw_end = $_GET['end'] ?? '';
    if (!is_string($raw_start) || !is_string($raw_end)) {
        officers_json_response(['error' => 'A calendar date range is required.'], 400);
    }
    try {
        $range_start = (new DateTimeImmutable($raw_start))->setTimezone(new DateTimeZone('Asia/Manila'));
        $range_end = (new DateTimeImmutable($raw_end))->setTimezone(new DateTimeZone('Asia/Manila'));
    } catch (Exception $exception) {
        officers_json_response(['error' => 'The calendar date range is invalid.'], 400);
    }
    if ($range_end <= $range_start) {
        officers_json_response(['error' => 'The calendar date range is invalid.'], 400);
    }

    $query = $pdo->prepare(
        'SELECT event_id, title, event_type, description, start_at, end_at, all_day, created_by
         FROM officer_events
         WHERE start_at < ? AND (end_at IS NULL OR end_at > ?)
         ORDER BY start_at'
    );
    $query->execute([$range_end->format('Y-m-d H:i:s'), $range_start->format('Y-m-d H:i:s')]);
    $events = [];
    $type_colors = [
        'meeting' => ['#4b5563', '#374151'],
        'event' => ['#5b6470', '#424a54'],
        'agenda' => ['#6b7280', '#4b5563'],
        'deadline' => ['#9b5c55', '#7f3f3a'],
        'other' => ['#77756f', '#5b5954'],
    ];
    foreach ($query->fetchAll() as $event) {
        $colors = $type_colors[$event['event_type']] ?? $type_colors['other'];
        $events[] = [
            'id' => (string)$event['event_id'],
            'title' => $event['title'],
            'start' => str_replace(' ', 'T', $event['start_at']),
            'end' => $event['end_at'] === null ? null : str_replace(' ', 'T', $event['end_at']),
            'allDay' => (bool)$event['all_day'],
            'backgroundColor' => $colors[0],
            'borderColor' => $colors[1],
            'extendedProps' => [
                'eventType' => $event['event_type'],
                'description' => $event['description'],
                'createdBy' => $event['created_by'],
                'readOnly' => false,
            ],
        ];
    }

    $birthdays = $pdo->query(
        "SELECT officer_id, full_name, birthday FROM officers
         WHERE birthday IS NOT NULL AND birthday <> '0000-00-00'"
    )->fetchAll();
    $first_year = (int)$range_start->format('Y');
    $last_year = (int)$range_end->format('Y');
    foreach ($birthdays as $officer) {
        $birthday_parts = explode('-', $officer['birthday']);
        if (count($birthday_parts) !== 3) {
            continue;
        }
        $month = (int)$birthday_parts[1];
        $day = (int)$birthday_parts[2];
        if (!checkdate($month, $day, 2000)) {
            continue;
        }
        for ($year = $first_year; $year <= $last_year; $year++) {
            $actual_month = $month;
            $actual_day = $day;
            if ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) {
                $actual_day = 28;
            }
            $birthday_date = sprintf('%04d-%02d-%02d', $year, $actual_month, $actual_day);
            if ($birthday_date < $range_start->format('Y-m-d') ||
                $birthday_date >= $range_end->format('Y-m-d')) {
                continue;
            }
            $events[] = [
                'id' => 'birthday-' . $officer['officer_id'] . '-' . $year,
                'title' => 'Birthday: ' . $officer['full_name'],
                'start' => $birthday_date,
                'allDay' => true,
                'backgroundColor' => '#e8e8e5',
                'borderColor' => '#c8c8c3',
                'textColor' => '#292929',
                'extendedProps' => ['readOnly' => true, 'eventType' => 'birthday'],
            ];
        }
    }
    officers_json_response($events);
}

if ($method === 'POST') {
    if (!isset($_SESSION['officers_csrf_token']) ||
        !hash_equals($_SESSION['officers_csrf_token'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        officers_json_response(['error' => 'Your session has expired. Refresh and unlock Officers again.'], 403);
    }

    if ($action === 'officer-create' || $action === 'officer-update') {
        $officer_id = normalize_student_number(officer_posted_string('student_no'));
        $original_id = normalize_student_number(officer_posted_string('original_student_no'));
        $position = officer_posted_string('position');
        $birthday = officer_parse_date(officer_posted_string('birthday'));
        if (!is_valid_student_number($officer_id) || $position === '' || mb_strlen($position) > 100 || $birthday === false) {
            officers_json_response(['error' => 'Enter a valid existing student number, position, and birthday.'], 422);
        }
        if ($action === 'officer-update' && $officer_id !== $original_id) {
            officers_json_response(['error' => 'An officer’s student number cannot be changed after registration.'], 422);
        }
        $student_query = $pdo->prepare('SELECT full_name FROM students WHERE student_no = ?');
        $student_query->execute([$officer_id]);
        $full_name = $student_query->fetchColumn();
        if ($full_name === false) {
            officers_json_response(['error' => 'That student number is not registered in Students.'], 422);
        }
        try {
            if ($action === 'officer-create') {
                $statement = $pdo->prepare(
                    'INSERT INTO officers (officer_id, full_name, position, birthday) VALUES (?, ?, ?, ?)'
                );
                $statement->execute([$officer_id, $full_name, $position, $birthday]);
            } else {
                $statement = $pdo->prepare(
                    'UPDATE officers SET full_name = ?, position = ?, birthday = ? WHERE officer_id = ?'
                );
                $statement->execute([$full_name, $position, $birthday, $original_id]);
                if ($statement->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT 1 FROM officers WHERE officer_id = ?');
                    $exists->execute([$original_id]);
                    if (!$exists->fetchColumn()) {
                        officers_json_response(['error' => 'Officer record not found. Refresh and try again.'], 404);
                    }
                }
            }
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                officers_json_response(['error' => 'This student is already registered as an officer.'], 409);
            }
            error_log('Officer save failed: ' . $exception->getMessage());
            officers_json_response(['error' => 'Unable to save the officer right now.'], 500);
        }
        officers_json_response(['success' => true, 'message' => 'Officer saved successfully.']);
    }

    if (in_array($action, ['event-create', 'event-update'], true)) {
        try {
            $event = officer_parse_event_payload();
        } catch (InvalidArgumentException $exception) {
            officers_json_response(['error' => $exception->getMessage()], 422);
        }
        try {
            if ($action === 'event-create') {
                $statement = $pdo->prepare(
                    'INSERT INTO officer_events
                        (title, event_type, description, start_at, end_at, all_day, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $statement->execute([
                    $event['title'], $event['event_type'], $event['description'], $event['start_at'],
                    $event['end_at'], $event['all_day'], (string)($_SESSION['full_name'] ?? 'Officer'),
                ]);
            } else {
                $event_id = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
                if ($event_id === false || $event_id === null || $event_id < 1) {
                    officers_json_response(['error' => 'Invalid calendar event.'], 422);
                }
                $statement = $pdo->prepare(
                    'UPDATE officer_events
                     SET title = ?, event_type = ?, description = ?, start_at = ?, end_at = ?, all_day = ?
                     WHERE event_id = ?'
                );
                $statement->execute([
                    $event['title'], $event['event_type'], $event['description'], $event['start_at'],
                    $event['end_at'], $event['all_day'], $event_id,
                ]);
                if ($statement->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT 1 FROM officer_events WHERE event_id = ?');
                    $exists->execute([$event_id]);
                    if (!$exists->fetchColumn()) {
                        officers_json_response(['error' => 'Calendar event not found. Refresh and try again.'], 404);
                    }
                }
            }
        } catch (PDOException $exception) {
            error_log('Officer calendar event save failed: ' . $exception->getMessage());
            officers_json_response(['error' => 'Unable to save the calendar event right now.'], 500);
        }
        officers_json_response(['success' => true, 'message' => 'Calendar event saved.']);
    }

    if ($action === 'event-delete') {
        $event_id = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
        if ($event_id === false || $event_id === null || $event_id < 1) {
            officers_json_response(['error' => 'Invalid calendar event.'], 422);
        }
        try {
            $statement = $pdo->prepare('DELETE FROM officer_events WHERE event_id = ?');
            $statement->execute([$event_id]);
        } catch (PDOException $exception) {
            error_log('Officer calendar event delete failed: ' . $exception->getMessage());
            officers_json_response(['error' => 'Unable to delete the calendar event right now.'], 500);
        }
        if ($statement->rowCount() !== 1) {
            officers_json_response(['error' => 'Calendar event not found. Refresh and try again.'], 404);
        }
        officers_json_response(['success' => true, 'message' => 'Calendar event deleted.']);
    }

    officers_json_response(['error' => 'Unknown Officers action.'], 400);
}

if ($method !== 'GET' || $action !== '') {
    officers_json_response(['error' => 'Unsupported request.'], 405);
}

if (empty($_SESSION['officers_csrf_token'])) {
    $_SESSION['officers_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['officers_csrf_token'];
$base = '../../';
$page_title = 'Officers';
include '../../includes/header.php';
?>
<div class="officers-page">
  <ul class="nav nav-tabs mb-3" id="officersTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="officer-list-tab" data-bs-toggle="tab" data-bs-target="#officer-list-panel" type="button" role="tab" aria-controls="officer-list-panel" aria-selected="true">Officer List</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="officer-calendar-tab" data-bs-toggle="tab" data-bs-target="#officer-calendar-panel" type="button" role="tab" aria-controls="officer-calendar-panel" aria-selected="false">Calendar</button>
    </li>
  </ul>
  <div class="tab-content">
    <section class="tab-pane fade show active" id="officer-list-panel" role="tabpanel" aria-labelledby="officer-list-tab">
      <div class="card">
        <div class="officer-list-heading">
          <div>
            <h2 class="mb-1">CSC Officers</h2>
            <p class="text-secondary mb-0">Officers must already be registered as students.</p>
          </div>
          <button class="btn" type="button" id="addOfficerButton">Register Officer</button>
        </div>
        <label for="officerSearch" class="visually-hidden">Search officers</label>
        <input class="form-control mb-3" type="search" id="officerSearch" placeholder="Search student number, name, or position">
        <div class="table-wrap">
          <table class="table table-hover align-middle">
            <thead><tr><th>Student Number</th><th>Name</th><th>Position</th><th>Actions</th></tr></thead>
            <tbody id="officerTableBody"><tr><td colspan="4" class="text-center text-secondary">Loading officers…</td></tr></tbody>
          </table>
        </div>
      </div>
    </section>
    <section class="tab-pane fade" id="officer-calendar-panel" role="tabpanel" aria-labelledby="officer-calendar-tab">
      <div class="card">
        <div class="officer-calendar-heading">
          <div><h2 class="mb-1">Officer Calendar</h2><p class="text-secondary mb-0">Select a time slot or day to schedule an event.</p></div>
          <button class="btn" type="button" id="addCalendarEventButton">Add Event</button>
        </div>
        <div id="officerCalendar" aria-label="CSC officer event calendar"></div>
      </div>
    </section>
  </div>
</div>

<div class="modal fade" id="officerModal" tabindex="-1" aria-labelledby="officerModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="officerForm">
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="officerModalTitle">Register Officer</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-error d-none" id="officerFormError" role="alert"></div>
          <input type="hidden" name="action" id="officerAction" value="officer-create">
          <input type="hidden" name="original_student_no" id="originalOfficerNumber">
          <label for="officerStudentNumber" class="form-label">Student Number</label>
          <input class="form-control scan-input" id="officerStudentNumber" name="student_no" type="text" placeholder="0000-00000-BN-0" maxlength="15" autocomplete="off" required>
          <div class="invalid-feedback" id="officerStudentNumberError">Enter a registered student number.</div>
          <div class="form-text" id="officerStudentName">Scan or enter an existing student number to verify it.</div>
          <label for="officerPosition" class="form-label">Position</label>
          <input class="form-control" id="officerPosition" name="position" type="text" maxlength="100" placeholder="e.g. VP for Internal Affairs" required>
          <label for="officerBirthday" class="form-label">Birthday</label>
          <input class="form-control" id="officerBirthday" name="birthday" type="date">
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn" type="submit" id="officerSubmit">Save Officer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="eventModal" tabindex="-1" aria-labelledby="eventModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form id="eventForm">
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="eventModalTitle">Add Calendar Event</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-error d-none" id="eventFormError" role="alert"></div>
          <input type="hidden" name="action" id="eventAction" value="event-create">
          <input type="hidden" name="event_id" id="eventId">
          <div class="row g-3">
            <div class="col-md-8">
              <label for="eventTitle" class="form-label">Title</label>
              <input class="form-control" id="eventTitle" name="title" type="text" maxlength="150" required>
            </div>
            <div class="col-md-4">
              <label for="eventType" class="form-label">Type</label>
              <select class="form-select" id="eventType" name="event_type" required>
                <option value="meeting">Meeting</option><option value="event">Event</option>
                <option value="agenda">Agenda</option><option value="deadline">Deadline</option><option value="other">Other</option>
              </select>
            </div>
            <div class="col-md-6">
              <label for="eventStartDate" class="form-label">Start Date</label>
              <input class="form-control" id="eventStartDate" name="start_date" type="date" required>
            </div>
            <div class="col-md-6">
              <label for="eventStartTime" class="form-label">Start Time</label>
              <input class="form-control" id="eventStartTime" name="start_time" type="time">
            </div>
            <div class="col-md-6">
              <label for="eventEndDate" class="form-label">End Date</label>
              <input class="form-control" id="eventEndDate" name="end_date" type="date">
            </div>
            <div class="col-md-6">
              <label for="eventEndTime" class="form-label">End Time</label>
              <input class="form-control" id="eventEndTime" name="end_time" type="time">
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" id="eventAllDay" name="all_day" type="checkbox" value="1">
                <label class="form-check-label" for="eventAllDay">All day</label>
              </div>
            </div>
            <div class="col-12">
              <label for="eventDescription" class="form-label">Description</label>
              <textarea class="form-control" id="eventDescription" name="description" rows="3"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer justify-content-between">
          <button class="btn btn-danger d-none" type="button" id="deleteEventButton">Delete Event</button>
          <div class="ms-auto d-flex gap-2">
            <button class="btn btn-outline" type="button" data-bs-dismiss="modal">Cancel</button>
            <button class="btn" type="submit" id="saveEventButton">Save Event</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="<?php echo $base; ?>assets/vendor/fullcalendar/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  'use strict';
  var csrfToken = <?php echo json_encode($csrf_token); ?>;
  var officerRows = [];
  var currentEvent = null;
  var calendar = null;
  var officerModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('officerModal'));
  var eventModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('eventModal'));
  var studentLookupTimer = null;
  var lastStudentLookup = '';

  function request(url, options) {
    options = options || {};
    options.headers = Object.assign({}, options.headers || {}, { 'X-CSRF-Token': csrfToken });
    return fetch(url, options).then(function (response) {
      if ((response.headers.get('content-type') || '').indexOf('application/json') === -1) {
        throw new Error('The request returned an unexpected response. Refresh and unlock Officers again.');
      }
      return response.json().then(function (payload) {
        if (!response.ok) throw new Error(payload.error || 'Request failed.');
        return payload;
      });
    });
  }

  function postForm(form) {
    return request('officers.php', {
      method: 'POST',
      body: new URLSearchParams(new FormData(form))
    });
  }

  function showError(element, message) {
    element.textContent = message;
    element.classList.remove('d-none');
  }

  function clearError(element) {
    element.textContent = '';
    element.classList.add('d-none');
  }

  function localDate(date) {
    return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' +
      String(date.getDate()).padStart(2, '0');
  }

  function localTime(date) {
    return String(date.getHours()).padStart(2, '0') + ':' + String(date.getMinutes()).padStart(2, '0');
  }

  function refreshOfficers() {
    return request('officers.php?action=officers').then(function (payload) {
      officerRows = payload.officers;
      renderOfficers();
      if (calendar) calendar.refetchEvents();
    });
  }

  function renderOfficers() {
    var body = document.getElementById('officerTableBody');
    var term = document.getElementById('officerSearch').value.trim().toLocaleLowerCase();
    var matches = officerRows.filter(function (officer) {
      return !term || [officer.officer_id, officer.full_name, officer.position].join(' ').toLocaleLowerCase().includes(term);
    });
    body.replaceChildren();
    if (!matches.length) {
      var empty = document.createElement('tr');
      var cell = document.createElement('td');
      cell.colSpan = 4;
      cell.className = 'text-center text-secondary py-4';
      cell.textContent = officerRows.length ? 'No officers match your search.' : 'No officers registered yet.';
      empty.appendChild(cell);
      body.appendChild(empty);
      return;
    }
    matches.forEach(function (officer) {
      var row = document.createElement('tr');
      [officer.officer_id, officer.full_name, officer.position].forEach(function (value) {
        var cell = document.createElement('td');
        cell.textContent = value || '—';
        row.appendChild(cell);
      });
      var actions = document.createElement('td');
      var edit = document.createElement('button');
      edit.type = 'button';
      edit.className = 'btn btn-outline btn-sm';
      edit.textContent = 'Edit';
      edit.addEventListener('click', function () { openOfficerModal(officer); });
      actions.appendChild(edit);
      row.appendChild(actions);
      body.appendChild(row);
    });
  }

  function openOfficerModal(officer) {
    var form = document.getElementById('officerForm');
    form.reset();
    clearError(document.getElementById('officerFormError'));
    var editing = Boolean(officer);
    document.getElementById('officerModalTitle').textContent = editing ? 'Edit Officer' : 'Register Officer';
    document.getElementById('officerAction').value = editing ? 'officer-update' : 'officer-create';
    document.getElementById('officerStudentNumber').value = editing ? officer.officer_id : '';
    document.getElementById('originalOfficerNumber').value = editing ? officer.officer_id : '';
    document.getElementById('officerStudentNumber').readOnly = editing;
    document.getElementById('officerStudentName').textContent = editing
      ? officer.full_name + (officer.birthday ? ' · Birthday: ' + officer.birthday : ' · Birthday not set')
      : 'Scan or enter an existing student number to verify it.';
    document.getElementById('officerPosition').value = editing ? officer.position : '';
    document.getElementById('officerBirthday').value = editing && officer.birthday ? officer.birthday : '';
    document.getElementById('officerStudentNumber').classList.remove('is-invalid', 'is-valid');
    officerModal.show();
  }

  function setEventDateFields(startDate, startTime, endDate, endTime, allDay) {
    document.getElementById('eventStartDate').value = startDate || '';
    document.getElementById('eventStartTime').value = startTime || '';
    document.getElementById('eventEndDate').value = endDate || '';
    document.getElementById('eventEndTime').value = endTime || '';
    document.getElementById('eventAllDay').checked = Boolean(allDay);
    updateEventTimeFields();
  }

  function updateEventTimeFields() {
    var allDay = document.getElementById('eventAllDay').checked;
    ['eventStartTime', 'eventEndTime'].forEach(function (id) {
      var input = document.getElementById(id);
      input.disabled = allDay;
      input.required = !allDay && id === 'eventStartTime';
    });
    document.getElementById('eventEndDate').required =
      !allDay && document.getElementById('eventEndTime').value !== '';
  }

  function openNewEvent(start, end, allDay) {
    document.getElementById('eventForm').reset();
    clearError(document.getElementById('eventFormError'));
    currentEvent = null;
    document.getElementById('eventModalTitle').textContent = 'Add Calendar Event';
    document.getElementById('eventAction').value = 'event-create';
    document.getElementById('eventId').value = '';
    document.getElementById('deleteEventButton').classList.add('d-none');
    document.getElementById('saveEventButton').classList.remove('d-none');
    document.getElementById('eventTitle').disabled = false;
    document.getElementById('eventType').disabled = false;
    document.getElementById('eventDescription').disabled = false;
    document.getElementById('eventStartDate').disabled = false;
    document.getElementById('eventEndDate').disabled = false;
    document.getElementById('eventAllDay').disabled = false;
    if (start) {
      var startDate = localDate(start);
      var endDate = end ? localDate(end) : '';
      if (allDay && end && endDate > startDate) {
        var endExclusive = new Date(end.getTime() - 86400000);
        endDate = localDate(endExclusive);
      }
      setEventDateFields(
        startDate,
        !allDay && start.getHours() + start.getMinutes() > 0 ? localTime(start) : '',
        endDate,
        !allDay && end ? localTime(end) : '',
        allDay
      );
    } else {
      setEventDateFields(localDate(new Date()), '', '', '', false);
    }
    eventModal.show();
  }

  function openExistingEvent(event) {
    currentEvent = event;
    document.getElementById('eventForm').reset();
    clearError(document.getElementById('eventFormError'));
    var readOnly = Boolean(event.extendedProps.readOnly);
    document.getElementById('eventModalTitle').textContent = readOnly ? 'Officer Birthday' : 'View / Edit Calendar Event';
    document.getElementById('eventAction').value = 'event-update';
    document.getElementById('eventId').value = event.id;
    document.getElementById('eventTitle').value = event.title;
    document.getElementById('eventType').value = event.extendedProps.eventType === 'birthday'
      ? 'other' : (event.extendedProps.eventType || 'other');
    document.getElementById('eventDescription').value = event.extendedProps.description || '';
    var startDate = event.start ? localDate(event.start) : '';
    var endDate = event.end ? localDate(event.end) : '';
    if (event.allDay && endDate && endDate > startDate) {
      endDate = localDate(new Date(event.end.getTime() - 86400000));
    }
    setEventDateFields(
      startDate,
      event.allDay || !event.start ? '' : localTime(event.start),
      endDate,
      event.allDay || !event.end ? '' : localTime(event.end),
      event.allDay
    );
    document.getElementById('deleteEventButton').classList.toggle('d-none', readOnly);
    document.getElementById('saveEventButton').classList.toggle('d-none', readOnly);
    ['eventTitle', 'eventType', 'eventDescription', 'eventStartDate', 'eventEndDate',
      'eventAllDay', 'eventStartTime', 'eventEndTime']
      .forEach(function (id) {
        var disabled = readOnly || (event.allDay && (id === 'eventStartTime' || id === 'eventEndTime'));
        document.getElementById(id).disabled = disabled;
      });
    eventModal.show();
  }

  document.getElementById('officerSearch').addEventListener('input', renderOfficers);
  document.getElementById('addOfficerButton').addEventListener('click', function () { openOfficerModal(null); });
  document.getElementById('addCalendarEventButton').addEventListener('click', function () {
    openNewEvent(null, null, false);
  });
  document.getElementById('eventAllDay').addEventListener('change', updateEventTimeFields);
  document.getElementById('eventEndTime').addEventListener('input', updateEventTimeFields);
  document.getElementById('eventEndDate').addEventListener('input', updateEventTimeFields);

  document.getElementById('officerStudentNumber').addEventListener('input', function (event) {
    var input = event.currentTarget;
    var normalized = window.normalizeStudentNumber(input.value);
    if (/^\d{4}-\d{5}-BN-\d$/.test(normalized) || /^\d{9}BN\d$/.test(normalized)) {
      input.value = normalized;
    }
    clearTimeout(studentLookupTimer);
    var name = document.getElementById('officerStudentName');
    if (!input.value) {
      input.classList.remove('is-valid', 'is-invalid');
      name.textContent = 'Scan or enter an existing student number to verify it.';
      lastStudentLookup = '';
      return;
    }
    if (!/^\d{4}-\d{5}-BN-\d$/.test(window.normalizeStudentNumber(input.value))) {
      input.classList.add('is-invalid');
      input.classList.remove('is-valid');
      name.textContent = 'Student number must match 0000-00000-BN-0.';
      return;
    }
    input.classList.remove('is-invalid');
    studentLookupTimer = window.setTimeout(function () {
      var studentNumber = window.normalizeStudentNumber(input.value);
      lastStudentLookup = studentNumber;
      request('officers.php?action=student&student_no=' + encodeURIComponent(studentNumber))
        .then(function (payload) {
          if (lastStudentLookup !== studentNumber) return;
          if (!payload.found) {
            input.classList.add('is-invalid');
            input.classList.remove('is-valid');
            name.textContent = 'No matching student. Register the student first.';
            return;
          }
          input.classList.add('is-valid');
          input.classList.remove('is-invalid');
          name.textContent = payload.student.full_name;
        })
        .catch(function (error) {
          input.classList.add('is-invalid');
          name.textContent = error.message;
        });
    }, 180);
  });

  document.getElementById('officerForm').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.currentTarget;
    var studentNumber = document.getElementById('officerStudentNumber');
    studentNumber.value = window.normalizeStudentNumber(studentNumber.value);
    if (!form.reportValidity()) {
      window.notify.error('Check the officer details', 'Complete the required fields with valid values.');
      return;
    }
    var submit = document.getElementById('officerSubmit');
    submit.disabled = true;
    postForm(form).then(function (payload) {
      officerModal.hide();
      window.notify.success('Officer saved', payload.message);
      return refreshOfficers();
    }).catch(function (error) {
      showError(document.getElementById('officerFormError'), error.message);
      window.notify.error('Officer could not be saved', error.message || 'Unable to save the officer.');
    }).finally(function () { submit.disabled = false; });
  });

  function eventFormData() {
    var form = document.getElementById('eventForm');
    var data = new URLSearchParams(new FormData(form));
    var allDay = document.getElementById('eventAllDay').checked;
    data.set('all_day', allDay ? '1' : '0');
    if (allDay) {
      data.set('start_time', '');
      data.set('end_time', '');
      var endDate = document.getElementById('eventEndDate').value;
      if (endDate) {
        var inclusiveEnd = new Date(endDate + 'T00:00:00');
        inclusiveEnd.setDate(inclusiveEnd.getDate() + 1);
        data.set('end_date', localDate(inclusiveEnd));
      }
    }
    return data;
  }

  document.getElementById('eventForm').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.currentTarget;
    updateEventTimeFields();
    if (!form.reportValidity()) {
      window.notify.error('Check the event details', 'Complete the required fields with valid values.');
      return;
    }
    var submit = document.getElementById('saveEventButton');
    submit.disabled = true;
    var eventAction = form.elements.action.value;
    request('officers.php', { method: 'POST', body: eventFormData() })
      .then(function (payload) {
        eventModal.hide();
        window.notify.success(eventAction === 'event-create' ? 'Event added' : 'Event updated', payload.message);
        calendar.refetchEvents();
      })
      .catch(function (error) {
        showError(document.getElementById('eventFormError'), error.message);
        window.notify.error('Event could not be saved', error.message || 'Unable to save the event.');
      })
      .finally(function () { submit.disabled = false; });
  });

  document.getElementById('deleteEventButton').addEventListener('click', async function (event) {
    var button = event.currentTarget;
    if (!currentEvent || button.disabled) return;
    var confirmed = await window.notify.confirm({
      title: 'Delete this calendar event?',
      text: 'This action cannot be undone.',
      confirmText: 'Delete event',
      cancelText: 'Cancel',
      danger: true
    });
    if (!confirmed) return;
    button.disabled = true;
    var data = new URLSearchParams();
    data.set('action', 'event-delete');
    data.set('event_id', currentEvent.id);
    request('officers.php', { method: 'POST', body: data })
      .then(function (payload) {
        eventModal.hide();
        window.notify.success('Event deleted', payload.message);
        calendar.refetchEvents();
      })
      .catch(function (error) {
        showError(document.getElementById('eventFormError'), error.message);
        window.notify.error('Event could not be deleted', error.message || 'Unable to delete the event.');
      })
      .finally(function () { button.disabled = false; });
  });

  function rescheduleEvent(info) {
    var event = info.event;
    if (event.extendedProps.readOnly) {
      info.revert();
      return;
    }
    var data = new URLSearchParams();
    data.set('action', 'event-update');
    data.set('event_id', event.id);
    data.set('title', event.title);
    data.set('event_type', event.extendedProps.eventType || 'other');
    data.set('description', event.extendedProps.description || '');
    data.set('all_day', event.allDay ? '1' : '0');
    data.set('start_date', localDate(event.start));
    data.set('start_time', event.allDay ? '' : localTime(event.start));
    if (event.end) {
      if (event.allDay) {
        data.set('end_date', localDate(event.end));
      } else {
        data.set('end_date', localDate(event.end));
        data.set('end_time', localTime(event.end));
      }
    }
    request('officers.php', { method: 'POST', body: data })
      .then(function (payload) {
        window.notify.success('Event updated', payload.message);
        calendar.refetchEvents();
      })
      .catch(function (error) {
        info.revert();
        window.notify.error('Event could not be updated', error.message || 'Unable to update the event.');
      });
  }

  function startCalendar() {
    if (calendar) {
      calendar.updateSize();
      return;
    }
    calendar = new FullCalendar.Calendar(document.getElementById('officerCalendar'), {
      initialView: 'dayGridMonth',
      height: 'auto',
      nowIndicator: true,
      selectable: true,
      editable: true,
      eventDurationEditable: true,
      dayMaxEvents: true,
      headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
      },
      buttonText: { today: 'Today', month: 'Month', week: 'Week', day: 'Day', list: 'List' },
      events: function (info, success, failure) {
        request('officers.php?action=events&start=' + encodeURIComponent(info.startStr) +
          '&end=' + encodeURIComponent(info.endStr))
          .then(success)
          .catch(failure);
      },
      dateClick: function (info) {
        openNewEvent(info.date, null, info.allDay);
      },
      select: function (info) {
        openNewEvent(info.start, info.end, info.allDay);
        calendar.unselect();
      },
      eventClick: function (info) { openExistingEvent(info.event); },
      eventDrop: rescheduleEvent,
      eventResize: rescheduleEvent,
      eventAllow: function (dropInfo, event) { return !event.extendedProps.readOnly; }
    });
    calendar.render();
  }

  document.getElementById('officer-calendar-tab').addEventListener('shown.bs.tab', startCalendar);
  document.getElementById('eventAllDay').checked = false;
  updateEventTimeFields();
  refreshOfficers().catch(function (error) {
    var body = document.getElementById('officerTableBody');
    body.replaceChildren();
    var row = document.createElement('tr');
    var cell = document.createElement('td');
    cell.colSpan = 4;
    cell.className = 'text-center text-danger py-4';
    cell.textContent = 'Unable to load officers: ' + error.message;
    row.appendChild(cell);
    body.appendChild(row);
  });
});
</script>
<?php include '../../includes/footer.php'; ?>
