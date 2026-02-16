-- VolunteerOps - Κατηγορία Πρώτες Βοήθειες & Ερωτήσεις
-- Δημιουργεί κατηγορία "Πρώτες Βοήθειες" και 100 ερωτήσεις (50 quiz + 50 exam)

-- Δημιουργία Κατηγορίας Πρώτες Βοήθειες
INSERT INTO `training_categories` (`id`, `name`, `description`, `icon`, `display_order`, `created_at`) 
VALUES (100, 'Πρώτες Βοήθειες', 'Βασικές γνώσεις πρώτων βοηθειών για εθελοντές', 'bi-heart-pulse', 1, NOW())
ON DUPLICATE KEY UPDATE 
    `name` = 'Πρώτες Βοήθειες',
    `description` = 'Βασικές γνώσεις πρώτων βοηθειών για εθελοντές';

-- =====================================================
-- ΕΡΩΤΗΣΕΙΣ ΚΟΥΙΖ (training_quiz_questions) - 50 ερωτήσεις
-- =====================================================

-- Quiz Question 1
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποιος είναι ο αριθμός έκτακτης ανάγκης στην Ελλάδα;', '100', '166', '112', '199', 'C', 'Το 112 είναι ο ενιαίος ευρωπαϊκός αριθμός έκτακτης ανάγκης.');

-- Quiz Question 2
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Η καρδιοπνευμονική αναζωογόνηση (CPR) πρέπει να γίνεται με ρυθμό 100-120 συμπιέσεις το λεπτό.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Ο σωστός ρυθμός CPR είναι 100-120 συμπιέσεις ανά λεπτό.');

-- Quiz Question 3
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Σε ποιο βάθος πρέπει να γίνονται οι θωρακικές συμπιέσεις σε ενήλικα;', '3-4 cm', '5-6 cm', '7-8 cm', '9-10 cm', 'B', 'Οι θωρακικές συμπιέσεις σε ενήλικα πρέπει να έχουν βάθος 5-6 cm.');

-- Quiz Question 4
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Εάν κάποιος πνίγεται και μπορεί να βήχει δυνατά, δεν πρέπει να παρέμβουμε.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Αν το θύμα βήχει δυνατά σημαίνει ότι οι αεραγωγοί δεν είναι πλήρως αποφραγμένοι. Ενθαρρύνουμε τον βήχα.');

-- Quiz Question 5
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποια είναι η σειρά των ενεργειών στην αλυσίδα επιβίωσης;', 'Αναγνώριση - Ενεργοποίηση - CPR - Απινίδωση', 'CPR - Αναγνώριση - Απινίδωση - Ενεργοποίηση', 'Απινίδωση - CPR - Αναγνώριση - Ενεργοποίηση', 'Ενεργοποίηση - Αναγνώριση - Απινίδωση - CPR', 'A', 'Η σωστή σειρά είναι: Αναγνώριση, Ενεργοποίηση (κλήση 112), CPR, Απινίδωση.');

-- Quiz Question 6
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Στην καρδιακή ανακοπή, οι εμφυσήσεις είναι πιο σημαντικές από τις θωρακικές συμπιέσεις.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Οι θωρακικές συμπιέσεις είναι πιο σημαντικές. Αν δεν μπορείτε να κάνετε εμφυσήσεις, κάντε μόνο συμπιέσεις.');

-- Quiz Question 7
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποια είναι η αναλογία θωρακικών συμπιέσεων προς εμφυσήσεις στο CPR;', '15:2', '30:2', '20:2', '25:2', 'B', 'Η αναλογία είναι 30 θωρακικές συμπιέσεις προς 2 εμφυσήσεις, ανεξαρτήτως αριθμού διασωστών.');

-- Quiz Question 8
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Το αυτόματο εξωτερικό απινιδωτή (AED) μπορεί να χρησιμοποιηθεί μόνο από γιατρούς.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Το AED μπορεί να χρησιμοποιηθεί από οποιονδήποτε. Δίνει φωνητικές οδηγίες και είναι ασφαλές.');

-- Quiz Question 9
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Πώς αντιμετωπίζουμε εγκαύματα πρώτου βαθμού;', 'Βάζουμε πάγο απευθείας', 'Ψύχουμε με τρεχούμενο κρύο νερό για 10-20 λεπτά', 'Βάζουμε οδοντόκρεμα', 'Αφήνουμε να θεραπευτεί μόνο του', 'B', 'Τα εγκαύματα ψύχονται με τρεχούμενο κρύο νερό για 10-20 λεπτά. Ποτέ πάγο ή οδοντόκρεμα.');

-- Quiz Question 10
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Σε περίπτωση αιμορραγίας, πρέπει να εφαρμόσουμε άμεση πίεση στο τραύμα.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Η άμεση πίεση είναι η πρώτη ενέργεια για τον έλεγχο αιμορραγίας.');

