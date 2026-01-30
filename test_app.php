<?php
/**
 * VolunteerOps - Automated Test Script
 * Τρέχει από command line: php test_app.php
 */

// Colors for terminal output
define('GREEN', "\033[32m");
define('RED', "\033[31m");
define('YELLOW', "\033[33m");
define('RESET', "\033[0m");

class AppTester {
    private string $baseUrl = 'http://localhost/volunteerops';
    private ?string $sessionCookie = null;
    private string $cookieFile;
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;
    
    public function __construct() {
        $this->cookieFile = sys_get_temp_dir() . '/volunteerops_test_cookies.txt';
        // Clear old cookies
        if (file_exists($this->cookieFile)) {
            unlink($this->cookieFile);
        }
    }
    
    public function run(): void {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "   VolunteerOps Automated Test Suite\n";
        echo str_repeat("=", 60) . "\n\n";
        
        // Step 1: Test login
        $this->testLogin();
        
        if (!$this->sessionCookie) {
            echo RED . "✗ Cannot continue without login" . RESET . "\n";
            return;
        }
        
        // Step 2: Test all pages
        $this->testPages();
        
        // Step 3: Test some POST actions
        $this->testActions();
        
        // Summary
        $this->printSummary();
    }
    
    private function testLogin(): void {
        echo "🔐 Testing Login...\n";
        
        // POST login - cookies are handled automatically via cookie file
        $loginResult = $this->httpPost('/login.php', [
            'email' => 'admin@volunteerops.gr',
            'password' => 'password123'
        ]);
        
        // After POST with follow redirect, we should be on dashboard
        if ($loginResult['success']) {
            $body = $loginResult['body'];
            if (strpos($body, 'logout') !== false || 
                strpos($body, 'Αποσύνδεση') !== false ||
                strpos($body, 'sidebar') !== false ||
                strpos($body, 'nav-link') !== false ||
                strpos($body, 'dashboard') !== false) {
                $this->sessionCookie = 'using_cookie_file';
                $this->pass('Login', 'Successfully logged in as admin');
            } else {
                $this->fail('Login', 'Login seemed to work but dashboard indicators not found');
            }
        } else {
            $this->fail('Login', 'HTTP ' . $loginResult['http_code']);
        }
    }
    
    private function testPages(): void {
        echo "\n📄 Testing Pages...\n";
        
        $pages = [
            // Main pages
            ['url' => '/dashboard.php', 'name' => 'Dashboard', 'expect' => 'Πίνακας Ελέγχου'],
            ['url' => '/missions.php', 'name' => 'Missions List', 'expect' => 'Αποστολές'],
            ['url' => '/mission-form.php', 'name' => 'New Mission Form', 'expect' => 'Νέα Αποστολή'],
            ['url' => '/mission-view.php?id=11', 'name' => 'Mission View', 'expect' => 'Βάρδιες'],
            ['url' => '/shifts.php', 'name' => 'Shifts List', 'expect' => 'Βάρδιες'],
            ['url' => '/shift-view.php?id=19', 'name' => 'Shift View', 'expect' => 'Εθελοντές'],
            ['url' => '/shift-form.php?mission_id=11', 'name' => 'New Shift Form', 'expect' => 'Νέα Βάρδια'],
            
            // Volunteer management
            ['url' => '/volunteers.php', 'name' => 'Volunteers List', 'expect' => 'Εθελοντές'],
            ['url' => '/volunteer-view.php?id=7', 'name' => 'Volunteer View', 'expect' => 'Μαρία'],
            ['url' => '/volunteer-form.php?id=7', 'name' => 'Volunteer Edit Form', 'expect' => 'Επεξεργασία'],
            
            // Gamification
            ['url' => '/leaderboard.php', 'name' => 'Leaderboard', 'expect' => 'Κατάταξη'],
            ['url' => '/my-points.php', 'name' => 'My Points', 'expect' => 'Πόντοι'],
            ['url' => '/achievements.php', 'name' => 'Achievements', 'expect' => 'Επιτεύγματα'],
            
            // Admin pages
            ['url' => '/departments.php', 'name' => 'Departments', 'expect' => 'Τμήματα'],
            ['url' => '/settings.php', 'name' => 'Settings', 'expect' => 'Ρυθμίσεις'],
            ['url' => '/reports.php', 'name' => 'Reports', 'expect' => 'Αναφορές'],
            ['url' => '/audit.php', 'name' => 'Audit Log', 'expect' => 'Ιστορικό'],
            
            // Profile
            ['url' => '/profile.php', 'name' => 'Profile', 'expect' => 'Προφίλ'],
        ];
        
        foreach ($pages as $page) {
            $result = $this->httpGet($page['url']);
            
            if (!$result['success']) {
                $this->fail($page['name'], 'HTTP ' . $result['http_code']);
                continue;
            }
            
            // Check for PHP errors in response (ignore Warnings for now)
            if (preg_match('/Fatal error|Parse error|Uncaught/i', $result['body'])) {
                $this->fail($page['name'], 'PHP fatal errors detected in output');
                continue;
            }
            
            // Check expected content
            if (!empty($page['expect']) && strpos($result['body'], $page['expect']) === false) {
                $this->fail($page['name'], "Expected text '{$page['expect']}' not found");
                continue;
            }
            
            $this->pass($page['name'], 'OK');
        }
    }
    
