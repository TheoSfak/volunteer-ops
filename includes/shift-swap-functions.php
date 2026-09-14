<?php
/**
 * VolunteerOps - Shift swap (αντικατάσταση βάρδιας) helpers
 *
 * Lifecycle of shift_swap_requests.status:
 *   PENDING_RESPONSE — ο εθελοντής που δέχτηκε το αίτημα δεν έχει απαντήσει ακόμα
 *   ACCEPTED         — απάντησε ναι· λείπει η τελική έγκριση διαχειριστή (shift-view.php)
 *   DECLINED / CANCELED / REJECTED / APPROVED — τελικές καταστάσεις
 *
 * Ζουν εδώ και όχι μέσα στο my-participations.php επειδή το dashboard δείχνει
 * και χειρίζεται τα ίδια αιτήματα: δύο αντίγραφα του κώδικα ειδοποιήσεων θα
 * αποκλίνουν την πρώτη φορά που θα αλλάξει ένα μήνυμα.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Αιτήματα που απευθύνονται σε αυτόν τον χρήστη και περιμένουν απάντησή του.
 */
function shiftSwapIncomingRequests(int $userId): array {
    return dbFetchAll(
        "SELECT ssr.*, s.start_time, s.end_time,
                m.id as mission_id, m.title as mission_title, m.location,
                fu.name as requester_name
         FROM shift_swap_requests ssr
         JOIN shifts s ON ssr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         JOIN users fu ON ssr.from_volunteer_id = fu.id
         WHERE ssr.to_volunteer_id = ? AND ssr.status = ?
         ORDER BY ssr.created_at DESC",
        [$userId, SWAP_PENDING_RESPONSE]
    );
}

/**
 * Αιτήματα που άνοιξε ο ίδιος και είναι ακόμα ενεργά — είτε περιμένουν τον άλλο
 * εθελοντή, είτε περιμένουν διαχειριστή αφού εκείνος αποδέχτηκε.
 */
function shiftSwapOutgoingRequests(int $userId): array {
    return dbFetchAll(
        "SELECT ssr.*, s.start_time, s.end_time,
                m.id as mission_id, m.title as mission_title, m.location,
                tu.name as to_volunteer_name
         FROM shift_swap_requests ssr
         JOIN shifts s ON ssr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         JOIN users tu ON ssr.to_volunteer_id = tu.id
         WHERE ssr.from_volunteer_id = ? AND ssr.status IN (?,?)
         ORDER BY s.start_time ASC",
        [$userId, SWAP_PENDING_RESPONSE, SWAP_ACCEPTED]
    );
}

/**
 * Αποδοχή ή άρνηση αιτήματος από τον εθελοντή στον οποίο απευθύνεται.
 *
 * @param array  $responder ο τρέχων χρήστης (χρειάζεται id + name)
 * @param string $response  'accept' ή οτιδήποτε άλλο = άρνηση
 * @return array{level:string,message:string} έτοιμο για setFlash()
 */
