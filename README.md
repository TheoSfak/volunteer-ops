# VolunteerOps

**Σύστημα Διαχείρισης Εθελοντών** (Volunteer Management System)

A Greek-language web application for managing volunteer missions, shifts, and participation with gamification features.

## Features

- **Mission Management** - Create, edit, and manage volunteer missions
- **Shift Scheduling** - Organize shifts with volunteer requirements
- **Participation System** - Volunteers apply for shifts, admins approve/reject
- **Attendance Tracking** - Mark volunteer attendance and hours
- **Gamification** - Points system with multipliers and achievements
- **Leaderboard** - Track top volunteers
- **Reports** - Statistics and analytics
- **Multi-role Access** - System Admin, Department Admin, Shift Leader, Volunteer

## Requirements

- PHP 8.0 or higher
- MySQL 8.0 or higher
- Apache/Nginx web server (XAMPP recommended for Windows)

## Installation

1. **Upload files** to your web server (e.g., `htdocs/volunteerops/`)

2. **Open installer** in your browser:
   ```
   http://localhost/volunteerops/install.php
   ```

3. **Follow the wizard:**
   - Step 1: Requirements check
   - Step 2: Database configuration
   - Step 3: Admin account creation
   - Step 4: Complete

4. **Login** with your admin credentials

## Default Login

After fresh installation:
- **Email:** admin@volunteerops.gr
- **Password:** admin123

⚠️ **Change the default password immediately after first login!**

## File Structure

```
volunteerops/
├── config.php              # Configuration
├── config.local.php        # Local overrides (gitignored)
├── bootstrap.php           # App bootstrap
├── includes/
│   ├── db.php              # Database functions
│   ├── auth.php            # Authentication
│   ├── functions.php       # Utilities
│   ├── header.php          # HTML header
│   └── footer.php          # HTML footer
├── sql/
│   └── schema.sql          # Database schema
├── install.php             # Web installer
└── *.php                   # Page controllers
```

## Pages

| Page | Description |
|------|-------------|
| `dashboard.php` | Main dashboard with stats |
| `missions.php` | Mission list |
| `mission-form.php` | Create/edit mission |
| `mission-view.php` | View mission details |
| `shifts.php` | Shift list |
| `shift-form.php` | Create/edit shift |
| `shift-view.php` | View shift, manage participants |
| `volunteers.php` | User management |
| `volunteer-view.php` | Volunteer profile |
| `leaderboard.php` | Points ranking |
| `my-points.php` | Personal points |
| `achievements.php` | Achievements list |
| `departments.php` | Department management |
| `reports.php` | Statistics reports |
| `settings.php` | System settings |
| `audit.php` | Audit log |
| `profile.php` | Edit profile |

## User Roles

1. **System Admin** (SYSTEM_ADMIN) - Full system access
2. **Department Admin** (DEPARTMENT_ADMIN) - Manage own department
3. **Shift Leader** (SHIFT_LEADER) - Manage shifts, approve participants
4. **Volunteer** (VOLUNTEER) - Apply for shifts, view own stats

## Points System

- **Base:** 10 points per hour
- **Weekend bonus:** ×1.5
- **Night shift bonus:** ×1.5 (22:00-06:00)
- **Medical mission bonus:** ×2.0

## License

MIT License

## Support

For issues or questions, please open a GitHub issue.
