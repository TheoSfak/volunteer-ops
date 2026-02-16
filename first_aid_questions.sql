-- =============================================
-- Ερωτήσεις Πρώτων Βοηθειών
-- =============================================
-- Αυτό το script δημιουργεί αυτόματα κατηγορία, exam και 45 ερωτήσεις πρώτων βοηθειών

-- 1. Δημιουργία κατηγορίας "Πρώτες Βοήθειες" αν δεν υπάρχει
INSERT INTO training_categories (name, description, icon, display_order, is_active)
SELECT 'Πρώτες Βοήθειες', 'Βασικές γνώσεις και τεχνικές πρώτων βοηθειών', '🚑', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM training_categories WHERE name = 'Πρώτες Βοήθειες');

-- 2. Δημιουργία exam "Διαγώνισμα Πρώτων Βοηθειών" αν δεν υπάρχει
INSERT INTO training_exams (title, description, category_id, questions_per_attempt, passing_percentage, time_limit_minutes, is_active, allow_retake, created_by)
SELECT 
    'Διαγώνισμα Πρώτων Βοηθειών',
    'Εξέταση γνώσεων πρώτων βοηθειών με ερωτήσεις πολλαπλής επιλογής και σωστό/λάθος',
    tc.id,
    20,
    70,
    30,
    1,
    1,
    1
FROM training_categories tc
WHERE tc.name = 'Πρώτες Βοήθειες'
AND NOT EXISTS (SELECT 1 FROM training_exams WHERE title = 'Διαγώνισμα Πρώτων Βοηθειών');

-- 3. Παίρνουμε το exam_id για χρήση στις ερωτήσεις
SET @exam_id = (SELECT id FROM training_exams WHERE title = 'Διαγώνισμα Πρώτων Βοηθειών' LIMIT 1);