function shiftSwapRespond(int $swapId, array $responder, string $response): array {
    $swap = dbFetchOne(
        "SELECT ssr.*, s.start_time, s.end_time, m.title as mission_title
         FROM shift_swap_requests ssr
         JOIN shifts s ON ssr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         WHERE ssr.id = ? AND ssr.to_volunteer_id = ? AND ssr.status = ?",
        [$swapId, $responder['id'], SWAP_PENDING_RESPONSE]
    );

    if (!$swap) {
        return ['level' => 'error', 'message' => 'Δεν βρέθηκε το αίτημα αντικατάστασης.'];
    }

    if ($response === 'accept') {
        dbExecute(
            "UPDATE shift_swap_requests SET status = ?, to_volunteer_responded_at = NOW(), updated_at = NOW() WHERE id = ?",
            [SWAP_ACCEPTED, $swapId]
        );
        logAudit('swap_accepted', 'shift_swap_requests', $swapId);

        $requester = dbFetchOne("SELECT name, email FROM users WHERE id = ?", [$swap['from_volunteer_id']]);
        if ($requester) {
            if (!empty($requester['email']) && isNotificationEnabled('shift_swap_accepted')) {
                sendNotificationEmail('shift_swap_accepted', $requester['email'], [
                    'user_name'        => $requester['name'],
                    'replacement_name' => $responder['name'],
                    'mission_title'    => $swap['mission_title'],
                    'shift_date'       => formatDateTime($swap['start_time'], 'd/m/Y'),
                    'shift_time'       => formatDateTime($swap['start_time'], 'H:i') . ' - ' . formatDateTime($swap['end_time'], 'H:i'),
                ]);
            }
            sendNotification(
                $swap['from_volunteer_id'],
                'Αποδοχή Αντικατάστασης',
                'Ο/Η ' . $responder['name'] . ' αποδέχτηκε το αίτημα αντικατάστασης για: ' . $swap['mission_title'] . '. Αναμένεται έγκριση διαχειριστή.'
            );
        }

        return ['level' => 'success', 'message' => 'Αποδεχτήκατε το αίτημα. Αναμένεται η τελική έγκριση από τον διαχειριστή.'];
    }

    dbExecute(
        "UPDATE shift_swap_requests SET status = ?, to_volunteer_responded_at = NOW(), updated_at = NOW() WHERE id = ?",
        [SWAP_DECLINED, $swapId]
    );
    logAudit('swap_declined', 'shift_swap_requests', $swapId);

    sendNotification(
        $swap['from_volunteer_id'],
        'Άρνηση Αντικατάστασης',
        'Ο/Η ' . $responder['name'] . ' αρνήθηκε το αίτημα αντικατάστασης για: ' . $swap['mission_title'] . '. Μπορείτε να ζητήσετε άλλον εθελοντή.'
    );

    return ['level' => 'warning', 'message' => 'Αρνηθήκατε το αίτημα αντικατάστασης.'];
}

/**
 * Αιτήματα που έχουν ήδη αποδοχή του εθελοντή και περιμένουν την τελική έγκριση
 * διαχειριστή. Μόνο για διαχειριστές — η ίδια λίστα που δείχνει η σελίδα της
 * βάρδιας, χωρίς να χρειάζεται να ξέρεις ποια βάρδια να ανοίξεις.
 *
 * Ολοκληρωμένες αποστολές εξαιρούνται: εκεί η έγκριση είναι μπλοκαρισμένη
 * (shift-view.php), οπότε δεν έχει νόημα να εμφανιστεί κουμπί που θα αρνηθεί.
 *
 * @param int|null $departmentId περιορισμός σε ένα τμήμα (department admin)
 */
function shiftSwapAwaitingAdmin(?int $departmentId = null): array {
    $params = [SWAP_ACCEPTED, STATUS_COMPLETED];
    $departmentFilter = '';
    if ($departmentId) {
        $departmentFilter = 'AND m.department_id = ?';
        $params[] = $departmentId;
    }

    return dbFetchAll(
        "SELECT ssr.*, s.start_time, s.end_time,
                m.id as mission_id, m.title as mission_title, m.location,
                fu.name as from_volunteer_name, tu.name as to_volunteer_name
         FROM shift_swap_requests ssr
         JOIN shifts s ON ssr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         JOIN users fu ON ssr.from_volunteer_id = fu.id
         JOIN users tu ON ssr.to_volunteer_id = tu.id
         WHERE ssr.status = ?
           AND m.status != ?
           AND m.deleted_at IS NULL
           $departmentFilter
         ORDER BY s.start_time ASC",
        $params
    );
}

/**
 * Τελική έγκριση διαχειριστή: εδώ — και μόνο εδώ — αλλάζει πραγματικά η βάρδια.
 * Ο αρχικός εθελοντής βγαίνει, ο αντικαταστάτης μπαίνει εγκεκριμένος.
 *
 * @param array    $admin          ο διαχειριστής που αποφασίζει (id + name)
 * @param int|null $requireShiftId περιορισμός στη βάρδια που βλέπει ο caller
 * @return array{level:string,message:string} έτοιμο για setFlash()
 */
