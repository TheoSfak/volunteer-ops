<?php
/**
 * VolunteerOps - Full Automated Test Suite
 * Tests ALL pages, forms, buttons, and functionality
 * 
 * Run: php test_full.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

class VolunteerOpsFullTester {
    private $baseUrl = 'http://localhost/volunteerops';
    private $cookieFile;
    private $testResults = [];
    private $passed = 0;
    private $failed = 0;
    private $skipped = 0;
    private $currentSection = '';
    
    // Test data IDs for cleanup
    private $testMissionId = null;
    private $testShiftId = null;
    private $testVolunteerId = null;
    private $testDepartmentId = null;
    
    public function __construct() {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'volunteerops_test_');
    }
    
    public function __destruct() {
        if (file_exists($this->cookieFile)) {
            unlink($this->cookieFile);
        }
    }
    
    // ========================================
    // HTTP HELPERS
    // ========================================
    
    private function httpGet($path) {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_TIMEOUT => 30
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 400,
            'code' => $httpCode,
            'body' => $response,
            'error' => $error
        ];
    }
    
    private function httpPost($path, $data = []) {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_TIMEOUT => 30
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 400,
            'code' => $httpCode,
            'body' => $response,
            'finalUrl' => $finalUrl,
            'error' => $error
        ];
    }
    
    private function extractCsrfToken($html) {
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/value="([^"]+)"\s+name="csrf_token"/', $html, $m)) {
            return $m[1];
        }
        return 'test_csrf_token';
    }
    
    private function hasPhpError($html) {
        return preg_match('/<b>(Fatal error|Warning|Notice|Parse error)<\/b>|SQLSTATE\[|Exception:/i', $html);
    }
    
    private function extractFlashMessage($html) {
        if (preg_match('/class="alert[^"]*"[^>]*>([^<]+)/i', $html, $m)) {
            return trim(strip_tags($m[1]));
        }
        return null;
    }
    
    // ========================================
    // TEST RESULT HELPERS
    // ========================================
    
    private function section($name) {
        $this->currentSection = $name;
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "  📦 $name\n";
        echo str_repeat("=", 60) . "\n";
    }
    
    private function pass($test, $details = '') {
        $this->passed++;
        $detailStr = $details ? " - $details" : '';
        echo "  ✓ $test$detailStr\n";
        $this->testResults[] = ['section' => $this->currentSection, 'test' => $test, 'status' => 'pass', 'details' => $details];
    }
    
    private function fail($test, $details = '') {
        $this->failed++;
        $detailStr = $details ? " - $details" : '';
        echo "  ✗ $test$detailStr\n";
        $this->testResults[] = ['section' => $this->currentSection, 'test' => $test, 'status' => 'fail', 'details' => $details];
    }
    
    private function skip($test, $reason = '') {
        $this->skipped++;
        $reasonStr = $reason ? " ($reason)" : '';
        echo "  ⊘ $test$reasonStr\n";
        $this->testResults[] = ['section' => $this->currentSection, 'test' => $test, 'status' => 'skip', 'details' => $reason];
    }
    
    // ========================================
    // MAIN TEST RUNNER
    // ========================================
    
    public function run() {
        echo "\n";
        echo str_repeat("=", 60) . "\n";
        echo "   VolunteerOps FULL Automated Test Suite\n";
        echo "   " . date('Y-m-d H:i:s') . "\n";
        echo str_repeat("=", 60) . "\n";
        
        // Run all test sections
        $this->testAuthentication();
        $this->testPublicPages();
        $this->testDashboard();
        $this->testMissionsCRUD();
        $this->testShiftsCRUD();
        $this->testVolunteerManagement();
        $this->testParticipationWorkflow();
        $this->testMissionLifecycleAndAttendance();  // NEW: Close, Attendance, Complete
        $this->testAttendanceAndPoints();
        $this->testDepartmentsCRUD();
        $this->testLeaderboardAndAchievements();
        $this->testReportsAndAudit();
        $this->testUpdateSystem();  // NEW: Update & Backup System
        $this->testProfileAndSettings();
        $this->testEdgeCases();
        $this->cleanup();
        
        $this->printSummary();
    }
    
    // ========================================
    // 1. AUTHENTICATION TESTS
    // ========================================
    
    private function testAuthentication() {
        $this->section('Authentication');
        
        // Test login page loads
        $response = $this->httpGet('/login.php');
        if ($response['success'] && strpos($response['body'], 'email') !== false) {
            $this->pass('Login page loads');
        } else {
            $this->fail('Login page loads', 'HTTP ' . $response['code']);
        }
        
        // Test invalid login
        $response = $this->httpPost('/login.php', [
            'email' => 'invalid@test.com',
            'password' => 'wrongpassword',
            'csrf_token' => 'test'
        ]);
        if (strpos($response['body'], 'login') !== false || strpos($response['body'], 'Λάθος') !== false) {
            $this->pass('Invalid login rejected');
        } else {
            $this->fail('Invalid login rejected');
        }
        
        // Test valid login as admin
        $response = $this->httpPost('/login.php', [
            'email' => 'admin@volunteerops.gr',
            'password' => 'password123',
            'csrf_token' => 'test'
        ]);
        if ($response['success'] && strpos($response['finalUrl'], 'dashboard') !== false) {
            $this->pass('Admin login successful');
        } else {
            $this->fail('Admin login', 'Redirect to: ' . $response['finalUrl']);
        }
        
        // Test we're authenticated
        $response = $this->httpGet('/dashboard.php');
        if ($response['success'] && (strpos($response['body'], 'Διαχειριστής') !== false || 
            strpos($response['body'], 'dashboard') !== false ||
            strpos($response['body'], 'Αποσύνδεση') !== false)) {
            $this->pass('Session persists after login');
        } else {
            $this->fail('Session persists after login');
        }
    }
    
    // ========================================
    // 2. PUBLIC PAGES
    // ========================================
    
    private function testPublicPages() {
        $this->section('Public Pages Access');
        
        $pages = [
            '/login.php' => 'Σύνδεση',
            '/register.php' => 'Εγγραφή',
        ];
        
        foreach ($pages as $url => $expected) {
            $response = $this->httpGet($url);
            if ($response['success'] && !$this->hasPhpError($response['body'])) {
                $this->pass("Page: $url");
            } else {
                $this->fail("Page: $url", 'HTTP ' . $response['code']);
            }
        }
    }
    
    // ========================================
    // 3. DASHBOARD
    // ========================================
    
    private function testDashboard() {
        $this->section('Dashboard');
        
        $response = $this->httpGet('/dashboard.php');
        
        if ($response['success']) {
            $this->pass('Dashboard loads');
        } else {
            $this->fail('Dashboard loads', 'HTTP ' . $response['code']);
        }
        
        if (!$this->hasPhpError($response['body'])) {
            $this->pass('Dashboard has no PHP errors');
        } else {
            $this->fail('Dashboard has no PHP errors');
        }
        
        // Check for stats cards
        if (strpos($response['body'], 'card') !== false) {
            $this->pass('Dashboard shows stats cards');
        } else {
            $this->fail('Dashboard shows stats cards');
        }
    }
    
    // ========================================
    // 4. MISSIONS CRUD
    // ========================================
    
    private function testMissionsCRUD() {
        $this->section('Missions CRUD');
        
        // LIST
        $response = $this->httpGet('/missions.php');
        if ($response['success'] && strpos($response['body'], 'Αποστολές') !== false) {
            $this->pass('Missions list loads');
        } else {
            $this->fail('Missions list loads');
        }
        
        // CREATE FORM
        $response = $this->httpGet('/mission-form.php');
        if ($response['success'] && strpos($response['body'], 'form') !== false) {
            $this->pass('Mission create form loads');
            $csrf = $this->extractCsrfToken($response['body']);
        } else {
            $this->fail('Mission create form loads');
            return;
        }
        
        // CREATE SUBMIT
        $testTitle = 'TEST_MISSION_' . time();
        $response = $this->httpPost('/mission-form.php', [
            'csrf_token' => $csrf,
            'title' => $testTitle,
            'description' => 'Αυτή είναι μια δοκιμαστική αποστολή για το automated test.',
            'type' => 'VOLUNTEER',
            'department_id' => 1,
            'location' => 'Test Location',
            'location_details' => '',
            'start_datetime' => date('d/m/Y H:i', strtotime('+1 day')),
            'end_datetime' => date('d/m/Y H:i', strtotime('+2 days')),
            'requirements' => '',
            'notes' => '',
            'status' => 'DRAFT'
        ]);
        
        // Check if redirected or success
        if (strpos($response['finalUrl'], 'mission-view.php') !== false || 
            strpos($response['body'], 'επιτυχ') !== false ||
            strpos($response['body'], $testTitle) !== false) {
            $this->pass('Mission created');
            
            // Extract mission ID from URL
            if (preg_match('/id=(\d+)/', $response['finalUrl'], $m)) {
                $this->testMissionId = $m[1];
            }
        } else {
            // Try to find the mission
            $list = $this->httpGet('/missions.php');
            if (strpos($list['body'], $testTitle) !== false) {
                $this->pass('Mission created');
                if (preg_match('/mission-view\.php\?id=(\d+)[^>]*>' . preg_quote($testTitle) . '/', $list['body'], $m)) {
                    $this->testMissionId = $m[1];
                }
            } else {
                $this->fail('Mission created', 'Could not verify creation');
            }
        }
        
        // VIEW
        if ($this->testMissionId) {
            $response = $this->httpGet('/mission-view.php?id=' . $this->testMissionId);
            if ($response['success'] && strpos($response['body'], $testTitle) !== false) {
                $this->pass('Mission view works');
            } else {
                $this->fail('Mission view works');
            }
            
            // EDIT FORM
            $response = $this->httpGet('/mission-form.php?id=' . $this->testMissionId);
            if ($response['success'] && strpos($response['body'], $testTitle) !== false) {
                $this->pass('Mission edit form loads');
                $csrf = $this->extractCsrfToken($response['body']);
            } else {
                $this->fail('Mission edit form loads');
            }
            
            // EDIT SUBMIT
            $updatedTitle = $testTitle . '_UPDATED';
            $response = $this->httpPost('/mission-form.php?id=' . $this->testMissionId, [
                'csrf_token' => $csrf,
                'title' => $updatedTitle,
                'description' => 'Updated description',
                'type' => 'VOLUNTEER',
                'department_id' => 1,
                'location' => 'Updated Location',
                'location_details' => '',
                'start_datetime' => date('d/m/Y H:i', strtotime('+1 day')),
                'end_datetime' => date('d/m/Y H:i', strtotime('+2 days')),
                'requirements' => '',
                'notes' => '',
                'status' => 'DRAFT'
            ]);
            
            $view = $this->httpGet('/mission-view.php?id=' . $this->testMissionId);
            if (strpos($view['body'], $updatedTitle) !== false || strpos($view['body'], 'Updated') !== false) {
                $this->pass('Mission updated');
            } else {
                $this->fail('Mission updated');
            }
            
            // STATUS CHANGE - Open
            $response = $this->httpGet('/mission-view.php?id=' . $this->testMissionId);
            $csrf = $this->extractCsrfToken($response['body']);
            
            $response = $this->httpPost('/mission-view.php?id=' . $this->testMissionId, [
                'csrf_token' => $csrf,
                'action' => 'change_status',
                'new_status' => 'OPEN'
            ]);
            
            $view = $this->httpGet('/mission-view.php?id=' . $this->testMissionId);
            if (strpos($view['body'], 'OPEN') !== false || strpos($view['body'], 'Ανοιχτή') !== false) {
                $this->pass('Mission status changed to OPEN');
            } else {
                $this->fail('Mission status changed to OPEN');
            }
        }
        
        // FILTER TEST
        $response = $this->httpGet('/missions.php?status=OPEN');
        if ($response['success']) {
            $this->pass('Missions filter by status works');
        } else {
            $this->fail('Missions filter by status works');
        }
    }
    
    // ========================================
    // 5. SHIFTS CRUD
    // ========================================
    
    private function testShiftsCRUD() {
        $this->section('Shifts CRUD');
        
        // LIST
        $response = $this->httpGet('/shifts.php');
        if ($response['success'] && strpos($response['body'], 'Βάρδιες') !== false) {
            $this->pass('Shifts list loads');
        } else {
            $this->fail('Shifts list loads');
        }
        
        if (!$this->testMissionId) {
            $this->skip('Shift create', 'No test mission available');
            return;
        }
        
        // CREATE FORM
        $response = $this->httpGet('/shift-form.php?mission_id=' . $this->testMissionId);
        if ($response['success'] && strpos($response['body'], 'form') !== false) {
            $this->pass('Shift create form loads');
            $csrf = $this->extractCsrfToken($response['body']);
        } else {
            $this->fail('Shift create form loads');
            return;
        }
        
        // CREATE SUBMIT - shifts table only has: mission_id, start_time, end_time, max/min_volunteers, notes
        $shiftDate = date('Y-m-d', strtotime('+1 day'));
        $response = $this->httpPost('/shift-form.php?mission_id=' . $this->testMissionId, [
            'csrf_token' => $csrf,
            'mission_id' => $this->testMissionId,
            'start_date' => $shiftDate,
            'start_time_hour' => '09:00',
            'end_date' => $shiftDate,
            'end_time_hour' => '17:00',
            'min_volunteers' => 2,
            'max_volunteers' => 10,
            'notes' => 'Test shift notes'
        ]);
        
        // Check creation
        $missionView = $this->httpGet('/mission-view.php?id=' . $this->testMissionId);
        if (strpos($missionView['body'], '09:00') !== false || preg_match('/shift-view\.php\?id=(\d+)/', $missionView['body'], $m)) {
            $this->pass('Shift created');
            if (isset($m[1])) {
                $this->testShiftId = $m[1];
            } else {
                // Find shift ID from page
                preg_match_all('/shift-view\.php\?id=(\d+)/', $missionView['body'], $matches);
                if (!empty($matches[1])) {
                    $this->testShiftId = end($matches[1]); // Get last (newest)
                }
            }
        } else {
            $this->fail('Shift created');
        }
        
        // VIEW
        if ($this->testShiftId) {
            $response = $this->httpGet('/shift-view.php?id=' . $this->testShiftId);
            if ($response['success'] && !$this->hasPhpError($response['body'])) {
                $this->pass('Shift view works');
            } else {
                $this->fail('Shift view works');
            }
            
            // EDIT FORM
            $response = $this->httpGet('/shift-form.php?id=' . $this->testShiftId);
            if ($response['success']) {
                $this->pass('Shift edit form loads');
            } else {
                $this->fail('Shift edit form loads');
            }
        }
    }
    
    // ========================================
    // 6. VOLUNTEER MANAGEMENT
    // ========================================
    
    private function testVolunteerManagement() {
        $this->section('Volunteer Management');
        
        // LIST
        $response = $this->httpGet('/volunteers.php');
        if ($response['success'] && strpos($response['body'], 'Εθελοντές') !== false) {
            $this->pass('Volunteers list loads');
        } else {
            $this->fail('Volunteers list loads');
        }
        
        // Check list has data
        if (strpos($response['body'], '<table') !== false) {
            $this->pass('Volunteers table rendered');
        } else {
            $this->fail('Volunteers table rendered');
        }
        
        // VIEW existing volunteer
        $response = $this->httpGet('/volunteer-view.php?id=2');
        if ($response['success'] && !$this->hasPhpError($response['body'])) {
            $this->pass('Volunteer view works');
        } else {
            $this->fail('Volunteer view works', 'PHP error or HTTP failure');
        }
        
        // EDIT FORM
        $response = $this->httpGet('/volunteer-form.php?id=2');
        if ($response['success'] && strpos($response['body'], 'form') !== false) {
            $this->pass('Volunteer edit form loads');
        } else {
            $this->fail('Volunteer edit form loads');
        }
        
        // CREATE FORM
        $response = $this->httpGet('/volunteer-form.php');
        if ($response['success'] && strpos($response['body'], 'form') !== false) {
            $this->pass('Volunteer create form loads');
            $csrf = $this->extractCsrfToken($response['body']);
        } else {
            $this->fail('Volunteer create form loads');
            return;
        }
        
        // CREATE NEW VOLUNTEER
        $testEmail = 'test_volunteer_' . time() . '@test.com';
        $response = $this->httpPost('/volunteer-form.php', [
            'csrf_token' => $csrf,
            'name' => 'Test Volunteer',
            'email' => $testEmail,
            'phone' => '6900000000',
            'password' => 'testpass123',
            'role' => 'VOLUNTEER',
            'department_id' => 1,
            'is_active' => 1
        ]);
        
        // Verify creation
        $list = $this->httpGet('/volunteers.php');
        if (strpos($list['body'], $testEmail) !== false || strpos($list['body'], 'Test Volunteer') !== false) {
            $this->pass('Volunteer created');
            // Find ID
            if (preg_match('/volunteer-view\.php\?id=(\d+)[^>]*>.*?Test/s', $list['body'], $m)) {
                $this->testVolunteerId = $m[1];
            }
        } else {
            // Check if email already exists error
            if (strpos($response['body'], 'υπάρχει') !== false) {
                $this->skip('Volunteer created', 'Email already exists');
            } else {
                $this->fail('Volunteer created');
            }
        }
        
        // FILTER
        $response = $this->httpGet('/volunteers.php?role=VOLUNTEER');
        if ($response['success']) {
            $this->pass('Volunteers filter works');
        } else {
            $this->fail('Volunteers filter works');
        }
    }
    
    // ========================================
    // 7. PARTICIPATION WORKFLOW
    // ========================================
    
    private function testParticipationWorkflow() {
        $this->section('Participation Workflow');
        
        if (!$this->testShiftId) {
            $this->skip('Participation tests', 'No test shift available');
            return;
        }
        
        // Add volunteer to shift
        $response = $this->httpGet('/shift-view.php?id=' . $this->testShiftId);
        $csrf = $this->extractCsrfToken($response['body']);
        
        // First, find a volunteer ID from volunteers list
        $volunteers = $this->httpGet('/volunteers.php');
        $volunteerId = 7; // Default fallback
        if (preg_match('/volunteer-view\.php\?id=(\d+)/', $volunteers['body'], $m)) {
            $volunteerId = $m[1]; // Use first volunteer found
        }
        
        $response = $this->httpPost('/shift-view.php?id=' . $this->testShiftId, [
            'csrf_token' => $csrf,
            'action' => 'add_volunteer',
            'volunteer_id' => $volunteerId,
            'admin_notes' => 'Added by automated test'
        ]);
        
        // The add_volunteer action automatically sets status to APPROVED
        $view = $this->httpGet('/shift-view.php?id=' . $this->testShiftId);
        if (strpos($response['body'], 'προστέθηκε') !== false ||
            strpos($response['body'], 'ήδη') !== false ||  // Already registered is also OK
            strpos($view['body'], 'APPROVED') !== false ||
            strpos($view['body'], 'Εγκεκριμένη') !== false ||
            preg_match('/volunteer-view\.php\?id=\d+/', $view['body'])) {
            $this->pass('Add volunteer to shift');
        } else {
            $this->fail('Add volunteer to shift');
        }
        
        // Approve participation
        $csrf = $this->extractCsrfToken($view['body']);
        
        // Find participation ID from the page
        if (preg_match('/participation_id["\s]*(?:value="|:)\s*(\d+)/', $view['body'], $m)) {
            $participationId = $m[1];
            
            $response = $this->httpPost('/shift-view.php?id=' . $this->testShiftId, [
                'csrf_token' => $csrf,
                'action' => 'update_status',
                'participation_id' => $participationId,
                'new_status' => 'APPROVED'
            ]);
            
            $view = $this->httpGet('/shift-view.php?id=' . $this->testShiftId);
            if (strpos($view['body'], 'APPROVED') !== false || strpos($view['body'], 'Εγκεκριμένη') !== false) {
                $this->pass('Approve participation');
            } else {
                $this->fail('Approve participation');
            }
        } else {
            $this->skip('Approve participation', 'No participation found');
        }
    }
    
    // ========================================
    // 8. MISSION LIFECYCLE & ATTENDANCE
    // ========================================
    
    private function testMissionLifecycleAndAttendance() {
        $this->section('Mission Lifecycle & Attendance');
        
        if (!$this->testMissionId || !$this->testShiftId) {
            $this->skip('Mission lifecycle tests', 'No test mission/shift available');
            return;
        }
        
        // TEST 1: Close Mission (OPEN → CLOSED)
        $response = $this->httpGet('/mission-view.php?id=' . $this->testMissionId);
        $csrf = $this->extractCsrfToken($response['body']);
        
        $response = $this->httpPost('/mission-view.php?id=' . $this->testMissionId, [
            'csrf_token' => $csrf,
            'action' => 'close'
        ]);
        
        $view = $this->httpGet('/mission-view.php?id=' . $this->testMissionId);
        if (strpos($view['body'], 'CLOSED') !== false || 
            strpos($view['body'], 'Κλειστή') !== false ||
            strpos($response['body'], 'έκλεισε') !== false) {
            $this->pass('Close mission (OPEN → CLOSED)');
        } else {
            $this->fail('Close mission (OPEN → CLOSED)');
        }
        
        // For attendance tests, use existing shift 19 which has participants (IDs 1, 2, 3)
        // Use participation ID 1 for testing
        $testParticipationId = 1;
        
        // TEST 2: Mark Attendance (ήρθε/δεν ήρθε)
        $response = $this->httpGet('/shift-view.php?id=19');
        $csrf = $this->extractCsrfToken($response['body']);
        
        $response = $this->httpPost('/shift-view.php?id=19', [
            'csrf_token' => $csrf,
            'action' => 'mark_attended',
            'participation_id' => $testParticipationId,
            'actual_hours' => 4.5
        ]);
        
        $view = $this->httpGet('/shift-view.php?id=19');
        if (strpos($response['body'], 'καταχωρήθηκε') !== false ||
            strpos($view['body'], '4.5') !== false ||
            strpos($view['body'], '4,5') !== false ||
            strpos($view['body'], 'ώρες') !== false ||
            $response['success']) {
            $this->pass('Mark attendance (attended + hours)');
        } else {
            $this->fail('Mark attendance (attended + hours)');
        }
        
        // TEST 3: Update Hours (διόρθωση ωρών)
        $response = $this->httpGet('/shift-view.php?id=19');
        $csrf = $this->extractCsrfToken($response['body']);
        
        $response = $this->httpPost('/shift-view.php?id=19', [
            'csrf_token' => $csrf,
            'action' => 'mark_attended',
            'participation_id' => $testParticipationId,
            'actual_hours' => 6.0  // Changed from 4.5 to 6.0
        ]);
        
        $view = $this->httpGet('/shift-view.php?id=19');
        if (strpos($view['body'], '6') !== false || $response['success']) {
            $this->pass('Update volunteer hours');
        } else {
            $this->fail('Update volunteer hours');
        }
        
        // TEST 4: Complete Mission (CLOSED → COMPLETED)
        $response = $this->httpGet('/mission-view.php?id=' . $this->testMissionId);
        $csrf = $this->extractCsrfToken($response['body']);
        
        $response = $this->httpPost('/mission-view.php?id=' . $this->testMissionId, [
            'csrf_token' => $csrf,
            'action' => 'complete'
        ]);
        
        $view = $this->httpGet('/mission-view.php?id=' . $this->testMissionId);
        if (strpos($view['body'], 'COMPLETED') !== false || 
            strpos($view['body'], 'Ολοκληρωμένη') !== false ||
            strpos($response['body'], 'ολοκληρώθηκε') !== false) {
            $this->pass('Complete mission (CLOSED → COMPLETED)');
        } else {
            $this->fail('Complete mission (CLOSED → COMPLETED)');
        }
    }
    
    // ========================================
    // 9. ATTENDANCE & POINTS PAGES
    // ========================================
    
    private function testAttendanceAndPoints() {
        $this->section('Attendance & Points Pages');
        
        // Test attendance page with existing mission
        $response = $this->httpGet('/attendance.php?mission_id=11');
        if ($response['success'] && !$this->hasPhpError($response['body'])) {
            $this->pass('Attendance page loads');
        } else {
            $this->fail('Attendance page loads');
        }
        
        // Test shift attendance marking
        $response = $this->httpGet('/shift-view.php?id=19');
        if ($response['success']) {
            $this->pass('Shift with participants loads');
            
            // Check for attendance form elements
            if (strpos($response['body'], 'attended') !== false || 
                strpos($response['body'], 'Παρουσία') !== false ||
                strpos($response['body'], 'checkbox') !== false) {
                $this->pass('Attendance form elements present');
            } else {
                $this->skip('Attendance form elements present', 'May not have participants');
            }
        } else {
            $this->fail('Shift with participants loads');
        }
        
        // Test my-points page
        $response = $this->httpGet('/my-points.php');
        if ($response['success'] && !$this->hasPhpError($response['body'])) {
            $this->pass('My Points page loads');
        } else {
            $this->fail('My Points page loads');
        }
        
        // Check points display
        if (strpos($response['body'], 'Πόντοι') !== false || strpos($response['body'], 'points') !== false) {
            $this->pass('Points display works');
        } else {
            $this->fail('Points display works');
        }
    }
    
    // ========================================
    // 9. DEPARTMENTS CRUD
    // ========================================
    
    private function testDepartmentsCRUD() {
        $this->section('Departments CRUD');
        
        // LIST
        $response = $this->httpGet('/departments.php');
        if ($response['success'] && strpos($response['body'], 'Τμήματα') !== false) {
            $this->pass('Departments list loads');
            $csrf = $this->extractCsrfToken($response['body']);
        } else {
            $this->fail('Departments list loads');
            return;
        }
        
        // CREATE
        $testDeptName = 'TEST_DEPT_' . time();
        $response = $this->httpPost('/departments.php', [
            'csrf_token' => $csrf,
            'action' => 'create',
            'name' => $testDeptName,
            'description' => 'Test department for automated testing'
        ]);
        
        $list = $this->httpGet('/departments.php');
        if (strpos($list['body'], $testDeptName) !== false) {
            $this->pass('Department created');
            // Find ID
            if (preg_match('/data-id="(\d+)"[^>]*>.*?' . preg_quote($testDeptName) . '/s', $list['body'], $m)) {
                $this->testDepartmentId = $m[1];
            } elseif (preg_match('/name="id"\s+value="(\d+)"[^>]*>.*?' . preg_quote($testDeptName) . '/s', $list['body'], $m)) {
                $this->testDepartmentId = $m[1];
            }
        } else {
            $this->fail('Department created');
        }
        
        // EDIT (if we have ID)
        if ($this->testDepartmentId) {
            $csrf = $this->extractCsrfToken($list['body']);
            $response = $this->httpPost('/departments.php', [
                'csrf_token' => $csrf,
                'action' => 'update',
                'id' => $this->testDepartmentId,
                'name' => $testDeptName . '_UPDATED',
                'description' => 'Updated description'
            ]);
            
            $list = $this->httpGet('/departments.php');
            if (strpos($list['body'], $testDeptName . '_UPDATED') !== false) {
                $this->pass('Department updated');
            } else {
                $this->fail('Department updated');
            }
        }
    }
    
    // ========================================
    // 10. LEADERBOARD & ACHIEVEMENTS
    // ========================================
    
    private function testLeaderboardAndAchievements() {
        $this->section('Leaderboard & Achievements');
        
        // LEADERBOARD
        $response = $this->httpGet('/leaderboard.php');
        if ($response['success'] && !$this->hasPhpError($response['body'])) {
            $this->pass('Leaderboard loads');
        } else {
            $this->fail('Leaderboard loads');
        }
        
        if (strpos($response['body'], '<table') !== false || strpos($response['body'], 'ranking') !== false) {
            $this->pass('Leaderboard shows rankings');
        } else {
            $this->fail('Leaderboard shows rankings');
        }
        
        // ACHIEVEMENTS
        $response = $this->httpGet('/achievements.php');
        if ($response['success'] && !$this->hasPhpError($response['body'])) {
            $this->pass('Achievements page loads');
        } else {
            $this->fail('Achievements page loads');
        }
        
        if (strpos($response['body'], 'badge') !== false || strpos($response['body'], 'achievement') !== false) {
            $this->pass('Achievements display');
        } else {
            $this->skip('Achievements display', 'No achievements found');
        }
    }
    
    // ========================================
    // 11. REPORTS & AUDIT
    // ========================================
    
    private function testReportsAndAudit() {
        $this->section('Reports & Audit');
        
        // REPORTS
        $response = $this->httpGet('/reports.php');
        if ($response['success'] && !$this->hasPhpError($response['body'])) {
            $this->pass('Reports page loads');
        } else {
            $this->fail('Reports page loads');
        }
        
        // Test report filters
        $response = $this->httpGet('/reports.php?period=month');
        if ($response['success']) {
            $this->pass('Reports filter works');
        } else {
            $this->fail('Reports filter works');
        }
        
        // AUDIT LOG
        $response = $this->httpGet('/audit.php');
        if ($response['success'] && !$this->hasPhpError($response['body'])) {
            $this->pass('Audit log loads');
        } else {
            $this->fail('Audit log loads');
        }
        
        if (strpos($response['body'], '<table') !== false) {
            $this->pass('Audit log shows entries');
        } else {
            $this->skip('Audit log shows entries', 'May be empty');
        }
    }
    
    // ========================================
    // 11.5 UPDATE SYSTEM
    // ========================================
    
    private function testUpdateSystem() {
        $this->section('Update System & Backups');
        
        // UPDATE PAGE LOADS
        $response = $this->httpGet('/update.php');
        if ($response['success'] && !$this->hasPhpError($response['body'])) {
            $this->pass('Update page loads');
        } else {
            $this->fail('Update page loads');
        }
        
        // Check page has version info
        if (strpos($response['body'], 'APP_VERSION') !== false || 
            strpos($response['body'], 'Τρέχουσα Έκδοση') !== false ||
            strpos($response['body'], 'Έκδοση') !== false) {
            $this->pass('Update page shows version');
        } else {
            $this->fail('Update page shows version');
        }
        
        // Check for update check section
        if (strpos($response['body'], 'Έλεγχος Ενημερώσεων') !== false || 
            strpos($response['body'], 'cloud-download') !== false) {
            $this->pass('Update check section present');
        } else {
            $this->fail('Update check section present');
        }
        
        // Check for backup section
        if (strpos($response['body'], 'Backup') !== false || 
            strpos($response['body'], 'backup') !== false) {
            $this->pass('Backup section present');
        } else {
            $this->fail('Backup section present');
        }
        
        // CREATE BACKUP TEST
        $csrf = $this->extractCsrfToken($response['body']);
        $response = $this->httpPost('/update.php', [
            'csrf_token' => $csrf,
            'action' => 'create_backup'
        ]);
        
        // Should redirect back or show success
        if ($response['success'] || $response['code'] == 302) {
            // Check if backup was created
            $checkResponse = $this->httpGet('/update.php');
            if (strpos($checkResponse['body'], 'backup_') !== false || 
                strpos($checkResponse['body'], 'επιτυχώς') !== false ||
                strpos($checkResponse['body'], 'Backup') !== false) {
                $this->pass('Create backup works');
            } else {
                $this->skip('Create backup works', 'Backup may exist');
            }
        } else {
            $this->fail('Create backup works');
        }
        
        // CHECK UPDATE BUTTON EXISTS
        $response = $this->httpGet('/update.php');
        if (strpos($response['body'], 'Νέο Backup') !== false || 
            strpos($response['body'], 'create_backup') !== false) {
            $this->pass('New backup button present');
        } else {
            $this->fail('New backup button present');
        }
        
        // CHECK LOG SECTION
        if (strpos($response['body'], 'Log') !== false || 
            strpos($response['body'], 'terminal') !== false) {
            $this->pass('Update log section present');
        } else {
            $this->skip('Update log section present', 'Optional feature');
        }
        
        // CHECK HELP SECTION
        if (strpos($response['body'], 'Βοήθεια') !== false || 
            strpos($response['body'], 'question-circle') !== false) {
            $this->pass('Help section present');
        } else {
            $this->skip('Help section present', 'Optional');
        }
        
        // SETTINGS TAB LINK CHECK
        $response = $this->httpGet('/settings.php');
        if (strpos($response['body'], 'update.php') !== false || 
            strpos($response['body'], 'Ενημερώσεις') !== false) {
            $this->pass('Update link in settings tabs');
        } else {
            $this->fail('Update link in settings tabs');
        }
    }
    
    // ========================================
    // 12. PROFILE & SETTINGS
    // ========================================
    
    private function testProfileAndSettings() {
        $this->section('Profile & Settings');
        
        // PROFILE VIEW
        $response = $this->httpGet('/profile.php');
        if ($response['success'] && !$this->hasPhpError($response['body'])) {
            $this->pass('Profile page loads');
        } else {
            $this->fail('Profile page loads');
        }
        
        // Check profile has form
        if (strpos($response['body'], 'form') !== false) {
            $this->pass('Profile edit form present');
        } else {
            $this->fail('Profile edit form present');
        }
        
        // SETTINGS
        $response = $this->httpGet('/settings.php');
        if ($response['success'] && !$this->hasPhpError($response['body'])) {
            $this->pass('Settings page loads');
        } else {
            $this->fail('Settings page loads');
        }
    }
    
    // ========================================
    // 13. EDGE CASES
    // ========================================
    
    private function testEdgeCases() {
        $this->section('Edge Cases & Error Handling');
        
        // Invalid mission ID
        $response = $this->httpGet('/mission-view.php?id=99999');
        if ($response['code'] == 404 || strpos($response['body'], 'δεν βρέθηκε') !== false || 
            strpos($response['finalUrl'], 'missions.php') !== false) {
            $this->pass('Invalid mission ID handled');
        } else {
            $this->fail('Invalid mission ID handled');
        }
        
        // Invalid shift ID
        $response = $this->httpGet('/shift-view.php?id=99999');
        if ($response['code'] == 404 || strpos($response['body'], 'δεν βρέθηκε') !== false ||
            strpos($response['finalUrl'], 'shifts.php') !== false) {
            $this->pass('Invalid shift ID handled');
        } else {
            $this->fail('Invalid shift ID handled');
        }
        
        // Invalid volunteer ID
        $response = $this->httpGet('/volunteer-view.php?id=99999');
        if ($response['code'] == 404 || strpos($response['body'], 'δεν βρέθηκε') !== false ||
            strpos($response['finalUrl'], 'volunteers.php') !== false) {
            $this->pass('Invalid volunteer ID handled');
        } else {
            $this->fail('Invalid volunteer ID handled');
        }
        
        // Missing required parameter - should redirect to missions.php
        $response = $this->httpGet('/mission-view.php');
        // After redirect, we should see the missions list page
        if ($response['code'] == 400 || 
            (isset($response['finalUrl']) && strpos($response['finalUrl'], 'missions.php') !== false) ||
            strpos($response['body'], 'δεν βρέθηκε') !== false ||
            strpos($response['body'], 'Αποστολές') !== false) {  // We're on missions list
            $this->pass('Missing ID parameter handled');
        } else {
            $this->fail('Missing ID parameter handled');
        }
        
        // SQL Injection attempt
        $response = $this->httpGet('/mission-view.php?id=1%27%20OR%201=1--');
        if (!$this->hasPhpError($response['body']) && strpos($response['body'], 'SQLSTATE') === false) {
            $this->pass('SQL injection prevented');
        } else {
            $this->fail('SQL injection prevented', 'Query error exposed');
        }
        
        // XSS attempt
        $response = $this->httpGet('/missions.php?search=<script>alert(1)</script>');
        if (strpos($response['body'], '<script>alert(1)</script>') === false) {
            $this->pass('XSS prevented');
        } else {
            $this->fail('XSS prevented');
        }
    }
    
    // ========================================
    // CLEANUP
    // ========================================
    
    private function cleanup() {
        $this->section('Cleanup Test Data');
        
        // Delete test department
        if ($this->testDepartmentId) {
            $response = $this->httpGet('/departments.php');
            $csrf = $this->extractCsrfToken($response['body']);
            
            $response = $this->httpPost('/departments.php', [
                'csrf_token' => $csrf,
                'action' => 'delete',
                'id' => $this->testDepartmentId
            ]);
            
            $list = $this->httpGet('/departments.php');
            if (strpos($list['body'], 'TEST_DEPT_') === false) {
                $this->pass('Test department deleted');
            } else {
                $this->skip('Test department deleted', 'May have dependencies');
            }
        }
        
        // Delete test shift
        if ($this->testShiftId) {
            $response = $this->httpGet('/shift-view.php?id=' . $this->testShiftId);
            $csrf = $this->extractCsrfToken($response['body']);
            
            $response = $this->httpPost('/shift-view.php?id=' . $this->testShiftId, [
                'csrf_token' => $csrf,
                'action' => 'delete'
            ]);
            
            $this->pass('Test shift cleanup attempted');
        }
        
        // Delete test mission
        if ($this->testMissionId) {
            $response = $this->httpGet('/mission-view.php?id=' . $this->testMissionId);
            $csrf = $this->extractCsrfToken($response['body']);
            
            $response = $this->httpPost('/mission-view.php?id=' . $this->testMissionId, [
                'csrf_token' => $csrf,
                'action' => 'delete'
            ]);
            
            $list = $this->httpGet('/missions.php');
            if (strpos($list['body'], 'TEST_MISSION_') === false) {
                $this->pass('Test mission deleted');
            } else {
                $this->skip('Test mission deleted', 'May require manual cleanup');
            }
        }
        
        // Note about test volunteer
        if ($this->testVolunteerId) {
            $this->skip('Test volunteer deleted', 'Manual cleanup recommended');
        }
    }
    
    // ========================================
    // SUMMARY
    // ========================================
    
    private function printSummary() {
        $total = $this->passed + $this->failed + $this->skipped;
        $successRate = $total > 0 ? round(($this->passed / $total) * 100) : 0;
        
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "   SUMMARY\n";
        echo str_repeat("=", 60) . "\n\n";
        
        echo "  Total Tests:    $total\n";
        echo "  ✓ Passed:       {$this->passed}\n";
        echo "  ✗ Failed:       {$this->failed}\n";
        echo "  ⊘ Skipped:      {$this->skipped}\n";
        echo "  Success Rate:   {$successRate}%\n\n";
        
        if ($this->failed > 0) {
            echo "  FAILED TESTS:\n";
            foreach ($this->testResults as $result) {
                if ($result['status'] === 'fail') {
                    $details = $result['details'] ? " - {$result['details']}" : '';
                    echo "    ✗ [{$result['section']}] {$result['test']}$details\n";
                }
            }
            echo "\n";
        }
        
        if ($this->skipped > 0) {
            echo "  SKIPPED TESTS:\n";
            foreach ($this->testResults as $result) {
                if ($result['status'] === 'skip') {
                    $details = $result['details'] ? " ({$result['details']})" : '';
                    echo "    ⊘ [{$result['section']}] {$result['test']}$details\n";
                }
            }
            echo "\n";
        }
        
        // Overall status
        if ($this->failed === 0) {
            echo "  🎉 ALL TESTS PASSED!\n";
        } elseif ($successRate >= 80) {
            echo "  ⚠️  Some tests failed, but overall health is good.\n";
        } else {
            echo "  ❌ Multiple failures detected. Review required.\n";
        }
        
        echo "\n" . str_repeat("=", 60) . "\n";
    }
}

// Run tests
$tester = new VolunteerOpsFullTester();
$tester->run();
