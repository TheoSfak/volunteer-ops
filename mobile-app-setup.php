<?php
/**
 * VolunteerOps - Android App Setup Guide
 * Explains what the native Android app does and, critically, the phone
 * settings a volunteer must configure for background GPS to actually survive
 * a locked screen — permissions alone aren't enough on most Android phones,
 * OEM battery optimization silently kills the tracking service otherwise
 * (see includes/auth.php's WAR_ROOM_ACTION_SCRIPTS comment for the
 * real incident this whole feature exists to prevent). Download button is
 * deliberately at the bottom, after the setup steps, not at the top — a
 * volunteer who installs without doing these steps first gets an app that
 * looks like it works but silently stops tracking within the first shift.
 */
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Εφαρμογή Android';
$currentPage = 'mobile-app-setup';

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width: 720px;">

    <div class="card pp-card accent-success mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-android2 text-success me-2"></i>Εφαρμογή Android για το Επιχειρησιακό</h5>
        </div>
        <div class="card-body">
            <p class="mb-0">Όταν είστε σε ενεργή βάρδια, το Επιχειρησιακό στέλνει αυτόματα το στίγμα σας ανά τακτό διάστημα. Μέσα από τον browser αυτό σταματά μόλις κλειδώσει η οθόνη σας ή αλλάξετε εφαρμογή — δεν φταίει το πρόγραμμά μας, είναι περιορισμός του λειτουργικού συστήματος του κινητού. Η εφαρμογή Android λύνει ακριβώς αυτό: συνεχίζει να στέλνει το στίγμα σας ακόμη και με κλειδωμένη οθόνη, <strong>αλλά μόνο αν ρυθμίσετε το κινητό σας σωστά παρακάτω</strong> — η εγκατάσταση από μόνη της δεν αρκεί.</p>
        </div>
    </div>

    <div class="card pp-card accent-primary mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-list-ol text-primary me-2"></i>Βήματα Ρύθμισης</h5>
        </div>
        <div class="card-body">

            <div class="d-flex gap-3 mb-4">
                <div class="flex-shrink-0"><span class="badge bg-primary rounded-circle p-2" style="width:32px;height:32px;">1</span></div>
                <div>
                    <strong>Εγκατάσταση</strong>
                    <p class="text-muted mb-0">Κατεβάστε την εφαρμογή με το κουμπί στο τέλος αυτής της σελίδας. Το Android θα εμφανίσει προειδοποίηση για «εγκατάσταση από άγνωστη πηγή» — είναι αναμενόμενο, η εφαρμογή δεν διανέμεται μέσω Google Play. Πατήστε «Εγκατάσταση ούτως ή άλλως» / «Install anyway».</p>
                </div>
            </div>

            <div class="d-flex gap-3 mb-4">
                <div class="flex-shrink-0"><span class="badge bg-primary rounded-circle p-2" style="width:32px;height:32px;">2</span></div>
                <div>
                    <strong>Σύνδεση</strong>
                    <p class="text-muted mb-0">Ανοίξτε την εφαρμογή και συνδεθείτε με τον ίδιο λογαριασμό που χρησιμοποιείτε κανονικά.</p>
                </div>
            </div>

            <div class="d-flex gap-3 mb-4">
                <div class="flex-shrink-0"><span class="badge bg-primary rounded-circle p-2" style="width:32px;height:32px;">3</span></div>
                <div>
                    <strong>Άδεια Τοποθεσίας</strong>
                    <p class="text-muted mb-0">Μπείτε σε μια ενεργή αποστολή στο Επιχειρησιακό. Θα εμφανιστεί αίτημα άδειας τοποθεσίας — επιλέξτε «Επιτρέπεται» και, αν σας ρωτήσει για ακρίβεια, «Ακριβής τοποθεσία» (όχι κατά προσέγγιση).</p>
                </div>
            </div>

            <div class="d-flex gap-3 mb-4">
                <div class="flex-shrink-0"><span class="badge bg-primary rounded-circle p-2" style="width:32px;height:32px;">4</span></div>
                <div>
                    <strong>Άδεια Ειδοποιήσεων</strong>
                    <p class="text-muted mb-0">Επιτρέψτε τις ειδοποιήσεις όταν σας ζητηθεί. Χρειάζεται ώστε το Android να εμφανίζει τη μόνιμη ειδοποίηση «Η τοποθεσία σας κοινοποιείται…» όσο η βάρδια είναι ενεργή — αυτή η ειδοποίηση είναι υποχρεωτική από το ίδιο το Android, όχι κάτι δικό μας, και είναι επίσης ο τρόπος σας να βλέπετε ότι λειτουργεί.</p>
                </div>
            </div>

            <div class="d-flex gap-3">
                <div class="flex-shrink-0"><span class="badge bg-danger rounded-circle p-2" style="width:32px;height:32px;">5</span></div>
                <div>
                    <strong>Απενεργοποίηση εξοικονόμησης μπαταρίας για την εφαρμογή <span class="text-danger">(το πιο σημαντικό βήμα)</span></strong>
                    <p class="text-muted mb-2">Τα περισσότερα κινητά Android σταματούν εφαρμογές που «τρέχουν στο παρασκήνιο» για να εξοικονομήσουν μπαταρία — ακριβώς αυτό όμως χρειάζεται η εφαρμογή για να λειτουργήσει. Χωρίς αυτό το βήμα, το στίγμα θα σταματά ξανά μετά από λίγη ώρα, ίδιο πρόβλημα με τον browser.</p>
                    <p class="text-muted mb-2"><strong>Γενικά βήματα:</strong> Ρυθμίσεις → Εφαρμογές → <em><?= h(getSetting('app_name', 'VolunteerOps')) ?></em> → Μπαταρία → επιλέξτε «Χωρίς περιορισμούς» (Unrestricted) αντί για «Βελτιστοποιημένη».</p>
                    <p class="text-muted mb-0 small">Η ακριβής ονομασία διαφέρει ανά κατασκευαστή. Σε Xiaomi/MIUI, Samsung, Huawei και Oppo/Realme υπάρχουν συνήθως επιπλέον ρυθμίσεις μπαταρίας (π.χ. «Autostart», «Put app to sleep», «App launch») — αν παρατηρήσετε ότι το στίγμα σταματά παρόλο που κάνατε τα παραπάνω, ελέγξτε αν το κινητό σας έχει κάποια από αυτές και απενεργοποιήστε τις για την εφαρμογή.</p>
                </div>
            </div>

        </div>
    </div>

    <div class="alert alert-info d-flex gap-3 mb-4">
        <i class="bi bi-apple fs-3 flex-shrink-0"></i>
        <div>
            <strong>Χρήστες iPhone</strong>
            <p class="mb-0">Δεν υπάρχει ακόμη εφαρμογή για iPhone. Συνεχίστε να χρησιμοποιείτε την εφαρμογή που έχετε ήδη εγκαταστήσει από τον browser σας (Προσθήκη στην Αρχική Οθόνη) — το ξέρουμε ότι το στίγμα μπορεί να σταματήσει με κλειδωμένη οθόνη, εργαζόμαστε σε λύση.</p>
        </div>
    </div>

    <div class="text-center mb-4">
        <a href="assets/downloads/epidrasis.apk" class="btn btn-success btn-lg">
            <i class="bi bi-download me-1"></i>Λήψη Εφαρμογής Android
        </a>
    </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
