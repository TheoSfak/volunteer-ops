<?php
/**
 * Import Helper Functions
 * CSV import utilities for volunteers
 */

/**
 * Parse CSV file with UTF-8 BOM support
 */
function parseCsvFile($filePath) {
    $rows = [];
    $handle = fopen($filePath, 'r');
    
    if ($handle === false) {
        return ['success' => false, 'error' => 'Αδυναμία ανάγνωσης αρχείου.'];
    }
    
    // Read first line and remove BOM if present
    $firstLine = fgets($handle);
    if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
        $firstLine = substr($firstLine, 3);
    }
    rewind($handle);
    fseek($handle, 3); // Skip BOM
    
    // Read headers
    $headers = fgetcsv($handle);
    if ($headers === false) {
        fclose($handle);
        return ['success' => false, 'error' => 'Το αρχείο δεν περιέχει headers.'];
    }
    
    // Read data rows
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) === count($headers)) {
            $rows[] = array_combine($headers, $row);
        }
    }
    
    fclose($handle);
    
    return [
        'success' => true,
        'headers' => $headers,
        'rows' => $rows
    ];
}

/**
 * Validate volunteer data row
 * Expected fields: Όνομα, Email, Τηλέφωνο, Τμήμα ID, Ρόλος
 */
function validateVolunteerData($row, $rowNumber) {
    $errors = [];
    
    // Convert to array if needed
    if (is_object($row)) {
        $row = (array) $row;
    }
    
    // Get values by index (0-based)
    $values = array_values($row);
    
    // Field 0: Name
    if (empty($values[0])) {
        $errors[] = "Γραμμή $rowNumber: Το όνομα είναι υποχρεωτικό.";
    }
    
    // Field 1: Email
    if (empty($values[1])) {
        $errors[] = "Γραμμή $rowNumber: Το email είναι υποχρεωτικό.";
    } elseif (!filter_var($values[1], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Γραμμή $rowNumber: Μη έγκυρο email ({$values[1]}).";
    } else {
        // Check if email already exists
        $existing = dbFetchOne("SELECT id FROM users WHERE email = ?", [$values[1]]);
        if ($existing) {
            $errors[] = "Γραμμή $rowNumber: Το email {$values[1]} υπάρχει ήδη.";
        }
    }
    
    // Field 2: Phone (optional)
    if (!empty($values[2]) && !preg_match('/^\d{10}$/', $values[2])) {
        $errors[] = "Γραμμή $rowNumber: Μη έγκυρο τηλέφωνο (πρέπει να είναι 10 ψηφία).";
    }
    
    // Field 3: Department ID
    if (empty($values[3]) || !is_numeric($values[3])) {
        $errors[] = "Γραμμή $rowNumber: Το ID τμήματος είναι υποχρεωτικό.";
    } else {
        $dept = dbFetchOne("SELECT id FROM departments WHERE id = ?", [$values[3]]);
        if (!$dept) {
            $errors[] = "Γραμμή $rowNumber: Το τμήμα με ID {$values[3]} δεν υπάρχει.";
        }
    }
    
    // Field 4: Role
    if (empty($values[4])) {
        $errors[] = "Γραμμή $rowNumber: Ο ρόλος είναι υποχρεωτικός.";
    } elseif (!in_array($values[4], [ROLE_VOLUNTEER, ROLE_SHIFT_LEADER, ROLE_DEPARTMENT_ADMIN, ROLE_SYSTEM_ADMIN])) {
        $errors[] = "Γραμμή $rowNumber: Μη έγκυρος ρόλος ({$values[4]}).";
    }
    
    return $errors;
}

/**
 * Import volunteers from CSV
 */
function importVolunteersFromCsv($rows, $dryRun = false) {
    $results = [
        'success' => 0,
        'failed' => 0,
        'errors' => [],
        'passwords' => []
    ];
    
    foreach ($rows as $index => $row) {
        $rowNumber = $index + 2; // +1 for header, +1 for human-readable
        
        // Validate
        $errors = validateVolunteerData($row, $rowNumber);
        if (!empty($errors)) {
            $results['failed']++;
            $results['errors'] = array_merge($results['errors'], $errors);
            continue;
        }
        
        // Convert to array
        $values = array_values((array) $row);
        
        $name = trim($values[0]);
        $email = trim($values[1]);
        $phone = trim($values[2] ?? '');
        $departmentId = intval($values[3]);
        $role = trim($values[4]);
        
        if ($dryRun) {
            $results['success']++;
            continue;
        }
        
        // Generate random password
        $password = substr(md5(uniqid(rand(), true)), 0, 10);
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            $userId = dbInsert(
                "INSERT INTO users (name, email, phone, password, role, department_id, is_active, created_at, updated_at) 
                 VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
                [$name, $email, $phone, $hashedPassword, $role, $departmentId]
            );
            
            if ($userId) {
                $results['success']++;
                $results['passwords'][] = [
                    'email' => $email,
                    'password' => $password
                ];
                
                // Send welcome email (optional)
                // sendNotification($userId, 'Καλώς ήρθατε', "Ο λογαριασμός σας δημιουργήθηκε. Κωδικός: $password");
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
