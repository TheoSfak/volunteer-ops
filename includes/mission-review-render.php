<?php
/**
 * VolunteerOps - the incidents and shortage reports of a finished mission, as
 * HTML cards (Bootstrap), for the two screens that look back at a mission:
 * mission-archive.php (command staff, unmasked) and mission-debrief.php (masked).
 * The PDF report draws the same data in its own print markup
 * (mission-report-print.php); both read loadMissionReviewData().
 *
 * Read-only on purpose: nothing here posts, links to a write endpoint or
 * offers a button that changes a record.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/** Bootstrap contextual colour for a severity code. */
function reviewSeverityBadgeClass(string $severity): string {
    return ['critical' => 'danger', 'high' => 'warning', 'medium' => 'info', 'low' => 'secondary'][$severity] ?? 'secondary';
}

/** "12 λεπ." / "1.5 λεπ.", or null when there is nothing to say. */
function reviewMinutes(?float $minutes, string $lang): ?string {
    return $minutes === null ? null : t('review.minutes', ['n' => rtrim(rtrim(number_format($minutes, 1, '.', ''), '0'), '.')], $lang);
}

/**
 * @param array{incidents: array, shortages: array} $data from loadMissionReviewData()
 * @return array{incidents: string, shortages: string} one HTML card each, so a
 *         page can lay them side by side or stacked
 */
