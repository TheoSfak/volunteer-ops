# Changelog

All notable changes to VolunteerOps.

### v3.63.25 - 2026-04-28 (Latest)
- Harden attendance and hour counting across QR check-in, bulk attendance, and point reversal

### v3.63.24 - 2026-04-28
- Add AFM field to volunteer personal details, import, and export

### v3.63.23 - 2026-04-28
- Rename shelf inventory quantity labels to item number

### v3.63.22 - 2026-04-28
- Add seminar type dropdown to citizen registration, list, search, and CSV export

### v3.63.21 - 2026-04-28
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
