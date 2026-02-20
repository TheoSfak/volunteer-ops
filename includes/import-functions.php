<?php
/**
 * Import Helper Functions
 * CSV import utilities for volunteers — all fields
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Parse CSV file with UTF-8 BOM support.
 * Returns associative rows keyed by header names.
 */
function parseCsvFile($filePath) {
    $rows = [];
    $handle = fopen($filePath, 'r');

    if ($handle === false) {
        return ['success' => false, 'error' => 'Αδυναμία ανάγνωσης αρχείου.'];
    }

    // Strip UTF-8 BOM
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    // Auto-detect delimiter: read first line, count tabs vs commas
    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        return ['success' => false, 'error' => 'Το αρχείο δεν περιέχει headers.'];
    }
    $delimiter = substr_count($firstLine, "\t") >= substr_count($firstLine, ',') ? "\t" : ',';
    // Rewind to re-parse headers properly through fgetcsv
    $pos = ftell($handle) - strlen($firstLine);
    // Seek back to start of first line (after BOM)
    fseek($handle, $pos);

    $headers = fgetcsv($handle, 0, $delimiter);
    if ($headers === false) {
        fclose($handle);
        return ['success' => false, 'error' => 'Το αρχείο δεν περιέχει headers.'];
    }

    // Strip stray quotes and whitespace from header names
    $headers = array_map(fn($h) => trim(trim($h), '"'), $headers);

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count($row) === count($headers)) {
            $rows[] = array_combine($headers, $row);
        }
    }

    fclose($handle);
    return ['success' => true, 'headers' => $headers, 'rows' => $rows];
}

/** Convert Ναι/Όχι / 1/0 / yes/no → int */
function _csvBool($v): int {
    $v = mb_strtolower(trim((string)$v));
    return in_array($v, ['ναι', 'yes', '1', 'true']) ? 1 : 0;
}

/** Get optional column value from import row, returns trimmed string or null */
function _col(array $row, string $key): ?string {
    if (!isset($row[$key]) || trim($row[$key]) === '') return null;
    return trim($row[$key]);
}

/**
 * Validate one CSV row.
 * Required columns: Όνομα, Email, Τμήμα ID, Ρόλος
 */
function validateVolunteerData(array $row, int $rowNumber): array {
    $errors = [];
    $validRoles = [ROLE_VOLUNTEER, ROLE_SHIFT_LEADER, ROLE_DEPARTMENT_ADMIN, ROLE_SYSTEM_ADMIN];
    $validTypes = ['VOLUNTEER', 'TRAINEE_RESCUER', 'RESCUER'];

    if (empty($row['Όνομα'])) {
        $errors[] = "Γραμμή $rowNumber: Το όνομα είναι υποχρεωτικό.";
    }

    $email = trim($row['Email'] ?? '');
    if (empty($email)) {
        $errors[] = "Γραμμή $rowNumber: Το email είναι υποχρεωτικό.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Γραμμή $rowNumber: Μη έγκυρο email ($email).";
    }

    $phone = _col($row, 'Τηλέφωνο');
    if ($phone !== null) {
        $phoneClean = preg_replace('/[\s\-\.]+/', '', $phone);
        if (!preg_match('/^\d{10}$/', $phoneClean)) {
            $errors[] = "Γραμμή $rowNumber: Πεδίο 'Τηλέφωνο' — μη έγκυρη τιμή '$phone' (πρέπει να είναι 10 ψηφία, με ή χωρίς κενά).";
        }
    }

    $deptId = trim($row['Τμήμα ID'] ?? '');
    if (empty($deptId) || !is_numeric($deptId)) {
        $errors[] = "Γραμμή $rowNumber: Πεδίο 'Τμήμα ID' — απαιτείται αριθμητικό ID τμήματος (τιμή: '$deptId').";
    } else {
        $dept = dbFetchOne("SELECT id FROM departments WHERE id = ?", [(int)$deptId]);
        if (!$dept) {
            $errors[] = "Γραμμή $rowNumber: Πεδίο 'Τμήμα ID' — δεν βρέθηκε τμήμα με ID $deptId.";
        }
    }

    $role = trim($row['Ρόλος'] ?? '');
    if (empty($role)) {
        $errors[] = "Γραμμή $rowNumber: Πεδίο 'Ρόλος' — υποχρεωτικό.";
    } elseif (!in_array($role, $validRoles)) {
        $errors[] = "Γραμμή $rowNumber: Πεδίο 'Ρόλος' — μη έγκυρη τιμή '$role'. Επιτρεπτοί: " . implode(', ', $validRoles);
    }

    $vtype = _col($row, 'Τύπος Εθελοντή');
    if ($vtype !== null && $vtype !== '0' && !in_array($vtype, $validTypes)) {
        $errors[] = "Γραμμή $rowNumber: Πεδίο 'Τύπος Εθελοντή' — μη έγκυρη τιμή '$vtype'. Επιτρεπτοί: " . implode(', ', $validTypes);
    }

    return $errors;
}

/**
 * Import volunteers from CSV rows.
 * Inserts into users + volunteer_profiles.
 */
