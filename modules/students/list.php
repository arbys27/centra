<?php
require_once '../../includes/auth_check.php';
require_once '../../includes/pin_gate.php';
require_pin('students');
require_once '../../config/db.php';
require_once '../../includes/db.php';

$pdo = get_pdo_connection();
$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? '';
  
function students_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function posted_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

if ($request_method === 'GET' && $action === 'list') {
    $students = $pdo->query(
        'SELECT s.student_no, s.full_name, s.program, s.year_level, s.section,
                s.contact_number, s.date_registered,
                active.blacklist_id, active.reason, active.offense_type,
                active.blacklisted_by, active.blacklisted_at,
                (SELECT COUNT(*) FROM student_blacklist history
                 WHERE history.student_number = s.student_no) AS history_count
         FROM students s
         LEFT JOIN student_blacklist active
           ON active.blacklist_id = (
             SELECT blacklist_id FROM student_blacklist
             WHERE student_number = s.student_no AND is_active = 1
             ORDER BY blacklisted_at DESC, blacklist_id DESC LIMIT 1
           )
         ORDER BY s.date_registered DESC, s.full_name ASC'
    )->fetchAll();
    $imported = (int)$pdo->query('SELECT COUNT(*) FROM import_log')->fetchColumn() > 0;
    students_json_response(['students' => $students, 'masterlist_imported' => $imported]);
}

if ($request_method === 'GET' && $action === 'history') {
    $raw_student_number = $_GET['student_no'] ?? '';
    $student_number = is_string($raw_student_number) ? normalize_student_number($raw_student_number) : '';
    if (!is_valid_student_number($student_number)) {
        students_json_response(['error' => 'Invalid student number format.'], 400);
    }
    $history_statement = $pdo->prepare(
        'SELECT blacklist_id, reason, offense_type, blacklisted_by, blacklisted_at,
                lifted_at, lifted_by, lift_note, is_active
         FROM student_blacklist
         WHERE student_number = ?
         ORDER BY blacklisted_at DESC, blacklist_id DESC'
    );
    $history_statement->execute([$student_number]);
    students_json_response(['history' => $history_statement->fetchAll()]);
}

