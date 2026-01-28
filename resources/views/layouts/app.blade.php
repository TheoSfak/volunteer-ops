@php
    $appName = \App\Models\Setting::get('app_name', 'VolunteerOps');
@endphp
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', $appName) - Διαχείριση Εθελοντών</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Flatpickr Datepicker -->
    <link rel="stylesheet" href="/volunteer-ops/public/css/flatpickr.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #4f46e5;
            --primary-light: #818cf8;
            --primary-dark: #3730a3;
            --secondary-color: #64748b;
            --success-color: #10b981;
            --success-light: #d1fae5;
            --warning-color: #f59e0b;
            --warning-light: #fef3c7;
            --danger-color: #ef4444;
            --danger-light: #fee2e2;
            --info-color: #0ea5e9;
            --info-light: #e0f2fe;
            --sidebar-width: 280px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }
        
        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f0f4ff 0%, #e8eeff 50%, #f0f9ff 100%);
            min-height: 100vh;
        }
        
        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, #312e81 0%, #3730a3 50%, #4338ca 100%);
            padding: 0;
            z-index: 1000;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
        }
        
        .sidebar-brand {
            padding: 1.75rem 1.5rem;
            background: rgba(0,0,0,0.15);
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-brand h4 {
            color: white;
            margin: 0;
            font-weight: 700;
            font-size: 1.35rem;
            display: flex;
            align-items: center;
        }
        
        .sidebar-brand h4 i {
            color: #f472b6;
            margin-right: 0.5rem;
        }
        
        .sidebar-brand small {
            color: rgba(255,255,255,0.6);
            font-size: 0.75rem;
            letter-spacing: 0.5px;
        }
        
        .sidebar-nav {
            padding: 1rem 0;
        }
        
        .nav-section {
            padding: 0.75rem 1.5rem 0.5rem;
            color: rgba(255,255,255,0.4);
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 600;
            margin-top: 0.75rem;
        }
        
        .sidebar-nav .nav-link {
            color: rgba(255,255,255,0.75);
            padding: 0.875rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.875rem;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            border-left: 3px solid transparent;
            margin: 2px 0;
            font-size: 0.925rem;
        }
        
        .sidebar-nav .nav-link:hover {
            background: rgba(255,255,255,0.1);
            color: white;
            padding-left: 1.75rem;
        }
        
        .sidebar-nav .nav-link.active {
            background: linear-gradient(90deg, rgba(129,140,248,0.25) 0%, rgba(129,140,248,0.1) 100%);
            color: white;
            border-left-color: #a5b4fc;
            font-weight: 500;
        }
        
        .sidebar-nav .nav-link i {
            font-size: 1.15rem;
            width: 24px;
            text-align: center;
            opacity: 0.85;
        }
        
        .sidebar-nav .nav-link.active i {
            opacity: 1;
        }
        
        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }
        
        /* Top Navbar */
        .top-navbar {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            padding: 1rem 2rem;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        .page-title {
            font-size: 1.35rem;
            font-weight: 700;
            background: linear-gradient(135deg, #1e293b 0%, #475569 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0;
        }
        
        .user-dropdown .dropdown-toggle {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: #475569;
            padding: 0.5rem 0.75rem;
            border-radius: 12px;
            transition: all 0.2s;
        }
        
        .user-dropdown .dropdown-toggle:hover {
            background: #f1f5f9;
        }
        
        .user-dropdown .dropdown-toggle::after {
            display: none;
        }
        
        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.1rem;
            box-shadow: var(--shadow-md);
        }
        
        /* Content Area */
        .content-area {
            padding: 2rem;
        }
        
        /* Cards */
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: var(--shadow);
            background: white;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }
        
        .card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }
        
        .card-header {
            background: linear-gradient(135deg, #fafbff 0%, #f8fafc 100%);
            border-bottom: 1px solid #e2e8f0;
            padding: 1.25rem 1.5rem;
            font-weight: 600;
            color: #1e293b;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Stats Cards */
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.75rem;
            box-shadow: var(--shadow);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--primary-light));
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-xl);
        }
        
        .stat-card .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: transform 0.3s;
        }
        
        .stat-card:hover .stat-icon {
            transform: scale(1.1);
        }
        
        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, #1e293b 0%, #475569 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-card .stat-label {
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        /* Tables */
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            font-weight: 600;
            color: #475569;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.75px;
            border-bottom: 2px solid #e2e8f0;
            padding: 1rem 1.25rem;
        }
        
        .table td {
            vertical-align: middle;
            color: #334155;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .table tbody tr {
            transition: all 0.2s;
        }
        
        .table tbody tr:hover {
            background: linear-gradient(135deg, #fafbff 0%, #f8fafc 100%);
        }
        
        .table-hover tbody tr:hover td {
            background: transparent;
        }
        
        /* Badges */
        .badge {
            font-weight: 500;
            letter-spacing: 0.25px;
        }
        
        .badge-status {
            padding: 0.5rem 0.875rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-draft { 
            background: linear-gradient(135deg, #f1f5f9, #e2e8f0); 
            color: #475569; 
        }
        .badge-published { 
            background: linear-gradient(135deg, #dbeafe, #bfdbfe); 
            color: #1d4ed8; 
        }
        .badge-active { 
            background: linear-gradient(135deg, #d1fae5, #a7f3d0); 
            color: #059669; 
        }
        .badge-completed { 
            background: linear-gradient(135deg, #e0e7ff, #c7d2fe); 
            color: #4338ca; 
        }
        .badge-cancelled { 
            background: linear-gradient(135deg, #fee2e2, #fecaca); 
            color: #dc2626; 
        }
        
        /* Buttons */
        .btn {
            border-radius: 10px;
            font-weight: 500;
            padding: 0.625rem 1.25rem;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            border: none;
            box-shadow: 0 4px 14px 0 rgba(79, 70, 229, 0.35);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px 0 rgba(79, 70, 229, 0.45);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success-color) 0%, #059669 100%);
            border: none;
            box-shadow: 0 4px 14px 0 rgba(16, 185, 129, 0.35);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px 0 rgba(16, 185, 129, 0.45);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color) 0%, #dc2626 100%);
            border: none;
            box-shadow: 0 4px 14px 0 rgba(239, 68, 68, 0.35);
        }
        
        .btn-outline-primary {
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-color);
            border-color: var(--primary-color);
            transform: translateY(-2px);
        }
        
        .btn-sm {
            padding: 0.4rem 0.875rem;
            font-size: 0.85rem;
            border-radius: 8px;
        }
        
        .btn-group .btn {
            border-radius: 8px;
        }
        
        .btn-group .btn:first-child {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        
        .btn-group .btn:last-child {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }
        
        /* Forms */
        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid #e2e8f0;
            padding: 0.75rem 1rem;
            transition: all 0.25s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }
        
        .form-label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        
        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        /* Alerts */
        .alert {
            border: none;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            font-weight: 500;
            box-shadow: var(--shadow-sm);
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
        }
        
        .alert-warning {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #92400e;
        }
        
        .alert-info {
            background: linear-gradient(135deg, #e0f2fe, #bae6fd);
            color: #075985;
        }
        
        /* Dropdowns */
        .dropdown-menu {
            border: none;
            border-radius: 12px;
            box-shadow: var(--shadow-xl);
            padding: 0.5rem;
            animation: dropdownFade 0.2s ease;
        }
        
        @keyframes dropdownFade {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .dropdown-item {
            border-radius: 8px;
            padding: 0.625rem 1rem;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .dropdown-item:hover {
            background: #f1f5f9;
        }
        
        .dropdown-item.text-danger:hover {
            background: #fee2e2;
        }
        
        /* Progress Bars */
        .progress {
            height: 8px;
            border-radius: 50px;
            background: #e2e8f0;
            overflow: hidden;
        }
        
        .progress-bar {
            border-radius: 50px;
            background: linear-gradient(90deg, var(--primary-color), var(--primary-light));
        }
        
        /* Modal */
        .modal-content {
            border: none;
            border-radius: 20px;
            box-shadow: var(--shadow-xl);
        }
        
        .modal-header {
            border-bottom: 1px solid #f1f5f9;
            padding: 1.5rem;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            border-top: 1px solid #f1f5f9;
            padding: 1.25rem 1.5rem;
        }
        
        /* Pagination */
        .pagination {
            gap: 0.25rem;
        }
        
        .page-link {
            border: none;
            border-radius: 8px !important;
            padding: 0.5rem 0.875rem;
            color: #475569;
            font-weight: 500;
        }
        
        .page-link:hover {
            background: #f1f5f9;
            color: var(--primary-color);
        }
        
        .page-item.active .page-link {
            background: var(--primary-color);
            color: white;
        }
        
        /* Tabs */
        .nav-tabs {
            border: none;
            gap: 0.5rem;
        }
        
        .nav-tabs .nav-link {
            border: none;
            border-radius: 10px;
            padding: 0.75rem 1.25rem;
            font-weight: 500;
            color: #64748b;
            transition: all 0.25s;
        }
        
        .nav-tabs .nav-link:hover {
            background: #f1f5f9;
            color: var(--primary-color);
        }
        
        .nav-tabs .nav-link.active {
            background: var(--primary-color);
            color: white;
        }
        
        /* Notification Bell Animation */
        .btn-link .bi-bell {
            transition: transform 0.3s;
        }
        
        .btn-link:hover .bi-bell {
            animation: bellRing 0.5s ease;
        }
        
        @keyframes bellRing {
            0%, 100% { transform: rotate(0); }
            25% { transform: rotate(15deg); }
            50% { transform: rotate(-15deg); }
            75% { transform: rotate(10deg); }
        }
        
        /* Loading Animation */
        .skeleton {
            background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
            background-size: 200% 100%;
            animation: skeleton-loading 1.5s infinite;
            border-radius: 8px;
        }
        
        @keyframes skeleton-loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        /* Achievement/Badge Glow Effects */
        .badge-achievement {
            position: relative;
        }
        
        .badge-achievement.earned::after {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: inherit;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            z-index: -1;
            animation: glow 2s ease-in-out infinite;
        }
        
        @keyframes glow {
            0%, 100% { opacity: 0.5; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.05); }
        }
        
        /* Floating Animation for Icons */
        .float-animation {
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        /* Responsive - Mobile First */
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                z-index: 1050;
                box-shadow: 4px 0 20px rgba(0, 0, 0, 0.3);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .content-area {
                padding: 0.75rem;
            }
            
            /* Mobile overlay when sidebar is open */
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 1040;
                backdrop-filter: blur(2px);
            }
            
            .sidebar-overlay.show {
                display: block;
            }
            
            /* Better touch targets */
            .btn {
                min-height: 44px;
                min-width: 44px;
            }
            
            .nav-link {
                padding: 0.75rem 1rem !important;
            }
            
            /* Stack cards on mobile */
            .stat-card {
                margin-bottom: 0.75rem;
            }
            
            /* Better form controls for touch */
            .form-control, .form-select {
                min-height: 44px;
                font-size: 16px; /* Prevents iOS zoom on focus */
            }
            
            /* Page title smaller on mobile */
            .page-title {
                font-size: 1.1rem;
            }
            
            /* Full width buttons on mobile */
            .btn-group-mobile {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .btn-group-mobile .btn {
                width: 100%;
            }
            
            /* Hide some table columns on mobile */
            .hide-mobile {
                display: none !important;
            }
            
            /* Better spacing for cards */
            .card-body {
                padding: 1rem;
            }
            
            /* Sidebar close button on mobile */
            .sidebar-close {
                display: block;
                position: absolute;
                top: 1rem;
                right: 1rem;
                background: rgba(255, 255, 255, 0.1);
                border: none;
                color: white;
                width: 36px;
                height: 36px;
                border-radius: 50%;
                cursor: pointer;
            }
        }
        
        /* Tablet adjustments */
        @media (min-width: 576px) and (max-width: 991.98px) {
            .content-area {
                padding: 1.25rem;
            }
        }
        
        /* Hide sidebar close on desktop */
        @media (min-width: 992px) {
            .sidebar-close {
                display: none;
            }
        }
        
        /* Print Styles */
        @media print {
            .sidebar, .top-navbar {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
            }
        }
    </style>
    @stack('styles')
</head>
<body>
    <!-- Mobile Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar -->
    <aside class="sidebar">
        <button class="sidebar-close" id="sidebarClose">
            <i class="bi bi-x-lg"></i>
        </button>
        <div class="sidebar-brand">
            <h4><i class="bi bi-heart-pulse me-2"></i>{{ $appName }}</h4>
            <small>Διαχείριση Εθελοντών</small>
        </div>
        
        <nav class="sidebar-nav">
            <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i>
                <span>Πίνακας Ελέγχου</span>
            </a>
            
            <div class="nav-section">Διαχείριση</div>
            
            <a href="{{ route('missions.index') }}" class="nav-link {{ request()->routeIs('missions.*') || request()->routeIs('shifts.*') ? 'active' : '' }}">
                <i class="bi bi-flag"></i>
                <span>Αποστολές</span>
            </a>
            
            <a href="{{ route('participations.index') }}" class="nav-link {{ request()->routeIs('participations.*') ? 'active' : '' }}">
                <i class="bi bi-person-check"></i>
                <span>Συμμετοχές</span>
            </a>
            
            <div class="nav-section">Οργανισμός</div>
            
            <a href="{{ route('volunteers.index') }}" class="nav-link {{ request()->routeIs('volunteers.*') ? 'active' : '' }}">
                <i class="bi bi-people"></i>
                <span>Εθελοντές</span>
            </a>
            
            <a href="{{ route('departments.index') }}" class="nav-link {{ request()->routeIs('departments.*') ? 'active' : '' }}">
                <i class="bi bi-building"></i>
                <span>Τμήματα</span>
            </a>
            
            <div class="nav-section">Σύστημα</div>
            
            <a href="{{ route('documents.index') }}" class="nav-link {{ request()->routeIs('documents.*') ? 'active' : '' }}">
                <i class="bi bi-file-earmark-text"></i>
                <span>Έγγραφα</span>
            </a>
            
            <a href="{{ route('audit.index') }}" class="nav-link {{ request()->routeIs('audit.*') ? 'active' : '' }}">
                <i class="bi bi-journal-text"></i>
                <span>Αρχείο Καταγραφών</span>
            </a>
            
            <a href="{{ route('reports.index') }}" class="nav-link {{ request()->routeIs('reports.*') ? 'active' : '' }}">
                <i class="bi bi-graph-up"></i>
                <span>Αναφορές</span>
            </a>
            
            <div class="nav-section">Επιβράβευση</div>
            
            <a href="{{ route('gamification.leaderboard') }}" class="nav-link {{ request()->routeIs('gamification.leaderboard') ? 'active' : '' }}">
                <i class="bi bi-trophy"></i>
                <span>Κατάταξη</span>
            </a>
            
            <a href="{{ route('gamification.achievements') }}" class="nav-link {{ request()->routeIs('gamification.achievements') ? 'active' : '' }}">
                <i class="bi bi-award"></i>
                <span>Επιτεύγματα</span>
            </a>
            
            <a href="{{ route('gamification.points-history') }}" class="nav-link {{ request()->routeIs('gamification.points-history') ? 'active' : '' }}">
                <i class="bi bi-coin"></i>
                <span>Πόντοι</span>
            </a>
            
            @if(Auth::user()->hasRole(\App\Models\User::ROLE_SYSTEM_ADMIN))
            <a href="{{ route('settings.index') }}" class="nav-link {{ request()->routeIs('settings.*') ? 'active' : '' }}">
                <i class="bi bi-gear"></i>
                <span>Ρυθμίσεις</span>
            </a>
            @endif
        </nav>
    </aside>
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Navbar -->
        <header class="top-navbar">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-link d-lg-none p-0" id="sidebarToggle">
                    <i class="bi bi-list fs-4"></i>
                </button>
                <h1 class="page-title">@yield('page-title', 'Πίνακας Ελέγχου')</h1>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <!-- Notifications -->
                <div class="dropdown">
                    <button class="btn btn-link position-relative" data-bs-toggle="dropdown">
                        <i class="bi bi-bell fs-5 text-secondary"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            3
                        </span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end" style="width: 300px;">
                        <h6 class="dropdown-header">Ειδοποιήσεις</h6>
                        <a class="dropdown-item py-2" href="#">
                            <small class="text-muted">Νέα αίτηση συμμετοχής</small>
                        </a>
                        <a class="dropdown-item py-2" href="#">
                            <small class="text-muted">Αποστολή ολοκληρώθηκε</small>
                        </a>
                    </div>
                </div>
                
                <!-- User Menu -->
                <div class="dropdown user-dropdown">
                    <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">
                        <div class="user-avatar">
                            {{ substr(Auth::user()->name ?? 'U', 0, 1) }}
                        </div>
                        <div class="d-none d-md-block">
                            <div class="fw-medium">{{ Auth::user()->name ?? 'Χρήστης' }}</div>
                            <small class="text-muted">{{ Auth::user()->role ?? 'Διαχειριστής' }}</small>
                        </div>
                        <i class="bi bi-chevron-down"></i>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <a class="dropdown-item" href="{{ route('profile') }}">
                            <i class="bi bi-person me-2"></i>Το Προφίλ μου
                        </a>
                        @if(Auth::user()->hasRole(\App\Models\User::ROLE_SYSTEM_ADMIN))
                        <a class="dropdown-item" href="{{ route('settings.index') }}">
                            <i class="bi bi-gear me-2"></i>Ρυθμίσεις
                        </a>
                        @endif
                        <div class="dropdown-divider"></div>
                        <form action="{{ route('logout') }}" method="POST">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger">
                                <i class="bi bi-box-arrow-right me-2"></i>Αποσύνδεση
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>
        
        <!-- Content Area -->
        <div class="content-area">
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            
            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle me-2"></i>{{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            
            @if($errors->any())
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle me-2"></i>
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            
            @yield('content')
        </div>
    </main>
    
    <!-- jQuery (required for Summernote) -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Flatpickr Datepicker -->
    <script src="/volunteer-ops/public/js/flatpickr.min.js"></script>
    <script src="/volunteer-ops/public/js/flatpickr-gr.js"></script>
    <script>
        // Initialize Flatpickr for all date inputs with Greek format
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof flatpickr !== 'undefined') {
                flatpickr('.datepicker', {
                    locale: 'gr',
                    dateFormat: 'd/m/Y',
                    allowInput: true
                });
            }
        });
    </script>
    
    <script>
        // Sidebar toggle for mobile
        const sidebar = document.querySelector('.sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        
        function openSidebar() {
            sidebar.classList.add('show');
            overlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeSidebar() {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
            document.body.style.overflow = '';
        }
        
        document.getElementById('sidebarToggle')?.addEventListener('click', openSidebar);
        document.getElementById('sidebarClose')?.addEventListener('click', closeSidebar);
        overlay?.addEventListener('click', closeSidebar);
        
        // Close sidebar when clicking a nav link on mobile
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 992) {
                    closeSidebar();
                }
            });
        });
        
        // Handle window resize
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 992) {
                closeSidebar();
            }
        });
    </script>
    
    @stack('scripts')
</body>
</html>
