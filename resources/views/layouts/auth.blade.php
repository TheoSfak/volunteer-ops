<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Σύνδεση') - VolunteerOps</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            min-height: 100vh;
            display: flex;
            background: linear-gradient(135deg, #312e81 0%, #4338ca 50%, #6366f1 100%);
            overflow-x: hidden;
        }
        
        .auth-container {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }
        
        .auth-left {
            flex: 1;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 3rem;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .auth-left::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 15s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(10%, 10%) scale(1.1); }
        }
        
        .floating-shapes {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
        }
        
        .shape {
            position: absolute;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            animation: float 20s ease-in-out infinite;
        }
        
        .shape:nth-child(1) { width: 80px; height: 80px; top: 20%; left: 10%; animation-delay: 0s; }
        .shape:nth-child(2) { width: 120px; height: 120px; top: 60%; left: 20%; animation-delay: 5s; }
        .shape:nth-child(3) { width: 60px; height: 60px; top: 40%; left: 60%; animation-delay: 10s; }
        .shape:nth-child(4) { width: 100px; height: 100px; top: 80%; left: 70%; animation-delay: 15s; }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }
        
        @media (min-width: 992px) {
            .auth-left {
                display: flex;
            }
        }
        
        .auth-left-content {
            max-width: 500px;
            position: relative;
            z-index: 1;
        }
        
        .auth-left h1 {
            font-size: 2.75rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
        }
        
        .auth-left h1 i {
            color: #f472b6;
            margin-right: 0.75rem;
            animation: heartbeat 2s ease-in-out infinite;
        }
        
        @keyframes heartbeat {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .auth-left p {
            font-size: 1.15rem;
            opacity: 0.9;
            line-height: 1.8;
        }
        
        .auth-features {
            margin-top: 2.5rem;
        }
        
        .auth-feature {
            display: flex;
            align-items: flex-start;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: rgba(255,255,255,0.1);
            border-radius: 16px;
            backdrop-filter: blur(10px);
            transition: all 0.3s;
        }
        
        .auth-feature:hover {
            background: rgba(255,255,255,0.15);
            transform: translateX(10px);
        }
        
        .auth-feature i {
            font-size: 1.75rem;
            color: #a5b4fc;
        }
        
        .auth-feature h5 {
            margin: 0;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .auth-feature p {
            margin: 0.25rem 0 0;
            font-size: 0.9rem;
            opacity: 0.8;
        }
        
        .auth-right {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 2rem;
            position: relative;
        }
        
        .auth-right::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%234338ca' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            opacity: 0.5;
        }
        
        .auth-card {
            width: 100%;
            max-width: 440px;
            background: white;
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.15);
            padding: 3rem;
            position: relative;
            z-index: 1;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .auth-logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .auth-logo-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            box-shadow: 0 10px 30px rgba(79, 70, 229, 0.3);
        }
        
        .auth-logo i {
            font-size: 2.5rem;
            color: white;
        }
        
        .auth-logo h3 {
            margin-top: 0.5rem;
            font-weight: 800;
            font-size: 1.5rem;
            background: linear-gradient(135deg, #1e293b 0%, #475569 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .auth-title {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .auth-title h4 {
            color: #1e293b;
            font-weight: 700;
            margin-bottom: 0.5rem;
            font-size: 1.35rem;
        }
        
        .auth-title p {
            color: #64748b;
            font-size: 0.95rem;
            margin: 0;
        }
        
        .form-label {
            font-weight: 600;
            color: #374151;
            font-size: 0.9rem;
        }
        
        .form-control {
            padding: 0.875rem 1rem;
            border-radius: 12px;
            border: 2px solid #e5e7eb;
            font-size: 1rem;
            transition: all 0.25s;
        }
        
        .form-control:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
        }
        
        .input-group-text {
            background: #f9fafb;
            border: 2px solid #e5e7eb;
            border-right: none;
            border-radius: 12px 0 0 12px;
            color: #6b7280;
        }
        
        .input-group .form-control {
            border-left: none;
            border-radius: 0 12px 12px 0;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
            border: none;
            padding: 0.875rem 1.5rem;
            font-weight: 600;
            border-radius: 12px;
            font-size: 1rem;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
            transition: all 0.25s;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.45);
        }
        
        .btn-primary:active {
            transform: translateY(0);
        }
        
        .auth-footer {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e5e7eb;
        }
        
        .auth-footer a {
            color: #4f46e5;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .auth-footer a:hover {
            color: #4338ca;
        }
        
        .form-check-input:checked {
            background-color: #4f46e5;
            border-color: #4f46e5;
        }
        
        .alert {
            border-radius: 12px;
            border: none;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <!-- Left Side - Branding -->
        <div class="auth-left">
            <div class="floating-shapes">
                <div class="shape"></div>
                <div class="shape"></div>
                <div class="shape"></div>
                <div class="shape"></div>
            </div>
            <div class="auth-left-content">
                <h1><i class="bi bi-heart-pulse"></i>VolunteerOps</h1>
                <p>Η ολοκληρωμένη πλατφόρμα διαχείρισης εθελοντικών αποστολών. Οργανώστε αποστολές, διαχειριστείτε βάρδιες και συντονίστε τους εθελοντές σας αποτελεσματικά.</p>
                
                <div class="auth-features">
                    <div class="auth-feature">
                        <i class="bi bi-flag-fill"></i>
                        <div>
                            <h5>Διαχείριση Αποστολών</h5>
                            <p>Δημιουργήστε και παρακολουθήστε αποστολές</p>
                        </div>
                    </div>
                    <div class="auth-feature">
                        <i class="bi bi-calendar-event-fill"></i>
                        <div>
                            <h5>Προγραμματισμός Βαρδιών</h5>
                            <p>Οργανώστε το πρόγραμμα των εθελοντών</p>
                        </div>
                    </div>
                    <div class="auth-feature">
                        <i class="bi bi-people-fill"></i>
                        <div>
                            <h5>Συντονισμός Ομάδας</h5>
                            <p>Επικοινωνία και ειδοποιήσεις σε πραγματικό χρόνο</p>
                        </div>
                    </div>
                    <div class="auth-feature">
                        <i class="bi bi-trophy-fill"></i>
                        <div>
                            <h5>Gamification</h5>
                            <p>Επιβραβεύστε τους εθελοντές με πόντους και επιτεύγματα</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Right Side - Form -->
        <div class="auth-right">
            <div class="auth-card">
                <div class="auth-logo">
                    <div class="auth-logo-icon">
                        <i class="bi bi-heart-pulse"></i>
                    </div>
                    <h3>VolunteerOps</h3>
                </div>
                
                @yield('content')
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>
</html>
