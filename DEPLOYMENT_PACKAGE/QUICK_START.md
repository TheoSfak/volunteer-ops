# VolunteerOps - Quick Start Guide for yphresies.gr

## 🎯 3-Minute Installation

### Method 1: Web Installer (Recommended)

1. **Upload Files**
   - Download latest release from GitHub
   - Extract `volunteerops.zip`
   - Upload entire folder to: `/public_html/volunteerops/`

2. **Run Installer**
   - Visit: `https://yphresies.gr/volunteerops/install.php`
   - Follow the 4-step wizard
   - Enter MySQL credentials (from cPanel)
   - Create admin account
   - Done! ✅

3. **Delete Installer**
   ```bash
   rm /public_html/volunteerops/install.php
   ```

### Method 2: Automated Script (Advanced)

```bash
# 1. Upload and extract package
cd /home/USERNAME/
unzip volunteerops-deployment.zip

# 2. Edit deploy.sh (set USERNAME)
nano deploy.sh

# 3. Run deployment
bash deploy.sh

# Follow prompts
```

---

## 🔐 Default Credentials

**After Installation:**
- URL: `https://yphresies.gr/volunteerops/`
- Email: Your admin email
- Password: What you set during installation

---

## ⚡ Quick Configuration (5 minutes)

### 1. General Settings
- **Path:** Settings → General
- Upload logo
- Set organization name
- Add description

### 2. Email Setup (REQUIRED for notifications)
- **Path:** Settings → SMTP Email
- Use hosting email credentials OR Gmail/Outlook

**Example (Gmail):**
```
Host: smtp.gmail.com
Port: 587
Encryption: TLS
Username: your-email@gmail.com
Password: app-password (not your regular password!)
From Email: your-email@gmail.com
From Name: yphresies.gr
```

### 3. Create First Department
- **Path:** Departments → Add New
- Example: "Επιχειρησιακό Κέντρο"

### 4. Create First Mission
- **Path:** Missions → New Mission
- Add title, dates, description
- Add shifts (time slots)

---

## 📱 Cron Jobs (Automated Notifications)

**Add in cPanel → Cron Jobs:**

**All-in-One (Recommended):**
```
0 8 * * * /usr/bin/php /home/USERNAME/public_html/volunteerops/cron_daily.php
```

**Or Separately:**
```
# Task reminders - Every 6 hours
0 */6 * * * /usr/bin/php /home/USERNAME/public_html/volunteerops/cron_task_reminders.php

# Shift reminders - Daily at 08:00
0 8 * * * /usr/bin/php /home/USERNAME/public_html/volunteerops/cron_shift_reminders.php

# Incomplete missions - Daily at 09:00
0 9 * * * /usr/bin/php /home/USERNAME/public_html/volunteerops/cron_incomplete_missions.php
```

**Important:** Replace `USERNAME` with your actual cPanel username!

---

## 🔍 Troubleshooting

### Problem: White screen or errors

**Solution:**
1. Check PHP version: Must be 8.0+
2. Enable error reporting:
   ```php
   // Add to config.php temporarily
   ini_set('display_errors', 1);
   error_reporting(E_ALL);
   ```
3. Check error logs in cPanel

### Problem: Greek characters show as ???

**Solution:**
```sql
-- Run in phpMyAdmin
ALTER DATABASE volunteerops CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Problem: Emails not sending

**Solution:**
1. Test with Settings → SMTP Email → Test Email
2. Check SMTP credentials
3. Try different port (587 or 465)
4. Contact hosting about SMTP restrictions

### Problem: Slow performance

**Solution:**
```bash
# Run indexes installer (if not done automatically)
php /public_html/volunteerops/add_indexes.php
```

---

## 📊 System Requirements

**Server:**
- PHP 8.0 or higher ✅
- MySQL 5.7+ or MariaDB 10.3+ ✅
- Apache/Nginx with mod_rewrite ✅
- 512MB RAM minimum (1GB+ recommended) ✅

**PHP Extensions:**
- PDO ✅
- pdo_mysql ✅
- mbstring ✅
- json ✅
- session ✅

**Browser:**
- Modern browser (Chrome, Firefox, Safari, Edge)
- JavaScript enabled

---

## 🎓 First Steps After Installation

1. **Change Admin Password**
   - Profile → Change Password

2. **Add Departments**
   - Structure your organization

3. **Create Users**
   - Import CSV or add manually
   - Assign roles (Admin, Shift Leader, Volunteer)

4. **Create First Mission**
   - Test the workflow
   - Add shifts
   - Approve participants

5. **Configure Notifications**
   - Settings → Notifications
   - Enable desired notification types

6. **Setup Gamification**
   - View achievements system
   - Check leaderboard

---

## 📞 Support

**Documentation:** Check `README.md` in package  
**GitHub:** https://github.com/TheoSfak/volunteer-ops  
**Issues:** Report bugs on GitHub Issues  

---

## 🔄 Updates

To update to a new version:

1. **Backup Everything**
   ```bash
   # Files
   tar -czf volunteerops_backup.tar.gz public_html/volunteerops/
   
   # Database
   mysqldump -u USERNAME -p DATABASE_NAME > volunteerops_db_backup.sql
   ```

2. **Upload New Files**
   - Overwrite existing files
   - Keep `config.local.php` and `uploads/` folder

3. **Run Migrations** (if any)
   - Check `/sql/migrations/` folder

4. **Clear Cache**
   - Settings → Clear Cache (if available)
   - Or logout and login again

---

**Version:** 2.0 - Performance Optimized  
**Ready for Production:** Yes ✅  
**Last Updated:** January 31, 2026