-- MULTIPLE CHOICE ΕΡΩΤΗΣΕΙΣ (25 ερωτήσεις)

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Ποια είναι η πρώτη ενέργεια που πρέπει να κάνετε όταν βρείτε ένα άτομο χωρίς συνείδηση;', 'Ελέγχω αν αναπνέει', 'Ελέγχω αν το περιβάλλον είναι ασφαλές', 'Καλώ το 166', 'Κάνω καρδιοαναπνευστική αναζωογόνηση', 'B', 'Πρώτα πρέπει να διασφαλιστεί η ασφάλεια του διασώστη και του θύματος.', 1);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Σε περίπτωση εγκαύματος, ποια είναι η σωστή ενέργεια;', 'Βάζω πάγο απευθείας στο έγκαυμα', 'Ξεφουσκώνω τις φουσκάλες αμέσως', 'Βάζω οδοντόκρεμα στο έγκαυμα', 'Ψύχω με δροσερό (όχι παγωμένο) νερό για 10-20 λεπτά', 'D', 'Το δροσερό νερό μειώνει τη θερμοκρασία και τον πόνο. Ποτέ πάγος απευθείας στο δέρμα.', 2);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Πόσες συμπιέσεις στο στήθος πρέπει να γίνονται ανά λεπτό κατά την ΚΑΑ σε ενήλικα;', '60-80', '80-100', '100-120', '120-140', 'C', 'Ο ρυθμός των συμπιέσεων πρέπει να είναι 100-120 ανά λεπτό.', 3);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Ποιο είναι το πρώτο σημάδι σοβαρής αλλεργικής αντίδρασης (αναφυλαξίας);', 'Ελαφρύ κνησμό στο δέρμα', 'Δυσκολία στην αναπνοή και πρήξιμο', 'Ελαφριά ναυτία', 'Πονοκέφαλο', 'B', 'Η αναφυλακτική αντίδραση μπορεί να προκαλέσει πρήξιμο του λαιμού και δυσκολία στην αναπνοή, που απαιτεί άμεση ιατρική βοήθεια.', 4);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Πώς πρέπει να αντιμετωπίσετε μια ρινορραγία (αιμορραγία από τη μύτη);', 'Γύρνω το κεφάλι προς τα πίσω', 'Πιέζω το μαλακό μέρος της μύτης για 10 λεπτά με κεφάλι προς τα εμπρός', 'Βάζω βαμβάκι βαθιά στη μύτη', 'Ξαπλώνω το άτομο', 'B', 'Η πίεση σταματά την αιμορραγία και η κλίση προς τα εμπρός αποτρέπει την κατάποση αίματος.', 5);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Τι πρέπει να κάνετε αν κάποιος έχει επιληπτική κρίση;', 'Προσπαθώ να ακινητοποιήσω το άτομο', 'Βάζω κάτι στο στόμα του', 'Απομακρύνω επικίνδυνα αντικείμενα και προστατεύω το κεφάλι', 'Ρίχνω νερό στο πρόσωπό του', 'C', 'Η προστασία από τραυματισμούς είναι η προτεραιότητα. Ποτέ μην βάζετε κάτι στο στόμα.', 6);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Ποια είναι η θέση ανάνηψης (recovery position);', 'Ύπτια θέση με τα πόδια ψηλά', 'Πλάγια θέση με το κεφάλι προς τα πίσω', 'Πλάγια ασφαλής θέση με το κεφάλι χαμηλά', 'Καθιστή θέση', 'C', 'Η πλάγια ασφαλής θέση αποτρέπει την απόφραξη των αεραγωγών από εμετό ή τη γλώσσα.', 7);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Πόσο βαθιά πρέπει να συμπιέζετε το στήθος κατά την ΚΑΑ σε ενήλικα;', '2-3 εκατοστά', '5-6 εκατοστά', '8-10 εκατοστά', '1-2 εκατοστά', 'B', 'Οι συμπιέσεις πρέπει να είναι 5-6 εκατοστά σε βάθος για να είναι αποτελεσματικές.', 8);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Ποιος είναι ο αριθμός του Εθνικού Κέντρου Άμεσης Βοήθειας (ΕΚΑΒ);', '100', '166', '199', '112', 'B', 'Το 166 είναι το ΕΚΑΒ στην Ελλάδα. Το 112 είναι το ευρωπαϊκό νούμερο έκτακτης ανάγκης.', 9);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Τι πρέπει να κάνετε σε περίπτωση υποθερμίας;', 'Βάζω το άτομο σε ζεστό μπάνιο αμέσως', 'Ζεσταίνω σταδιακά με κουβέρτες και ζεστά ροφήματα', 'Τρίβω δυνατά το δέρμα', 'Δίνω αλκοόλ για να ζεσταθεί', 'B', 'Η σταδιακή θέρμανση είναι ασφαλέστερη. Το αλκοόλ και το ζεστό μπάνιο μπορεί να είναι επικίνδυνα.', 10);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Πώς αντιμετωπίζουμε ένα ηλεκτροπληξία;', 'Πιάνω αμέσως το άτομο να το απομακρύνω', 'Κόβω πρώτα το ρεύμα ή απομακρύνω το άτομο με μονωτικό υλικό', 'Ρίχνω νερό για να σταματήσει το ρεύμα', 'Περιμένω να σταματήσει μόνο του', 'B', 'Πρέπει να διακοπεί η πηγή ρεύματος ή να χρησιμοποιηθεί μονωτικό υλικό για ασφάλεια.', 11);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Σε ποια αναλογία γίνονται οι συμπιέσεις και οι αναπνοές στην ΚΑΑ;', '15 συμπιέσεις : 2 αναπνοές', '30 συμπιέσεις : 2 αναπνοές', '20 συμπιέσεις : 2 αναπνοές', '30 συμπιέσεις : 1 αναπνοή', 'B', 'Η αναλογία 30:2 είναι το διεθνές πρότυπο για την ΚΑΑ.', 12);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Τι σημαίνει ABC στις πρώτες βοήθειες;', 'Ασφάλεια, Βοήθεια, Χρόνος', 'Αεραγωγοί, Αναπνοή, Κυκλοφορία (Airway, Breathing, Circulation)', 'Αντιμετώπιση, Βοήθεια, Χρόνος', 'Ασφάλεια, Βοήθεια, Ψυχραιμία', 'B', 'Το ABC είναι η βασική σειρά αξιολόγησης: Airway (αεραγωγοί), Breathing (αναπνοή), Circulation (κυκλοφορία).', 13);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Πώς θα αντιμετωπίσετε ένα σοβαρό εξωτερικό τραύμα με αιμορραγία;', 'Βάζω αμέσως γάζα και επίδεσμο', 'Πιέζω απευθείας το τραύμα με καθαρό ύφασμα κρατώντας πίεση', 'Βάζω πάγο στο τραύμα', 'Ανασηκώνω μόνο το άκρο', 'B', 'Η άμεση πίεση στο σημείο της αιμορραγίας είναι η πιο αποτελεσματική μέθοδος.', 14);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Ποιο είναι το σημάδι πνιγμονής σε ενήλικα;', 'Βήχας με ήχο', 'Δυνατή φωνή', 'Αδυναμία ομιλίας και συγκράτηση του λαιμού', 'Χλωμάδα', 'C', 'Το πιο χαρακτηριστικό σημάδι πλήρους απόφραξης είναι η αδυναμία ομιλίας και το διεθνές σήμα πνιγμονής (χέρια στο λαιμό).', 15);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Τι είναι ο παλμός καρωτίδας και πού ελέγχεται;', 'Ο παλμός στον αυχένα, δίπλα στον λάρυγγα', 'Ο παλμός στο μηρό', 'Ο παλμός στο μετακάρπιο', 'Ο παλμός στον αστράγαλο', 'A', 'Ο καρωτιδικός παλμός είναι ο πιο αξιόπιστος για έλεγχο κυκλοφορίας και βρίσκεται στο πλάι του αυχένα.', 16);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Ποια είναι η σωστή τεχνική για το χτύπημα Heimlich σε ενήλικα που πνίγεται;', 'Χτυπήματα στην πλάτη', '5 χτυπήματα στην πλάτη, μετά 5 κοιλιακές ώσεις (Heimlich)', 'Μόνο κοιλιακές ώσεις', 'Συμπιέσεις στο στήθος', 'B', 'Η σωστή σειρά για ενήλικα με συνείδηση είναι 5 χτυπήματα στην πλάτη και μετά 5 κοιλιακές ώσεις εναλλάξ.', 17);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Τι πρέπει να κάνετε όταν κάποιος λιποθυμήσει;', 'Ανασηκώνω το κεφάλι του', 'Τον ξαπλώνω με τα πόδια ελαφρώς ψηλότερα', 'Τον ξυπνάω με χαστούκια', 'Του δίνω νερό αμέσως', 'B', 'Η λιποθυμία οφείλεται σε μειωμένη αιμάτωση του εγκεφάλου. Η ανύψωση των ποδιών βοηθάει.', 18);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Πώς αντιμετωπίζεται ένα κατάγμα στο χέρι;', 'Προσπαθώ να το ισιώσω', 'Ακινητοποιώ το άκρο στη θέση που βρίσκεται με νάρθηκα ή αυτοσχέδιο υλικό', 'Μασάζ στο σημείο του πόνου', 'Τίποτα, μόνο περιμένω το ασθενοφόρο', 'B', 'Η ακινητοποίηση αποτρέπει περαιτέρω βλάβη. Ποτέ μην προσπαθείτε να ισιώσετε κάταγμα.', 19);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Σε ποια περίπτωση χρησιμοποιείται αυτόματος εξωτερικός απινιδωτής (AED);', 'Όταν το άτομο έχει καρδιακή ανακοπή', 'Όταν το άτομο αναπνέει αλλά είναι αναίσθητο', 'Όταν το άτομο έχει στηθάγχη', 'Όταν το άτομο έχει υψηλή πίεση', 'A', 'Το AED χρησιμοποιείται μόνο σε περιπτώσεις καρδιακής ανακοπής όταν δεν υπάρχει παλμός.', 20);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Ποια είναι η σωστή θέση μεταφοράς για άτομο με τραυματισμό στη σπονδυλική στήλη;', 'Ύπτια με ανυψωμένο κεφάλι', 'Πλάγια θέση', 'Ολική ακινητοποίηση σε ευθεία γραμμή', 'Καθιστή θέση', 'C', 'Ο ύποπτος τραυματισμός σπονδυλικής στήλης απαιτεί πλήρη ακινητοποίηση για αποφυγή μόνιμης βλάβης.', 21);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Τι είναι το σοκ και πώς αντιμετωπίζεται;', 'Ψυχολογική αντίδραση - με ψυχραιμία', 'Κατάσταση ανεπάρκειας οξυγόνωσης ιστών - ξάπλωμα με πόδια ψηλά και θέρμανση', 'Αλλεργική αντίδραση - με αντιισταμινικά', 'Υπερβολικός πόνος - με παυσίπονα', 'B', 'Το σοκ είναι κρίσιμη κατάσταση μειωμένης αιμάτωσης που απαιτεί ξάπλωμα, ανύψωση ποδιών και θέρμανση.', 22);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Πώς αντιμετωπίζετε ένα δάγκωμα από φίδι;', 'Βάζω πάγο και κόβω το σημείο για να βγει το δηλητήριο', 'Ακινητοποιώ το άκρο κάτω από την καρδιά, μεταφέρω αμέσως σε νοσοκομείο', 'Ρουφάω το δηλητήριο με το στόμα', 'Βάζω ισχυρό περίδεσμο πάνω από το δάγκωμα', 'B', 'Η ακινητοποίηση και η άμεση μεταφορά είναι κρίσιμες. Ποτέ μην κόβετε ή ρουφάτε το τραύμα.', 23);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Ποια είναι τα σημάδια εγκεφαλικού επεισοδίου (stroke);', 'Κόπωση και αδυναμία', 'Ξαφνική αδυναμία προσώπου/άκρων, δυσκολία ομιλίας, έντονος πονοκέφαλος', 'Ζάλη και ναυτία', 'Ταχυκαρδία', 'B', 'Τα 3 κύρια σημάδια: πτώση προσώπου (F-face), αδυναμία άκρων (A-arm), δυσκολία ομιλίας (S-speech), άμεση δράση (T-time).', 24);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'MULTIPLE_CHOICE', 'Τι πρέπει να κάνετε αν κάποιος καταπιεί χημικό καθαριστικό;', 'Προκαλώ εμετό αμέσως', 'Δίνω γάλα ή νερό και καλώ δηλητηριολογικό κέντρο', 'Δεν κάνω τίποτα, περιμένω συμπτώματα', 'Δίνω ξύδι για ουδετεροποίηση', 'B', 'Το γάλα ή νερό αραιώνει το χημικό. Ποτέ μην προκαλείτε εμετό σε χημικά (μπορεί να καούν ξανά οι ιστοί).', 25);


