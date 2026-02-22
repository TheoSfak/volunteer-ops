<?php
/**
 * VolunteerOps - Inventory Helper Functions
 * Provides all inventory-related utilities, constants, and business logic.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

// =============================================
// INVENTORY STATUS CONSTANTS
// =============================================
define('INVENTORY_AVAILABLE', 'available');
define('INVENTORY_BOOKED', 'booked');
define('INVENTORY_MAINTENANCE', 'maintenance');
define('INVENTORY_DAMAGED', 'damaged');

define('BOOKING_ACTIVE', 'active');
define('BOOKING_OVERDUE', 'overdue');
define('BOOKING_RETURNED', 'returned');
define('BOOKING_LOST', 'lost');

// =============================================
// GREEK LABELS
// =============================================
const INVENTORY_STATUS_LABELS = [
    'available'   => 'Διαθέσιμο',
    'booked'      => 'Χρεωμένο',
    'maintenance' => 'Συντήρηση',
    'damaged'     => 'Χαλασμένο',
];

const BOOKING_STATUS_LABELS = [
    'active'   => 'Ενεργή',
    'overdue'  => 'Εκπρόθεσμη',
    'returned' => 'Επιστράφηκε',
    'lost'     => 'Χαμένο',
];

const NOTE_TYPE_LABELS = [
    'booking'     => 'Κράτηση',
    'return'      => 'Επιστροφή',
    'maintenance' => 'Συντήρηση',
    'damage'      => 'Βλάβη',
    'general'     => 'Γενικό',
];

const NOTE_PRIORITY_LABELS = [
    'low'    => 'Χαμηλή',
    'medium' => 'Μεσαία',
    'high'   => 'Υψηλή',
    'urgent' => 'Επείγον',
];

const NOTE_STATUS_LABELS = [
    'pending'      => 'Εκκρεμεί',
    'acknowledged' => 'Αναγνωρίσθηκε',
    'in_progress'  => 'Σε Εξέλιξη',
    'resolved'     => 'Επιλύθηκε',
    'archived'     => 'Αρχειοθετήθηκε',
];

// =============================================
// TABLE EXISTENCE CHECK
// =============================================

/**
 * Check if inventory tables exist in the database.
 * Result is cached for the request lifetime.
 */
function inventoryTablesExist() {
    static $exists = null;
    if ($exists !== null) return $exists;
    
    try {
        dbFetchValue("SELECT 1 FROM inventory_items LIMIT 1");
        $exists = true;
    } catch (\PDOException $e) {
        $exists = false;
    }
    return $exists;
}

/**
 * Guard function: redirects to dashboard with a flash message
 * if inventory tables have not been created yet.
 */
function requireInventoryTables() {
    if (!inventoryTablesExist()) {
        setFlash('error', 'Οι πίνακες αποθέματος δεν έχουν δημιουργηθεί. Εκτελέστε πρώτα το <a href="migrate_v3.php">migrate_v3.php</a>.');
        redirect('dashboard.php');
    }
}

// =============================================
// STATUS BADGE HELPERS
// =============================================

/**
 * Render Bootstrap badge for inventory item status
 */
function inventoryStatusBadge($status) {
    $colors = [
        'available'   => 'success',
        'booked'      => 'primary',
        'maintenance' => 'warning',
        'damaged'     => 'danger',
    ];
    $label = INVENTORY_STATUS_LABELS[$status] ?? $status;
    $color = $colors[$status] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . h($label) . '</span>';
}

/**
 * Render Bootstrap badge for booking status
 */
function bookingStatusBadge($status) {
    $colors = [
        'active'   => 'primary',
        'overdue'  => 'danger',
        'returned' => 'success',
        'lost'     => 'dark',
    ];
    $label = BOOKING_STATUS_LABELS[$status] ?? $status;
    $color = $colors[$status] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . h($label) . '</span>';
}

/**
 * Render Bootstrap badge for note priority
 */
function notePriorityBadge($priority) {
    $colors = [
        'low'    => 'secondary',
        'medium' => 'info',
        'high'   => 'warning',
        'urgent' => 'danger',
    ];
    $label = NOTE_PRIORITY_LABELS[$priority] ?? $priority;
    $color = $colors[$priority] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . h($label) . '</span>';
}

