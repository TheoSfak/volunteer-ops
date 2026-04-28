# VolunteerOps

**Σύστημα Διαχείρισης Εθελοντών** — Greek-language volunteer mission management system for rescue and civil protection organisations. Plain PHP/MySQL, no frameworks, deployable on any standard web host.

**Version:** 3.63.21
**Author:** Theodore Sfakianakis
**Email:** theodore.sfakianakis@gmail.com

![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0+-4479A1?logo=mysql&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?logo=bootstrap&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)
![Version](https://img.shields.io/badge/version-3.63.21-blue)

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
- Confetti popup on new achievement unlock
- Backfill achievements tool for existing volunteers
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
- Volunteer Activity Report (Print/PDF)

### 🏢 Organisational Structure
- Departments and sub-departments
- Branches
- Warehouses (linked to inventory)
- Volunteer positions
- Skills catalogue

### 📦 Inventory Management
- Full equipment CRUD with categories and warehouse locations
- Shelf/location tracking within warehouses
- Equipment kits (grouped items) with barcode label printing
- Book and return equipment per volunteer (including kit return via barcode)
- Inventory notes log
- Cron job for shelf expiry alerts

### 🎓 Training & Exams
- Training materials library with file downloads
- **Exam module:** create exams, assign question pools, take exams online
- **Quiz module:** quick knowledge-check quizzes with random pool selection
- Question pool with multiple-choice and true/false types
- First-aid question bank (pre-seeded)
- Admin exam management, question editor, statistics, and CSV export
- Per-volunteer exam results history

### 📜 Certificates
- Certificate type catalogue
- Issue and track certificates per volunteer with expiry dates
- Cron job for expiry notifications
- Citizen certificate types and issuance
- Citizen certificate expiry cron job

### 👤 Citizens Module
- Citizens register (name, contact, history)
- Citizen certificate issuance and tracking

### 📢 Complaints System
- Public-facing complaint submission form
- Admin complaint management: New → In Review → Resolved | Rejected
- Per-volunteer complaint history view

### 📰 Newsletter System
- Create and send newsletters to all or filtered subscriber groups
- Newsletter log with open/send tracking
- Unsubscribe link support

### 🔔 Notifications & Email
- In-app notification centre with unread badge
- Per-user notification preferences (opt in/out per event type)
- **DB-driven email templates** — editable from admin UI without code changes
- Email template preview with live variable substitution
- Email send log viewer & analytics
- Automated cron jobs: shift reminders (24 h), cert expiry, overdue mission alerts, task due-date reminders

### ✔️ Task Management
- Create and assign tasks to volunteers or departments
- Task view with status tracking and comments
- Due-date reminders via cron

### 🗺️ Live Ops
- Real-time GPS pings and field status updates
- Live operations dashboard with active/upcoming mission overview
- Broadcast messages to field volunteers
- Volunteer GPS map with clustered pins

### 📊 Reports & Analytics
- Admin dashboard: missions, volunteers, hours, pending requests (with charts)
- Volunteer dashboard: personal shifts, points, upcoming schedule
- Ops dashboard: live operational overview
- Mega analytics & reports page
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
- Schema consolidation & security hardening (v3.39.0)
- Composite DB indexes for performance (v3.27.3)

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
- PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `curl` (for push notifications), `zip` (optional, for exports)

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

## Changelog

### v3.63.21 - 2026-04-28 (Latest)
- Keep bulk attendance no-shows as absent instead of showing them as QR check-ins

### v3.63.20 - 2026-04-28
- Make QR check-in count as attended participation in reports and history

### v3.63.19 - 2026-04-28
- Sort active mission lists by soonest upcoming start date first

### v3.63.18 - 2026-04-28
- Sort volunteers by the stored surname-first name format

### v3.63.17 - 2026-04-28
- Normalize newsletter default template selection across production sites

### v3.63.16 - 2026-04-28
- Sort volunteers and citizens alphabetically by surname

### v3.63.15 - 2026-04-28
- Sort active/available mission coverage lists newest date first

### v3.63.14 - 2026-04-28
- Add bulk dashboard workflow for overdue mission attendance, points, coverage, and completion

### v3.63.13 - 2026-04-28
- Fix newsletter template rendering so preview and sent email use the same email-safe design

### v3.63.12 - 2026-04-28
- Harden PWA caching, push CSRF, private upload access, and push runtime guards

### v3.53.7 — 2026-03-04
- Swap sidebar nav links & layout adjustments

### v3.53.x — 2026-03-04
- v3.53.6: Phone number display fixes
- v3.53.5: Mobile layout improvements
- v3.53.4: Swap sidebar elements
- v3.53.3: Restore previous navigation structure
- v3.53.2: Fix various UI issues
- v3.53.1: Fix sidebar/nav bugs
- v3.53.0: Shift management overhaul

### v3.52.x — 2026-03-03
- v3.52.9: Code cleanup round
- v3.52.8: Deep UI refactor
- v3.52.7: Health check improvements
- v3.52.6: Component completion pass
- v3.52.5: Deep feature review
- v3.52.4 / v3.52.3: Calendar improvements
- v3.52.2: UI beautification
- v3.52.1: ICS export fixes
- v3.52.0: FullCalendar integration overhaul

### v3.51.x — 2026-03-03
- v3.51.2: Email system improvements
- v3.51.0: Σύστημα notifications overhaul

### v3.50.x — 2026-03-02
- v3.50.2 / v3.50.1: Task management fixes
- v3.50.0: Εγχειρίδιο (manual/help) module

### v3.49.0 — 2026-03-01
- Application performance improvements

### v3.48.x — 2026-03-01
- v3.48.1: Bug fix
- v3.48.0: Enforce strict role access control

### v3.47.x — 2026-02-27
- v3.47.7: Performance optimisations
- v3.47.6: Skip irrelevant migrations
- v3.47.5 / v3.47.4 / v3.47.3 / v3.47.2 / v3.47.1: Email template migration & styling fixes
- v3.47.0: All email templates fully styled

### v3.46.x — 2026-02-27
- v3.46.1: Styled citizen cert email templates
- v3.46.0: Citizen Certificate Expiry Notifications

### v3.45.0 — 2026-02-27
- Citizens Module (citizens register, certificate issuance, expiry tracking)

### v3.42.0 — 2026-02-27
- Notification Center (in-app + per-user preferences)

### v3.41.0 — 2026-02-27
- Fix GPS location access

### v3.40.x — 2026-02-27
- v3.40.3: Shelf expiry email template
- v3.40.2 / v3.40.1: Cron execution fixes
- v3.40.0: Manual Cron Job Execution from admin UI

### v3.39.0 — 2026-02-27
- Schema Consolidation & Security Hardening

### v3.38.0 — 2026-02-27
- Security Hardening (CSRF, PDO, output escaping audit)

### v3.37.x — 2026-02-26 / 2026-02-27
- v3.37.7: Fix Yahoo spam placement
- v3.37.6: Auto-create email_logs table
- v3.37.5: Email Delivery Logs & Analytics
- v3.37.4: Fix Yahoo Email Delivery
- v3.37.3: Database Query Performance Optimization (composite indexes)
- v3.37.2: Quiz/Exam navigation warning
- v3.37.1: Quiz retake fix
- v3.37.0: Quiz random pool selection

### v3.36.x — 2026-02-26
- v3.36.5 / v3.36.4 / v3.36.3: Question pool seed & schema fixes
- v3.36.2 / v3.36.1: Question pool UI
- v3.36.0: Reset Data (admin tool)

### v3.35.0 — v3.33.0 — 2026-02-25
- True/False question save bug fix, bulletproof TF quiz scoring, complete quiz system fix

### v3.32.0 — 2026-02-25
- Dashboard greeting & motivational quotes

### v3.31.0 — 2026-02-25
- Security hardening pass

### v3.30.0 — 2026-02-25
- Mobile card view for tables (responsive overhaul)

### v3.29.x — 2026-02-25
- v3.29.1: Backfill achievements tool
- v3.29.0: Expanded achievements + confetti popup

### v3.28.x — 2026-02-25
- v3.28.4 / v3.28.3 / v3.28.2: Footer on auth pages
- v3.28.1: Forgot Password & no self-registration option
- v3.28.0: Email verification & admin approval on registration

### v3.27.x — 2026-02-24 / 2026-02-25
- v3.27.9: Fix invalid volunteer type in email targeting
- v3.27.8: Position badge in volunteers list
- v3.27.7: Draft missions sidebar link
- v3.27.6: Position targeting for mission email
- v3.27.5: Targeted email on mission publish
- v3.27.4: Fix missions CSV export
- v3.27.3: Performance — composite DB indexes + auto-migration
- v3.27.2: Educational missions progress bar
- v3.27.0: Simplify volunteer type system

### v3.26.x — 2026-02-23 / 2026-02-24
- v3.26.18: Hide attendance button on open missions
- v3.26.5: Google Calendar integration for shift notifications
- v3.26.4: Clickable phone numbers
- v3.26.2: Random pool questions for exams
- v3.26.1: Exam system overhaul
- v3.26.0: Enriched volunteer dashboard

### v3.25.x — 2026-02-23
- v3.25.9: Attendance progress bar in profile
- v3.25.2: Beautified dashboard
- v3.25.1: Beautified volunteer profiles
- v3.25.0: Εύρεση προσφύγων / Συντονισμός αποστολών

### v3.24.0 — 2026-02-23
- Mega Analytics & Reports Page

### v3.23.x — 2026-02-23
- v3.23.0: Overdue mission alerts & COMPLETED mission lock

### v3.22.x — 2026-02-23
- v3.22.0: Certificate expiry tracking (default 3 years)

### v3.21.0 — 2026-02-23
- Αυξήσεις ειδοποιήσεων αρχείου

### v3.20.0 / v3.19.0 — 2026-02-23
- Code smell fixes & code quality audit

### v3.18.0 — 2026-02-23
- Mass add volunteers to missions/shifts

### v3.17.x — 2026-02-22 / 2026-02-23
- v3.17.4: Fix duplicate debrief error
- v3.17.3: Keep completed missions on Ops Dashboard
- v3.17.2: Return equipment kits via barcode
- v3.17.1: Print labels for equipment kits
- v3.17.0: Equipment Kits module

### v3.16.x — 2026-02-22
- v3.16.2: Fix map markers
- v3.16.1: Dismiss "needs help" alerts
- v3.16.0: Post-mission debrief (Ανάφορά Μετά την Αποστολή)

### v3.15.x — 2026-02-22
- v3.15.20: Ops Dashboard — active vs upcoming missions
- v3.15.0: Live Ops — GPS pings, field status & broadcast

### v3.14.0 — 2026-02-22
- Επιχειρησιακό dashboard

### v3.13.x — 2026-02-22
- v3.13.28: Search volunteers by skill
- v3.13.26: Admin skill management in volunteer profile
- v3.13.22: Volunteer Activity Report (Print/PDF)
- v3.13.21: Google Calendar link in approval emails
- v3.13.18: Mass delete backups
- v3.13.12: Logo in all email headers
- v3.13.11: Beautiful email templates + enable all notifications

---

## License

MIT License — see [LICENSE](LICENSE) for full terms.

## Support

For issues or questions, please open a [GitHub issue](https://github.com/TheoSfak/volunteer-ops/issues).
