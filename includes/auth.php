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
// The set of scripts that make up an active War Room (Action Room) session:
// war-room.php itself plus every AJAX/JSON endpoint it calls from a long-open
// tab. TWO independent things key off this one list — that's deliberate, not
// incidental — because keeping it as a single constant is what fixes a bug
// class this codebase already shipped twice: mission-route.php and then
// mission-incident.php were each added to only ONE of what used to be two
// separately hand-maintained arrays (this timeout exemption here, and
// bootstrap.php's external-guest allow-list), silently breaking the other
// behavior until someone noticed. Add a new War Room endpoint to THIS array
// once and both behaviors below follow automatically:
//   1. initSession() below exempts these scripts from the idle-timeout (see
//      the incident this fixed, described in the next comment block).
//   2. bootstrap.php's external-guest gate does
//      array_merge(WAR_ROOM_ACTION_SCRIPTS, [...guest-only pages...]) to
//      build the pages a partner-org guest account may navigate to.
//
// Action Room and everything it depends on (GPS ping, chat, dispatch, SOS,
// etc.) are exempt from the idle-timeout below. Real incident: a mobile
// phone's screen was kept on via the Keep Awake button the whole time, but the
// OS still throttled background JS/network activity (Android Doze-style
// battery optimization applies independently of a screen wake lock — the lock
// only stops the screen from sleeping, it does not exempt the tab from
// background throttling) for longer than session_timeout_minutes. The next
// request after execution resumed found last_activity stale, force-logged the
// volunteer out mid-mission, and GPS auto-ping silently started failing with
// no warning to anyone. As long as the PHP session itself is still valid, one
// of these pages never force-logs-out purely for elapsed time.
define('WAR_ROOM_ACTION_SCRIPTS', [
    'war-room.php', 'ping-location.php', 'volunteer-status.php',
    'mission-chat.php', 'mission-photo.php', 'mission-photo-view.php',
    'mission-dispatch.php', 'mission-order.php', 'mission-sos.php',
    'mission-shortage.php', 'mission-history.php', 'mission-response-report.php',
    'mission-track.php', 'geocode-address.php', 'api-push-subscribe.php',
    // mission-incident.php (Action Room incident/casualty log, added alongside
    // mission-shortage.php/mission-route.php above) — same background-throttling
    // force-logout risk as every other War Room AJAX endpoint on this list.
    'mission-incident.php',
    // Route Orders (mission-route.php, shipped v3.124.0, well after this list
    // was first built) was missed entirely — every depart/arrive/complete/skip
    // tap hit the same background-throttling-triggered force-logout this list
    // exists to prevent everywhere else. Real report: a volunteer tapping
    // "Ξεκίνησε" got the exact "session expired, GPS stopped sending" banner
    // this comment already describes, repeatedly, on their phone.
    'mission-route.php',
    // api-war-room-layout.php (drag-and-drop card layout save) — same risk as
    // every other endpoint on this list: called from within a long-open
    // war-room.php tab, so it needs the same exemption. Not "mission-"
    // prefixed, but neither is api-push-subscribe.php above, already on this
    // list for the identical reason. Previously absent from bootstrap.php's
    // separate guest allow-list (a guest UI never renders the drag/reorder
    // controls, so this was never reachable for them in practice); now
    // included there too via the shared constant, which is safe regardless —
    // the endpoint enforces its own canManageAnyActionRoom() check no matter
    // how the request got routed to it.
    'api-war-room-layout.php',
    // mission-sector.php (search-area coverage tracking) — same background-
    // throttling force-logout risk as every other War Room AJAX endpoint
    // here, and this one matters especially given the multi-country drill
    // this feature was built for: partner-org guest accounts polling it
    // from the field need both this exemption and (via bootstrap.php's
    // $__extAllowed, which derives from this array) guest access itself.
    'mission-sector.php',
    // mission-restricted-area.php (hazard/danger-zone polygons + breach
    // ack/resolve) — same risk and same need for guest access as
    // mission-sector.php just above: its GET poll is read by every approved
    // participant (not just admins) so their own device can drive the
    // personalized full-screen breach alarm.
    'mission-restricted-area.php',
    // mission-sector-coverage.php (Verified Coverage — GPS ground-truth
    // grid-sample against sector polygons) — read-only, but still opened
    // from within a long-open war-room.php tab like every other endpoint on
    // this list, same background-throttling force-logout risk.
    'mission-sector-coverage.php',
    // war-room-keepalive.php — the ONE poll on this page that isn't gated on
    // `!document.hidden` (see its own docblock), so it's also the one whose
    // exemption matters most: a delayed ping landing right as the timeout
    // elapses must still refresh last_activity and return normally instead
    // of hitting the idle check itself and logging the session out.
    'war-room-keepalive.php',
]);

