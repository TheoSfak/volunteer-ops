<?php
/** Annual volunteer subscription management. */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/subscription-iris.php';
requirePermission('subscriptions_manage');

$pageTitle = 'Ετήσιες Συνδρομές';

/**
 * Store either a normally selected receipt or a photo captured by the mobile camera.
 * Returns [originalName, storedName], or null when no file was submitted.
 */
function storeResizedSubscriptionReceiptImage(string $sourcePath, string $destinationPath, string $mime): void {
    $imageInfo = getimagesize($sourcePath);
    if (!$imageInfo || empty($imageInfo[0]) || empty($imageInfo[1])) {
        throw new RuntimeException('Η εικόνα της απόδειξης δεν είναι έγκυρη.');
    }

    [$sourceWidth, $sourceHeight] = $imageInfo;
    $maxWidth = 1920;
    $maxHeight = 1080;
    if ($sourceWidth <= $maxWidth && $sourceHeight <= $maxHeight) {
        if (!move_uploaded_file($sourcePath, $destinationPath)) {
            throw new RuntimeException('Δεν ήταν δυνατή η αποθήκευση της εικόνας της απόδειξης.');
        }
        return;
    }

    $loader = $mime === 'image/jpeg' ? 'imagecreatefromjpeg' : 'imagecreatefrompng';
    if (!function_exists('imagecreatetruecolor') || !function_exists($loader)) {
        throw new RuntimeException('Η αλλαγή μεγέθους εικόνας δεν είναι διαθέσιμη στον διακομιστή.');
    }

    $scale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
    $targetWidth = max(1, (int)floor($sourceWidth * $scale));
    $targetHeight = max(1, (int)floor($sourceHeight * $scale));
    $sourceImage = $loader($sourcePath);
    $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$sourceImage || !$targetImage) {
        if ($sourceImage) imagedestroy($sourceImage);
        if ($targetImage) imagedestroy($targetImage);
        throw new RuntimeException('Δεν ήταν δυνατή η αλλαγή μεγέθους της εικόνας της απόδειξης.');
    }

    if ($mime === 'image/png') {
        imagealphablending($targetImage, false);
        imagesavealpha($targetImage, true);
        $transparent = imagecolorallocatealpha($targetImage, 0, 0, 0, 127);
        imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $transparent);
    }

    $resampled = imagecopyresampled(
        $targetImage,
        $sourceImage,
        0,
        0,
        0,
        0,
        $targetWidth,
        $targetHeight,
        $sourceWidth,
        $sourceHeight
    );
    $saved = $resampled && ($mime === 'image/jpeg'
        ? imagejpeg($targetImage, $destinationPath, 85)
        : imagepng($targetImage, $destinationPath, 6));
    imagedestroy($sourceImage);
    imagedestroy($targetImage);

    if (!$saved) {
        if (is_file($destinationPath)) unlink($destinationPath);
        throw new RuntimeException('Δεν ήταν δυνατή η αλλαγή μεγέθους και η αποθήκευση της εικόνας της απόδειξης.');
    }
}

function storeSubscriptionReceipt(int $userId, string $volunteerName): ?array {
    $file = null;
    foreach (['receipt', 'receipt_camera'] as $field) {
        if (!empty($_FILES[$field]['name'])) {
            $file = $_FILES[$field];
            break;
        }
    }
    if (!$file) return null;

    $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    $mime = !empty($file['tmp_name']) ? (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) : false;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 10 * 1024 * 1024 || !isset($allowed[$mime])) {
        throw new RuntimeException('Η απόδειξη πρέπει να είναι PDF, JPG ή PNG έως 10MB.');
    }

    $dir = __DIR__ . '/uploads/subscription-receipts/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Δεν ήταν δυνατή η δημιουργία του φακέλου αποδείξεων.');
    }
    $nameParts = preg_split('/\s+/', trim($volunteerName));
    $surname = end($nameParts) ?: 'receipt-' . $userId;
    $greekToLatin = [
        'Α'=>'A','Β'=>'V','Γ'=>'G','Δ'=>'D','Ε'=>'E','Ζ'=>'Z','Η'=>'I','Θ'=>'Th','Ι'=>'I','Κ'=>'K','Λ'=>'L','Μ'=>'M','Ν'=>'N','Ξ'=>'X','Ο'=>'O','Π'=>'P','Ρ'=>'R','Σ'=>'S','Τ'=>'T','Υ'=>'Y','Φ'=>'F','Χ'=>'Ch','Ψ'=>'Ps','Ω'=>'O',
        'ά'=>'a','έ'=>'e','ή'=>'i','ί'=>'i','ό'=>'o','ύ'=>'y','ώ'=>'o','ϊ'=>'i','ϋ'=>'y','ΐ'=>'i','ΰ'=>'y',
        'α'=>'a','β'=>'v','γ'=>'g','δ'=>'d','ε'=>'e','ζ'=>'z','η'=>'i','θ'=>'th','ι'=>'i','κ'=>'k','λ'=>'l','μ'=>'m','ν'=>'n','ξ'=>'x','ο'=>'o','π'=>'p','ρ'=>'r','σ'=>'s','ς'=>'s','τ'=>'t','υ'=>'y','φ'=>'f','χ'=>'ch','ψ'=>'ps','ω'=>'o',
    ];
    $safeBase = strtolower(strtr($surname, $greekToLatin));
    $safeBase = preg_replace('/[^a-z0-9]+/i', '-', $safeBase);
    $safeBase = trim($safeBase, '-') ?: 'receipt-' . $userId;
    $extension = $allowed[$mime];
    $storedName = $safeBase . '.' . $extension;
    for ($suffix = 2; is_file($dir . $storedName); $suffix++) {
        $storedName = $safeBase . '-' . $suffix . '.' . $extension;
    }
    if ($mime === 'image/jpeg' || $mime === 'image/png') {
        storeResizedSubscriptionReceiptImage($file['tmp_name'], $dir . $storedName, $mime);
    } elseif (!move_uploaded_file($file['tmp_name'], $dir . $storedName)) {
        throw new RuntimeException('Δεν ήταν δυνατή η αποθήκευση της απόδειξης.');
    }
    return [$storedName, $storedName];
}

/**
 * Calculate an inclusive subscription period.
 * A new period starting on 15/07/2024 ends on 14/07/2025. A continuous
 * renewal instead extends the previous inclusive expiry by whole years.
 */
function calculateSubscriptionExpiry(string $paymentDate, int $coverageYears, ?array $previousSubscription, bool $isReactivation): string {
    if ($isReactivation || !$previousSubscription) {
        return (new DateTime($paymentDate))
            ->modify('+' . $coverageYears . ' years')
            ->modify('-1 day')
            ->format('Y-m-d');
    }

    return (new DateTime($previousSubscription['expiry_date']))
        ->modify('+' . $coverageYears . ' years')
        ->format('Y-m-d');
}

function isValidSubscriptionDate(string $value): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function isValidSubscriptionAmount(string $amount): bool {
    return $amount === '' || (is_numeric($amount) && (float)$amount >= 0);
}

/**
 * Keep the submitted payment-form values across an error redirect so the
 * operator does not retype everything. Uploaded files cannot survive the
 * redirect and are intentionally excluded.
 */
function rememberSubscriptionPaymentForm(): void {
    $_SESSION['subscription_payment_form'] = [
        'user_id' => (int)post('user_id'),
        'payment_date' => post('payment_date'),
        'expiry_date' => post('expiry_date'),
        'coverage_years' => max(1, min(5, (int)post('coverage_years', 1))),
        'amount' => post('amount'),
        'payment_method' => post('payment_method'),
        'receipt_number' => post('receipt_number'),
        'notes' => post('notes'),
        'force_reactivation' => post('force_reactivation') === '1' ? '1' : '0',
    ];
}