    private function testActions(): void {
        echo "\n🔧 Testing Actions...\n";
        
        // Test 1: View a mission
        $mission = $this->httpGet('/mission-view.php?id=11');
        if ($mission['success'] && strpos($mission['body'], 'Βάρδιες') !== false) {
            $this->pass('View Mission #11', 'OK');
        } else {
            $this->fail('View Mission #11', 'Failed to load');
        }
        
        // Test 2: Check shifts table exists
        $shifts = $this->httpGet('/shifts.php');
        if ($shifts['success'] && strpos($shifts['body'], '<table') !== false) {
            $this->pass('Shifts Table', 'Table rendered');
        } else {
            $this->fail('Shifts Table', 'No table found');
        }
        
        // Test 3: Check leaderboard has users
        $leaderboard = $this->httpGet('/leaderboard.php');
        if ($leaderboard['success'] && strpos($leaderboard['body'], 'Μαρία') !== false) {
            $this->pass('Leaderboard Data', 'Users displayed');
        } else {
            $this->fail('Leaderboard Data', 'No user data found');
        }
        
        // Test 4: Check volunteer points page
        $myPoints = $this->httpGet('/my-points.php');
        // Look for actual PHP errors, not just the word "error" anywhere
        if ($myPoints['success'] && !preg_match('/<b>(Fatal error|Warning|Notice|Parse error)<\/b>|SQLSTATE\[/i', $myPoints['body'])) {
            $this->pass('My Points Page', 'No errors');
        } else {
            $this->fail('My Points Page', 'Errors detected');
        }
        
        // Test 5: Check departments page
        $departments = $this->httpGet('/departments.php');
        if ($departments['success'] && strpos($departments['body'], 'Τμήματα') !== false) {
            $this->pass('Departments Page', 'OK');
        } else {
            $this->fail('Departments Page', 'Failed');
        }
        
        // Test 6: Check attendance page exists
        $attendance = $this->httpGet('/attendance.php?mission_id=11');
        if ($attendance['http_code'] == 200 || $attendance['http_code'] == 302) {
            $this->pass('Attendance Page', 'Accessible');
        } else {
            $this->fail('Attendance Page', 'HTTP ' . $attendance['http_code']);
        }
    }
    
    private function httpGet(string $path, bool $useSession = true): array {
        $ch = curl_init($this->baseUrl . $path);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_COOKIEJAR => $this->cookieFile,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        curl_close($ch);
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 400,
            'http_code' => $httpCode,
            'body' => $body,
            'headers' => $headers
        ];
    }
    
    private function httpPost(string $path, array $data): array {
        $ch = curl_init($this->baseUrl . $path);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_COOKIEJAR => $this->cookieFile,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        curl_close($ch);
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 400,
            'http_code' => $httpCode,
            'body' => $body,
            'headers' => $headers
        ];
    }
    
    private function pass(string $test, string $message): void {
        $this->passed++;
        $this->results[] = ['status' => 'pass', 'test' => $test, 'message' => $message];
        echo "  " . GREEN . "✓" . RESET . " {$test}: {$message}\n";
    }
    
    private function fail(string $test, string $message): void {
        $this->failed++;
        $this->results[] = ['status' => 'fail', 'test' => $test, 'message' => $message];
        echo "  " . RED . "✗" . RESET . " {$test}: {$message}\n";
    }
    
    private function printSummary(): void {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "   Summary\n";
        echo str_repeat("=", 60) . "\n";
        
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100) : 0;
        
        echo "\n  Total Tests: {$total}\n";
        echo "  " . GREEN . "Passed: {$this->passed}" . RESET . "\n";
        echo "  " . RED . "Failed: {$this->failed}" . RESET . "\n";
        echo "  Success Rate: {$percentage}%\n\n";
        
        if ($this->failed > 0) {
            echo YELLOW . "Failed Tests:" . RESET . "\n";
            foreach ($this->results as $r) {
                if ($r['status'] === 'fail') {
                    echo "  - {$r['test']}: {$r['message']}\n";
                }
            }
            echo "\n";
        }
    }
}

// Run tests
$tester = new AppTester();
$tester->run();
