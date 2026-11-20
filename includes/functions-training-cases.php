<?php
/**
 * VolunteerOps - Training cases (v3.505.0).
 *
 * A case is a short case study at a place: a story, some findings, and a branching
 * sequence of multiple-choice decisions, each with a rating and feedback. It is
 * stored as a JSON document (format_version 1) in training_cases; staging it in a
 * scenario takes a SNAPSHOT of the document, so a later library edit never changes
 * a prepared or running drill. See docs/superpowers/specs/2026-11-20-training-cases-design.md.
 *
 * This file: the document rules (normalise, validate, score). Runs and answers
 * are in their own functions, added by later tasks.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

const TRAINING_CASE_FORMAT_VERSION = 1;
const TRAINING_CASE_MAX_DOC_BYTES = 24000;
const TRAINING_CASE_MAX_STEPS = 12;
const TRAINING_CASE_MAX_FINDINGS = 12;
const TRAINING_CASE_RATING_POINTS = ['best' => 2, 'acceptable' => 1, 'harmful' => 0];

/** Plain text only: tags removed, runs of spaces collapsed (line breaks kept), cut to $max characters. */
function trainingCasePlain($value, int $max): string {
    $text = trim(strip_tags((string) $value));
    $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
    return mb_substr($text, 0, $max);
}

/** A step or choice id: a lowercase letter then up to 23 letters, digits or underscores, else ''. */
function trainingCaseId($value): string {
    $id = strtolower(trim((string) $value));
    return preg_match('/^[a-z][a-z0-9_]{0,23}$/', $id) ? $id : '';
}

/**
 * Reads a decoded document into the one shape every other function relies on: known keys only, plain text, every
 * field cut to its limit, steps kept in the order given. Returns null when $raw is not an array. It never repairs
 * the STRUCTURE (a missing step stays missing): trainingCaseValidate() says what is wrong.
 */
function trainingCaseNormalize($raw): ?array {
    if (!is_array($raw)) {
        return null;
    }
    $doc = [
        'title'    => trainingCasePlain($raw['title'] ?? '', 160),
        'story'    => trainingCasePlain($raw['story'] ?? '', 1500),
        'closing'  => trainingCasePlain($raw['closing'] ?? '', 1500),
        'findings' => [],
        'start'    => trainingCaseId($raw['start'] ?? ''),
        'steps'    => [],
    ];
    $findings = is_array($raw['findings'] ?? null) ? array_values($raw['findings']) : [];
    foreach (array_slice($findings, 0, TRAINING_CASE_MAX_FINDINGS) as $finding) {
        if (!is_array($finding)) {
            continue;
        }
        $label = trainingCasePlain($finding['label'] ?? '', 60);
        $value = trainingCasePlain($finding['value'] ?? '', 60);
        if ($label === '' || $value === '') {
            continue;
        }
        $doc['findings'][] = ['label' => $label, 'value' => $value, 'unit' => trainingCasePlain($finding['unit'] ?? '', 20)];
    }
    $steps = is_array($raw['steps'] ?? null) ? $raw['steps'] : [];
    foreach ($steps as $stepId => $step) {
        $stepId = trainingCaseId($stepId);
        if ($stepId === '' || !is_array($step) || isset($doc['steps'][$stepId])) {
            continue;
        }
        $choices = [];
        foreach (is_array($step['choices'] ?? null) ? array_values($step['choices']) : [] as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $next = $choice['next'] ?? null;
            $choices[] = [
                'id'       => trainingCaseId($choice['id'] ?? ''),
                'label'    => trainingCasePlain($choice['label'] ?? '', 200),
                'rating'   => (string) ($choice['rating'] ?? ''),
                'feedback' => trainingCasePlain($choice['feedback'] ?? '', 600),
                'next'     => ($next === null || $next === '') ? null : trainingCaseId($next),
            ];
        }
        $doc['steps'][$stepId] = [
            'prompt'  => trainingCasePlain($step['prompt'] ?? '', 400),
            'hint'    => trainingCasePlain($step['hint'] ?? '', 300),
            'choices' => array_slice($choices, 0, 8),
        ];
    }
    return $doc;
}

/**
 * Every rule a case must meet, on a NORMALISED document. Returns a list of ['code' => string, 'where' => step id or ''],
 * empty when the case is sound. The loop and reachability checks run only once the rest is sound, since they
 * need every next to point somewhere real.
 */