function initSession() {
    if (session_status() === PHP_SESSION_NONE) {
        $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $isWarRoomExempt = in_array($currentScript, WAR_ROOM_ACTION_SCRIPTS, true);

        // Second layer of defense, independent of the app's own timeout check
        // below: PHP's own session garbage collector can delete a session
        // file server-side once it's gone untouched longer than
        // session.gc_maxlifetime (a php.ini default, often far shorter than
        // this app's own configurable timeout) — raise it generously, set
        // before session_start() so it actually takes effect for this session.
        //
        // Raised on EVERY request, not just the War Room ones it was first
        // added for. PHP's GC is probabilistic and runs inside whichever
        // request happens to trigger it, sweeping with THAT request's
        // gc_maxlifetime — so a dashboard.php hit landing on the 1-in-1000
        // roll swept with php.ini's value (1440s = 24 minutes, both locally
        // and on the live hosts) and deleted the session file of an Action
        // Room tab that had merely been backgrounded on a locked phone for
        // half an hour. Exactly the force-logout this ini_set exists to
        // prevent, arriving from a completely unrelated page, which is why
        // limiting it to the War Room scripts could never actually hold.
        // Nothing is weakened by the longer file life: idle is still enforced
        // below against $_SESSION['last_activity'], so a surviving file grants
        // no access it did not already have — it only stops the file being
        // deleted out from under a session the app still considers valid.
        ini_set('session.gc_maxlifetime', 86400); // 24 hours

        // Read the configured inactivity window BEFORE starting the session,
        // so the cookie's own lifetime just below can be driven by it too —
        // previously this was computed only AFTER session_set_cookie_params()
        // had already locked the cookie to the hardcoded SESSION_LIFETIME
        // (2 hours), so setting_value above 120 minutes in Settings was
        // silently capped: the browser discarded the cookie itself at the
        // 2-hour mark no matter what admins configured, regardless of
        // ongoing activity. Clamped to the same 5–1440 minute range
        // settings.php's own form advertises (min="5" max="1440"), matching
        // the equivalent clamp now enforced at save time — defense in depth
        // in case an older/manually-edited row still holds something outside it.
        $timeoutSeconds = SESSION_LIFETIME;
        try {
            $dbTimeout = dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'session_timeout_minutes'");
            if ($dbTimeout !== null && $dbTimeout !== false && (int)$dbTimeout > 0) {
                $timeoutSeconds = max(300, min(86400, (int)$dbTimeout * 60));
            }
        } catch (Exception $e) {
            // DB not ready yet (install phase), use constant
        }

        // War Room pages get the same generous cookie lifetime as their
        // session.gc_maxlifetime override above (24h) rather than the
        // possibly much shorter configured value — otherwise a short
        // app-wide timeout would reintroduce, via the cookie itself
        // expiring client-side, the exact background-throttling force-
        // logout this whole exemption list exists to prevent (see the
        // WAR_ROOM_ACTION_SCRIPTS docblock above).
        $cookieLifetime = $isWarRoomExempt ? 86400 : $timeoutSeconds;

        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => $cookieLifetime,
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
        session_name(SESSION_NAME);
        session_start();

        // Stamped by every War Room script, read by every other one below.
        if ($isWarRoomExempt) {
            $_SESSION['war_room_at'] = time();
        }

        // A session that has been used for an operation within the last day is
        // treated as operational everywhere, not only on the War Room scripts
        // themselves. Both rules below key off this rather than off
        // $isWarRoomExempt, because the exemption is per-SCRIPT and the
        // session is shared by every tab — which is precisely how it kept
        // failing. Caught by a direct test of the fix above: with the cookie
        // now re-issued on every request, a single dashboard.php load in a
        // second tab rewrote the Action Room's 24h cookie back down to the
        // configured 2h, so opening the app's home page in another tab
        // silently re-armed the very logout this is meant to stop.
        $warRoomProtected = isset($_SESSION['war_room_at'])
            && (time() - $_SESSION['war_room_at']) < 86400;
        if ($warRoomProtected) {
            $cookieLifetime = 86400;
        }

        // Slide the cookie's expiry forward on every request.
        //
        // session_set_cookie_params() above only ever reaches the browser on
        // the request that CREATES the session id: PHP emits Set-Cookie there
        // and never again for an id that arrived in the request (verified
        // directly — three consecutive requests to the same script, only the
        // first carried the header). Every session in this app is minted by
        // session_regenerate_id(true) inside loginUser()/
        // establishMissionVisitorSession(), i.e. on login.php or
        // visitor-join.php — neither of which is War-Room-exempt. So the
        // cookie was always stamped with the configured inactivity window
        // (120 minutes by default) counted from the moment of login, and the
        // 86400 branch above was dead code that had never once applied to a
        // real session.
        //
        // The effect in the field: exactly two hours after logging in, no
        // matter how continuously the app was being used, the BROWSER itself
        // discarded the cookie and the next request arrived anonymous. In the
        // Action Room that surfaces as the red "session expired — GPS ping
        // stopped sending" banner mid-operation; everywhere else it is a
        // sudden bounce to the login page. That is the "random logouts"
        // volunteers have been reporting.
        //
        // Re-issuing it here makes the window sliding instead of fixed, and
        // makes the War Room's 24h exemption real for the first time. Safe to
        // do unconditionally: the idle rule itself is still enforced below
        // against $_SESSION['last_activity'], server-side, where a browser
        // cannot argue with it. logout() sets its own deletion cookie later
        // in the same response and a browser applies Set-Cookie headers in
        // order, so the last one for this name — the deletion — still wins.
        if (isset($_COOKIE[SESSION_NAME]) && !headers_sent()) {
            setcookie(SESSION_NAME, session_id(), [
                'expires'  => time() + $cookieLifetime,
                'path'     => '/',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        // $warRoomProtected (computed above, right after session_start) also
        // governs the idle timeout: a session used for an operation is not
        // idle-timed-out, no matter which page the next request comes from.
        //
        // The exemption on the line below is per-SCRIPT, and that was never
        // enough, because logout() destroys the one session every tab shares.
        // The failure documented in includes/inactivity-timeout.php is real
        // and was reported from live testing: Action Room open on a phone, a
        // second tab (or the app's own dashboard) idle in the background, the
        // phone locked in a pocket so every timer on the page is frozen by
        // Android's battery throttling — then the screen comes back on, the
        // idle tab's request lands first, finds last_activity stale and kills
        // the session out from under the Action Room, which was exempt and
        // still open. The volunteer is logged out mid-mission by a page they
        // were not even looking at.
        //
        // 86400 deliberately matches the cookie lifetime and gc_maxlifetime
        // the War Room already gets above, so all three say the same thing:
        // once someone is in an operation, this app keeps them signed in for
        // the day. Ordinary sessions that never touched the Action Room are
        // unaffected and still expire on the configured schedule.
        if (!$isWarRoomExempt && !$warRoomProtected && isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeoutSeconds) {
            logout();
            session_start();
            setFlash('warning', 'Η συνεδρία σας έληξε. Παρακαλώ συνδεθείτε ξανά.');
            redirect('login.php');
        }
        $_SESSION['last_activity'] = time();
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
        $user = dbFetchOne(
            "SELECT u.*, COALESCE(vt.name, mvt.label) AS home_team_name, COALESCE(vt.color, mvt.color) AS home_team_color
             FROM users u
             LEFT JOIN volunteer_teams vt ON vt.id = u.volunteer_team_id
             LEFT JOIN mission_visitor_tags mvt ON mvt.id = u.mission_visitor_tag_id
             WHERE u.id = ? AND u.is_active = 1",
            [$_SESSION['user_id']]
        );
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
 * Check if current system admin is in role preview mode.
 */
function isPreviewMode(): bool {
    if (empty($_SESSION['preview_role_id'])) return false;
    $user = getCurrentUser();
    return $user && $user['role'] === ROLE_SYSTEM_ADMIN;
}

/**
 * Get the role being previewed (null if not in preview mode or role deleted).
 */
function getPreviewRole(): ?array {
    if (!isPreviewMode()) return null;
    static $cache = null;
    if ($cache === null) {
        $cache = dbFetchOne("SELECT * FROM custom_roles WHERE id = ?", [(int)$_SESSION['preview_role_id']]) ?: false;
    }
    return $cache ?: null;
}

/**
 * Check if current user is admin.
 * Returns false during role preview so the UI renders as the previewed role.
 */
function isAdmin() {
    if (isPreviewMode()) return false;
    $user = getCurrentUser();
    if (!$user) return false;
    if (in_array($user['role'], [ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN])) return true;
    return !empty($user['custom_role_id']);
}

/**
 * Check if current user is system admin.
 * Returns false during role preview so the sidebar renders as the previewed role.
 */
function isSystemAdmin() {
    if (isPreviewMode()) return false;
    $user = getCurrentUser();
    return $user && $user['role'] === ROLE_SYSTEM_ADMIN;
}

/**
 * Partner/guest rescue-team account — locked down to Action Room
 * for the mission(s) an admin has approved them on, nothing else.
 */
function isExternalGuest() {
    $user = getCurrentUser();
    return $user && !empty($user['is_external']);
}

/**
 * Walk-up, single-mission Action Room visitor (police/citizen/etc. who
 * self-registered via a QR link) — a narrower sub-type of isExternalGuest(),
 * always is_external=1 AND is_mission_visitor=1. See mission_visitor_tag_id /
 * mission_visitor_mission_id on users, and visitor-join.php.
 */
function isMissionVisitor($user = null) {
    if ($user === null) $user = getCurrentUser();
    return $user && !empty($user['is_mission_visitor']);
}

/**
 * Check if user has specific role
 */
function hasRole($role) {
    $user = getCurrentUser();
    return $user && $user['role'] === $role;
}

/**
 * Login user — with DB-backed brute-force protection (5 attempts / 15 minutes per IP)
 * and session fixation prevention.
 */
function login($email, $password) {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // --- DB-backed rate limiting (survives session reset) ---
    $recentFails = (int) dbFetchValue(
        "SELECT COUNT(*) FROM audit_logs 
         WHERE action = 'login_failed' AND ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
        [$ip]
    );

    if ($recentFails >= 5) {
        return [
            'success' => false,
            'message' => "Πολλές αποτυχημένες συνδέσεις. Παρακαλώ περιμένετε 15 λεπτά και δοκιμάστε ξανά.",
        ];
    }

    // --- Lookup user ---
    $user = dbFetchOne("SELECT * FROM users WHERE email = ?", [$email]);

    if (!$user || !password_verify($password, $user['password'])) {
        // Log failed attempt to DB with IP
        logAudit('login_failed', 'users', 0, null, ['ip' => $ip, 'email' => $email]);
        return ['success' => false, 'message' => 'Λάθος email ή κωδικός.'];
    }

    // Mission Visitors never get a real email/password (synthetic values
    // generated by visitor-join.php, never shown to them) — this branch is
    // defense in depth, not the normal path in. They can only ever get a
    // session via establishMissionVisitorSession() below.
    if (!empty($user['is_mission_visitor'])) {
        return ['success' => false, 'message' => 'Αυτός ο λογαριασμός δεν συνδέεται με email/κωδικό.'];
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

    // --- Success: regenerate session, set data ---

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
 * Establish a session for a Mission Visitor (visitor-join.php's
 * register/resume actions) — same session-fixation-safe block as login(),
 * minus the password/rate-limit/approval checks that don't apply to a
 * walk-up, phone-only, single-mission account.
 */
function establishMissionVisitorSession(int $userId, string $name, string $email): void {
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $_SESSION['user_id']    = $userId;
    $_SESSION['user_name']  = $name;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_role']  = ROLE_VOLUNTEER;
    $_SESSION['login_time'] = time();

    dbExecute("UPDATE users SET updated_at = NOW() WHERE id = ?", [$userId]);
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
    
    // Determine approval status based on system setting
    $requireApproval = getSetting('require_approval', '0');
    $approvalStatus = $requireApproval ? 'PENDING' : 'APPROVED';
    $isActive = $requireApproval ? 0 : 1;
    
    // Insert user
    $userId = dbInsert(
        "INSERT INTO users (name, email, password, phone, role, department_id, is_active, approval_status, email_verification_token, created_at, updated_at) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
        [
            $data['name'],
            $data['email'],
            $hashedPassword,
            $data['phone'] ?? null,
            ROLE_VOLUNTEER,
            $data['department_id'] ?? null,
            $isActive,
            $approvalStatus,
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
 * Validate password strength
 * Returns error message string or null if valid
 */
function validatePasswordStrength(string $password): ?string {
    if (strlen($password) < 8) {
        return 'Ο κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Ο κωδικός πρέπει να περιέχει τουλάχιστον ένα κεφαλαίο γράμμα.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Ο κωδικός πρέπει να περιέχει τουλάχιστον ένα πεζό γράμμα.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Ο κωδικός πρέπει να περιέχει τουλάχιστον ένα ψηφίο.';
    }
    return null;
}

/**
 * Update user password
 */
function updatePassword($userId, $currentPassword, $newPassword) {
    $user = dbFetchOne("SELECT password FROM users WHERE id = ?", [$userId]);
    
    if (!$user || !password_verify($currentPassword, $user['password'])) {
        return ['success' => false, 'message' => 'Ο τρέχων κωδικός είναι λάθος.'];
    }
    
    // Enforce password strength
    $pwError = validatePasswordStrength($newPassword);
    if ($pwError) {
        return ['success' => false, 'message' => $pwError];
    }
    
    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
    dbExecute("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?", [$hashed, $userId]);
    
    logAudit('password_change', 'users', $userId);
    
    return ['success' => true];
}

/**
 * Check if the current user has permission to access a page/section.
 *
 * Rules:
 *  - SYSTEM_ADMIN → always true (full access)
 *  - Any user with no custom_role_id → false (plain volunteer, no elevated access)
 *  - User with custom_role_id → check custom_role_permissions table
 *
 * Pages restricted to SYSTEM_ADMIN only (audit, settings, branches, etc.)
 * never call this function — they use requireRole([ROLE_SYSTEM_ADMIN]) directly.
 */
function hasPagePermission(string $slug): bool {
    $user = getCurrentUser();
    if (!$user) return false;

    // Normal mode: SYSTEM_ADMIN can access everything (bypass skipped during preview)
    if (!isPreviewMode() && $user['role'] === ROLE_SYSTEM_ADMIN) return true;

    // Determine which role ID to load permissions from
    if (isPreviewMode()) {
        $roleId = (int) $_SESSION['preview_role_id'];
    } else {
        if (empty($user['custom_role_id'])) return false;
        $roleId = (int) $user['custom_role_id'];
    }

    static $permCache = [];
    if (!isset($permCache[$roleId])) {
        try {
            $rows = dbFetchAll(
                "SELECT page_slug FROM custom_role_permissions WHERE role_id = ?",
                [$roleId]
            );
            $permCache[$roleId] = array_column($rows, 'page_slug');
        } catch (Exception $e) {
            $permCache[$roleId] = [];
        }
    }

    // Direct grant
    if (in_array($slug, $permCache[$roleId], true)) return true;

    // Implication rules: manage → implies view
    $implications = [
        'missions_view'   => ['missions_manage'],
        'complaints_view' => ['complaints_manage'],
        'training_view'   => ['training_manage', 'questions_manage'],
        'citizens_view'   => ['citizens_manage'],
        'inventory_view'  => ['inventory_manage'],
        'volunteers_view' => ['volunteers_manage'],
    ];

    if (isset($implications[$slug])) {
        foreach ($implications[$slug] as $grantingSlug) {
            if (in_array($grantingSlug, $permCache[$roleId], true)) return true;
        }
    }

    return false;
}

/**
 * The audit log contains security-sensitive information and is intentionally
 * restricted to one explicitly authorised account, regardless of role.
 */
function canAccessAuditLog(): bool {
    $user = getCurrentUser();
    return $user
        && isset($user['email'])
        && strcasecmp(trim((string)$user['email']), 'sfakianakis.theodore@gmail.com') === 0;
}

/**
 * Require a page permission — redirect to dashboard if not authorised.
 * Internally calls requireLogin(), so no need to call it separately.
 */
function requirePermission(string $slug): void {
    requireLogin();
    if (!hasPagePermission($slug)) {
        setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης σε αυτή τη σελίδα.');
        redirect('dashboard.php');
    }
}