function importVolunteersFromCsv(array $rows, bool $dryRun = false): array {
    $results = ['success' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'passwords' => []];

    foreach ($rows as $index => $row) {
        $rowNumber = $index + 2;

        $errors = validateVolunteerData($row, $rowNumber);
        if (!empty($errors)) {
            $results['failed']++;
            $results['errors'] = array_merge($results['errors'], $errors);
            continue;
        }

        if ($dryRun) {
            $results['success']++;
            continue;
        }

        // Skip silently if email already exists
        $email = trim($row['Email']);
        $existing = dbFetchOne("SELECT id FROM users WHERE email = ?", [$email]);
        if ($existing) {
            $results['skipped']++;
            continue;
        }

        $password       = substr(md5(uniqid(rand(), true)), 0, 10);
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // ── users row ──
        $name             = trim($row['Όνομα']);
        $email            = trim($row['Email']); // already checked above
        $phone            = _col($row, 'Τηλέφωνο');
        if ($phone !== null) $phone = preg_replace('/[\s\-\.]+/', '', $phone);
        $deptId           = (int) trim($row['Τμήμα ID']);
        $role             = trim($row['Ρόλος']);
        $rawType          = _col($row, 'Τύπος Εθελοντή');
        $volunteerType    = ($rawType && $rawType !== '0') ? $rawType : 'VOLUNTEER';
        $idCard           = _col($row, 'Ταυτότητα');
        $amka             = _col($row, 'ΑΜΚΑ');
        $drivingLicense   = _col($row, 'Δίπλωμα Οδήγησης');
        $vehiclePlate     = _col($row, 'Πινακίδα Οχήματος');
        $pantsSize        = _col($row, 'Παντελόνι');
        $shirtSize        = _col($row, 'Μπλούζα');
        $blouseSize       = _col($row, 'Μπλάκετ');
        $fleeceSize       = _col($row, 'Fleece');
        $regEpidrasis     = _col($row, 'Μητρώο Επίδρασης');
        $regGgpp          = _col($row, 'Μητρώο ΓΓΠΠ');

        // ── volunteer_profiles row ──
        $address              = _col($row, 'Διεύθυνση');
        $city                 = _col($row, 'Πόλη');
        $postalCode           = _col($row, 'ΤΚ');
        $emergencyName        = _col($row, 'Επαφή Έκτακτης Ανάγκης');
        $emergencyPhone       = _col($row, 'Τηλ. Επαφής Έκτακτης');
        $bloodType            = _col($row, 'Ομάδα Αίματος');
        $bio                  = _col($row, 'Βιογραφικό');
        $medicalNotes         = _col($row, 'Ιατρικές Σημειώσεις');
        $availWeekdays        = isset($row['Διαθ. Καθημερινές'])  ? _csvBool($row['Διαθ. Καθημερινές'])  : 1;
        $availWeekends        = isset($row['Διαθ. Σαββ/κα'])      ? _csvBool($row['Διαθ. Σαββ/κα'])      : 1;
        $availNights          = isset($row['Διαθ. Βράδια'])        ? _csvBool($row['Διαθ. Βράδια'])        : 0;
        $hasDrivingLicense    = isset($row['Έχει Δίπλωμα Οδήγησης']) ? _csvBool($row['Έχει Δίπλωμα Οδήγησης']) : 0;
        $hasFirstAid          = isset($row['Έχει Πρώτες Βοήθειες'])  ? _csvBool($row['Έχει Πρώτες Βοήθειες'])  : 0;

        try {
            $userId = dbInsert(
                "INSERT INTO users
                    (name, email, phone, password, role, volunteer_type, department_id,
                     id_card, amka, driving_license, vehicle_plate,
                     pants_size, shirt_size, blouse_size, fleece_size,
                     registry_epidrasis, registry_ggpp,
                     is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
                [$name, $email, $phone, $hashedPassword, $role, $volunteerType, $deptId,
                 $idCard, $amka, $drivingLicense, $vehiclePlate,
                 $pantsSize, $shirtSize, $blouseSize, $fleeceSize,
                 $regEpidrasis, $regGgpp]
            );

            if ($userId) {
                // Create volunteer_profiles record only if any profile field present
                $hasProfile = ($address || $city || $postalCode || $emergencyName ||
                               $emergencyPhone || $bloodType || $bio || $medicalNotes);
                if ($hasProfile) {
                    dbInsert(
                        "INSERT INTO volunteer_profiles
                            (user_id, address, city, postal_code,
                             emergency_contact_name, emergency_contact_phone,
                             blood_type, bio, medical_notes,
                             available_weekdays, available_weekends, available_nights,
                             has_driving_license, has_first_aid,
                             created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                        [$userId, $address, $city, $postalCode,
                         $emergencyName, $emergencyPhone,
                         $bloodType, $bio, $medicalNotes,
                         $availWeekdays, $availWeekends, $availNights,
                         $hasDrivingLicense, $hasFirstAid]
                    );
                }

                $results['success']++;
                $results['passwords'][] = ['email' => $email, 'password' => $password];
            } else {
                $results['failed']++;
                $results['errors'][] = "Γραμμή $rowNumber: Αποτυχία εισαγωγής.";
            }
        } catch (Exception $e) {
            $results['failed']++;
            $results['errors'][] = "Γραμμή $rowNumber: " . $e->getMessage();
        }
    }

    return $results;
}