function trainingCaseValidate(array $doc): array {
    $errors = [];
    $add = function (string $code, string $where = '') use (&$errors): void {
        $errors[] = ['code' => $code, 'where' => $where];
    };
    if ($doc['title'] === '') {
        $add('title_missing');
    }
    if ($doc['story'] === '') {
        $add('story_missing');
    }
    if (count($doc['steps']) < 1) {
        $add('no_steps');
    }
    if (count($doc['steps']) > TRAINING_CASE_MAX_STEPS) {
        $add('too_many_steps');
    }
    if (!isset($doc['steps'][$doc['start']])) {
        $add('start_missing');
    }
    foreach ($doc['steps'] as $stepId => $step) {
        $stepId = (string) $stepId;
        if ($step['prompt'] === '') {
            $add('prompt_missing', $stepId);
        }
        $count = count($step['choices']);
        if ($count < 2 || $count > 5) {
            $add('choice_count', $stepId);
        }
        $seen = [];
        $hasBest = false;
        foreach ($step['choices'] as $choice) {
            if ($choice['id'] === '' || isset($seen[$choice['id']])) {
                $add('choice_id', $stepId);
            }
            $seen[$choice['id']] = true;
            if (!isset(TRAINING_CASE_RATING_POINTS[$choice['rating']])) {
                $add('rating_invalid', $stepId);
            }
            if ($choice['rating'] === 'best') {
                $hasBest = true;
            }
            if ($choice['label'] === '') {
                $add('choice_label_missing', $stepId);
            }
            if ($choice['feedback'] === '') {
                $add('feedback_missing', $stepId);
            }
            if ($choice['next'] !== null && !isset($doc['steps'][$choice['next']])) {
                $add('next_missing', $stepId);
            }
        }
        if (!$hasBest) {
            $add('no_best', $stepId);
        }
    }
    if (!$errors) {
        $state = [];
        $visit = function (string $stepId) use (&$visit, &$state, $doc, $add): void {
            $state[$stepId] = 1;
            foreach ($doc['steps'][$stepId]['choices'] as $choice) {
                $next = $choice['next'];
                if ($next === null) {
                    continue;
                }
                if (($state[$next] ?? 0) === 1) {
                    $add('loop', $stepId);
                } elseif (!isset($state[$next])) {
                    $visit($next);
                }
            }
            $state[$stepId] = 2;
        };
        $visit($doc['start']);
        foreach (array_keys($doc['steps']) as $stepId) {
            if (!isset($state[(string) $stepId])) {
                $add('unreachable', (string) $stepId);
            }
        }
    }
    if (strlen((string) json_encode($doc, JSON_UNESCAPED_UNICODE)) > TRAINING_CASE_MAX_DOC_BYTES) {
        $add('too_big');
    }
    return $errors;
}

/** The sentence for one validation error, in the viewer's language. */
function trainingCaseErrorText(array $error): string {
    return t('case.err.' . $error['code'], ['step' => (string) ($error['where'] ?? '')]);
}

function trainingCaseRatingPoints(string $rating): int {
    return TRAINING_CASE_RATING_POINTS[$rating] ?? 0;
}

/** 'excellent' from 80%, 'good' from 50%, else 'practice' (also for a run with nothing answered). */
function trainingCaseScoreLabel(int $points, int $max): string {
    if ($max <= 0) {
        return 'practice';
    }
    $ratio = $points / $max;
    return $ratio >= 0.8 ? 'excellent' : ($ratio >= 0.5 ? 'good' : 'practice');
}

function trainingCaseScorePercent(int $points, int $max): int {
    return $max > 0 ? (int) round(100 * $points / $max) : 0;
}

/** The ideal route through a valid case: from start, the first `best` choice of each step. [[stepId, choiceId], ...]. */
function trainingCaseBestPath(array $doc): array {
    $path = [];
    $stepId = $doc['start'];
    while ($stepId !== null && isset($doc['steps'][$stepId])) {
        $best = null;
        foreach ($doc['steps'][$stepId]['choices'] as $choice) {
            if ($choice['rating'] === 'best') {
                $best = $choice;
                break;
            }
        }
        if ($best === null) {
            break;
        }
        $path[] = [$stepId, $best['id']];
        $stepId = $best['next'];
    }
    return $path;
}