if (isPost()) {
    verifyCsrf();
    if (post('action') === 'mark_iris_seen') {
        $request = subscriptionIrisMarkSeen((int)post('iris_request_id'), (int)getCurrentUserId());
        if ($request) {
            logAudit('mark_subscription_iris_request_seen', 'subscription_iris_requests', (int)$request['id']);
            setFlash('success', 'Το αίτημα IRIS σημειώθηκε ως ληφθέν και ο εθελοντής ειδοποιήθηκε.');
        } else {
            setFlash('warning', 'Το αίτημα IRIS δεν είναι διαθέσιμο ή έχει ήδη ενημερωθεί.');
        }
        redirect('subscriptions.php?filter=' . urlencode(get('filter', 'all')));
    }
    if (post('action') === 'record_payment') {
        $userId = (int) post('user_id');
        $paymentDate = post('payment_date');
        $requestedExpiryDate = post('expiry_date');
        $amount = str_replace(',', '.', trim(post('amount')));
        $method = trim(post('payment_method'));
        $receiptNumber = trim(post('receipt_number'));
        $notes = trim(post('notes'));
        $irisRequestId = (int)post('iris_request_id');
        $irisRequest = $irisRequestId ? dbFetchOne("SELECT * FROM subscription_iris_requests WHERE id = ? AND user_id = ? AND status IN ('REPORTED', 'SEEN')", [$irisRequestId, $userId]) : null;
        $volunteer = dbFetchOne("SELECT id, name FROM users WHERE id = ? AND is_active = 1", [$userId]);

        if (!$volunteer || !isValidSubscriptionDate($paymentDate) || !isValidSubscriptionDate($requestedExpiryDate)) {
            rememberSubscriptionPaymentForm();
            setFlash('error', 'Επιλέξτε έγκυρο εθελοντή και ημερομηνία πληρωμής.');
        } elseif (!isValidSubscriptionAmount($amount)) {
            rememberSubscriptionPaymentForm();
            setFlash('error', 'Το ποσό πρέπει να είναι έγκυρος θετικός αριθμός (π.χ. 30 ή 30,50).');
        } else {
            $previousSubscription = dbFetchOne("SELECT id, payment_date, expiry_date FROM volunteer_subscriptions WHERE user_id = ? AND payment_date < ? ORDER BY expiry_date DESC, id DESC LIMIT 1", [$userId, $paymentDate]);
            $nextSubscription = dbFetchOne("SELECT id, payment_date, expiry_date FROM volunteer_subscriptions WHERE user_id = ? AND payment_date > ? ORDER BY payment_date ASC, id ASC LIMIT 1", [$userId, $paymentDate]);
            $activeSubscription = dbFetchOne("SELECT id, payment_date, expiry_date FROM volunteer_subscriptions WHERE user_id = ? AND expiry_date >= CURDATE() ORDER BY expiry_date DESC, id DESC LIMIT 1", [$userId]);
            $isHistorical = (bool)$nextSubscription;

            if ($requestedExpiryDate < $paymentDate) {
                rememberSubscriptionPaymentForm();
                setFlash('error', 'Η ημερομηνία λήξης δεν μπορεί να είναι πριν από την ημερομηνία πληρωμής/έναρξης.');
                redirect('subscriptions.php');
            }
            $sameStartSubscription = dbFetchOne("SELECT id, expiry_date FROM volunteer_subscriptions WHERE user_id = ? AND payment_date = ? LIMIT 1", [$userId, $paymentDate]);
            if ($sameStartSubscription) {
                setFlash('warning', 'Υπάρχει ήδη συνδρομή με ημερομηνία πληρωμής/έναρξης ' . formatDate($paymentDate) . ' και λήξη ' . formatDate($sameStartSubscription['expiry_date']) . '.');
                redirect('subscriptions.php?show_history=1&history_user_id=' . $userId . '#subscriptionPaymentHistory');
            }
            if ($isHistorical && $nextSubscription && $requestedExpiryDate >= $nextSubscription['payment_date']) {
                rememberSubscriptionPaymentForm();
                setFlash('error', 'Η παλιά συνδρομή επικαλύπτεται με την επόμενη περίοδο που ξεκινά στις ' . formatDate($nextSubscription['payment_date']) . '. Η λήξη πρέπει να είναι το αργότερο την προηγούμενη ημέρα.');
                redirect('subscriptions.php');
            }
            if ($isHistorical && $previousSubscription && $paymentDate <= $previousSubscription['expiry_date']) {
                rememberSubscriptionPaymentForm();
                setFlash('error', 'Η παλιά συνδρομή επικαλύπτεται με προηγούμενη περίοδο που λήγει στις ' . formatDate($previousSubscription['expiry_date']) . '.');
                redirect('subscriptions.php');
            }
            if ($activeSubscription && !$irisRequest && !$isHistorical) {
                setFlash('warning', 'Υπάρχει ήδη ενεργή συνδρομή έως ' . formatDate($activeSubscription['expiry_date']) . '. Μπορείτε να την επεξεργαστείτε ή να καταχωρήσετε μόνο παλαιότερη, μη επικαλυπτόμενη περίοδο.');
                redirect('subscriptions.php?edit=' . $activeSubscription['id']);
            }
            $reactivationLimit = max(0, (int)getSetting('subscription_reactivation_days', 90));
            $coverageYears = $irisRequest ? (int)$irisRequest['coverage_years'] : max(1, min(5, (int)post('coverage_years', 1)));
            if ($irisRequest) {
                $amount = number_format((float)$irisRequest['total_amount'], 2, '.', '');
                $method = 'IRIS';
            }
            $daysSinceExpiry = $previousSubscription ? (int)floor((strtotime($paymentDate) - strtotime($previousSubscription['expiry_date'])) / 86400) : 0;
            $isReactivation = post('force_reactivation') === '1' || ($previousSubscription && $daysSinceExpiry > $reactivationLimit);
            $calculatedExpiryDate = calculateSubscriptionExpiry($paymentDate, $coverageYears, $previousSubscription ?: null, $isReactivation);
            $expiryDate = $requestedExpiryDate;
            if ($expiryDate !== $calculatedExpiryDate && post('expiry_override_confirmed') !== '1') {
                rememberSubscriptionPaymentForm();
                setFlash('warning', 'Η ημερομηνία λήξης διαφέρει από την προτεινόμενη (' . formatDate($calculatedExpiryDate) . '). Επιβεβαιώστε την αλλαγή πριν την αποθήκευση.');
                redirect('subscriptions.php');
            }
            $receiptOriginal = null;
            $receiptStored = null;
            try {
                $storedReceipt = storeSubscriptionReceipt($userId, $volunteer['name']);
                if ($storedReceipt) [$receiptOriginal, $receiptStored] = $storedReceipt;
            } catch (RuntimeException $e) {
                rememberSubscriptionPaymentForm();
                setFlash('error', $e->getMessage());
                redirect('subscriptions.php');
            }

            $subscriptionId = dbInsert("INSERT INTO volunteer_subscriptions
                (user_id, payment_date, expiry_date, renewal_kind, coverage_years, amount, payment_method, receipt_number, receipt_original_name, receipt_stored_name, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                $userId, $paymentDate, $expiryDate, $isReactivation ? 'REACTIVATION' : 'RENEWAL', $coverageYears, $amount === '' ? null : $amount,
                $method ?: null, $receiptNumber ?: null, $receiptOriginal, $receiptStored, $notes ?: null, getCurrentUserId()
            ]);
            unset($_SESSION['subscription_payment_form']);
            if ($irisRequest) subscriptionIrisCompleteLatestRequest($userId);
            logAudit('record_subscription_payment', 'volunteer_subscriptions', $subscriptionId, null, [
                'user_id' => $userId,
                'payment_date' => $paymentDate,
                'expiry_date' => $expiryDate,
                'renewal_kind' => $isReactivation ? 'REACTIVATION' : 'RENEWAL',
                'historical' => $isHistorical,
            ]);
            setFlash('success', ($isHistorical ? 'Η παλιά πληρωμή καταχωρήθηκε στο ιστορικό. ' : ($isReactivation ? 'Η συνδρομή επανενεργοποιήθηκε. ' : 'Η πληρωμή καταχωρήθηκε. ')) . 'Η περίοδος λήγει στις ' . formatDate($expiryDate) . '.');
            if ($isHistorical) {
                redirect('subscriptions.php?show_history=1&history_user_id=' . $userId . '#subscriptionPaymentHistory');
            }
        }
        redirect('subscriptions.php');
    }
    if (post('action') === 'edit_subscription') {
        $subscriptionId = (int)post('subscription_id');
        $paymentDate = post('payment_date');
        $expiryDate = post('expiry_date');
        if (!isValidSubscriptionDate($paymentDate) || !isValidSubscriptionDate($expiryDate)) {
            setFlash('error', 'Οι ημερομηνίες δεν είναι έγκυρες.');
            redirect('subscriptions.php?edit=' . $subscriptionId);
        } elseif ($expiryDate < $paymentDate) {
            setFlash('error', 'Η ημερομηνία λήξης δεν μπορεί να είναι πριν από την ημερομηνία πληρωμής/έναρξης.');
            redirect('subscriptions.php?edit=' . $subscriptionId);
        } else {
            $old = dbFetchOne("SELECT vs.*, u.name AS volunteer_name FROM volunteer_subscriptions vs JOIN users u ON u.id = vs.user_id WHERE vs.id = ?", [$subscriptionId]);
            if ($old) {
                $previousSubscription = dbFetchOne("SELECT id, payment_date, expiry_date FROM volunteer_subscriptions WHERE user_id = ? AND id <> ? AND payment_date < ? ORDER BY expiry_date DESC, id DESC LIMIT 1", [$old['user_id'], $subscriptionId, $paymentDate]);
                $nextSubscription = dbFetchOne("SELECT id, payment_date, expiry_date FROM volunteer_subscriptions WHERE user_id = ? AND id <> ? AND payment_date > ? ORDER BY payment_date ASC, id ASC LIMIT 1", [$old['user_id'], $subscriptionId, $paymentDate]);
                $sameStartSubscription = dbFetchOne("SELECT id, expiry_date FROM volunteer_subscriptions WHERE user_id = ? AND id <> ? AND payment_date = ? LIMIT 1", [$old['user_id'], $subscriptionId, $paymentDate]);
                if ($sameStartSubscription) {
                    setFlash('warning', 'Υπάρχει ήδη άλλη συνδρομή με ημερομηνία πληρωμής/έναρξης ' . formatDate($paymentDate) . ' και λήξη ' . formatDate($sameStartSubscription['expiry_date']) . '.');
                    redirect('subscriptions.php?show_history=1&history_user_id=' . (int)$old['user_id'] . '#subscriptionPaymentHistory');
                }
                if ($nextSubscription && $expiryDate >= $nextSubscription['payment_date']) {
                    setFlash('error', 'Η περίοδος επικαλύπτεται με την επόμενη συνδρομή που ξεκινά στις ' . formatDate($nextSubscription['payment_date']) . '.');
                    redirect('subscriptions.php?edit=' . $subscriptionId);
                }
                if ($nextSubscription && $previousSubscription && $paymentDate <= $previousSubscription['expiry_date']) {
                    setFlash('error', 'Η περίοδος επικαλύπτεται με προηγούμενη συνδρομή που λήγει στις ' . formatDate($previousSubscription['expiry_date']) . '.');
                    redirect('subscriptions.php?edit=' . $subscriptionId);
                }
                $amount = str_replace(',', '.', trim(post('amount')));
                if (!isValidSubscriptionAmount($amount)) {
                    setFlash('error', 'Το ποσό πρέπει να είναι έγκυρος θετικός αριθμός (π.χ. 30 ή 30,50).');
                    redirect('subscriptions.php?edit=' . $subscriptionId);
                }
                $receiptNumber = trim(post('receipt_number'));
                $reset = $expiryDate !== $old['expiry_date'];
                $receiptOriginal = $old['receipt_original_name'];
                $receiptStored = $old['receipt_stored_name'];
                try {
                    $storedReceipt = storeSubscriptionReceipt((int)$old['user_id'], $old['volunteer_name']);
                    if ($storedReceipt) [$receiptOriginal, $receiptStored] = $storedReceipt;
                } catch (RuntimeException $e) {
                    setFlash('error', $e->getMessage());
                    redirect('subscriptions.php?edit=' . $subscriptionId);
                }
                dbExecute("UPDATE volunteer_subscriptions SET payment_date = ?, expiry_date = ?, amount = ?, payment_method = ?, receipt_number = ?, receipt_original_name = ?, receipt_stored_name = ?, notes = ?, renewal_kind = ?, reminder_sent_3m = ?, reminder_sent_1m = ?, reminder_sent_1w = ?, reminder_sent_expired = ? WHERE id = ?", [$paymentDate, $expiryDate, $amount === '' ? null : $amount, trim(post('payment_method')) ?: null, $receiptNumber ?: null, $receiptOriginal, $receiptStored, trim(post('notes')) ?: null, post('renewal_kind') === 'REACTIVATION' ? 'REACTIVATION' : 'RENEWAL', $reset ? 0 : $old['reminder_sent_3m'], $reset ? 0 : $old['reminder_sent_1m'], $reset ? 0 : $old['reminder_sent_1w'], $reset ? 0 : $old['reminder_sent_expired'], $subscriptionId]);
                logAudit('edit_subscription', 'volunteer_subscriptions', $subscriptionId, [
                    'payment_date' => $old['payment_date'],
                    'expiry_date' => $old['expiry_date'],
                    'amount' => $old['amount'],
                    'payment_method' => $old['payment_method'],
                    'receipt_number' => $old['receipt_number'],
                    'renewal_kind' => $old['renewal_kind'],
                ], [
                    'payment_date' => $paymentDate,
                    'expiry_date' => $expiryDate,
                    'amount' => $amount === '' ? null : $amount,
                    'payment_method' => trim(post('payment_method')) ?: null,
                    'receipt_number' => $receiptNumber ?: null,
                    'renewal_kind' => post('renewal_kind') === 'REACTIVATION' ? 'REACTIVATION' : 'RENEWAL',
                ]);
                setFlash('success', 'Η συνδρομή ενημερώθηκε.');
            }
        }
        redirect('subscriptions.php');
    }
    if (post('action') === 'delete_subscription') {
        $subscriptionId = (int)post('subscription_id');
        $old = dbFetchOne("SELECT vs.*, u.name AS volunteer_name FROM volunteer_subscriptions vs JOIN users u ON u.id = vs.user_id WHERE vs.id = ?", [$subscriptionId]);
        if (!$old) {
            setFlash('error', 'Η συνδρομή δεν βρέθηκε.');
            redirect('subscriptions.php');
        }
        if (post('delete_confirmed') !== '1') {
            setFlash('error', 'Η διαγραφή απαιτεί ρητή επιβεβαίωση.');
            redirect('subscriptions.php?show_history=1&history_user_id=' . (int)$old['user_id'] . '#subscriptionPaymentHistory');
        }
        dbExecute("DELETE FROM volunteer_subscriptions WHERE id = ?", [$subscriptionId]);
        if (!empty($old['receipt_stored_name'])) {
            $receiptPath = __DIR__ . '/uploads/subscription-receipts/' . basename($old['receipt_stored_name']);
            if (is_file($receiptPath)) @unlink($receiptPath);
        }
        logAudit('delete_subscription', 'volunteer_subscriptions', $subscriptionId, [
            'user_id' => $old['user_id'],
            'volunteer_name' => $old['volunteer_name'],
            'payment_date' => $old['payment_date'],
            'expiry_date' => $old['expiry_date'],
            'amount' => $old['amount'],
            'payment_method' => $old['payment_method'],
            'receipt_number' => $old['receipt_number'],
            'renewal_kind' => $old['renewal_kind'],
        ], null);
        setFlash('success', 'Η συνδρομή του/της ' . $old['volunteer_name'] . ' (' . formatDate($old['payment_date']) . ' – ' . formatDate($old['expiry_date']) . ') διαγράφηκε οριστικά.');
        redirect('subscriptions.php?show_history=1&history_user_id=' . (int)$old['user_id'] . '#subscriptionPaymentHistory');
    }
}

