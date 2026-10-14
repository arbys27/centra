<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: application/json; charset=utf-8');

function student_import_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function student_import_pin_unlocked(): bool
{
    $key = 'pin_unlocked_students';
    $last_activity = (int)($_SESSION[$key] ?? 0);
    if ($last_activity <= 0 || $last_activity + 600 < time()) {
        unset($_SESSION[$key]);
        return false;
    }
    $_SESSION[$key] = time();
    return true;
}

function student_import_decode(string $contents): ?string
{
    if (strncmp($contents, "\xEF\xBB\xBF", 3) === 0) {
        $contents = substr($contents, 3);
    }
    if (strpos($contents, "\0") !== false) {
        return null;
    }
    if (function_exists('mb_check_encoding') && mb_check_encoding($contents, 'UTF-8')) {
        return $contents;
    }
    $converted = @iconv('Windows-1252', 'UTF-8', $contents);
    return $converted === false ? null : $converted;
}

function student_import_csv_rows(string $contents): array
{
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('Unable to read the uploaded CSV.');
    }
    fwrite($stream, $contents);
    rewind($stream);
    $first_line = fgets($stream);
    if ($first_line === false) {
        fclose($stream);
        return [',', []];
    }
    $comma_fields = count(str_getcsv($first_line, ','));
    $semicolon_fields = count(str_getcsv($first_line, ';'));
    $delimiter = $semicolon_fields > $comma_fields ? ';' : ',';
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
        $rows[] = $row;
    }
    fclose($stream);
    return [$delimiter, $rows];
}

function student_import_header_key(string $header): string
{
    $header = strtolower(trim($header));
    $header = str_replace('#', ' number ', $header);
    $header = preg_replace('/[.#]+/', ' ', $header);
    $header = preg_replace('/[\s_-]+/', ' ', $header);
    return trim($header);
}

function student_import_map_headers(array $headers): array
{
    $aliases = [
        'student_no' => ['student number', 'student no', 'id number', 'student id'],
        'full_name' => ['full name', 'name', 'student name'],
        'program' => ['program', 'course'],
        'year_level' => ['year level', 'year'],
        'section' => ['section', 'block'],
        'last_name' => ['last name', 'lastname', 'surname'],
        'first_name' => ['first name', 'firstname', 'given name'],
        'middle_name' => ['middle name', 'middlename', 'middle initial'],
    ];
    $keys = array_map('student_import_header_key', $headers);
    $mapping = [];
    foreach ($aliases as $field => $field_aliases) {
        foreach ($keys as $index => $key) {
            if (in_array($key, $field_aliases, true)) {
                $mapping[$field] = $index;
                break;
            }
        }
    }
    if (!isset($mapping['full_name']) && isset($mapping['first_name'], $mapping['last_name'])) {
        $mapping['full_name'] = '__combined_name';
    }
    return $mapping;
}

function student_import_normalize_number(string $number): string
{
    $number = strtoupper(trim($number));
    if (preg_match('/^\d{9}BN\d$/', $number) === 1) {
        $number = substr($number, 0, 4) . '-' . substr($number, 4, 5) . '-BN-' . substr($number, -1);
    }
    return $number;
}

function student_import_name_from_row(array $row, array $mapping): string
{
    if (($mapping['full_name'] ?? null) === '__combined_name') {
        $first = trim((string)($row[$mapping['first_name']] ?? ''));
        $middle = trim((string)($row[$mapping['middle_name']] ?? ''));
        $last = trim((string)($row[$mapping['last_name']] ?? ''));
        if ($middle !== '' && preg_match('/^[\p{L}]\.?$/u', $middle) !== 1) {
            $middle = mb_substr($middle, 0, 1) . '.';
        }
        return trim(implode(' ', array_filter([$first, $middle, $last], static function ($part) {
            return $part !== '';
        })));
    }
    $name = trim((string)($row[$mapping['full_name']] ?? ''));
    if (preg_match('/^([^,]+),\s*(.+)$/u', $name, $parts) === 1) {
        $last = trim($parts[1]);
        $given_parts = preg_split('/\s+/u', trim($parts[2]), -1, PREG_SPLIT_NO_EMPTY);
        if ($given_parts) {
            $first = array_shift($given_parts);
            return trim(implode(' ', array_filter([$first, implode(' ', $given_parts), $last], static function ($part) {
                return $part !== '';
            })));
        }
    }
    return $name;
}

