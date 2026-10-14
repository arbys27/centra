    <?php
    require_once '../../includes/auth_check.php';
    require_once '../../config/db.php';
    require_once '../../includes/db.php';

    $is_ajax = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    $fail = static function (string $message, int $status = 422) use ($is_ajax): void {
        if ($is_ajax) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['notification_flash'] = [
            'type' => 'error',
            'title' => 'Request could not be saved',
            'detail' => $message,
        ];
        header('Location: scan.php?error=' . urlencode($message));
        exit;
    };
    $succeed = static function (string $message, string $detail) use ($is_ajax): void {
        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['notification_flash'] = [
            'type' => 'success',
            'title' => 'Request saved',
            'detail' => $detail,
        ];
        header('Location: list.php?success=1');
        exit;
    };

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $fail('This request must be submitted using the form.', 405);
    }
    if (!isset($_SESSION['printan_csrf_token']) ||
        !hash_equals($_SESSION['printan_csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $fail('Your session has expired. Refresh the page and try again.', 403);
    }

    $raw_student_no = $_POST['student_no'] ?? '';
    $student_no = is_string($raw_student_no) ? normalize_student_number($raw_student_no) : '';
    if (!is_valid_student_number($student_no)) {
        $fail('Student number must match 0000-00000-BN-0.');
    }

    $service_type = $_POST['service_type'] ?? '';
    if (!is_string($service_type) || !in_array($service_type, ['print', 'xerox', 'scan'], true)) {
        $fail('Select print, xerox, or scan as the service.');
    }
    $num_pages = filter_var($_POST['num_pages'] ?? null, FILTER_VALIDATE_INT);
    if ($num_pages === false || $num_pages === null || $num_pages < 1 || $num_pages > 1000) {
        $fail('Pages must be between 1 and 1000.');
    }
    $copies = 1;
    $own_paper_value = $_POST['own_paper'] ?? '0';
    $colored_value = $_POST['is_colored'] ?? '0';
    $sides_value = $_POST['back_to_back'] ?? '0';
    if (!in_array($own_paper_value, ['0', '1'], true) ||
        !in_array($colored_value, ['0', '1'], true) ||
        !in_array($sides_value, ['0', '1'], true)) {
        $fail('Choose valid paper, color, and sides options.');
    }
    $own_paper = $own_paper_value === '1' ? 1 : 0;
    $is_colored = $colored_value === '1' ? 1 : 0;
    $back_to_back = (!$own_paper && $sides_value === '1') ? 1 : 0;
    if ($own_paper) {
        $rate = $is_colored ? 3 : 1;
    } else {
        $rate = !$is_colored ? ($back_to_back ? 3 : 2) : ($back_to_back ? 7 : 4);
    }
    $amount = $rate * $num_pages;

    $pdo = get_pdo_connection();
    $blacklist = get_active_blacklist($pdo, $student_no);
    if ($blacklist !== null) {
        $fail('Student is blacklisted: ' . $blacklist['reason'], 403);
    }
    $student_query = $pdo->prepare(
        'SELECT student_no, full_name, program, year_level FROM students WHERE student_no = ?'
    );
    $student_query->execute([$student_no]);
    $student = $student_query->fetch();
    if ($student === false) {
        $fail('Student number not found. Please register the student first.', 404);
    }

    try {
        $insert = $pdo->prepare(
            "INSERT INTO printan_records
                (student_no, full_name, program, year_level, service_type, is_colored, own_paper,
                back_to_back, num_pages, copies, rate_per_page, amount_paid, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')"
        );
        $insert->execute([
            $student['student_no'], $student['full_name'], $student['program'], $student['year_level'],
            $service_type, $is_colored, $own_paper, $back_to_back, $num_pages, $copies,
            $rate, $amount,
        ]);
    } catch (PDOException $exception) {
        error_log('Printan request save failed: ' . $exception->getMessage());
        $fail('Unable to save the request right now.', 500);
    }

    $succeed(
        'Printan request saved successfully.',
        $student['full_name'] . ' · ' . $num_pages . ' pages · ₱' . number_format($amount, 2)
    );