$volunteers = dbFetchAll("SELECT id, name, email FROM users WHERE is_active = 1 ORDER BY name");
$preselectedPaymentUserId = (int)get('user_id', 0);
if ($preselectedPaymentUserId && !(int)dbFetchValue("SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1", [$preselectedPaymentUserId])) {
    $preselectedPaymentUserId = 0;
}
$openPaymentModal = get('open_payment') === '1' && $preselectedPaymentUserId > 0;
$subscriptionPeriods = dbFetchAll("SELECT id, user_id, payment_date, expiry_date FROM volunteer_subscriptions ORDER BY payment_date ASC, id ASC");
$subscriptionPeriodsByVolunteer = [];
foreach ($subscriptionPeriods as $subscription) {
    $subscriptionPeriodsByVolunteer[$subscription['user_id']][] = [
        'id' => (int)$subscription['id'],
        'payment_date' => $subscription['payment_date'],
        'expiry_date' => $subscription['expiry_date'],
    ];
}
$oldPaymentForm = $_SESSION['subscription_payment_form'] ?? null;
unset($_SESSION['subscription_payment_form']);
if ($oldPaymentForm && !empty($oldPaymentForm['user_id'])) {
    $restoredUserId = (int)$oldPaymentForm['user_id'];
    if ((int)dbFetchValue("SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1", [$restoredUserId])) {
        $preselectedPaymentUserId = $restoredUserId;
    }
}
$subscriptionReactivationDays = max(0, (int)getSetting('subscription_reactivation_days', 90));
$subscriptionDateFormat = getSetting('date_format', 'd/m/Y');
if (!in_array($subscriptionDateFormat, ['d/m/Y', 'Y-m-d', 'd.m.Y'], true)) $subscriptionDateFormat = 'd/m/Y';
$filter = get('filter', 'all');
$validFilters = ['all', 'week', 'month', 'quarter', 'expired'];
if (!in_array($filter, $validFilters, true)) $filter = 'all';
$sort = (string)get('sort', 'surname');
$sortDirection = strtolower((string)get('dir', 'asc'));
$sortColumns = [
    // Names are stored as "Surname Firstname", so the first token is the surname.
    'surname' => "LOWER(TRIM(SUBSTRING_INDEX(TRIM(u.name), ' ', 1)))",
    'payment' => 'vs.payment_date',
    'expiry' => 'vs.expiry_date',
    'receipt' => "COALESCE(vs.receipt_number, '')",
];
if (!isset($sortColumns[$sort])) $sort = 'surname';
if (!in_array($sortDirection, ['asc', 'desc'], true)) $sortDirection = 'asc';
$allowedPerPage = [25, 50, 100];
$perPage = (int)get('per_page', 25);
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 25;
$sortDirectionSql = strtoupper($sortDirection);
$sortOrderSql = $sortColumns[$sort] . ' ' . $sortDirectionSql;
if ($sort === 'surname') $sortOrderSql .= ', LOWER(TRIM(u.name)) ' . $sortDirectionSql;
$sortOrderSql .= ', vs.id DESC';
$sortUrls = [];
foreach (array_keys($sortColumns) as $sortKey) {
    $nextDirection = $sort === $sortKey && $sortDirection === 'asc' ? 'desc' : 'asc';
    $sortUrls[$sortKey] = 'subscriptions.php?' . http_build_query([
        'filter' => $filter,
        'sort' => $sortKey,
        'dir' => $nextDirection,
        'per_page' => $perPage,
    ]);
}
$sortIcon = static function (string $sortKey) use ($sort, $sortDirection): string {
    if ($sort !== $sortKey) return '<i class="bi bi-arrow-down-up ms-1 text-muted" aria-hidden="true"></i>';
    $icon = $sortDirection === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down';
    return '<i class="bi ' . $icon . ' ms-1" aria-hidden="true"></i>';
};
$latestOnly = "vs.id = (SELECT vs2.id FROM volunteer_subscriptions vs2 WHERE vs2.user_id = vs.user_id ORDER BY vs2.expiry_date DESC, vs2.id DESC LIMIT 1)";
$filterSql = [
    'all' => '1=1',
    'week' => 'vs.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)',
    'month' => 'vs.expiry_date > DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND vs.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)',
    'quarter' => 'vs.expiry_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND vs.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)',
    'expired' => 'vs.expiry_date < CURDATE()',
];
$counts = [];
foreach (['week', 'month', 'quarter', 'expired'] as $key) {
    $counts[$key] = (int)dbFetchValue("SELECT COUNT(*) FROM volunteer_subscriptions vs WHERE {$latestOnly} AND {$filterSql[$key]}");
}
$isExcelExport = get('export') === 'excel';
$filteredTotal = (int)dbFetchValue("SELECT COUNT(*) FROM volunteer_subscriptions vs WHERE {$latestOnly} AND {$filterSql[$filter]}");
$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
$subscriptionPage = max(1, (int)get('page', 1));
if ($subscriptionPage > $totalPages) $subscriptionPage = $totalPages;
$offset = ($subscriptionPage - 1) * $perPage;
$paginationBaseParams = ['filter' => $filter, 'sort' => $sort, 'dir' => $sortDirection, 'per_page' => $perPage];
$previousPageUrl = $subscriptionPage > 1
    ? 'subscriptions.php?' . http_build_query($paginationBaseParams + ['page' => $subscriptionPage - 1])
    : null;
$nextPageUrl = $subscriptionPage < $totalPages
    ? 'subscriptions.php?' . http_build_query($paginationBaseParams + ['page' => $subscriptionPage + 1])
    : null;
$rowsSql = "SELECT vs.*, u.name AS volunteer_name, u.email, creator.name AS created_by_name,
        iris.id AS iris_request_id, iris.coverage_years AS iris_coverage_years, iris.total_amount AS iris_total_amount,
        iris.payment_reported_at AS iris_payment_reported_at, iris.status AS iris_status, iris.seen_at AS iris_seen_at
    FROM volunteer_subscriptions vs JOIN users u ON u.id = vs.user_id LEFT JOIN users creator ON creator.id = vs.created_by
    LEFT JOIN subscription_iris_requests iris ON iris.id = (SELECT sir.id FROM subscription_iris_requests sir WHERE sir.user_id = vs.user_id AND sir.status IN ('REPORTED', 'SEEN') ORDER BY sir.id DESC LIMIT 1)
    WHERE {$latestOnly} AND {$filterSql[$filter]} ORDER BY {$sortOrderSql}";
if (!$isExcelExport) $rowsSql .= " LIMIT {$perPage} OFFSET {$offset}";
$rows = dbFetchAll($rowsSql);
$editing = get('edit') ? dbFetchOne("SELECT vs.*, u.name AS volunteer_name FROM volunteer_subscriptions vs JOIN users u ON u.id = vs.user_id WHERE vs.id = ?", [(int)get('edit')]) : null;
$irisRequestForPayment = get('iris_request') ? dbFetchOne("SELECT sir.*, u.name AS volunteer_name FROM subscription_iris_requests sir JOIN users u ON u.id = sir.user_id WHERE sir.id = ? AND sir.status IN ('REPORTED', 'SEEN')", [(int)get('iris_request')]) : null;

