# VolunteerOps - Οδηγίες Εγκατάστασης

## Απαιτήσεις Συστήματος

- PHP 8.2+
- MySQL 8.0+
- Composer (για εγκατάσταση dependencies)
- Apache/Nginx με mod_rewrite ενεργοποιημένο

### Απαιτούμενες PHP Extensions
- BCMath, Ctype, Fileinfo, JSON, Mbstring, OpenSSL, PDO, PDO_MySQL, Tokenizer, XML

---

## Μέθοδος 1: Εγκατάσταση από ZIP (Shared Hosting)

### Βήμα 1: Ανέβασμα αρχείων
1. Αποσυμπιέστε το αρχείο `volunteerops-deploy.zip`
2. Ανεβάστε ΟΛΑ τα αρχεία στον server σας
3. Ο φάκελος `public/` πρέπει να είναι ο web root (ή κάντε symlink)

### Βήμα 2: Ρύθμιση βάσης δεδομένων
1. Δημιουργήστε μια νέα MySQL βάση δεδομένων (π.χ. `volunteer_ops`)
2. Εισάγετε το SQL αρχείο:
   - **Μόνο δομή (χωρίς δεδομένα):** `database/schema.sql`
   - **Πλήρης (με δεδομένα δοκιμής):** `database/volunteer_ops_full.sql`

```bash
mysql -u USERNAME -p DATABASE_NAME < database/schema.sql
```

### Βήμα 3: Ρύθμιση περιβάλλοντος
1. Αντιγράψτε το `.env.example` σε `.env`
2. Επεξεργαστείτε το `.env` με τις ρυθμίσεις σας:

```env
APP_NAME=VolunteerOps
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=volunteer_ops
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password
```

### Βήμα 4: Δημιουργία κλειδιού εφαρμογής

**Επιλογή A: Με SSH πρόσβαση**
```bash
php artisan key:generate
```

**Επιλογή B: Χωρίς SSH (Shared Hosting)**

1. Επισκεφθείτε: https://generate-random.org/laravel-key-generator
2. Αντιγράψτε το κλειδί που δημιουργείται (μορφή: `base64:XXXXX...`)
3. Ανοίξτε το `.env` και προσθέστε/επεξεργαστείτε:
```env
APP_KEY=base64:ΤΟ_ΚΛΕΙΔΙ_ΠΟΥ_ΑΝΤΙΓΡΑΨΑΤΕ
```

**Επιλογή Γ: Χρήση του web installer**

Αν δεν έχετε SSH, μπορείτε να ανεβάσετε προσωρινά αυτό το αρχείο ως `generate-key.php` στο `public/`:

```php
<?php
// Διαγράψτε αυτό το αρχείο ΑΜΕΣΩΣ μετά τη χρήση!
$key = 'base64:' . base64_encode(random_bytes(32));
echo "Προσθέστε αυτό στο .env σας:<br><br>";
echo "<code>APP_KEY=" . $key . "</code>";
echo "<br><br><strong style='color:red'>ΔΙΑΓΡΑΨΤΕ ΑΥΤΟ ΤΟ ΑΡΧΕΙΟ ΤΩΡΑ!</strong>";
```

Επισκεφθείτε `https://yourdomain.com/generate-key.php`, αντιγράψτε το κλειδί, και **διαγράψτε αμέσως το αρχείο**.

### Βήμα 6: Δικαιώματα φακέλων

**Μέσω cPanel File Manager:**
- Κάντε δεξί κλικ στο `storage/` → Change Permissions → 755 ή 775
- Το ίδιο για `bootstrap/cache/`

**Μέσω SSH:**
```bash
chmod -R 775 storage
chmod -R 775 bootstrap/cache
```

### Βήμα 7: Storage Link

**Επιλογή A: Με SSH**
```bash
php artisan storage:link
```

**Επιλογή B: Χωρίς SSH (Shared Hosting)**

Δημιουργήστε χειροκίνητα symbolic link μέσω cPanel:
1. Πηγαίνετε στο **cPanel → File Manager**
2. Διαγράψτε το `public/storage` αν υπάρχει
3. Στο terminal του cPanel ή μέσω SSH:
   ```bash
   ln -s ../storage/app/public public/storage
   ```

Εναλλακτικά, ανεβάστε προσωρινά αυτό ως `public/storage-link.php`:
```php
<?php
// Διαγράψτε ΑΜΕΣΩΣ μετά τη χρήση!
$target = __DIR__ . '/../storage/app/public';
$link = __DIR__ . '/storage';
if (file_exists($link)) { echo "Το link υπάρχει ήδη!"; exit; }
if (symlink($target, $link)) {
    echo "Storage link δημιουργήθηκε επιτυχώς!<br>";
    echo "<strong style='color:red'>ΔΙΑΓΡΑΨΤΕ ΑΥΤΟ ΤΟ ΑΡΧΕΙΟ ΤΩΡΑ!</strong>";
} else {
    echo "Αποτυχία. Δοκιμάστε μέσω cPanel File Manager.";
}
```

---

## Μέθοδος 2: Εγκατάσταση από GitHub

```bash
# Κλωνοποίηση repository
git clone https://github.com/TheoSfak/volunteer-ops.git
cd volunteer-ops

# Εγκατάσταση dependencies
composer install --optimize-autoloader --no-dev

# Ρύθμιση περιβάλλοντος
cp .env.example .env
php artisan key:generate

# Εκτέλεση migrations
php artisan migrate

# (Προαιρετικά) Εισαγωγή δοκιμαστικών δεδομένων
php artisan db:seed
```

---

## Ρύθμιση για Shared Hosting (cPanel/DirectAdmin)

Αν ο server σας απαιτεί το `public_html` ως web root:

### Επιλογή A: Μετακίνηση public
1. Ανεβάστε όλα τα αρχεία ΕΚΤΟΣ του `public/` σε φάκελο πάνω από το public_html (π.χ. `/home/user/volunteerops/`)
2. Αντιγράψτε τα περιεχόμενα του `public/` στο `public_html/`
3. Επεξεργαστείτε το `public_html/index.php`:

```php
require __DIR__.'/../volunteerops/vendor/autoload.php';
$app = require_once __DIR__.'/../volunteerops/bootstrap/app.php';
```

### Επιλογή B: .htaccess redirect
Δημιουργήστε `.htaccess` στο root:
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
```

---

## Προεπιλεγμένοι Λογαριασμοί

Μετά την εκτέλεση του seeder:

| Ρόλος | Email | Κωδικός |
|-------|-------|---------|
| System Admin | admin@volunteerops.gr | password123 |
| Volunteer | volunteer@volunteerops.gr | password123 |

⚠️ **ΣΗΜΑΝΤΙΚΟ:** Αλλάξτε αμέσως τους κωδικούς μετά την πρώτη σύνδεση!

---

## Αντιμετώπιση Προβλημάτων

### 500 Error
- Ελέγξτε τα δικαιώματα `storage/` και `bootstrap/cache/`
- Βεβαιωθείτε ότι υπάρχει το `.env` με σωστές ρυθμίσεις

### Λευκή σελίδα
- Ενεργοποιήστε προσωρινά `APP_DEBUG=true` στο `.env`
- Ελέγξτε το `storage/logs/laravel.log`

### CSS/JS δεν φορτώνουν
- Εκτελέστε `php artisan storage:link`
- Ελέγξτε το `APP_URL` στο `.env`

---

## Υποστήριξη

- GitHub: https://github.com/TheoSfak/volunteer-ops
- Issues: https://github.com/TheoSfak/volunteer-ops/issues
