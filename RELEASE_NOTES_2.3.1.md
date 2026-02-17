## 🧹 Version 2.3.1 - Production Test Data Cleanup Utility

### ✨ New Feature
**cleanup_test_data.php** - Production-safe administrative tool for removing test data from live database

### 🎯 What It Does
- **Automatic Detection**: 
  - Test users: Searches for "test", "debug", or "demo" in names and emails
  - Test missions: Searches for "test", "δοκιμή", or "demo" in titles/descriptions

- **Statistics Preview**: Shows complete data before deletion
  - Users: Participations count, points earned, notifications
  - Missions: Number of shifts, participants count

- **Safe Cascading Deletes**:
  - Users: Removes notifications, volunteer_points, participation_requests
  - Missions: Removes participation_requests, volunteer_points, shifts

- **Selective Control**: Checkbox selection with "Select All" and confirmation dialogs

### 🔒 Security
- System admin-only access
- CSRF token protection
- Double confirmation required
- Warning message about permanent deletion

### 🏭 Production Ready
- Compatible with production database schema
- No transaction dependencies
- No task_assignments table references
- Greek error messages

### 📋 Perfect For
- Cleaning up after development testing
- Removing demo data after presentations
- Clearing training session data
- QA testing cleanup

### 🚀 How to Use
1. Upload file to production server
2. Login as system admin
3. Visit `/cleanup_test_data.php`
4. Review the detected test data
5. Select items to delete
6. Confirm and execute

---
**Note**: This tool permanently deletes data. Always backup before use!
