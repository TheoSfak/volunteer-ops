# VolunteerOps

**Σύστημα Διαχείρισης Εθελοντών** — Greek-language volunteer mission management system for rescue and civil protection organisations. Plain PHP/MySQL, no frameworks, deployable on any standard web host.

![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0+-4479A1?logo=mysql&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?logo=bootstrap&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)

---

## Features

### 🗺️ Mission Management
- Full mission lifecycle: **Draft → Open → Closed → Completed | Canceled**
- Custom mission types with icons and colour coding
- Urgent flag, location, requirements, and notes fields
- Per-department visibility and filtering
- Post-mission debrief reports (objectives, rating, incidents, equipment issues)
- Overdue mission detection with admin alerts
- T.E.P. (Τ.Ε.Π.) access-controlled mission type

### 📅 Shift Scheduling
- Multiple shifts per mission with configurable volunteer capacity
- Interactive calendar view (FullCalendar integration)
- ICS/iCal export for personal calendar sync
- Real-time slot availability tracking (approved / pending / capacity)

### 🙋 Participation System
- Volunteers apply for individual shifts with optional notes
- Admins approve, reject, or reactivate requests with reasons
- Bulk approve/reject from participation list
- Manual admin-add of volunteers to shifts (with email notification)
- Volunteer self-cancellation of pending requests
- Full participation history per volunteer

### ✅ Attendance & Points
- Per-shift attendance marking with actual hours recording
- Bulk attendance save per shift
- **Points calculation with multipliers:**
  - Base: 10 pts/hour
  - Weekend: ×1.5
  - Night shift (22:00–06:00): ×1.5
  - Medical mission: ×2.0
- Points history log per volunteer
- One-click points award after attendance confirmation

### 🏆 Gamification
- Achievement badges (auto-awarded based on activity milestones)
- Leaderboard with department and date filters
- Personal points dashboard with contribution stats

### 👥 Volunteer Management
- Four roles: **System Admin · Department Admin · Shift Leader · Volunteer**
- Two volunteer types: **Rescuer** and **Trainee Rescuer** (restricted access)
- Self-registration with email verification + admin approval workflow
- Forgot / reset password by email
- Cohort year tracking
- Volunteer positions (roles within the organisation)
- Skills tagging per volunteer
- Inactive volunteer management and bulk status updates
- Bulk CSV import with field mapping
- Per-volunteer profile page with participation stats, points, certificates, and documents
- Volunteer document upload and download

### 🏢 Organisational Structure
- Departments and sub-departments
- Branches
- Warehouses (linked to inventory)
- Volunteer positions
- Skills catalogue

### 📦 Inventory Management
- Full equipment CRUD with categories and warehouse locations
- Shelf/location tracking within warehouses
- Equipment kits (grouped items)
- Book and return equipment per volunteer
- Barcode label printing
- Inventory notes log
- Cron job for shelf expiry alerts

### 🎓 Training & Exams
- Training materials library with file downloads
- **Exam module:** create exams, assign question pools, take exams online
- **Quiz module:** quick knowledge-check quizzes
- Question pool with multiple-choice and true/false types
- First-aid question bank (pre-seeded)
- Admin exam management, question editor, statistics, and CSV export
- Per-volunteer exam results history

### 📜 Certificates
- Certificate type catalogue (volunteer certificates)
- Issue and track certificates per volunteer with expiry dates
- Cron job for expiry notifications
- Citizen certificate types and citizen certificate issuance
- Citizen certificate expiry cron job

### 👤 Citizens Module
- Citizens register (name, contact, history)
- Citizen certificate issuance and tracking

### 📢 Complaints System
- Public-facing complaint submission form
- Admin complaint management with status workflow (New → In Review → Resolved | Rejected)
- Per-volunteer complaint history view

### 📰 Newsletter System
- Create and send newsletters to all or filtered subscriber groups
- Newsletter log with open/send tracking
- Unsubscribe link support

### 🔔 Notifications
- In-app notification centre with unread badge
- Per-user notification preferences (opt in/out per event type)
- **DB-driven email templates** — editable from the admin UI without code changes
- Email template preview with live variable substitution
- Email send log viewer
- Automated cron jobs:
  - Shift reminders (24 h before)
  - Certificate expiry warnings
  - Incomplete mission alerts
  - Task due-date reminders

