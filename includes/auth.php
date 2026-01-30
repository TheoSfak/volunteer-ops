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
 * Login user
 */
function login($email, $password) {
    $user = dbFetchOne("SELECT * FROM users WHERE email = ?", [$email]);
    
    if (!$user) {
        return ['success' => false, 'message' => 'Λάθος email ή κωδικός.'];
    }
    
    if (!$user['is_active']) {
        return ['success' => false, 'message' => 'Ο λογαριασμός σας είναι απενεργοποιημένος.'];
    }
    
    if (!password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Λάθος email ή κωδικός.'];
    }
    
    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['login_time'] = time();
    
    // Update last login
    dbExecute("UPDATE users SET updated_at = NOW() WHERE id = ?", [$user['id']]);
    
    // Log action
    logAudit('login', 'users', $user['id'], null, ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    
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
    
    // Insert user
    $userId = dbInsert(
        "INSERT INTO users (name, email, password, phone, role, department_id, is_active, created_at, updated_at) 
         VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
        [
            $data['name'],
            $data['email'],
            $hashedPassword,
            $data['phone'] ?? null,
            ROLE_VOLUNTEER, // New users are always volunteers
            $data['department_id'] ?? null
        ]
    );
    
    if ($userId) {
        // Create volunteer profile
        dbInsert(
            "INSERT INTO volunteer_profiles (user_id, created_at, updated_at) VALUES (?, NOW(), NOW())",
            [$userId]
        );
        
        logAudit('register', 'users', $userId);
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