if ($isExcelExport) {
    $filterLabels = ['all' => 'oles', 'week' => '1-evdomada', 'month' => '1-minas', 'quarter' => '3-mines', 'expired' => 'ligmenes'];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="subscriptions-' . $filterLabels[$filter] . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel Greek text
    fputcsv($out, ['Εθελοντής', 'Email', 'Ημερομηνία Πληρωμής', 'Ημερομηνία Λήξης', 'Έτη κάλυψης', 'Τύπος', 'Ποσό', 'Τρόπος Πληρωμής', 'Αριθμός Απόδειξης', 'Κατάσταση', 'Σημειώσεις'], ';');
    foreach ($rows as $row) {
        $days = (int)floor((strtotime($row['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
        $status = $days < 0 ? 'Ληγμένη' : ($days === 0 ? 'Λήγει σήμερα' : 'Ενεργή (' . $days . ' ημέρες)');
        fputcsv($out, [
            $row['volunteer_name'], $row['email'], $row['payment_date'], $row['expiry_date'], $row['coverage_years'] ?? 1,
            $row['renewal_kind'] === 'REACTIVATION' ? 'Επανενεργοποίηση' : 'Ανανέωση',
            $row['amount'] ?? '', $row['payment_method'] ?? '', $row['receipt_number'] ?? '', $status, $row['notes'] ?? ''
        ], ';');
    }
    fclose($out);
    exit;
}

$showPaymentHistory = get('show_history') === '1';
$historyPerPage = 50;
$historyUserId = max(0, (int)get('history_user_id', 0));
if ($historyUserId && !(int)dbFetchValue("SELECT COUNT(*) FROM users WHERE id = ?", [$historyUserId])) {
    $historyUserId = 0;
}
$historyWhereSql = $historyUserId ? "WHERE vs.user_id = {$historyUserId}" : '';

if (get('export') === 'history') {
    $historyExportRows = dbFetchAll("SELECT vs.*, u.name AS volunteer_name, u.email, creator.name AS created_by_name
        FROM volunteer_subscriptions vs
        JOIN users u ON u.id = vs.user_id
        LEFT JOIN users creator ON creator.id = vs.created_by
        {$historyWhereSql}
        ORDER BY vs.payment_date DESC, vs.id DESC");
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="subscriptions-history-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel Greek text
    fputcsv($out, ['Εθελοντής', 'Email', 'Ημερομηνία Πληρωμής', 'Ημερομηνία Λήξης', 'Έτη κάλυψης', 'Τύπος', 'Ποσό', 'Τρόπος Πληρωμής', 'Αριθμός Απόδειξης', 'Σημειώσεις', 'Καταχώρηση από', 'Ημερομηνία καταχώρησης'], ';');
    foreach ($historyExportRows as $historyExportRow) {
        fputcsv($out, [
            $historyExportRow['volunteer_name'], $historyExportRow['email'], $historyExportRow['payment_date'], $historyExportRow['expiry_date'],
            $historyExportRow['coverage_years'] ?? 1,
            $historyExportRow['renewal_kind'] === 'REACTIVATION' ? 'Επανενεργοποίηση' : 'Ανανέωση',
            $historyExportRow['amount'] ?? '', $historyExportRow['payment_method'] ?? '', $historyExportRow['receipt_number'] ?? '',
            $historyExportRow['notes'] ?? '', $historyExportRow['created_by_name'] ?? '', $historyExportRow['created_at'] ?? ''
        ], ';');
    }
    fclose($out);
    exit;
}

$historyTotal = (int)dbFetchValue("SELECT COUNT(*) FROM volunteer_subscriptions vs {$historyWhereSql}");
$historyYearTotals = $showPaymentHistory
    ? dbFetchAll("SELECT YEAR(vs.payment_date) AS payment_year, COUNT(*) AS payments_count, COALESCE(SUM(vs.amount), 0) AS total_amount
        FROM volunteer_subscriptions vs {$historyWhereSql}
        GROUP BY YEAR(vs.payment_date) ORDER BY payment_year DESC")
    : [];
$historyVolunteers = dbFetchAll("SELECT DISTINCT u.id, u.name FROM volunteer_subscriptions vs JOIN users u ON u.id = vs.user_id ORDER BY u.name");
$historyTotalPages = max(1, (int)ceil($historyTotal / $historyPerPage));
$historyCurrentPage = max(1, (int)get('history_page', 1));
if ($historyCurrentPage > $historyTotalPages) $historyCurrentPage = $historyTotalPages;
$historyOffset = ($historyCurrentPage - 1) * $historyPerPage;
$historyRows = $showPaymentHistory
    ? dbFetchAll("SELECT vs.*, u.name AS volunteer_name, u.email, creator.name AS created_by_name
        FROM volunteer_subscriptions vs
        JOIN users u ON u.id = vs.user_id
        LEFT JOIN users creator ON creator.id = vs.created_by
        {$historyWhereSql}
        ORDER BY vs.payment_date DESC, vs.id DESC
        LIMIT {$historyPerPage} OFFSET {$historyOffset}")
    : [];
$historyPaginationPages = [1, $historyTotalPages];
for ($pageNumber = $historyCurrentPage - 2; $pageNumber <= $historyCurrentPage + 2; $pageNumber++) {
    if ($pageNumber >= 1 && $pageNumber <= $historyTotalPages) $historyPaginationPages[] = $pageNumber;
}
$historyPaginationPages = array_values(array_unique($historyPaginationPages));
sort($historyPaginationPages);

include __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 subscriptions-page-header">
    <h1 class="h3 mb-0"><i class="bi bi-cash-coin me-2"></i>Ετήσιες Συνδρομές</h1>
    <div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-secondary" href="subscriptions.php?<?= h(http_build_query(array_filter(['filter' => $filter, 'sort' => $sort, 'dir' => $sortDirection, 'per_page' => $perPage, 'page' => $subscriptionPage, 'show_history' => $showPaymentHistory ? null : 1], static fn($value) => $value !== null))) ?><?= $showPaymentHistory ? '' : '#subscriptionPaymentHistory' ?>"><i class="bi bi-clock-history me-1"></i><?= $showPaymentHistory ? 'Απόκρυψη ιστορικού' : 'Ιστορικό πληρωμών' ?> <span class="badge text-bg-secondary ms-1"><?= $historyTotal ?></span></a><a class="btn btn-outline-success" href="subscriptions.php?<?= h(http_build_query(['filter' => $filter, 'sort' => $sort, 'dir' => $sortDirection, 'per_page' => $perPage, 'export' => 'excel'])) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Εξαγωγή Excel</a><button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#paymentModal"><i class="bi bi-plus-lg me-1"></i>Καταχώρηση πληρωμής</button></div>
</div>
<?php if ($editing): ?>
<div class="card border-primary mb-3">
    <div class="card-header"><strong>Επεξεργασία συνδρομής: <?= h($editing['volunteer_name']) ?></strong></div>
    <form id="editSubscriptionForm" method="post" enctype="multipart/form-data">
        <div class="card-body row g-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_subscription">
            <input type="hidden" name="subscription_id" value="<?= $editing['id'] ?>">
            <div class="col-md-3"><label class="form-label">Πληρωμή</label><input class="form-control subscription-datepicker" type="text" name="payment_date" value="<?= h($editing['payment_date']) ?>" required></div>
            <div class="col-md-3"><label class="form-label">Λήξη</label><input class="form-control subscription-datepicker" type="text" name="expiry_date" value="<?= h($editing['expiry_date']) ?>" required><div class="form-text" id="editExpiryHint"></div></div>
            <div class="col-md-2"><label class="form-label">Ποσό</label><input class="form-control" type="number" step="0.01" name="amount" value="<?= h($editing['amount']) ?>"></div>
            <div class="col-md-2"><label class="form-label">Τύπος</label><select class="form-select" name="renewal_kind"><option value="RENEWAL" <?= $editing['renewal_kind'] === 'RENEWAL' ? 'selected' : '' ?>>Ανανέωση</option><option value="REACTIVATION" <?= $editing['renewal_kind'] === 'REACTIVATION' ? 'selected' : '' ?>>Επανενεργοποίηση</option></select></div>
            <div class="col-md-2"><label class="form-label">Τρόπος</label><input class="form-control" name="payment_method" value="<?= h($editing['payment_method']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Αριθμός απόδειξης</label><input class="form-control" name="receipt_number" maxlength="100" value="<?= h($editing['receipt_number'] ?? '') ?>"></div>
            <div class="col-md-6" id="editReceiptUpload"><label class="form-label">Αντικατάσταση απόδειξης</label><input type="file" class="form-control" name="receipt" accept=".pdf,.jpg,.jpeg,.png"><div class="form-text">PDF, JPG ή PNG έως 10MB. Οι εικόνες περιορίζονται αυτόματα έως 1920×1080. Η νέα απόδειξη αντικαθιστά την καταχωρημένη.</div></div>
            <div class="col-12"><label class="form-label">Σημειώσεις</label><textarea class="form-control" name="notes" rows="2"><?= h($editing['notes']) ?></textarea></div>
        </div>
        <div class="card-footer"><button class="btn btn-primary">Αποθήκευση αλλαγών</button> <a href="subscriptions.php" class="btn btn-outline-secondary">Ακύρωση</a></div>
    </form>
</div>
<?php endif; ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach (['all' => ['Όλες', null, 'secondary'], 'week' => ['Λήγουν σε 1 εβδομάδα', $counts['week'], 'danger'], 'month' => ['Λήγουν σε 1 μήνα', $counts['month'], 'warning'], 'quarter' => ['Λήγουν σε 3 μήνες', $counts['quarter'], 'info'], 'expired' => ['Ληγμένες', $counts['expired'], 'dark']] as $key => [$label, $count, $color]): ?>
        <a href="subscriptions.php?<?= h(http_build_query(['filter' => $key, 'sort' => $sort, 'dir' => $sortDirection, 'per_page' => $perPage])) ?>" class="btn btn-sm <?= $filter === $key ? 'btn-' . $color : 'btn-outline-' . $color ?>"><?= $label ?><?php if ($count !== null): ?> <span class="badge text-bg-light ms-1"><?= $count ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</div>
<style>
.subscription-list-table td:nth-child(9) { min-width: 205px; text-align: center; }
.subscription-list-table td:nth-child(9) .d-flex { justify-content: center !important; align-items: center; flex-wrap: nowrap; gap: .5rem !important; }
.subscription-list-table td:nth-child(9) form { margin: 0; flex: 0 0 auto; }
.subscription-list-table td:nth-child(9) .btn { white-space: nowrap; }
@media (max-width: 767.98px) {
    .subscriptions-page-header {
        flex-direction: column;
        align-items: stretch !important;
        gap: .75rem;
    }
    .subscriptions-page-header > div { display: grid !important; grid-template-columns: 1fr; }
    .subscriptions-page-header .btn { width: 100%; }
    .subscriptions-mobile-sort { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .subscription-list-table,
    .subscription-list-table tbody,
    .subscription-history-table,
    .subscription-history-table tbody { display: block; width: 100%; }
    .subscription-list-table thead,
    .subscription-history-table thead { display: none; }
    .subscription-list-table tbody,
    .subscription-history-table tbody { display: grid; gap: .75rem; padding: .75rem; }
    .subscription-list-table tr,
    .subscription-history-table tr {
        display: block;
        border: 1px solid var(--bs-border-color);
        border-radius: .75rem;
        background: var(--bs-body-bg);
        padding: .25rem .85rem;
        box-shadow: 0 .125rem .25rem rgba(0, 0, 0, .04);
    }
    .subscription-list-table td,
    .subscription-history-table td {
        display: block;
        position: relative;
        width: 100%;
        min-width: 0 !important;
        max-width: none !important;
        min-height: 2.6rem;
        padding: .65rem 0 .65rem 40% !important;
        text-align: right !important;
        border-bottom: 1px solid var(--bs-border-color);
        overflow-wrap: anywhere;
    }
    .subscription-list-table td:last-child,
    .subscription-history-table td:last-child { border-bottom: 0; }
    .subscription-list-table td::before,
    .subscription-history-table td::before {
        content: attr(data-label);
        position: absolute;
        top: .65rem;
        left: 0;
        width: 36%;
        color: var(--bs-secondary-color);
        font-weight: 600;
        text-align: left;
    }
    .subscription-list-table td:nth-child(9) .d-flex {
        justify-content: flex-end !important;
        flex-wrap: wrap;
    }
    .subscription-list-table .badge { white-space: normal; }
    .subscription-list-table .mobile-card-actions { justify-content: flex-end; }
    .subscription-list-table .mobile-card-actions .btn { margin-left: auto; }
    .subscription-list-table .mobile-card-empty,
    .subscription-history-table .mobile-card-empty {
        display: block;
        min-height: 0;
        padding: 1rem 0 !important;
        text-align: center !important;
        border: 0;
    }
    .subscription-list-table .mobile-card-empty::before,
    .subscription-history-table .mobile-card-empty::before { display: none; }
    .subscriptions-pagination nav { max-width: 100%; overflow-x: auto; }
    #subscriptionHistorySearch { width: 100%; max-width: none !important; }
}
</style>
<div class="d-md-none gap-2 mb-3 subscriptions-mobile-sort" aria-label="Ταξινόμηση συνδρομών">
    <a class="btn btn-sm btn-outline-secondary" href="<?= h($sortUrls['surname']) ?>">Εθελοντής<?= $sortIcon('surname') ?></a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h($sortUrls['payment']) ?>">Πληρωμή<?= $sortIcon('payment') ?></a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h($sortUrls['expiry']) ?>">Λήξη<?= $sortIcon('expiry') ?></a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h($sortUrls['receipt']) ?>">Αρ. απόδειξης<?= $sortIcon('receipt') ?></a>
</div>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0 subscription-list-table">
<thead><tr><th><a href="<?= h($sortUrls['surname']) ?>" class="text-decoration-none text-reset" title="Ταξινόμηση κατά επώνυμο">Εθελοντής<?= $sortIcon('surname') ?></a></th><th><a href="<?= h($sortUrls['payment']) ?>" class="text-decoration-none text-reset" title="Ταξινόμηση κατά ημερομηνία πληρωμής">Πληρωμή<?= $sortIcon('payment') ?></a></th><th><a href="<?= h($sortUrls['expiry']) ?>" class="text-decoration-none text-reset" title="Ταξινόμηση κατά ημερομηνία λήξης">Λήξη<?= $sortIcon('expiry') ?></a></th><th>Έτη</th><th style="min-width:130px">Ποσό</th><th>Τρόπος</th><th><a href="<?= h($sortUrls['receipt']) ?>" class="text-decoration-none text-reset" title="Ταξινόμηση κατά αριθμό απόδειξης">Αρ. απόδειξης<?= $sortIcon('receipt') ?></a></th><th style="max-width:150px">Απόδειξη</th><th>Πληρωμή με IRIS</th><th>Κατάσταση</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $row): $days = (int)floor((strtotime($row['expiry_date']) - strtotime(date('Y-m-d'))) / 86400); $badge = $days < 0 ? 'danger' : ($days <= 7 ? 'danger' : ($days <= 30 ? 'warning text-dark' : ($days <= 90 ? 'info text-dark' : 'success'))); $label = $days < 0 ? 'Ληγμένη' : ($days === 0 ? 'Λήγει σήμερα' : 'Ενεργή (' . $days . ' ημ.)'); $hasReceiptFile = !empty($row['receipt_stored_name']) && is_file(__DIR__ . '/uploads/subscription-receipts/' . basename($row['receipt_stored_name'])); ?>
<?php $isReceiptImage = $hasReceiptFile && in_array(strtolower(pathinfo($row['receipt_stored_name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true); ?>
<tr>
    <td data-label="Εθελοντής"><a href="volunteer-view.php?id=<?= $row['user_id'] ?>"><?= h($row['volunteer_name']) ?></a></td>
    <td data-label="Πληρωμή"><?= formatDate($row['payment_date']) ?></td>
    <td data-label="Λήξη"><?= formatDate($row['expiry_date']) ?></td>
    <td data-label="Έτη"><?= (int)($row['coverage_years'] ?? 1) ?></td>
    <td data-label="Ποσό" class="text-nowrap" style="min-width:130px"><?= $row['amount'] !== null ? number_format((float)$row['amount'], 2, ',', '.') . ' €' : '—' ?></td>
    <td data-label="Τρόπος"><?= h($row['payment_method'] ?: '—') ?></td>
    <td data-label="Αρ. απόδειξης"><?= h($row['receipt_number'] ?: '—') ?></td>
    <td data-label="Απόδειξη" style="max-width:150px"><?php if ($hasReceiptFile): ?><button type="button" class="btn btn-sm btn-outline-secondary receipt-preview-btn text-truncate mw-100" data-bs-toggle="modal" data-bs-target="#receiptPreviewModal" data-preview-url="subscription-receipt.php?id=<?= $row['id'] ?>" data-preview-name="<?= h($row['receipt_original_name']) ?>" data-preview-type="<?= $isReceiptImage ? 'image' : 'pdf' ?>"><i class="bi <?= $isReceiptImage ? 'bi-eye' : 'bi-file-earmark-pdf' ?>"></i> Προβολή</button><?php elseif (!empty($row['receipt_stored_name'])): ?><span class="text-danger small"><i class="bi bi-exclamation-triangle"></i> Μη διαθέσιμη</span><?php else: ?>—<?php endif; ?></td>
    <td data-label="Πληρωμή με IRIS"><?php if ($row['iris_request_id']): ?><div><div class="small <?= $row['iris_status'] === 'SEEN' ? 'text-decoration-line-through text-muted' : '' ?>"><strong><?= formatDateTime($row['iris_payment_reported_at']) ?></strong><br><?= (int)$row['iris_coverage_years'] ?> έτη · <?= number_format((float)$row['iris_total_amount'], 2, ',', '.') ?> €</div><?php if ($row['iris_status'] === 'REPORTED'): ?><div class="d-flex gap-1 mt-1"><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="mark_iris_seen"><input type="hidden" name="iris_request_id" value="<?= (int)$row['iris_request_id'] ?>"><button class="btn btn-sm btn-outline-secondary">Το είδα</button></form><a class="btn btn-sm btn-outline-success" href="subscriptions.php?filter=<?= h($filter) ?>&iris_request=<?= (int)$row['iris_request_id'] ?>">Καταχώρηση</a></div><?php endif; ?></div><?php else: ?>—<?php endif; ?></td>
    <td data-label="Κατάσταση"><span class="badge bg-<?= $badge ?>"><?= $label ?></span></td>
    <td data-label="Ενέργειες" class="mobile-card-actions"><div class="d-flex gap-1 justify-content-end"><a class="btn btn-sm btn-outline-primary" href="subscriptions.php?filter=<?= h($filter) ?>&edit=<?= $row['id'] ?>" aria-label="Επεξεργασία συνδρομής"><i class="bi bi-pencil"></i></a><button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteSubscriptionModal" data-subscription-id="<?= $row['id'] ?>" data-volunteer="<?= h($row['volunteer_name']) ?>" data-period="<?= formatDate($row['payment_date']) ?> – <?= formatDate($row['expiry_date']) ?>" data-amount="<?= $row['amount'] !== null ? number_format((float)$row['amount'], 2, ',', '.') . ' €' : '—' ?>" aria-label="Διαγραφή συνδρομής"><i class="bi bi-trash"></i></button></div></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="11" class="text-center text-muted py-4 mobile-card-empty">Δεν υπάρχουν καταχωρημένες συνδρομές.</td></tr><?php endif; ?>
</tbody></table></div></div>

<?php if ($filteredTotal > 0): ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 subscriptions-pagination">
    <div class="d-flex flex-wrap align-items-center gap-3">
        <div class="small text-muted">Εμφάνιση <?= $offset + 1 ?>–<?= min($offset + $perPage, $filteredTotal) ?> από <?= $filteredTotal ?> συνδρομές</div>
        <form method="get" class="d-flex align-items-center gap-2">
            <input type="hidden" name="filter" value="<?= h($filter) ?>">
            <input type="hidden" name="sort" value="<?= h($sort) ?>">
            <input type="hidden" name="dir" value="<?= h($sortDirection) ?>">
            <label for="subscriptionsPerPage" class="small text-muted text-nowrap">Ανά σελίδα</label>
            <select class="form-select form-select-sm" id="subscriptionsPerPage" name="per_page" onchange="this.form.submit()" style="width:auto">
                <?php foreach ($allowedPerPage as $perPageOption): ?><option value="<?= $perPageOption ?>" <?= $perPage === $perPageOption ? 'selected' : '' ?>><?= $perPageOption ?></option><?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php if ($totalPages > 1): ?>
    <nav class="btn-group btn-group-sm" aria-label="Σελιδοποίηση συνδρομών">
        <?php if ($previousPageUrl): ?><a class="btn btn-outline-secondary" href="<?= h($previousPageUrl) ?>"><i class="bi bi-chevron-left me-1"></i>Προηγούμενη</a><?php else: ?><span class="btn btn-outline-secondary disabled" aria-disabled="true"><i class="bi bi-chevron-left me-1"></i>Προηγούμενη</span><?php endif; ?>
        <span class="btn btn-outline-secondary disabled" aria-current="page">Σελίδα <?= $subscriptionPage ?> από <?= $totalPages ?></span>
        <?php if ($nextPageUrl): ?><a class="btn btn-outline-secondary" href="<?= h($nextPageUrl) ?>">Επόμενη<i class="bi bi-chevron-right ms-1"></i></a><?php else: ?><span class="btn btn-outline-secondary disabled" aria-disabled="true">Επόμενη<i class="bi bi-chevron-right ms-1"></i></span><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($showPaymentHistory): ?>
<div class="mt-3" id="subscriptionPaymentHistory">
    <div class="card shadow-sm border-secondary">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="mb-1"><i class="bi bi-clock-history me-2"></i>Ιστορικό πληρωμών συνδρομών</h5>
                <div class="small text-muted">Εμφάνιση <?= $historyTotal ? $historyOffset + 1 : 0 ?>–<?= min($historyOffset + $historyPerPage, $historyTotal) ?> από <?= $historyTotal ?> πληρωμές</div>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <form method="get" action="subscriptions.php#subscriptionPaymentHistory" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="filter" value="<?= h($filter) ?>">
                    <input type="hidden" name="sort" value="<?= h($sort) ?>">
                    <input type="hidden" name="dir" value="<?= h($sortDirection) ?>">
                    <input type="hidden" name="per_page" value="<?= $perPage ?>">
                    <input type="hidden" name="show_history" value="1">
                    <label class="visually-hidden" for="subscriptionHistoryVolunteer">Εθελοντής ιστορικού</label>
                    <select class="form-select form-select-sm" id="subscriptionHistoryVolunteer" name="history_user_id" onchange="this.form.submit()">
                        <option value="0">Όλοι οι εθελοντές</option>
                        <?php foreach ($historyVolunteers as $historyVolunteer): ?><option value="<?= (int)$historyVolunteer['id'] ?>" <?= $historyUserId === (int)$historyVolunteer['id'] ? 'selected' : '' ?>><?= h($historyVolunteer['name']) ?></option><?php endforeach; ?>
                    </select>
                </form>
                <a class="btn btn-sm btn-outline-success" href="subscriptions.php?export=history<?= $historyUserId ? '&history_user_id=' . $historyUserId : '' ?>"><i class="bi bi-file-earmark-excel me-1"></i>Εξαγωγή ιστορικού</a>
                <input type="search" class="form-control form-control-sm" id="subscriptionHistorySearch" style="max-width:320px" placeholder="Αναζήτηση στην τρέχουσα σελίδα…" autocomplete="off">
            </div>
        </div>
        <?php if ($historyYearTotals): ?>
        <div class="d-flex flex-wrap gap-2 px-3 py-2 border-bottom align-items-center">
            <span class="small text-muted">Εισπράξεις ανά έτος:</span>
            <?php foreach ($historyYearTotals as $yearTotal): ?>
                <span class="badge text-bg-light border"><strong><?= (int)$yearTotal['payment_year'] ?></strong>: <?= (int)$yearTotal['payments_count'] ?> πληρωμές · <?= number_format((float)$yearTotal['total_amount'], 2, ',', '.') ?> €</span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 subscription-history-table">
                <thead><tr><th>Εθελοντής</th><th>Πληρωμή</th><th>Λήξη</th><th>Έτη</th><th>Ποσό</th><th>Τρόπος</th><th>Αρ. απόδειξης</th><th>Απόδειξη</th><th>Καταχώρηση από</th><th>Ενέργειες</th></tr></thead>
                <tbody>
                <?php foreach ($historyRows as $historyRow): ?>
                    <?php
                    $historyReceiptPath = __DIR__ . '/uploads/subscription-receipts/' . basename((string)$historyRow['receipt_stored_name']);
                    $historyHasReceipt = !empty($historyRow['receipt_stored_name']) && is_file($historyReceiptPath);
                    $historyReceiptIsImage = $historyHasReceipt && in_array(strtolower(pathinfo($historyRow['receipt_stored_name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true);
                    $historySearchText = implode(' ', [
                        $historyRow['volunteer_name'],
                        $historyRow['email'],
                        $historyRow['payment_date'],
                        $historyRow['expiry_date'],
                        $historyRow['amount'] ?? '',
                        $historyRow['payment_method'] ?? '',
                        $historyRow['receipt_number'] ?? '',
                        $historyRow['notes'] ?? '',
                        $historyRow['created_by_name'] ?? '',
                    ]);
                    ?>
                    <tr class="subscription-history-row" data-history-search="<?= h($historySearchText) ?>">
                        <td data-label="Εθελοντής"><div><a href="volunteer-view.php?id=<?= (int)$historyRow['user_id'] ?>"><?= h($historyRow['volunteer_name']) ?></a><div class="small text-muted"><?= h($historyRow['email']) ?></div></div></td>
                        <td data-label="Πληρωμή" class="text-nowrap"><?= formatDate($historyRow['payment_date']) ?></td>
                        <td data-label="Λήξη" class="text-nowrap"><?= formatDate($historyRow['expiry_date']) ?></td>
                        <td data-label="Έτη"><?= (int)($historyRow['coverage_years'] ?? 1) ?></td>
                        <td data-label="Ποσό" class="text-nowrap"><?= $historyRow['amount'] !== null ? number_format((float)$historyRow['amount'], 2, ',', '.') . ' €' : '—' ?></td>
                        <td data-label="Τρόπος"><?= h($historyRow['payment_method'] ?: '—') ?></td>
                        <td data-label="Αρ. απόδειξης"><?= h($historyRow['receipt_number'] ?: '—') ?></td>
                        <td data-label="Απόδειξη"><?php if ($historyHasReceipt): ?><button type="button" class="btn btn-sm btn-outline-secondary receipt-preview-btn" data-bs-toggle="modal" data-bs-target="#receiptPreviewModal" data-preview-url="subscription-receipt.php?id=<?= (int)$historyRow['id'] ?>" data-preview-name="<?= h($historyRow['receipt_original_name'] ?: 'Απόδειξη ' . formatDate($historyRow['payment_date'])) ?>" data-preview-type="<?= $historyReceiptIsImage ? 'image' : 'pdf' ?>"><i class="bi <?= $historyReceiptIsImage ? 'bi-image' : 'bi-file-earmark-pdf' ?>"></i> Προβολή</button><?php elseif (!empty($historyRow['receipt_stored_name'])): ?><span class="text-danger small"><i class="bi bi-exclamation-triangle"></i> Μη διαθέσιμη</span><?php else: ?>—<?php endif; ?></td>
                        <td data-label="Καταχώρηση από"><?= h($historyRow['created_by_name'] ?: '—') ?></td>
                        <td data-label="Ενέργειες" class="mobile-card-actions"><div class="d-flex gap-1"><a class="btn btn-sm btn-outline-primary" href="subscriptions.php?edit=<?= (int)$historyRow['id'] ?>" aria-label="Επεξεργασία συνδρομής"><i class="bi bi-pencil"></i></a><button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteSubscriptionModal" data-subscription-id="<?= (int)$historyRow['id'] ?>" data-volunteer="<?= h($historyRow['volunteer_name']) ?>" data-period="<?= formatDate($historyRow['payment_date']) ?> – <?= formatDate($historyRow['expiry_date']) ?>" data-amount="<?= $historyRow['amount'] !== null ? number_format((float)$historyRow['amount'], 2, ',', '.') . ' €' : '—' ?>" aria-label="Διαγραφή συνδρομής"><i class="bi bi-trash"></i></button></div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$historyRows): ?><tr><td colspan="10" class="text-center text-muted py-4 mobile-card-empty">Δεν υπάρχει ιστορικό πληρωμών.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($historyTotalPages > 1): ?>
        <div class="card-footer d-flex justify-content-center">
            <nav aria-label="Σελιδοποίηση ιστορικού πληρωμών">
                <ul class="pagination pagination-sm mb-0">
                    <?php $historyBaseParams = ['filter' => $filter, 'sort' => $sort, 'dir' => $sortDirection, 'per_page' => $perPage, 'page' => $subscriptionPage, 'show_history' => 1, 'history_user_id' => $historyUserId ?: null]; ?>
                    <li class="page-item <?= $historyCurrentPage <= 1 ? 'disabled' : '' ?>">
                        <?php if ($historyCurrentPage <= 1): ?><span class="page-link" aria-hidden="true">&laquo;</span><?php else: ?><a class="page-link" href="subscriptions.php?<?= h(http_build_query($historyBaseParams + ['history_page' => $historyCurrentPage - 1])) ?>#subscriptionPaymentHistory" aria-label="Προηγούμενη σελίδα ιστορικού">&laquo;</a><?php endif; ?>
                    </li>
                    <?php $previousHistoryPage = null; ?>
                    <?php foreach ($historyPaginationPages as $historyPageNumber): ?>
                        <?php if ($previousHistoryPage !== null && $historyPageNumber > $previousHistoryPage + 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                        <li class="page-item <?= $historyPageNumber === $historyCurrentPage ? 'active' : '' ?>" <?= $historyPageNumber === $historyCurrentPage ? 'aria-current="page"' : '' ?>>
                            <?php if ($historyPageNumber === $historyCurrentPage): ?><span class="page-link"><?= $historyPageNumber ?></span><?php else: ?><a class="page-link" href="subscriptions.php?<?= h(http_build_query($historyBaseParams + ['history_page' => $historyPageNumber])) ?>#subscriptionPaymentHistory"><?= $historyPageNumber ?></a><?php endif; ?>
                        </li>
                        <?php $previousHistoryPage = $historyPageNumber; ?>
                    <?php endforeach; ?>
                    <li class="page-item <?= $historyCurrentPage >= $historyTotalPages ? 'disabled' : '' ?>">
                        <?php if ($historyCurrentPage >= $historyTotalPages): ?><span class="page-link" aria-hidden="true">&raquo;</span><?php else: ?><a class="page-link" href="subscriptions.php?<?= h(http_build_query($historyBaseParams + ['history_page' => $historyCurrentPage + 1])) ?>#subscriptionPaymentHistory" aria-label="Επόμενη σελίδα ιστορικού">&raquo;</a><?php endif; ?>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="receiptPreviewModal" tabindex="-1" aria-labelledby="receiptPreviewModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-truncate" id="receiptPreviewModalTitle">Προεπισκόπηση απόδειξης</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Κλείσιμο"></button>
            </div>
            <div class="modal-body text-center">
                <img id="receiptPreviewImage" src="" alt="Προεπισκόπηση απόδειξης" class="img-fluid rounded border d-none" style="max-width:100%;max-height:70vh;object-fit:contain;">
                <iframe id="receiptPreviewPdf" src="" title="Προεπισκόπηση απόδειξης PDF" class="w-100 border rounded d-none" style="height:70vh;"></iframe>
                <div id="receiptPreviewError" class="alert alert-danger mt-3 d-none mb-0">Δεν ήταν δυνατή η προβολή της απόδειξης. Δοκιμάστε να την ανοίξετε ξανά.</div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteSubscriptionModal" tabindex="-1" aria-labelledby="deleteSubscriptionModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-danger">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_subscription">
                <input type="hidden" name="subscription_id" id="deleteSubscriptionId" value="">
                <div class="modal-header text-bg-danger">
                    <h5 class="modal-title" id="deleteSubscriptionModalTitle"><i class="bi bi-exclamation-triangle-fill me-2"></i>Οριστική διαγραφή συνδρομής</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Κλείσιμο"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Πρόκειται να διαγράψετε <strong>οριστικά</strong> την παρακάτω πληρωμή συνδρομής:</p>
                    <ul class="mb-3">
                        <li>Εθελοντής: <strong id="deleteSubscriptionVolunteer"></strong></li>
                        <li>Περίοδος: <strong id="deleteSubscriptionPeriod"></strong></li>
                        <li>Ποσό: <strong id="deleteSubscriptionAmount"></strong></li>
                    </ul>
                    <div class="alert alert-danger mb-3"><i class="bi bi-exclamation-octagon me-1"></i>Η ενέργεια δεν μπορεί να αναιρεθεί. Θα διαγραφεί και η αποθηκευμένη απόδειξη, και οι αυτόματοι υπολογισμοί λήξης για τον εθελοντή θα βασιστούν στις υπόλοιπες περιόδους.</div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" name="delete_confirmed" id="deleteSubscriptionConfirm">
                        <label class="form-check-label" for="deleteSubscriptionConfirm">Κατανοώ ότι η διαγραφή είναι οριστική και θέλω να συνεχίσω.</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-danger" id="deleteSubscriptionSubmit" disabled><i class="bi bi-trash me-1"></i>Οριστική διαγραφή</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post" enctype="multipart/form-data">
<input type="hidden" name="iris_request_id" value="<?= (int)($irisRequestForPayment['id'] ?? 0) ?>">
<?= csrfField() ?><input type="hidden" name="action" value="record_payment"><div class="modal-header"><h5 class="modal-title">Καταχώρηση ετήσιας συνδρομής</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="mb-3"><label class="form-label">Εθελοντής</label><select class="form-select" name="user_id" id="subscriptionVolunteer" required><option value="">Επιλέξτε…</option><?php foreach ($volunteers as $v): ?><option value="<?= $v['id'] ?>" <?= $preselectedPaymentUserId === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['name']) ?> — <?= h($v['email']) ?></option><?php endforeach; ?></select></div><div class="row g-3"><div class="col-md-6"><label class="form-label">Ημερομηνία πληρωμής</label><input type="text" class="form-control subscription-datepicker" name="payment_date" id="subscriptionPaymentDate" value="<?= h($oldPaymentForm['payment_date'] ?? date('Y-m-d')) ?>" required></div><div class="col-md-6"><label class="form-label">Διάρκεια κάλυψης</label><select class="form-select" name="coverage_years" id="subscriptionCoverageYears"><?php $restoredCoverageYears = (int)($oldPaymentForm['coverage_years'] ?? 1); ?><option value="1" <?= $restoredCoverageYears === 1 ? 'selected' : '' ?>>1 έτος</option><option value="2" <?= $restoredCoverageYears === 2 ? 'selected' : '' ?>>2 έτη</option><option value="3" <?= $restoredCoverageYears === 3 ? 'selected' : '' ?>>3 έτη</option><option value="4" <?= $restoredCoverageYears === 4 ? 'selected' : '' ?>>4 έτη</option><option value="5" <?= $restoredCoverageYears === 5 ? 'selected' : '' ?>>5 έτη</option></select></div><div class="col-12"><label class="form-label">Ημερομηνία λήξης</label><input type="text" class="form-control subscription-datepicker" name="expiry_date" id="subscriptionExpiryDate" value="<?= h($oldPaymentForm['expiry_date'] ?? '') ?>" required><div class="form-text" id="subscriptionExpiryHint">Επιλέξτε εθελοντή για τον αυτόματο υπολογισμό.</div><div class="alert alert-warning mt-2 mb-0 d-none" id="subscriptionExpiryWarning"><i class="bi bi-exclamation-triangle me-1"></i>Η ημερομηνία λήξης άλλαξε από την προτεινόμενη. Ελέγξτε την πριν την αποθήκευση.</div><input type="hidden" name="expiry_override_confirmed" id="subscriptionExpiryOverrideConfirmed" value="0"></div></div><div class="row mt-1"><div class="col"><label class="form-label">Ποσό (€)</label><input type="number" step="0.01" min="0" class="form-control" name="amount" value="<?= h($oldPaymentForm['amount'] ?? '') ?>"></div><div class="col"><label class="form-label">Τρόπος</label><?php $restoredPaymentMethod = $oldPaymentForm['payment_method'] ?? ''; ?><select class="form-select" name="payment_method"><option value="">—</option><?php foreach (['Μετρητά', 'Τραπεζική κατάθεση', 'Κάρτα', 'Άλλο'] as $paymentMethodOption): ?><option <?= $restoredPaymentMethod === $paymentMethodOption ? 'selected' : '' ?>><?= $paymentMethodOption ?></option><?php endforeach; ?></select></div></div><div class="mt-3"><label class="form-label">Αριθμός απόδειξης</label><input type="text" class="form-control" name="receipt_number" maxlength="100" value="<?= h($oldPaymentForm['receipt_number'] ?? '') ?>"></div><div class="mt-3"><label class="form-label">Απόδειξη</label><input type="file" class="form-control" name="receipt" accept=".pdf,.jpg,.jpeg,.png"><div class="form-text">PDF, JPG ή PNG έως 10MB. Οι εικόνες περιορίζονται αυτόματα έως 1920×1080.<?= $oldPaymentForm ? ' Επιλέξτε ξανά το αρχείο απόδειξης — δεν διατηρείται μετά από σφάλμα.' : '' ?></div></div><div class="mt-3"><label class="form-label">Σημειώσεις</label><textarea class="form-control" name="notes" rows="2"><?= h($oldPaymentForm['notes'] ?? '') ?></textarea></div></div>
<div class="px-3 pb-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" value="1" name="force_reactivation" id="forceReactivation" <?= ($oldPaymentForm['force_reactivation'] ?? '') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="forceReactivation">Επανενεργοποίηση συνδρομής</label><div class="form-text">Ξεκινά νέα περίοδο από την ημερομηνία πληρωμής, ανεξάρτητα από την προηγούμενη λήξη.</div></div></div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button><button class="btn btn-primary">Αποθήκευση</button></div></form></div></div></div>
<script>
(() => {
    // Keep Bootstrap modals directly under <body>. This prevents a hidden or
    // stacking parent from leaving only the backdrop visible and blocking the page.
    ['receiptPreviewModal', 'paymentModal', 'deleteSubscriptionModal'].forEach((modalId) => {
        const modalElement = document.getElementById(modalId);
        if (modalElement && modalElement.parentElement !== document.body) {
            document.body.appendChild(modalElement);
        }
    });

    const receiptPreviewModal = document.getElementById('receiptPreviewModal');
    if (receiptPreviewModal) {
        const previewImage = document.getElementById('receiptPreviewImage');
        const previewPdf = document.getElementById('receiptPreviewPdf');
        const previewTitle = document.getElementById('receiptPreviewModalTitle');
        const previewError = document.getElementById('receiptPreviewError');
        receiptPreviewModal.addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            previewTitle.textContent = button.dataset.previewName || 'Προεπισκόπηση απόδειξης';
            previewError.classList.add('d-none');
            const isImage = button.dataset.previewType === 'image';
            previewImage.classList.toggle('d-none', !isImage);
            previewPdf.classList.toggle('d-none', isImage);
            if (isImage) previewImage.src = button.dataset.previewUrl;
            else previewPdf.src = button.dataset.previewUrl;
        });
        previewImage.addEventListener('error', () => previewError.classList.remove('d-none'));
        receiptPreviewModal.addEventListener('hidden.bs.modal', () => {
            previewImage.removeAttribute('src');
            previewPdf.removeAttribute('src');
        });
    }

    const deleteSubscriptionModal = document.getElementById('deleteSubscriptionModal');
    if (deleteSubscriptionModal) {
        const deleteConfirm = document.getElementById('deleteSubscriptionConfirm');
        const deleteSubmit = document.getElementById('deleteSubscriptionSubmit');
        deleteSubscriptionModal.addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            if (!button) return;
            document.getElementById('deleteSubscriptionId').value = button.dataset.subscriptionId || '';
            document.getElementById('deleteSubscriptionVolunteer').textContent = button.dataset.volunteer || '—';
            document.getElementById('deleteSubscriptionPeriod').textContent = button.dataset.period || '—';
            document.getElementById('deleteSubscriptionAmount').textContent = button.dataset.amount || '—';
            deleteConfirm.checked = false;
            deleteSubmit.disabled = true;
        });
        deleteConfirm.addEventListener('change', () => { deleteSubmit.disabled = !deleteConfirm.checked; });
    }

    const historySearch = document.getElementById('subscriptionHistorySearch');
    if (historySearch) {
        historySearch.addEventListener('input', () => {
            const query = historySearch.value.trim().toLocaleLowerCase('el-GR');
            document.querySelectorAll('.subscription-history-row').forEach(row => {
                row.hidden = query !== '' && !row.dataset.historySearch.toLocaleLowerCase('el-GR').includes(query);
            });
        });
    }

    const periodsByVolunteer = <?= json_encode($subscriptionPeriodsByVolunteer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const reactivationDays = <?= (int)$subscriptionReactivationDays ?>;
    const volunteer = document.getElementById('subscriptionVolunteer');
    const payment = document.getElementById('subscriptionPaymentDate');
    const years = document.getElementById('subscriptionCoverageYears');
    const reactivation = document.getElementById('forceReactivation');
    const expiry = document.getElementById('subscriptionExpiryDate');
    if (volunteer) {
        const volunteerSearch = document.createElement('input');
        volunteerSearch.type = 'search';
        volunteerSearch.id = 'subscriptionVolunteerSearch';
        volunteerSearch.className = 'form-control mb-2';
        volunteerSearch.placeholder = 'Αναζήτηση με όνομα ή επώνυμο…';
        volunteerSearch.autocomplete = 'off';
        volunteer.parentElement.insertBefore(volunteerSearch, volunteer);
        volunteerSearch.addEventListener('input', () => {
            const query = volunteerSearch.value.trim().toLocaleLowerCase('el-GR');
            Array.from(volunteer.options).forEach(option => {
                option.hidden = option.value !== '' && query !== '' && !option.text.toLocaleLowerCase('el-GR').includes(query);
            });
        });
        volunteerSearch.addEventListener('keydown', event => {
            if (event.key !== 'Enter') return;
            const match = Array.from(volunteer.options).find(option => option.value && !option.hidden);
            if (!match) return;
            event.preventDefault();
            volunteer.value = match.value;
            volunteer.dispatchEvent(new Event('change'));
        });
    }
    const hint = document.getElementById('subscriptionExpiryHint');
    const warning = document.getElementById('subscriptionExpiryWarning');
    const confirmed = document.getElementById('subscriptionExpiryOverrideConfirmed');
    const form = expiry.closest('form');
    const setDateValue = (field, value) => {
        if (field._flatpickr) {
            field._flatpickr.setDate(value, false, 'Y-m-d');
        } else {
            field.value = value;
        }
    };

    const toDate = (value) => value && /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(value + 'T12:00:00') : null;
    const isoDate = (date) => date.toISOString().slice(0, 10);
    const addYears = (value, count) => {
        const date = toDate(value);
        if (!date) return '';
        date.setFullYear(date.getFullYear() + Number(count));
        return isoDate(date);
    };
    const addYearsMinusDay = (value, count) => {
        const date = toDate(addYears(value, count));
        if (!date) return '';
        date.setDate(date.getDate() - 1);
        return isoDate(date);
    };
    const formatDate = (value) => {
        const date = toDate(value);
        return date ? date.toLocaleDateString('el-GR') : value;
    };
    const refreshExpiry = () => {
        const paidOn = payment.value;
        if (!toDate(paidOn)) return;
        const periods = periodsByVolunteer[volunteer.value] || [];
        const previousPeriod = periods
            .filter(period => period.payment_date < paidOn)
            .sort((left, right) => right.expiry_date.localeCompare(left.expiry_date))[0] || null;
        const nextPeriod = periods
            .filter(period => period.payment_date > paidOn)
            .sort((left, right) => left.payment_date.localeCompare(right.payment_date))[0] || null;
        const previousExpiry = previousPeriod?.expiry_date || '';
        const expiredDays = previousExpiry ? Math.floor((toDate(paidOn) - toDate(previousExpiry)) / 86400000) : 0;
        const isReactivation = reactivation.checked || (previousExpiry && expiredDays > reactivationDays);
        const expectedExpiry = isReactivation || !previousExpiry
            ? addYearsMinusDay(paidOn, years.value)
            : addYears(previousExpiry, years.value);
        setDateValue(expiry, expectedExpiry);
        expiry.dataset.expectedExpiry = expectedExpiry;
        confirmed.value = '0';
        warning.classList.add('d-none');
        hint.textContent = nextPeriod
            ? `Παλιά περίοδος πριν από τη συνδρομή που ξεκινά ${formatDate(nextPeriod.payment_date)}. Η λήξη υπολογίστηκε συμπεριληπτικά.`
            : (isReactivation || !previousExpiry
                ? `Νέα περίοδος από ${formatDate(paidOn)} για ${years.value} έτη (συμπεριληπτικές ημερομηνίες).`
                : `Συνεχής ανανέωση από την προηγούμενη λήξη ${formatDate(previousExpiry)} για ${years.value} έτη.`);
    };
    const checkManualExpiry = () => {
        const changed = expiry.value && expiry.dataset.expectedExpiry && expiry.value !== expiry.dataset.expectedExpiry;
        warning.classList.toggle('d-none', !changed);
        confirmed.value = '0';
    };
    [volunteer, payment, years, reactivation].forEach((field) => field.addEventListener('change', refreshExpiry));
    expiry.addEventListener('input', checkManualExpiry);
    form.addEventListener('submit', (event) => {
        if (expiry.value !== expiry.dataset.expectedExpiry && confirmed.value !== '1') {
            const proceed = window.confirm(`Η λήξη άλλαξε από ${formatDate(expiry.dataset.expectedExpiry)} σε ${formatDate(expiry.value)}. Θέλετε να αποθηκευτεί η χειροκίνητη ημερομηνία;`);
            if (!proceed) {
                event.preventDefault();
                return;
            }
            confirmed.value = '1';
        }
    });
    const addCameraInput = (regularInput, formId, target, cameraId) => {
        if (!target || document.getElementById(cameraId)) return;
        const cameraInput = document.createElement('input');
        cameraInput.type = 'file';
        cameraInput.name = 'receipt_camera';
        cameraInput.id = cameraId;
        cameraInput.accept = 'image/jpeg,image/png';
        cameraInput.setAttribute('capture', 'environment');
        cameraInput.className = 'd-none';
        if (formId) cameraInput.setAttribute('form', formId);

        const cameraButton = document.createElement('label');
        cameraButton.className = 'btn btn-outline-primary btn-sm mt-2';
        cameraButton.htmlFor = cameraId;
        cameraButton.innerHTML = '<i class="bi bi-camera-fill me-1"></i>Λήψη με κάμερα';
        const hint = document.createElement('div');
        hint.className = 'form-text';
        hint.textContent = 'Σε κινητό ανοίγει την πίσω κάμερα για φωτογράφιση της απόδειξης.';
        target.append(cameraInput, cameraButton, hint);

        cameraInput.addEventListener('change', () => {
            if (cameraInput.files.length) regularInput.value = '';
        });
        regularInput.addEventListener('change', () => {
            if (regularInput.files.length) cameraInput.value = '';
        });
    };
    const newReceiptInput = document.querySelector('#paymentModal input[name="receipt"]');
    const subscriptionPaymentMethod = document.querySelector('#paymentModal select[name="payment_method"]');
    if (subscriptionPaymentMethod && !Array.from(subscriptionPaymentMethod.options).some(option => option.value === 'IRIS')) {
        const irisOption = document.createElement('option');
        irisOption.value = 'IRIS';
        irisOption.textContent = 'IRIS';
        subscriptionPaymentMethod.append(irisOption);
    }
    <?php if ($irisRequestForPayment): ?>
    document.addEventListener('DOMContentLoaded', () => {
        const irisVolunteer = document.getElementById('subscriptionVolunteer');
        const irisYears = document.getElementById('subscriptionCoverageYears');
        const irisAmount = document.querySelector('#paymentModal input[name="amount"]');
        const irisMethod = document.querySelector('#paymentModal select[name="payment_method"]');
        if (irisVolunteer) { irisVolunteer.value = '<?= (int)$irisRequestForPayment['user_id'] ?>'; irisVolunteer.dispatchEvent(new Event('change')); }
        if (irisYears) { irisYears.value = '<?= (int)$irisRequestForPayment['coverage_years'] ?>'; irisYears.dispatchEvent(new Event('change')); }
        if (irisAmount) irisAmount.value = '<?= h(number_format((float)$irisRequestForPayment['total_amount'], 2, '.', '')) ?>';
        if (irisMethod) irisMethod.value = 'IRIS';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('paymentModal')).show();
    });
    <?php endif; ?>
    <?php if ($openPaymentModal && !$irisRequestForPayment): ?>
    document.addEventListener('DOMContentLoaded', () => {
        const selectedVolunteer = document.getElementById('subscriptionVolunteer');
        if (selectedVolunteer) selectedVolunteer.dispatchEvent(new Event('change'));
        bootstrap.Modal.getOrCreateInstance(document.getElementById('paymentModal')).show();
    });
    <?php endif; ?>
    <?php if ($oldPaymentForm && !$irisRequestForPayment): ?>
    document.addEventListener('DOMContentLoaded', () => {
        refreshExpiry();
        <?php if (!empty($oldPaymentForm['expiry_date'])): ?>
        setDateValue(expiry, <?= json_encode((string)$oldPaymentForm['expiry_date']) ?>);
        checkManualExpiry();
        <?php endif; ?>
        bootstrap.Modal.getOrCreateInstance(document.getElementById('paymentModal')).show();
    });
    <?php endif; ?>
    <?php if ($editing): ?>
    const editForm = document.getElementById('editSubscriptionForm');
    if (editForm) {
        const editUserId = <?= (int)$editing['user_id'] ?>;
        const editSubscriptionId = <?= (int)$editing['id'] ?>;
        const editYears = <?= max(1, (int)($editing['coverage_years'] ?? 1)) ?>;
        const editPayment = editForm.querySelector('[name="payment_date"]');
        const editExpiry = editForm.querySelector('[name="expiry_date"]');
        const editHint = document.getElementById('editExpiryHint');
        const refreshEditHint = () => {
            const paidOn = editPayment.value;
            if (!toDate(paidOn)) { editHint.textContent = ''; return; }
            const periods = (periodsByVolunteer[editUserId] || []).filter(period => period.id !== editSubscriptionId);
            const previousPeriod = periods
                .filter(period => period.payment_date < paidOn)
                .sort((left, right) => right.expiry_date.localeCompare(left.expiry_date))[0] || null;
            const nextPeriod = periods
                .filter(period => period.payment_date > paidOn)
                .sort((left, right) => left.payment_date.localeCompare(right.payment_date))[0] || null;
            const previousExpiry = previousPeriod?.expiry_date || '';
            const expiredDays = previousExpiry ? Math.floor((toDate(paidOn) - toDate(previousExpiry)) / 86400000) : 0;
            const suggested = (!previousExpiry || expiredDays > reactivationDays)
                ? addYearsMinusDay(paidOn, editYears)
                : addYears(previousExpiry, editYears);
            const problems = [];
            if (editExpiry.value && editExpiry.value < paidOn) problems.push('η λήξη είναι πριν από την πληρωμή');
            if (nextPeriod && editExpiry.value && editExpiry.value >= nextPeriod.payment_date) problems.push(`επικάλυψη με την επόμενη περίοδο που ξεκινά ${formatDate(nextPeriod.payment_date)}`);
            editHint.innerHTML = `Προτεινόμενη λήξη για ${editYears} έτη: <strong>${formatDate(suggested)}</strong>`
                + (problems.length ? ` — <span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>${problems.join(' · ')}</span>` : '');
        };
        [editPayment, editExpiry].forEach((field) => {
            field.addEventListener('change', refreshEditHint);
            field.addEventListener('input', refreshEditHint);
        });
        refreshEditHint();
    }
    <?php endif; ?>
    if (newReceiptInput) addCameraInput(newReceiptInput, null, newReceiptInput.parentElement, 'subscriptionCameraNew');

    const editReceiptInput = document.querySelector('#editSubscriptionForm input[name="receipt"]');
    if (editReceiptInput) addCameraInput(editReceiptInput, null, document.getElementById('editReceiptUpload'), 'subscriptionCameraEdit');
    refreshExpiry();
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.subscription-datepicker').forEach((field) => {
            flatpickr(field, {
                dateFormat: 'Y-m-d',
                altInput: true,
                altFormat: <?= json_encode($subscriptionDateFormat) ?>,
                allowInput: true,
                disableMobile: true,
                onValueUpdate: () => {
                    field.dispatchEvent(new Event('input', {bubbles: true}));
                    field.dispatchEvent(new Event('change', {bubbles: true}));
                }
            });
        });
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
