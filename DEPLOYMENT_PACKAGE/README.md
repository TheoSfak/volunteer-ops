# VolunteerOps - Automated Deployment Package for yphresies.gr

## 📦 Package Contents

This automated deployment package includes:
- Complete VolunteerOps application
- Automated installation script
- Database setup with indexes
- Configuration wizard
- Backup system

## 🚀 Installation Steps

### 1. Upload Files

Upload the entire `volunteerops` folder to your server:
```bash
/public_html/volunteerops/
```

### 2. Set Permissions

```bash
chmod -R 755 /public_html/volunteerops
chmod -R 777 /public_html/volunteerops/uploads
chmod -R 777 /public_html/volunteerops/backups
```

### 3. Run Installer

Navigate to: `https://yphresies.gr/volunteerops/install.php`

Follow the wizard:
- Database connection (MySQL credentials from cPanel)
- Admin account creation
- Email settings (SMTP)
- Optional demo data

### 4. Delete Install Files (IMPORTANT!)

After successful installation:
```bash
rm /public_html/volunteerops/install.php
rm /public_html/volunteerops/add_indexes.php
rm -rf /public_html/volunteerops/sql
```

### 5. Setup Cron Jobs (Optional but Recommended)

Add to cPanel Cron Jobs:

**Daily at 08:00** - All notifications
```
0 8 * * * /usr/bin/php /home/USERNAME/public_html/volunteerops/cron_daily.php
```

OR separately:

**Every 6 hours** - Task deadline reminders
```
0 */6 * * * /usr/bin/php /home/USERNAME/public_html/volunteerops/cron_task_reminders.php
```

**Every day at 08:00** - Shift reminders (24h before)
```
0 8 * * * /usr/bin/php /home/USERNAME/public_html/volunteerops/cron_shift_reminders.php
```

**Every day at 09:00** - Incomplete mission alerts
```
0 9 * * * /usr/bin/php /home/USERNAME/public_html/volunteerops/cron_incomplete_missions.php
```

## 📊 Database Requirements

- MySQL 5.7+ or MariaDB 10.3+
- PHP 8.0+
- PDO PHP Extension
- mbstring PHP Extension

## 🔒 Security Checklist

- [ ] Deleted install.php after installation
- [ ] Changed default admin password
- [ ] Set proper file permissions (755 for files, 777 only for uploads/backups)
- [ ] Configured SMTP settings
- [ ] Enabled HTTPS (SSL certificate)
- [ ] Configured database backups in cPanel

## 🎨 Post-Installation Configuration

1. **General Settings** (Settings → General)
   - Organization name
   - Logo upload
   - Description
   - Time zone

2. **Email Settings** (Settings → SMTP)
   - SMTP credentials from your provider
   - Test email functionality

3. **Notification Preferences** (Settings → Notifications)
   - Enable/disable specific notification types

4. **Create Departments** (Administration → Departments)
   - Add your organization's departments

5. **Create Users** (Administration → Volunteers)
   - Import from CSV or create manually

## 🆘 Troubleshooting

### Installation fails

- Check database credentials
- Verify PHP version (8.0+ required)
- Check file permissions
- Review browser console for errors

### Greek characters show as ???

- Database charset must be UTF8MB4
- PHP mbstring extension must be enabled
- Browser encoding set to UTF-8

### Emails not sending

- Verify SMTP credentials
- Check that ports are not blocked (587, 465)
- Use "Test Email" feature in Settings

### Performance issues

- Run `add_indexes.php` if not done automatically
- Enable PHP OPcache
- Consider upgrading hosting plan

## 📞 Support

For issues or questions:
- GitHub: https://github.com/TheoSfak/volunteer-ops
- Email: support@yphresies.gr

## 🔄 Updates

To update to a new version:
1. Backup current installation
2. Upload new files (overwrite)
3. Run any new migration scripts in `/sql/migrations/`
4. Clear browser cache

---

**Version:** 2.0  
**Last Updated:** January 31, 2026  
**Optimized for:** Performance & Production Use
