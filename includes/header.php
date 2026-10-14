<?php
// Expects $page_title and $base (relative path prefix to root) to be set by the including page.
if (!isset($base)) $base = '';
if (!isset($page_title)) $page_title = '14th Central Student Council';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csc-base" content="<?php echo htmlspecialchars($base, ENT_QUOTES, 'UTF-8'); ?>">
<title><?php echo htmlspecialchars($page_title); ?> | 14th Central Student Council - PUP Binan Campus</title>
<link rel="stylesheet" href="<?php echo $base; ?>assets/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="<?php echo $base; ?>assets/vendor/fonts/manrope.css">
<link rel="stylesheet" href="<?php echo $base; ?>assets/css/style.css">
<link rel="stylesheet" href="<?php echo $base; ?>assets/css/notifications.css">
</head>
<body class="<?php echo $page_title === 'Dashboard' ? 'dashboard-page' : ''; ?>">
<div class="layout">
<aside class="sidebar">
    <div class="brand">
        <img class="brand-logo" src="<?php echo $base; ?>assets/img/629336028_1332709998878542_5484852480615400786_n.jpg" alt="14th Central Student Council seal">
        <div class="brand-title">14th Central Student Council</div>
        <div class="brand-sub">PUP Binan Campus</div>
    </div>
    <?php if ($page_title !== 'Dashboard'): ?>
    <button class="sidebar-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavigation" aria-controls="mainNavigation" aria-expanded="true">Menu</button>
    <?php endif; ?>
    <nav id="mainNavigation" class="collapse show">
        <a href="<?php echo $base; ?>dashboard.php" class="<?php echo ($page_title=='Dashboard')?'active':''; ?>">🏠 Dashboard</a>

        <div class="nav-section">Services</div>
        <a href="<?php echo $base; ?>modules/printan/scan.php" class="<?php echo ($page_title=='Printan ng Bayan')?'active':''; ?>">🖨️ Printan ng Bayan</a>
        <a href="<?php echo $base; ?>modules/iskolend/borrow.php" class="<?php echo in_array($page_title, ['ISKOLEND', 'ISKOLEND Inventory'], true)?'active':''; ?>">📦 ISKOLEND</a>
        <a href="<?php echo $base; ?>modules/attendance/scan.php" class="<?php echo ($page_title=='Attendance')?'active':''; ?>">🕒 Officer Attendance</a>

        <div class="nav-section">Reports</div>
        <a href="<?php echo $base; ?>modules/analytics/dashboard.php" class="<?php echo ($page_title=='Analytics')?'active':''; ?>">📊 Analytics & Reports</a>

        <div class="nav-section">PIN Protected</div>
        <a href="<?php echo $base; ?>modules/officers/list.php" class="<?php echo ($page_title=='Officers')?'active':''; ?>">🔒 Officers</a>
        <a href="<?php echo $base; ?>modules/students/list.php" class="<?php echo ($page_title=='Students')?'active':''; ?>">🔒 Students</a>

        <div class="nav-section"></div>
        <a href="<?php echo $base; ?>logout.php" id="logoutLink">🚪 Logout</a>
    </nav>
</aside>
<main class="content">
    <div class="topbar">
        <h1><?php echo htmlspecialchars($page_title); ?></h1>
        <div class="topbar-actions">
            <div class="user-chip">👤 <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Officer'); ?></div>
        </div>
    </div>
