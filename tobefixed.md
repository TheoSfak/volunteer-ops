# VolunteerOps - To Be Fixed (v2.4.5+)

Audit date: 2026-02-17 (post v2.4.4 release)

---

## CRITICAL (7)

- [x] **C1: config.php:38** — `DEBUG_MODE` hardcoded to `true`. `display_errors=1` active in production, leaks stack traces/DB details. Set to `false`. **FIXED**
- [x] **C2: fix_greek.php** — Hardcoded DB creds (`root`/empty), no auth guard. Publicly accessible, drops/rewrites data. **DELETED**
- [x] **C3: delete_test_mission.php** — No `requireLogin()`/`requireRole()`. Anyone can delete missions. **DELETED**
- [x] **C4: enable_zip_extension.php** — No auth guard, exposes PHP config info. **DELETED**
- [x] **C5: test_error.php** — No auth guard + `display_errors=1`. Public debug page. **DELETED**
- [x] **C6: test_full.php** — No auth guard, `display_errors=1`. Test suite exposes app internals. **DELETED**
- [x] **C7: training-material-download.php:29** — HTTP header injection: unsanitized `$material['title']` in `Content-Disposition`. Sanitize newlines/quotes from filename. **FIXED**

---

## HIGH (10)

- [x] **H1: login.php:15** — `isPost()` handler missing `verifyCsrf()`. Login form vulnerable to CSRF. **FIXED**
- [x] **H2: test_greek.php** — No auth guard, test/debug page accessible without login. **DELETED**
- [x] **H3: check_mission_template.php** — No auth guard, exposes internal template data. **DELETED**
- [x] **H4: restore_mission_template.php** — No auth guard, modifies DB templates. **DELETED**
- [x] **H5: restore_notifications.php** — No auth guard, modifies notification data. **DELETED**
- [x] **H6: restore_task_templates.php** — No auth guard, modifies task templates. **DELETED**
- [x] **H7: run_cohort_migration.php** — No auth guard, runs DB migrations publicly. **DELETED**
- [x] **H8: includes/export-functions.php:1** — Missing `defined('VOLUNTEEROPS')` guard. **FIXED**
- [x] **H9: includes/import-functions.php:1** — Missing `defined('VOLUNTEEROPS')` guard. **FIXED**
- [x] **H10: install.php:10** — `error_reporting(E_ALL)` + `display_errors=1` always on (outside DEBUG_MODE check). **FIXED - only enabled during fresh installation**

---

## MEDIUM (8)

- [x] **M1: 80+ hardcoded status strings** — `'PENDING'`, `'APPROVED'`, `'REJECTED'`, `'DRAFT'`, `'OPEN'`, `'CLOSED'`, `'COMPLETED'`, `'CANCELED'` used directly instead of constants (`PARTICIPATION_PENDING`, `STATUS_DRAFT`, etc.) across 16+ files: dashboard.php, shift-view.php, mission-view.php, missions.php, participations.php, my-participations.php, volunteers.php, volunteer-view.php, attendance.php, reports.php, profile.php, shifts.php, tasks.php, task-view.php, task-form.php, includes/header.php. **FIXED**
- [x] **M2: training-material-download.php:7,14,21** — Uses `die('...')` for error handling instead of redirect with flash message. **FIXED**
- [x] **M3: email-template-preview.php:14,20** — Uses `die('Template not found')` instead of redirect with flash. **FIXED - now uses HTML error display**
- [x] **M4: run_quiz_migration.php:9** — Uses `die('Migration file not found!')` instead of proper error handling. **DELETED**
- [x] **M5: cron_daily.php** — No auth guard. Should block web access or check CLI mode. **FIXED - CLI check added**
- [x] **M6: cron_shift_reminders.php** — Same as M5. **FIXED - CLI check added**
- [x] **M7: cron_incomplete_missions.php** — Same as M5. **FIXED - CLI check added**
- [x] **M8: cron_task_reminders.php** — Same as M5. **FIXED - CLI check added**

---

## LOW (5)

- [ ] **L1: training-material-download.php:28** — `Content-Type` set from DB value `$material['file_type']` without validation. (Note: C7 fixed filename sanitization)
- [x] **L2: missions.php:12** — `get('status', 'OPEN')` uses hardcoded default instead of `STATUS_OPEN`. **FIXED**
- [ ] **L3: dashboard.php:786-788** — PHP vars injected raw into JavaScript (integers from COUNT, safe but no `intval()` cast).
- [ ] **L4: logout.php** — No `requireLogin()` (harmless but unnecessary access for unauthenticated visitors). Note: Now has CSRF protection for POST.
- [ ] **L5: Various files** — `<?= $variable ?>` without `h()` on DB integers/computed values. Safe but not defensive.

---

## Quick Win Strategy

### Phase 1 — Delete junk files (5 min) ✅ COMPLETED
Delete: `fix_greek.php`, `delete_test_mission.php`, `enable_zip_extension.php`, `test_error.php`, `test_greek.php`, `check_mission_template.php`, `restore_mission_template.php`, `restore_notifications.php`, `restore_task_templates.php`, `run_cohort_migration.php`, `run_quiz_migration.php`

### Phase 2 — Quick security fixes (15 min) ✅ COMPLETED
- Set `DEBUG_MODE = false` in config.php ✅
- Add `verifyCsrf()` to login.php POST handler ✅
- Add `defined('VOLUNTEEROPS')` guard to export-functions.php and import-functions.php ✅
- Sanitize filename in training-material-download.php ✅
- Add `requireRole([ROLE_SYSTEM_ADMIN])` to test_full.php (if keeping) ✅ **DELETED INSTEAD**
- Add CLI check to cron scripts: `if (php_sapi_name() !== 'cli') die('CLI only');` ✅

### Phase 3 — Hardcoded enums refactor (1-2 hrs) ✅ COMPLETED
Replace 80+ string literals with constants across 16 files. **COMPLETED**

---

## 📊 COMPLETION SUMMARY

**Total Issues: 30**
- ✅ **FIXED: 26** (87%)
- ⏳ **REMAINING: 4** (13%) - All LOW priority

**By Priority:**
- 🔴 **CRITICAL (7/7):** 100% FIXED ✅
- 🟠 **HIGH (10/10):** 100% FIXED ✅
- 🟡 **MEDIUM (8/8):** 100% FIXED ✅
- 🔵 **LOW (1/5):** 20% FIXED (4 remaining, all non-blocking)

**Remaining Issues (LOW priority only):**
- L1: Content-Type validation in training-material-download.php
- L3: JavaScript integer casting in dashboard.php
- L4: logout.php requireLogin() (cosmetic)
- L5: Defensive h() on integer outputs (cosmetic)

**🎉 ALL CRITICAL, HIGH, and MEDIUM issues have been resolved!**

---

## Recommended action plan (UPDATED):
- ✅ Delete utility/test scripts from production
- ✅ Set DEBUG_MODE = false in config.php
- ✅ Add verifyCsrf() to login.php + sanitize header in training-material-download.php
- ✅ Add include guards to export-functions.php and import-functions.php
- ✅ Hardcoded enums — COMPLETED
- ⏳ Remaining LOW priority items can be addressed in future maintenance
