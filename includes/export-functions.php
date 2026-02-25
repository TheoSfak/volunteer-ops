<?php
/**
 * Export Helper Functions
 * CSV export utilities for missions, volunteers, participations, and statistics
 */

// Prevent direct access
if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Export missions to CSV
 */
function exportMissionsToCsv($filters = []) {
    $where = ['m.deleted_at IS NULL'];
    $params = [];
    
    if (!empty($filters['status'])) {
        $where[] = 'm.status = ?';
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['department_id'])) {
        $where[] = 'm.department_id = ?';
        $params[] = $filters['department_id'];
    }
    
    if (!empty($filters['mission_type'])) {
        $where[] = 'm.mission_type_id = ?';
        $params[] = (int) $filters['mission_type'];
    }
    
    if (!empty($filters['search'])) {
        $where[] = '(m.title LIKE ? OR m.description LIKE ? OR m.location LIKE ?)';
        $term = '%' . $filters['search'] . '%';
        $params = array_merge($params, [$term, $term, $term]);
    }
    
    if (!empty($filters['start_date'])) {
        $where[] = 'm.start_datetime >= ?';
        $params[] = $filters['start_date'] . ' 00:00:00';
    }
    
    if (!empty($filters['end_date'])) {
        $where[] = 'm.end_datetime <= ?';
        $params[] = $filters['end_date'] . ' 23:59:59';
    }
    
    $sql = "SELECT 
                m.id,
                m.title,
                m.description,
                mt.name AS type_name,
                d.name AS department,
                m.location,
                m.start_datetime,
                m.end_datetime,
                m.status,
                u.name AS responsible_name,
                m.created_at,
                (SELECT COUNT(*) FROM shifts WHERE mission_id = m.id) AS shift_count,
                (SELECT COUNT(*) FROM shifts s JOIN participation_requests pr ON pr.shift_id = s.id
                 WHERE s.mission_id = m.id AND pr.status = 'APPROVED') AS volunteer_count
            FROM missions m
            LEFT JOIN departments d ON m.department_id = d.id
            LEFT JOIN mission_types mt ON m.mission_type_id = mt.id
            LEFT JOIN users u ON m.responsible_user_id = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY m.start_datetime DESC";
    
    $missions = dbFetchAll($sql, $params);
    
    // Clean output buffer before sending CSV
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="missions_' . date('Y-m-d_His') . '.csv"');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // UTF-8 BOM for Greek characters
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // CSV headers
    fputcsv($output, [
        'ID',
        'Τίτλος',
        'Περιγραφή',
        'Τύπος',
        'Τμήμα',
        'Τοποθεσία',
        'Έναρξη',
        'Λήξη',
        'Κατάσταση',
        'Υπεύθυνος',
        'Βάρδιες',
        'Εγκεκριμένοι Εθελοντές',
        'Δημιουργήθηκε'
    ]);
    
    // Data rows
    foreach ($missions as $mission) {
        fputcsv($output, [
            $mission['id'],
            $mission['title'],
            $mission['description'],
            $mission['type_name'] ?? '',
            $mission['department'],
            $mission['location'],
            formatDateTime($mission['start_datetime']),
            formatDateTime($mission['end_datetime']),
            $GLOBALS['STATUS_LABELS'][$mission['status']] ?? $mission['status'],
            $mission['responsible_name'] ?? '',
            $mission['shift_count'],
            $mission['volunteer_count'],
            formatDateTime($mission['created_at'])
        ]);
    }
    
    fclose($output);
    exit;
}

/**
 * Export volunteers to CSV (all fields incl. volunteer_profiles)
 */
