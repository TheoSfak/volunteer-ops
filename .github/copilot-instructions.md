# VolunteerOps - AI Coding Agent Instructions

## Project Overview
Greek-language plain PHP/MySQL volunteer mission management system. Web-only frontend with Bootstrap 5 and gamification features.

**Stack:** PHP 8.0+, MySQL 8 (MariaDB compatible), Session-based auth, Bootstrap 5.3.2 (CDN)  
**Environment:** XAMPP Windows - `http://localhost/volunteerops/`  
**Live DB:** `volunteer_ops` (MySQL, user: root, no password)  
**Current version:** `APP_VERSION` in `config.php` — bump on every feature commit  
**No frameworks** - Plain PHP with PDO for database

## Architecture

### File Structure
```
volunteerops/
├── config.php              # Constants, database defaults, role/status definitions
├── config.local.php        # Local DB credentials (created by installer, gitignored)
├── bootstrap.php           # Entry point - includes all files, starts session
├── includes/
│   ├── db.php              # PDO Database singleton & helper functions
│   ├── auth.php            # Session auth, login, logout, role checks
│   ├── functions.php       # Utilities (h(), redirect(), flash, CSRF, dates, badges)
│   ├── email.php           # SMTP email sending & notification templates
│   ├── header.php          # HTML header, sidebar navigation, CSS
│   └── footer.php          # Footer, JS includes
├── sql/
│   └── schema.sql          # Full database schema + seed data (DROP/CREATE)
├── install.php             # Web installer wizard (creates config.local.php)
├── test_full.php           # Comprehensive test suite (85+ tests)
└── [page].php              # All page controllers in root folder
```

### Database Connection Pattern
```php
// Helper functions from includes/db.php - ALWAYS use these
$rows = dbFetchAll("SELECT * FROM users WHERE role = ?", [ROLE_VOLUNTEER]);
$user = dbFetchOne("SELECT * FROM users WHERE id = ?", [$id]);
$count = dbFetchValue("SELECT COUNT(*) FROM missions");
$id = dbInsert("INSERT INTO users (name, email) VALUES (?, ?)", [$name, $email]);
$affected = dbExecute("UPDATE users SET name = ? WHERE id = ?", [$name, $id]);
```

## Key Constants (config.php)

**All status/role constants are UPPERCASE strings in DB:**
```php
// User roles (hierarchical)
ROLE_SYSTEM_ADMIN | ROLE_DEPARTMENT_ADMIN | ROLE_SHIFT_LEADER | ROLE_VOLUNTEER

// Mission lifecycle: DRAFT → OPEN → CLOSED → COMPLETED | CANCELED
STATUS_DRAFT | STATUS_OPEN | STATUS_CLOSED | STATUS_COMPLETED | STATUS_CANCELED

// Participation lifecycle: PENDING → APPROVED | REJECTED | CANCELED_BY_*
PARTICIPATION_PENDING | PARTICIPATION_APPROVED | PARTICIPATION_REJECTED | 
PARTICIPATION_CANCELED_BY_USER | PARTICIPATION_CANCELED_BY_ADMIN

// Greek labels via arrays: ROLE_LABELS, STATUS_LABELS, PARTICIPATION_LABELS

// Gamification multipliers
POINTS_PER_HOUR = 10, WEEKEND_MULTIPLIER = 1.5, NIGHT_MULTIPLIER = 1.5, MEDICAL_MULTIPLIER = 2.0
```

## Page Structure Template
**Every page follows this exact pattern:**
```php
<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();                           // or: requireRole([ROLE_SYSTEM_ADMIN])

$pageTitle = 'Τίτλος Σελίδας';            // MUST be Greek

// Handle POST actions BEFORE any HTML output
if (isPost()) {
    verifyCsrf();
    // process form...
    setFlash('success', 'Η ενέργεια ολοκληρώθηκε.');
    redirect('same-page.php');
}

// Fetch data for display
$data = dbFetchAll("SELECT ...");

include __DIR__ . '/includes/header.php';
?>
<!-- HTML content with Bootstrap 5 classes -->
<?php include __DIR__ . '/includes/footer.php'; ?>
```

## Helper Functions Quick Reference

### Authentication (includes/auth.php)
```php
isLoggedIn()                              // Check if user logged in
getCurrentUser()                          // Get full user array (cached)
getCurrentUserId()                        // Get user ID only
requireLogin()                            // Redirect to login if not auth
requireRole([ROLE_SYSTEM_ADMIN])          // Require specific role(s)
isAdmin()                                 // SYSTEM_ADMIN or DEPARTMENT_ADMIN
isSystemAdmin()                           // SYSTEM_ADMIN only
hasRole(ROLE_SHIFT_LEADER)                // Check specific role
login($email, $password)                  // Returns ['success'=>bool, 'message'=>str]
logout()
```