-- Quiz Question 11
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Τι είναι το σύνδρομο shock;', 'Ψυχολογική αντίδραση σε τραυματισμό', 'Ανεπαρκής αιματική ροή στα όργανα', 'Απώλεια συνείδησης', 'Αλλεργική αντίδραση', 'B', 'Το shock είναι κατάσταση ανεπαρκούς αιματικής ροής που μπορεί να οδηγήσει σε βλάβη οργάνων.');

-- Quiz Question 12
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Σε περίπτωση κατάγματος, πρέπει να προσπαθήσουμε να το τοποθετήσουμε στη θέση του.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Δεν προσπαθούμε ποτέ να επανατοποθετήσουμε κάταγμα. Ακινητοποιούμε και καλούμε βοήθεια.');

-- Quiz Question 13
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποια είναι η ανάσχεση θέση (recovery position);', 'Ύπτια θέση', 'Πλάγια σταθερή θέση', 'Όρθια θέση', 'Καθιστή θέση', 'B', 'Η πλάγια σταθερή θέση (recovery position) προστατεύει τον αεραγωγό σε άτομο χωρίς συνείδηση που αναπνέει.');

-- Quiz Question 14
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Στην υπογλυκαιμία (χαμηλό σάκχαρο), δίνουμε ζάχαρη ή γλυκό χυμό αν το άτομο είναι συνειδητό.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Στην υπογλυκαιμία, αν το άτομο είναι συνειδητό, δίνουμε γρήγορα απορροφούμενη ζάχαρη.');

-- Quiz Question 15
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποια είναι τα σημεία καρδιακής ανακοπής;', 'Πόνος στο στήθος και δύσπνοια', 'Απώλεια συνείδησης και έλλειψη αναπνοής', 'Πονοκέφαλος και ζάλη', 'Ναυτία και εμετός', 'B', 'Η καρδιακή ανακοπή χαρακτηρίζεται από απώλεια συνείδησης και έλλειψη φυσιολογικής αναπνοής.');

-- Quiz Question 16
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Το επιληπτικό σπασμό πρέπει να προσπαθήσουμε να το σταματήσουμε κρατώντας το άτομο.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Δεν κρατάμε το άτομο κατά τη διάρκεια σπασμού. Προστατεύουμε από τραυματισμό και παρακολουθούμε.');

-- Quiz Question 17
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Τι σημαίνει το ABC στις πρώτες βοήθειες;', 'Ambulance, Blood, CPR', 'Airway, Breathing, Circulation', 'Alert, Bandage, Call', 'Assess, Bandage, Comfort', 'B', 'ABC σημαίνει: Airway (Αεραγωγός), Breathing (Αναπνοή), Circulation (Κυκλοφορία).');

-- Quiz Question 18
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Σε περίπτωση δηλητηρίασης, πρέπει πάντα να προκαλέσουμε εμετό.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Δεν προκαλούμε εμετό χωρίς ιατρική οδηγία. Μερικές ουσίες μπορεί να προκαλέσουν μεγαλύτερη βλάβη.');

-- Quiz Question 19
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποιος είναι ο κίνδυνος με το τουρνικέ (garrote);', 'Δεν υπάρχει κίνδυνος', 'Μπορεί να προκαλέσει νέκρωση ιστών', 'Είναι πολύ ακριβό', 'Δεν είναι αποτελεσματικό', 'B', 'Το τουρνικέ χρησιμοποιείται μόνο σε ζωτική απειλή από αιμορραγία, καθώς μπορεί να προκαλέσει νέκρωση.');

-- Quiz Question 20
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Το εγκεφαλικό επεισόδιο (εγκεφαλικό) απαιτεί άμεση ιατρική φροντίδα.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Το εγκεφαλικό είναι επείγουσα κατάσταση. Κάθε λεπτό μετράει για την πρόληψη εγκεφαλικής βλάβης.');

-- Quiz Question 21
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Τι είναι το FAST test;', 'Τεστ για καρδιακή προσβολή', 'Τεστ για εγκεφαλικό επεισόδιο', 'Τεστ για διαβήτη', 'Τεστ για κατάγματα', 'B', 'FAST (Face, Arms, Speech, Time) είναι το τεστ για την αναγνώριση εγκεφαλικού επεισοδίου.');

-- Quiz Question 22
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Τα γάντια μιας χρήσης είναι απαραίτητα για την προστασία από λοιμώξεις.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Τα γάντια μιας χρήσης προστατεύουν τόσο τον διασώστη όσο και το θύμα από λοιμώξεις.');

-- Quiz Question 23
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Σε τι θερμοκρασία νερού ψύχουμε εγκαύματα;', 'Παγωμένο νερό 0-5°C', 'Κρύο νερό 10-20°C', 'Χλιαρό νερό 25-30°C', 'Ζεστό νερό 35-40°C', 'B', 'Χρησιμοποιούμε κρύο τρεχούμενο νερό (10-20°C) για 10-20 λεπτά.');