// =============================================
// ACCESS CONTROL
// =============================================

/**
 * Check if user has access to a specific inventory item
 */
function checkInventoryAccess($itemId, $userId = null) {
    $userId = $userId ?? getCurrentUserId();
    $user   = getCurrentUser();

    // System admin can access everything
    if ($user['role'] === ROLE_SYSTEM_ADMIN) {
        return true;
    }

    $item = dbFetchOne("SELECT department_id FROM inventory_items WHERE id = ?", [$itemId]);
    if (!$item) return false;

    // Global items (no department) are accessible to all
    if ($item['department_id'] === null) return true;

    // User belongs to this department
    if ($user['department_id'] && $user['department_id'] == $item['department_id']) return true;

    // Check explicit department access grant
    $access = dbFetchOne(
        "SELECT 1 FROM inventory_department_access WHERE user_id = ? AND department_id = ?",
        [$userId, $item['department_id']]
    );
    return !empty($access);
}

/**
 * Check if current user can manage inventory (add/edit/delete items)
 */
function canManageInventory() {
    return isAdmin();
}

// =============================================
// DEPARTMENT FILTERING
// =============================================

/**
 * Apply department filter to an inventory query.
 * System admins can optionally filter by department via session.
 * Department admins/volunteers see only their department + global items.
 *
 * @param string $query SQL query being built
 * @param array  $params Query parameters
 * @param string $alias Table alias for department_id column
 * @return array [$query, $params]
 */
function filterInventoryByDepartment($query, $params = [], $alias = 'i') {
    $user = getCurrentUser();

    if ($user['role'] === ROLE_SYSTEM_ADMIN) {
        // System admin: optional filter from session/GET
        $filter = $_SESSION['inventory_department_filter'] ?? null;
        if ($filter !== null && $filter !== '' && $filter !== 'all') {
            $query   .= " AND ({$alias}.department_id = ? OR {$alias}.department_id IS NULL)";
            $params[] = (int)$filter;
        }
        return [$query, $params];
    }

    // Non-system-admin: filter to own department + global items
    if ($user['department_id']) {
        $query   .= " AND ({$alias}.department_id = ? OR {$alias}.department_id IS NULL)";
        $params[] = $user['department_id'];
    }

    return [$query, $params];
}

/**
 * Get the currently active inventory department filter (for UI)
 */
function getCurrentInventoryDepartment() {
    $user = getCurrentUser();
    if ($user['role'] === ROLE_SYSTEM_ADMIN) {
        return $_SESSION['inventory_department_filter'] ?? 'all';
    }
    return $user['department_id'] ?? null;
}

// =============================================
// ITEM CRUD
// =============================================

/**
 * Get inventory items with filters and pagination support
 */
