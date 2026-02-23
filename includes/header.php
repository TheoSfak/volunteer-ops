<?php
/**
 * VolunteerOps - Header Template
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

$currentUser = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Get app settings for header
$appName = getSetting('app_name', 'VolunteerOps');
$appLogo = getSetting('app_logo', '');
$appDescription = getSetting('app_description', '');
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle ?? $appName) ?> - <?= h($appName) ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Flatpickr for date/time pickers -->
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <!-- Sortable.js for drag and drop -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.1/Sortable.min.js"></script>
    
    <style>
        :root {
            --sidebar-width: 260px;
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --sidebar-gradient: linear-gradient(180deg, #1e3c72 0%, #2a5298 50%, #1e3c72 100%);
            --accent-color: #667eea;
            --accent-hover: #5a6fd6;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --card-shadow-hover: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
        }
        
        /* Animated Gradient Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-gradient);
            padding-top: 0;
            z-index: 1000;
            overflow-y: auto;
            box-shadow: 4px 0 15px rgba(0, 0, 0, 0.15);
        }
        
        .sidebar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Ccircle cx='30' cy='30' r='2'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            pointer-events: none;
        }
        
        .sidebar .nav-link {
            color: rgba(255,255,255,0.85);
            padding: 0.85rem 1.5rem;
            border-radius: 0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-weight: 500;
            font-size: 0.9rem;
            position: relative;
            overflow: hidden;
        }
        
        .sidebar .nav-link::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 0;
            background: linear-gradient(90deg, rgba(255,255,255,0.15) 0%, transparent 100%);
            transition: width 0.3s ease;
        }
        
        .sidebar .nav-link:hover {
            color: #fff;
            background: rgba(255,255,255,0.1);
            transform: translateX(5px);
        }
        
        .sidebar .nav-link:hover::before {
            width: 100%;
        }
        
        .sidebar .nav-link.active {
            color: #fff;
            background: linear-gradient(90deg, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0.05) 100%);
            border-left: 4px solid #fbbf24;
            box-shadow: inset 0 0 20px rgba(255,255,255,0.1);
        }
        
        .sidebar .nav-link i {
            width: 28px;
            margin-right: 0.75rem;
            font-size: 1.1rem;
        }
        
        .sidebar-brand {
            color: #fff;
            font-size: 1.4rem;
            font-weight: 700;
            padding: 1.5rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: rgba(0,0,0,0.2);
            margin-bottom: 0.5rem;
            letter-spacing: -0.5px;
        }
        
        .sidebar-brand:hover {
            color: #fff;
            background: rgba(0,0,0,0.25);
        }
        
        .sidebar-section {
            color: rgba(255,255,255,0.5);
            font-size: 0.7rem;
            text-transform: uppercase;
            padding: 1.25rem 1.5rem 0.5rem;
            letter-spacing: 1.5px;
            font-weight: 600;
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .content-wrapper {
            flex: 1;
            padding: 2rem;
        }
        
        /* Glassmorphism Top Navbar */
        .top-navbar {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.3);
            position: sticky;
            top: 0;
            z-index: 1020;
        }
        
        /* User Dropdown Styling */
        .top-navbar .dropdown {
            position: relative;
        }
        
        .top-navbar .dropdown-menu {
            position: absolute !important;
            right: 0 !important;
            left: auto !important;
            top: 100% !important;
            transform: none !important;
            z-index: 1050 !important;
            min-width: 220px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-radius: 8px;
            border: none;
            margin-top: 0.5rem;
        }
        
        .top-navbar .dropdown-toggle {
            font-weight: 500;
        }
        
        .top-navbar .dropdown-item {
            padding: 0.6rem 1rem;
        }
        
        .top-navbar .dropdown-item:hover {
            background-color: #f8f9fa;
        }
        
        /* Modern Cards */
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }
        
        .card:hover {
            box-shadow: var(--card-shadow-hover);
            transform: translateY(-2px);
        }
        
        .card-header {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 1px solid #e2e8f0;
            font-weight: 600;
            padding: 1rem 1.25rem;
        }
        
        /* Stats Cards with Gradients */
        .stats-card {
            border-left: none !important;
            border-radius: 16px;
            position: relative;
            overflow: hidden;
        }
        
        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 6px;
            height: 100%;
            border-radius: 16px 0 0 16px;
        }
        
        .stats-card.primary::before { background: linear-gradient(180deg, #667eea 0%, #764ba2 100%); }
        .stats-card.success::before { background: linear-gradient(180deg, #10b981 0%, #059669 100%); }
        .stats-card.warning::before { background: linear-gradient(180deg, #f59e0b 0%, #d97706 100%); }
        .stats-card.danger::before { background: linear-gradient(180deg, #ef4444 0%, #dc2626 100%); }
        
        .stats-card .stats-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #fff;
        }
        
        .stats-card.primary .stats-icon { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stats-card.success .stats-icon { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .stats-card.warning .stats-icon { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .stats-card.danger .stats-icon { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        
        /* Modern Buttons */
        .btn {
            border-radius: 10px;
            font-weight: 500;
            padding: 0.6rem 1.25rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd6 0%, #6a4190 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }
        
        .btn-success:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border: none;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border: none;
            color: #fff;
        }
        
        .btn-outline-primary {
            border: 2px solid var(--accent-color);
            color: var(--accent-color);
        }
        
        .btn-outline-primary:hover {
            background: var(--accent-color);
            border-color: var(--accent-color);
            transform: translateY(-2px);
        }
        
        /* Modern Badges */
        .badge {
            font-weight: 500;
            padding: 0.5em 0.85em;
            border-radius: 8px;
            font-size: 0.75rem;
            letter-spacing: 0.3px;
        }
        
        .badge.bg-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important; }
        .badge.bg-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important; color: #fff !important; }
        .badge.bg-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important; }
        .badge.bg-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important; }
        .badge.bg-info { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%) !important; }
        .badge.bg-secondary { background: linear-gradient(135deg, #64748b 0%, #475569 100%) !important; }
        
        /* Modern Tables */
        .table {
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .table thead th {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            padding: 1rem;
        }
        
        .table tbody tr {
            transition: all 0.2s ease;
        }
        
        .table tbody tr:hover {
            background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
        }
        
        .table td {
            padding: 1rem;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }
        
        /* Modern Form Controls */
        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid #e2e8f0;
            padding: 0.7rem 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.15);
        }
        
        .form-label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        
        /* Modern Alerts */
        .alert {
            border: none;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            box-shadow: var(--card-shadow);
        }
        
        .alert-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(5, 150, 105, 0.1) 100%);
            color: #047857;
            border-left: 4px solid #10b981;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(220, 38, 38, 0.1) 100%);
            color: #b91c1c;
            border-left: 4px solid #ef4444;
        }
        
        .alert-warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(217, 119, 6, 0.1) 100%);
            color: #92400e;
            border-left: 4px solid #f59e0b;
        }
        
        .alert-info {
            background: linear-gradient(135deg, rgba(6, 182, 212, 0.1) 0%, rgba(8, 145, 178, 0.1) 100%);
            color: #0e7490;
            border-left: 4px solid #06b6d4;
        }
        
        /* Page Headings */
        h1, .h1, h2, .h2, h3, .h3 {
            font-weight: 700;
            color: #1e293b;
            letter-spacing: -0.5px;
        }
        
        /* Breadcrumb */
        .breadcrumb {
            background: transparent;
            padding: 0;
            margin: 0;
        }
        
        .breadcrumb-item a {
            color: var(--accent-color);
            text-decoration: none;
        }
        
        .breadcrumb-item.active {
            color: #64748b;
        }
        
        /* Avatar */
        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 600;
            font-size: 1rem;
        }
        
        /* Dropdown */
        .dropdown-menu {
            border: none;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            padding: 0.5rem;
        }
        
        .dropdown-item {
            border-radius: 8px;
            padding: 0.6rem 1rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .dropdown-item:hover {
            background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
        }
        
        /* Progress bars */
        .progress {
            border-radius: 10px;
            height: 10px;
            background: #e2e8f0;
        }
        
        .progress-bar {
            border-radius: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .card, .alert, .stats-card {
            animation: fadeInUp 0.4s ease-out;
        }
        
        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #5a6fd6 0%, #6a4190 100%);
        }
        
        /* Mobile sidebar toggle */
        .sidebar-toggle {
            display: none;
        }
        
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .sidebar-toggle {
                display: block;
            }
            
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 999;
            }
            
            .sidebar-overlay.show {
                display: block;
            }
            
            .main-content {
                overflow-x: hidden;
                max-width: 100vw;
            }
            
            .top-navbar {
                padding: 0.6rem 0.8rem;
            }
            
            .top-navbar .dropdown-toggle {
                max-width: 75vw;
                overflow: visible;
                display: inline-flex;
                align-items: center;
                flex-wrap: wrap;
                font-size: 0.85rem;
                padding: 0.25rem 0.4rem;
            }

            .top-navbar .dropdown-toggle .user-name-text {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                max-width: 55vw;
            }

            .user-type-badge-mobile {
                display: block;
                width: 100%;
                margin-top: 2px;
                font-size: 0.7rem;
            }
            
            
            .content-wrapper {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <a href="dashboard.php" class="sidebar-brand">
            <i class="bi bi-heart-pulse"></i>
            <div style="flex: 1;">
                <div style="font-size: 1.4rem; font-weight: 700;"><?= h($appName) ?></div>
                <?php if (!empty($appDescription)): ?>
                    <div style="font-size: 0.75rem; font-weight: 400; opacity: 0.85; margin-top: 0.25rem; line-height: 1.3;"><?= h($appDescription) ?></div>
                <?php endif; ?>
            </div>
        </a>
        
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>" href="dashboard.php">
                    <i class="bi bi-speedometer2"></i> Πίνακας Ελέγχου
                </a>
            </li>
            <?php if (isAdmin() || hasRole(ROLE_SHIFT_LEADER)): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'ops-dashboard' ? 'active' : '' ?>" href="ops-dashboard.php">
                    <i class="bi bi-broadcast text-danger"></i> Επιχειρησιακό
                </a>
            </li>
            <?php endif; ?>
            
            <div class="sidebar-section">Αποστολές</div>
            
            <li class="nav-item">
                <a class="nav-link <?= ($currentPage === 'missions' && get('status', 'OPEN') === 'OPEN') ? 'active' : '' ?>" href="missions.php?status=OPEN">
                    <i class="bi bi-flag-fill text-success"></i> Ενεργές Αποστολές
                </a>
            </li>
            <?php if (isAdmin()): ?>
            <li class="nav-item">
                <a class="nav-link <?= ($currentPage === 'missions' && get('status') === 'CLOSED') ? 'active' : '' ?>" href="missions.php?status=CLOSED">
                    <i class="bi bi-flag text-warning"></i> Κλειστές Αποστολές
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= ($currentPage === 'missions' && get('status') === 'COMPLETED') ? 'active' : '' ?>" href="missions.php?status=COMPLETED">
                    <i class="bi bi-flag-fill text-primary"></i> Ολοκληρωμένες Αποστολές
                </a>
            </li>
            <?php endif; ?>
            
            <div class="sidebar-section">Διαχείριση</div>
            
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'tasks' ? 'active' : '' ?>" href="tasks.php">
                    <i class="bi bi-list-task"></i> Εργασίες
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'participations' ? 'active' : '' ?>" href="participations.php">
                    <i class="bi bi-person-check"></i> Συμμετοχές
                </a>
            </li>
            
            <div class="sidebar-section">Εκπαίδευση</div>
            
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'training' ? 'active' : '' ?>" href="training.php">
                    <i class="bi bi-book"></i> Μαθήματα
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'training-materials' ? 'active' : '' ?>" href="training-materials.php">
                    <i class="bi bi-file-earmark-pdf"></i> Εκπαιδευτικό Υλικό
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'training-quizzes' ? 'active' : '' ?>" href="training-quizzes.php">
                    <i class="bi bi-puzzle"></i> Κουίζ
                </a>
            </li>
            <?php if (isAdmin() || isTraineeRescuer()): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'training-exams' ? 'active' : '' ?>" href="training-exams.php">
                    <i class="bi bi-award"></i> Διαγωνίσματα
                </a>
            </li>
            <?php endif; ?>
            
            <?php if (isAdmin()): ?>
            <div class="sidebar-section">Διαχείριση Εκπαίδευσης</div>
            
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'training-admin' ? 'active' : '' ?>" href="training-admin.php">
                    <i class="bi bi-gear"></i> Κατηγορίες & Υλικό
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'exam-admin' ? 'active' : '' ?>" href="exam-admin.php">
                    <i class="bi bi-file-earmark-text"></i> Διαγωνίσματα & Κουίζ
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'exam-form' ? 'active' : '' ?>" href="exam-form.php">
                    <i class="bi bi-plus-circle"></i> Νέο Διαγώνισμα
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'quiz-form' ? 'active' : '' ?>" href="quiz-form.php">
                    <i class="bi bi-plus-circle"></i> Νέο Κουίζ
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'exam-statistics' ? 'active' : '' ?>" href="exam-statistics.php">
                    <i class="bi bi-bar-chart-line"></i> Στατιστικά Εξετάσεων
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= in_array($currentPage, ['questions-pool', 'exam-questions-admin', 'quiz-questions-admin']) ? 'active' : '' ?>" href="questions-pool.php">
                    <i class="bi bi-question-circle"></i> Διαχείριση Ερωτήσεων
                </a>
            </li>
            <?php endif; ?>
            
            <?php if (isAdmin()): ?>
            <div class="sidebar-section">Διοίκηση</div>
            
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'volunteers' ? 'active' : '' ?>" href="volunteers.php">
                    <i class="bi bi-people"></i> Εθελοντές
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inactive-volunteers' ? 'active' : '' ?>" href="inactive-volunteers.php">
                    <i class="bi bi-person-x"></i> Ανενεργοί Εθελοντές
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'departments' ? 'active' : '' ?>" href="departments.php">
                    <i class="bi bi-shield"></i> Σώματα
                </a>
            </li>
            <?php endif; ?>
            <?php if (isSystemAdmin()): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'branches' ? 'active' : '' ?>" href="branches.php">
                    <i class="bi bi-geo-alt-fill"></i> Παραρτήματα
                </a>
            </li>
            <?php endif; ?>
            <?php if (isAdmin()): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'volunteer-positions' ? 'active' : '' ?>" href="volunteer-positions.php">
                    <i class="bi bi-person-badge"></i> Θέσεις Εθελοντών
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'mission-types' ? 'active' : '' ?>" href="mission-types.php">
                    <i class="bi bi-tags"></i> Τύποι Αποστολών
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'skills' ? 'active' : '' ?>" href="skills.php">
                    <i class="bi bi-stars"></i> Δεξιότητες
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'certificates' ? 'active' : '' ?>" href="certificates.php">
                    <i class="bi bi-award"></i> Πιστοποιητικά
                </a>
            </li>
            <?php if (isSystemAdmin()): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'certificate-types' ? 'active' : '' ?>" href="certificate-types.php">
                    <i class="bi bi-award-fill"></i> Τύποι Πιστοποιητικών
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'reports' ? 'active' : '' ?>" href="reports.php">
                    <i class="bi bi-graph-up"></i> Αναφορές
                </a>
            </li>
            <?php endif; ?>
            
            <?php if (!isTraineeRescuer()): ?>
            <div class="sidebar-section">Απόθεμα</div>
            
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inventory' ? 'active' : '' ?>" href="inventory.php">
                    <i class="bi bi-box-seam"></i> Υλικά
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inventory-kits' ? 'active' : '' ?>" href="inventory-kits.php">
                    <i class="bi bi-briefcase"></i> Σετ Εξοπλισμού
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inventory-shelf' ? 'active' : '' ?>" href="inventory-shelf.php">
                    <i class="bi bi-grid-3x3"></i> Υλικά Ραφιού
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inventory-book' ? 'active' : '' ?>" href="inventory-book.php">
                    <i class="bi bi-upc-scan"></i> Χρέωση / Επιστροφή
                </a>
            </li>
            <?php if (isAdmin()): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inventory-notes' ? 'active' : '' ?>" href="inventory-notes.php">
                    <i class="bi bi-sticky"></i> Σημειώσεις Υλικών
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inventory-categories' ? 'active' : '' ?>" href="inventory-categories.php">
                    <i class="bi bi-tags"></i> Κατηγορίες Υλικών
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inventory-locations' ? 'active' : '' ?>" href="inventory-locations.php">
                    <i class="bi bi-geo-alt"></i> Τοποθεσίες
                </a>
            </li>
            <?php endif; ?>
            <?php if (isSystemAdmin()): ?>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'inventory-warehouses' ? 'active' : '' ?>" href="inventory-warehouses.php">
                    <i class="bi bi-building"></i> Αποθήκες
                </a>
            </li>
            <?php endif; ?>
            <!-- Παραρτήματα: accessible via Διοίκηση → Παραρτήματα -->
            <?php endif; // !isTraineeRescuer ?>
            
            <div class="sidebar-section">Gamification</div>
            
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'leaderboard' ? 'active' : '' ?>" href="leaderboard.php">
                    <i class="bi bi-trophy"></i> Κατάταξη
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'achievements' ? 'active' : '' ?>" href="achievements.php">
                    <i class="bi bi-award"></i> Επιτεύγματα
                </a>
            </li>
            
            <?php if (isSystemAdmin()): ?>
            <div class="sidebar-section">Επικοινωνία</div>
            
            <li class="nav-item">
                <a class="nav-link <?= in_array($currentPage, ['newsletters', 'newsletter-form', 'newsletter-view', 'newsletter-log']) ? 'active' : '' ?>" href="newsletters.php">
                    <i class="bi bi-envelope-paper"></i> Ενημερωτικά
                </a>
            </li>
            
            <div class="sidebar-section">Σύστημα</div>
            
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'audit' ? 'active' : '' ?>" href="audit.php">
                    <i class="bi bi-journal-text"></i> Αρχείο Καταγραφής
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'settings' ? 'active' : '' ?>" href="settings.php">
                    <i class="bi bi-gear"></i> Ρυθμίσεις
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navbar -->
        <nav class="top-navbar d-flex justify-content-between align-items-center">
            <button class="btn btn-link sidebar-toggle p-0" onclick="toggleSidebar()">
                <i class="bi bi-list fs-4"></i>
            </button>
            
            <div class="d-flex align-items-center ms-auto">
                <div class="dropdown">
                    <button class="btn btn-link text-dark dropdown-toggle text-decoration-none" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($currentUser['profile_photo']) && file_exists(__DIR__ . '/../uploads/avatars/' . $currentUser['profile_photo'])): ?>
                            <img src="<?= BASE_URL ?>/uploads/avatars/<?= h($currentUser['profile_photo']) ?>" class="rounded-circle me-1 flex-shrink-0" style="width:30px;height:30px;object-fit:cover;" alt="">
                        <?php else: ?>
                            <i class="bi bi-person-circle me-1 flex-shrink-0"></i>
                        <?php endif; ?>
                        <span class="user-name-text"><?= h($currentUser['name'] ?? 'Χρήστης') ?></span>
                        <span class="user-type-badge d-none d-md-inline"><?= volunteerTypeBadge($currentUser['volunteer_type'] ?? VTYPE_VOLUNTEER) ?></span>
                        <span class="user-type-badge-mobile d-md-none"><?= volunteerTypeBadge($currentUser['volunteer_type'] ?? VTYPE_VOLUNTEER) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown" style="right: 0; left: auto;">
                        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>Το Προφίλ μου</a></li>
                        <li><a class="dropdown-item" href="my-participations.php"><i class="bi bi-list-check me-2"></i>Οι Αιτήσεις μου</a></li>
                        <li><a class="dropdown-item" href="my-points.php"><i class="bi bi-star me-2"></i>Οι Πόντοι μου</a></li>
                        <li><a class="dropdown-item" href="notification-preferences.php"><i class="bi bi-bell me-2"></i>Ρυθμίσεις Ειδοποιήσεων</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form action="logout.php" method="post" class="d-inline">
                                <?= csrfField() ?>
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="bi bi-box-arrow-right me-2"></i>Αποσύνδεση
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
        
        <!-- Page Content -->
        <div class="content-wrapper">
            <?php if (!empty($appLogo) && file_exists(__DIR__ . '/../uploads/logos/' . $appLogo)): ?>
                <div class="text-center mb-4" style="padding: 2rem 0;">
                    <img src="uploads/logos/<?= h($appLogo) ?>" alt="<?= h($appName) ?>" style="max-width: 200px; max-height: 150px; width: auto; height: auto; border-radius: 12px; box-shadow: 0 8px 16px rgba(0,0,0,0.15);">
                </div>
            <?php endif; ?>
            <?php displayFlash(); ?>