-- Quiz Question 24
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Σε περίπτωση ηλεκτροπληξίας, πρέπει πρώτα να διακόψουμε την πηγή ρεύματος.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Πρώτα διακόπτουμε το ρεύμα για την ασφάλειά μας. Μετά παρέχουμε πρώτες βοήθειες.');

-- Quiz Question 25
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Τι κάνουμε σε περίπτωση ρήξης δοντιού;', 'Πετάμε το δόντι', 'Πλένουμε με σαπούνι και το τοποθετούμε στη θέση του', 'Το βάζουμε σε γάλα ή φυσιολογικό ορό', 'Το βάζουμε σε αλκοόλη', 'C', 'Το δόντι βάζεται σε γάλα ή φυσιολογικό ορό και μεταφέρεται άμεσα σε οδοντίατρο.');

-- Quiz Question 26
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Η αλλεργική αναφυλαξία μπορεί να είναι θανατηφόρα.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Η αναφυλαξία είναι σοβαρή αλλεργική αντίδραση που μπορεί να προκαλέσει θάνατο χωρίς άμεση αντιμετώπιση.');

-- Quiz Question 27
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποια είναι η θέση για άτομο σε shock;', 'Όρθια', 'Ύπτια με ανυψωμένα πόδια', 'Πρηνής', 'Καθιστή', 'B', 'Στο shock, το άτομο τοποθετείται ύπτιο με ανυψωμένα πόδια για να βελτιωθεί η εγκεφαλική αιμάτωση.');

-- Quiz Question 28
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Το άσπρο μέρος της ασπιρίνης μπορεί να χρησιμοποιηθεί για καρδιακή προσβολή.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Η ασπιρίνη (αν δεν υπάρχει αντένδειξη) μπορεί να βοηθήσει σε καρδιακή προσβολή μασημένη, όχι καταποθείσα.');

-- Quiz Question 29
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Πόσες εμφυσήσεις διάρκειας πόσων δευτερολέπτων κάνουμε στο CPR;', '2 εμφυσήσεις, 1 δευτερόλεπτο η καθεμία', '2 εμφυσήσεις, 2 δευτερόλεπτα η καθεμία', '3 εμφυσήσεις, 1 δευτερόλεπτο η καθεμία', '1 εμφύσηση, 3 δευτερόλεπτα', 'A', 'Κάνουμε 2 εμφυσήσεις διάρκειας 1 δευτερολέπτου η καθεμία, αρκετές για να ανασηκωθεί το στήθος.');

-- Quiz Question 30
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Στα παιδιά το CPR γίνεται με τον ίδιο τρόπο όπως στους ενήλικες.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Στα παιδιά το βάθος συμπίεσης και η δύναμη είναι διαφορετικά. Σε βρέφη χρησιμοποιούμε 2 δάχτυλα.');

-- Quiz Question 31
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Τι είναι η θέση Trendelenburg;', 'Ύπτια με κεφάλι ψηλά', 'Ύπτια με πόδια ψηλά', 'Πλάγια θέση', 'Ημικάθιστη θέση', 'B', 'Στη θέση Trendelenburg το σώμα είναι ύπτιο με τα πόδια ανυψωμένα, χρήσιμη στο shock.');

-- Quiz Question 32
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Σε κάταγμα της σπονδυλικής στήλης, πρέπει να μετακινήσουμε το θύμα αμέσως.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Δεν μετακινούμε θύμα με πιθανό κάταγμα σπονδυλικής στήλης εκτός αν υπάρχει άμεσος κίνδυνος.');

-- Quiz Question 33
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποιο είναι το πρώτο βήμα σε κάθε περίπτωση έκτακτης ανάγκης;', 'Καλούμε ασθενοφόρο', 'Αξιολογούμε την ασφάλεια του χώρου', 'Ξεκινάμε CPR', 'Ρωτάμε το θύμα πώς είναι', 'B', 'Πρώτα αξιολογούμε την ασφάλεια για να μην γίνουμε κι εμείς θύματα.');

-- Quiz Question 34
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Το AED μπορεί να χρησιμοποιηθεί σε παιδιά κάτω των 8 ετών.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Το AED μπορεί να χρησιμοποιηθεί σε παιδιά, ιδανικά με παιδιατρικά pads αν υπάρχουν.');

-- Quiz Question 35
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Πώς αντιμετωπίζουμε τη θερμοπληξία;', 'Δίνουμε ζεστά υγρά', 'Ψύχουμε σταδιακά το σώμα', 'Βάζουμε πάνω από ένα σκεπάσματα', 'Αφήνουμε το άτομο να ξεκουραστεί', 'B', 'Στη θερμοπληξία πρέπει να ψύξουμε σταδιακά το σώμα και να ενυδατώσουμε (αν είναι συνειδητό).');

