<?php
/**
 * Helpers for member-reported IRIS subscription payments.
 * These are payment notices only: an administrator still records the actual payment.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

function subscriptionIrisRenewalDays(): int {
    return max(0, min(3650, (int)getSetting('subscription_iris_renewal_days', 90)));
}

function subscriptionIrisAnnualAmount(): float {
    return max(0, (float)str_replace(',', '.', getSetting('subscription_iris_annual_amount', '30')));
}

function subscriptionIrisTaxId(): string {
    return trim(getSetting('subscription_iris_tax_id', '996695642'));
}

function subscriptionIrisIsEligible(?array $subscription): bool {
    if (!$subscription || empty($subscription['expiry_date'])) return false;
    $daysUntilExpiry = (int)floor((strtotime($subscription['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
    return $daysUntilExpiry <= subscriptionIrisRenewalDays();
}

function subscriptionIrisLatestRequest(int $userId): ?array {
    return dbFetchOne("SELECT * FROM subscription_iris_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$userId]) ?: null;
}

function subscriptionIrisPrepare(int $userId, array $subscription, int $coverageYears): array {
    if (!subscriptionIrisIsEligible($subscription)) {
        throw new RuntimeException('Η ανανέωση με IRIS δεν είναι ακόμη διαθέσιμη.');
    }

    $coverageYears = max(1, min(5, $coverageYears));
    $annualAmount = subscriptionIrisAnnualAmount();
    if ($annualAmount <= 0 || subscriptionIrisTaxId() === '') {
        throw new RuntimeException('Οι ρυθμίσεις IRIS δεν είναι πλήρεις. Επικοινωνήστε με τη διοίκηση.');
    }
    $totalAmount = round($annualAmount * $coverageYears, 2);
    $latest = subscriptionIrisLatestRequest($userId);

    if ($latest && $latest['status'] === 'PREPARED') {
        dbExecute("UPDATE subscription_iris_requests SET subscription_id = ?, coverage_years = ?, annual_amount = ?, total_amount = ?, updated_at = NOW() WHERE id = ?", [
            (int)$subscription['id'], $coverageYears, $annualAmount, $totalAmount, (int)$latest['id']
        ]);
        return dbFetchOne("SELECT * FROM subscription_iris_requests WHERE id = ?", [(int)$latest['id']]);
    }

    $id = dbInsert("INSERT INTO subscription_iris_requests
        (user_id, subscription_id, coverage_years, annual_amount, total_amount, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 'PREPARED', NOW(), NOW())", [
        $userId, (int)$subscription['id'], $coverageYears, $annualAmount, $totalAmount
    ]);
    return dbFetchOne("SELECT * FROM subscription_iris_requests WHERE id = ?", [$id]);
}

function subscriptionIrisReportPayment(int $userId, array $subscription): array {
    if (!subscriptionIrisIsEligible($subscription)) {
        throw new RuntimeException('Η ενημέρωση πληρωμής IRIS δεν είναι ακόμη διαθέσιμη.');
    }
    $latest = subscriptionIrisLatestRequest($userId);
    if (!$latest || $latest['status'] !== 'PREPARED' || (int)$latest['subscription_id'] !== (int)$subscription['id']) {
        throw new RuntimeException('Επιλέξτε πρώτα τη διάρκεια ανανέωσης.');
    }
    dbExecute("UPDATE subscription_iris_requests SET status = 'REPORTED', payment_reported_at = NOW(), updated_at = NOW() WHERE id = ?", [(int)$latest['id']]);
    return dbFetchOne("SELECT * FROM subscription_iris_requests WHERE id = ?", [(int)$latest['id']]);
}

function subscriptionIrisMarkSeen(int $requestId, int $adminId): ?array {
    $request = dbFetchOne("SELECT sir.*, u.name AS volunteer_name, u.email AS volunteer_email
        FROM subscription_iris_requests sir JOIN users u ON u.id = sir.user_id WHERE sir.id = ?", [$requestId]);
    if (!$request || $request['status'] !== 'REPORTED') return null;

    dbExecute("UPDATE subscription_iris_requests SET status = 'SEEN', seen_at = NOW(), seen_by = ?, updated_at = NOW() WHERE id = ?", [$adminId, $requestId]);
    $title = 'Το αίτημα πληρωμής IRIS ελήφθη';
    $message = 'Η διοίκηση έλαβε γνώση του αιτήματος πληρωμής IRIS για τη συνδρομή σας. Η ενεργοποίηση θα γίνει μετά την επιβεβαίωση της πληρωμής.';
    sendNotification((int)$request['user_id'], $title, $message, 'info', 'subscription_iris_request_seen');
    if (!empty($request['volunteer_email'])) {
        sendNotificationEmail('subscription_iris_request_seen', $request['volunteer_email'], [
            'user_name' => $request['volunteer_name'],
            'volunteer_name' => $request['volunteer_name'],
            'coverage_years' => (int)$request['coverage_years'],
            'total_amount' => number_format((float)$request['total_amount'], 2, ',', '.') . ' €',
        ]);
    }
    return $request;
}

function subscriptionIrisCompleteLatestRequest(int $userId): void {
    dbExecute("UPDATE subscription_iris_requests
        SET status = 'COMPLETED', completed_at = NOW(), updated_at = NOW()
        WHERE user_id = ? AND status IN ('REPORTED', 'SEEN')
        ORDER BY id DESC LIMIT 1", [$userId]);
}
