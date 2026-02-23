<?php
/**
 * VolunteerOps - Αναφορές & Στατιστικά (Mega Analytics Page)
 * All statistics consolidated into one page with 8 tabs and Chart.js visualizations.
 */
require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Αναφορές & Στατιστικά';
$currentPage = 'reports';
$user = getCurrentUser();

// --- Safe DB helpers (return defaults if table/column missing) ---
function safeFetchAll($sql, $params = []) {
    try { return dbFetchAll($sql, $params); } catch (Exception $e) { return []; }
}
function safeFetchValue($sql, $params = []) {
    try { $v = dbFetchValue($sql, $params); return $v !== false && $v !== null ? $v : 0; } catch (Exception $e) { return 0; }
}

// --- FILTERS ---
$activeTab   = get('tab', 'overview');
$startDate   = get('start_date', date('Y') . '-01-01');
$endDate     = get('end_date', date('Y-m-d'));
$departmentId = get('department_id', '');
$cohortYear  = get('cohort_year', '');

if ($user['role'] === ROLE_DEPARTMENT_ADMIN) {
    $departmentId = $user['department_id'];
}

$departments = dbFetchAll("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name");
$cohortYears = safeFetchAll("SELECT DISTINCT cohort_year FROM users WHERE cohort_year IS NOT NULL AND cohort_year > 0 ORDER BY cohort_year DESC");

// Filter query string for tab links
$fp = http_build_query(array_filter([
    'start_date' => $startDate, 'end_date' => $endDate,
    'department_id' => $departmentId, 'cohort_year' => $cohortYear,
]));
function tabUrl($tab) { global $fp; return "reports.php?tab=$tab" . ($fp ? "&$fp" : ""); }

// --- COMMON WHERE CLAUSES ---
$mWhere = "m.deleted_at IS NULL AND DATE(m.start_datetime) BETWEEN ? AND ?";
$mParams = [$startDate, $endDate];
if ($departmentId) { $mWhere .= " AND m.department_id = ?"; $mParams[] = $departmentId; }

