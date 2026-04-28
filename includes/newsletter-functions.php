<?php
/**
 * VolunteerOps - Newsletter Helper Functions
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Build the recipient user list or count for a newsletter campaign.
 *
 * @param array  $roles   Array of role constants. Empty = all roles.
 * @param int    $deptId  Department ID filter, 0 = all departments.
 * @param bool   $countOnly  If true, returns [count, []] instead of [count, $users]
 * @return array [int $count, array $users]
 */
function buildRecipientQuery(array $roles, int $deptId, bool $countOnly = false): array {
    // Empty roles = no recipients (manual-only mode)
    if (empty($roles) && $deptId <= 0) {
        return [0, []];
    }

    $params = [];
    $where  = ['u.is_active = 1', 'u.deleted_at IS NULL', 'u.email IS NOT NULL', "u.email != ''", 'u.newsletter_unsubscribed = 0'];

    if (!empty($roles)) {
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $where[]      = "u.role IN ({$placeholders})";
        foreach ($roles as $r) $params[] = $r;
    }

    if ($deptId > 0) {
        $where[]  = 'u.department_id = ?';
        $params[] = $deptId;
    }

    $whereClause = implode(' AND ', $where);

    if ($countOnly) {
        $count = (int)dbFetchValue("SELECT COUNT(*) FROM users u WHERE {$whereClause}", $params);
        return [$count, []];
    }

    $users = dbFetchAll("
        SELECT u.id, u.name, u.email, u.role, u.total_points, u.department_id,
               d.name AS dept_name
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE {$whereClause}
        ORDER BY u.name ASC
    ", $params);

    return [count($users), $users];
}

/**
 * Replace newsletter dynamic tags in a string for a specific recipient user.
 *
 * Tags: {name}, {email}, {role}, {department}, {points}, {unsubscribe_link}
 *
 * @param string $text   Subject or body_html with tags
 * @param array  $user   User row from DB (with dept_name)
 * @param string $unsubscribeToken  Unique token for this send
 * @return string
 */
function replaceNewsletterTags(string $text, array $user, string $unsubscribeToken): string {
    $roleLabels = [
        ROLE_SYSTEM_ADMIN      => 'Διαχειριστής Συστήματος',
        ROLE_DEPARTMENT_ADMIN  => 'Διαχειριστής Τμήματος',
        ROLE_SHIFT_LEADER      => 'Αρχηγός Βάρδιας',
        ROLE_VOLUNTEER         => 'Εθελοντής',
    ];

    $baseUrl       = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $unsubscribeUrl = $baseUrl . '/newsletter-unsubscribe.php?token=' . urlencode($unsubscribeToken);
    $unsubscribeLink = '<a href="' . $unsubscribeUrl . '" style="color:#888;font-size:12px;">Διαγραφή από λίστα αλληλογραφίας</a>';

    $replacements = [
        '{name}'             => h($user['name'] ?? ''),
        '{email}'            => h($user['email'] ?? ''),
        '{role}'             => h($roleLabels[$user['role'] ?? ''] ?? ($user['role'] ?? '')),
        '{department}'       => h($user['dept_name'] ?? 'Χωρίς τμήμα'),
        '{points}'           => (string)(int)($user['total_points'] ?? 0),
        '{unsubscribe_link}' => $unsubscribeLink,
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $text);
}

/**
 * Return an email-safe logo image for newsletter templates.
 *
 * Email clients cannot resolve relative image paths like uploads/logos/logo.png,
 * so newsletters must use an absolute URL.
 */
function getNewsletterLogoHtml(): string {
    $appLogo = trim((string)getSetting('app_logo', ''));
    if ($appLogo === '') {
        return '';
    }

    $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    if ($baseUrl === '') {
        return '';
    }

    $logoFile = rawurlencode(basename($appLogo));
    $logoUrl = $baseUrl . '/uploads/logos/' . $logoFile;
    $alt = defined('APP_NAME') ? APP_NAME : 'VolunteerOps';

    return '<img src="' . h($logoUrl) . '" alt="' . h($alt) . '" width="48" '
        . 'style="display:block;max-width:48px;max-height:48px;border:0;outline:none;text-decoration:none;">';
}

/**
 * Wrap newsletter content in the selected visual email template.
 *
 * This is intentionally shared by preview, test-send, real-send and resend so
 * the UI shows the same HTML that recipients receive.
 */
function wrapNewsletterBody(string $body, string $title, ?int $templateId = null): string {
    $fromName = getSetting('smtp_from_name', 'VolunteerOps');

    $tpl = null;
    if ($templateId) {
        $tpl = dbFetchOne("SELECT body_html FROM newsletter_templates WHERE id = ?", [$templateId]);
    }
    if (!$tpl) {
        $tpl = dbFetchOne("SELECT body_html FROM newsletter_templates WHERE is_default = 1 LIMIT 1");
    }

    $templateBody = $tpl ? (string)$tpl['body_html'] : '{content}';
    if (strpos($templateBody, '{content}') === false) {
        $templateBody .= '{content}';
    }

    $replacements = [
        '{from_name}' => h($fromName),
        '{title}'     => h($title),
        '{logo_url}'  => getNewsletterLogoHtml(),
        '{content}'   => $body,
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $templateBody);
}

/**
 * Generate a cryptographically secure unsubscribe token.
 */
function generateUnsubscribeToken(): string {
    return bin2hex(random_bytes(32));
}
