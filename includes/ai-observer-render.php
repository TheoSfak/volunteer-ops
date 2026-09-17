<?php
/**
 * VolunteerOps — shared rendering for the AI observer assessment.
 *
 * One renderer for both mission-stats.php (screen) and
 * mission-report-print.php (PDF/print), for the same reason
 * computeMissionOrderTypeBreakdown() was extracted: two inline copies of the
 * same markup drift, and a reader comparing the screen to the printed report
 * must not find two different assessments.
 *
 * Evidence refs are deliberately NOT printed. They exist so the server can
 * delete unfounded claims before anyone reads them (see aiObserverValidate());
 * showing TEAM-1 / PILLAR-response on the page would read as debug output in
 * a document meant for an oversight authority.
 */

require_once __DIR__ . '/ai-observer.php';

/**
 * Base styles, shared by both pages so the block looks the same on screen and
 * on paper. Colours come from the same tier palette the rest of the report
 * uses.
 *
 * The block is styled to read as an ASSESSMENT, not as an alarm: no red
 * banner, no siren iconography. A model's opinion must never take the visual
 * weight of a real SOS.
 */
function aiObserverStyles(): string {
    return <<<'CSS'
.aio-wrap { border:1px solid #d9d5cc; border-left:4px solid #5b6bbf; border-radius:6px; padding:14px 16px; background:#fbfbfa; }
.aio-head { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
.aio-title { font-weight:700; font-size:1rem; margin:0; }
.aio-tag { font-size:.68rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:#5b6bbf; border:1px solid #c4cae6; background:#eef0f9; border-radius:10px; padding:1px 7px; }
.aio-verdict { font-size:1.02rem; font-weight:600; line-height:1.45; margin:0 0 10px; color:#2c2a26; }
.aio-wrap p { margin:0 0 9px; line-height:1.6; }
.aio-wrap p:last-child { margin-bottom:0; }
.aio-h { font-weight:700; font-size:.82rem; letter-spacing:.03em; text-transform:uppercase; color:#6b665c; margin:14px 0 7px; }
.aio-item { border-left:3px solid var(--aio-c,#6c757d); padding:2px 0 2px 10px; margin-bottom:9px; }
.aio-item:last-child { margin-bottom:0; }
.aio-sev { display:inline-block; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:var(--aio-c,#6c757d); margin-right:6px; }
.aio-item strong { font-weight:600; }
.aio-item div { line-height:1.55; }
.aio-gaps { margin-top:14px; font-size:.86rem; color:#6b665c; }
.aio-meta { margin-top:14px; padding-top:9px; border-top:1px dashed #d9d5cc; font-size:.76rem; color:#7a746a; line-height:1.5; }
CSS;
}

/**
 * One assessment section (πεδίο/αποστολή, or συντονιστικό) as HTML.
 * Returns '' when the section carries nothing, so the caller can decide
 * whether the surrounding card is worth drawing at all.
 */
function renderAiObserverSection(array $section, string $heading, string $lang = 'el'): string {
    if (!aiObserverSectionHasContent($section)) {
        return '';
    }
    $w = fn(string $el, string $en) => $lang === 'en' ? $en : $el;

    $out  = '<div class="aio-wrap">';
    $out .= '<div class="aio-head"><h6 class="aio-title">' . h($heading) . '</h6>'
          . '<span class="aio-tag">' . h($w('Ανάλυση AI', 'AI analysis')) . '</span></div>';

    if ($section['verdict'] !== null) {
        $out .= '<div class="aio-verdict">' . h($section['verdict']) . '</div>';
    }
    foreach ($section['analysis'] as $paragraph) {
        $out .= '<p>' . h($paragraph) . '</p>';
    }

    if (!empty($section['findings'])) {
        $out .= '<div class="aio-h">' . h($w('Ευρήματα', 'Findings')) . '</div>';
        foreach ($section['findings'] as $f) {
            [$label, $colour] = aiObserverSeverityMeta($f['severity'], $lang);
            $out .= '<div class="aio-item" style="--aio-c:' . h($colour) . ';">'
                  . '<span class="aio-sev">' . h($label) . '</span>';
            if ($f['title'] !== '') {
                $out .= '<strong>' . h($f['title']) . '</strong>';
            }
            $out .= '<div>' . h($f['text']) . '</div></div>';
        }
    }

    if (!empty($section['recommendations'])) {
        $out .= '<div class="aio-h">' . h($w('Συστάσεις', 'Recommendations')) . '</div>';
        foreach ($section['recommendations'] as $r) {
            [$label, $colour] = aiObserverPriorityMeta($r['priority'], $lang);
            $out .= '<div class="aio-item" style="--aio-c:' . h($colour) . ';">'
                  . '<span class="aio-sev">' . h($label) . '</span>'
                  . '<div>' . h($r['text']) . '</div></div>';
        }
    }

    $out .= '</div>';
    return $out;
}

/**
 * The stamp printed once under the assessment: which model, when.
 *
 * Deliberately two facts and nothing else. This used to be a four-sentence
 * declaration — who requested it, that the numbers come from the application,
 * that the model interprets rather than scores, that none of it affects the
 * mission score. Every one of those was true and none of them earned the
 * space: the "Ανάλυση AI" badge beside the heading already marks the block as
 * machine-written, and a disclaimer nobody reads twice is just weight on the
 * page. Model and timestamp stay because without them a reader cannot tell a
 * fresh assessment from last season's, which is the one thing that decides
 * whether to regenerate.
 *
 * The data-gaps list is NOT boilerplate and stays above it — it is the model
 * naming what it could not judge, which is content.
 */
function renderAiObserverMeta(array $assessment, ?string $lang = null): string {
    // A stored assessment knows its own language; the parameter exists for
    // callers that render a section in a language the row does not carry.
    $lang = $lang ?? ($assessment['lang'] ?? 'el');
    $w = fn(string $el, string $en) => $lang === 'en' ? $en : $el;

    $bits = [h($assessment['model'])];
    if (!empty($assessment['generated_at'])) {
        $bits[] = h(formatDateTime($assessment['generated_at']));
    }
    $out = '<div class="aio-meta">' . implode(' · ', $bits);

    // Both of these are exceptions, not decoration: they appear only when
    // something is actually wrong with what the reader is looking at.
    if (!empty($assessment['dropped_claims'])) {
        $n = (int) $assessment['dropped_claims'];
        $out .= ' · ' . $n . ($lang === 'en'
            ? ($n === 1 ? ' claim removed as unevidenced' : ' claims removed as unevidenced')
            : (($n === 1 ? ' ισχυρισμός αφαιρέθηκε' : ' ισχυρισμοί αφαιρέθηκαν') . ' ως ατεκμηρίωτ' . ($n === 1 ? 'ος' : 'οι')));
    }
    if (!empty($assessment['is_stale'])) {
        $out .= ' · ' . $w('παλαιότερη έκδοση οδηγιών — αξίζει επαναδημιουργία', 'older prompt version — worth regenerating');
    }
    $out .= '</div>';

    if (!empty($assessment['payload']['data_gaps'])) {
        $gaps = '<div class="aio-gaps"><strong>' . h($w('Κενά τεκμηρίωσης:', 'Evidence gaps:')) . '</strong><ul style="margin:4px 0 0; padding-left:18px;">';
        foreach ($assessment['payload']['data_gaps'] as $g) {
            $gaps .= '<li>' . h($g) . '</li>';
        }
        $gaps .= '</ul></div>';
        $out = $gaps . $out;
    }

    return $out;
}