function shiftSwapAdminApprove(int $swapId, array $admin, ?int $requireShiftId = null): array {
    $swap = shiftSwapFetchForAdmin($swapId, $requireShiftId);
    if (!$swap) {
        return ['level' => 'error', 'message' => 'Δεν βρέθηκε το αίτημα αντικατάστασης.'];
    }
    if ($swap['mission_status'] === STATUS_COMPLETED) {
        return ['level' => 'error', 'message' => 'Η αποστολή είναι ολοκληρωμένη. Αλλάξτε πρώτα την κατάσταση σε «Κλειστή» για να κάνετε αλλαγές.'];
    }

    $db = db();
    $db->beginTransaction();
    try {
        // Ο αρχικός εθελοντής βγαίνει από τη βάρδια
        dbExecute(
            "UPDATE participation_requests SET status = ?, updated_at = NOW() WHERE id = ?",
            [PARTICIPATION_CANCELED_BY_USER, $swap['participation_id']]
        );
        // Ο αντικαταστάτης μπαίνει εγκεκριμένος (upsert: μπορεί να έχει παλιά απορριφθείσα αίτηση)
        $existingPr = dbFetchOne(
            "SELECT id FROM participation_requests WHERE shift_id = ? AND volunteer_id = ?",
            [$swap['shift_id'], $swap['to_volunteer_id']]
        );
        if ($existingPr) {
            dbExecute(
                "UPDATE participation_requests SET status = ?, decided_by = ?, decided_at = NOW(), updated_at = NOW() WHERE id = ?",
                [PARTICIPATION_APPROVED, $admin['id'], $existingPr['id']]
            );
        } else {
            dbInsert(
                "INSERT INTO participation_requests (shift_id, volunteer_id, status, decided_by, decided_at, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW(),NOW())",
                [$swap['shift_id'], $swap['to_volunteer_id'], PARTICIPATION_APPROVED, $admin['id']]
            );
        }
        dbExecute(
            "UPDATE shift_swap_requests SET status = ?, decided_by = ?, decided_at = NOW(), updated_at = NOW() WHERE id = ?",
            [SWAP_APPROVED, $admin['id'], $swapId]
        );
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        return ['level' => 'error', 'message' => 'Σφάλμα κατά την έγκριση αντικατάστασης.'];
    }

    logAudit('approve_swap', 'shift_swap_requests', $swapId);

    $shiftUrl = 'shift-view.php?id=' . (int) $swap['shift_id'];
    $vars = [
        'mission_title' => $swap['mission_title'],
        'shift_date'    => formatDateTime($swap['start_time'], 'd/m/Y'),
        'shift_time'    => formatDateTime($swap['start_time'], 'H:i') . ' - ' . formatDateTime($swap['end_time'], 'H:i'),
    ];
    if (!empty($swap['from_email']) && isNotificationEnabled('shift_swap_approved')) {
        sendNotificationEmail('shift_swap_approved', $swap['from_email'],
            array_merge($vars, ['user_name' => $swap['from_volunteer_name'], 'replacement_name' => $swap['to_volunteer_name']]));
    }
    if (!empty($swap['to_email']) && isNotificationEnabled('shift_swap_approved')) {
        sendNotificationEmail('shift_swap_approved', $swap['to_email'],
            array_merge($vars, ['user_name' => $swap['to_volunteer_name'], 'replacement_name' => $swap['to_volunteer_name']]));
    }
    sendNotification($swap['from_volunteer_id'], 'Αντικατάσταση Εγκρίθηκε',
        'Η αντικατάστασή σας από τον/την ' . $swap['to_volunteer_name'] . ' εγκρίθηκε από τον διαχειριστή.', 'success', '', ['url' => $shiftUrl]);
    sendNotification($swap['to_volunteer_id'], 'Εγκρίθηκε η Συμμετοχή σας',
        'Εγκριθήκατε ως αντικατάσταση για τη βάρδια της αποστολής: ' . $swap['mission_title'] . '.', 'success', '', ['url' => $shiftUrl]);

    return ['level' => 'success', 'message' => 'Η αντικατάσταση εγκρίθηκε επιτυχώς.'];
}