### ✔️ Task Management
- Create and assign tasks to volunteers or departments
- Task view with status tracking and comments
- Due-date reminders via cron

### 📊 Reports & Analytics
- Admin dashboard: missions, volunteers, hours, pending requests (with charts)
- Volunteer dashboard: personal shifts, points, upcoming schedule
- Ops dashboard: live operational overview
- Volunteer report: per-volunteer detailed activity export
- Exam statistics with CSV export
- Audit log: every admin action recorded with user, table, record, and details

### 🔒 Security & Quality
- Session-based authentication with role hierarchy enforcement
- CSRF token on every POST form
- All output escaped with `h()` / `htmlspecialchars`
- Fully parameterised PDO queries — no string concatenation in SQL
- Audit log for accountability
- `.htaccess` protection for sensitive directories

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.0+, plain PDO (no ORM) |
| Database | MySQL 8 / MariaDB |
| Frontend | Bootstrap 5.3.2 (CDN), Bootstrap Icons |
| Calendar | FullCalendar 6 |
| Charts | Chart.js |
| Email | PHPMailer (SMTP) |
| Auth | PHP sessions |

---

## Requirements

- PHP 8.0 or higher
- MySQL 8.0 or MariaDB
- Apache / Nginx (XAMPP recommended on Windows)
- PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `zip` (optional, for exports)

---

## Installation

1. **Upload files** to your web server directory (e.g., `htdocs/volunteerops/`)

2. **Run the web installer:**
   ```
   http://localhost/volunteerops/install.php
   ```

3. **Follow the wizard** (requirements check → DB config → admin account → done)

4. **Log in** with your admin credentials

> **Default credentials after fresh install:**
> - Email: `admin@volunteerops.gr`
> - Password: `admin123`
>
> ⚠️ Change the default password immediately after first login!

---

## File Structure

```
volunteerops/
├── config.php              # App constants and configuration
├── config.local.php        # Local DB credentials (gitignored)
├── bootstrap.php           # Bootstraps includes and session
├── includes/
│   ├── db.php              # PDO singleton + helper functions
│   ├── auth.php            # Authentication + role checks
│   ├── functions.php       # Utilities, CSRF, flash, helpers
│   ├── email.php           # SMTP + template-driven emails
│   ├── export-functions.php
│   ├── import-functions.php
│   ├── inventory-functions.php
│   ├── newsletter-functions.php
│   ├── training-functions.php
│   ├── achievements-functions.php
│   ├── migrations.php
│   ├── header.php          # HTML header + sidebar nav
│   └── footer.php          # Footer + JS
├── sql/
│   └── schema.sql          # Full schema + seed data (DROP/CREATE)
├── install.php             # Web installer wizard
├── test_full.php           # Automated test suite (85+ tests)
└── [page].php              # All page controllers in root
```

---

## User Roles

| Role | Access |
|------|--------|
| **System Admin** | Full system access, settings, audit log, user approval |
| **Department Admin** | Manage missions and volunteers within own department |
| **Shift Leader** | Manage shifts, approve/reject participation requests |
| **Volunteer** | Apply for shifts, view own stats, take exams |

> Volunteers with type **Trainee Rescuer** have restricted access (inventory blocked, certain features hidden).

---

## Cron Jobs

| Script | Purpose | Recommended schedule |
|--------|---------|----------------------|
| `cron_shift_reminders.php` | Email reminders 24 h before shifts | Every hour |
| `cron_certificate_expiry.php` | Warn about expiring volunteer certificates | Daily |
| `cron_citizen_cert_expiry.php` | Warn about expiring citizen certificates | Daily |
| `cron_incomplete_missions.php` | Alert admins about overdue open missions | Daily |
| `cron_shelf_expiry.php` | Warn about inventory shelf expiry | Daily |
| `cron_task_reminders.php` | Remind assignees of due tasks | Daily |
| `cron_daily.php` | General daily housekeeping | Daily |

Run via CLI: `php /path/to/volunteerops/cron_shift_reminders.php`

---

## License

MIT License

## Support

For issues or questions, please open a GitHub issue.