// --- CSV EXPORT ---
if (get('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . $activeTab . '_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    $out = fopen('php://output', 'w');
    switch ($activeTab) {
        case 'overview':
            fputcsv($out, ['Μετρική', 'Τιμή']);
            $tot = safeFetchValue("SELECT COUNT(*) FROM missions m WHERE $mWhere", $mParams);
            $comp = safeFetchValue("SELECT COUNT(*) FROM missions m WHERE $mWhere AND m.status = ?", array_merge($mParams, [STATUS_COMPLETED]));
            $hrs = safeFetchValue("SELECT COALESCE(SUM(pr.actual_hours),0) FROM participation_requests pr JOIN shifts s ON pr.shift_id=s.id JOIN missions m ON s.mission_id=m.id WHERE $mWhere AND pr.attended=1", $mParams);
            fputcsv($out, ['Σύνολο Αποστολών', $tot]);
            fputcsv($out, ['Ολοκληρωμένες', $comp]);
            fputcsv($out, ['Ώρες Εθελοντισμού', $hrs]);
            break;
        case 'missions':
            fputcsv($out, ['Κατάσταση', 'Πλήθος']);
            $rows = dbFetchAll("SELECT m.status, COUNT(*) as cnt FROM missions m WHERE $mWhere GROUP BY m.status", $mParams);
            foreach ($rows as $r) fputcsv($out, [$r['status'], $r['cnt']]);
            break;
        case 'volunteers':
            fputcsv($out, ['Εθελοντής', 'Τμήμα', 'Ώρες', 'Βάρδιες', 'Πόντοι']);
            $rows = safeFetchAll("SELECT u.name, d.name as dept, COALESCE(SUM(pr.actual_hours),0) as hours, COUNT(DISTINCT CASE WHEN pr.attended=1 THEN pr.id END) as shifts, u.total_points FROM users u LEFT JOIN departments d ON u.department_id=d.id LEFT JOIN participation_requests pr ON pr.volunteer_id=u.id AND pr.attended=1 WHERE u.deleted_at IS NULL AND u.role IN ('VOLUNTEER','SHIFT_LEADER') " . ($departmentId ? "AND u.department_id=?" : "") . " GROUP BY u.id ORDER BY hours DESC LIMIT 100", $departmentId ? [$departmentId] : []);
            foreach ($rows as $r) fputcsv($out, [$r['name'], $r['dept'], $r['hours'], $r['shifts'], $r['total_points']]);
            break;
        case 'participation':
            fputcsv($out, ['Κατάσταση', 'Πλήθος']);
            $rows = safeFetchAll("SELECT pr.status, COUNT(*) as cnt FROM participation_requests pr JOIN shifts s ON pr.shift_id=s.id JOIN missions m ON s.mission_id=m.id WHERE $mWhere GROUP BY pr.status", $mParams);
            foreach ($rows as $r) fputcsv($out, [$r['status'], $r['cnt']]);
            break;
        case 'training':
            fputcsv($out, ['Εθελοντής', 'Βαθμολογία %', 'Χρόνος (δευτ.)', 'Επιτυχία']);
            $rows = safeFetchAll("SELECT u.name, ea.score, ea.total_questions, ea.time_taken_seconds, ea.passed FROM exam_attempts ea JOIN users u ON ea.user_id=u.id WHERE ea.completed_at IS NOT NULL ORDER BY (ea.score/ea.total_questions) DESC LIMIT 100");
            foreach ($rows as $r) fputcsv($out, [$r['name'], $r['total_questions'] > 0 ? round($r['score']/$r['total_questions']*100,1) : 0, $r['time_taken_seconds'], $r['passed'] ? 'Ναι' : 'Όχι']);
            break;
        case 'certificates':
            fputcsv($out, ['Εθελοντής', 'Τύπος', 'Ημ. Έκδοσης', 'Ημ. Λήξης', 'Κατάσταση']);
            $rows = safeFetchAll("SELECT u.name, ct.name as type_name, vc.issue_date, vc.expiry_date FROM volunteer_certificates vc JOIN users u ON vc.volunteer_id=u.id JOIN certificate_types ct ON vc.certificate_type_id=ct.id ORDER BY vc.expiry_date ASC LIMIT 200");
            foreach ($rows as $r) {
                $st = strtotime($r['expiry_date']) < time() ? 'Ληγμένο' : (strtotime($r['expiry_date']) < strtotime('+30 days') ? 'Λήγει σύντομα' : 'Ενεργό');
                fputcsv($out, [$r['name'], $r['type_name'], $r['issue_date'], $r['expiry_date'], $st]);
            }
            break;
        default:
            fputcsv($out, ['Δεν υπάρχουν δεδομένα εξαγωγής για αυτή την καρτέλα']);
    }
    fclose($out);
    exit;
}

// --- LOAD DATA PER TAB ---
$kpi = [];
$chartData = [];
$tableData = [];

switch ($activeTab) {

// ===== TAB 1: OVERVIEW =====
case 'overview':
    $kpi['missions_total'] = dbFetchValue("SELECT COUNT(*) FROM missions m WHERE $mWhere", $mParams);
    $kpi['missions_completed'] = dbFetchValue("SELECT COUNT(*) FROM missions m WHERE $mWhere AND m.status = ?", array_merge($mParams, [STATUS_COMPLETED]));
    $kpi['active_volunteers'] = safeFetchValue(
        "SELECT COUNT(DISTINCT pr.volunteer_id) FROM participation_requests pr
         JOIN shifts s ON pr.shift_id=s.id JOIN missions m ON s.mission_id=m.id
         WHERE $mWhere AND pr.status = ?", array_merge($mParams, [PARTICIPATION_APPROVED]));
    $kpi['total_hours'] = safeFetchValue(
        "SELECT COALESCE(SUM(pr.actual_hours),0) FROM participation_requests pr
         JOIN shifts s ON pr.shift_id=s.id JOIN missions m ON s.mission_id=m.id
         WHERE $mWhere AND pr.attended=1", $mParams);
    $kpi['total_points'] = safeFetchValue(
        "SELECT COALESCE(SUM(pr.points_awarded),0) FROM participation_requests pr
         JOIN shifts s ON pr.shift_id=s.id JOIN missions m ON s.mission_id=m.id
         WHERE $mWhere AND pr.attended=1", $mParams);
    $approved = safeFetchValue(
        "SELECT COUNT(*) FROM participation_requests pr
         JOIN shifts s ON pr.shift_id=s.id JOIN missions m ON s.mission_id=m.id
         WHERE $mWhere AND pr.status = ?", array_merge($mParams, [PARTICIPATION_APPROVED]));
    $attended = safeFetchValue(
        "SELECT COUNT(*) FROM participation_requests pr
         JOIN shifts s ON pr.shift_id=s.id JOIN missions m ON s.mission_id=m.id
         WHERE $mWhere AND pr.attended=1", $mParams);
    $kpi['attendance_rate'] = $approved > 0 ? round(($attended / $approved) * 100, 1) : 0;

    // Monthly trends (12 months)
    $chartData['monthly'] = safeFetchAll(
        "SELECT DATE_FORMAT(m.start_datetime, '%Y-%m') as month,
                COUNT(DISTINCT m.id) as missions,
                COUNT(DISTINCT CASE WHEN pr.status='APPROVED' THEN pr.volunteer_id END) as volunteers,
                COALESCE(SUM(CASE WHEN pr.attended=1 THEN pr.actual_hours ELSE 0 END),0) as hours
         FROM missions m
         LEFT JOIN shifts s ON s.mission_id=m.id
         LEFT JOIN participation_requests pr ON pr.shift_id=s.id
         WHERE m.deleted_at IS NULL AND m.start_datetime >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         " . ($departmentId ? "AND m.department_id = ?" : "") . "
         GROUP BY month ORDER BY month",
        $departmentId ? [$departmentId] : []);

    // Mission status distribution
    $chartData['statusDist'] = dbFetchAll(
        "SELECT m.status, COUNT(*) as cnt FROM missions m WHERE $mWhere GROUP BY m.status", $mParams);

    // Hours per department
    $chartData['hoursByDept'] = safeFetchAll(
        "SELECT d.name, COALESCE(SUM(pr.actual_hours),0) as hours
         FROM participation_requests pr
         JOIN shifts s ON pr.shift_id=s.id JOIN missions m ON s.mission_id=m.id
         JOIN departments d ON m.department_id=d.id
         WHERE $mWhere AND pr.attended=1
         GROUP BY d.id, d.name ORDER BY hours DESC",
        $mParams);
    break;

// ===== TAB 2: MISSIONS =====
case 'missions':
    $kpi['total']     = dbFetchValue("SELECT COUNT(*) FROM missions m WHERE $mWhere", $mParams);
    $kpi['open']      = dbFetchValue("SELECT COUNT(*) FROM missions m WHERE $mWhere AND m.status='OPEN'", $mParams);
    $kpi['closed']    = dbFetchValue("SELECT COUNT(*) FROM missions m WHERE $mWhere AND m.status='CLOSED'", $mParams);
    $kpi['completed'] = dbFetchValue("SELECT COUNT(*) FROM missions m WHERE $mWhere AND m.status='COMPLETED'", $mParams);
    $kpi['canceled']  = dbFetchValue("SELECT COUNT(*) FROM missions m WHERE $mWhere AND m.status='CANCELED'", $mParams);
    $kpi['urgent']    = safeFetchValue("SELECT COUNT(*) FROM missions m WHERE $mWhere AND m.is_urgent=1", $mParams);

    // Missions per month by status
    $chartData['monthlyStatus'] = safeFetchAll(
        "SELECT DATE_FORMAT(m.start_datetime,'%Y-%m') as month, m.status, COUNT(*) as cnt
         FROM missions m WHERE $mWhere GROUP BY month, m.status ORDER BY month", $mParams);

    // By mission type
    $chartData['byType'] = safeFetchAll(
        "SELECT COALESCE(mt.name,'Χωρίς τύπο') as name, COALESCE(mt.color,'#858796') as color, COUNT(*) as cnt
         FROM missions m LEFT JOIN mission_types mt ON m.mission_type_id=mt.id
         WHERE $mWhere GROUP BY mt.id, mt.name, mt.color ORDER BY cnt DESC", $mParams);

    // By department
    $chartData['byDept'] = safeFetchAll(
        "SELECT d.name, COUNT(*) as cnt FROM missions m
         JOIN departments d ON m.department_id=d.id
         WHERE $mWhere GROUP BY d.id, d.name ORDER BY cnt DESC", $mParams);

    // Shift fill rate per month
    $chartData['fillRate'] = safeFetchAll(
        "SELECT DATE_FORMAT(m.start_datetime,'%Y-%m') as month,
                ROUND(AVG(CASE WHEN s.max_volunteers > 0 THEN
                    (SELECT COUNT(*) FROM participation_requests pr2 WHERE pr2.shift_id=s.id AND pr2.status='APPROVED') / s.max_volunteers * 100
                ELSE 0 END),1) as fill_pct
         FROM shifts s JOIN missions m ON s.mission_id=m.id
         WHERE $mWhere GROUP BY month ORDER BY month", $mParams);

    // Debriefs
    $tableData['debriefs'] = safeFetchAll(
        "SELECT m.title, md.rating, md.objectives_met, md.incidents
         FROM mission_debriefs md JOIN missions m ON md.mission_id=m.id
         WHERE $mWhere ORDER BY m.start_datetime DESC LIMIT 20", $mParams);
    break;

// ===== TAB 3: VOLUNTEERS =====
case 'volunteers':
    $uWhere = "u.deleted_at IS NULL";
    $uParams = [];
    if ($departmentId) { $uWhere .= " AND u.department_id=?"; $uParams[] = $departmentId; }
    if ($cohortYear)   { $uWhere .= " AND u.cohort_year=?";   $uParams[] = $cohortYear; }

    $kpi['total']    = dbFetchValue("SELECT COUNT(*) FROM users u WHERE $uWhere AND u.role != 'SYSTEM_ADMIN'", $uParams);
    $kpi['active']   = dbFetchValue("SELECT COUNT(*) FROM users u WHERE $uWhere AND u.is_active=1 AND u.role != 'SYSTEM_ADMIN'", $uParams);
    $kpi['inactive'] = dbFetchValue("SELECT COUNT(*) FROM users u WHERE $uWhere AND u.is_active=0 AND u.role != 'SYSTEM_ADMIN'", $uParams);
    $kpi['new_period'] = dbFetchValue("SELECT COUNT(*) FROM users u WHERE $uWhere AND u.role != 'SYSTEM_ADMIN' AND DATE(u.created_at) BETWEEN ? AND ?",
        array_merge($uParams, [$startDate, $endDate]));

    // By volunteer type
    $chartData['byType'] = safeFetchAll(
        "SELECT u.volunteer_type as type, COUNT(*) as cnt FROM users u
         WHERE $uWhere AND u.role IN ('VOLUNTEER','SHIFT_LEADER') GROUP BY u.volunteer_type", $uParams);

    // By department
    $chartData['byDept'] = safeFetchAll(
        "SELECT d.name, COUNT(*) as cnt FROM users u
         JOIN departments d ON u.department_id=d.id
         WHERE $uWhere AND u.role != 'SYSTEM_ADMIN' GROUP BY d.id, d.name ORDER BY cnt DESC", $uParams);

    // Registrations per month (last 12 months)
    $chartData['regsMonthly'] = safeFetchAll(
        "SELECT DATE_FORMAT(u.created_at,'%Y-%m') as month, COUNT(*) as cnt
         FROM users u WHERE u.deleted_at IS NULL AND u.role != 'SYSTEM_ADMIN'
         AND u.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         " . ($departmentId ? "AND u.department_id=?" : "") . "
         GROUP BY month ORDER BY month",
        $departmentId ? [$departmentId] : []);

    // Top 15 volunteers
    $tableData['top'] = safeFetchAll(
        "SELECT u.id, u.name, d.name as dept,
                COALESCE(SUM(pr.actual_hours),0) as hours,
                COUNT(DISTINCT CASE WHEN pr.attended=1 THEN pr.id END) as shifts,
                u.total_points
         FROM users u
         LEFT JOIN departments d ON u.department_id=d.id
         LEFT JOIN participation_requests pr ON pr.volunteer_id=u.id AND pr.attended=1
         WHERE $uWhere AND u.role IN ('VOLUNTEER','SHIFT_LEADER')
         GROUP BY u.id ORDER BY hours DESC LIMIT 15", $uParams);
    break;

// ===== TAB 4: PARTICIPATION =====
case 'participation':
    $pJoin = "FROM participation_requests pr JOIN shifts s ON pr.shift_id=s.id JOIN missions m ON s.mission_id=m.id WHERE $mWhere";
    $kpi['total']       = safeFetchValue("SELECT COUNT(*) $pJoin", $mParams);
    $kpi['approved']    = safeFetchValue("SELECT COUNT(*) $pJoin AND pr.status='APPROVED'", $mParams);
    $kpi['rejected']    = safeFetchValue("SELECT COUNT(*) $pJoin AND pr.status='REJECTED'", $mParams);
    $kpi['canceled_u']  = safeFetchValue("SELECT COUNT(*) $pJoin AND pr.status='CANCELED_BY_USER'", $mParams);
    $kpi['canceled_a']  = safeFetchValue("SELECT COUNT(*) $pJoin AND pr.status='CANCELED_BY_ADMIN'", $mParams);
    $kpi['attended']    = safeFetchValue("SELECT COUNT(*) $pJoin AND pr.attended=1", $mParams);
    $kpi['noshow']      = safeFetchValue("SELECT COUNT(*) $pJoin AND pr.status='APPROVED' AND pr.attended=0 AND s.end_time < NOW()", $mParams);

    // Monthly trend
    $chartData['monthly'] = safeFetchAll(
        "SELECT DATE_FORMAT(pr.created_at,'%Y-%m') as month, COUNT(*) as cnt
         $pJoin GROUP BY month ORDER BY month", $mParams);

    // Status distribution
    $chartData['statusDist'] = safeFetchAll(
        "SELECT pr.status, COUNT(*) as cnt $pJoin GROUP BY pr.status", $mParams);

    // Attendance by department
    $chartData['attByDept'] = safeFetchAll(
        "SELECT d.name,
                COUNT(CASE WHEN pr.attended=1 THEN 1 END) as attended,
                COUNT(CASE WHEN pr.status='APPROVED' THEN 1 END) as total_approved
         FROM participation_requests pr
         JOIN shifts s ON pr.shift_id=s.id
         JOIN missions m ON s.mission_id=m.id
         JOIN departments d ON m.department_id=d.id
         WHERE $mWhere AND m.department_id IS NOT NULL
         GROUP BY d.id, d.name ORDER BY attended DESC", $mParams);

    // Avg response time (hours)
    $chartData['responseTime'] = safeFetchAll(
        "SELECT DATE_FORMAT(pr.created_at,'%Y-%m') as month,
                ROUND(AVG(TIMESTAMPDIFF(HOUR, pr.created_at, pr.decided_at)),1) as avg_hours
         $pJoin AND pr.decided_at IS NOT NULL GROUP BY month ORDER BY month", $mParams);

    // Top 15 by attendance
    $tableData['topAttendance'] = safeFetchAll(
        "SELECT u.name, d.name as dept,
                COUNT(CASE WHEN pr.attended=1 THEN 1 END) as attended_cnt,
                COUNT(CASE WHEN pr.status='APPROVED' THEN 1 END) as approved_cnt,
                COALESCE(SUM(pr.actual_hours),0) as total_hours
         FROM participation_requests pr
         JOIN shifts s ON pr.shift_id=s.id
         JOIN missions m ON s.mission_id=m.id
         JOIN users u ON pr.volunteer_id=u.id
         LEFT JOIN departments d ON u.department_id=d.id
         WHERE $mWhere
         GROUP BY u.id ORDER BY attended_cnt DESC LIMIT 15", $mParams);
    break;

// ===== TAB 5: TRAINING =====
case 'training':
    // Exam stats
    $kpi['exam_attempts']  = safeFetchValue("SELECT COUNT(*) FROM exam_attempts WHERE completed_at IS NOT NULL");
    $kpi['exam_passed']    = safeFetchValue("SELECT COUNT(*) FROM exam_attempts WHERE passed=1");
    $kpi['exam_pass_rate'] = $kpi['exam_attempts'] > 0 ? round($kpi['exam_passed']/$kpi['exam_attempts']*100,1) : 0;
    $kpi['exam_avg_score'] = safeFetchValue("SELECT ROUND(AVG(score/total_questions*100),1) FROM exam_attempts WHERE completed_at IS NOT NULL AND total_questions>0");
    // Quiz stats
    $kpi['quiz_attempts']  = safeFetchValue("SELECT COUNT(*) FROM quiz_attempts WHERE completed_at IS NOT NULL");
    $kpi['quiz_avg_score'] = safeFetchValue("SELECT ROUND(AVG(score/total_questions*100),1) FROM quiz_attempts WHERE completed_at IS NOT NULL AND total_questions>0");

    // Per category (exams)
    $chartData['examByCategory'] = safeFetchAll(
        "SELECT tc.name, COUNT(*) as attempts,
                ROUND(AVG(ea.score/ea.total_questions*100),1) as avg_score,
                SUM(ea.passed) as passed_cnt
         FROM exam_attempts ea
         JOIN training_exams te ON ea.exam_id=te.id
         JOIN training_categories tc ON te.category_id=tc.id
         WHERE ea.completed_at IS NOT NULL AND ea.total_questions>0
         GROUP BY tc.id, tc.name ORDER BY attempts DESC");

    // Monthly trend
    $chartData['monthly'] = safeFetchAll(
        "SELECT DATE_FORMAT(completed_at,'%Y-%m') as month,
                COUNT(*) as attempts, SUM(passed) as passed_cnt
         FROM exam_attempts WHERE completed_at IS NOT NULL
         AND completed_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         GROUP BY month ORDER BY month");

    // Pass/Fail distribution
    $chartData['passFail'] = safeFetchAll(
        "SELECT CASE WHEN passed=1 THEN 'Επιτυχία' ELSE 'Αποτυχία' END as result, COUNT(*) as cnt
         FROM exam_attempts WHERE completed_at IS NOT NULL GROUP BY passed");

    // Top 10 by score
    $tableData['topScores'] = safeFetchAll(
        "SELECT u.name, ROUND(ea.score/ea.total_questions*100,1) as pct,
                ea.time_taken_seconds, ea.passed, te.id as exam_id
         FROM exam_attempts ea
         JOIN users u ON ea.user_id=u.id
         JOIN training_exams te ON ea.exam_id=te.id
         WHERE ea.completed_at IS NOT NULL AND ea.total_questions>0
         ORDER BY pct DESC, ea.time_taken_seconds ASC LIMIT 10");
    break;

// ===== TAB 6: CERTIFICATES =====
case 'certificates':
    $kpi['active']   = safeFetchValue("SELECT COUNT(*) FROM volunteer_certificates WHERE expiry_date >= CURDATE()");
    $kpi['expiring'] = safeFetchValue("SELECT COUNT(*) FROM volunteer_certificates WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $kpi['expired']  = safeFetchValue("SELECT COUNT(*) FROM volunteer_certificates WHERE expiry_date < CURDATE()");
    $totalRequired   = safeFetchValue("SELECT COUNT(DISTINCT ct.id) * COUNT(DISTINCT u.id)
        FROM certificate_types ct, users u
        WHERE ct.is_required=1 AND u.deleted_at IS NULL AND u.role IN ('VOLUNTEER','SHIFT_LEADER')");
    $hasCert = safeFetchValue("SELECT COUNT(*) FROM volunteer_certificates vc
        JOIN certificate_types ct ON vc.certificate_type_id=ct.id
        WHERE ct.is_required=1 AND vc.expiry_date >= CURDATE()");
    $kpi['compliance'] = $totalRequired > 0 ? round($hasCert / $totalRequired * 100, 1) : 0;

    // By type
    $chartData['byType'] = safeFetchAll(
        "SELECT ct.name,
                SUM(CASE WHEN vc.expiry_date >= CURDATE() THEN 1 ELSE 0 END) as active_cnt,
                SUM(CASE WHEN vc.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as expiring_cnt,
                SUM(CASE WHEN vc.expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired_cnt
         FROM volunteer_certificates vc
         JOIN certificate_types ct ON vc.certificate_type_id=ct.id
         GROUP BY ct.id, ct.name ORDER BY ct.name");

    // Missing required
    $tableData['missing'] = safeFetchAll(
        "SELECT u.name, u.email, ct.name as cert_type, d.name as dept
         FROM certificate_types ct
         CROSS JOIN users u
         LEFT JOIN departments d ON u.department_id=d.id
         LEFT JOIN volunteer_certificates vc ON vc.volunteer_id=u.id AND vc.certificate_type_id=ct.id AND vc.expiry_date >= CURDATE()
         WHERE ct.is_required=1 AND u.deleted_at IS NULL AND u.role IN ('VOLUNTEER','SHIFT_LEADER')
         AND vc.id IS NULL ORDER BY ct.name, u.name LIMIT 50");
    break;

// ===== TAB 7: INVENTORY =====
case 'inventory':
    $kpi['total']     = safeFetchValue("SELECT COUNT(*) FROM inventory_items");
    $kpi['available'] = safeFetchValue("SELECT COUNT(*) FROM inventory_items WHERE status='AVAILABLE'");
    $kpi['booked']    = safeFetchValue("SELECT COUNT(*) FROM inventory_items WHERE status IN ('BOOKED','CHECKED_OUT')");
    $kpi['overdue']   = safeFetchValue("SELECT COUNT(*) FROM inventory_bookings WHERE status='overdue'");
    $kpi['lost']      = safeFetchValue("SELECT COUNT(*) FROM inventory_bookings WHERE status='lost'");

    // By status
    $chartData['byStatus'] = safeFetchAll(
        "SELECT status, COUNT(*) as cnt FROM inventory_items GROUP BY status ORDER BY cnt DESC");

    // By category
    $chartData['byCategory'] = safeFetchAll(
        "SELECT ic.name, COUNT(*) as cnt FROM inventory_items ii
         JOIN inventory_categories ic ON ii.category_id=ic.id
         GROUP BY ic.id, ic.name ORDER BY cnt DESC");

    // Bookings per month
    $chartData['bookingsMonthly'] = safeFetchAll(
        "SELECT DATE_FORMAT(created_at,'%Y-%m') as month, COUNT(*) as cnt
         FROM inventory_bookings
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         GROUP BY month ORDER BY month");
    break;

// ===== TAB 8: SYSTEM =====
case 'system':
    $kpi['audit_total']   = safeFetchValue("SELECT COUNT(*) FROM audit_log WHERE DATE(created_at) BETWEEN ? AND ?", [$startDate, $endDate]);
    $kpi['emails_sent']   = safeFetchValue("SELECT COALESCE(SUM(sent_count),0) FROM newsletters WHERE DATE(sent_at) BETWEEN ? AND ?", [$startDate, $endDate]);
    $kpi['emails_failed'] = safeFetchValue("SELECT COALESCE(SUM(failed_count),0) FROM newsletters WHERE DATE(sent_at) BETWEEN ? AND ?", [$startDate, $endDate]);
    $kpi['notif_total']   = safeFetchValue("SELECT COUNT(*) FROM notifications WHERE DATE(created_at) BETWEEN ? AND ?", [$startDate, $endDate]);
    $kpi['notif_read']    = safeFetchValue("SELECT COUNT(*) FROM notifications WHERE read_at IS NOT NULL AND DATE(created_at) BETWEEN ? AND ?", [$startDate, $endDate]);

    // Audit per month
    $chartData['auditMonthly'] = safeFetchAll(
        "SELECT DATE_FORMAT(created_at,'%Y-%m') as month, COUNT(*) as cnt
         FROM audit_log WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         GROUP BY month ORDER BY month");

    // Audit by action
    $chartData['auditByAction'] = safeFetchAll(
        "SELECT action, COUNT(*) as cnt FROM audit_log
         WHERE DATE(created_at) BETWEEN ? AND ?
         GROUP BY action ORDER BY cnt DESC LIMIT 10", [$startDate, $endDate]);

    // Newsletter delivery per month
    $chartData['newsletterMonthly'] = safeFetchAll(
        "SELECT DATE_FORMAT(sent_at,'%Y-%m') as month,
                SUM(sent_count) as sent, SUM(failed_count) as failed
         FROM newsletters WHERE sent_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         GROUP BY month ORDER BY month");
    break;

default:
    $activeTab = 'overview';
    redirect('reports.php?tab=overview');
}

include __DIR__ . '/includes/header.php';

// Greek month names for JS
$greekMonths = ['Ιαν','Φεβ','Μαρ','Απρ','Μάι','Ιούν','Ιούλ','Αύγ','Σεπ','Οκτ','Νοέ','Δεκ'];
?>

<style>
.kpi-card { border-top: 3px solid; transition: transform 0.15s; }
.kpi-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.12); }
.kpi-card .kpi-value { font-size: 1.8rem; font-weight: 700; }
.kpi-card .kpi-label { font-size: 0.85rem; color: #6c757d; }
.chart-card { min-height: 340px; }
.chart-card canvas { max-height: 300px; }
.nav-tabs .nav-link { font-size: 0.9rem; }
.nav-tabs .nav-link i { margin-right: 4px; }
.table-ranking th, .table-ranking td { font-size: 0.88rem; }
@media print {
    .sidebar, .navbar, .filter-bar, .nav-tabs, .btn-export, .no-print { display: none !important; }
    .main-content { margin-left: 0 !important; padding: 0 !important; }
    .chart-card { break-inside: avoid; page-break-inside: avoid; }
}
</style>

<!-- FILTER BAR -->
<div class="filter-bar bg-light border rounded p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
        <div class="col-md-2">
            <label class="form-label small mb-1">Από</label>
            <input type="date" name="start_date" class="form-control form-control-sm" value="<?= h($startDate) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Έως</label>
            <input type="date" name="end_date" class="form-control form-control-sm" value="<?= h($endDate) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Τμήμα</label>
            <select name="department_id" class="form-select form-select-sm" <?= $user['role'] === ROLE_DEPARTMENT_ADMIN ? 'disabled' : '' ?>>
                <option value="">Όλα</option>
                <?php foreach ($departments as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $departmentId == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Σειρά</label>
            <select name="cohort_year" class="form-select form-select-sm">
                <option value="">Όλες</option>
                <?php foreach ($cohortYears as $cy): ?>
                <option value="<?= h($cy['cohort_year']) ?>" <?= $cohortYear == $cy['cohort_year'] ? 'selected' : '' ?>><?= h($cy['cohort_year']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-auto">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Εφαρμογή</button>
        </div>
        <div class="col-md-auto">
            <a href="<?= tabUrl($activeTab) ?>&export=csv" class="btn btn-outline-success btn-sm btn-export"><i class="bi bi-download"></i> CSV</a>
            <button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-sm btn-export"><i class="bi bi-printer"></i> Εκτύπωση</button>
        </div>
    </form>
</div>

<!-- TABS -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item"><a class="nav-link <?= $activeTab==='overview' ? 'active' : '' ?>" href="<?= tabUrl('overview') ?>"><i class="bi bi-speedometer2"></i> Επισκόπηση</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='missions' ? 'active' : '' ?>" href="<?= tabUrl('missions') ?>"><i class="bi bi-geo-alt"></i> Αποστολές</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='volunteers' ? 'active' : '' ?>" href="<?= tabUrl('volunteers') ?>"><i class="bi bi-people"></i> Εθελοντές</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='participation' ? 'active' : '' ?>" href="<?= tabUrl('participation') ?>"><i class="bi bi-clipboard-check"></i> Συμμετοχές</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='training' ? 'active' : '' ?>" href="<?= tabUrl('training') ?>"><i class="bi bi-mortarboard"></i> Εκπαίδευση</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='certificates' ? 'active' : '' ?>" href="<?= tabUrl('certificates') ?>"><i class="bi bi-award"></i> Πιστοποιητικά</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='inventory' ? 'active' : '' ?>" href="<?= tabUrl('inventory') ?>"><i class="bi bi-box-seam"></i> Εξοπλισμός</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='system' ? 'active' : '' ?>" href="<?= tabUrl('system') ?>"><i class="bi bi-gear"></i> Σύστημα</a></li>
</ul>

<!-- ==================== TAB CONTENT ==================== -->

<?php if ($activeTab === 'overview'): ?>
<!-- KPI CARDS -->
<div class="row mb-4">
    <div class="col-md-2 col-sm-4 mb-3">
        <div class="card kpi-card" style="border-top-color:#4e73df">
            <div class="card-body text-center p-3">
                <i class="bi bi-geo-alt fs-3 text-primary"></i>
                <div class="kpi-value"><?= number_format($kpi['missions_total']) ?></div>
                <div class="kpi-label">Αποστολές</div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-sm-4 mb-3">
        <div class="card kpi-card" style="border-top-color:#1cc88a">
            <div class="card-body text-center p-3">
                <i class="bi bi-check-circle fs-3 text-success"></i>
                <div class="kpi-value"><?= number_format($kpi['missions_completed']) ?></div>
                <div class="kpi-label">Ολοκληρωμένες</div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-sm-4 mb-3">
        <div class="card kpi-card" style="border-top-color:#36b9cc">
            <div class="card-body text-center p-3">
                <i class="bi bi-people fs-3 text-info"></i>
                <div class="kpi-value"><?= number_format($kpi['active_volunteers']) ?></div>
                <div class="kpi-label">Ενεργοί Εθελοντές</div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-sm-4 mb-3">
        <div class="card kpi-card" style="border-top-color:#f6c23e">
            <div class="card-body text-center p-3">
                <i class="bi bi-clock-history fs-3 text-warning"></i>
                <div class="kpi-value"><?= number_format($kpi['total_hours'], 1) ?></div>
                <div class="kpi-label">Ώρες Εθελοντισμού</div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-sm-4 mb-3">
        <div class="card kpi-card" style="border-top-color:#5a5c69">
            <div class="card-body text-center p-3">
                <i class="bi bi-star fs-3 text-secondary"></i>
                <div class="kpi-value"><?= number_format($kpi['total_points']) ?></div>
                <div class="kpi-label">Πόντοι</div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-sm-4 mb-3">
        <div class="card kpi-card" style="border-top-color:<?= $kpi['attendance_rate'] >= 80 ? '#1cc88a' : ($kpi['attendance_rate'] >= 60 ? '#f6c23e' : '#e74a3b') ?>">
            <div class="card-body text-center p-3">
                <i class="bi bi-percent fs-3"></i>
                <div class="kpi-value"><?= $kpi['attendance_rate'] ?>%</div>
                <div class="kpi-label">Ποσοστό Παρουσίας</div>
            </div>
        </div>
    </div>
</div>
<!-- CHARTS -->
<div class="row">
    <div class="col-lg-8 mb-3">
        <div class="card chart-card">
            <div class="card-header"><strong><i class="bi bi-graph-up"></i> Μηνιαίες Τάσεις (12 μήνες)</strong></div>
            <div class="card-body"><canvas id="chartMonthly"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card chart-card">
            <div class="card-header"><strong><i class="bi bi-pie-chart"></i> Κατάσταση Αποστολών</strong></div>
            <div class="card-body"><canvas id="chartStatusDist"></canvas></div>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-12 mb-3">
        <div class="card chart-card">
            <div class="card-header"><strong><i class="bi bi-bar-chart"></i> Ώρες ανά Τμήμα</strong></div>
            <div class="card-body"><canvas id="chartHoursDept"></canvas></div>
        </div>
    </div>
</div>

<?php elseif ($activeTab === 'missions'): ?>
<!-- KPI CARDS -->
<div class="row mb-4">
    <?php
    $mCards = [
        ['Σύνολο', $kpi['total'], 'geo-alt', '#4e73df'],
        ['Ανοιχτές', $kpi['open'], 'unlock', '#36b9cc'],
        ['Κλειστές', $kpi['closed'], 'lock', '#f6c23e'],
        ['Ολοκληρωμένες', $kpi['completed'], 'check-circle', '#1cc88a'],
        ['Ακυρωμένες', $kpi['canceled'], 'x-circle', '#e74a3b'],
        ['Επείγουσες', $kpi['urgent'], 'exclamation-triangle', '#e74a3b'],
    ];
    foreach ($mCards as $c): ?>
    <div class="col-md-2 col-sm-4 mb-3">
        <div class="card kpi-card" style="border-top-color:<?= $c[3] ?>">
            <div class="card-body text-center p-3">
                <i class="bi bi-<?= $c[2] ?> fs-3" style="color:<?= $c[3] ?>"></i>
                <div class="kpi-value"><?= number_format($c[1]) ?></div>
                <div class="kpi-label"><?= $c[0] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div class="row">
    <div class="col-lg-8 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-bar-chart-stacked"></i> Αποστολές/Μήνα ανά Κατάσταση</strong></div>
            <div class="card-body"><canvas id="chartMissionsMonthly"></canvas></div></div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-pie-chart"></i> Ανά Τύπο Αποστολής</strong></div>
            <div class="card-body"><canvas id="chartMissionsByType"></canvas></div></div>
    </div>
</div>
<div class="row">
    <div class="col-lg-6 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-pie-chart-fill"></i> Ανά Τμήμα</strong></div>
            <div class="card-body"><canvas id="chartMissionsByDept"></canvas></div></div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-bar-chart"></i> Πληρότητα Βαρδιών % / Μήνα</strong></div>
            <div class="card-body"><canvas id="chartFillRate"></canvas></div></div>
    </div>
</div>
<?php if (!empty($tableData['debriefs'])): ?>
<div class="card mb-3">
    <div class="card-header"><strong><i class="bi bi-journal-text"></i> Αξιολογήσεις Αποστολών (Debriefs)</strong></div>
    <div class="card-body table-responsive">
        <table class="table table-sm table-striped table-ranking">
            <thead><tr><th>Αποστολή</th><th>Βαθμός</th><th>Στόχοι</th><th>Περιστατικά</th></tr></thead>
            <tbody>
            <?php foreach ($tableData['debriefs'] as $db): ?>
            <tr>
                <td><?= h($db['title']) ?></td>
                <td><?php for($i=1;$i<=5;$i++) echo $i <= ($db['rating']??0) ? '<i class="bi bi-star-fill text-warning"></i>' : '<i class="bi bi-star text-muted"></i>'; ?></td>
                <td><span class="badge bg-<?= ($db['objectives_met']??'') === 'YES' ? 'success' : (($db['objectives_met']??'') === 'PARTIAL' ? 'warning' : 'danger') ?>"><?= h($db['objectives_met'] ?? '-') ?></span></td>
                <td><?= h($db['incidents'] ?? '-') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php elseif ($activeTab === 'volunteers'): ?>
<div class="row mb-4">
    <?php
    $vCards = [
        ['Σύνολο', $kpi['total'], 'people', '#4e73df'],
        ['Ενεργοί', $kpi['active'], 'person-check', '#1cc88a'],
        ['Ανενεργοί', $kpi['inactive'], 'person-x', '#e74a3b'],
        ['Νέοι (περίοδος)', $kpi['new_period'], 'person-plus', '#36b9cc'],
    ];
    foreach ($vCards as $c): ?>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card kpi-card" style="border-top-color:<?= $c[3] ?>">
            <div class="card-body text-center p-3">
                <i class="bi bi-<?= $c[2] ?> fs-3" style="color:<?= $c[3] ?>"></i>
                <div class="kpi-value"><?= number_format($c[1]) ?></div>
                <div class="kpi-label"><?= $c[0] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div class="row">
    <div class="col-lg-4 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-pie-chart"></i> Ανά Τύπο</strong></div>
            <div class="card-body"><canvas id="chartVolByType"></canvas></div></div>
    </div>
    <div class="col-lg-8 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-bar-chart"></i> Ανά Τμήμα</strong></div>
            <div class="card-body"><canvas id="chartVolByDept"></canvas></div></div>
    </div>
</div>
<div class="row">
    <div class="col-12 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-graph-up"></i> Εγγραφές / Μήνα</strong></div>
            <div class="card-body"><canvas id="chartRegsMonthly"></canvas></div></div>
    </div>
</div>
<?php if (!empty($tableData['top'])): ?>
<div class="card mb-3">
    <div class="card-header"><strong><i class="bi bi-trophy"></i> Top 15 Εθελοντές</strong></div>
    <div class="card-body table-responsive">
        <table class="table table-sm table-striped table-ranking">
            <thead><tr><th>#</th><th>Εθελοντής</th><th>Τμήμα</th><th>Ώρες</th><th>Βάρδιες</th><th>Πόντοι</th></tr></thead>
            <tbody>
            <?php foreach ($tableData['top'] as $i => $v): ?>
            <tr>
                <td><span class="badge bg-<?= $i < 3 ? 'warning' : 'secondary' ?>"><?= $i+1 ?></span></td>
                <td><?= h($v['name']) ?></td>
                <td><?= h($v['dept'] ?? '-') ?></td>
                <td><?= number_format($v['hours'], 1) ?></td>
                <td><?= $v['shifts'] ?></td>
                <td><?= number_format($v['total_points']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php elseif ($activeTab === 'participation'): ?>
<div class="row mb-4">
    <?php
    $pCards = [
        ['Σύνολο Αιτήσεων', $kpi['total'], 'clipboard', '#4e73df'],
        ['Εγκεκριμένες', $kpi['approved'], 'check-circle', '#1cc88a'],
        ['Απορρίψεις', $kpi['rejected'], 'x-circle', '#e74a3b'],
        ['Ακύρωση Εθελοντή', $kpi['canceled_u'], 'person-x', '#f6c23e'],
        ['Ακύρωση Admin', $kpi['canceled_a'], 'shield-x', '#858796'],
        ['Παρόντες', $kpi['attended'], 'person-check', '#1cc88a'],
    ];
    foreach ($pCards as $c): ?>
    <div class="col-md-2 col-sm-4 mb-3">
        <div class="card kpi-card" style="border-top-color:<?= $c[3] ?>">
            <div class="card-body text-center p-3">
                <i class="bi bi-<?= $c[2] ?> fs-3" style="color:<?= $c[3] ?>"></i>
                <div class="kpi-value"><?= number_format($c[1]) ?></div>
                <div class="kpi-label"><?= $c[0] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div class="row">
    <div class="col-lg-8 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-graph-up"></i> Αιτήσεις / Μήνα</strong></div>
            <div class="card-body"><canvas id="chartPartMonthly"></canvas></div></div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-pie-chart"></i> Κατανομή Κατάστασης</strong></div>
            <div class="card-body"><canvas id="chartPartStatus"></canvas></div></div>
    </div>
</div>
<div class="row">
    <div class="col-lg-6 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-bar-chart"></i> Παρουσίες ανά Τμήμα</strong></div>
            <div class="card-body"><canvas id="chartAttByDept"></canvas></div></div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-clock"></i> Μέσος Χρόνος Απάντησης (ώρες)</strong></div>
            <div class="card-body"><canvas id="chartRespTime"></canvas></div></div>
    </div>
</div>
<?php if (!empty($tableData['topAttendance'])): ?>
<div class="card mb-3">
    <div class="card-header"><strong><i class="bi bi-trophy"></i> Top 15 - Παρουσίες</strong></div>
    <div class="card-body table-responsive">
        <table class="table table-sm table-striped table-ranking">
            <thead><tr><th>#</th><th>Εθελοντής</th><th>Τμήμα</th><th>Παρουσίες</th><th>Εγκεκριμένες</th><th>Ώρες</th><th>%</th></tr></thead>
            <tbody>
            <?php foreach ($tableData['topAttendance'] as $i => $v): ?>
            <tr>
                <td><span class="badge bg-<?= $i < 3 ? 'warning' : 'secondary' ?>"><?= $i+1 ?></span></td>
                <td><?= h($v['name']) ?></td>
                <td><?= h($v['dept'] ?? '-') ?></td>
                <td><?= $v['attended_cnt'] ?></td>
                <td><?= $v['approved_cnt'] ?></td>
                <td><?= number_format($v['total_hours'], 1) ?></td>
                <td><?= $v['approved_cnt'] > 0 ? round($v['attended_cnt']/$v['approved_cnt']*100) : 0 ?>%</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php elseif ($activeTab === 'training'): ?>
<div class="row mb-4">
    <?php
    $tCards = [
        ['Προσπάθειες Εξετ.', $kpi['exam_attempts'], 'file-earmark-text', '#4e73df'],
        ['Επιτυχία Εξετ.', $kpi['exam_pass_rate'] . '%', 'check2-circle', '#1cc88a'],
        ['Μ.Ο. Βαθμολογίας', ($kpi['exam_avg_score'] ?: 0) . '%', 'graph-up', '#f6c23e'],
        ['Προσπάθειες Κουίζ', $kpi['quiz_attempts'], 'puzzle', '#36b9cc'],
        ['Μ.Ο. Κουίζ', ($kpi['quiz_avg_score'] ?: 0) . '%', 'graph-up-arrow', '#5a5c69'],
    ];
    foreach ($tCards as $c): ?>
    <div class="col mb-3">
        <div class="card kpi-card" style="border-top-color:<?= $c[3] ?>">
            <div class="card-body text-center p-3">
                <i class="bi bi-<?= $c[2] ?> fs-3" style="color:<?= $c[3] ?>"></i>
                <div class="kpi-value"><?= $c[1] ?></div>
                <div class="kpi-label"><?= $c[0] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div class="row">
    <div class="col-lg-6 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-bar-chart"></i> Εξετάσεις ανά Κατηγορία</strong></div>
            <div class="card-body"><canvas id="chartExamByCat"></canvas></div></div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-graph-up"></i> Προσπάθειες / Μήνα</strong></div>
            <div class="card-body"><canvas id="chartTrainMonthly"></canvas></div></div>
    </div>
</div>
<div class="row">
    <div class="col-lg-4 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-pie-chart"></i> Επιτυχία / Αποτυχία</strong></div>
            <div class="card-body"><canvas id="chartPassFail"></canvas></div></div>
    </div>
    <div class="col-lg-8 mb-3">
        <?php if (!empty($tableData['topScores'])): ?>
        <div class="card">
            <div class="card-header"><strong><i class="bi bi-trophy"></i> Top 10 Βαθμολογίες Εξετάσεων</strong></div>
            <div class="card-body table-responsive">
                <table class="table table-sm table-striped table-ranking">
                    <thead><tr><th>#</th><th>Εθελοντής</th><th>Βαθμός %</th><th>Χρόνος</th><th>Αποτέλεσμα</th></tr></thead>
                    <tbody>
                    <?php foreach ($tableData['topScores'] as $i => $s): ?>
                    <tr>
                        <td><span class="badge bg-<?= $i < 3 ? 'warning' : 'secondary' ?>"><?= $i+1 ?></span></td>
                        <td><?= h($s['name']) ?></td>
                        <td><strong><?= $s['pct'] ?>%</strong></td>
                        <td><?= $s['time_taken_seconds'] ? gmdate('i:s', $s['time_taken_seconds']) : '-' ?></td>
                        <td><span class="badge bg-<?= $s['passed'] ? 'success' : 'danger' ?>"><?= $s['passed'] ? 'Επιτυχία' : 'Αποτυχία' ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($activeTab === 'certificates'): ?>
<div class="row mb-4">
    <?php
    $cCards = [
        ['Ενεργά', $kpi['active'], 'shield-check', '#1cc88a'],
        ['Λήγουν (30 ημ.)', $kpi['expiring'], 'exclamation-triangle', '#f6c23e'],
        ['Ληγμένα', $kpi['expired'], 'shield-x', '#e74a3b'],
        ['Συμμόρφωση', $kpi['compliance'] . '%', 'clipboard-check', $kpi['compliance'] >= 80 ? '#1cc88a' : '#e74a3b'],
    ];
    foreach ($cCards as $c): ?>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card kpi-card" style="border-top-color:<?= $c[3] ?>">
            <div class="card-body text-center p-3">
                <i class="bi bi-<?= $c[2] ?> fs-3" style="color:<?= $c[3] ?>"></i>
                <div class="kpi-value"><?= $c[1] ?></div>
                <div class="kpi-label"><?= $c[0] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div class="row">
    <div class="col-lg-8 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-bar-chart-stacked"></i> Πιστοποιητικά ανά Τύπο</strong></div>
            <div class="card-body"><canvas id="chartCertByType"></canvas></div></div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-pie-chart"></i> Κατάσταση</strong></div>
            <div class="card-body"><canvas id="chartCertStatus"></canvas></div></div>
    </div>
</div>
<?php if (!empty($tableData['missing'])): ?>
<div class="card mb-3">
    <div class="card-header"><strong><i class="bi bi-exclamation-triangle text-danger"></i> Ελλείποντα Υποχρεωτικά Πιστοποιητικά</strong></div>
    <div class="card-body table-responsive">
        <table class="table table-sm table-striped table-ranking">
            <thead><tr><th>Εθελοντής</th><th>Email</th><th>Τμήμα</th><th>Πιστοποιητικό</th></tr></thead>
            <tbody>
            <?php foreach ($tableData['missing'] as $m): ?>
            <tr><td><?= h($m['name']) ?></td><td><?= h($m['email']) ?></td><td><?= h($m['dept'] ?? '-') ?></td><td><span class="badge bg-danger"><?= h($m['cert_type']) ?></span></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php elseif ($activeTab === 'inventory'): ?>
<div class="row mb-4">
    <?php
    $iCards = [
        ['Σύνολο', $kpi['total'], 'box-seam', '#4e73df'],
        ['Διαθέσιμα', $kpi['available'], 'check-circle', '#1cc88a'],
        ['Δεσμευμένα', $kpi['booked'], 'lock', '#f6c23e'],
        ['Εκπρόθεσμα', $kpi['overdue'], 'exclamation-triangle', '#e74a3b'],
        ['Απολεσθέντα', $kpi['lost'], 'x-octagon', '#858796'],
    ];
    foreach ($iCards as $c): ?>
    <div class="col mb-3">
        <div class="card kpi-card" style="border-top-color:<?= $c[3] ?>">
            <div class="card-body text-center p-3">
                <i class="bi bi-<?= $c[2] ?> fs-3" style="color:<?= $c[3] ?>"></i>
                <div class="kpi-value"><?= number_format($c[1]) ?></div>
                <div class="kpi-label"><?= $c[0] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div class="row">
    <div class="col-lg-4 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-pie-chart"></i> Κατά Κατάσταση</strong></div>
            <div class="card-body"><canvas id="chartInvStatus"></canvas></div></div>
    </div>
    <div class="col-lg-8 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-bar-chart"></i> Κατά Κατηγορία</strong></div>
            <div class="card-body"><canvas id="chartInvCategory"></canvas></div></div>
    </div>
</div>
<div class="row">
    <div class="col-12 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-graph-up"></i> Κρατήσεις / Μήνα</strong></div>
            <div class="card-body"><canvas id="chartInvBookings"></canvas></div></div>
    </div>
</div>

<?php elseif ($activeTab === 'system'): ?>
<div class="row mb-4">
    <?php
    $sCards = [
        ['Ενέργειες Audit', $kpi['audit_total'], 'journal-text', '#4e73df'],
        ['Email Σταλμένα', $kpi['emails_sent'], 'envelope-check', '#1cc88a'],
        ['Email Αποτυχημένα', $kpi['emails_failed'], 'envelope-x', '#e74a3b'],
        ['Ειδοποιήσεις', $kpi['notif_total'], 'bell', '#f6c23e'],
        ['Αναγνωσμένες', $kpi['notif_read'], 'bell-fill', '#36b9cc'],
    ];
    foreach ($sCards as $c): ?>
    <div class="col mb-3">
        <div class="card kpi-card" style="border-top-color:<?= $c[3] ?>">
            <div class="card-body text-center p-3">
                <i class="bi bi-<?= $c[2] ?> fs-3" style="color:<?= $c[3] ?>"></i>
                <div class="kpi-value"><?= number_format($c[1]) ?></div>
                <div class="kpi-label"><?= $c[0] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div class="row">
    <div class="col-lg-8 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-graph-up"></i> Audit Ενέργειες / Μήνα</strong></div>
            <div class="card-body"><canvas id="chartAuditMonthly"></canvas></div></div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-pie-chart"></i> Τύπος Ενέργειας</strong></div>
            <div class="card-body"><canvas id="chartAuditActions"></canvas></div></div>
    </div>
</div>
<div class="row">
    <div class="col-12 mb-3">
        <div class="card chart-card"><div class="card-header"><strong><i class="bi bi-bar-chart"></i> Newsletter Αποστολές / Μήνα</strong></div>
            <div class="card-body"><canvas id="chartNewsletterMonthly"></canvas></div></div>
    </div>
</div>
<?php endif; ?>

<!-- ==================== CHART.JS INITIALIZATION ==================== -->
<script>
const COLORS = ['#4e73df','#1cc88a','#36b9cc','#f6c23e','#e74a3b','#858796','#5a5c69','#2e59d9','#17a673','#2c9faf','#fd7e14','#6f42c1'];
const STATUS_COLORS = {'DRAFT':'#858796','OPEN':'#4e73df','CLOSED':'#f6c23e','COMPLETED':'#1cc88a','CANCELED':'#e74a3b'};
const PARTICIPATION_COLORS = {'PENDING':'#f6c23e','APPROVED':'#1cc88a','REJECTED':'#e74a3b','CANCELED_BY_USER':'#858796','CANCELED_BY_ADMIN':'#5a5c69'};
const GR_MONTHS = <?= json_encode($greekMonths) ?>;

Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.plugins.legend.labels.usePointStyle = true;

function monthLabel(ym) {
    const parts = ym.split('-');
    return GR_MONTHS[parseInt(parts[1])-1] + ' ' + parts[0].slice(2);
}

function mc(id, type, data, options = {}) {
    const el = document.getElementById(id);
    if (!el) return null;
    return new Chart(el, { type, data, options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        ...options
    }});
}

<?php if ($activeTab === 'overview'): ?>
// Monthly Trends
(function(){
    const raw = <?= json_encode($chartData['monthly'] ?? []) ?>;
    mc('chartMonthly', 'line', {
        labels: raw.map(r => monthLabel(r.month)),
        datasets: [
            {label:'Αποστολές', data:raw.map(r=>r.missions), borderColor:'#4e73df', backgroundColor:'rgba(78,115,223,.1)', fill:true, tension:0.3},
            {label:'Εθελοντές', data:raw.map(r=>r.volunteers), borderColor:'#1cc88a', backgroundColor:'rgba(28,200,138,.1)', fill:true, tension:0.3},
            {label:'Ώρες', data:raw.map(r=>r.hours), borderColor:'#f6c23e', backgroundColor:'rgba(246,194,62,.1)', fill:true, tension:0.3, yAxisID:'y1'}
        ]
    }, {scales:{y:{beginAtZero:true,position:'left',title:{display:true,text:'Αριθμός'}},y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false},title:{display:true,text:'Ώρες'}}}});
})();

// Status Distribution
(function(){
    const raw = <?= json_encode($chartData['statusDist'] ?? []) ?>;
    mc('chartStatusDist', 'doughnut', {
        labels: raw.map(r => r.status),
        datasets: [{data: raw.map(r => r.cnt), backgroundColor: raw.map(r => STATUS_COLORS[r.status] || '#858796')}]
    });
})();

// Hours by Department
(function(){
    const raw = <?= json_encode($chartData['hoursByDept'] ?? []) ?>;
    mc('chartHoursDept', 'bar', {
        labels: raw.map(r => r.name),
        datasets: [{label:'Ώρες', data:raw.map(r=>r.hours), backgroundColor:COLORS}]
    }, {indexAxis:'horizontal', plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true}}});
})();

<?php elseif ($activeTab === 'missions'): ?>
// Missions per month by status (stacked)
(function(){
    const raw = <?= json_encode($chartData['monthlyStatus'] ?? []) ?>;
    const months = [...new Set(raw.map(r => r.month))].sort();
    const statuses = [...new Set(raw.map(r => r.status))];
    const datasets = statuses.map(s => ({
        label: s, backgroundColor: STATUS_COLORS[s] || '#858796',
        data: months.map(m => { const f = raw.find(r => r.month===m && r.status===s); return f ? f.cnt : 0; })
    }));
    mc('chartMissionsMonthly', 'bar', {labels: months.map(monthLabel), datasets}, {scales:{x:{stacked:true},y:{stacked:true,beginAtZero:true}}});
})();

// By Type (pie)
(function(){
    const raw = <?= json_encode($chartData['byType'] ?? []) ?>;
    mc('chartMissionsByType', 'pie', {
        labels: raw.map(r=>r.name), datasets:[{data:raw.map(r=>r.cnt), backgroundColor:raw.map(r=>r.color||COLORS[0])}]
    });
})();

// By Department (pie)
(function(){
    const raw = <?= json_encode($chartData['byDept'] ?? []) ?>;
    mc('chartMissionsByDept', 'pie', {
        labels: raw.map(r=>r.name), datasets:[{data:raw.map(r=>r.cnt), backgroundColor:COLORS}]
    });
})();

// Fill Rate
(function(){
    const raw = <?= json_encode($chartData['fillRate'] ?? []) ?>;
    mc('chartFillRate', 'bar', {
        labels: raw.map(r=>monthLabel(r.month)),
        datasets:[{label:'Πληρότητα %', data:raw.map(r=>r.fill_pct), backgroundColor:'#36b9cc'}]
    }, {plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true,max:100}}});
})();

<?php elseif ($activeTab === 'volunteers'): ?>
// By Type
(function(){
    const raw = <?= json_encode($chartData['byType'] ?? []) ?>;
    const typeLabels = {'VOLUNTEER':'Εθελοντής','TRAINEE_RESCUER':'Δόκιμος Διασώστης','RESCUER':'Διασώστης'};
    mc('chartVolByType', 'doughnut', {
        labels: raw.map(r=>typeLabels[r.type]||r.type), datasets:[{data:raw.map(r=>r.cnt), backgroundColor:COLORS}]
    });
})();

// By Department
(function(){
    const raw = <?= json_encode($chartData['byDept'] ?? []) ?>;
    mc('chartVolByDept', 'bar', {
        labels: raw.map(r=>r.name), datasets:[{label:'Εθελοντές', data:raw.map(r=>r.cnt), backgroundColor:COLORS}]
    }, {plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}});
})();

// Registrations Monthly
(function(){
    const raw = <?= json_encode($chartData['regsMonthly'] ?? []) ?>;
    mc('chartRegsMonthly', 'line', {
        labels: raw.map(r=>monthLabel(r.month)),
        datasets:[{label:'Νέες Εγγραφές', data:raw.map(r=>r.cnt), borderColor:'#4e73df', backgroundColor:'rgba(78,115,223,.15)', fill:true, tension:0.3}]
    }, {plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}});
})();

<?php elseif ($activeTab === 'participation'): ?>
// Monthly
(function(){
    const raw = <?= json_encode($chartData['monthly'] ?? []) ?>;
    mc('chartPartMonthly', 'line', {
        labels: raw.map(r=>monthLabel(r.month)),
        datasets:[{label:'Αιτήσεις', data:raw.map(r=>r.cnt), borderColor:'#4e73df', backgroundColor:'rgba(78,115,223,.15)', fill:true, tension:0.3}]
    }, {plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}});
})();

// Status Dist
(function(){
    const raw = <?= json_encode($chartData['statusDist'] ?? []) ?>;
    mc('chartPartStatus', 'doughnut', {
        labels: raw.map(r=>r.status), datasets:[{data:raw.map(r=>r.cnt), backgroundColor:raw.map(r=>PARTICIPATION_COLORS[r.status]||'#858796')}]
    });
})();

// Attendance by dept
(function(){
    const raw = <?= json_encode($chartData['attByDept'] ?? []) ?>;
    mc('chartAttByDept', 'bar', {
        labels: raw.map(r=>r.name),
        datasets:[
            {label:'Παρόντες', data:raw.map(r=>r.attended), backgroundColor:'#1cc88a'},
            {label:'Εγκεκριμένοι', data:raw.map(r=>r.total_approved), backgroundColor:'#36b9cc'}
        ]
    }, {scales:{y:{beginAtZero:true}}});
})();

// Response Time
(function(){
    const raw = <?= json_encode($chartData['responseTime'] ?? []) ?>;
    mc('chartRespTime', 'bar', {
        labels: raw.map(r=>monthLabel(r.month)),
        datasets:[{label:'Ώρες', data:raw.map(r=>r.avg_hours), backgroundColor:'#f6c23e'}]
    }, {plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}});
})();

<?php elseif ($activeTab === 'training'): ?>
// By Category
(function(){
    const raw = <?= json_encode($chartData['examByCategory'] ?? []) ?>;
    mc('chartExamByCat', 'bar', {
        labels: raw.map(r=>r.name),
        datasets:[
            {label:'Προσπάθειες', data:raw.map(r=>r.attempts), backgroundColor:'#4e73df'},
            {label:'Επιτυχίες', data:raw.map(r=>r.passed_cnt), backgroundColor:'#1cc88a'},
            {label:'Μ.Ο. %', data:raw.map(r=>r.avg_score), backgroundColor:'#f6c23e', yAxisID:'y1'}
        ]
    }, {scales:{y:{beginAtZero:true,position:'left'},y1:{beginAtZero:true,max:100,position:'right',grid:{drawOnChartArea:false}}}});
})();

// Monthly
(function(){
    const raw = <?= json_encode($chartData['monthly'] ?? []) ?>;
    mc('chartTrainMonthly', 'line', {
        labels: raw.map(r=>monthLabel(r.month)),
        datasets:[
            {label:'Προσπάθειες', data:raw.map(r=>r.attempts), borderColor:'#4e73df', tension:0.3},
            {label:'Επιτυχίες', data:raw.map(r=>r.passed_cnt), borderColor:'#1cc88a', tension:0.3}
        ]
    }, {scales:{y:{beginAtZero:true}}});
})();

// Pass/Fail
(function(){
    const raw = <?= json_encode($chartData['passFail'] ?? []) ?>;
    mc('chartPassFail', 'doughnut', {
        labels: raw.map(r=>r.result), datasets:[{data:raw.map(r=>r.cnt), backgroundColor:['#1cc88a','#e74a3b']}]
    });
})();

<?php elseif ($activeTab === 'certificates'): ?>
// By Type (stacked)
(function(){
    const raw = <?= json_encode($chartData['byType'] ?? []) ?>;
    mc('chartCertByType', 'bar', {
        labels: raw.map(r=>r.name),
        datasets:[
            {label:'Ενεργά', data:raw.map(r=>r.active_cnt), backgroundColor:'#1cc88a'},
            {label:'Λήγουν', data:raw.map(r=>r.expiring_cnt), backgroundColor:'#f6c23e'},
            {label:'Ληγμένα', data:raw.map(r=>r.expired_cnt), backgroundColor:'#e74a3b'}
        ]
    }, {scales:{x:{stacked:true},y:{stacked:true,beginAtZero:true}}});
})();

// Status Doughnut
(function(){
    mc('chartCertStatus', 'doughnut', {
        labels: ['Ενεργά','Λήγουν (30ημ.)','Ληγμένα'],
        datasets:[{data:[<?= (int)$kpi['active'] ?>,<?= (int)$kpi['expiring'] ?>,<?= (int)$kpi['expired'] ?>], backgroundColor:['#1cc88a','#f6c23e','#e74a3b']}]
    });
})();

<?php elseif ($activeTab === 'inventory'): ?>
// By Status
(function(){
    const raw = <?= json_encode($chartData['byStatus'] ?? []) ?>;
    mc('chartInvStatus', 'doughnut', {
        labels: raw.map(r=>r.status), datasets:[{data:raw.map(r=>r.cnt), backgroundColor:COLORS}]
    });
})();

// By Category
(function(){
    const raw = <?= json_encode($chartData['byCategory'] ?? []) ?>;
    mc('chartInvCategory', 'bar', {
        labels: raw.map(r=>r.name), datasets:[{label:'Αντικείμενα', data:raw.map(r=>r.cnt), backgroundColor:COLORS}]
    }, {plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}});
})();

// Bookings Monthly
(function(){
    const raw = <?= json_encode($chartData['bookingsMonthly'] ?? []) ?>;
    mc('chartInvBookings', 'line', {
        labels: raw.map(r=>monthLabel(r.month)),
        datasets:[{label:'Κρατήσεις', data:raw.map(r=>r.cnt), borderColor:'#4e73df', backgroundColor:'rgba(78,115,223,.15)', fill:true, tension:0.3}]
    }, {plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}});
})();

<?php elseif ($activeTab === 'system'): ?>
// Audit Monthly
(function(){
    const raw = <?= json_encode($chartData['auditMonthly'] ?? []) ?>;
    mc('chartAuditMonthly', 'line', {
        labels: raw.map(r=>monthLabel(r.month)),
        datasets:[{label:'Ενέργειες', data:raw.map(r=>r.cnt), borderColor:'#4e73df', backgroundColor:'rgba(78,115,223,.15)', fill:true, tension:0.3}]
    }, {plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}});
})();

// Audit Actions
(function(){
    const raw = <?= json_encode($chartData['auditByAction'] ?? []) ?>;
    mc('chartAuditActions', 'doughnut', {
        labels: raw.map(r=>r.action), datasets:[{data:raw.map(r=>r.cnt), backgroundColor:COLORS}]
    });
})();

// Newsletter Monthly
(function(){
    const raw = <?= json_encode($chartData['newsletterMonthly'] ?? []) ?>;
    mc('chartNewsletterMonthly', 'bar', {
        labels: raw.map(r=>monthLabel(r.month)),
        datasets:[
            {label:'Σταλμένα', data:raw.map(r=>r.sent), backgroundColor:'#1cc88a'},
            {label:'Αποτυχημένα', data:raw.map(r=>r.failed), backgroundColor:'#e74a3b'}
        ]
    }, {scales:{y:{beginAtZero:true}}});
})();
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