/**
 * Απόρριψη από διαχειριστή: η βάρδια μένει ως έχει, ο αρχικός εθελοντής παραμένει.
 *
 * @return array{level:string,message:string} έτοιμο για setFlash()
 */
function shiftSwapAdminReject(int $swapId, array $admin, ?int $requireShiftId = null): array {
    $swap = shiftSwapFetchForAdmin($swapId, $requireShiftId);
    if (!$swap) {
        return ['level' => 'error', 'message' => 'Δεν βρέθηκε το αίτημα αντικατάστασης.'];
    }
    if ($swap['mission_status'] === STATUS_COMPLETED) {
        return ['level' => 'error', 'message' => 'Η αποστολή είναι ολοκληρωμένη. Αλλάξτε πρώτα την κατάσταση σε «Κλειστή» για να κάνετε αλλαγές.'];
    }

    dbExecute(
        "UPDATE shift_swap_requests SET status = ?, decided_by = ?, decided_at = NOW(), updated_at = NOW() WHERE id = ?",
        [SWAP_REJECTED, $admin['id'], $swapId]
    );
    logAudit('reject_swap', 'shift_swap_requests', $swapId);

    $shiftUrl = 'shift-view.php?id=' . (int) $swap['shift_id'];
    sendNotification($swap['from_volunteer_id'], 'Αίτημα Αντικατάστασης Απορρίφθηκε',
        'Ο διαχειριστής απέρριψε το αίτημα αντικατάστασης σας.', 'warning', '', ['url' => $shiftUrl]);
    sendNotification($swap['to_volunteer_id'], 'Αίτημα Αντικατάστασης Απορρίφθηκε',
        'Ο διαχειριστής απέρριψε το αίτημα αντικατάστασης.', 'warning', '', ['url' => $shiftUrl]);

    return ['level' => 'warning', 'message' => 'Το αίτημα αντικατάστασης απορρίφθηκε.'];
}

/**
 * Ένα αίτημα σε κατάσταση ACCEPTED, με ό,τι χρειάζονται οι δύο παραπάνω.
 * Εσωτερικό — η άδεια (isAdmin) ελέγχεται από τον caller.
 */
function shiftSwapFetchForAdmin(int $swapId, ?int $requireShiftId = null): ?array {
    $params = [$swapId, SWAP_ACCEPTED];
    $shiftFilter = '';
    if ($requireShiftId !== null) {
        $shiftFilter = 'AND ssr.shift_id = ?';
        $params[] = $requireShiftId;
    }

    return dbFetchOne(
        "SELECT ssr.*, s.start_time, s.end_time,
                m.title as mission_title, m.status as mission_status,
                fu.name as from_volunteer_name, fu.email as from_email,
                tu.name as to_volunteer_name, tu.email as to_email
         FROM shift_swap_requests ssr
         JOIN shifts s ON ssr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         JOIN users fu ON ssr.from_volunteer_id = fu.id
         JOIN users tu ON ssr.to_volunteer_id = tu.id
         WHERE ssr.id = ? AND ssr.status = ? $shiftFilter",
        $params
    ) ?: null;
}

/**
 * Ακύρωση αιτήματος από αυτόν που το έκανε, όσο δεν έχει κριθεί από διαχειριστή.
 *
 * @return array{level:string,message:string} έτοιμο για setFlash()
 */
function shiftSwapCancel(int $swapId, int $userId): array {
    $swap = dbFetchOne(
        "SELECT id FROM shift_swap_requests WHERE id = ? AND from_volunteer_id = ? AND status IN (?,?)",
        [$swapId, $userId, SWAP_PENDING_RESPONSE, SWAP_ACCEPTED]
    );

    if (!$swap) {
        return ['level' => 'error', 'message' => 'Δεν βρέθηκε ενεργό αίτημα αντικατάστασης προς ακύρωση.'];
    }

    dbExecute(
        "UPDATE shift_swap_requests SET status = ?, updated_at = NOW() WHERE id = ?",
        [SWAP_CANCELED, $swapId]
    );
    logAudit('swap_canceled', 'shift_swap_requests', $swapId);

    return ['level' => 'success', 'message' => 'Το αίτημα αντικατάστασης ακυρώθηκε.'];
}