function exportVolunteersToCsv($filters = []) {
    $where = ['u.is_active = 1'];
    $params = [];

    if (!empty($filters['role'])) {
        $where[] = 'u.role = ?';
        $params[] = $filters['role'];
    }
    if (!empty($filters['department_id'])) {
        $where[] = 'u.department_id = ?';
        $params[] = $filters['department_id'];
    }

    $sql = "SELECT
                u.id,
                u.name,
                u.email,
                u.phone,
                u.id_card,
                u.amka,
                u.driving_license,
                u.vehicle_plate,
                u.pants_size,
                u.shirt_size,
                u.blouse_size,
                u.fleece_size,
                u.registry_epidrasis,
                u.registry_ggpp,
                u.role,
                u.volunteer_type,
                u.is_active,
                u.total_points,
                u.department_id,
                d.name  AS department,
                wh.name AS warehouse,
                vp.address,
                vp.city,
                vp.postal_code,
                vp.emergency_contact_name,
                vp.emergency_contact_phone,
                vp.blood_type,
                vp.bio,
                vp.medical_notes,
                vp.available_weekdays,
                vp.available_weekends,
                vp.available_nights,
                vp.has_driving_license,
                vp.has_first_aid
            FROM users u
            LEFT JOIN departments d  ON u.department_id  = d.id
            LEFT JOIN departments wh ON u.warehouse_id   = wh.id
            LEFT JOIN volunteer_profiles vp ON vp.user_id = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY u.name";

    $volunteers = dbFetchAll($sql, $params);

    if (ob_get_level()) ob_end_clean();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="volunteers_' . date('Y-m-d_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    $roleLabels = [
        ROLE_SYSTEM_ADMIN    => 'SYSTEM_ADMIN',
        ROLE_DEPARTMENT_ADMIN => 'DEPARTMENT_ADMIN',
        ROLE_SHIFT_LEADER    => 'SHIFT_LEADER',
        ROLE_VOLUNTEER       => 'VOLUNTEER',
    ];
    $vtypes = ['VOLUNTEER' => 'VOLUNTEER', 'TRAINEE_RESCUER' => 'TRAINEE_RESCUER', 'RESCUER' => 'RESCUER'];

    fputcsv($out, [
        'ID', 'Όνομα', 'Email', 'Τηλέφωνο', 'Ταυτότητα', 'ΑΜΚΑ',
        'Δίπλωμα Οδήγησης', 'Πινακίδα Οχήματος',
        'Παντελόνι', 'Μπλούζα', 'Μπλάκετ', 'Fleece',
        'Μητρώο Επίδρασης', 'Μητρώο ΓΓΠΠ',
        'Ρόλος', 'Τύπος Εθελοντή', 'Ενεργός', 'Πόντοι',
        'Τμήμα ID', 'Τμήμα', 'Αποθήκη/Παράρτημα',
        'Διεύθυνση', 'Πόλη', 'ΤΚ',
        'Επαφή Έκτακτης Ανάγκης', 'Τηλ. Επαφής Έκτακτης',
        'Ομάδα Αίματος', 'Βιογραφικό', 'Ιατρικές Σημειώσεις',
        'Διαθ. Καθημερινές', 'Διαθ. Σαββ/κα', 'Διαθ. Βράδια',
        'Έχει Δίπλωμα Οδήγησης', 'Έχει Πρώτες Βοήθειες',
    ]);

    $yn = fn($v) => $v ? 'Ναι' : 'Όχι';

    foreach ($volunteers as $v) {
        fputcsv($out, [
            $v['id'],
            $v['name'],
            $v['email'],
            $v['phone'],
            $v['id_card'],
            $v['amka'],
            $v['driving_license'],
            $v['vehicle_plate'],
            $v['pants_size'],
            $v['shirt_size'],
            $v['blouse_size'],
            $v['fleece_size'],
            $v['registry_epidrasis'],
            $v['registry_ggpp'],
            $roleLabels[$v['role']] ?? $v['role'],
            $v['volunteer_type'] ?? 'VOLUNTEER',
            $yn($v['is_active']),
            $v['total_points'],
            $v['department_id'],
            $v['department'],
            $v['warehouse'],
            $v['address'],
            $v['city'],
            $v['postal_code'],
            $v['emergency_contact_name'],
            $v['emergency_contact_phone'],
            $v['blood_type'],
            $v['bio'],
            $v['medical_notes'],
            $yn($v['available_weekdays']),
            $yn($v['available_weekends']),
            $yn($v['available_nights']),
            $yn($v['has_driving_license']),
            $yn($v['has_first_aid']),
        ]);
    }

    fclose($out);
    exit;
}


/**
 * Export participations to CSV
 */
