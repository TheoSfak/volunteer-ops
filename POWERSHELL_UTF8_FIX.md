# PowerShell UTF-8 Configuration Guide

## Πρόβλημα
Το PowerShell στα Windows έχει πρόβλημα με τα ελληνικά και γενικά με UTF-8 χαρακτήρες.

## Λύσεις για Μελλοντική Χρήση

### 1. Προσωρινή Λύση (για τρέχουσα session)
Πριν τρέξεις οποιαδήποτε εντολή με ελληνικά, εκτέλεσε:

```powershell
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.Encoding]::UTF8
chcp 65001
```

### 2. Μόνιμη Λύση (για όλες τις sessions)
Πρόσθεσε τις παραπάνω εντολές στο PowerShell Profile:

```powershell
# Άνοιγμα του profile
notepad $PROFILE

# Αν δεν υπάρχει το αρχείο, δημιούργησέ το:
New-Item -Path $PROFILE -Type File -Force
notepad $PROFILE
```

Πρόσθεσε στο αρχείο:
```powershell
# UTF-8 Support για ελληνικά
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.Encoding]::UTF8
```

### 3. Χρήση PHP Scripts αντί για SQL μέσω PowerShell
**ΠΡΟΤΕΙΝΟΜΕΝΗ ΛΥΣΗ**: Για εισαγωγή ελληνικών στη βάση, χρησιμοποίησε ΠΑΝΤΑ PHP scripts όπως:
- `restore_notifications.php`
- `restore_task_templates.php`

Γιατί: Το PHP χειρίζεται σωστά το UTF8MB4 χωρίς προβλήματα κωδικοποίησης.

### 4. Εναλλακτικά: Χρήση MySQL Command Line
Αντί για PowerShell, μπορείς να χρησιμοποιήσεις το MySQL command line:

```cmd
C:\xampp\mysql\bin\mysql.exe -u root -hlocalhost volunteerops < script.sql
```

Το CMD (Command Prompt) χειρίζεται καλύτερα το UTF-8 από το PowerShell.

### 5. Windows Terminal (Modern Solution)
Κατέβασε το Windows Terminal από το Microsoft Store. Έχει πολύ καλύτερη υποστήριξη UTF-8:
- Settings → Profiles → PowerShell → Font: "Cascadia Code" ή "Consolas"
- Settings → Appearance → "Use new text renderer"

## Σύνοψη
**Για το VolunteerOps project:**
- ✅ Χρησιμοποίησε PHP scripts για ελληνικά (όπως κάνουμε τώρα)
- ❌ Μην τρέχεις SQL με ελληνικά απευθείας στο PowerShell
- ⚠️ Αν πρέπει οπωσδήποτε να χρησιμοποιήσεις PowerShell, τρέξε πρώτα το chcp 65001

## Test
Για να δοκιμάσεις αν δουλεύει:
```powershell
chcp 65001
echo "Ελληνικά: Αυτό είναι δοκιμή"
```