function student_import_read_file(): array
{
    if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 5 * 1024 * 1024 + 65536) {
            student_import_response(['error' => 'The CSV file must be 5 MB or smaller.'], 413);
        }
        student_import_response(['error' => 'Choose a CSV file to continue.'], 422);
    }
    $file = $_FILES['csv_file'];
    if (in_array((int)($file['error'] ?? UPLOAD_ERR_OK), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        student_import_response(['error' => 'The CSV file must be 5 MB or smaller.'], 413);
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        student_import_response(['error' => 'The CSV upload did not complete. Choose the file again.'], 422);
    }
    $size = (int)($file['size'] ?? 0);
    if ($size < 1) {
        student_import_response(['error' => 'The selected file is empty.'], 422);
    }
    if ($size > 5 * 1024 * 1024) {
        student_import_response(['error' => 'The CSV file must be 5 MB or smaller.'], 413);
    }
    if (strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv') {
        student_import_response(['error' => 'Choose a .csv file. Other file types are not accepted.'], 415);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string)$file['tmp_name']);
    $allowed_mimes = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'];
    if (!is_string($mime) || !in_array($mime, $allowed_mimes, true)) {
        student_import_response(['error' => 'The selected file is not a text CSV file.'], 415);
    }
    $contents = file_get_contents((string)$file['tmp_name']);
    if ($contents === false) {
        student_import_response(['error' => 'Unable to read the uploaded CSV.'], 422);
    }
    if (strpos($contents, "\0") !== false) {
        student_import_response(['error' => 'The selected file contains non-text data, not a CSV.'], 415);
    }
    $decoded = student_import_decode($contents);
    if ($decoded === null || trim($decoded) === '') {
        student_import_response(['error' => 'The CSV text uses an unsupported encoding. Save it as UTF-8 or Windows-1252.'], 422);
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $decoded) === 1) {
        student_import_response(['error' => 'The selected file contains non-text data, not a CSV.'], 415);
    }
    return [$decoded, basename((string)$file['name'])];
}