function renderMissionReviewCards(array $data, string $lang = 'el'): array {
    $tr = fn(string $key, array $vars = []) => t($key, $vars, $lang);
    $out = '';

    // ── Incidents ───────────────────────────────────────────────────────────
    $out .= '<div class="ar-card"><h2><i class="bi bi-heart-pulse me-1"></i>' . h($tr('review.incidents_title'))
        . ' <span class="badge bg-secondary">' . count($data['incidents']) . '</span></h2>';
    if (!$data['incidents']) {
        $out .= '<p class="text-muted small mb-0">' . h($tr('review.incidents_none')) . '</p>';
    }
    foreach ($data['incidents'] as $i) {
        $patient = [];
        if ($i['patient'] !== null) $patient[] = h($i['patient']);
        $ageGender = trim(($i['estimated_age'] ?? '') . ' ' . ($i['gender_label'] ?? ''));
        if ($ageGender !== '') $patient[] = h($ageGender);
        if ($i['phone'] !== null) $patient[] = h($i['phone']);

        $out .= '<div class="border rounded p-2 mb-2">';
        $out .= '<div class="d-flex flex-wrap gap-2 align-items-center">'
            . '<span class="badge bg-' . reviewSeverityBadgeClass($i['severity']) . '">' . h($i['severity_label']) . '</span>'
            . '<strong>' . h($i['type_label']) . '</strong>'
            . '<span class="text-muted small">' . h($i['team_label']) . ' — ' . h($i['reporter_name']) . '</span></div>';
        if ($patient) {
            $out .= '<div class="small mt-1"><i class="bi bi-person me-1"></i>' . h($tr('review.patient')) . ': ' . implode(' · ', $patient) . '</div>';
        }
        if ($i['notes'] !== null) {
            $out .= '<div class="small mt-1"><i class="bi bi-journal-text me-1"></i>' . h($tr('review.notes')) . ': ' . nl2br(h($i['notes'])) . '</div>';
        }
        if ($i['lat'] !== null && $i['lng'] !== null) {
            $coords = number_format($i['lat'], 5, '.', '') . ', ' . number_format($i['lng'], 5, '.', '');
            $out .= '<div class="small mt-1"><i class="bi bi-geo-alt me-1"></i>' . h($tr('review.location')) . ': '
                . '<a href="https://www.google.com/maps?q=' . rawurlencode($i['lat'] . ',' . $i['lng']) . '" target="_blank" rel="noopener">' . h($coords) . '</a>'
                . ($i['accuracy_m'] !== null ? ' (±' . (int) $i['accuracy_m'] . ' m)' : '') . '</div>';
        }
        $times = [h($tr('review.reported_at')) . ' ' . h($i['created_at'])];
        $times[] = h($tr('review.seen_at')) . ' ' . ($i['acknowledged_at'] ? h($i['acknowledged_at']) . ($i['seen_minutes'] !== null ? ' (' . h(reviewMinutes($i['seen_minutes'], $lang)) . ')' : '') : '—');
        $outcome = $i['outcome_label'] ? h($i['outcome_label']) . ($i['outcome_location'] ? ' (' . h($i['outcome_location']) . ')' : '') : '—';
        $times[] = h($tr('review.outcome')) . ' ' . $outcome . ($i['resolved_at'] ? ' — ' . h($i['resolved_at']) . ($i['resolved_minutes'] !== null ? ' (' . h(reviewMinutes($i['resolved_minutes'], $lang)) . ')' : '') : '');
        $out .= '<div class="small text-muted mt-1">' . implode(' · ', $times) . '</div>';
        foreach ($i['responders'] as $r) {
            $line = $r['declined']
                ? h($tr('review.responder_declined'))
                : h($tr('review.responder_sent', ['time' => $r['sent']]))
                    . ($r['arrived'] ? ' · ' . h($tr('review.responder_arrived', ['time' => $r['arrived']]))
                        . ($r['arrive_minutes'] !== null ? ' (' . h($tr('review.responder_after', ['n' => (int) $r['arrive_minutes']])) . ')' : '') : '')
                    . ($r['completed'] ? ' · ' . h($tr('review.responder_completed', ['time' => $r['completed']])) : '');
            $out .= '<div class="small text-muted"><i class="bi bi-truck me-1"></i><strong>' . h($r['label']) . '</strong>: ' . $line . '</div>';
        }
        $out .= '</div>';
    }
    $out .= '</div>';
    $incidentsHtml = $out;
    $out = '';

    // ── Shortage reports ────────────────────────────────────────────────────
    $statusClass = ['resolved' => 'success', 'not_resolved' => 'danger', 'seen' => 'warning', 'open' => 'secondary'];
    $out .= '<div class="ar-card"><h2><i class="bi bi-exclamation-triangle me-1"></i>' . h($tr('review.shortages_title'))
        . ' <span class="badge bg-secondary">' . count($data['shortages']) . '</span></h2>';
    if (!$data['shortages']) {
        $out .= '<p class="text-muted small mb-0">' . h($tr('review.shortages_none')) . '</p>';
    }
    foreach ($data['shortages'] as $s) {
        $out .= '<div class="border rounded p-2 mb-2">';
        $out .= '<div class="d-flex flex-wrap gap-2 align-items-center">'
            . '<span class="badge bg-' . reviewSeverityBadgeClass($s['severity']) . '">' . h($s['severity_label']) . '</span>'
            . '<strong>' . h($s['title']) . '</strong>'
            . '<span class="badge bg-' . $statusClass[$s['status']] . '">' . h($tr('review.status_' . $s['status'])) . '</span></div>';
        $out .= '<div class="small text-muted">' . h($s['type_label']) . ' · ' . h($s['team_label']) . ' — ' . h($s['reporter_name']) . '</div>';
        if (trim((string) $s['description']) !== '') {
            $out .= '<div class="small mt-1">' . nl2br(h($s['description'])) . '</div>';
        }
        $times = [h($tr('review.reported_at')) . ' ' . h($s['created_at'])];
        $times[] = h($tr('review.seen_at')) . ' ' . ($s['acknowledged_at'] ? h($s['acknowledged_at']) . ($s['seen_minutes'] !== null ? ' (' . h(reviewMinutes($s['seen_minutes'], $lang)) . ')' : '') : '—');
        $closedAt = $s['resolved_at'] ?: $s['not_resolved_at'];
        if ($closedAt) {
            $times[] = h($tr($s['resolved_at'] ? 'review.resolved_at' : 'review.not_resolved_at')) . ' ' . h($closedAt)
                . ($s['resolved_minutes'] !== null ? ' (' . h(reviewMinutes($s['resolved_minutes'], $lang)) . ')' : '');
        }
        $out .= '<div class="small text-muted mt-1">' . implode(' · ', $times) . '</div>';
        if ($s['outcome_note'] !== null) {
            $out .= '<div class="small mt-1"><i class="bi bi-chat-left-text me-1"></i>' . h($tr('review.resolution_note')) . ': ' . nl2br(h($s['outcome_note'])) . '</div>';
        }
        $out .= '</div>';
    }
    $out .= '</div>';

    return ['incidents' => $incidentsHtml, 'shortages' => $out];
}
