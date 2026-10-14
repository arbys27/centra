<?php
require_once 'includes/auth_check.php';
require_once 'config/db.php';
require_once 'includes/db.php';
$base = '';
$page_title = 'Dashboard';
$timezone = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $timezone);
$today = $now->setTime(0, 0, 0);
$week_end = $today->modify('+7 days');

$officer_columns_result = $conn->query('SHOW COLUMNS FROM officers');
$officer_columns = [];
while ($column = $officer_columns_result->fetch_assoc()) {
    $officer_columns[] = $column['Field'];
}
if (!in_array('birthday', $officer_columns, true)) {
    $conn->query('ALTER TABLE officers ADD COLUMN birthday DATE NULL AFTER position');
}

$event_table_check = $conn->prepare(
    "SELECT COUNT(*) AS table_count FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'officer_events'"
);
$event_table_check->execute();
if ((int)$event_table_check->get_result()->fetch_assoc()['table_count'] === 0) {
    $conn->query(
        'CREATE TABLE officer_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            event_date DATE NOT NULL,
            event_time TIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

$today_birthdays = [];
$birthday_result = $conn->query(
    "SELECT full_name, position, birthday FROM officers WHERE birthday IS NOT NULL AND birthday <> '0000-00-00'"
);
while ($officer = $birthday_result->fetch_assoc()) {
    if (!preg_match('/^\d{4}-(\d{2})-(\d{2})$/', (string)$officer['birthday'], $parts)) {
        continue;
    }
    $month = (int)$parts[1];
    $day = (int)$parts[2];
    if (!checkdate($month, $day, 2000)) {
        continue;
    }
    $is_leap_day = $month === 2 && $day === 29;
    if (($month === (int)$today->format('n') && $day === (int)$today->format('j')) ||
        ($is_leap_day && !checkdate(2, 29, (int)$today->format('Y')) &&
            $today->format('m-d') === '02-28')) {
        $today_birthdays[] = $officer;
    }
}

$event_columns_result = $conn->query('SHOW COLUMNS FROM officer_events');
$event_columns = [];
while ($column = $event_columns_result->fetch_assoc()) {
    $event_columns[$column['Field']] = strtolower($column['Type']);
}
$event_date_column = null;
foreach (['start_at', 'event_date', 'start_date', 'starts_at', 'event_datetime', 'start_datetime', 'date'] as $candidate) {
    if (isset($event_columns[$candidate])) {
        $event_date_column = $candidate;
        break;
    }
}
$event_title_column = null;
foreach (['title', 'event_title', 'event_name', 'name'] as $candidate) {
    if (isset($event_columns[$candidate])) {
        $event_title_column = $candidate;
        break;
    }
}
$event_time_column = null;
foreach (['event_time', 'start_time', 'time'] as $candidate) {
    if (isset($event_columns[$candidate])) {
        $event_time_column = $candidate;
        break;
    }
}
$agenda_error = '';
$today_events = [];
$upcoming_events = [];
if ($event_date_column === null || $event_title_column === null) {
    $agenda_error = 'The officer_events table must have a title and event date column.';
    error_log($agenda_error);
} else {
    $events_result = $conn->query('SELECT * FROM officer_events');
    while ($event = $events_result->fetch_assoc()) {
        $raw_date = (string)$event[$event_date_column];
        if ($raw_date === '') {
            continue;
        }
        try {
            $starts_at = new DateTimeImmutable($raw_date, $timezone);
            if ($event_time_column !== null && !empty($event[$event_time_column])) {
                $starts_at = new DateTimeImmutable(
                    $starts_at->format('Y-m-d') . ' ' . $event[$event_time_column],
                    $timezone
                );
            }
        } catch (Exception $exception) {
            error_log('Skipping officer event with invalid date: ' . $exception->getMessage());
            continue;
        }

        $event['display_time'] = !empty($event['all_day'])
            ? 'All day'
            : ($event_time_column !== null && !empty($event[$event_time_column])
            ? $starts_at->format('g:i A')
            : (preg_match('/\d{2}:\d{2}/', $raw_date) ? $starts_at->format('g:i A') : 'All day'));
        $event['starts_at'] = $starts_at;
        $event['within_30_minutes'] = empty($event['all_day']) &&
            $starts_at >= $now && $starts_at <= $now->modify('+30 minutes');
        $event_day = $starts_at->setTime(0, 0, 0);
        if ($event_day == $today) {
            $today_events[] = $event;
        } elseif ($event_day > $today && $event_day <= $week_end) {
            $upcoming_events[] = $event;
        }
    }
}
usort($today_events, function ($first, $second) {
    return $first['starts_at'] <=> $second['starts_at'];
});
usort($upcoming_events, function ($first, $second) {
    return $first['starts_at'] <=> $second['starts_at'];
});

$show_agenda_reminder = !isset($_SESSION['dashboard_agenda_reminder_shown']) && !empty($today_events);
if ($show_agenda_reminder) {
    $_SESSION['dashboard_agenda_reminder_shown'] = true;
}

/* ---- at-a-glance numbers (small strip on the wallpaper) ---- */
$day_start = $today->format('Y-m-d H:i:s');
$day_end = $today->modify('+1 day')->format('Y-m-d H:i:s');
$transactions_query = $conn->prepare(
    "SELECT
        (SELECT COUNT(*) FROM printan_records
         WHERE date_time >= ? AND date_time < ? AND status = 'completed') +
        (SELECT COUNT(*) FROM ecopuntos_records
         WHERE date_time >= ? AND date_time < ?) +
        (SELECT COUNT(*) FROM iskolend_borrow
         WHERE date_borrowed >= ? AND date_borrowed < ?) +
        (SELECT COUNT(*) FROM iskolend_borrow
         WHERE date_returned >= ? AND date_returned < ?) AS total"
);
$transactions_query->bind_param(
    'ssssssss',
    $day_start, $day_end,
    $day_start, $day_end,
    $day_start, $day_end,
    $day_start, $day_end
);
$transactions_query->execute();
$glance_transactions = (int)$transactions_query->get_result()->fetch_assoc()['total'];

$officers_query = $conn->prepare(
    'SELECT COUNT(DISTINCT officer_id) AS total
     FROM attendance_records
     WHERE log_date = ? AND time_in IS NOT NULL'
);
$today_date = $today->format('Y-m-d');
$officers_query->bind_param('s', $today_date);
$officers_query->execute();
$glance_officers = (int)$officers_query->get_result()->fetch_assoc()['total'];

function initials($name) {
    $parts = preg_split('/\s+/', trim((string)$name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out !== '' ? $out : '?';
}

include 'includes/header.php';
?>

<link rel="stylesheet" href="<?php echo $base; ?>assets/vendor/fonts/manrope.css">

<style>
  /* ===== Full-bleed wallpaper dashboard ===== */
  html, body { height: 100%; overflow: hidden; }
  html { background: #0b0d10; }

  :root {
    --wall-left: 264px;
    --g100:#f3f4f6; --g200:#e5e7eb; --g300:#d1d5db; --g400:#9ca3af; --g500:#6b7280; --g900:#111827;
  }

  .dash-wall-bg {
    position: fixed;
    top: 0; right: 0; bottom: 0; left: var(--wall-left);
    z-index: -1;
    background:
      radial-gradient(120% 80% at 100% 0%, rgba(8,10,14,.55) 0%, rgba(8,10,14,0) 55%),
      linear-gradient(180deg, rgba(8,10,14,.40) 0%, rgba(8,10,14,0) 28%, rgba(8,10,14,0) 62%, rgba(8,10,14,.62) 100%),
      url('<?php echo $base; ?>assets/img/csc-wallpaper.png') center / cover no-repeat;
  }

  body.dash-wall h1 { color: #fff; text-shadow: 0 2px 14px rgba(0,0,0,.6); }

  .dash-ui {
    position: fixed;
    top: 0; right: 0; bottom: 0; left: var(--wall-left);
    z-index: 5;
    pointer-events: none;
    font-family: 'Manrope', system-ui, -apple-system, 'Segoe UI', sans-serif;
    color: #f3f4f6;
  }
  .dash-ui > * { pointer-events: auto; }

  /* ---------- Clock ---------- */
  .clock-card {
    position: absolute;
    top: 24px; right: 24px;
    box-sizing: border-box;
    width: min(420px, calc(100vw - var(--wall-left) - 48px));
    padding: 18px 22px;
    border: 1px solid rgba(255,255,255,.16);
    border-radius: 18px;
    background: linear-gradient(145deg, rgba(31,41,55,.58), rgba(17,24,39,.48));
    -webkit-backdrop-filter: blur(18px) saturate(135%);
    backdrop-filter: blur(18px) saturate(135%);
    box-shadow: 0 14px 36px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.06);
    color: #fff;
    text-align: right;
    text-shadow: 0 2px 14px rgba(0,0,0,.5);
  }
  .clock-time {
    display: block;
    font-size: clamp(32px, 4.5vw, 60px);
    font-weight: 800;
    letter-spacing: -.04em;
    line-height: 1;
  }
  .clock-date {
    display: block;
    margin-top: 8px;
    font-size: clamp(13px, 1.3vw, 17px);
    font-weight: 600;
    color: var(--g200);
    letter-spacing: .01em;
  }
  .clock-label {
    display: inline-flex; align-items: center; gap: 7px;
    margin-top: 10px; padding: 5px 12px;
    font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
    color: var(--g200);
    background: rgba(17,24,39,.5);
    border: 1px solid rgba(255,255,255,.14);
    border-radius: 999px;
    -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
    text-shadow: none;
  }
  .clock-label::before {
    content: ''; width: 6px; height: 6px; border-radius: 50%; background: #d1d5db;
    box-shadow: 0 0 0 0 rgba(209,213,219,.7); animation: pulse 2s infinite;
  }
  @keyframes pulse { 70% { box-shadow: 0 0 0 7px rgba(209,213,219,0); } 100% { box-shadow: 0 0 0 0 rgba(209,213,219,0); } }

  /* ---------- Positioning ---------- */
  .dash-left  { position: absolute; left: 24px; bottom: 26px; }
  .dash-right { position: absolute; right: 40px; bottom: 26px; display: flex; flex-direction: column; gap: 14px; align-items: flex-end; }

  /* ---------- Glass cards ---------- */
  .glass {
    width: min(350px, calc(100vw - var(--wall-left) - 48px));
    padding: 18px 20px 16px;
    border-radius: 20px;
    background: linear-gradient(145deg, rgba(31,41,55,.62), rgba(17,24,39,.58));
    -webkit-backdrop-filter: blur(20px) saturate(140%);
    backdrop-filter: blur(20px) saturate(140%);
    border: 1px solid rgba(255,255,255,.13);
    box-shadow: 0 18px 44px rgba(0,0,0,.38), inset 0 1px 0 rgba(255,255,255,.08);
    animation: rise .5s ease both;
  }
  .dash-right .glass:nth-child(2) { animation-delay: .08s; }
  @keyframes rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }

  .glass-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
  .glass h2 {
    margin: 0; font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--g300);
  }
  .count-pill {
    min-width: 24px; padding: 2px 9px; text-align: center;
    font-size: 11px; font-weight: 700; color: var(--g900); background: var(--g200); border-radius: 999px;
  }

  .hub-list { list-style: none; margin: 0; padding: 0; }
  .hub-list li {
    display: flex; align-items: center; gap: 12px;
    padding: 9px 10px; margin: 0 -10px;
    border-radius: 12px; font-size: 14px; transition: background .15s;
  }
  .dash-ui .hub-list li {
    color: #fff;
    background: rgba(17,24,39,.72);
    border: 1px solid rgba(255,255,255,.14);
  }
  .dash-ui .hub-list li:hover { background: rgba(55,65,81,.82); }
  .hub-list li:hover { background: rgba(255,255,255,.07); }

  .time-chip {
    flex: none; min-width: 66px; padding: 5px 8px; text-align: center;
    font-size: 11px; font-weight: 700; letter-spacing: .02em; color: var(--g200);
    background: rgba(255,255,255,.09); border: 1px solid rgba(255,255,255,.1); border-radius: 9px;
  }
  .date-chip {
    flex: none; width: 44px; padding: 4px 0; text-align: center;
    background: rgba(255,255,255,.09); border: 1px solid rgba(255,255,255,.1); border-radius: 10px; line-height: 1.1;
  }
  .date-chip b { display: block; font-size: 16px; color: #fff; }
  .date-chip span { font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--g400); }

  .item-body { min-width: 0; flex: 1; }
  .item-title { display: block; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .item-sub   { display: block; font-size: 12px; color: var(--g400); margin-top: 1px; }

  .avatar {
    flex: none; width: 36px; height: 36px; display: grid; place-items: center;
    border-radius: 50%; font-size: 12px; font-weight: 800; color: var(--g900);
    background: linear-gradient(135deg, #f3f4f6, #9ca3af);
  }

  .soon {
    flex: none; display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 9px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
    color: var(--g900); background: var(--g200); border-radius: 999px;
  }
  .soon::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: var(--g900); animation: pulse2 1.4s infinite; }
  @keyframes pulse2 { 50% { opacity: .25; } }

  .hub-more { margin: 8px 0 0; font-size: 12px; font-weight: 600; color: var(--g400); }

  /* ---------- At-a-glance strip ---------- */
  .glance {
    position: absolute; left: 50%; top: 32px; transform: translateX(-50%);
    display: flex; gap: 8px;
  }
  .glance .chip {
    display: flex; align-items: center; gap: 10px; padding: 9px 16px;
    border-radius: 14px; background: rgba(17,24,39,.5);
    border: 1px solid rgba(255,255,255,.13);
    -webkit-backdrop-filter: blur(16px); backdrop-filter: blur(16px);
    box-shadow: 0 8px 22px rgba(0,0,0,.25);
  }
  .glance .chip b { font-size: 16px; font-weight: 800; color: #fff; letter-spacing: -.02em; }
  .glance .chip span { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--g400); }
  @media (max-width: 1200px) { .glance { display: none; } }

  /* ---------- Service shortcuts ---------- */
  .service-shortcuts {
    position: absolute;
    top: max(210px, 34vh);
    right: 24px;
    width: min(220px, calc(100% - 48px));
  }
  .shortcuts-heading {
    margin: 0 0 8px;
    color: #fff;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .08em;
    text-align: right;
    text-transform: uppercase;
    text-shadow: 0 2px 14px rgba(0,0,0,.55);
  }
  .shortcut-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 7px;
  }
  .shortcut-card {
    min-height: 48px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 7px 10px;
    color: #fff;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 12px;
    background: rgba(17,24,39,.68);
    -webkit-backdrop-filter: blur(12px);
    backdrop-filter: blur(12px);
    box-shadow: 0 6px 16px rgba(0,0,0,.22);
    transition: transform .2s ease, border-color .2s ease, background .2s ease;
  }
  .shortcut-card:hover {
    color: #fff;
    transform: translateY(-4px);
    border-color: rgba(255,255,255,.42);
    background: linear-gradient(145deg, rgba(55,65,81,.88), rgba(17,24,39,.82));
  }
  .shortcut-card:focus-visible {
    outline: 3px solid #fff;
    outline-offset: 4px;
  }
  .shortcut-icon {
    flex: none;
    width: 32px;
    height: 32px;
    display: grid;
    place-items: center;
    border-radius: 9px;
    background: rgba(255,255,255,.12);
    font-size: 17px;
  }
  .shortcut-copy { min-width: 0; }
  .shortcut-title {
    display: block;
    font-size: 13px;
    font-weight: 800;
    line-height: 1.2;
  }
  .shortcut-description {
    display: none;
  }

  /* ---------- Agenda reminder modal ---------- */
  #agendaReminderModal .modal-content {
    border: 0; border-radius: 20px; overflow: hidden; font-family: 'Manrope', system-ui, sans-serif;
    box-shadow: 0 30px 80px rgba(0,0,0,.45);
  }
  #agendaReminderModal .modal-header { background: linear-gradient(135deg, #111827, #374151); color: #fff; border: 0; padding: 18px 22px; }
  #agendaReminderModal .btn-close { filter: invert(1); opacity: .8; }
  #agendaReminderModal .modal-body { padding: 14px 22px; }
  #agendaReminderModal .hub-list li {
    color: #111827;
    background: #f3f4f6;
    border: 1px solid #d1d5db;
  }
  #agendaReminderModal .hub-list li:hover { background: #f3f4f6; }
  #agendaReminderModal .item-title { color: #111827; }
  #agendaReminderModal .time-chip {
    color: #fff;
    background: #374151;
    border-color: #374151;
  }
  #agendaReminderModal .soon { background: #111827; color: #fff; }
  #agendaReminderModal .soon::before { background: #fff; }
  #agendaReminderModal .modal-footer { border: 0; padding: 4px 22px 18px; }
  #agendaReminderModal .btn-gotit {
    background: #111827; color: #fff; border: 0; border-radius: 12px; padding: 10px 22px; font-weight: 700;
  }
  #agendaReminderModal .btn-gotit:hover { background: #374151; }

  /* ---------- Sidebar auto-hide + hamburger ---------- */
  .dash-wall-bg, .dash-ui { transition: left .45s cubic-bezier(.4, 0, .2, 1); }

  .dash-sidebar {
    transition: translate .45s cubic-bezier(.4, 0, .2, 1), opacity .35s ease, visibility 0s linear 0s;
  }
  body.sidebar-hidden .dash-sidebar {
    translate: -100% 0;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: translate .45s cubic-bezier(.4, 0, .2, 1), opacity .35s ease, visibility 0s linear .45s;
  }

  .sidebar-toggle {
    position: fixed;
    top: 20px;
    left: calc(var(--wall-left) + 18px);
    z-index: 1000;
    width: 46px; height: 46px;
    display: grid; place-items: center;
    padding: 0;
    color: #f3f4f6;
    appearance: none;
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,.16);
    background: rgba(17,24,39,.55);
    -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px);
    box-shadow: 0 8px 22px rgba(0,0,0,.3);
    cursor: pointer;
    transition: left .45s cubic-bezier(.4, 0, .2, 1), background .2s, transform .2s;
  }
  .sidebar-toggle:hover { background: rgba(55,65,81,.75); transform: scale(1.06); }
  .sidebar-toggle:active { transform: scale(.95); }
  .sidebar-toggle:focus-visible { outline: 2px solid #e5e7eb; outline-offset: 3px; }
  .sidebar-toggle .bars { position: relative; width: 20px; height: 14px; }
  .sidebar-toggle .bars i {
    position: absolute; left: 0; width: 100%; height: 2px; border-radius: 2px; background: #f3f4f6;
    transition: transform .35s cubic-bezier(.4, 0, .2, 1), opacity .2s, top .35s cubic-bezier(.4, 0, .2, 1);
  }
  .sidebar-toggle .bars i:nth-child(1) { top: 0; }
  .sidebar-toggle .bars i:nth-child(2) { top: 6px; }
  .sidebar-toggle .bars i:nth-child(3) { top: 12px; }
  /* sidebar open -> bars morph into an X */
  body:not(.sidebar-hidden) .sidebar-toggle .bars i:nth-child(1) { top: 6px; transform: rotate(45deg); }
  body:not(.sidebar-hidden) .sidebar-toggle .bars i:nth-child(2) { opacity: 0; }
  body:not(.sidebar-hidden) .sidebar-toggle .bars i:nth-child(3) { top: 6px; transform: rotate(-45deg); }

  @media (max-width: 760px) {
    .clock-card {
      top: 16px;
      right: 16px;
      width: min(360px, calc(100vw - var(--wall-left) - 32px));
      padding: 14px 16px;
    }
    .clock-time { font-size: clamp(28px, 8vw, 40px); }
    .clock-date { font-size: 13px; }
    .service-shortcuts {
      right: 16px;
      width: min(200px, calc(100% - 32px));
    }
    .shortcuts-heading { font-size: 11px; }
    .shortcut-grid { gap: 6px; }
    .shortcut-card {
      min-height: 44px;
      gap: 8px;
      padding: 6px 8px;
    }
    .shortcut-icon { width: 30px; height: 30px; border-radius: 8px; font-size: 16px; }
    .shortcut-title { font-size: 12px; }
  }

  @media (prefers-reduced-motion: reduce) {
    .dash-wall-bg, .dash-ui, .dash-sidebar, .sidebar-toggle, .sidebar-toggle .bars i { transition: none !important; }
    .shortcut-card { transition: none !important; }
  }
</style>

<div class="dash-wall-bg" id="dashWall" aria-hidden="true"></div>

<button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Hide sidebar" aria-expanded="true" title="Toggle sidebar (Esc to hide)">
  <span class="bars" aria-hidden="true"><i></i><i></i><i></i></span>
</button>

<div class="dash-ui" id="dashUi">

  <section class="clock-card" aria-label="Philippine Standard Time">
    <time class="clock-time" id="liveTime"><?php echo htmlspecialchars($now->format('g:i A')); ?></time>
    <span class="clock-date" id="liveDate"><?php echo htmlspecialchars($now->format('l, F j, Y')); ?></span>
    <div class="clock-label">Philippine Standard Time</div>
  </section>

  <div class="glance" aria-label="At a glance">
    <div class="chip"><b><?php echo number_format($glance_transactions); ?></b><span>Transactions today</span></div>
    <div class="chip"><b><?php echo number_format($glance_officers); ?></b><span>Officers on duty today</span></div>
  </div>

  <section class="service-shortcuts" aria-labelledby="shortcutHeading">
    <h2 class="shortcuts-heading" id="shortcutHeading">Quick access</h2>
    <nav class="shortcut-grid" aria-label="Service shortcuts">
      <a class="shortcut-card" href="<?php echo $base; ?>modules/printan/scan.php">
        <span class="shortcut-icon" aria-hidden="true">🖨️</span>
        <span class="shortcut-copy">
          <span class="shortcut-title">Printan ng Bayan</span>
          <span class="shortcut-description">Log a print, xerox, or scan request</span>
        </span>
      </a>
      <a class="shortcut-card" href="<?php echo $base; ?>modules/iskolend/borrow.php">
        <span class="shortcut-icon" aria-hidden="true">📦</span>
        <span class="shortcut-copy">
          <span class="shortcut-title">ISKOLEND</span>
          <span class="shortcut-description">Borrow and return council items</span>
        </span>
      </a>
      <a class="shortcut-card" href="<?php echo $base; ?>modules/attendance/scan.php">
        <span class="shortcut-icon" aria-hidden="true">🕒</span>
        <span class="shortcut-copy">
          <span class="shortcut-title">Officer Attendance</span>
          <span class="shortcut-description">Record officer time in or out</span>
        </span>
      </a>
    </nav>
  </section>

  <?php if ($today_birthdays): ?>
  <div class="dash-left">
    <section class="glass dashboard-birthdays" aria-labelledby="birthdayHeading">
      <div class="glass-head">
        <h2 id="birthdayHeading">🎉 Today’s Birthdays</h2>
        <span class="count-pill"><?php echo count($today_birthdays); ?></span>
      </div>
      <ul class="hub-list">
        <?php foreach (array_slice($today_birthdays, 0, 3) as $officer): ?>
          <li>
            <div class="avatar"><?php echo htmlspecialchars(initials($officer['full_name'])); ?></div>
            <div class="item-body">
              <span class="item-title"><?php echo htmlspecialchars($officer['full_name']); ?></span>
              <span class="item-sub"><?php echo htmlspecialchars($officer['position']); ?></span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($today_birthdays) > 3): ?><p class="hub-more">+<?php echo count($today_birthdays) - 3; ?> more birthdays</p><?php endif; ?>
    </section>
  </div>
  <?php endif; ?>

  <?php if ($today_events || $upcoming_events): ?>
  <div class="dash-right">
    <?php if ($today_events): ?>
    <section class="glass dashboard-today" aria-labelledby="todayAgendaHeading">
      <div class="glass-head">
        <h2 id="todayAgendaHeading">Today</h2>
        <span class="count-pill"><?php echo count($today_events); ?></span>
      </div>
      <ul class="hub-list">
        <?php foreach (array_slice($today_events, 0, 3) as $event): ?>
          <li>
            <span class="time-chip"><?php echo htmlspecialchars($event['display_time']); ?></span>
            <div class="item-body"><span class="item-title"><?php echo htmlspecialchars($event[$event_title_column]); ?></span></div>
            <?php if ($event['within_30_minutes']): ?><span class="soon">Soon</span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($today_events) > 3): ?><p class="hub-more">+<?php echo count($today_events) - 3; ?> more events today</p><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($upcoming_events): ?>
    <section class="glass dashboard-upcoming" aria-labelledby="upcomingAgendaHeading">
      <div class="glass-head">
        <h2 id="upcomingAgendaHeading">Next 7 days</h2>
        <span class="count-pill"><?php echo count($upcoming_events); ?></span>
      </div>
      <ul class="hub-list">
        <?php foreach (array_slice($upcoming_events, 0, 2) as $event): ?>
          <li>
            <div class="date-chip">
              <b><?php echo htmlspecialchars($event['starts_at']->format('j')); ?></b>
              <span><?php echo htmlspecialchars($event['starts_at']->format('M')); ?></span>
            </div>
            <div class="item-body">
              <span class="item-title"><?php echo htmlspecialchars($event[$event_title_column]); ?></span>
              <span class="item-sub"><?php echo htmlspecialchars($event['starts_at']->format('D')); ?> · <?php echo htmlspecialchars($event['display_time']); ?></span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($upcoming_events) > 2): ?><p class="hub-more">+<?php echo count($upcoming_events) - 2; ?> more events</p><?php endif; ?>
    </section>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>