-- Quiz Question 36
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Σε περίπτωση υποθερμίας, πρέπει να ζεστάνουμε το θύμα γρήγορα με ζεστό μπάνιο.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Στην υποθερμία ζεσταίνουμε σταδιακά. Η γρήγορη θέρμανση μπορεί να είναι επικίνδυνη.');

-- Quiz Question 37
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποιος είναι ο σωστός τρόπος να ελέγξουμε τη συνείδηση σε ενήλικα;', 'Χτυπάμε στο πρόσωπο', 'Κουνάμε από τους ώμους και μιλάμε δυνατά', 'Ρίχνουμε νερό', 'Σφυρίζουμε δυνατά', 'B', 'Κουνάμε απαλά τους ώμους και ρωτάμε δυνατά "Είστε καλά;".');

-- Quiz Question 38
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Το χάπι νιτρογλυκερίνης χρησιμοποιείται για τη στηθάγχη.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Η νιτρογλυκερίνη διατίθεται σε ασθενείς με στηθάγχη και τοποθετείται υπογλώσσια.');

-- Quiz Question 39
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Πού ακούμε για αναπνοή σε άτομο χωρίς συνείδηση;', 'Στο στόμα', 'Στη μύτη', 'Πάνω από το στόμα και τη μύτη', 'Στο αυτί', 'C', 'Γέρνουμε το αυτί μας πάνω από το στόμα και τη μύτη του θύματος για να ακούσουμε/δούμε/νιώσουμε αναπνοές.');

-- Quiz Question 40
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Το να βάλουμε κάτι στο στόμα κατά τη διάρκεια επιληπτικού σπασμού βοηθά το θύμα.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Δεν βάζουμε τίποτα στο στόμα κατά το σπασμό. Μπορεί να προκαλέσει πνιγμό ή τραυματισμό.');

-- Quiz Question 41
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Τι σημαίνει "Look, Listen, Feel" στις πρώτες βοήθειες;', 'Έλεγχος αναπνοής', 'Έλεγχος καρδιακού παλμού', 'Έλεγχος συνείδησης', 'Έλεγχος αιμορραγίας', 'A', 'Look, Listen, Feel: Κοιτάμε για κίνηση θώρακα, Ακούμε για ήχους αναπνοής, Νιώθουμε την αναπνοή.');

-- Quiz Question 42
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Η χρήση του EpiPen (αυτόματη επινεφρίνη) χρειάζεται ιατρική συνταγή και πρέπει να χρησιμοποιείται μόνο σε αναφυλαξία.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Το EpiPen χορηγείται μόνο σε περιπτώσεις αναφυλαξίας και χρειάζεται συνταγή γιατρού.');

-- Quiz Question 43
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποια είναι η ένδειξη για χρήση AED;', 'Κάθε άτομο χωρίς συνείδηση', 'Κάταγμα', 'Καρδιακή ανακοπή (χωρίς παλμό)', 'Αιμορραγία', 'C', 'Το AED χρησιμοποιείται σε καρδιακή ανακοπή (χωρίς παλμό και χωρίς αναπνοή).');

-- Quiz Question 44
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Η ασπιρίνη και η νιτρογλυκερίνη είναι το ίδιο φάρμακο.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Είναι διαφορετικά φάρμακα. Η ασπιρίνη είναι αντιπηκτικό, η νιτρογλυκερίνη αγγειοδιασταλτικό.');

-- Quiz Question 45
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποιο είναι το προειδοποιητικό σημάδι καρδιακής προσβολής;', 'Πόνος στο στήθος που μπορεί να ακτινοβολεί', 'Κρύος ιδρώτας', 'Δύσπνοια', 'Όλα τα παραπάνω', 'D', 'Τα συμπτώματα καρδιακής προσβολής περιλαμβάνουν πόνο στήθους, κρύο ιδρώτα, δύσπνοια, ναυτία.');

-- Quiz Question 46
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Όταν κάνουμε CPR, είναι φυσιολογικό να ακούγεται "κρακ" από τα πλευρά.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Μπορεί να ακουστούν θόρυβοι από τα πλευρά κατά το CPR. Συνεχίζουμε τις συμπιέσεις.');

-- Quiz Question 47
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Πώς αντιμετωπίζουμε νευρικό σοκ (λιποθυμία);', 'Όρθια θέση', 'Ύπτια με ανυψωμένα πόδια', 'Καθιστή με κεφάλι ανάμεσα στα γόνατα', 'Πλάγια θέση', 'B', 'Στη λιποθυμία τοποθετούμε το άτομο ύπτιο με ανυψωμένα πόδια για αύξηση εγκεφαλικής αιμάτωσης.');