### Utilities (includes/functions.php)
```php
h($string)                                // Escape HTML (ALWAYS use in output)
redirect($url)                            // Header redirect + exit
setFlash('success|error|warning', 'Msg')  // Flash message
displayFlash()                            // Echo flash as Bootstrap alert
isPost()                                  // Check if POST request
post('field', 'default')                  // Get sanitized POST value
get('field', 'default')                   // Get sanitized GET value
csrfField()                               // Returns <input> for CSRF token
verifyCsrf()                              // Validate CSRF, redirect on failure
formatDate($date)                         // Returns 'd/m/Y'
formatDateTime($date)                     // Returns 'd/m/Y H:i'
formatDateGreek($date)                    // 'Δευτέρα, 5 Φεβρουαρίου 2026'
statusBadge($status)                      // Bootstrap badge HTML
roleBadge($role)                          // Bootstrap badge HTML
logAudit($action, $table, $id)            // Log to audit_log table
getSetting($key, $default)                // Get from settings table
```

## Greek Language - CRITICAL
**All UI text MUST be Greek. Variables, functions, and code stay English.**
```php
// Flash messages - always Greek
setFlash('success', 'Οι αλλαγές αποθηκεύτηκαν επιτυχώς.');
setFlash('error', 'Δεν βρέθηκε η εγγραφή.');

// Common terms:
// Αποστολές (Missions), Βάρδιες (Shifts), Εθελοντές (Volunteers)
// Αποθήκευση (Save), Ακύρωση (Cancel), Επεξεργασία (Edit), Διαγραφή (Delete)
// Εκκρεμεί (Pending), Εγκεκριμένη (Approved), Απορρίφθηκε (Rejected)
// Τμήμα (Department), Επείγον (Urgent), Ολοκληρωμένη (Completed)
```

## Critical Database Tables
| Table | Key Fields | Notes |
|-------|-----------|-------|
| `users` | id, name, email, role, volunteer_type, department_id, total_points | role ENUM + volunteer_type ENUM |
| `missions` | id, title, status, start_datetime, end_datetime, cancellation_reason | Has soft delete |
| `shifts` | id, mission_id, start_time, end_time, max_volunteers | Cascade delete |
| `participation_requests` | id, shift_id, volunteer_id, status, rejection_reason, notes, admin_notes, decided_by, decided_at | Unique(shift_id,volunteer_id) |
| `volunteer_points` | id, user_id, points, source, shift_id | Points history |
| `notifications` | id, user_id, type, title, message, data, read_at | In-app notifications |
| `email_templates` | id, code, name, subject, body_html, is_active | DB-stored email templates |
| `notification_settings` | id, code, name, email_enabled, email_template_id | Controls which notifications send email |
| `audit_log` | id, user_id, action, table_name, record_id, details | Every admin action logged |
| `settings` | setting_key, setting_value | App-wide settings via `getSetting()` |

## Key Workflows

### Adding New Page
1. Create `new-page.php` in root folder
2. Start with `require_once __DIR__ . '/bootstrap.php';`
3. Set `$pageTitle` (Greek)
4. Handle POST before any HTML
5. Include header.php at top, footer.php at bottom
6. Add navigation link in `includes/header.php` sidebar

### Database Changes
1. Edit `sql/schema.sql` (add new columns/tables)
2. Re-run installer OR manually execute ALTER statements
3. **CRITICAL:** Always check form field names match DB column names

### Participation Flow
1. Volunteer applies → `participation_requests` status=PENDING
2. Admin approves/rejects/reactivates → status updated, `decided_by`+`decided_at` set
3. All status changes send email via `sendNotificationEmail()` + in-app via `sendNotification()`
4. After shift ends → admin marks attendance → points calculated via local `calculatePoints($shift, $hours)` in `shift-view.php`
5. Points written to `volunteer_points` + `users.total_points` incremented

### Email Notification System
Emails are **template-driven from the DB**, not hardcoded:
```php
// Check DB notification_settings table first, then email_templates table
if (isNotificationEnabled('participation_approved')) {
    sendNotificationEmail('participation_approved', $email, [
        'user_name' => $name, 'mission_title' => $title, ...
    ]);
}
// In-app only (no email):
sendNotification($userId, 'Τίτλος', 'Μήνυμα');
```
Existing notification codes: `participation_approved`, `participation_rejected`, `admin_added_volunteer`, `shift_reminder`, `mission_canceled`.
To add new: INSERT into `notification_settings` + `email_templates` in `sql/schema.sql`.