function exportParticipationsToCsv($filters = []) {
    $where = ['1=1'];
    $params = [];
    
    if (!empty($filters['status'])) {
        $where[] = 'pr.status = ?';
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['mission_id'])) {
        $where[] = 's.mission_id = ?';
        $params[] = $filters['mission_id'];
    }
    
    if (!empty($filters['volunteer_id'])) {
        $where[] = 'pr.volunteer_id = ?';
        $params[] = $filters['volunteer_id'];
    }
    
    $sql = "SELECT 
                pr.id,
                u.name AS volunteer_name,
                u.email AS volunteer_email,
                m.title AS mission_title,
                s.start_time,
                s.end_time,
                pr.status,
                pr.attended,
                pr.actual_hours,
                pr.notes,
                pr.admin_notes,
                pr.created_at
            FROM participation_requests pr
            INNER JOIN users u ON pr.volunteer_id = u.id
            INNER JOIN shifts s ON pr.shift_id = s.id
            INNER JOIN missions m ON s.mission_id = m.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY pr.created_at DESC";
    
    $participations = dbFetchAll($sql, $params);
    
    // Clean output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="participations_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, [
        'ID',
        'Εθελοντής',
        'Email',
        'Αποστολή',
        'Έναρξη Βάρδιας',
        'Λήξη Βάρδιας',
        'Κατάσταση',
        'Παρουσία',
        'Ώρες',
        'Σημειώσεις Εθελοντή',
        'Σημειώσεις Admin',
        'Αίτηση'
    ]);
    
    foreach ($participations as $p) {
        fputcsv($output, [
            $p['id'],
            $p['volunteer_name'],
            $p['volunteer_email'],
            $p['mission_title'],
            formatDateTime($p['start_time']),
            formatDateTime($p['end_time']),
            $GLOBALS['PARTICIPATION_LABELS'][$p['status']] ?? $p['status'],
            $p['attended'] ? 'Ναι' : 'Όχι',
            $p['actual_hours'] ?? '-',
            $p['notes'] ?? '',
            $p['admin_notes'] ?? '',
            formatDateTime($p['created_at'])
        ]);
    }
    
    fclose($output);
    exit;
}

/**
 * Export statistics to CSV
 */
function exportStatisticsToCsv($period = 'monthly') {
    if ($period === 'monthly') {
        $sql = "SELECT 
                    DATE_FORMAT(m.start_datetime, '%Y-%m') AS period,
                    DATE_FORMAT(m.start_datetime, '%M %Y') AS period_label,
                    COUNT(DISTINCT m.id) AS total_missions,
                    COUNT(DISTINCT pr.id) AS total_participations,
                    COUNT(DISTINCT CASE WHEN pr.attended = 1 THEN pr.id END) AS attended_count,
                    SUM(pr.actual_hours) AS total_hours,
                    COUNT(DISTINCT pr.volunteer_id) AS unique_volunteers
                FROM missions m
                LEFT JOIN shifts s ON m.id = s.mission_id
                LEFT JOIN participation_requests pr ON s.id = pr.shift_id AND pr.status = '" . PARTICIPATION_APPROVED . "'
                WHERE m.start_datetime >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(m.start_datetime, '%Y-%m')
                ORDER BY period DESC";
    } else {
        $sql = "SELECT 
                    YEAR(m.start_datetime) AS period,
                    YEAR(m.start_datetime) AS period_label,
                    COUNT(DISTINCT m.id) AS total_missions,
                    COUNT(DISTINCT pr.id) AS total_participations,
                    COUNT(DISTINCT CASE WHEN pr.attended = 1 THEN pr.id END) AS attended_count,
                    SUM(pr.actual_hours) AS total_hours,
                    COUNT(DISTINCT pr.volunteer_id) AS unique_volunteers
                FROM missions m
                LEFT JOIN shifts s ON m.id = s.mission_id
                LEFT JOIN participation_requests pr ON s.id = pr.shift_id AND pr.status = '" . PARTICIPATION_APPROVED . "'
                GROUP BY YEAR(m.start_datetime)
                ORDER BY period DESC";
    }
    
    $stats = dbFetchAll($sql);
    
    // Clean output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="statistics_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, [
        'Περίοδος',
        'Αποστολές',
        'Συμμετοχές',
        'Παρουσίες',
        'Ώρες',
        'Μοναδικοί Εθελοντές'
    ]);
    
    foreach ($stats as $stat) {
        fputcsv($output, [
            $stat['period_label'],
            $stat['total_missions'],
            $stat['total_participations'],
            $stat['attended_count'],
            $stat['total_hours'] ?? 0,
            $stat['unique_volunteers']
        ]);
    }
    
    fclose($output);
    exit;
}