-- Quiz Question 48
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Η πλάγια σταθερή θέση (recovery position) χρησιμοποιείται σε άτομα χωρίς συνείδηση που αναπνέουν φυσιολογικά.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Η recovery position διατηρεί τον αεραγωγό ανοιχτό σε άτομα χωρίς συνείδηση που αναπνέουν.');

-- Quiz Question 49
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποιο είναι το σύμπτωμα υπεργλυκαιμίας (υψηλού σακχάρου);', 'Πείνα και τρόμος', 'Δίψα και συχνοουρία', 'Χλωμότητα', 'Εφίδρωση', 'B', 'Η υπεργλυκαιμία χαρακτηρίζεται από δίψα, συχνοουρία, κούραση και θολή όραση.');

-- Quiz Question 50
INSERT INTO `training_quiz_questions` (`category_id`, `quiz_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Ο διασώστης πρέπει να έχει εμβολιασμούς για ηπατίτιδα Β και τέτανο.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Οι εμβολιασμοί για ηπατίτιδα Β και τέτανο προστατεύουν τον διασώστη από λοιμώξεις.');

-- =====================================================
-- ΕΡΩΤΗΣΕΙΣ ΔΙΑΓΩΝΙΣΜΑΤΟΣ (training_exam_questions) - 50 ερωτήσεις
-- =====================================================

-- Exam Question 1
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποια είναι η πρώτη ενέργεια σε περίπτωση καρδιακής ανακοπής;', 'Εμφυσήσεις', 'Κλήση 112', 'Θωρακικές συμπιέσεις', 'Χρήση AED', 'C', 'Μετά την αναγνώριση καρδιακής ανακοπής, ξεκινούν αμέσως θωρακικές συμπιέσεις (CAB approach).');

-- Exam Question 2
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Σε ενήλικα, η αναλογία συμπιέσεων προς εμφυσήσεις είναι 15:2.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Η σωστή αναλογία σε ενήλικες είναι 30:2, ανεξαρτήτως αριθμού διασωστών.');

-- Exam Question 3
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Πού τοποθετούμε τα χέρια για θωρακικές συμπιέσεις;', 'Πάνω μέρος στέρνου', 'Κέντρο στέρνου (κάτω μισό)', 'Κοιλιά', 'Αριστερό πλευρό θώρακα', 'B', 'Τα χέρια τοποθετούνται στο κέντρο του στέρνου (κάτω μισό), όχι στο ξιφοειδές.');

-- Exam Question 4
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Το AED πρέπει να ανοίγει αμέσως μόλις φτάσει στο σημείο.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Το AED ανοίγει αμέσως και ακολουθούμε τις φωνητικές του οδηγίες.');

-- Exam Question 5
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Πόσο χρόνο αφιερώνουμε για να ελέγξουμε αναπνοή;', '2-3 δευτερόλεπτα', '5-10 δευτερόλεπτα', 'Όχι περισσότερο από 10 δευτερόλεπτα', '30 δευτερόλεπτα', 'C', 'Ο έλεγχος αναπνοής δεν πρέπει να ξεπερνά τα 10 δευτερόλεπτα για να μην καθυστερεί το CPR.');

-- Exam Question 6
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Το CPR μπορεί να σπάσει πλευρά, αλλά πρέπει να συνεχιστεί.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Είναι πιθανό να σπάσουν πλευρά, ειδικά σε ηλικιωμένους, αλλά το CPR συνεχίζεται.');

-- Exam Question 7
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Πότε σταματάμε το CPR;', 'Μετά από 5 λεπτά', 'Όταν κουραστούμε', 'Όταν φτάσει βοήθεια ή το θύμα δείξει σημεία ζωής', 'Όταν αποφασίσουμε', 'C', 'Το CPR σταματά όταν: 1) Φτάσει βοήθεια 2) Το θύμα δείξει σημεία ζωής 3) Είμαστε εξαντλημένοι.');

-- Exam Question 8
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Σε παιδί 1-8 ετών χρησιμοποιούμε ένα χέρι για θωρακικές συμπιέσεις.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Σε παιδιά 1-8 ετών χρησιμοποιούμε ένα ή δύο χέρια ανάλογα με το μέγεθος του παιδιού.');

-- Exam Question 9
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Τι κάνουμε αν το AED συστήσει "δεν απαιτείται shock";', 'Σταματάμε το CPR', 'Συνεχίζουμε το CPR', 'Ανοίγουμε το AED ξανά', 'Περιμένουμε το ασθενοφόρο', 'B', 'Αν το AED πει "δεν απαιτείται shock", συνεχίζουμε αμέσως το CPR.');

-- Exam Question 10
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Η εμφύσηση στόμα-με-στόμα είναι υποχρεωτική στο CPR.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Αν δεν μπορούμε να κάνουμε εμφυσήσεις, κάνουμε μόνο θωρακικές συμπιέσεις (hands-only CPR).');

-- Exam Question 11
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Πώς ανοίγουμε τον αεραγωγό σε τραυματία χωρίς υποψία τραύματος αυχένα;', 'Jaw thrust', 'Head tilt - chin lift', 'Δεν ανοίγουμε', 'Lateral position', 'B', 'Η τεχνική head tilt - chin lift χρησιμοποιείται όταν δεν υπάρχει υποψία τραύματος σπονδυλικής στήλης.');

-- Exam Question 12
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Το jaw thrust χρησιμοποιείται όταν υπάρχει υποψία τραύματος σπονδυλικής στήλης.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Το jaw thrust δεν κινεί τον αυχένα και είναι ασφαλέστερο σε πιθανό τραύμα σπονδυλικής στήλης.');

-- Exam Question 13
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποια είναι η συνιστώμενη θέση για έγκυο στο τρίτο τρίμηνο που έχει λιποθυμήσει;', 'Ύπτια', 'Πλάγια αριστερή', 'Πρηνής', 'Καθιστή', 'B', 'Στην έγκυο, η πλάγια αριστερή θέση αποτρέπει τη συμπίεση της κάτω κοίλης φλέβας.');

-- Exam Question 14
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Σε βρέφος κάτω του 1 έτους κάνουμε 2 δάχτυλα για θωρακικές συμπιέσεις.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Σε βρέφη χρησιμοποιούμε 2 δάχτυλα (δείκτη και μέσο) για τις θωρακικές συμπιέσεις.');

-- Exam Question 15
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποιο είναι το βάθος συμπίεσης σε βρέφος;', '1-2 cm', '3-4 cm', 'Τουλάχιστον 4 cm (1/3 του βάθους θώρακα)', '5-6 cm', 'C', 'Σε βρέφη το βάθος είναι περίπου 4 cm (1/3 του βάθους θώρακα).');

-- Exam Question 16
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Η πνιγμονή (choking) σε ενήλικα αντιμετωπίζεται με την τεχνική Heimlich.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Η τεχνική Heimlich (κοιλιακές συμπιέσεις) είναι η βασική μέθοδος για πνιγμονή σε ενήλικα.');

-- Exam Question 17
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Πώς αντιμετωπίζουμε πνιγμονή σε βρέφος;', 'Κοιλιακές συμπιέσεις', '5 χτυπήματα στην πλάτη + 5 θωρακικές συμπιέσεις', 'Τίποτα, βήχας μόνο', 'Αναποδογυρίζουμε το βρέφος', 'B', 'Σε βρέφος εναλλάσσουμε 5 χτυπήματα πλάτης με 5 θωρακικές συμπιέσεις.');

-- Exam Question 18
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Σε περίπτωση σοβαρής αιμορραγίας, το τουρνικέ είναι η πρώτη επιλογή.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Το τουρνικέ είναι η τελευταία επιλογή, μόνο όταν η άμεση πίεση αποτυγχάνει.');

-- Exam Question 19
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Που εφαρμόζεται το τουρνικέ;', 'Στο τραύμα', 'Πάνω από το τραύμα', 'Κάτω από το τραύμα', 'Δεν εφαρμόζεται', 'B', 'Το τουρνικέ τοποθετείται 5-7 cm πάνω από το τραύμα (προς το σώμα).');

-- Exam Question 20
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Τα εσωτερικά όργανα που εξέχουν από τραύμα πρέπει να επανατοποθετηθούν.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Δεν επανατοποθετούμε όργανα. Καλύπτουμε με υγρό στείρο γάζα και μεταφέρουμε σε νοσοκομείο.');

-- Exam Question 21
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Τι είναι το "Golden Hour";', 'Η πρώτη ώρα μετά τον τραυματισμό', 'Η ώρα του ηλιοβασιλέματος', 'Ο χρόνος CPR', 'Η ώρα άφιξης στο νοσοκομείο', 'A', 'Το "Golden Hour" είναι η πρώτη κρίσιμη ώρα μετά από σοβαρό τραυματισμό.');

-- Exam Question 22
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Σε κάταγμα, ακινητοποιούμε την άρθρωση πάνω και κάτω από το κάταγμα.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Ακινητοποιούμε την άρθρωση πάνω και κάτω από το κάταγμα για σταθερότητα.');

-- Exam Question 23
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποια είναι η ένδειξη εγκεφαλικού επεισοδίου στο FAST test;', 'Face drooping, Arm weakness, Speech difficulty', 'Fast heartbeat, Arrhythmia, Shock', 'Fever, Aches, Sweating', 'Fracture, Amputation, Severe pain', 'A', 'FAST: Face (πτώση προσώπου), Arms (αδυναμία χεριού), Speech (δυσκολία ομιλίας), Time (άμεση ενέργεια).');

-- Exam Question 24
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Η νιτρογλυκερίνη μπορεί να δοθεί σε οποιονδήποτε με πόνο στο στήθος.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Η νιτρογλυκερίνη δίνεται μόνο αν έχει συνταγεί στον ασθενή και όχι σε χαμηλή πίεση.');

-- Exam Question 25
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποια είναι η αντιμετώπιση της αναφυλαξίας;', 'Αντιισταμινικό', 'Επινεφρίνη (EpiPen) και 112', 'Κορτιζόνη', 'Αναμονή', 'B', 'Η αναφυλαξία είναι επείγουσα. Χορηγούμε επινεφρίνη (EpiPen) και καλούμε αμέσως 112.');

-- Exam Question 26
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Το EpiPen χορηγείται στον εξωτερικό μηρό.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Το EpiPen εφαρμόζεται στο εξωτερικό μέρος του μηρού μέσω των ρούχων.');

-- Exam Question 27
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Πόσο διαρκεί το EpiPen μετά τη χορήγηση;', 'Μόνιμα', '5-10 λεπτά', '10-20 λεπτά', '2 ώρες', 'C', 'Το EpiPen διαρκεί περίπου 10-20 λεπτά. Μπορεί να χρειαστεί δεύτερη δόση.');

-- Exam Question 28
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Σε διαβητική κρίση, αν υπάρχει αμφιβολία μεταξύ υπο- και υπεργλυκαιμίας, δίνουμε ζάχαρη.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Σε αμφιβολία δίνουμε ζάχαρη γιατί η υπογλυκαιμία είναι πιο άμεσα επικίνδυνη.');

-- Exam Question 29
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Πώς αντιμετωπίζουμε τσίμπημα μέλισσας με αλλεργία;', 'Αφαιρούμε το κεντρί και παρακολουθούμε', 'Βάζουμε πάγο', 'Χορηγούμε EpiPen αν υπάρχει', 'Όλα τα παραπάνω', 'D', 'Αφαιρούμε το κεντρί, βάζουμε ψύξη, χορηγούμε EpiPen αν υπάρχει γνωστή αλλεργία και καλούμε 112.');

-- Exam Question 30
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Το δάγκωμα φιδιού πρέπει να ψυχθεί με πάγο αμέσως.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Δεν βάζουμε πάγο ή τουρνικέ. Ακινητοποιούμε το μέλος και μεταφέρουμε σε νοσοκομείο.');

-- Exam Question 31
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποιο είναι το σύμπτωμα σοβαρού εγκεφαλικού τραύματος;', 'Πονοκέφαλος', 'Ροή υγρού ή αίματος από αυτί/μύτη', 'Ζάλη', 'Ναυτία', 'B', 'Η ροή υγρού (εγκεφαλονωτιαίου) ή αίματος από αυτί/μύτη δείχνει σοβαρό εγκεφαλικό τραύμα.');

-- Exam Question 32
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Στη διάσειση (concussion) το άτομο πρέπει να μείνει ξύπνιο για 24 ώρες.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Αυτός είναι μύθος. Μπορεί να κοιμηθεί αλλά πρέπει να παρακολουθείται για επιδείνωση.');

-- Exam Question 33
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Τι είναι η κλίμακα Glasgow Coma Scale (GCS);', 'Μέτρηση πόνου', 'Μέτρηση επιπέδου συνείδησης', 'Μέτρηση αιμορραγίας', 'Μέτρηση θερμοκρασίας', 'B', 'Η GCS αξιολογεί το επίπεδο συνείδησης (3-15 βαθμοί) με βάση μάτια, λόγο και κινητική απόκριση.');

-- Exam Question 34
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Η πλάτη με τραύμα (penetrating) πρέπει να αφαιρεθεί το αντικείμενο αμέσως.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Δεν αφαιρούμε αντικείμενα που έχουν διαπεράσει το σώμα. Σταθεροποιούμε και μεταφέρουμε.');

-- Exam Question 35
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Πώς αντιμετωπίζουμε πνευμοθώρακα υπό τάση (tension pneumothorax);', 'Εμφυσήσεις', 'Άμεση μεταφορά σε νοσοκομείο', 'Πίεση στο στήθος', 'Τίποτα', 'B', 'Ο πνευμοθώρακας υπό τάση είναι επείγον και χρειάζεται άμεση ιατρική παρέμβαση.');

-- Exam Question 36
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Το εγκεφαλικό επεισόδιο μπορεί να προκληθεί από θρόμβο ή αιμορραγία.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Το εγκεφαλικό μπορεί να είναι ισχαιμικό (θρόμβος) ή αιμορραγικό.');

-- Exam Question 37
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποιο φάρμακο χορηγείται σε ισχαιμικό εγκεφαλικό;', 'Αντιπηκτικό', 'Επινεφρίνη', 'Ασπιρίνη (αν δεν υπάρχει αντένδειξη)', 'Κορτιζόνη', 'C', 'Η ασπιρίνη μπορεί να χορηγηθεί σε ισχαιμικό εγκεφαλικό μετά από ιατρική οδηγία.');

-- Exam Question 38
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Ο επιληπτικός σπασμός διαρκεί συνήθως λιγότερο από 2 λεπτά.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Οι περισσότεροι επιληπτικοί σπασμοί διαρκούν 1-2 λεπτά. Άνω των 5 λεπτών είναι επείγον.');

-- Exam Question 39
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Τι είναι το status epilepticus;', 'Ένας σύντομος σπασμός', 'Σπασμός που διαρκεί >5 λεπτά ή επαναλαμβανόμενοι σπασμοί', 'Σπασμός σε παιδί', 'Ήπιος σπασμός', 'B', 'Το status epilepticus είναι επείγουσα κατάσταση με παρατεταμένο σπασμό >5 λεπτά.');

-- Exam Question 40
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Τα εγκαύματα τρίτου βαθμού είναι πάντα οδυνηρά.', 'Σωστό', 'Λάθος', NULL, NULL, 'B', 'Τα εγκαύματα τρίτου βαθμού καταστρέφουν τις νευρικές απολήξεις και μπορεί να μην πονούν.');

-- Exam Question 41
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Πώς ταξινομούνται τα εγκαύματα;', '1ος, 2ος, 3ος βαθμός', 'Ήπια, μέτρια, σοβαρά', 'Επιφανειακά, βαθιά', 'Κανένα από τα παραπάνω', 'A', 'Τα εγκαύματα ταξινομούνται σε 1ου (επιφανειακό), 2ου (μερικό πάχος), 3ου (πλήρες πάχος) βαθμού.');

-- Exam Question 42
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Στα χημικά εγκαύματα, ξεπλένουμε με άφθονο νερό για τουλάχιστον 20 λεπτά.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Τα χημικά εγκαύματα ξεπλένονται με άφθονο τρεχούμενο νερό για τουλάχιστον 20 λεπτά.');

-- Exam Question 43
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποιος είναι ο κανόνας των 9 (Rule of Nines);', 'Κανόνας για CPR', 'Εκτίμηση έκτασης εγκαυμάτων', 'Κανόνας για αιμορραγία', 'Κανόνας για κατάγματα', 'B', 'Ο κανόνας των 9 χρησιμεύει για την εκτίμηση του ποσοστού εγκαυμένης επιφάνειας σώματος.');

-- Exam Question 44
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Η υποθερμία ορίζεται ως θερμοκρασία σώματος <35°C.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Η υποθερμία είναι θερμοκρασία σώματος κάτω από 35°C.');

-- Exam Question 45
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Τι είναι το "rewarming shock" στην υποθερμία;', 'Αιφνίδια βελτίωση', 'Επιδείνωση κατά τη θέρμανση', 'Σπασμός', 'Δεν υπάρχει', 'B', 'Το rewarming shock μπορεί να προκληθεί από γρήγορη θέρμανση. Θερμαίνουμε σταδιακά.');

-- Exam Question 46
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Στην ηλεκτροπληξία, το σώμα μπορεί να έχει 2 σημεία εισόδου/εξόδου.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Το ηλεκτρικό ρεύμα έχει σημείο εισόδου και εξόδου. Ψάχνουμε και τα δύο σημεία.');

-- Exam Question 47
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Πώς μετακινούμε τραυματία με πιθανό τραύμα σπονδυλικής στήλης;', 'Με 1 άτομο', 'Με inline stabilization (ακινητοποίηση αυχένα)', 'Δεν μετακινούμε ποτέ', 'Με σακούλα ύπνου', 'B', 'Χρειάζεται inline stabilization του αυχένα και συνήθως 3-4 άτομα για ασφαλή μετακίνηση.');

-- Exam Question 48
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Ο C-spine collar τοποθετείται μόνο από εκπαιδευμένο προσωπικό.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Ο αυχενικός κολάρος τοποθετείται μόνο από εκπαιδευμένο προσωπικό. Αλλιώς χειροκίνητη ακινητοποίηση.');

-- Exam Question 49
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'multiple_choice', 'Ποιο είναι το σημάδι εσωτερικής αιμορραγίας;', 'Εμφανής αιμορραγία', 'Σημάδια shock χωρίς εμφανή αιμορραγία', 'Πόνος σε κάταγμα', 'Πονοκέφαλος', 'B', 'Η εσωτερική αιμορραγία εμφανίζεται με σημεία shock χωρίς εμφανή απώλεια αίματος.');

-- Exam Question 50
INSERT INTO `training_exam_questions` (`category_id`, `exam_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) 
VALUES (100, NULL, 'true_false', 'Οι διασώστες πρέπει να έχουν ασφάλιση αστικής ευθύνης.', 'Σωστό', 'Λάθος', NULL, NULL, 'A', 'Η ασφάλιση αστικής ευθύνης προστατεύει τους διασώστες από νομικές συνέπειες κατά την άσκηση καθηκόντων.');