<?php if ($show_agenda_reminder && $today_events): ?>
<div class="modal fade" id="agendaReminderModal" tabindex="-1" aria-labelledby="agendaReminderTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title fs-5" id="agendaReminderTitle">Today’s Agenda</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <ul class="hub-list">
          <?php foreach ($today_events as $event): ?>
            <li>
              <span class="time-chip"><?php echo htmlspecialchars($event['display_time']); ?></span>
              <div class="item-body"><span class="item-title"><?php echo htmlspecialchars($event[$event_title_column]); ?></span></div>
              <?php if ($event['within_30_minutes']): ?><span class="soon">Soon</span><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="modal-footer"><button type="button" class="btn-gotit" data-bs-dismiss="modal">Got it</button></div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
/* Make the wallpaper start exactly at the sidebar's right edge and show through the page header */
(function () {
  document.body.classList.add('dash-wall');

  /* ----- Sidebar: auto-hide on load, hamburger to bring it back ----- */
  var sb = document.querySelector('.sidebar, #sidebar, .sidenav, aside');
  var toggle = document.getElementById('sidebarToggle');
  var sbWidth = 264;
  var sbHidden = false;

  function measure() {
    if (!sb || sbHidden) return;
    var r = Math.round(sb.getBoundingClientRect().right);
    if (r >= 150 && r <= 400) sbWidth = r;
  }
  function apply() {
    document.documentElement.style.setProperty('--wall-left', (sbHidden ? 0 : sbWidth) + 'px');
    document.body.classList.toggle('sidebar-hidden', sbHidden);
    if (toggle) {
      toggle.setAttribute('aria-expanded', String(!sbHidden));
      toggle.setAttribute('aria-label', sbHidden ? 'Show sidebar' : 'Hide sidebar');
    }
  }
  function setSidebarHidden(value) {
    sbHidden = value;
    if (!value) measure();
    apply();
  }

  measure();
  if (sb) {
    sb.classList.add('dash-sidebar');
  } else if (toggle) {
    toggle.style.display = 'none';   // no sidebar found, nothing to toggle
  }
  apply();

  if (toggle) {
    toggle.addEventListener('click', function () { setSidebarHidden(!sbHidden); });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !sbHidden) setSidebarHidden(true);
  });
  window.addEventListener('resize', function () { measure(); apply(); });

  // Slide the sidebar out shortly after the page appears
  if (sb) {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    setTimeout(function () { setSidebarHidden(true); }, reduce ? 0 : 500);
  }

  // Hide the page header bar (title, admin badge and the line under them)
  var ui = document.getElementById('dashUi');
  var sidebar = document.querySelector('.sidebar, #sidebar, .sidenav, aside');
  var h1s = document.querySelectorAll('h1');
  for (var i = 0; i < h1s.length; i++) {
    var h = h1s[i];
    if (sidebar && sidebar.contains(h)) continue;
    var bar = h.parentElement;
    if (bar && !bar.contains(ui) && bar !== document.body) {
      bar.style.display = 'none';
    } else {
      h.style.display = 'none';
    }
    break;
  }
  // Fallback: hide the admin badge by its text if it is still visible
  var all = document.querySelectorAll('body *');
  for (var j = 0; j < all.length; j++) {
    var n = all[j];
    if (ui.contains(n) || (sidebar && sidebar.contains(n))) continue;
    if (n.children.length === 0 && /CSC Administrator/i.test(n.textContent) && n.offsetParent !== null) {
      var badge = n.closest('a, button, div, span') || n;
      badge.style.display = 'none';
      break;
    }
  }

  // Remove the light page background behind the header so the wallpaper shows through
  var el = document.getElementById('dashWall').parentElement;
  while (el && el !== document.documentElement) {
    el.style.background = 'transparent';
    el = el.parentElement;
  }
})();
</script>

<script src="assets/js/dashboard.js"></script>
<?php include 'includes/footer.php'; ?>