function getInventoryItems($filters = [], $limit = null, $offset = null) {
    $query = "
        SELECT i.*, 
               c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
               d.name AS dept_name,
               loc.name AS location_name,
               u.name AS creator_name,
               (SELECT COUNT(*) FROM inventory_notes n WHERE n.item_id = i.id AND n.status NOT IN ('resolved', 'archived')) AS open_notes_count
        FROM inventory_items i
        LEFT JOIN inventory_categories c ON i.category_id = c.id
        LEFT JOIN departments d ON i.department_id = d.id
        LEFT JOIN inventory_locations loc ON i.location_id = loc.id
        LEFT JOIN users u ON i.created_by = u.id
        WHERE i.is_active = 1
    ";
    $params = [];

    // Status filter
    if (!empty($filters['status'])) {
        $query   .= " AND i.status = ?";
        $params[] = $filters['status'];
    }

    // Category filter
    if (!empty($filters['category_id'])) {
        $query   .= " AND i.category_id = ?";
        $params[] = (int)$filters['category_id'];
    }

    // Search (barcode, name, description)
    if (!empty($filters['search'])) {
        $query .= " AND (i.name LIKE ? OR i.barcode LIKE ? OR i.description LIKE ?)";
        $search   = '%' . dbEscape($filters['search']) . '%';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    // Department filter (role-based)
    [$query, $params] = filterInventoryByDepartment($query, $params, 'i');

    $query .= " ORDER BY i.name ASC";

    if ($limit !== null) {
        $query .= " LIMIT " . (int)$limit;
        if ($offset !== null) {
            $query .= " OFFSET " . (int)$offset;
        }
    }

    return dbFetchAll($query, $params);
}

/**
 * Count inventory items with same filters (for pagination)
 */
function countInventoryItems($filters = []) {
    $query  = "SELECT COUNT(*) FROM inventory_items i WHERE i.is_active = 1";
    $params = [];

    if (!empty($filters['status'])) {
        $query   .= " AND i.status = ?";
        $params[] = $filters['status'];
    }
    if (!empty($filters['category_id'])) {
        $query   .= " AND i.category_id = ?";
        $params[] = (int)$filters['category_id'];
    }
    if (!empty($filters['search'])) {
        $query .= " AND (i.name LIKE ? OR i.barcode LIKE ? OR i.description LIKE ?)";
        $search   = '%' . dbEscape($filters['search']) . '%';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    [$query, $params] = filterInventoryByDepartment($query, $params, 'i');

    return (int)dbFetchValue($query, $params);
}

/**
 * Get a single inventory item by ID (with joins)
 */
function getInventoryItem($id) {
    return dbFetchOne("
        SELECT i.*, 
               c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
               d.name AS dept_name,
               loc.name AS location_name,
               u.name AS creator_name,
               bu.name AS booked_by_user_name
        FROM inventory_items i
        LEFT JOIN inventory_categories c ON i.category_id = c.id
        LEFT JOIN departments d ON i.department_id = d.id
        LEFT JOIN inventory_locations loc ON i.location_id = loc.id
        LEFT JOIN users u ON i.created_by = u.id
        LEFT JOIN users bu ON i.booked_by_user_id = bu.id
        WHERE i.id = ?
    ", [$id]);
}

/**
 * Get an inventory item by barcode
 */
function getInventoryItemByBarcode($barcode) {
    return dbFetchOne("
        SELECT i.*, 
               c.name AS category_name, c.icon AS category_icon,
               d.name AS dept_name
        FROM inventory_items i
        LEFT JOIN inventory_categories c ON i.category_id = c.id
        LEFT JOIN departments d ON i.department_id = d.id
        WHERE i.barcode = ? AND i.is_active = 1
    ", [$barcode]);
}

// =============================================
// BOOKING FUNCTIONS
// =============================================

/**
 * Create a booking (checkout an item)
 */
function createInventoryBooking($itemId, $userId, $data = []) {
    try {
        $pdo = db();
        $pdo->beginTransaction();

        // Lock the item row
        $item = dbFetchOne(
            "SELECT * FROM inventory_items WHERE id = ? AND status = 'available' FOR UPDATE",
            [$itemId]
        );

        if (!$item) {
            throw new Exception('Το υλικό δεν είναι διαθέσιμο για χρέωση.');
        }

        if (!checkInventoryAccess($itemId, $userId)) {
            throw new Exception('Δεν έχετε πρόσβαση σε αυτό το υλικό.');
        }

        $volunteer = dbFetchOne("SELECT name, phone, email FROM users WHERE id = ?", [$userId]);

        $bookingId = dbInsert("
            INSERT INTO inventory_bookings 
                (item_id, user_id, volunteer_name, volunteer_phone, volunteer_email,
                 mission_location, notes, expected_return_date, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ", [
            $itemId,
            $userId,
            $volunteer['name'],
            $volunteer['phone'] ?? '',
            $volunteer['email'] ?? '',
            $data['mission_location'] ?? '',
            $data['notes'] ?? '',
            !empty($data['expected_return_date']) ? $data['expected_return_date'] : null,
        ]);

        // Note: trigger handles updating inventory_items status

        logAudit('inventory_book', 'inventory_bookings', $bookingId);

        $pdo->commit();
        return ['success' => true, 'booking_id' => $bookingId];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Return an item (complete a booking)
 */
function returnInventoryItem($bookingId, $returnNotes = '') {
    try {
        $pdo = db();
        $pdo->beginTransaction();

        $booking = dbFetchOne("
            SELECT b.*, i.name AS item_name
            FROM inventory_bookings b
            JOIN inventory_items i ON b.item_id = i.id
            WHERE b.id = ? AND b.status IN ('active', 'overdue')
        ", [$bookingId]);

        if (!$booking) {
            throw new Exception('Η χρέωση δεν βρέθηκε ή έχει ήδη ολοκληρωθεί.');
        }

        // Calculate hours
        $start = new DateTime($booking['created_at']);
        $end   = new DateTime();
        $hours = round(($end->getTimestamp() - $start->getTimestamp()) / 3600, 2);

        dbExecute("
            UPDATE inventory_bookings 
            SET status = 'returned', 
                return_date = NOW(),
                returned_by_user_id = ?, 
                return_notes = ?, 
                actual_hours = ?
            WHERE id = ?
        ", [getCurrentUserId(), $returnNotes, $hours, $bookingId]);

        // Note: trigger handles updating inventory_items status back to 'available'

        logAudit('inventory_return', 'inventory_bookings', $bookingId);

        $pdo->commit();
        return ['success' => true, 'hours' => $hours];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get booking history for an item
 */
function getItemBookings($itemId, $limit = 20) {
    return dbFetchAll("
        SELECT b.*, u.name AS user_name, ru.name AS returned_by_name
        FROM inventory_bookings b
        LEFT JOIN users u ON b.user_id = u.id
        LEFT JOIN users ru ON b.returned_by_user_id = ru.id
        WHERE b.item_id = ?
        ORDER BY b.created_at DESC
        LIMIT " . (int)$limit,
        [$itemId]
    );
}

/**
 * Get active bookings for a user
 */
function getUserActiveBookings($userId) {
    return dbFetchAll("
        SELECT b.*, i.name AS item_name, i.barcode
        FROM inventory_bookings b
        JOIN inventory_items i ON b.item_id = i.id
        WHERE b.user_id = ? AND b.status IN ('active', 'overdue')
        ORDER BY b.created_at DESC
    ", [$userId]);
}

// =============================================
// STATISTICS
// =============================================

/**
 * Get inventory statistics (optionally filtered by department)
 */
function getInventoryStats($deptId = null) {
    $query = "
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available,
            SUM(CASE WHEN status = 'booked' THEN 1 ELSE 0 END) AS booked,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance,
            SUM(CASE WHEN status = 'damaged' THEN 1 ELSE 0 END) AS damaged
        FROM inventory_items 
        WHERE is_active = 1
    ";
    $params = [];

    if ($deptId !== null) {
        $query   .= " AND (department_id = ? OR department_id IS NULL)";
        $params[] = (int)$deptId;
    } else {
        [$query, $params] = filterInventoryByDepartment($query, $params, 'inventory_items');
    }

    $row = dbFetchOne($query, $params);
    // Ensure defaults
    return [
        'total'       => (int)($row['total'] ?? 0),
        'available'   => (int)($row['available'] ?? 0),
        'booked'      => (int)($row['booked'] ?? 0),
        'maintenance' => (int)($row['maintenance'] ?? 0),
        'damaged'     => (int)($row['damaged'] ?? 0),
    ];
}

/**
 * Get count of pending notes (for sidebar badge)
 */
function getPendingInventoryNotesCount() {
    $query  = "SELECT COUNT(*) FROM inventory_notes WHERE status IN ('pending', 'acknowledged')";
    $params = [];
    // Only count notes for items the user can see
    return (int)dbFetchValue($query, $params);
}

// =============================================
// BARCODE UTILITIES
// =============================================

/**
 * Generate next barcode with prefix
 */
function generateInventoryBarcode($prefix = 'INV') {
    $user = getCurrentUser();
    if ($user['department_id']) {
        $dept = dbFetchOne("SELECT inventory_settings FROM departments WHERE id = ?", [$user['department_id']]);
        if ($dept && !empty($dept['inventory_settings'])) {
            $settings = json_decode($dept['inventory_settings'], true);
            if (!empty($settings['barcode_prefix'])) {
                $prefix = $settings['barcode_prefix'];
            }
        }
    }

    $highest = dbFetchValue("
        SELECT MAX(CAST(SUBSTRING(barcode, ? + 1) AS UNSIGNED)) 
        FROM inventory_items 
        WHERE barcode LIKE CONCAT(?, '%')
    ", [strlen($prefix), $prefix]);

    $next = ($highest ?? 0) + 1;
    return $prefix . str_pad($next, 6, '0', STR_PAD_LEFT);
}

/**
 * Validate barcode format and uniqueness
 */
function validateBarcode($barcode, $excludeId = null) {
    if (empty($barcode)) {
        return 'Το barcode είναι υποχρεωτικό.';
    }
    if (!preg_match('/^[A-Za-z0-9\-_]{3,50}$/', $barcode)) {
        return 'Μη έγκυρη μορφή barcode (3-50 χαρακτήρες, μόνο γράμματα, αριθμοί, -, _).';
    }

    $query  = "SELECT id FROM inventory_items WHERE barcode = ?";
    $params = [$barcode];
    if ($excludeId) {
        $query   .= " AND id != ?";
        $params[] = $excludeId;
    }
    $existing = dbFetchOne($query, $params);
    if ($existing) {
        return 'Αυτό το barcode χρησιμοποιείται ήδη.';
    }

    return null; // Valid
}

// =============================================
// OVERDUE CALCULATION
// =============================================

/**
 * Calculate overdue status for a booking
 */
function calculateOverdueStatus($bookingDate, $expectedReturn = null) {
    $overdueDays = (int)getSetting('inventory_overdue_days', 3);

    $start      = new DateTime($bookingDate);
    $now        = new DateTime();
    $daysPassed = (int)$now->diff($start)->format('%a');

    if ($expectedReturn) {
        $expected    = new DateTime($expectedReturn);
        $isOverdue   = $now > $expected;
        $daysOverdue = $isOverdue ? (int)$now->diff($expected)->format('%a') : 0;
    } else {
        $isOverdue   = $daysPassed > $overdueDays;
        $daysOverdue = $isOverdue ? ($daysPassed - $overdueDays) : 0;
    }

    return [
        'days_passed'  => $daysPassed,
        'is_overdue'   => $isOverdue,
        'days_overdue' => $daysOverdue,
        'status_class' => $isOverdue ? 'danger' : 'success',
        'status_label' => $isOverdue
            ? "Εκπρόθεσμο ({$daysOverdue}η)"
            : "Εντός ({$daysPassed}η)",
    ];
}

// =============================================
// CATEGORIES & LOCATIONS HELPERS
// =============================================

/**
 * Get all active categories
 */
function getInventoryCategories() {
    return dbFetchAll("SELECT * FROM inventory_categories WHERE is_active = 1 ORDER BY sort_order, name");
}

/**
 * Get all active locations (optionally filtered by department)
 */
function getInventoryLocations($deptId = null) {
    $query  = "SELECT * FROM inventory_locations WHERE is_active = 1";
    $params = [];
    if ($deptId) {
        $query   .= " AND (department_id = ? OR department_id IS NULL)";
        $params[] = (int)$deptId;
    }
    $query .= " ORDER BY name";
    return dbFetchAll($query, $params);
}

/**
 * Get departments that have inventory enabled (warehouses)
 */
function getInventoryDepartments() {
    return dbFetchAll("SELECT id, name FROM departments WHERE is_active = 1 AND has_inventory = 1 ORDER BY name");
}

// =============================================
// EQUIPMENT KITS (ΣΕΤ ΕΞΟΠΛΙΣΜΟΥ)
// =============================================

/**
 * Get a kit by ID, including its items
 */
function getInventoryKit($id) {
    $kit = dbFetchOne("SELECT k.*, d.name as department_name, u.name as creator_name 
                       FROM inventory_kits k 
                       LEFT JOIN departments d ON k.department_id = d.id 
                       LEFT JOIN users u ON k.created_by = u.id 
                       WHERE k.id = ?", [$id]);
    if (!$kit) return null;
    
    $kit['items'] = dbFetchAll("SELECT i.*, c.icon as category_icon 
                                FROM inventory_kit_items ki 
                                JOIN inventory_items i ON ki.item_id = i.id 
                                LEFT JOIN inventory_categories c ON i.category_id = c.id 
                                WHERE ki.kit_id = ? 
                                ORDER BY i.name", [$id]);
    return $kit;
}

/**
 * Get a kit by barcode
 */
function getInventoryKitByBarcode($barcode) {
    $kit = dbFetchOne("SELECT * FROM inventory_kits WHERE barcode = ?", [$barcode]);
    if (!$kit) return null;
    return getInventoryKit($kit['id']);
}

/**
 * Create a new kit
 */
function createInventoryKit($data, $itemIds) {
    try {
        $pdo = db();
        $pdo->beginTransaction();
        
        $kitId = dbInsert(
            "INSERT INTO inventory_kits (barcode, name, description, department_id, created_by) 
             VALUES (?, ?, ?, ?, ?)",
            [
                $data['barcode'],
                $data['name'],
                $data['description'] ?? null,
                $data['department_id'] ?: null,
                getCurrentUserId()
            ]
        );
        
        foreach ($itemIds as $itemId) {
            dbInsert("INSERT INTO inventory_kit_items (kit_id, item_id) VALUES (?, ?)", [$kitId, $itemId]);
        }
        
        $pdo->commit();
        return ['success' => true, 'id' => $kitId];
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'error' => 'Σφάλμα κατά τη δημιουργία του σετ: ' . $e->getMessage()];
    }
}

/**
 * Update an existing kit
 */
function updateInventoryKit($id, $data, $itemIds) {
    try {
        $pdo = db();
        $pdo->beginTransaction();
        
        dbExecute(
            "UPDATE inventory_kits SET barcode = ?, name = ?, description = ?, department_id = ? WHERE id = ?",
            [
                $data['barcode'],
                $data['name'],
                $data['description'] ?? null,
                $data['department_id'] ?: null,
                $id
            ]
        );
        
        dbExecute("DELETE FROM inventory_kit_items WHERE kit_id = ?", [$id]);
        foreach ($itemIds as $itemId) {
            dbInsert("INSERT INTO inventory_kit_items (kit_id, item_id) VALUES (?, ?)", [$id, $itemId]);
        }
        
        $pdo->commit();
        return ['success' => true];
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'error' => 'Σφάλμα κατά την ενημέρωση του σετ: ' . $e->getMessage()];
    }
}

/**
 * Delete a kit
 */
function deleteInventoryKit($id) {
    try {
        dbExecute("DELETE FROM inventory_kits WHERE id = ?", [$id]);
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Σφάλμα κατά τη διαγραφή του σετ: ' . $e->getMessage()];
    }
}

/**
 * Book all available items in a kit
 */
function bookInventoryKit($kitId, $userId, $data) {
    $kit = getInventoryKit($kitId);
    if (!$kit) return ['success' => false, 'error' => 'Το σετ δεν βρέθηκε.'];
    
    $bookedCount = 0;
    $failedCount = 0;
    $messages = [];
    
    foreach ($kit['items'] as $item) {
        if ($item['status'] === 'available' && $item['is_active'] == 1) {
            $res = createInventoryBooking($item['id'], $userId, $data);
            if ($res['success']) {
                $bookedCount++;
            } else {
                $failedCount++;
                $messages[] = "{$item['name']}: " . $res['error'];
            }
        } else {
            $failedCount++;
            $statusLabel = $item['status'] === 'booked' ? 'Χρεωμένο' : ($item['status'] === 'maintenance' ? 'Συντήρηση' : 'Φθορά');
            $messages[] = "{$item['name']}: Μη διαθέσιμο ($statusLabel)";
        }
    }
    
    if ($bookedCount > 0) {
        $msg = "Χρεώθηκαν επιτυχώς $bookedCount υλικά από το σετ '{$kit['name']}'.";
        if ($failedCount > 0) {
            $msg .= " Δεν χρεώθηκαν $failedCount υλικά: " . implode(', ', $messages);
        }
        return ['success' => true, 'message' => $msg];
    } else {
        return ['success' => false, 'error' => "Κανένα υλικό από το σετ δεν ήταν διαθέσιμο για χρέωση. Λεπτομέρειες: " . implode(', ', $messages)];
    }
}

