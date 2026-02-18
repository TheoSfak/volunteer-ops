# 📦 VolunteerOps Inventory System Integration Plan

**Version:** 1.0.0  
**Target Release:** v2.6.0  
**Created:** February 18, 2026  
**Source System:** FirstAid Manager v1.5.5 (JSON-based)  
**Target System:** VolunteerOps v2.5.0 (MySQL-based)  
**Integration Strategy:** Hybrid Architecture - Centralized missions + Soft multi-tenant inventory

---

## 📋 Executive Summary

### Project Goal
Integrate FirstAid Manager's inventory management system into VolunteerOps while maintaining centralized mission/shift operations. Create a hybrid architecture where:
- **Centralized**: Missions, shifts, volunteers, user management (existing VolunteerOps core)
- **Soft Multi-tenant**: Inventory items, bookings, locations (new inventory module via `department_id` FK)

### Integration Approach
✅ Convert FirstAid's JSON database to MySQL tables  
✅ Soft multi-tenancy via `department_id` foreign key (NOT separate databases)  
✅ Users login to VolunteerOps main app to access inventory features  
✅ Enhanced `ROLE_SYSTEM_ADMIN` can filter/manage all department inventories  
✅ `ROLE_DEPARTMENT_ADMIN` manages their department's inventory only  
✅ `ROLE_VOLUNTEER` can book/return items from their department  
✅ Phased implementation over 6 weeks (120 hours)

### Success Criteria
- ✅ 100% data isolation between department inventories
- ✅ Barcode scanning works on mobile devices (iOS/Android)
- ✅ Analytics dashboard with 12 Chart.js visualizations
- ✅ Real-time notes system with cross-page updates
- ✅ Mobile-responsive design (375px to 1920px)
- ✅ Export functionality (CSV/Excel with Greek UTF-8 BOM)
- ✅ Zero performance degradation (<2s page load with 1000+ items)
- ✅ All CRUD operations maintain existing VolunteerOps patterns

### Key Features to Implement
1. ☑️ **Resource Management** - Barcode tracking, categories, locations, status
2. ☑️ **Booking System** - Single/bulk checkout, returns, overdue tracking
3. ☑️ **Multi-tenancy** - Department-based inventory separation (soft FK)
4. ☑️ **Real-time Notes** - Cross-page communication, status workflow
5. ☑️ **Barcode Scanning** - Quagga.js camera integration
6. ☑️ **Analytics Dashboard** - Chart.js with 12 visualizations
7. ☑️ **Advanced Reports** - Filters, date ranges, CSV/Excel/PDF exports
8. ☑️ **Fixed Assets** - Long-term loan tracking
9. ☑️ **Mobile Responsive** - Touch-friendly UI
10. ☑️ **Location Tracking** - Warehouse/storage management

---

## 🏗️ Integration Architecture

### System Architecture Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                    VolunteerOps v2.6.0                       │
│                  (Hybrid Architecture)                       │
└─────────────────────────────────────────────────────────────┘
         │                            │
         ▼                            ▼
┌───────────────────────┐    ┌───────────────────────┐
│  CENTRALIZED MODULE   │    │  INVENTORY MODULE     │
│  (Existing System)    │    │  (New - Multi-tenant) │
├───────────────────────┤    ├───────────────────────┤
│ • Missions            │    │ • Items               │
│ • Shifts              │    │ • Bookings            │
│ • Participations      │    │ • Categories          │
│ • Users (Global)      │    │ • Locations           │
│ • Departments (Meta)  │    │ • Notes               │
│ • Notifications       │    │ • Fixed Assets        │
│ • Audit Logs          │    │ • Analytics           │
└───────────────────────┘    └───────────────────────┘
         │                            │
         └──────────┬─────────────────┘
                    ▼
         ┌───────────────────────┐
         │   Shared Components   │
         ├───────────────────────┤
         │ • Authentication      │
         │ • Authorization       │
         │ • Session Management  │
         │ • Email System        │
         │ • Audit Logging       │
         │ • Bootstrap UI        │
         └───────────────────────┘
```

### Department Access Model

```
ROLE_SYSTEM_ADMIN
├─ View ALL departments' inventories (with filter dropdown)
├─ Manage ALL inventories
├─ Create/edit/delete departments
└─ Access global analytics

ROLE_DEPARTMENT_ADMIN
├─ View ONLY assigned department inventory
├─ Manage own department items
├─ Approve/reject bookings
└─ View department analytics

