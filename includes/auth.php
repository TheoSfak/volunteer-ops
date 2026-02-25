<?php
/**
 * VolunteerOps - Authentication Functions
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Start secure session
 */
function initSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
    }
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current user data
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    static $user = null;
    if ($user === null) {
        $user = dbFetchOne("SELECT * FROM users WHERE id = ? AND is_active = 1", [$_SESSION['user_id']]);
    }
    return $user;
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Require authentication - redirect to login if not logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        setFlash('error', 'Παρακαλώ συνδεθείτε για να συνεχίσετε.');
        redirect('login.php');
    }
    
    // Check if user still exists and is active
    $user = getCurrentUser();
    if (!$user) {
        logout();
        setFlash('error', 'Η συνεδρία σας έληξε. Παρακαλώ συνδεθείτε ξανά.');
        redirect('login.php');
    }
}

/**
 * Require specific role(s)
 */
function requireRole($roles) {
    requireLogin();
    
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    
    $user = getCurrentUser();
    if (!in_array($user['role'], $roles)) {
        setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης σε αυτή τη σελίδα.');
        redirect('dashboard.php');
    }
}

/**
 * Check if current user is admin
 */
function isAdmin() {
    $user = getCurrentUser();
    return $user && in_array($user['role'], [ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);
}

/**
 * Check if current user is system admin
 */
function isSystemAdmin() {
    $user = getCurrentUser();
    return $user && $user['role'] === ROLE_SYSTEM_ADMIN;
}

/**
 * Check if user has specific role
 */
function hasRole($role) {
    $user = getCurrentUser();
    return $user && $user['role'] === $role;
}

/**
 * Login user — with brute-force protection (5 attempts / 15 minutes per IP)
 * and session fixation prevention.
 */
function login($email, $password) {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login_attempts_' . md5($ip);

    // --- Rate limiting ---
    $attempts = $_SESSION[$key] ?? ['count' => 0, 'since' => time()];

    // Reset window if older than 15 minutes
    if ((time() - $attempts['since']) > 900) {
        $attempts = ['count' => 0, 'since' => time()];
    }

    if ($attempts['count'] >= 5) {
        $wait = max(0, (int)ceil((900 - (time() - $attempts['since'])) / 60));
        return [
            'success' => false,
            'message' => "Πολλές αποτυχημένες συνδέσεις. Παρακαλώ περιμένετε $wait λεπτά και δοκιμάστε ξανά.",
        ];
    }

    // --- Lookup user ---
    $user = dbFetchOne("SELECT * FROM users WHERE email = ?", [$email]);

    if (!$user || !password_verify($password, $user['password'])) {
        $attempts['count']++;
        $_SESSION[$key] = $attempts;
        return ['success' => false, 'message' => 'Λάθος email ή κωδικός.'];
    }

    // Check email verification (skip for admins created by system)
    if (empty($user['email_verified_at']) && ($user['approval_status'] ?? 'APPROVED') !== 'APPROVED') {
        return ['success' => false, 'message' => 'Παρακαλώ επιβεβαιώστε πρώτα την ηλεκτρονική σας διεύθυνση. Ελέγξτε τα εισερχόμενά σας.'];
    }

    // Check approval status
    $approvalStatus = $user['approval_status'] ?? 'APPROVED';
    if ($approvalStatus === 'PENDING') {
        return ['success' => false, 'message' => 'Η εγγραφή σας είναι σε αναμονή έγκρισης από τον διαχειριστή. Θα ειδοποιηθείτε μόλις εγκριθεί.'];
    }
    if ($approvalStatus === 'REJECTED') {
        return ['success' => false, 'message' => 'Η αίτηση εγγραφής σας έχει απορριφθεί. Επικοινωνήστε με τον διαχειριστή για περισσότερες πληροφορίες.'];
    }

    if (!$user['is_active']) {
        return ['success' => false, 'message' => 'Ο λογαριασμός σας είναι απενεργοποιημένος.'];
    }

    // --- Success: clear rate-limit, regenerate session, set data ---
    unset($_SESSION[$key]);

    // Prevent session fixation: bind the session to the new authenticated user
    session_regenerate_id(true);
    // Regenerate CSRF token for the new session
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['login_time'] = time();

    // Update last login
    dbExecute("UPDATE users SET updated_at = NOW() WHERE id = ?", [$user['id']]);

    // Log action
    logAudit('login', 'users', $user['id'], null, ['ip' => $ip]);

    return ['success' => true, 'user' => $user];
}

/**
 * Logout user
 */
function logout() {
    if (isLoggedIn()) {
        logAudit('logout', 'users', $_SESSION['user_id']);
    }
    
    $_SESSION = [];
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

/**
 * Register new user
 */
function registerUser($data) {
    // Check if email exists
    $exists = dbFetchValue("SELECT COUNT(*) FROM users WHERE email = ?", [$data['email']]);
    if ($exists) {
        return ['success' => false, 'message' => 'Αυτό το email χρησιμοποιείται ήδη.'];
    }
    
    // Hash password
    $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
    $verificationToken = bin2hex(random_bytes(32));
    
    // Insert user — inactive until email verified and admin approved
    $userId = dbInsert(
        "INSERT INTO users (name, email, password, phone, role, department_id, is_active, approval_status, email_verification_token, created_at, updated_at) 
         VALUES (?, ?, ?, ?, ?, ?, 0, 'PENDING', ?, NOW(), NOW())",
        [
            $data['name'],
            $data['email'],
            $hashedPassword,
            $data['phone'] ?? null,
            ROLE_VOLUNTEER,
            $data['department_id'] ?? null,
            $verificationToken
        ]
    );
    
    if ($userId) {
        // Create volunteer profile
        dbInsert(
            "INSERT INTO volunteer_profiles (user_id, created_at, updated_at) VALUES (?, NOW(), NOW())",
            [$userId]
        );
        
        logAudit('register', 'users', $userId);
        
        // Build verification URL
        $appName = getSetting('app_name', 'VolunteerOps');
        $proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path    = dirname($_SERVER['SCRIPT_NAME'] ?? '/volunteerops');
        $baseUrl = getSetting('app_url', $proto . '://' . $host . rtrim($path, '/'));
        $verifyUrl = rtrim($baseUrl, '/') . '/verify-email.php?token=' . $verificationToken;
        
        // Send verification email
        $subject = 'Επιβεβαίωση Email - ' . $appName;
        $body = '
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">
  <h2 style="color:#2c3e50">Καλωσήρθατε στο ' . htmlspecialchars($appName) . '!</h2>
  <p>Γεια σας <strong>' . htmlspecialchars($data['name']) . '</strong>,</p>
  <p>Ευχαριστούμε για την εγγραφή σας. Παρακαλώ επιβεβαιώστε την ηλεκτρονική σας διεύθυνση κάνοντας κλικ στον παρακάτω σύνδεσμο:</p>
  <p style="text-align:center;margin:2rem 0">
    <a href="' . $verifyUrl . '" style="background:#27ae60;color:white;padding:14px 32px;text-decoration:none;border-radius:6px;display:inline-block;font-size:16px">
      ✉ Επιβεβαίωση Email
    </a>
  </p>
  <p>Ή αντιγράψτε αυτό το link στον browser σας:<br><small style="color:#666">' . $verifyUrl . '</small></p>
  <hr style="border:1px solid #eee;margin:1.5rem 0">
  <p style="color:#888;font-size:13px">Αν δεν ζητήσατε εγγραφή στο ' . htmlspecialchars($appName) . ', αγνοήστε αυτό το email.</p>
</div>';
        
        sendEmail($data['email'], $subject, $body);
        
        return ['success' => true, 'user_id' => $userId];
    }
    
    return ['success' => false, 'message' => 'Σφάλμα κατά την εγγραφή.'];
}

/**
 * Update user password
 */
function updatePassword($userId, $currentPassword, $newPassword) {
    $user = dbFetchOne("SELECT password FROM users WHERE id = ?", [$userId]);
    
    if (!$user || !password_verify($currentPassword, $user['password'])) {
        return ['success' => false, 'message' => 'Ο τρέχων κωδικός είναι λάθος.'];
    }
    
    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
    dbExecute("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?", [$hashed, $userId]);
    
    logAudit('password_change', 'users', $userId);
    
    return ['success' => true];
}