-- TRUE/FALSE ΕΡΩΤΗΣΕΙΣ (20 ερωτήσεις)

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Μπορώ να σταματήσω την ΚΑΑ αν κουραστώ ή αν έρθει το ασθενοφόρο.', 'Σωστό', 'Λάθος', NULL, NULL, 'T', 'Η ΚΑΑ μπορεί να σταματήσει όταν έρθει βοήθεια, το άτομο ανακτήσει παλμό, ή αν ο διασώστης εξαντληθεί.', 26);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Όταν κάποιος πνίγεται αλλά βήχει δυνατά, πρέπει να χτυπήσω την πλάτη του αμέσως.', 'Σωστό', 'Λάθος', NULL, NULL, 'F', 'Αν βήχει δυνατά σημαίνει ότι υπάρχει μερική απόφραξη και ο βήχας μπορεί να βγάλει το αντικείμενο. Ενθαρρύνουμε τον βήχα.', 27);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Το AED (απινιδωτής) μπορεί να χρησιμοποιηθεί σε βρεγμένο περιβάλλον.', 'Σωστό', 'Λάθος', NULL, NULL, 'F', 'Το θύμα πρέπει να είναι στεγνό και σε στεγνή επιφάνεια για ασφαλή χρήση του AED.', 28);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Πρέπει πάντα να ελέγχω την ασφάλεια του περιβάλλοντος πριν πλησιάσω ένα θύμα.', 'Σωστό', 'Λάθος', NULL, NULL, 'T', 'Η ασφάλεια του διασώστη είναι πρωταρχική. Δεν μπορείτε να βοηθήσετε αν τραυματιστείτε.', 29);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Σε περίπτωση υπερθερμίας, πρέπει να δώσω παγωμένο νερό να πιει το θύμα.', 'Σωστό', 'Λάθος', NULL, NULL, 'F', 'Το νερό πρέπει να είναι δροσερό, όχι παγωμένο. Το παγωμένο νερό μπορεί να προκαλέσει σοκ στο στομάχι.', 30);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Όταν κάποιος έχει στηθάγχη, πρέπει να του δώσω αμέσως ασπιρίνη.', 'Σωστό', 'Λάθος', NULL, NULL, 'F', 'Η ασπιρίνη μπορεί να βοηθήσει σε έμφραγμα αλλά μόνο αν δεν υπάρχουν αντενδείξεις. Πρέπει να ρωτήσετε το άτομο αν έχει δική του ασπιρίνη/νιτρογλυκερίνη.', 31);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Μπορώ να μετακινήσω έναν τραυματία από τροχαίο αν είναι σε επικίνδυνο σημείο.', 'Σωστό', 'Λάθος', NULL, NULL, 'T', 'Μόνο αν υπάρχει άμεσος κίνδυνος (φωτιά, έκρηξη, κυκλοφορία). Διαφορετικά περιμένουμε ειδικούς.', 32);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Πρέπει να βγάλω το κράνος από μοτοσικλετιστή που είναι άνευ αισθήσεων.', 'Σωστό', 'Λάθος', NULL, NULL, 'F', 'Το κράνος αφαιρείται μόνο από εκπαιδευμένο προσωπικό λόγω κινδύνου τραυματισμού σπονδυλικής στήλης.', 33);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Αν δω ένα κάταγμα με έξοδο οστού (ανοιχτό κάταγμα), πρέπει να το σπρώξω μέσα.', 'Σωστό', 'Λάθος', NULL, NULL, 'F', 'Ποτέ μην πειράζουμε ανοιχτό κάταγμα. Καλύπτουμε με αποστειρωμένη γάζα και ακινητοποιούμε.', 34);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Η ημερομηνία λήξης του φαρμακείου πρώτων βοηθειών δεν είναι σημαντική.', 'Σωστό', 'Λάθος', NULL, NULL, 'F', 'Τα φάρμακα και τα υλικά λήγουν και χάνουν την αποτελεσματικότητά τους. Ελέγχετε τακτικά τις ημερομηνίες.', 35);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Σε σοβαρό τραυματισμό, είναι σημαντικό να κρατάω την ψυχραιμία μου και να καθησυχάσω το θύμα.', 'Σωστό', 'Λάθος', NULL, NULL, 'T', 'Η ψυχολογική υποστήριξη και η ηρεμία του διασώστη βοηθούν το θύμα και βελτιώνουν την κατάσταση.', 36);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Τα γάντια μιας χρήσης είναι απαραίτητα για την προστασία από λοιμώξεις.', 'Σωστό', 'Λάθος', NULL, NULL, 'T', 'Τα γάντια προστατεύουν τόσο τον διασώστη όσο και το θύμα από λοιμώξεις μέσω αίματος ή υγρών.', 37);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Μπορώ να δώσω φαγητό ή νερό σε άτομο που πρόκειται να χειρουργηθεί.', 'Σωστό', 'Λάθος', NULL, NULL, 'F', 'Σε σοβαρούς τραυματισμούς που μπορεί να χρειαστεί χειρουργείο, δεν δίνουμε τίποτα από το στόμα.', 38);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Το 112 είναι το ευρωπαϊκό νούμερο έκτακτης ανάγκης και λειτουργεί σε όλη την Ευρώπη.', 'Σωστό', 'Λάθος', NULL, NULL, 'T', 'Το 112 είναι το ενιαίο ευρωπαϊκό νούμερο έκτακτης ανάγκης που συνδέει με όλες τις υπηρεσίες.', 39);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Όταν κάνω ΚΑΑ σε παιδί, χρησιμοποιώ ένα χέρι αντί για δύο.', 'Σωστό', 'Λάθος', NULL, NULL, 'T', 'Σε παιδιά (1-8 ετών) χρησιμοποιούμε ένα χέρι και σε βρέφη δύο δάχτυλα για τις συμπιέσεις.', 40);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Μπορώ να χορηγήσω φάρμακα από το φαρμακείο πρώτων βοηθειών χωρίς να ρωτήσω το θύμα.', 'Σωστό', 'Λάθος', NULL, NULL, 'F', 'Πρέπει πάντα να ρωτάμε για αλλεργίες και να παίρνουμε τη συγκατάθεση του θύματος πριν δώσουμε οποιοδήποτε φάρμακο.', 41);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Η χρήση AED (απινιδωτή) μπορεί να προκαλέσει βλάβη σε άτομο με φυσιολογικό καρδιακό ρυθμό.', 'Σωστό', 'Λάθος', NULL, NULL, 'F', 'Το AED αναλύει τον καρδιακό ρυθμό και δεν θα δώσει ηλεκτρικό shock αν δεν χρειάζεται. Είναι ασφαλές.', 42);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Σε περίπτωση αμπούλας (φουσκάλας) από έγκαυμα, πρέπει να την σκάσω αμέσως.', 'Σωστό', 'Λάθος', NULL, NULL, 'F', 'Οι φουσκάλες προστατεύουν το κάτω δέρμα από λοιμώξεις. Δεν τις σκάμε παρά μόνο ιατρικό προσωπικό.', 43);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Μετά από τραυματισμό κεφαλιού, αν το άτομο είναι εντάξει, δεν χρειάζεται ιατρική εξέταση.', 'Σωστό', 'Λάθος', NULL, NULL, 'F', 'Ακόμα και αν φαίνεται καλά, τα συμπτώματα εσωτερικής αιμορραγίας μπορεί να εμφανιστούν αργότερα. Χρειάζεται εξέταση.', 44);

INSERT INTO training_exam_questions (exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, display_order) VALUES
(@exam_id, 'TRUE_FALSE', 'Όταν κάνω τεχνητή αναπνοή, πρέπει να φουσκώσω το στήθος του θύματος πολύ δυνατά.', 'Σωστό', 'Λάθος', NULL, NULL, 'F', 'Οι αναπνοές πρέπει να είναι ομαλές και να ανασηκώνουν το στήθος ελαφρώς, όχι υπερβολικά (κίνδυνος βλάβης).', 45);

-- ΟΛΑ ΕΤΟΙΜΑ! Το script δημιουργεί:
-- - Κατηγορία "Πρώτες Βοήθειες" (αν δεν υπάρχει)
-- - Exam "Διαγώνισμα Πρώτων Βοηθειών" (αν δεν υπάρχει)
-- - 45 ερωτήσεις (25 multiple choice + 20 true/false)