function student_import_prepare(PDO $pdo, string $contents, ?string $mapping_json, string $mode): array
{
    if (!in_array($mode, ['skip', 'update'], true)) {
        student_import_response(['error' => 'Choose Skip or Update details for existing students.'], 422);
    }
    [, $records] = student_import_csv_rows($contents);
    if (!$records) {
        student_import_response(['error' => 'The selected file does not contain a header row.'], 422);
    }
    $headers = array_map(static function ($header) {
        return trim((string)$header);
    }, array_shift($records));
    if (count($headers) < 2) {
        student_import_response(['error' => 'The selected file does not appear to contain CSV columns.'], 415);
    }
    $auto_mapping = student_import_map_headers($headers);
    $mapping = $auto_mapping;
    if ($mapping_json !== null && $mapping_json !== '') {
        $requested_mapping = json_decode($mapping_json, true);
        if (!is_array($requested_mapping)) {
            student_import_response(['error' => 'The selected column mapping is invalid.'], 422);
        }
        foreach (['student_no', 'full_name', 'program', 'year_level', 'section'] as $field) {
            if (array_key_exists($field, $requested_mapping)) {
                $value = $requested_mapping[$field];
                if ($field === 'full_name' && $value === '__combined_name' &&
                    isset($auto_mapping['first_name'], $auto_mapping['last_name'])) {
                    $mapping[$field] = '__combined_name';
                } elseif (is_numeric($value) && (int)$value >= 0 && (int)$value < count($headers)) {
                    $mapping[$field] = (int)$value;
                } else {
                    unset($mapping[$field]);
                }
            }
        }
    }
    if (!isset($mapping['student_no']) || !isset($mapping['full_name'])) {
        student_import_response([
            'mapping_required' => true,
            'headers' => $headers,
            'mapping' => $mapping,
            'has_name_parts' => isset($auto_mapping['first_name'], $auto_mapping['last_name']),
        ], 200);
    }

    $prepared_rows = [];
    $seen = [];
    $nonempty = 0;
    foreach ($records as $offset => $record) {
        $record = array_map(static function ($value) {
            return trim((string)$value);
        }, $record);
        if (!array_filter($record, static function ($value) { return $value !== ''; })) {
            continue;
        }
        $nonempty++;
        if ($nonempty > 5000) {
            student_import_response(['error' => 'The CSV contains more than 5,000 non-empty data rows.'], 413);
        }
        $row_number = $offset + 2;
        $student_no = student_import_normalize_number((string)($record[$mapping['student_no']] ?? ''));
        $full_name = student_import_name_from_row($record, $mapping);
        $program = isset($mapping['program']) ? trim((string)($record[$mapping['program']] ?? '')) : '';
        $year = isset($mapping['year_level']) ? trim((string)($record[$mapping['year_level']] ?? '')) : '';
        $section = isset($mapping['section']) ? trim((string)($record[$mapping['section']] ?? '')) : '';
        $reason = '';
        if (preg_match('/^\d{4}-\d{5}-BN-\d$/', $student_no) !== 1) {
            $reason = 'Invalid student number.';
        } elseif ($student_no !== '' && isset($seen[$student_no])) {
            $reason = 'Duplicate student number in this file.';
        } else {
            $seen[$student_no] = true;
            if ($full_name === '') {
                $reason = 'Full name is required.';
            } elseif (mb_strlen($full_name) > 100) {
                $reason = 'Full name must be 100 characters or fewer.';
            } elseif (mb_strlen($program) > 100 || mb_strlen($year) > 20 ||
                mb_strlen($section) > 20 || mb_strlen($contact) > 30) {
                $reason = 'Program, year, section, or contact exceeds the allowed length.';
            }
        }
        $prepared_rows[] = [
            'file_row' => $row_number,
            'student_no' => $student_no,
            'full_name' => $full_name,
            'program' => $program,
            'year_level' => $year,
            'section' => $section,
            'reason' => $reason,
        ];
    }

    $existing = [];
    $numbers = array_values(array_unique(array_filter(array_column($prepared_rows, 'student_no'))));
    foreach (array_chunk($numbers, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $query = $pdo->prepare("SELECT student_no FROM students WHERE student_no IN ({$placeholders})");
        $query->execute($chunk);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $number) {
            $existing[(string)$number] = true;
        }
    }

    $counts = ['valid_new' => 0, 'will_update' => 0, 'skipped' => 0, 'rejected' => 0];
    foreach ($prepared_rows as &$row) {
        if ($row['reason'] !== '') {
            $row['status'] = 'Rejected';
            $counts['rejected']++;
        } elseif (isset($existing[$row['student_no']])) {
            $row['status'] = $mode === 'update' ? 'Exists: will update' : 'Exists: skip';
            $counts[$mode === 'update' ? 'will_update' : 'skipped']++;
        } else {
            $row['status'] = 'Valid (new)';
            $counts['valid_new']++;
        }
    }
    unset($row);
    return [
        'rows' => $prepared_rows,
        'counts' => $counts,
        'total_rows' => $nonempty,
        'headers' => $headers,
        'mapping' => $mapping,
    ];
}