ROLE_VOLUNTEER
├─ View available items in their department
├─ Book/return items
├─ View own booking history
└─ Add notes to items
```

---

## 💾 MySQL Database Schema

### Table: `inventory_items` (Main resource table)

```sql
CREATE TABLE inventory_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    barcode VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    category_id INT,
    department_id INT NULL,                    -- NULL = global item, else dept-specific
    location VARCHAR(255),                      -- "Κεντρική Αποθήκη"
    location_notes TEXT,                        -- "Ράφι Α1, 2ος όροφος"
    status ENUM('available', 'booked', 'maintenance', 'damaged') DEFAULT 'available',
    condition_notes TEXT,
    
    -- Booking info (denormalized for quick status check)
    booked_by_user_id INT NULL,
    booked_by_name VARCHAR(255),
    booking_date DATETIME NULL,
    expected_return_date DATETIME NULL,
    
    -- Metadata
    image_url VARCHAR(500),
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (category_id) REFERENCES inventory_categories(id),
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    FOREIGN KEY (booked_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id),
    
    INDEX idx_barcode (barcode),
    INDEX idx_status (status),
    INDEX idx_department (department_id),
    INDEX idx_category (category_id),
    INDEX idx_active (is_active),
    INDEX idx_dept_status (department_id, status),
    FULLTEXT INDEX idx_search (name, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table: `inventory_categories`

```sql
CREATE TABLE inventory_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    icon VARCHAR(10) DEFAULT '📦',              -- Emoji icon
    color VARCHAR(7) DEFAULT '#6c757d',         -- Hex color for badges
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_name (name),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed data
INSERT INTO inventory_categories (name, icon, color, sort_order) VALUES
('Φαρμακεία', '💊', '#dc3545', 1),
('Ιατρικός Εξοπλισμός', '🏥', '#28a745', 2),
('Επικοινωνία', '📢', '#17a2b8', 3),
('Σκηνές & Εξοπλισμός', '⛺', '#ffc107', 4),
('Εκπαίδευση', '📚', '#6c757d', 5),
('Ασύρματοι', '📻', '#007bff', 6),
('Οχήματα', '🚑', '#e83e8c', 7);
```

### Table: `inventory_bookings` (Checkout transactions)

```sql
CREATE TABLE inventory_bookings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_id INT NOT NULL,
    user_id INT NOT NULL,
    
    -- Cached info
    volunteer_name VARCHAR(255),
    volunteer_phone VARCHAR(20),
    volunteer_email VARCHAR(255),
    
    -- Booking details
    mission_location VARCHAR(500),
    booking_type ENUM('single', 'bulk') DEFAULT 'single',
    expected_return_date DATE NULL,
    notes TEXT,
    
    -- Status
    status ENUM('active', 'overdue', 'returned', 'lost') DEFAULT 'active',
    
    -- Return info
    return_date DATETIME NULL,
    returned_by_user_id INT NULL,
    return_notes TEXT,
    actual_hours DECIMAL(6,2) NULL,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (returned_by_user_id) REFERENCES users(id),
    
    INDEX idx_status (status),
    INDEX idx_item (item_id),
    INDEX idx_user (user_id),
    INDEX idx_dates (created_at, return_date),
    INDEX idx_status_dates (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table: `inventory_notes` (Real-time communication)

```sql
CREATE TABLE inventory_notes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_id INT NOT NULL,
    item_name VARCHAR(255),
    
    note_type ENUM('booking', 'return', 'maintenance', 'damage', 'general') DEFAULT 'general',
    content TEXT NOT NULL,
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    
    status ENUM('pending', 'acknowledged', 'in_progress', 'resolved', 'archived') DEFAULT 'pending',
    status_history JSON,
    
    related_booking_id INT NULL,
    assigned_to_user_id INT NULL,
    
    created_by_user_id INT,
    created_by_name VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    resolved_at DATETIME NULL,
    resolved_by_user_id INT NULL,
    resolution_notes TEXT,
    
    FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
    FOREIGN KEY (related_booking_id) REFERENCES inventory_bookings(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    FOREIGN KEY (assigned_to_user_id) REFERENCES users(id),
    FOREIGN KEY (resolved_by_user_id) REFERENCES users(id),
    
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_item (item_id),
    INDEX idx_type (note_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table: `inventory_fixed_assets` (Long-term loans)

```sql
CREATE TABLE inventory_fixed_assets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    barcode VARCHAR(50) UNIQUE,
    description TEXT,
    location VARCHAR(255),
    department_id INT NULL,
    
    status ENUM('available', 'checked_out', 'retired') DEFAULT 'available',
    checked_out_to_user_id INT NULL,
    checked_out_to_name VARCHAR(255),
    checked_out_phone VARCHAR(20),
    checked_out_at DATETIME NULL,
    checkout_notes TEXT,
    
    purchase_date DATE,
    purchase_cost DECIMAL(10,2),
    serial_number VARCHAR(100),
    condition_notes TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (checked_out_to_user_id) REFERENCES users(id),
    
    INDEX idx_status (status),
    INDEX idx_department (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table: `inventory_locations` (Warehouse metadata)

```sql
CREATE TABLE inventory_locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    department_id INT NULL,
    location_type ENUM('warehouse', 'vehicle', 'room', 'other') DEFAULT 'warehouse',
    address TEXT,
    capacity INT,
    current_items_count INT DEFAULT 0,
    notes TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (department_id) REFERENCES departments(id),
    INDEX idx_department (department_id),
    INDEX idx_type (location_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table: `inventory_department_access` (Permissions)

```sql
CREATE TABLE inventory_department_access (
    user_id INT NOT NULL,
    department_id INT NOT NULL,
    access_level ENUM('viewer', 'manager', 'admin') DEFAULT 'viewer',
    can_book BOOLEAN DEFAULT TRUE,
    can_manage_items BOOLEAN DEFAULT FALSE,
    can_approve_bookings BOOLEAN DEFAULT FALSE,
    granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    granted_by_user_id INT,
    
    PRIMARY KEY (user_id, department_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by_user_id) REFERENCES users(id),
    
    INDEX idx_access_level (access_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Modify Existing `departments` Table

```sql
ALTER TABLE departments 
ADD COLUMN has_inventory BOOLEAN DEFAULT FALSE AFTER is_headquarters,
ADD COLUMN inventory_settings JSON AFTER has_inventory;

-- Example inventory_settings:
-- {
--   "overdue_days": 3,
--   "require_booking_approval": false,
--   "barcode_prefix": "HER",
--   "allow_external_bookings": false,
--   "default_location_id": 1
-- }
```

### Database Triggers

```sql
DELIMITER $$

-- Auto-update item status on booking
CREATE TRIGGER trg_booking_insert AFTER INSERT ON inventory_bookings
FOR EACH ROW
BEGIN
    IF NEW.status = 'active' THEN
        UPDATE inventory_items 
        SET status = 'booked',
            booked_by_user_id = NEW.user_id,
            booked_by_name = NEW.volunteer_name,
            booking_date = NEW.created_at
        WHERE id = NEW.item_id;
    END IF;
END$$

-- Auto-update item status on return
CREATE TRIGGER trg_booking_return AFTER UPDATE ON inventory_bookings
FOR EACH ROW
BEGIN
    IF OLD.status = 'active' AND NEW.status = 'returned' THEN
        UPDATE inventory_items 
        SET status = 'available',
            booked_by_user_id = NULL,
            booked_by_name = NULL,
            booking_date = NULL,
            expected_return_date = NULL
        WHERE id = NEW.item_id;
    END IF;
END$$

DELIMITER ;
```

---

## 📅 Phased Implementation Roadmap

### Phase 1: Core Inventory CRUD (Week 1-2, 40 hours)

#### Week 1 Tasks (20h)

**Day 1-2: Database Setup (8h)**
- [ ] Create `sql/inventory_schema.sql` migration file
- [ ] Run migrations (dev, test environments)
- [ ] Seed categories with Greek names
- [ ] Test all triggers and foreign keys
- [ ] Create backup procedure

**Day 3-4: List Page (8h)**
- [ ] Create `inventory.php` (main list)
- [ ] Search: barcode, name, description
- [ ] Filters: category, status, department
- [ ] Table: barcode, name, category, location, status, actions
- [ ] Pagination (20 per page)
- [ ] Add button (admin only)

**Day 5: View Page (4h)**
- [ ] Create `inventory-view.php`
- [ ] Display all item details
- [ ] Show booking history
- [ ] Show active notes
- [ ] Quick booking form (if available)

#### Week 2 Tasks (20h)

**Day 1-2: Form Pages (8h)**
- [ ] Create `inventory-form.php`
- [ ] Barcode input + validation
- [ ] Category dropdown
- [ ] Department selector (SYSTEM_ADMIN)
- [ ] Location dropdown
- [ ] Image upload
- [ ] Greek error messages

**Day 3-4: Booking System (10h)**
- [ ] Create `inventory-book.php`
- [ ] Barcode scanner button
- [ ] Volunteer selector
- [ ] Mission location input
- [ ] Expected return date
- [ ] Notes field
- [ ] Return functionality

**Day 5: Testing (2h)**
- [ ] CRUD operations
- [ ] Trigger verification
- [ ] Permission enforcement
- [ ] Greek language check
- [ ] Mobile responsive

**Phase 1 Deliverables:**
✅ 5 PHP pages  
✅ 7 database tables  
✅ Basic booking workflow  
✅ Admin management  
✅ Volunteer booking

---

### Phase 2: Soft Multi-Tenancy (Week 3, 24 hours)

**Day 1-2: Infrastructure (10h)**
- [ ] Add `inventory_settings` to departments
- [ ] Create `inventory_department_access` table
- [ ] Build department selector component
- [ ] Add session variable: `inventory_department_filter`
- [ ] Create helper: `getCurrentInventoryDepartment()`

**Day 3-4: Query Filtering (10h)**
- [ ] Update all inventory queries with department filter
- [ ] Create `checkInventoryAccess($itemId, $userId)`
- [ ] Add department column to list views
- [ ] Auto-assign department on item creation

**Day 5: Department Management (4h)**
- [ ] Create `inventory-departments.php` (SYSTEM_ADMIN)
- [ ] List departments with inventory stats
- [ ] Enable/disable inventory per department
- [ ] Configure department settings

**Phase 2 Deliverables:**
✅ Department filtering works  
✅ SYSTEM_ADMIN views all  
✅ DEPARTMENT_ADMIN sees only theirs  
✅ Department selector in header  
✅ Management page

---

### Phase 3: Advanced Features (Week 4-5, 40 hours)

#### Week 4 Tasks (20h)

**Day 1-2: Barcode Scanner (8h)**
- [ ] Add Quagga.js library
- [ ] Create `inventory-barcode.php`
- [ ] Camera access implementation
- [ ] Barcode detection & validation
- [ ] Mobile device testing

**Day 3-5: Real-time Notes (12h)**
- [ ] Create `inventory-notes.php`
- [ ] Status workflow implementation
- [ ] Priority system
- [ ] History tracking (JSON)
- [ ] Real-time updates (localStorage)
- [ ] Email notifications (high priority)
- [ ] Notes widget on view page

#### Week 5 Tasks (20h)

**Day 1-2: Bulk Actions (10h)**
- [ ] Create `inventory-bulk-book.php`
- [ ] Multi-select UI
- [ ] Batch booking form
- [ ] Transaction handling
- [ ] Create `inventory-bulk-return.php`
- [ ] Batch return processing

**Day 3-4: Export System (8h)**
- [ ] CSV export with UTF-8 BOM
- [ ] Excel export (PHPSpreadsheet)
- [ ] PDF export (TCPDF)
- [ ] Filter exports by date/category/dept
- [ ] Booking history export

**Day 5: Mobile CSS (2h)**
- [ ] Responsive breakpoints
- [ ] Touch-friendly buttons (44px)
- [ ] Responsive tables
- [ ] Mobile testing (375px, 768px, 1024px)

**Phase 3 Deliverables:**
✅ Barcode scanning  
✅ Real-time notes  
✅ Bulk booking/return  
✅ CSV/Excel/PDF exports  
✅ Mobile-optimized UI

---

### Phase 4: Analytics & Reporting (Week 6, 16 hours)

**Day 1-2: Chart.js Dashboard (8h)**
- [ ] Create `inventory-analytics.php`
- [ ] Implement 12 visualizations:
  1. Resource Status (Doughnut)
  2. Category Breakdown (Pie)
  3. Monthly Bookings (Line)
  4. Top Volunteers (Bar)
  5. Location Utilization (H-Bar)
  6. Day of Week (Polar)
  7. Hourly Pattern (Line)
  8. Department Comparison (Grouped Bar)
  9. Booking Duration (Radar)
  10. Overdue Trend (Line)
  11. Category Popularity (Bar)
  12. KPI Cards

**Day 3-4: Advanced Reports (6h)**
- [ ] Create `inventory-reports.php`
- [ ] Date range filters
- [ ] Category/department/status filters
- [ ] Export filtered results
- [ ] Scheduled reports (email)

**Day 5: Overdue Cron (2h)**
- [ ] Create `cron_inventory_overdue.php`
- [ ] Find overdue bookings
- [ ] Update status to 'overdue'
- [ ] Send email notifications
- [ ] Log audit trail

**Phase 4 Deliverables:**
✅ Analytics dashboard  
✅ Advanced reports  
✅ Automated overdue tracking  
✅ Email notifications

---

## 📁 File Structure & New Pages

### New Files to Create

```
volunteerops/
├── inventory.php                          # Main list
├── inventory-form.php                     # Add/Edit
├── inventory-view.php                     # Details
├── inventory-book.php                     # Quick booking
├── inventory-bulk-book.php                # Bulk booking
├── inventory-bulk-return.php              # Bulk return
├── inventory-categories.php               # Categories
├── inventory-locations.php                # Locations
├── inventory-notes.php                    # Notes
├── inventory-analytics.php                # Analytics
├── inventory-reports.php                  # Reports
├── inventory-export.php                   # Export handler
├── inventory-fixed-assets.php             # Fixed assets
├── inventory-barcode.php                  # Scanner
├── inventory-departments.php              # Dept mgmt
│
├── includes/
│   ├── inventory-functions.php            # Helpers
│   ├── inventory-export-functions.php     # Export utils
│   └── department-selector.php            # Dropdown
│
├── assets/
│   ├── js/
│   │   ├── barcode-scanner.js
│   │   ├── inventory-notes.js
│   │   └── inventory-charts.js
│   └── css/
│       └── inventory.css
│
├── sql/
│   └── inventory_schema.sql               # Migration
│
└── cron_inventory_overdue.php             # Cron job
```

### Sidebar Navigation (includes/header.php)

```php
<?php if (isLoggedIn()): ?>
    <!-- Inventory Section -->
    <li class="nav-header">Απόθεμα</li>
    
    <li class="nav-item">
        <a href="inventory.php" class="nav-link">
            <i class="bi bi-box-seam"></i> Υλικά
        </a>
    </li>
    
    <li class="nav-item">
        <a href="inventory-book.php" class="nav-link">
            <i class="bi bi-upc-scan"></i> Χρέωση
        </a>
    </li>
    
    <?php if (hasRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN])): ?>
        <li class="nav-item">
            <a href="inventory-categories.php" class="nav-link">
                <i class="bi bi-tags"></i> Κατηγορίες
            </a>
        </li>
        
        <li class="nav-item">
            <a href="inventory-notes.php" class="nav-link">
                <i class="bi bi-sticky"></i> Σημειώσεις
                <?php if ($pendingNotes > 0): ?>
                    <span class="badge bg-danger"><?= $pendingNotes ?></span>
                <?php endif; ?>
            </a>
        </li>
        
        <li class="nav-item">
            <a href="inventory-analytics.php" class="nav-link">
                <i class="bi bi-graph-up"></i> Στατιστικά
            </a>
        </li>
    <?php endif; ?>
    
    <?php if (hasRole(ROLE_SYSTEM_ADMIN)): ?>
        <li class="nav-item">
            <a href="inventory-departments.php" class="nav-link">
                <i class="bi bi-building"></i> Τμήματα
            </a>
        </li>
    <?php endif; ?>
<?php endif; ?>
```

---

## 🔧 Helper Functions Library

Create `includes/inventory-functions.php`:

```php
<?php
/**
 * VolunteerOps - Inventory Helper Functions
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

// Status constants
define('INVENTORY_STATUS_AVAILABLE', 'available');
define('INVENTORY_STATUS_BOOKED', 'booked');
define('INVENTORY_STATUS_MAINTENANCE', 'maintenance');
define('INVENTORY_STATUS_DAMAGED', 'damaged');

// Greek labels
const INVENTORY_STATUS_LABELS = [
    'available' => 'Διαθέσιμο',
    'booked' => 'Χρεωμένο',
    'maintenance' => 'Συντήρηση',
    'damaged' => 'Χαλασμένο'
];

const BOOKING_STATUS_LABELS = [
    'active' => 'Ενεργή',
    'overdue' => 'Εκπρόθεσμη',
    'returned' => 'Επιστράφηκε',
    'lost' => 'Χαμένο'
];

/**
 * Check inventory access
 */
function checkInventoryAccess($itemId, $userId = null) {
    $userId = $userId ?? getCurrentUserId();
    $user = getCurrentUser();
    
    if ($user['role'] === ROLE_SYSTEM_ADMIN) {
        return true;
    }
    
    $item = dbFetchOne("SELECT department_id FROM inventory_items WHERE id = ?", [$itemId]);
    if (!$item) return false;
    
    if ($item['department_id'] === null) return true;
    
    if ($user['department_id'] == $item['department_id']) return true;
    
    $access = dbFetchOne("
        SELECT 1 FROM inventory_department_access 
        WHERE user_id = ? AND department_id = ?
    ", [$userId, $item['department_id']]);
    
    return !empty($access);
}

/**
 * Get inventory items with filters
 */
function getInventoryItems($filters = []) {
    $query = "
        SELECT i.*, c.name as category_name, c.icon, d.display_name as dept_name
        FROM inventory_items i
        LEFT JOIN inventory_categories c ON i.category_id = c.id
        LEFT JOIN departments d ON i.department_id = d.id
        WHERE i.is_active = 1
    ";
    $params = [];
    
    if (!empty($filters['status'])) {
        $query .= " AND i.status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['category_id'])) {
        $query .= " AND i.category_id = ?";
        $params[] = $filters['category_id'];
    }
    
    if (!empty($filters['search'])) {
        $query .= " AND (i.name LIKE ? OR i.barcode LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $params[] = $search;
        $params[] = $search;
    }
    
    [$query, $params] = filterInventoryByDepartment($query, $params, 'i');
    
    $query .= " ORDER BY i.name";
    
    return dbFetchAll($query, $params);
}

/**
 * Create booking
 */
function createInventoryBooking($itemId, $userId, $data) {
    try {
        $pdo = getPDO();
        $pdo->beginTransaction();
        
        $item = dbFetchOne("
            SELECT * FROM inventory_items 
            WHERE id = ? AND status = 'available' 
            FOR UPDATE
        ", [$itemId]);
        
        if (!$item) throw new Exception('Το υλικό δεν είναι διαθέσιμο.');
        
        if (!checkInventoryAccess($itemId, $userId)) {
            throw new Exception('Δεν έχετε πρόσβαση.');
        }
        
        $user = getCurrentUser();
        
        $bookingId = dbInsert("
            INSERT INTO inventory_bookings (
                item_id, user_id, volunteer_name, volunteer_phone,
                mission_location, notes, expected_return_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ", [
            $itemId, $userId, $user['name'], $user['phone'] ?? '',
            $data['mission_location'] ?? '', $data['notes'] ?? '',
            $data['expected_return_date'] ?? null
        ]);
        
        dbExecute("
            UPDATE inventory_items 
            SET status = 'booked', booked_by_user_id = ?, 
                booked_by_name = ?, booking_date = NOW()
            WHERE id = ?
        ", [$userId, $user['name'], $itemId]);
        
        logAudit('inventory_booking_created', 'inventory_bookings', $bookingId);
        
        $pdo->commit();
        return ['success' => true, 'booking_id' => $bookingId];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Return item
 */
function returnInventoryItem($bookingId, $returnNotes = '') {
    try {
        $pdo = getPDO();
        $pdo->beginTransaction();
        
        $booking = dbFetchOne("
            SELECT b.*, i.name as item_name
            FROM inventory_bookings b
            JOIN inventory_items i ON b.item_id = i.id
            WHERE b.id = ? AND b.status = 'active'
        ", [$bookingId]);
        
        if (!$booking) throw new Exception('Η κράτηση δεν βρέθηκε.');
        
        $start = new DateTime($booking['created_at']);
        $end = new DateTime();
        $hours = ($end->getTimestamp() - $start->getTimestamp()) / 3600;
        
        dbExecute("
            UPDATE inventory_bookings 
            SET status = 'returned', return_date = NOW(),
                returned_by_user_id = ?, return_notes = ?, actual_hours = ?
            WHERE id = ?
        ", [getCurrentUserId(), $returnNotes, $hours, $bookingId]);
        
        dbExecute("
            UPDATE inventory_items 
            SET status = 'available', booked_by_user_id = NULL,
                booked_by_name = NULL, booking_date = NULL
            WHERE id = ?
        ", [$booking['item_id']]);
        
        logAudit('inventory_returned', 'inventory_bookings', $bookingId);
        
        $pdo->commit();
        return ['success' => true, 'hours' => $hours];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Calculate overdue
 */
function calculateOverdueStatus($bookingDate, $expectedReturn = null) {
    $overdueDays = (int)getSetting('inventory_overdue_days', 3);
    
    $start = new DateTime($bookingDate);
    $now = new DateTime();
    $daysPassed = (int)$now->diff($start)->format('%a');
    
    if ($expectedReturn) {
        $expected = new DateTime($expectedReturn);
        $isOverdue = $now > $expected;
        $daysOverdue = $isOverdue ? (int)$now->diff($expected)->format('%a') : 0;
    } else {
        $isOverdue = $daysPassed > $overdueDays;
        $daysOverdue = $isOverdue ? ($daysPassed - $overdueDays) : 0;
    }
    
    return [
        'days_passed' => $daysPassed,
        'is_overdue' => $isOverdue,
        'days_overdue' => $daysOverdue,
        'status_class' => $isOverdue ? 'danger' : 'success',
        'status_label' => $isOverdue ? "Εκπρόθεσμο ({$daysOverdue}η)" : "Εντός ({$daysPassed}η)"
    ];
}

/**
 * Filter by department
 */
function filterInventoryByDepartment($query, $params = [], $alias = 'i') {
    $user = getCurrentUser();
    
    if ($user['role'] === ROLE_SYSTEM_ADMIN) {
        $filter = $_SESSION['inventory_department_filter'] ?? null;
        
        if ($filter === null || $filter === '') {
            return [$query, $params];
        }
        
        $query .= " AND ($alias.department_id = ? OR $alias.department_id IS NULL)";
        $params[] = $filter;
        return [$query, $params];
    }
    
    if ($user['department_id']) {
        $query .= " AND ($alias.department_id = ? OR $alias.department_id IS NULL)";
        $params[] = $user['department_id'];
    }
    
    return [$query, $params];
}

/**
 * Generate barcode
 */
function generateInventoryBarcode($prefix = 'INV', $deptId = null) {
    if ($deptId) {
        $dept = dbFetchOne("SELECT inventory_settings FROM departments WHERE id = ?", [$deptId]);
        if ($dept && $dept['inventory_settings']) {
            $settings = json_decode($dept['inventory_settings'], true);
            $prefix = $settings['barcode_prefix'] ?? $prefix;
        }
    }
    
    $highest = dbFetchValue("
        SELECT MAX(CAST(SUBSTRING(barcode, LENGTH(?)+1) AS UNSIGNED)) 
        FROM inventory_items WHERE barcode LIKE CONCAT(?, '%')
    ", [$prefix, $prefix]);
    
    $next = ($highest ?? 0) + 1;
    return $prefix . str_pad($next, 6, '0', STR_PAD_LEFT);
}

/**
 * Status badge
 */
function inventoryStatusBadge($status) {
    $colors = [
        'available' => 'success',
        'booked' => 'primary',
        'maintenance' => 'warning',
        'damaged' => 'danger'
    ];
    
    return '<span class="badge bg-' . ($colors[$status] ?? 'secondary') . '">' . 
           h(INVENTORY_STATUS_LABELS[$status] ?? $status) . '</span>';
}

/**
 * Get inventory stats
 */
function getInventoryStats($deptId = null) {
    $query = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
            SUM(CASE WHEN status = 'booked' THEN 1 ELSE 0 END) as booked,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
            SUM(CASE WHEN status = 'damaged' THEN 1 ELSE 0 END) as damaged
        FROM inventory_items WHERE is_active = 1
    ";
    
    $params = [];
    if ($deptId !== null) {
        $query .= " AND (department_id = ? OR department_id IS NULL)";
        $params[] = $deptId;
    } else {
        [$query, $params] = filterInventoryByDepartment($query, $params);
    }
    
    return dbFetchOne($query, $params);
}
```

---

## 🧪 Testing Strategy

### Unit Tests

**Test File: `tests/InventoryTest.php`**

```php
<?php
class InventoryTest extends PHPUnit\Framework\TestCase {
    
    public function testCreateBooking() {
        $result = createInventoryBooking(1, 5, [
            'mission_location' => 'Test Event',
            'notes' => 'Test booking'
        ]);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('booking_id', $result);
    }
    
    public function testDepartmentIsolation() {
        // User from Dept A tries to access Dept B item
        $hasAccess = checkInventoryAccess($itemDeptB, $userDeptA);
        $this->assertFalse($hasAccess);
    }
    
    public function testOverdueCalculation() {
        $booking = ['created_at' => '2026-02-10 10:00:00'];
        $status = calculateOverdueStatus($booking['created_at']);
        
        $this->assertTrue($status['is_overdue']);
        $this->assertGreaterThan(3, $status['days_passed']);
    }
}
```

### Manual Test Cases

**Test Case 1: Department Isolation**
1. Login as DEPARTMENT_ADMIN (Heraklion)
2. Create item with department_id = 1 (Heraklion)
3. Logout, login as DEPARTMENT_ADMIN (Athens, dept 2)
4. Go to inventory.php
5. ✅ Should NOT see Heraklion's item

**Test Case 2: Barcode Scanning**
1. Open inventory-barcode.php on mobile
2. Allow camera permissions
3. Scan barcode "TEST001"
4. ✅ Should redirect to inventory-view.php?barcode=TEST001

**Test Case 3: Real-time Notes**
1. Open inventory-view.php?id=1 in Tab A
2. Open inventory-notes.php in Tab B
3. Create note in Tab B
4. ✅ Tab A should show notification toast

**Test Case 4: Overdue Notification**
1. Create booking with expected_return_date = yesterday
2. Run: `php cron_inventory_overdue.php`
3. ✅ Status updated to 'overdue'
4. ✅ Email sent to volunteer

---

## 🚀 Builder AI Step-by-Step Instructions

### Phase 1: Database Setup (Steps 1-10)

1. Create file `c:\xampp\htdocs\volunteerops\sql\inventory_schema.sql`
2. Copy all CREATE TABLE statements from section "MySQL Database Schema"
3. Open phpMyAdmin: http://localhost/phpmyadmin
4. Select `volunteer_ops` database
5. Import `sql/inventory_schema.sql`
6. Verify 7 new tables created
7. Run seed data for `inventory_categories`
8. Test triggers with manual INSERT into `inventory_bookings`
9. Verify `inventory_items.status` updates automatically
10. Create database backup

### Phase 1: Core Files (Steps 11-30)

11. Create `includes/inventory-functions.php` with helper functions
12. Add line to `bootstrap.php`: `require_once __DIR__ . '/includes/inventory-functions.php';`
13. Create `inventory.php` - copy structure from `missions.php`
14. Replace queries to use `inventory_items` table
15. Add filters: category, status, department
16. Add search box for barcode/name
17. Create `inventory-form.php` - copy from `mission-form.php`
18. Add fields: barcode, name, category, location, department
19. Add barcode validation (unique check)
20. Create `inventory-view.php` - copy from `mission-view.php`
21. Display item details with status badge
22. Show booking history table
23. Add "Book This Item" button (if available)
24. Create `inventory-book.php`
25. Add barcode input field
26. Add volunteer selector (current user auto-filled)
27. Add mission location textarea
28. Implement POST handler with `createInventoryBooking()`
29. Add return functionality on same page
30. Test complete CRUD workflow

### Phase 2: Multi-tenancy (Steps 31-40)

31. Run: `ALTER TABLE departments ADD COLUMN has_inventory BOOLEAN`
32. Run: `ALTER TABLE departments ADD COLUMN inventory_settings JSON`
33. Create `inventory-departments.php` (SYSTEM_ADMIN only)
34. Add department enable/disable toggle
35. Add department settings form (overdue_days, barcode_prefix)
36. Create `includes/department-selector.php` component
37. Add department selector to `includes/header.php` (inventory pages only)
38. Add JavaScript: change event redirects with `?dept=X` parameter
39. Update all inventory queries with `filterInventoryByDepartment()`
40. Test: SYSTEM_ADMIN sees all, DEPARTMENT_ADMIN sees only theirs

### Phase 3: Barcode Scanner (Steps 41-50)

41. Download Quagga.js: https://github.com/serratus/quaggaJS
42. Copy `quagga.min.js` to `assets/js/`
43. Create `assets/js/barcode-scanner.js`
44. Create `inventory-barcode.php`
45. Add camera container: `<div id="scanner-container"></div>`
46. Initialize Quagga with environment camera
47. Add detected barcode display
48. Redirect to `inventory-view.php?barcode=X` on detect
49. Add fallback: manual barcode input form
50. Test on iPhone and Android

---

## ⚠️ Risk Analysis

### Security Risks

| Risk | Impact | Mitigation |
|------|--------|-----------|
| SQL Injection in barcode | HIGH | Use prepared statements, validate barcode format |
| Department access bypass | HIGH | Check `checkInventoryAccess()` on every item view |
| CSRF on booking | MEDIUM | Use `csrfField()` and `verifyCsrf()` |
| Barcode scanner XSS | MEDIUM | Sanitize barcode before display with `h()` |
| File upload (images) | HIGH | Validate file type, size, rename files |

### Performance Risks

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Full table scan on search | HIGH | Add FULLTEXT index on name, description |
| N+1 queries on list page | MEDIUM | Use LEFT JOIN for categories, departments |
| Large JSON status_history | LOW | Limit history to 50 entries, archive old |
| Slow analytics queries | MEDIUM | Cache results for 5 minutes in session |

### Data Integrity Risks

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Concurrent bookings | HIGH | Use `FOR UPDATE` lock in transaction |
| Orphaned bookings (trigger fails) | MEDIUM | Add foreign key CASCADE |
| Department deleted with items | MEDIUM | Use `ON DELETE SET NULL` |
| Negative inventory count | LOW | Add CHECK constraint on location capacity |

---

## 📚 Appendices

### Appendix A: Greek Strings Dictionary

```php
// Add to config.php
const INVENTORY_STRINGS = [
    'available' => 'Διαθέσιμο',
    'booked' => 'Χρεωμένο',
    'maintenance' => 'Συντήρηση',
    'damaged' => 'Χαλασμένο',
    'book_item' => 'Χρέωση Υλικού',
    'return_item' => 'Επιστροφή Υλικού',
    'barcode' => 'Barcode',
    'category' => 'Κατηγορία',
    'location' => 'Τοποθεσία',
    'status' => 'Κατάσταση',
    'department' => 'Τμήμα',
    'notes' => 'Σημειώσεις',
    'booking_history' => 'Ιστορικό Κρατήσεων',
    'overdue' => 'Εκπρόθεσμο',
    'mission_location' => 'Τοποθεσία Αποστολής',
    'expected_return' => 'Αναμενόμενη Επιστροφή'
];
```

### Appendix B: Mobile Testing Checklist

- [ ] iPhone SE (375px) - Portrait
- [ ] iPhone 12 (390px) - Portrait
- [ ] iPhone 12 Pro Max (428px) - Portrait
- [ ] iPad (768px) - Portrait & Landscape
- [ ] iPad Pro (1024px) - Portrait & Landscape
- [ ] Android (360px) - Samsung Galaxy S20
- [ ] Android (412px) - Pixel 5
- [ ] Desktop (1920px) - Chrome, Firefox, Safari
- [ ] Barcode scanner works on all devices
- [ ] Touch targets minimum 44x44px
- [ ] No horizontal scrolling
- [ ] Tables hide non-critical columns on mobile
- [ ] Forms stack vertically on mobile

### Appendix C: Performance Optimization

```sql
-- Add composite indexes
ALTER TABLE inventory_items 
ADD INDEX idx_dept_status_active (department_id, status, is_active);

ALTER TABLE inventory_bookings
ADD INDEX idx_item_status (item_id, status);

-- Optimize overdue query
ALTER TABLE inventory_bookings
ADD INDEX idx_overdue (status, created_at)
WHERE status = 'active';

-- Full-text search
ALTER TABLE inventory_items 
ADD FULLTEXT INDEX idx_fulltext (name, description, location_notes);

-- Usage:
SELECT * FROM inventory_items 
WHERE MATCH(name, description) AGAINST('φαρμακείο' IN NATURAL LANGUAGE MODE);
```

### Appendix D: Deployment Checklist

**Pre-deployment:**
- [ ] All unit tests passing
- [ ] Manual testing completed
- [ ] Database backup created
- [ ] Greek encoding verified (UTF-8)
- [ ] CSRF protection on all forms
- [ ] Debug mode = false
- [ ] Error logging enabled

**Deployment:**
- [ ] Run `sql/inventory_schema.sql` on production DB
- [ ] Copy all new files to production server
- [ ] Clear OpCache: `opcache_reset()`
- [ ] Test barcode scanner on production domain (HTTPS required)
- [ ] Configure cron job: `cron_inventory_overdue.php`
- [ ] Send test email notification

**Post-deployment:**
- [ ] Monitor error logs for 48 hours
- [ ] Check performance (page load < 2s)
- [ ] Verify department isolation
- [ ] Test mobile devices
- [ ] User training session
- [ ] Documentation update

---

## 🎯 Success Metrics

**After 1 Month:**
- [ ] 100+ inventory items added
- [ ] 50+ bookings processed
- [ ] 0 critical bugs reported
- [ ] Average page load < 1.5s
- [ ] Mobile usage > 30%
- [ ] User satisfaction > 80%

**After 3 Months:**
- [ ] 500+ items, 200+ bookings
- [ ] Analytics dashboard used weekly
- [ ] Export feature used monthly
- [ ] Overdue rate < 5%
- [ ] Department admins trained
- [ ] System integrated with mobile app

---

## 📞 Support & Resources

**Documentation:**
- FirstAid Manager source: `C:\Users\theo\Desktop\multi\`
- VolunteerOps repo: https://github.com/TheoSfak/volunteer-ops

**Libraries:**
- Quagga.js: https://github.com/serratus/quaggaJS
- Chart.js: https://www.chartjs.org/
- PHPSpreadsheet: https://phpspreadsheet.readthedocs.io/

**Testing:**
- XAMPP: http://localhost/volunteerops/
- phpMyAdmin: http://localhost/phpmyadmin

---

**Document Version:** 1.0.0  
**Last Updated:** February 18, 2026  
**Total Estimated Time:** 120 hours (6 weeks)  
**Complexity Rating:** ★★★★☆ (Advanced)

**Next Steps:** Begin Phase 1 - Create database schema and core CRUD pages.