if ($request_method === 'POST') {
    if (!isset($_SESSION['students_csrf_token']) ||
        !hash_equals($_SESSION['students_csrf_token'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        students_json_response(['error' => 'Your session has expired. Refresh the Students page and try again.'], 403);
    }

    if ($action === 'register') {
        $raw_student_number = posted_string('student_no');
        $student_number = normalize_student_number($raw_student_number);
        $full_name = posted_string('full_name');
        $program = posted_string('program');
        $year_level = posted_string('year_level');
        $section = posted_string('section');
        $contact_number = posted_string('contact_number');

        if (!is_valid_student_number($student_number)) {
            students_json_response(['error' => 'Student number must match 0000-00000-BN-0.', 'field' => 'student_no'], 422);
        }
        if ($full_name === '' || mb_strlen($full_name) > 150 ||
            $program === '' || mb_strlen($program) > 100 ||
            !in_array($year_level, ['1', '2', '3', '4', '5'], true) ||
            $section === '' || mb_strlen($section) > 20 ||
            $contact_number === '' || mb_strlen($contact_number) > 30) {
            students_json_response(['error' => 'Check the required fields and their maximum lengths.'], 422);
        }

        try {
            $insert = $pdo->prepare(
                'INSERT INTO students (student_no, full_name, program, year_level, section, contact_number)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$student_number, $full_name, $program, $year_level, $section, $contact_number]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                students_json_response(['error' => 'This student number is already registered.', 'field' => 'student_no'], 409);
            }
            error_log('Student registration failed: ' . $exception->getMessage());
            students_json_response(['error' => 'Unable to register the student right now.'], 500);
        }

        students_json_response(['success' => true, 'message' => 'Student registered successfully.']);
    }

    if ($action === 'blacklist') {
        $raw_student_number = posted_string('student_no');
        $student_number = normalize_student_number($raw_student_number);
        $offense_type = posted_string('offense_type');
        $officer_name = posted_string('officer_name');
        $offense_labels = [
            'unpaid_fee' => 'Unpaid fee',
            'item_damage_or_loss' => 'Item damage or loss',
            'overdue_items' => 'Overdue items',
            'misconduct' => 'Misconduct',
            'other' => 'Other',
        ];

        if (!is_valid_student_number($student_number)) {
            students_json_response(['error' => 'Invalid student number format.'], 422);
        }
        if (!isset($offense_labels[$offense_type]) ||
            mb_strlen($officer_name) > 100 || $officer_name === '') {
            students_json_response(['error' => 'Select an offense and provide the officer name.'], 422);
        }
        $reason = $offense_labels[$offense_type];

        try {
            $pdo->beginTransaction();
            $student_check = $pdo->prepare('SELECT student_no FROM students WHERE student_no = ? FOR UPDATE');
            $student_check->execute([$student_number]);
            if ($student_check->fetchColumn() === false) {
                $pdo->rollBack();
                students_json_response(['error' => 'Student record not found.'], 404);
            }
            $active_check = $pdo->prepare(
                'SELECT blacklist_id FROM student_blacklist WHERE student_number = ? AND is_active = 1 LIMIT 1'
            );
            $active_check->execute([$student_number]);
            if ($active_check->fetchColumn() !== false) {
                $pdo->rollBack();
                students_json_response(['error' => 'This student is already blacklisted.'], 409);
            }
            $insert = $pdo->prepare(
                'INSERT INTO student_blacklist
                    (student_number, reason, offense_type, blacklisted_by, is_active)
                 VALUES (?, ?, ?, ?, 1)'
            );
            $insert->execute([$student_number, $reason, $offense_type, $officer_name]);
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Student blacklist update failed: ' . $exception->getMessage());
            students_json_response(['error' => 'Unable to blacklist the student right now.'], 500);
        }

        students_json_response(['success' => true, 'message' => 'Student blacklisted. All CSC services are now blocked.']);
    }

    if ($action === 'lift') {
        $blacklist_id = filter_var($_POST['blacklist_id'] ?? null, FILTER_VALIDATE_INT);
        $officer_name = posted_string('officer_name');
        $lift_note = posted_string('lift_note');
        if ($blacklist_id === false || $blacklist_id === null || $blacklist_id < 1 ||
            $officer_name === '' || mb_strlen($officer_name) > 100 ||
            $lift_note === '' || mb_strlen($lift_note) > 255) {
            students_json_response(['error' => 'Provide who lifted the blacklist and a reason for lifting it.'], 422);
        }
        try {
            $lift = $pdo->prepare(
                'UPDATE student_blacklist
                 SET is_active = 0, lifted_at = CURRENT_TIMESTAMP, lifted_by = ?, lift_note = ?
                 WHERE blacklist_id = ? AND is_active = 1'
            );
            $lift->execute([$officer_name, $lift_note, $blacklist_id]);
        } catch (PDOException $exception) {
            error_log('Student blacklist lift failed: ' . $exception->getMessage());
            students_json_response(['error' => 'Unable to lift the blacklist right now.'], 500);
        }
        if ($lift->rowCount() !== 1) {
            students_json_response(['error' => 'This blacklist entry is no longer active. Refresh and try again.'], 409);
        }
        students_json_response(['success' => true, 'message' => 'Blacklist lifted. The history has been kept.']);
    }

    students_json_response(['error' => 'Unknown Students action.'], 400);
}

if ($request_method !== 'GET' || $action !== '') {
    students_json_response(['error' => 'Unsupported request.'], 405);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['students_csrf_token'])) {
    $_SESSION['students_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['students_csrf_token'];
$masterlist_imported = (int)$pdo->query('SELECT COUNT(*) FROM import_log')->fetchColumn() > 0;
$base = '../../';
$page_title = 'Students';
include '../../includes/header.php';
?>
<style>
  /* Match these tokens to your theme if the fallbacks differ. */
  .student-page, .st-modal {
    --st-ink: var(--primary, #111111);
    --st-surface: var(--card-bg, #ffffff);
    --st-text: var(--text, #1b1b1a);
    --st-muted: var(--text-muted, #6b6b66);
    --st-line: var(--border, rgba(17, 17, 17, .11));
    --st-soft: rgba(17, 17, 17, .045);
    --st-red: var(--danger, #a63a32);
    --st-red-soft: rgba(166, 58, 50, .10);
    --st-green: #2f6b4f;
    --st-green-soft: #e3efe8;
  }

  /* Header */
  .st-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 22px; }
  .st-head h2 { margin: 0 0 4px; font-size: 1.6rem; font-weight: 700; letter-spacing: -.01em; }
  .st-head p { margin: 0; color: var(--st-muted); font-size: .95rem; }
  .st-primary {
    display: inline-flex; align-items: center; gap: 8px; height: 42px; padding: 0 18px;
    border: 0; border-radius: 12px; background: var(--st-ink); color: #fff; font: inherit; font-size: .92rem; font-weight: 600;
    cursor: pointer; transition: transform .12s, box-shadow .15s;
  }
  .st-primary:hover { box-shadow: 0 8px 18px -8px rgba(17, 17, 17, .55); }
  .st-primary:active { transform: translateY(1px); }
  .st-primary svg { width: 16px; height: 16px; }

  /* Stats */
  .st-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 22px; }
  .st-stat { padding: 14px 16px; border: 1px solid var(--st-line); border-radius: 14px; background: var(--st-soft); }
  .st-stat-label { display: flex; align-items: center; gap: 8px; font-size: .82rem; color: var(--st-muted); }
  .st-stat-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--st-ink); }
  .st-stat-dot.ok { background: var(--st-green); }
  .st-stat-dot.bad { background: var(--st-red); }
  .st-stat-value { margin-top: 4px; font-size: 1.6rem; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; }

  /* Toolbar */
  .st-toolbar { display: flex; gap: 12px; margin-bottom: 14px; }
  .st-search { position: relative; flex: 1; min-width: 0; }
  .st-search svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 17px; height: 17px; color: var(--st-muted); pointer-events: none; }
  .st-search input.form-control {
    height: 44px; padding-left: 42px; border: 1.5px solid var(--st-line); border-radius: 12px; background: var(--st-surface); box-shadow: none; font-size: .92rem;
  }
  .st-toolbar select.form-select {
    width: auto; height: 44px; padding-left: 14px; padding-right: 36px; border: 1.5px solid var(--st-line); border-radius: 12px; box-shadow: none; font-size: .92rem; cursor: pointer;
  }
  .st-search input.form-control:focus, .st-toolbar select.form-select:focus, .st-modal .form-control:focus, .st-modal .form-select:focus {
    border-color: var(--st-ink); box-shadow: 0 0 0 4px rgba(17, 17, 17, .08);
  }

  /* Table */
  .st-table { width: 100%; margin: 0; border-collapse: separate; border-spacing: 0; }
  .st-table thead th {
    padding: 10px 14px; border-bottom: 1px solid var(--st-line); background: transparent;
    font-size: .78rem; font-weight: 600; color: var(--st-muted); white-space: nowrap; text-align: left;
  }
  .st-table thead th:last-child { text-align: right; }
  .student-sort {
    display: inline-flex; align-items: center; gap: 6px; padding: 0; border: 0; background: none;
    font: inherit; color: inherit; cursor: pointer; transition: color .15s;
  }
  .student-sort:hover { color: var(--st-ink); }
  th[aria-sort="ascending"] .student-sort, th[aria-sort="descending"] .student-sort { color: var(--st-ink); }
  th[aria-sort="ascending"] .student-sort::after { content: "↑"; }
  th[aria-sort="descending"] .student-sort::after { content: "↓"; }
  .st-table tbody td { padding: 14px; border-bottom: 1px solid var(--st-line); vertical-align: middle; background: transparent; font-size: .92rem; }
  .st-table tbody tr { transition: background-color .15s; }
  .st-table tbody tr:hover td { background: var(--st-soft); }
  .st-table tbody tr:last-child td { border-bottom: 0; }
  .student-table th:nth-child(1) { width: 23%; }
  .student-table th:nth-child(2) { width: 29%; }
  .student-table th:nth-child(3) { width: 20%; }
  .student-table th:nth-child(4) { width: 14%; }
  .student-table th:nth-child(5) { width: 14%; }
  .st-num { font-variant-numeric: tabular-nums; white-space: nowrap; font-weight: 500; }
  .st-person { display: flex; align-items: center; }
  .st-name { font-weight: 600; }
  .st-sub { font-size: .8rem; color: var(--st-muted); }
  .st-blacklisted-tag { display: inline-block; margin-top: 4px; color: var(--st-red); font-size: .72rem; font-weight: 650; }
  .student-row-blacklisted td { background: rgba(166, 58, 50, .025); }
  .st-badge { display: inline-flex; align-items: center; gap: 7px; padding: 4px 11px; border-radius: 999px; font-size: .8rem; font-weight: 600; white-space: nowrap; }
  .st-badge::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
  .st-badge-ok { color: var(--st-green); background: var(--st-green-soft); }
  .st-badge-bad { color: var(--st-red); background: var(--st-red-soft); }
  .st-badge-off { color: var(--st-muted); background: var(--st-soft); }

  .student-row-actions { display: flex; justify-content: flex-end; gap: 8px; }
  .st-act {
    height: 34px; padding: 0 14px; border: 1.5px solid transparent; border-radius: 10px; font: inherit; font-size: .84rem; font-weight: 600;
    cursor: pointer; white-space: nowrap; transition: background-color .15s, color .15s, border-color .15s;
  }
  .st-act-danger { color: var(--st-red); background: var(--st-red-soft); }
  .st-act-danger:hover { color: #fff; background: var(--st-red); }
  .st-act-ghost { color: var(--st-text); background: transparent; border-color: var(--st-line); }
  .st-act-ghost:hover { border-color: var(--st-ink); }
  .st-act:disabled { opacity: .45; cursor: not-allowed; }
  .st-empty { padding: 44px 16px !important; text-align: center; color: var(--st-muted); }

  /* Pagination */
  .student-pagination { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding-top: 16px; border-top: 1px solid var(--st-line); }
  .student-pagination .st-pager { display: flex; gap: 8px; }

  /* Modals */
  .st-modal .modal-content { border: 0; border-radius: 20px; background: var(--st-surface); box-shadow: 0 30px 70px -20px rgba(17, 17, 17, .45); }
  .st-modal .modal-header { padding: 22px 26px 6px; border-bottom: 0; }
  .st-modal .modal-title { font-weight: 700; }
  .st-modal .modal-body { padding: 14px 26px 6px; }
  .st-modal .modal-footer { padding: 14px 26px 24px; border-top: 0; gap: 10px; }
  .st-modal .form-label { margin-bottom: 6px; font-size: .86rem; font-weight: 600; }
  .st-modal .form-control, .st-modal .form-select { min-height: 44px; border: 1.5px solid var(--st-line); border-radius: 12px; box-shadow: none; }
  .st-modal textarea.form-control { min-height: 0; }
  .st-modal .btn { min-height: 42px; border-radius: 12px; font-weight: 600; }
  .student-modal-summary { padding: 12px 14px; border: 1px solid var(--st-line); border-radius: 12px; background: var(--st-soft); font-size: .88rem; margin-bottom: 14px; }
  .blacklist-history-entry { padding: 14px 16px; margin-bottom: 10px; border: 1px solid var(--st-line); border-left: 4px solid var(--st-muted); border-radius: 12px; }
  .blacklist-history-entry.is-active { border-left-color: var(--st-red); }
  .st-import-dropzone { padding: 28px 18px; border: 2px dashed var(--st-line); border-radius: 14px; background: var(--st-soft); text-align: center; cursor: pointer; }
  .st-import-dropzone.is-dragging { border-color: var(--st-ink); background: rgba(17, 17, 17, .08); }
  .st-import-preview { max-height: 360px; overflow: auto; }
  .st-import-preview table { min-width: 840px; }
  .st-import-summary { display: flex; gap: 8px; flex-wrap: wrap; margin: 14px 0; }
  .st-import-summary span { padding: 6px 10px; border: 1px solid var(--st-line); border-radius: 999px; font-size: .82rem; }
  .st-import-reason { display: block; margin-top: 4px; color: var(--st-red); font-size: .78rem; }

  @media (max-width: 720px) {
    .st-stats { grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
    .st-stat { padding: 12px; }
    .st-stat-value { font-size: 1.3rem; }
    .st-toolbar { flex-direction: column; }
    .st-toolbar select.form-select { width: 100%; }
    .st-primary { width: 100%; justify-content: center; }
  }
  @media (prefers-reduced-motion: reduce) {
    .st-primary, .st-act, .st-table tbody tr { transition: none; }
  }
</style>

<div class="student-page">
  <section class="card">
    <div class="st-head">
      <div>
        <h2>Student Registry</h2>
        <p>Search, register, and manage student service access.</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-dark<?php echo $masterlist_imported ? ' d-none' : ''; ?>" type="button" id="importStudentsButton" data-bs-toggle="modal" data-bs-target="#importStudentsModal">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4m0 0L8 8m4-4 4 4"/><path d="M4 16v4h16v-4"/></svg>
          Import CSV
        </button>
        <button class="st-primary" type="button" data-bs-toggle="modal" data-bs-target="#registerStudentModal">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
          Register a Student
        </button>
      </div>
    </div>

    <div class="st-stats" aria-label="Student summary">
      <div class="st-stat">
        <div class="st-stat-label"><span class="st-stat-dot"></span>Total students</div>
        <div class="st-stat-value" id="statTotal">–</div>
      </div>
      <div class="st-stat">
        <div class="st-stat-label"><span class="st-stat-dot ok"></span>Active</div>
        <div class="st-stat-value" id="statActive">–</div>
      </div>
      <div class="st-stat">
        <div class="st-stat-label"><span class="st-stat-dot bad"></span>Blacklisted</div>
        <div class="st-stat-value" id="statBlacklisted">–</div>
      </div>
    </div>

    <div class="st-toolbar">
      <div class="st-search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <label for="studentSearch" class="visually-hidden">Search students</label>
        <input type="search" id="studentSearch" class="form-control" placeholder="Search number, name, program, year, or section">
      </div>
      <label for="studentPageSize" class="visually-hidden">Students per page</label>
      <select id="studentPageSize" class="form-select">
        <option value="10">10 per page</option>
        <option value="25">25 per page</option>
        <option value="50">50 per page</option>
      </select>
    </div>

    <div class="table-wrap">
      <table class="st-table student-table">
        <thead>
          <tr>
            <th scope="col" data-col="student_no"><button type="button" class="student-sort" data-sort="student_no">Student number</button></th>
            <th scope="col" data-col="full_name"><button type="button" class="student-sort" data-sort="full_name">Name</button></th>
            <th scope="col" data-col="program"><button type="button" class="student-sort" data-sort="program">Program</button></th>
            <th scope="col" data-col="year_section"><button type="button" class="student-sort" data-sort="year_section">Year and section</button></th>
            <th scope="col">Actions</th>
          </tr>
        </thead>
        <tbody id="studentTableBody"><tr><td colspan="5" class="st-empty">Loading students…</td></tr></tbody>
      </table>
    </div>
    <div class="student-pagination">
      <span id="studentResultCount" class="text-secondary" aria-live="polite"></span>
      <div class="st-pager" role="group" aria-label="Student pages">
        <button class="st-act st-act-ghost" type="button" id="studentPreviousPage">Previous</button>
        <button class="st-act st-act-ghost" type="button" id="studentNextPage">Next</button>
      </div>
    </div>
  </section>
</div>

<div class="modal fade st-modal" id="importStudentsModal" tabindex="-1" aria-labelledby="importStudentsTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title fs-5" id="importStudentsTitle">Import Student Masterlist</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="importChooseStep">
          <p class="text-secondary">Step A · Choose a UTF-8 or Excel CSV file (maximum 5 MB and 5,000 rows).</p>
          <label for="studentsCsvFile" class="st-import-dropzone w-100" id="studentsCsvDropzone">
            <strong>Drop your CSV file here or choose a file</strong>
            <span class="d-block small text-secondary mt-2" id="studentsCsvFilename">CSV only · up to 5 MB</span>
          </label>
          <input class="visually-hidden" id="studentsCsvFile" type="file" accept=".csv,text/csv">
          <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mt-3">
            <a href="#" id="studentsCsvTemplate">Download template</a>
            <label class="form-label mb-0" for="studentsDuplicateMode">If a student number already exists
              <select id="studentsDuplicateMode" class="form-select mt-1">
                <option value="skip">Skip</option>
                <option value="update">Update details</option>
              </select>
            </label>
          </div>
          <div class="small text-secondary mt-2">Skip keeps the existing record unchanged. Update details changes only the name, program, year, and section; it does not change contact details or blacklist history.</div>
          <div class="alert alert-danger d-none mt-3" id="studentsImportError" role="alert"></div>
        </div>
        <div id="importMapStep" class="d-none">
          <p class="text-secondary">Step B · Map the required columns. Unknown columns will be ignored.</p>
          <div class="row g-3" id="studentsImportMappings"></div>
        </div>
        <div id="importPreviewStep" class="d-none">
          <p class="text-secondary">Step B · Preview and validation (nothing is saved yet).</p>
          <div class="st-import-summary" id="studentsImportSummary"></div>
          <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
            <span id="studentsImportRowCount" class="small text-secondary"></span>
            <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" id="studentsRejectedOnly"> Show rejected only</label>
          </div>
          <div class="st-import-preview table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th>Row</th><th>Student number</th><th>Name</th><th>Program</th><th>Year</th><th>Section</th><th>Status / reason</th></tr></thead>
              <tbody id="studentsImportPreviewRows"></tbody>
            </table>
          </div>
        </div>
        <div id="importResultStep" class="d-none">
          <p class="text-secondary">Step C · Import result</p>
          <div id="studentsImportResult"></div>
          <button type="button" class="btn btn-outline-dark mt-3 d-none" id="downloadRejectedRows">Download rejected rows</button>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-outline-dark d-none" id="studentsImportBack">Back to file</button>
        <button type="button" class="btn btn-dark" id="studentsImportPreviewButton" disabled>Preview CSV</button>
        <button type="button" class="btn btn-dark d-none" id="studentsImportMapButton">Preview with mapping</button>
        <button type="button" class="btn btn-dark d-none" id="studentsImportConfirmButton" disabled>
          <span class="spinner-border spinner-border-sm me-2 d-none" aria-hidden="true"></span>
          <span class="button-label">Import students</span>
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade st-modal" id="registerStudentModal" tabindex="-1" aria-labelledby="registerStudentTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form id="registerStudentForm" novalidate>
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="registerStudentTitle">Register a Student</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-error d-none" id="registerStudentError" role="alert"></div>
          <input type="hidden" name="action" value="register">
          <div class="row g-3">
            <div class="col-md-6">
              <label for="registerStudentNumber" class="form-label">Student Number</label>
              <input class="form-control scan-input" id="registerStudentNumber" name="student_no" type="text" placeholder="0000-00000-BN-0" pattern="[0-9]{4}-[0-9]{5}-BN-[0-9]" maxlength="15" autocomplete="off" aria-describedby="registerStudentNumberError" required>
              <div class="invalid-feedback" id="registerStudentNumberError">Student number must match 0000-00000-BN-0.</div>
            </div>
            <div class="col-md-6">
              <label for="registerFullName" class="form-label">Full Name</label>
              <input class="form-control" id="registerFullName" name="full_name" type="text" maxlength="150" required>
              <div class="invalid-feedback">Enter the student’s full name.</div>
            </div>
            <div class="col-md-6">
              <label for="registerProgram" class="form-label">Program</label>
              <input class="form-control" id="registerProgram" name="program" type="text" maxlength="100" placeholder="e.g. BSIT" required>
              <div class="invalid-feedback">Enter the student’s program.</div>
            </div>
            <div class="col-md-3">
              <label for="registerYearLevel" class="form-label">Year Level</label>
              <select class="form-select" id="registerYearLevel" name="year_level" required>
                <option value="">Select year</option>
                <option value="1">1st</option><option value="2">2nd</option>
                <option value="3">3rd</option><option value="4">4th</option><option value="5">5th</option>
              </select>
              <div class="invalid-feedback">Select the year level.</div>
            </div>
            <div class="col-md-3">
              <label for="registerSection" class="form-label">Section</label>
              <input class="form-control" id="registerSection" name="section" type="text" maxlength="20" required>
              <div class="invalid-feedback">Enter the student’s section.</div>
            </div>
            <div class="col-md-6">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn" type="submit" id="registerStudentSubmit">Register Student</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade st-modal" id="blacklistStudentModal" tabindex="-1" aria-labelledby="blacklistStudentTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="blacklistStudentForm">
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="blacklistStudentTitle">Blacklist Student</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-error d-none" id="blacklistStudentError" role="alert"></div>
          <div class="student-modal-summary" id="blacklistStudentSummary"></div>
          <input type="hidden" name="action" value="blacklist">
          <input type="hidden" name="student_no" id="blacklistStudentNumber">
          <label for="offenseType" class="form-label">Offense Type</label>
          <select class="form-select" id="offenseType" name="offense_type" required>
            <option value="">Select offense</option>
            <option value="unpaid_fee">Unpaid fee</option>
            <option value="item_damage_or_loss">Item damage or loss</option>
            <option value="overdue_items">Overdue items</option>
            <option value="misconduct">Misconduct</option>
            <option value="other">Other</option>
          </select>
          <label for="blacklistedBy" class="form-label mt-3">Officer’s Name</label>
          <input class="form-control" id="blacklistedBy" name="officer_name" type="text" maxlength="100" value="<?php echo htmlspecialchars((string)($_SESSION['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-danger" type="submit" id="blacklistStudentSubmit">
            <span class="spinner-border spinner-border-sm me-2 d-none" aria-hidden="true"></span>
            <span class="button-label">Confirm Blacklist</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade st-modal" id="blacklistHistoryModal" tabindex="-1" aria-labelledby="blacklistHistoryTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title fs-5" id="blacklistHistoryTitle">Blacklist History</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pb-4">
        <div class="student-modal-summary" id="historyStudentSummary"></div>
        <div id="blacklistHistoryEntries" aria-live="polite"></div>
        <form id="liftBlacklistForm" class="border-top pt-3 mt-3 d-none">
          <div class="alert alert-error d-none" id="liftBlacklistError" role="alert"></div>
          <input type="hidden" name="action" value="lift">
          <input type="hidden" name="blacklist_id" id="liftBlacklistId">
          <label for="liftedBy" class="form-label">Lifted By</label>
          <input class="form-control" id="liftedBy" name="officer_name" type="text" maxlength="100" value="<?php echo htmlspecialchars((string)($_SESSION['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
          <label for="liftNote" class="form-label mt-3">Reason for Lifting</label>
          <textarea class="form-control" id="liftNote" name="lift_note" maxlength="255" rows="3" required></textarea>
          <button class="btn btn-danger mt-3" type="submit" id="liftBlacklistSubmit">
            <span class="spinner-border spinner-border-sm me-2 d-none" aria-hidden="true"></span>
            <span class="button-label">Lift Blacklist</span>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="<?php echo $base; ?>assets/js/student-scan.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
(function () {
  'use strict';
  var csrfToken = <?php echo json_encode($csrf_token); ?>;
  var students = [];
  var page = 1;
  var pageSize = 10;
  var sortKey = 'full_name';
  var sortDirection = 1;
  var registerModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('registerStudentModal'));
  var blacklistModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('blacklistStudentModal'));
  var historyModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('blacklistHistoryModal'));
  var importModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('importStudentsModal'));
  var importFile = null;
  var importPreview = null;
  var importMapping = null;
  var importHasNameParts = false;
  var importAvailable = <?php echo $masterlist_imported ? 'false' : 'true'; ?>;

  function escapeText(value) {
    return value === null || value === undefined ? '' : String(value);
  }

  function apiRequest(url, options) {
    options = options || {};
    options.headers = Object.assign({}, options.headers || {}, { 'X-CSRF-Token': csrfToken });
    return fetch(url, options).then(function (response) {
        if ((response.headers.get('content-type') || '').indexOf('application/json') === -1) {
          throw new Error('The request returned an unexpected response. Refresh the page and try again.');
        }
        return response.json().then(function (payload) {
        if (!response.ok) {
          var error = new Error(payload.error || 'The request could not be completed.');
          error.payload = payload;
          throw error;
        }
        return payload;
      });
    });
  }

  function formOptions(form) {
    return {
      method: 'POST',
      body: new URLSearchParams(new FormData(form))
    };
  }

  function showFormError(element, message) {
    element.textContent = message;
    element.classList.remove('d-none');
  }

  function clearFormError(element) {
    element.textContent = '';
    element.classList.add('d-none');
  }

  function setButtonBusy(button, busy, label) {
    button.disabled = busy;
    button.querySelector('.spinner-border').classList.toggle('d-none', !busy);
    button.querySelector('.button-label').textContent = busy ? label : button.dataset.defaultLabel;
  }

  function showImportStep(step) {
    ['importChooseStep', 'importMapStep', 'importPreviewStep', 'importResultStep'].forEach(function (id) {
      document.getElementById(id).classList.toggle('d-none', id !== step);
    });
    document.getElementById('studentsImportPreviewButton').classList.toggle('d-none', step !== 'importChooseStep');
    document.getElementById('studentsImportMapButton').classList.toggle('d-none', step !== 'importMapStep');
    document.getElementById('studentsImportConfirmButton').classList.toggle('d-none', step !== 'importPreviewStep');
    document.getElementById('studentsImportBack').classList.toggle('d-none', step === 'importChooseStep' || step === 'importResultStep');
  }

  function setImportError(message) {
    var error = document.getElementById('studentsImportError');
    error.textContent = message || '';
    error.classList.toggle('d-none', !message);
  }

  function importRequest(operation, mapping) {
    if (!importFile) return Promise.reject(new Error('Choose a CSV file to continue.'));
    var data = new FormData();
    data.append('csv_file', importFile, importFile.name);
    data.append('operation', operation);
    data.append('duplicate_mode', document.getElementById('studentsDuplicateMode').value);
    if (mapping) data.append('mapping', JSON.stringify(mapping));
    return fetch('import.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' },
      body: data
    }).then(function (response) {
      return response.json().then(function (payload) {
        if (!response.ok) {
          var error = new Error(payload.error || 'The import request could not be completed.');
          error.payload = payload;
          throw error;
        }
        return payload;
      });
    });
  }

  function mappingSelect(field, title, required, headers, value) {
    var wrapper = document.createElement('div');
    wrapper.className = 'col-sm-6';
    var label = document.createElement('label');
    label.className = 'form-label';
    label.textContent = title + (required ? ' *' : '');
    var select = document.createElement('select');
    select.className = 'form-select';
    select.dataset.field = field;
    var empty = document.createElement('option');
    empty.value = '';
    empty.textContent = 'Do not map';
    select.appendChild(empty);
    headers.forEach(function (header, index) {
      var option = document.createElement('option');
      option.value = String(index);
      option.textContent = header || ('Column ' + (index + 1));
      select.appendChild(option);
    });
    if (field === 'full_name' && importHasNameParts) {
      var combined = document.createElement('option');
      combined.value = '__combined_name';
      combined.textContent = 'Combine first, middle, and last name columns';
      select.appendChild(combined);
    }
    if (value !== undefined) select.value = String(value);
    label.appendChild(select);
    wrapper.appendChild(label);
    return wrapper;
  }

  function showImportMapping(payload) {
    importHasNameParts = payload.has_name_parts === true;
    importMapping = payload.mapping || {};
    var container = document.getElementById('studentsImportMappings');
    container.replaceChildren();
    [
      ['student_no', 'Student number', true],
      ['full_name', 'Name', true],
      ['program', 'Program', false],
      ['year_level', 'Year', false],
      ['section', 'Section', false]
    ].forEach(function (field) {
      container.appendChild(mappingSelect(field[0], field[1], field[2], payload.headers, importMapping[field[0]]));
    });
    showImportStep('importMapStep');
  }

  function currentImportMapping() {
    var mapping = {};
    document.querySelectorAll('#studentsImportMappings select').forEach(function (select) {
      if (select.value !== '') mapping[select.dataset.field] = select.value;
    });
    return mapping;
  }

  function renderImportPreview(payload) {
    importPreview = payload;
    var summary = document.getElementById('studentsImportSummary');
    summary.replaceChildren();
    [
      ['Valid (new)', payload.counts.valid_new],
      ['Will update', payload.counts.will_update],
      ['Skipped', payload.counts.skipped],
      ['Rejected', payload.counts.rejected]
    ].forEach(function (entry) {
      var item = document.createElement('span');
      item.textContent = entry[0] + ': ' + entry[1];
      summary.appendChild(item);
    });
    document.getElementById('studentsImportRowCount').textContent =
      payload.total_rows + ' non-empty data row(s); showing the first ' + payload.preview.length + '.';
    renderImportPreviewRows();
    var importCount = payload.counts.valid_new + payload.counts.will_update;
    var button = document.getElementById('studentsImportConfirmButton');
    button.disabled = !payload.importable;
    button.dataset.defaultLabel = 'Import ' + importCount + ' students';
    button.querySelector('.button-label').textContent = button.dataset.defaultLabel;
    showImportStep('importPreviewStep');
  }

  function renderImportPreviewRows() {
    if (!importPreview) return;
    var onlyRejected = document.getElementById('studentsRejectedOnly').checked;
    var rows = onlyRejected
      ? importPreview.preview.filter(function (row) { return row.status === 'Rejected'; })
      : importPreview.preview;
    var body = document.getElementById('studentsImportPreviewRows');
    body.replaceChildren();
    if (!rows.length) {
      var empty = document.createElement('tr');
      var cell = document.createElement('td');
      cell.colSpan = 7;
      cell.className = 'text-center text-secondary py-4';
      cell.textContent = onlyRejected ? 'No rejected rows in the first 50 rows.' : 'No data rows to preview.';
      empty.appendChild(cell);
      body.appendChild(empty);
      return;
    }
    rows.forEach(function (row) {
      var tr = document.createElement('tr');
      [
        row.file_row, row.student_no, row.full_name, row.program,
        row.year_level, row.section
      ].forEach(function (value) {
        var td = document.createElement('td');
        td.textContent = escapeText(value) || '—';
        tr.appendChild(td);
      });
      var statusCell = document.createElement('td');
      var badge = document.createElement('span');
      badge.className = 'badge ' + (row.status === 'Rejected' ? 'text-bg-danger' :
        (row.status === 'Valid (new)' ? 'text-bg-success' : 'text-bg-secondary'));
      badge.textContent = row.status;
      statusCell.appendChild(badge);
      if (row.reason) {
        var reason = document.createElement('span');
        reason.className = 'st-import-reason';
        reason.textContent = row.reason;
        statusCell.appendChild(reason);
      }
      tr.appendChild(statusCell);
      body.appendChild(tr);
    });
  }

  function safeCsvCell(value) {
    var text = String(value === null || value === undefined ? '' : value);
    if (/^[=+\-@]/.test(text)) text = "'" + text;
    return '"' + text.replace(/"/g, '""') + '"';
  }

  function downloadRejectedCsv(rows) {
    var lines = [['row_number', 'student_number', 'reason']].concat(rows.map(function (row) {
      return [row.file_row, row.student_no, row.reason];
    })).map(function (line) {
      return line.map(safeCsvCell).join(',');
    });
    var blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = 'rejected-students.csv';
    link.click();
    URL.revokeObjectURL(url);
  }

  function chooseImportFile(file) {
    importFile = file || null;
    importPreview = null;
    setImportError('');
    document.getElementById('studentsCsvFilename').textContent = file ? file.name : 'CSV only · up to 5 MB';
    document.getElementById('studentsImportPreviewButton').disabled = !file;
    if (file && (file.size > 5 * 1024 * 1024 || !/\.csv$/i.test(file.name))) {
      setImportError(file.size > 5 * 1024 * 1024
        ? 'The CSV file must be 5 MB or smaller.'
        : 'Choose a .csv file. Other file types are not accepted.');
      document.getElementById('studentsImportPreviewButton').disabled = true;
    }
  }

  document.getElementById('studentsCsvFile').addEventListener('change', function () {
    chooseImportFile(this.files && this.files[0]);
  });
  var csvDropzone = document.getElementById('studentsCsvDropzone');
  ['dragenter', 'dragover'].forEach(function (eventName) {
    csvDropzone.addEventListener(eventName, function (event) {
      event.preventDefault();
      csvDropzone.classList.add('is-dragging');
    });
  });
  ['dragleave', 'drop'].forEach(function (eventName) {
    csvDropzone.addEventListener(eventName, function (event) {
      event.preventDefault();
      csvDropzone.classList.remove('is-dragging');
    });
  });
  csvDropzone.addEventListener('drop', function (event) {
    var files = event.dataTransfer && event.dataTransfer.files;
    if (files && files.length) {
      var transfer = new DataTransfer();
      transfer.items.add(files[0]);
      document.getElementById('studentsCsvFile').files = transfer.files;
      chooseImportFile(files[0]);
    }
  });
  document.getElementById('studentsCsvTemplate').addEventListener('click', function (event) {
    event.preventDefault();
    var csv = '\uFEFFstudent_number,full_name,program,year_level,section\r\n' +
      '0000-00000-BN-0,"Ñ Sample Student",BSIT,1,A\r\n';
    var url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    var link = document.createElement('a');
    link.href = url;
    link.download = 'student-masterlist-template.csv';
    link.click();
    URL.revokeObjectURL(url);
  });
  document.getElementById('studentsDuplicateMode').addEventListener('change', function () {
    if (importPreview) {
      importPreview = null;
      setImportError('Duplicate handling changed. Preview the CSV again to refresh the counts.');
      showImportStep('importChooseStep');
    }
  });
  document.getElementById('studentsImportPreviewButton').addEventListener('click', function () {
    setImportError('');
    var button = this;
    button.disabled = true;
    button.textContent = 'Reading CSV…';
    importRequest('preview').then(function (payload) {
      if (payload.mapping_required) {
        showImportMapping(payload);
      } else {
        importMapping = payload.mapping;
        renderImportPreview(payload);
      }
    }).catch(function (error) {
      setImportError(error.message);
      if (window.notify) window.notify.error('CSV preview failed', error.message);
    }).finally(function () {
      button.textContent = 'Preview CSV';
      button.disabled = !importFile || !/\.csv$/i.test(importFile.name) || importFile.size > 5 * 1024 * 1024;
    });
  });
  document.getElementById('studentsImportMapButton').addEventListener('click', function () {
    setImportError('');
    var mapping = currentImportMapping();
    if (mapping.student_no === undefined || mapping.full_name === undefined) {
      setImportError('Map both Student number and Name before continuing.');
      return;
    }
    var button = this;
    button.disabled = true;
    button.textContent = 'Validating…';
    importRequest('preview', mapping).then(function (payload) {
      importMapping = mapping;
      renderImportPreview(payload);
    }).catch(function (error) {
      setImportError(error.message);
      if (window.notify) window.notify.error('CSV preview failed', error.message);
    }).finally(function () {
      button.disabled = false;
      button.textContent = 'Preview with mapping';
    });
  });
  document.getElementById('studentsImportBack').addEventListener('click', function () {
    showImportStep('importChooseStep');
  });
  document.getElementById('studentsRejectedOnly').addEventListener('change', renderImportPreviewRows);
  document.getElementById('downloadRejectedRows').addEventListener('click', function () {
    if (this._rejectedRows) downloadRejectedCsv(this._rejectedRows);
  });
  document.getElementById('studentsImportConfirmButton').addEventListener('click', function () {
    if (!importPreview || !importPreview.importable || this.disabled) return;
    setImportError('');
    var button = this;
    button.disabled = true;
    button.querySelector('.spinner-border').classList.remove('d-none');
    button.querySelector('.button-label').textContent = 'Importing…';
    importRequest('import', importMapping).then(function (payload) {
      var counts = payload.counts;
      var resultText = 'Import complete: ' + counts.added + ' added, ' + counts.updated +
        ' updated, ' + counts.skipped + ' skipped, ' + counts.rejected + ' rejected.';
      document.getElementById('studentsImportResult').textContent = resultText;
      importAvailable = false;
      document.getElementById('importStudentsButton').classList.add('d-none');
      var rejectedButton = document.getElementById('downloadRejectedRows');
      rejectedButton._rejectedRows = payload.rejected_rows || [];
      rejectedButton.classList.toggle('d-none', !rejectedButton._rejectedRows.length);
      showImportStep('importResultStep');
      if (window.notify) {
        window.notify.success('Import complete', counts.added + ' added · ' + counts.updated +
          ' updated · ' + counts.skipped + ' skipped · ' + counts.rejected + ' rejected');
      }
      return refreshStudents().catch(showTableError);
    }).catch(function (error) {
      setImportError(error.message);
      if (window.notify) window.notify.error('Import failed', error.message);
      if (error.payload && error.payload.imported) {
        importAvailable = false;
        document.getElementById('importStudentsButton').classList.add('d-none');
      }
      button.disabled = false;
    }).finally(function () {
      button.querySelector('.spinner-border').classList.add('d-none');
      button.querySelector('.button-label').textContent = button.dataset.defaultLabel || 'Import students';
    });
  });
  document.getElementById('importStudentsModal').addEventListener('hidden.bs.modal', function () {
    importFile = null;
    importPreview = null;
    importMapping = null;
    document.getElementById('studentsCsvFile').value = '';
    document.getElementById('studentsCsvFilename').textContent = 'CSV only · up to 5 MB';
    document.getElementById('studentsImportPreviewButton').disabled = true;
    document.getElementById('studentsRejectedOnly').checked = false;
    document.getElementById('downloadRejectedRows')._rejectedRows = [];
    setImportError('');
    showImportStep('importChooseStep');
  });

  function studentSortValue(student, key) {
    if (key === 'year_section') return student.year_level + ' ' + student.section;
    return escapeText(student[key]).toLocaleLowerCase();
  }

  function filteredStudents() {
    var term = document.getElementById('studentSearch').value.trim().toLocaleLowerCase();
    return students.filter(function (student) {
      return !term || [
        student.student_no, student.full_name, student.program, student.year_level,
        student.section
      ].join(' ').toLocaleLowerCase().indexOf(term) !== -1;
    }).sort(function (first, second) {
      return studentSortValue(first, sortKey).localeCompare(studentSortValue(second, sortKey), undefined, { numeric: true }) * sortDirection;
    });
  }

  function makeCell(row, value, className) {
    var cell = document.createElement('td');
    if (className) cell.className = className;
    cell.textContent = escapeText(value) || '—';
    row.appendChild(cell);
    return cell;
  }

  function makeButton(label, classes, callback) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = classes;
    button.textContent = label;
    button.addEventListener('click', callback);
    return button;
  }

  function updateSortIndicators() {
    document.querySelectorAll('th[data-col]').forEach(function (th) {
      if (th.dataset.col === sortKey) {
        th.setAttribute('aria-sort', sortDirection === 1 ? 'ascending' : 'descending');
      } else {
        th.removeAttribute('aria-sort');
      }
    });
  }

  function updateStats() {
    var blocked = students.filter(function (student) { return student.blacklist_id; }).length;
    document.getElementById('statTotal').textContent = students.length;
    document.getElementById('statBlacklisted').textContent = blocked;
    document.getElementById('statActive').textContent = students.length - blocked;
  }

  function renderStudents() {
    var body = document.getElementById('studentTableBody');
    var results = filteredStudents();
    var pageCount = Math.max(1, Math.ceil(results.length / pageSize));
    page = Math.min(page, pageCount);
    body.replaceChildren();
    updateSortIndicators();
    updateStats();
    if (!results.length) {
      var empty = document.createElement('tr');
      var emptyCell = document.createElement('td');
      emptyCell.colSpan = 5;
      emptyCell.className = 'st-empty';
      emptyCell.textContent = students.length ? 'No students match your search.' : 'No students registered yet.';
      empty.appendChild(emptyCell);
      body.appendChild(empty);
    } else {
      results.slice((page - 1) * pageSize, page * pageSize).forEach(function (student) {
        var row = document.createElement('tr');
        var blocked = !!student.blacklist_id;
        if (blocked) row.classList.add('student-row-blacklisted');
        makeCell(row, student.student_no, 'st-num');

        var nameCell = document.createElement('td');
        var person = document.createElement('div');
        person.className = 'st-person';
        var text = document.createElement('div');
        var name = document.createElement('div');
        name.className = 'st-name';
        name.textContent = escapeText(student.full_name) || '—';
        text.appendChild(name);
        if (blocked) {
          var blockedTag = document.createElement('span');
          blockedTag.className = 'st-blacklisted-tag';
          blockedTag.textContent = 'Blacklisted';
          text.appendChild(blockedTag);
        }
        if (student.contact_number) {
          var contact = document.createElement('div');
          contact.className = 'st-sub';
          contact.textContent = student.contact_number;
          text.appendChild(contact);
        }
        person.appendChild(text);
        nameCell.appendChild(person);
        row.appendChild(nameCell);

        makeCell(row, student.program);
        makeCell(row, 'Year ' + student.year_level + (student.section ? ' · ' + student.section : ''));

        var actions = document.createElement('td');
        var actionWrap = document.createElement('div');
        actionWrap.className = 'student-row-actions';
        if (blocked) {
          actionWrap.appendChild(makeButton('View / Lift', 'st-act st-act-ghost', function () {
            openHistory(student);
          }));
        } else {
          if (Number(student.history_count) > 0) {
            actionWrap.appendChild(makeButton('History', 'st-act st-act-ghost', function () {
              openHistory(student);
            }));
          }
          actionWrap.appendChild(makeButton('Blacklist', 'st-act st-act-danger', function () {
            openBlacklist(student);
          }));
        }
        actions.appendChild(actionWrap);
        row.appendChild(actions);
        body.appendChild(row);
      });
    }
    var first = results.length ? (page - 1) * pageSize + 1 : 0;
    var last = Math.min(page * pageSize, results.length);
    document.getElementById('studentResultCount').textContent =
      results.length ? 'Showing ' + first + '–' + last + ' of ' + results.length + ' students' : '0 students';
    document.getElementById('studentPreviousPage').disabled = page <= 1;
    document.getElementById('studentNextPage').disabled = page >= pageCount;
  }

  function refreshStudents() {
    return apiRequest('list.php?action=list').then(function (payload) {
      students = payload.students;
      importAvailable = payload.masterlist_imported !== true;
      document.getElementById('importStudentsButton').classList.toggle('d-none', !importAvailable);
      renderStudents();
    });
  }

  function showTableError(error) {
    var body = document.getElementById('studentTableBody');
    body.replaceChildren();
    var row = document.createElement('tr');
    var cell = document.createElement('td');
    cell.colSpan = 5;
    cell.className = 'st-empty text-danger';
    cell.textContent = 'Unable to load students: ' + error.message;
    row.appendChild(cell);
    body.appendChild(row);
  }

  function summaryText(student) {
    return student.student_no + ' · ' + student.full_name + ' · ' + student.program +
      ' · Year ' + student.year_level + ', Section ' + (student.section || '—');
  }

  function openBlacklist(student) {
    document.getElementById('blacklistStudentSummary').textContent = summaryText(student);
    document.getElementById('blacklistStudentNumber').value = student.student_no;
    document.getElementById('blacklistStudentForm').reset();
    document.getElementById('blacklistStudentNumber').value = student.student_no;
    document.getElementById('blacklistedBy').value = <?php echo json_encode((string)($_SESSION['full_name'] ?? '')); ?>;
    clearFormError(document.getElementById('blacklistStudentError'));
    blacklistModal.show();
  }

  function appendHistoryRecord(container, record) {
    var isActive = Number(record.is_active) === 1;
    var card = document.createElement('article');
    card.className = 'blacklist-history-entry' + (isActive ? ' is-active' : '');
    var status = document.createElement('span');
    status.className = 'st-badge ' + (isActive ? 'st-badge-bad' : 'st-badge-off');
    status.textContent = isActive ? 'Active blacklist' : 'Lifted';
    card.appendChild(status);

    var offense = record.offense_type.replace(/_/g, ' ');
    var reason = String(record.reason || '').trim();
    if (reason && reason.toLocaleLowerCase() !== offense.toLocaleLowerCase()) {
      var reasonText = document.createElement('p');
      reasonText.className = 'mb-2 mt-2';
      reasonText.textContent = reason;
      card.appendChild(reasonText);
    }
    var info = document.createElement('p');
    info.className = 'small text-secondary mb-1';
    info.textContent = 'Offense: ' + offense +
      ' · Blacklisted ' + record.blacklisted_at + ' by ' + (record.blacklisted_by || 'Not recorded');
    card.appendChild(info);
    if (!isActive) {
      var lifted = document.createElement('p');
      lifted.className = 'small text-secondary mb-0';
      lifted.textContent = 'Lifted ' + (record.lifted_at || 'date not recorded') +
        ' by ' + (record.lifted_by || 'Not recorded') +
        (record.lift_note ? ' · ' + record.lift_note : '');
      card.appendChild(lifted);
    }
    container.appendChild(card);
  }

  function openHistory(student) {
    document.getElementById('historyStudentSummary').textContent = summaryText(student);
    document.getElementById('blacklistHistoryEntries').textContent = 'Loading blacklist history…';
    document.getElementById('liftBlacklistForm').classList.add('d-none');
    document.getElementById('liftBlacklistForm').reset();
    document.getElementById('liftedBy').value = <?php echo json_encode((string)($_SESSION['full_name'] ?? '')); ?>;
    clearFormError(document.getElementById('liftBlacklistError'));
    historyModal.show();
    apiRequest('list.php?action=history&student_no=' + encodeURIComponent(student.student_no))
      .then(function (payload) {
        var entries = document.getElementById('blacklistHistoryEntries');
        entries.replaceChildren();
        payload.history.forEach(function (record) { appendHistoryRecord(entries, record); });
        var active = payload.history.find(function (record) { return Number(record.is_active) === 1; });
        if (active) {
          document.getElementById('liftBlacklistId').value = active.blacklist_id;
          document.getElementById('liftBlacklistForm').classList.remove('d-none');
        } else if (!payload.history.length) {
          entries.textContent = 'No blacklist history found.';
        }
      })
      .catch(function (error) {
        document.getElementById('blacklistHistoryEntries').textContent = error.message;
      });
  }

  document.getElementById('studentSearch').addEventListener('input', function () {
    page = 1;
    renderStudents();
  });
  document.getElementById('studentPageSize').addEventListener('change', function (event) {
    pageSize = Number(event.target.value);
    page = 1;
    renderStudents();
  });
  document.getElementById('studentPreviousPage').addEventListener('click', function () {
    page -= 1;
    renderStudents();
  });
  document.getElementById('studentNextPage').addEventListener('click', function () {
    page += 1;
    renderStudents();
  });
  document.querySelectorAll('.student-sort').forEach(function (button) {
    button.addEventListener('click', function () {
      var nextKey = button.dataset.sort;
      sortDirection = nextKey === sortKey ? -sortDirection : 1;
      sortKey = nextKey;
      renderStudents();
    });
  });

  document.getElementById('registerStudentModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('registerStudentForm').reset();
    studentNumberInput.classList.remove('is-valid', 'is-invalid');
    studentNumberInput.setCustomValidity('');
    studentNumberError.textContent = 'Student number must match 0000-00000-BN-0.';
    clearFormError(document.getElementById('registerStudentError'));
  });
  document.getElementById('blacklistStudentModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('blacklistStudentForm').reset();
    clearFormError(document.getElementById('blacklistStudentError'));
  });

  var studentNumberInput = document.getElementById('registerStudentNumber');
  var studentNumberError = document.getElementById('registerStudentNumberError');
  studentNumberInput.addEventListener('input', function () {
    var normalized = window.normalizeStudentNumber(studentNumberInput.value);
    if (/^\d{4}-\d{5}-BN-\d$/.test(normalized) || /^\d{9}BN\d$/.test(normalized)) {
      studentNumberInput.value = normalized;
    }
    var valid = /^\d{4}-\d{5}-BN-\d$/.test(window.normalizeStudentNumber(studentNumberInput.value));
    studentNumberInput.classList.toggle('is-invalid', !valid && studentNumberInput.value.length > 0);
    studentNumberInput.classList.toggle('is-valid', valid);
    studentNumberError.textContent = 'Student number must match 0000-00000-BN-0.';
    studentNumberInput.setCustomValidity(valid || !studentNumberInput.value ? '' : studentNumberError.textContent);
  });

  document.getElementById('registerStudentForm').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.currentTarget;
    var submit = document.getElementById('registerStudentSubmit');
    var errorBox = document.getElementById('registerStudentError');
    clearFormError(errorBox);
    if (!form.reportValidity()) {
      window.notify.error('Check the student details', 'Complete the required fields with valid values.');
      return;
    }
    submit.disabled = true;
    var normalized = window.normalizeStudentNumber(studentNumberInput.value);
    studentNumberInput.value = normalized;
    apiRequest('list.php', formOptions(form)).then(function (payload) {
      registerModal.hide();
      var registeredStudent = {
        student_no: normalized,
        full_name: form.elements.full_name.value,
        program: form.elements.program.value,
        year_level: form.elements.year_level.value,
        section: form.elements.section.value
      };
      window.notify.success('Student registered', summaryText(registeredStudent));
      form.reset();
      studentNumberInput.classList.remove('is-valid', 'is-invalid');
      return refreshStudents().catch(showTableError);
    }).catch(function (error) {
      if (error.payload && error.payload.field === 'student_no') {
        studentNumberInput.classList.add('is-invalid');
        studentNumberError.textContent = error.message;
        studentNumberInput.setCustomValidity(error.message);
      } else {
        showFormError(errorBox, error.message);
      }
      window.notify.error('Student could not be registered', error.message || 'Unable to register the student.');
    }).finally(function () {
      submit.disabled = false;
    });
  });

  document.getElementById('blacklistStudentForm').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.currentTarget;
    if (form.dataset.confirmed === 'true') {
      delete form.dataset.confirmed;
    } else {
      if (form.dataset.confirming === 'true') return;
      if (!form.reportValidity()) {
        window.notify.error('Check the blacklist details', 'Complete the required fields before continuing.');
        return;
      }
      form.dataset.confirming = 'true';
      window.notify.confirm({
        title: 'Blacklist this student?',
        text: 'This will block the student from CSC services.',
        confirmText: 'Blacklist student',
        cancelText: 'Cancel',
        danger: true
      }).then(function (confirmed) {
        form.dataset.confirming = 'false';
        if (confirmed) {
          form.dataset.confirmed = 'true';
          form.requestSubmit();
        }
      });
      return;
    }
    var submit = document.getElementById('blacklistStudentSubmit');
    setButtonBusy(submit, true, 'Saving…');
    apiRequest('list.php', formOptions(form)).then(function (payload) {
      blacklistModal.hide();
      var student = students.find(function (candidate) {
        return candidate.student_no === form.elements.student_no.value;
      });
      window.notify.success('Student blacklisted', student ? student.full_name : form.elements.student_no.value);
      return refreshStudents().catch(showTableError);
    }).catch(function (error) {
      showFormError(document.getElementById('blacklistStudentError'), error.message);
      window.notify.error('Student could not be blacklisted', error.message || 'Unable to blacklist the student.');
    }).finally(function () {
      setButtonBusy(submit, false);
    });
  });

  document.getElementById('liftBlacklistForm').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.currentTarget;
    if (form.dataset.confirmed === 'true') {
      delete form.dataset.confirmed;
    } else {
      if (form.dataset.confirming === 'true') return;
      if (!form.reportValidity()) {
        window.notify.error('Check the blacklist details', 'Provide who lifted the blacklist and a reason.');
        return;
      }
      form.dataset.confirming = 'true';
      window.notify.confirm({
        title: 'Lift this blacklist?',
        text: 'The student will regain access to CSC services.',
        confirmText: 'Lift blacklist',
        cancelText: 'Cancel',
        danger: true
      }).then(function (confirmed) {
        form.dataset.confirming = 'false';
        if (confirmed) {
          form.dataset.confirmed = 'true';
          form.requestSubmit();
        }
      });
      return;
    }
    var submit = document.getElementById('liftBlacklistSubmit');
    setButtonBusy(submit, true, 'Saving…');
    apiRequest('list.php', formOptions(form)).then(function (payload) {
      historyModal.hide();
      var studentName = document.getElementById('historyStudentSummary').textContent.trim();
      window.notify.success('Blacklist lifted', studentName);
      return refreshStudents().catch(showTableError);
    }).catch(function (error) {
      showFormError(document.getElementById('liftBlacklistError'), error.message);
      window.notify.error('Blacklist could not be lifted', error.message || 'Unable to lift the blacklist.');
    }).finally(function () {
      setButtonBusy(submit, false);
    });
  });

  ['blacklistStudentSubmit', 'liftBlacklistSubmit'].forEach(function (id) {
    var button = document.getElementById(id);
    button.dataset.defaultLabel = button.querySelector('.button-label').textContent;
  });

  updateSortIndicators();
  refreshStudents().catch(showTableError);
}());
});
</script>
<?php include '../../includes/footer.php'; ?>