if (!student_import_pin_unlocked()) {
    student_import_response(['error' => 'Unlock the Students PIN again before importing.'], 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    student_import_response(['error' => 'Unsupported request.'], 405);
}
if (!isset($_SESSION['students_csrf_token']) ||
    !hash_equals((string)$_SESSION['students_csrf_token'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    student_import_response(['error' => 'Your session has expired. Refresh the Students page and try again.'], 403);
}

$operation = $_POST['operation'] ?? '';
if (!in_array($operation, ['preview', 'import'], true)) {
    student_import_response(['error' => 'Unsupported student import action.'], 400);
}
require_once '../../includes/db.php';
try {
    $pdo = get_pdo_connection();
    if ((int)$pdo->query('SELECT COUNT(*) FROM import_log')->fetchColumn() > 0) {
        student_import_response([
            'error' => 'The one-time student masterlist import has already been completed.',
            'imported' => true,
        ], 409);
    }
} catch (Throwable $exception) {
    student_import_response(['error' => 'Unable to check the student import status.'], 500);
}
[$contents, $filename] = student_import_read_file();
$mode = is_string($_POST['duplicate_mode'] ?? null) ? $_POST['duplicate_mode'] : 'skip';
$mapping_json = is_string($_POST['mapping'] ?? null) ? $_POST['mapping'] : null;
try {
    $prepared = student_import_prepare($pdo, $contents, $mapping_json, $mode);
} catch (Throwable $exception) {
    student_import_response(['error' => 'Unable to validate the CSV against the student registry.'], 500);
}
if (isset($prepared['mapping_required'])) {
    student_import_response($prepared);
}

if ($operation === 'preview') {
    $preview = array_slice($prepared['rows'], 0, 50);
    $rejected = [];
    foreach ($prepared['rows'] as $row) {
        if ($row['status'] === 'Rejected') {
            $rejected[] = [
                'file_row' => $row['file_row'],
                'student_no' => $row['student_no'],
                'reason' => $row['reason'],
            ];
        }
    }
    student_import_response([
        'preview' => $preview,
        'rejected_rows' => $rejected,
        'counts' => $prepared['counts'],
        'total_rows' => $prepared['total_rows'],
        'importable' => $prepared['counts']['valid_new'] + $prepared['counts']['will_update'] > 0,
        'mapping' => $prepared['mapping'],
    ]);
}

$importable_count = $prepared['counts']['valid_new'] + $prepared['counts']['will_update'];
if ($importable_count < 1) {
    student_import_response(['error' => 'There are no valid student rows to import.'], 422);
}

$added = 0;
$updated = 0;
$skipped = 0;
$rejected = 0;
$rejected_rows = [];
$lock_acquired = false;

try {
    $lock_acquired = (int)$pdo->query(
        "SELECT GET_LOCK('csc_students_masterlist_import', 10)"
    )->fetchColumn() === 1;
    if (!$lock_acquired) {
        student_import_response([
            'error' => 'Another masterlist import is being processed. Please try again shortly.',
        ], 409);
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM import_log')->fetchColumn() > 0) {
        $pdo->query("SELECT RELEASE_LOCK('csc_students_masterlist_import')");
        $lock_acquired = false;
        student_import_response([
            'error' => 'The one-time student masterlist import has already been completed.',
            'imported' => true,
        ], 409);
    }
    $insert = $pdo->prepare(
        'INSERT INTO students (student_no, full_name, program, year_level, section)
         VALUES (?, ?, ?, ?, ?)'
    );
    $update = $pdo->prepare(
        'UPDATE students
         SET full_name = ?, program = ?, year_level = ?, section = ?
         WHERE student_no = ?'
    );
    $exists = $pdo->prepare('SELECT student_no FROM students WHERE student_no = ? FOR UPDATE');
    $pdo->beginTransaction();
    foreach (array_chunk($prepared['rows'], 500) as $chunk) {
        foreach ($chunk as $row) {
            if ($row['reason'] !== '') {
                $rejected++;
                $rejected_rows[] = [
                    'file_row' => $row['file_row'],
                    'student_no' => $row['student_no'],
                    'reason' => $row['reason'],
                ];
                continue;
            }
            $exists->execute([$row['student_no']]);
            $already_exists = $exists->fetchColumn() !== false;
            if ($already_exists && $mode === 'skip') {
                $skipped++;
                continue;
            }
            if ($already_exists) {
                $update->execute([
                    $row['full_name'], $row['program'], $row['year_level'],
                    $row['section'], $row['student_no'],
                ]);
                $updated++;
                continue;
            }
            $insert->execute([
                $row['student_no'], $row['full_name'], $row['program'],
                $row['year_level'], $row['section'],
            ]);
            $added++;
        }
    }
    $log = $pdo->prepare(
        'INSERT INTO import_log
            (filename, imported_by, total_rows, added, updated, skipped, rejected)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $imported_by = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown officer');
    $log->execute([
        mb_substr($filename, 0, 255), mb_substr($imported_by, 0, 100),
        $prepared['total_rows'], $added, $updated, $skipped, $rejected,
    ]);
    $pdo->commit();
    $pdo->query("SELECT RELEASE_LOCK('csc_students_masterlist_import')");
    $lock_acquired = false;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($lock_acquired) {
        $pdo->query("SELECT RELEASE_LOCK('csc_students_masterlist_import')");
    }
    student_import_response(['error' => 'The student import could not be completed. No changes were saved.'], 500);
}

student_import_response([
    'success' => true,
    'counts' => [
        'added' => $added,
        'updated' => $updated,
        'skipped' => $skipped,
        'rejected' => $rejected,
    ],
    'total_rows' => $prepared['total_rows'],
    'rejected_rows' => $rejected_rows,
]);