### Version Bump & Release (REQUIRED on every commit to GitHub)
**Every single commit must include a version bump, tag, AND GitHub release. No exceptions.**
```powershell
# 1. Bump APP_VERSION in config.php (both xampp AND volunteer-ops-github)
# 2. Copy all changed files to volunteer-ops-github/
# 3. git add . ; git commit -m "feat: description vX.Y.Z"
# 4. git tag vX.Y.Z ; git push origin main --tags
# 5. gh release create vX.Y.Z --title "vX.Y.Z - Short description" --notes "## What's New`n`n### feat: ...\n- bullet points"
```
Version format: `MAJOR.MINOR.PATCH` — increment PATCH for fixes/small features, MINOR for significant features.

### Delete Confirmations
Missions and shifts with participants require Bootstrap modal showing affected volunteers, then notifications to all.

## Test Suite
Run comprehensive tests: `php test_full.php`
- Tests all pages, forms, CRUD operations
- Uses cURL with cookie persistence for session
- Admin credentials: admin@volunteerops.gr / admin123

## File Responsibilities
| File | Purpose |
|------|---------|
| `dashboard.php` | Role-based dashboard with stats cards |
| `missions.php` / `mission-form.php` / `mission-view.php` | Mission CRUD + status lifecycle |
| `shifts.php` / `shift-form.php` / `shift-view.php` | Shift CRUD + participant management |
| `my-participations.php` | Volunteer's own participation list |
| `volunteers.php` / `volunteer-view.php` | User management + volunteer profile |
| `import-volunteers.php` | Bulk CSV import via `includes/import-functions.php` |
| `leaderboard.php` / `achievements.php` | Gamification |
| `inventory*.php` | Equipment inventory (book/return/warehouse/shelf/label) — blocked for `TRAINEE_RESCUER` |
| `exam-*.php` / `training*.php` / `questions-pool.php` | Training & exam module |
| `newsletters.php` / `newsletter-*.php` | Newsletter system via `includes/newsletter-functions.php` |
| `branches.php` / `departments.php` / `skills.php` | Organisational structure |
| `tasks.php` | Task management |
| `cron_*.php` | Cron jobs — run via CLI scheduler, not web |
| `email-template-edit.php` / `email-template-preview.php` | Manage DB email templates |
| `settings.php` | System settings (system admin) |
| `audit.php` | Audit log viewer (system admin) |
| `includes/inventory-functions.php` | Inventory business logic |
| `includes/export-functions.php` | Excel/CSV export helpers |
| `includes/migrations.php` | DB migration runner |

## Common Patterns

### Multiple POST Actions (switch/case)
Pages with multiple actions (approve, reject, delete, etc.) use a `switch` on `post('action')`:
```php
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    $prId = (int) post('participation_id');
    switch ($action) {
        case 'approve': ...; break;
        case 'reject':  ...; break;
        case 'reactivate': ...; break;
    }
    redirect('shift-view.php?id=' . $id);
}
```

### Volunteer Type Access Control
`TRAINEE_RESCUER` volunteers are blocked from inventory and certain features:
```php
if (isTraineeRescuer()) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
    redirect('dashboard.php');
}
// Display badge in tables:
echo volunteerTypeBadge($p['volunteer_type'] ?? VTYPE_VOLUNTEER);
```

### Pagination
```php
$total = dbFetchValue("SELECT COUNT(*) FROM items WHERE ...");
$pagination = paginate($total, (int)get('page', 1), 20);
$items = dbFetchAll("SELECT * FROM items LIMIT ? OFFSET ?",
    [$pagination['per_page'], $pagination['offset']]);
// In HTML:
echo paginationLinks($pagination);
```

### Form with Edit/Create Mode
```php
$id = get('id');
$isEdit = !empty($id);
$item = $isEdit ? dbFetchOne("SELECT * FROM items WHERE id = ?", [$id]) : null;
if ($isEdit && !$item) {
    setFlash('error', 'Δεν βρέθηκε η εγγραφή.');
    redirect('items.php');
}
```

### Bootstrap Modal with Confirmation
```html
<button data-bs-toggle="modal" data-bs-target="#deleteModal">Διαγραφή</button>
<div class="modal fade" id="deleteModal">
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete">
        <!-- Modal content -->
    </form>
</div>
```

## ⚠️ Common Mistakes to Avoid
1. **Field mismatch**: Always verify form field names match DB column names
2. **Missing h()**: Always escape output with `h($variable)`
3. **Missing CSRF**: Every POST form needs `<?= csrfField() ?>`
4. **English in UI**: All user-visible text must be Greek
5. **Hardcoded emails**: Use `sendNotificationEmail()` with DB templates, not raw `sendEmail()`
6. **Skipping version bump/tag/release**: Every GitHub commit MUST bump `APP_VERSION`, create a git tag, AND create a `gh release` — all three, every time
7. **calculatePoints/calculateShiftHours**: Defined locally in `shift-view.php`, not in a global include
8. **Direct DB queries**: Use helper functions (dbFetchAll, etc.), never raw PDO
