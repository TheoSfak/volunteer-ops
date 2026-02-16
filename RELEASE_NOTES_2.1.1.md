## 🎉 Version 2.1.1 - Bug Fixes & New Features

### ✨ New Features
- **Mission-level Volunteer Assignment**: Admins can now manually assign volunteers to multiple shifts at once from the mission view page
- **Multiple Shift Selection**: When assigning a volunteer, admins can select multiple shifts in a single operation

### 🐛 Bug Fixes
- Fixed all email template dynamic variables (volunteer_name → user_name)
- Added missing email variables: shift_time and location to all notification emails
- Fixed undefined array key warnings in shift-view.php (description, location, required_skills)
- Improved error handling for missing ZipArchive PHP extension

### 🔧 Improvements
- Changed rejection button text to "Ακύρωση Συμμετοχής" for better clarity
- Removed test user filters after database cleanup
- Enhanced notification emails with more complete information

### 🛠️ Utilities Added
- `delete_test_users.php` - Utility for cleaning test data from database
- `enable_zip_extension.php` - Helper page with instructions for enabling PHP zip extension

### 📧 Email System
All notification emails now properly display:
- User name
- Shift date and time (separated)
- Mission location
- All other dynamic fields

### 🔄 Update Instructions
1. Backup your current installation
2. Use the built-in update system at `/update.php`
3. Or manually download and extract this release
