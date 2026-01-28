# VolunteerOps - Σύστημα Διαχείρισης Εθελοντών

![Laravel](https://img.shields.io/badge/Laravel-10.x-FF2D20?logo=laravel)
![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php)
![License](https://img.shields.io/badge/License-MIT-green)

Ελληνόφωνο σύστημα διαχείρισης εθελοντικών αποστολών με gamification features, REST API και responsive web interface.

## ✨ Χαρακτηριστικά

- 📋 **Διαχείριση Αποστολών** - Δημιουργία, παρακολούθηση και ολοκλήρωση αποστολών
- 👥 **Διαχείριση Εθελοντών** - Προφίλ, δεξιότητες, ιστορικό συμμετοχών
- 📅 **Βάρδιες** - Προγραμματισμός βαρδιών με αυτόματο έλεγχο διαθεσιμότητας
- 🏆 **Gamification** - Πόντοι, επιτεύγματα, κατάταξη εθελοντών
- 🔐 **Πολλαπλοί Ρόλοι** - Admin, Department Admin, Shift Leader, Volunteer
- 📊 **Αναφορές & Στατιστικά** - Πλήρες dashboard με analytics
- 📱 **Mobile-First** - Responsive design με Bootstrap 5

## 🚀 Γρήγορη Εγκατάσταση (Shared Hosting)

### Βήμα 1: Κατεβάστε
Κατεβάστε την τελευταία έκδοση από τα [Releases](https://github.com/TheoSfak/volunteer-ops/releases)

### Βήμα 2: Ανεβάστε
Αποσυμπιέστε και ανεβάστε τα αρχεία στον server σας

### Βήμα 3: Εγκαταστήστε
Επισκεφθείτε `https://yourdomain.com/install.php` και ακολουθήστε τον οδηγό!

> 📖 Δείτε το [INSTALLATION.md](INSTALLATION.md) για αναλυτικές οδηγίες

---

## 💻 Εγκατάσταση για Developers

### Απαιτήσεις

- PHP 8.2+
- Composer
- MySQL 8.x
- Node.js (προαιρετικό)

### Εγκατάσταση

1. **Κλωνοποίηση του project**
```bash
git clone https://github.com/TheoSfak/volunteer-ops.git
cd volunteer-ops
```

2. **Εγκατάσταση εξαρτήσεων**
```bash
composer install
```

3. **Ρύθμιση περιβάλλοντος**
```bash
cp .env.example .env
php artisan key:generate
```

4. **Ρύθμιση βάσης δεδομένων**

Επεξεργαστείτε το `.env` και ορίστε τα στοιχεία της βάσης:
```
DB_DATABASE=volunteer_ops
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

5. **Εκτέλεση migrations και seeders**
```bash
php artisan migrate
php artisan db:seed
```

6. **Εκκίνηση server ανάπτυξης**
```bash
php artisan serve
```

## Προεπιλεγμένοι Χρήστες

Μετά το seeding, οι παρακάτω χρήστες είναι διαθέσιμοι:

| Email | Password | Ρόλος |
|-------|----------|-------|
| admin@volunteerops.gr | password123 | Διαχειριστής Συστήματος |
| health@volunteerops.gr | password123 | Διαχειριστής Τομέα |
| environment@volunteerops.gr | password123 | Διαχειριστής Τομέα |
| leader1@volunteerops.gr | password123 | Αρχηγός Βάρδιας |
| volunteer1@volunteerops.gr | password123 | Εθελοντής |

## API Endpoints

### Αυθεντικοποίηση (`/api/auth`)
- `POST /register` - Εγγραφή νέου χρήστη
- `POST /login` - Σύνδεση
- `POST /logout` - Αποσύνδεση
- `GET /me` - Προφίλ τρέχοντα χρήστη
- `PUT /update-profile` - Ενημέρωση προφίλ
- `PUT /change-password` - Αλλαγή κωδικού
- `POST /refresh-token` - Ανανέωση token

### Τμήματα (`/api/departments`)
- `GET /` - Λίστα τμημάτων
- `POST /` - Δημιουργία τμήματος
- `GET /{id}` - Προβολή τμήματος
- `PUT /{id}` - Ενημέρωση τμήματος
- `DELETE /{id}` - Διαγραφή τμήματος
- `GET /{id}/users` - Χρήστες τμήματος

### Εθελοντές (`/api/volunteers`)
- `GET /` - Λίστα εθελοντών
- `GET /search` - Αναζήτηση
- `GET /stats` - Στατιστικά
- `GET /{id}` - Προβολή εθελοντή
- `PUT /{id}` - Ενημέρωση εθελοντή
- `GET /{id}/history` - Ιστορικό

### Αποστολές (`/api/missions`)
- `GET /` - Λίστα αποστολών
- `POST /` - Δημιουργία αποστολής
- `GET /{id}` - Προβολή αποστολής
- `PUT /{id}` - Ενημέρωση αποστολής
- `DELETE /{id}` - Διαγραφή αποστολής
- `POST /{id}/publish` - Δημοσίευση
- `POST /{id}/close` - Κλείσιμο
- `POST /{id}/cancel` - Ακύρωση
- `GET /{id}/stats` - Στατιστικά

### Βάρδιες (`/api/missions/{missionId}/shifts`)
- `GET /` - Λίστα βαρδιών αποστολής
- `POST /` - Δημιουργία βάρδιας
- `GET /{id}` - Προβολή βάρδιας
- `PUT /{id}` - Ενημέρωση βάρδιας
- `DELETE /{id}` - Διαγραφή βάρδιας
- `POST /{id}/lock` - Κλείδωμα βάρδιας
- `GET /{id}/volunteers` - Εθελοντές βάρδιας

### Συμμετοχές (`/api/participations`)
- `POST /apply` - Υποβολή αιτήματος
- `GET /my-participations` - Οι συμμετοχές μου
- `GET /pending` - Εκκρεμή αιτήματα
- `POST /{id}/approve` - Έγκριση
- `POST /{id}/reject` - Απόρριψη
- `DELETE /{id}` - Ακύρωση

### Έγγραφα (`/api/documents`)
- `GET /` - Λίστα εγγράφων
- `POST /` - Δημιουργία εγγράφου
- `GET /{id}` - Προβολή εγγράφου
- `PUT /{id}` - Ενημέρωση εγγράφου
- `DELETE /{id}` - Διαγραφή εγγράφου
- `GET /{id}/download` - Λήψη αρχείου

### Αρχεία (`/api/files`)
- `POST /upload` - Μεταφόρτωση αρχείου
- `GET /{id}` - Λήψη αρχείου
- `DELETE /{id}` - Διαγραφή αρχείου

### Ειδοποιήσεις (`/api/notifications`)
- `GET /` - Λίστα ειδοποιήσεων
- `GET /unread-count` - Αριθμός μη αναγνωσμένων
- `PATCH /{id}/read` - Σήμανση ως αναγνωσμένη
- `PATCH /read-all` - Σήμανση όλων

### Audit Log (`/api/audit-logs`)
- `GET /` - Λίστα καταγραφών
- `GET /entity/{type}/{id}` - Ιστορικό οντότητας

### Αναφορές (`/api/reports`)
- `GET /dashboard` - Στατιστικά Dashboard
- `GET /missions` - Αναφορά αποστολών
- `GET /shifts` - Αναφορά βαρδιών
- `GET /volunteers` - Αναφορά εθελοντών
- `GET /participations` - Αναφορά συμμετοχών
- `GET /departments` - Αναφορά τμημάτων
- `GET /export/{type}` - Εξαγωγή

## Ρόλοι Χρηστών

| Ρόλος | Περιγραφή |
|-------|-----------|
| SYSTEM_ADMIN | Πλήρης πρόσβαση σε όλο το σύστημα |
| DEPARTMENT_ADMIN | Διαχείριση του τμήματος του |
| SHIFT_LEADER | Διαχείριση βαρδιών και εγκρίσεις |
| VOLUNTEER | Βασικές λειτουργίες εθελοντή |

## Αρχιτεκτονική

Το project χρησιμοποιεί modular αρχιτεκτονική:

```
app/
├── Modules/
│   ├── Auth/
│   ├── Directory/
│   ├── Volunteers/
│   ├── Missions/
│   ├── Shifts/
│   ├── Participation/
│   ├── Documents/
│   ├── Notifications/
│   ├── Audit/
│   └── Reports/
├── Models/
├── Providers/
├── Http/
│   ├── Controllers/
│   └── Middleware/
└── Exceptions/
```

Κάθε module περιέχει:
- Controllers
- Services
- Requests (Form Requests)
- Policies
- Events/Listeners
- Models (όπου απαιτείται)
- routes.php

## Άδεια

MIT License
