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

/**
 * Plain text only: real tags and comments removed, runs of spaces collapsed (line breaks kept), cut to $max characters.
 * A '<' that does not start a tag (for example '<90' or '<50') is kept literally: clinical thresholds are content.
 * A tag starts with '<' then a letter, '/', '!' or '?'. Removal repeats until nothing changes, so '<<b>b>' cannot
 * rebuild a tag. Non-scalar input (an array from JSON) reads as ''.
 * The raw text is first cut to 4 times $max bytes, so the repeated removal costs a bounded amount whatever the input
 * ('<<<...b>b>b>' is quadratic otherwise); real text is far shorter than that, tags included.
 */
function trainingCasePlain($value, int $max): string {
    $text = is_scalar($value) ? (string) $value : '';
    $text = mb_strcut($text, 0, max(1, $max) * 4, 'UTF-8');
    do {
        $before = $text;
        $text = (string) preg_replace('/<!--.*?-->|<[\/!?]?[a-z][^>]*>/is', '', $text);
    } while ($text !== $before);
    $text = trim($text);
    $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
    return mb_substr($text, 0, $max);
}

/** A step or choice id: a lowercase letter then up to 23 letters, digits or underscores, else ''. */
function trainingCaseId($value): string {
    $id = strtolower(trim(is_scalar($value) ? (string) $value : ''));
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
            $rating = $choice['rating'] ?? '';
            $choices[] = [
                'id'       => trainingCaseId($choice['id'] ?? ''),
                'label'    => trainingCasePlain($choice['label'] ?? '', 200),
                'rating'   => is_scalar($rating) ? (string) $rating : '',
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

/**
 * The ideal route through a valid case: from start, the first `best` choice of each step. [[stepId, choiceId], ...].
 * It stops at a step already on the path or after TRAINING_CASE_MAX_STEPS steps, so an unvalidated cyclic document
 * cannot loop forever.
 */
function trainingCaseBestPath(array $doc): array {
    $path = [];
    $seen = [];
    $stepId = $doc['start'];
    while ($stepId !== null && isset($doc['steps'][$stepId])) {
        if (isset($seen[$stepId]) || count($path) >= TRAINING_CASE_MAX_STEPS) {
            break;
        }
        $seen[$stepId] = true;
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

const TRAINING_CASE_TYPES = ['trauma', 'cardiac', 'respiratory', 'neuro', 'environmental', 'other'];
/**
 * The size of an import or export file. The export is pretty-printed, and the 24 starters shipped since v3.506.0 come to
 * about 355 KB on their own, so "export everything" on a fresh library has to fit: 1 MB holds 50 cases of a normal size.
 */
const TRAINING_CASE_MAX_IMPORT_BYTES = 1000000;
/** An import reads only this many cases of a file; the library's export refuses to produce more than the import would keep. */
const TRAINING_CASE_MAX_IMPORT_CASES = 50;
/** The editor posts the whole document as one JSON field: a real case is far under this (the stored limit is 24000 bytes). */
const TRAINING_CASE_MAX_EDITOR_BYTES = 100000;
const TRAINING_CASE_PACK_FORMAT = 'volunteerops-training-cases';

/** type, difficulty (1 to 3), language (el|en) and minutes (1 to 30), clamped to what the library allows. */
function trainingCaseMeta(array $meta): array {
    $type = (string) ($meta['type_tag'] ?? 'other');
    $lang = (string) ($meta['language'] ?? 'el');
    return [
        'type_tag'    => in_array($type, TRAINING_CASE_TYPES, true) ? $type : 'other',
        'difficulty'  => max(1, min(3, (int) ($meta['difficulty'] ?? 1))),
        'language'    => in_array($lang, ['el', 'en'], true) ? $lang : 'el',
        'est_minutes' => max(1, min(30, (int) ($meta['est_minutes'] ?? 5))),
    ];
}

/** A slug nobody uses yet. Greek titles have no latin letters, so the base falls back to 'case'. */
function trainingCaseUniqueSlug(string $title): string {
    $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
    if ($base === '') {
        $base = 'case';
    }
    $base = substr($base, 0, 60);
    $slug = $base;
    $n = 1;
    while (dbFetchValue("SELECT 1 FROM training_cases WHERE slug = ?", [$slug])) {
        $n++;
        $slug = $base . '-' . $n;
    }
    return $slug;
}

/**
 * Creates ($id null) or edits a case. The document is normalised and validated first; nothing is written when it
 * has errors. Every case can be edited, a starter included (it stays a starter: the mark only says the case came with the app).
 */
function trainingCaseSave(?int $id, array $meta, $rawDoc, int $userId): array {
    $doc = trainingCaseNormalize($rawDoc);
    if ($doc === null) {
        return ['ok' => false, 'errors' => [['code' => 'unreadable', 'where' => '']]];
    }
    $errors = trainingCaseValidate($doc);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }
    $fields = trainingCaseMeta($meta);
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
    if ($id === null) {
        $newId = (int) dbInsert(
            "INSERT INTO training_cases (slug, title, type_tag, difficulty, language, est_minutes, document, format_version, is_starter, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)",
            [trainingCaseUniqueSlug($doc['title']), $doc['title'], $fields['type_tag'], $fields['difficulty'], $fields['language'],
             $fields['est_minutes'], $json, TRAINING_CASE_FORMAT_VERSION, $userId]
        );
        logAudit('create_training_case', 'training_cases', $newId, null, ['title' => $doc['title']]);
        return ['ok' => true, 'id' => $newId];
    }
    if (!dbFetchValue("SELECT 1 FROM training_cases WHERE id = ?", [$id])) {
        return ['ok' => false, 'errors' => [['code' => 'not_found', 'where' => '']]];
    }
    dbExecute(
        "UPDATE training_cases SET title = ?, type_tag = ?, difficulty = ?, language = ?, est_minutes = ?, document = ?, format_version = ?
         WHERE id = ?",
        [$doc['title'], $fields['type_tag'], $fields['difficulty'], $fields['language'], $fields['est_minutes'], $json, TRAINING_CASE_FORMAT_VERSION, $id]
    );
    logAudit('update_training_case', 'training_cases', $id, null, ['title' => $doc['title']]);
    return ['ok' => true, 'id' => $id];
}

/** One case with its document decoded into ['doc'], or null. */
function trainingCaseLoad(int $id): ?array {
    $row = dbFetchOne("SELECT * FROM training_cases WHERE id = ?", [$id]);
    if (!$row) {
        return null;
    }
    $row['doc'] = trainingCaseNormalize(json_decode((string) $row['document'], true)) ?? [];
    return $row;
}

/** The library, without documents: starters first, then by title. Archived ones only with include_archived. */
function trainingCaseList(array $filter = []): array {
    $where = [];
    $args = [];
    if (empty($filter['include_archived'])) {
        $where[] = 'archived_at IS NULL';
    }
    if (!empty($filter['type'])) {
        $where[] = 'type_tag = ?';
        $args[] = (string) $filter['type'];
    }
    if (!empty($filter['difficulty'])) {
        $where[] = 'difficulty = ?';
        $args[] = (int) $filter['difficulty'];
    }
    if (!empty($filter['language'])) {
        $where[] = 'language = ?';
        $args[] = (string) $filter['language'];
    }
    return dbFetchAll(
        "SELECT id, slug, title, type_tag, difficulty, language, est_minutes, is_starter, archived_at
         FROM training_cases" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
         ORDER BY is_starter DESC, title, id",
        $args
    );
}

/** A copy of any case (a starter included), saved as an ordinary, non-starter case. */
function trainingCaseDuplicate(int $id, int $userId): array {
    $row = trainingCaseLoad($id);
    if (!$row) {
        return ['ok' => false, 'errors' => [['code' => 'not_found', 'where' => '']]];
    }
    $doc = $row['doc'];
    $doc['title'] = mb_substr($doc['title'] . ' (αντίγραφο)', 0, 160);
    return trainingCaseSave(null, $row, $doc, $userId);
}

function trainingCaseArchive(int $id, bool $archive): void {
    dbExecute("UPDATE training_cases SET archived_at = " . ($archive ? 'NOW()' : 'NULL') . " WHERE id = ?", [$id]);
}

/**
 * Deletes a case from the library for good, a starter included (the seeder remembers a starter was seeded, so it does not
 * come back). Safe for drills: a placement keeps its own snapshot and its case_id becomes NULL (ON DELETE SET NULL), and a
 * staged scenario item carries its own copy of the document. True when a row was deleted, false when there was none.
 */
function trainingCaseDelete(int $id): bool {
    return dbExecute("DELETE FROM training_cases WHERE id = ?", [$id]) > 0;
}

/** One file holding any number of cases: {format, format_version, cases: [{slug, type_tag, difficulty, language, est_minutes, document}]}. */
function trainingCaseExport(array $ids): string {
    $cases = [];
    foreach ($ids as $id) {
        $row = trainingCaseLoad((int) $id);
        if (!$row) {
            continue;
        }
        $cases[] = [
            'slug' => $row['slug'], 'type_tag' => $row['type_tag'], 'difficulty' => (int) $row['difficulty'],
            'language' => $row['language'], 'est_minutes' => (int) $row['est_minutes'], 'document' => $row['doc'],
        ];
    }
    return (string) json_encode(
        ['format' => TRAINING_CASE_PACK_FORMAT, 'format_version' => TRAINING_CASE_FORMAT_VERSION, 'cases' => $cases],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
}

/**
 * Reads an import file WITHOUT saving: ['ok' => false, 'error' => t() key] for a file that is too big, not JSON, not
 * one of ours or from a newer format; else ['ok' => true, 'cases' => [['title', 'meta', 'doc', 'errors', 'clash']]],
 * where `clash` says the slug is already in the library (the import renames it) and `errors` is the validation list.
 * Anything the format does not know is ignored. The size check comes first: nothing is decoded or normalised before it.
 */
function trainingCaseImportRead(string $json): array {
    if (strlen($json) > TRAINING_CASE_MAX_IMPORT_BYTES) {
        return ['ok' => false, 'error' => 'case.import_too_big'];
    }
    $pack = json_decode($json, true);
    if (!is_array($pack) || ($pack['format'] ?? '') !== TRAINING_CASE_PACK_FORMAT || !is_array($pack['cases'] ?? null)) {
        return ['ok' => false, 'error' => 'case.import_unreadable'];
    }
    if ((int) ($pack['format_version'] ?? 0) > TRAINING_CASE_FORMAT_VERSION) {
        return ['ok' => false, 'error' => 'case.import_newer'];
    }
    $out = [];
    foreach (array_slice($pack['cases'], 0, TRAINING_CASE_MAX_IMPORT_CASES) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $doc = trainingCaseNormalize($entry['document'] ?? null);
        $errors = $doc === null ? [['code' => 'unreadable', 'where' => '']] : trainingCaseValidate($doc);
        $slugRaw = $entry['slug'] ?? '';
        $slug = substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(is_scalar($slugRaw) ? (string) $slugRaw : '')), '-'), 0, 80);
        $out[] = [
            'title'  => $doc['title'] ?? '',
            'slug'   => $slug,
            'meta'   => trainingCaseMeta($entry),
            'doc'    => $doc,
            'errors' => $errors,
            'clash'  => $slug !== '' && (bool) dbFetchValue("SELECT 1 FROM training_cases WHERE slug = ?", [$slug]),
        ];
    }
    return ['ok' => true, 'cases' => $out];
}

/** Saves every valid case of an import file as a new, non-starter case. Returns ['saved' => n, 'skipped' => n, 'ids' => []]. */
function trainingCaseImport(string $json, int $userId): array {
    $read = trainingCaseImportRead($json);
    if (!$read['ok']) {
        return ['saved' => 0, 'skipped' => 0, 'ids' => [], 'error' => $read['error']];
    }
    $saved = [];
    $skipped = 0;
    foreach ($read['cases'] as $case) {
        if ($case['errors'] || $case['doc'] === null) {
            $skipped++;
            continue;
        }
        $result = trainingCaseSave(null, $case['meta'], $case['doc'], $userId);
        if ($result['ok']) {
            // trainingCaseSave() names the slug from the title; keep the file's own slug when it is free.
            if ($case['slug'] !== '' && !dbFetchValue("SELECT 1 FROM training_cases WHERE slug = ?", [$case['slug']])) {
                dbExecute("UPDATE training_cases SET slug = ? WHERE id = ?", [$case['slug'], $result['id']]);
            }
            $saved[] = $result['id'];
        } else {
            $skipped++;
        }
    }
    return ['saved' => count($saved), 'skipped' => $skipped, 'ids' => $saved];
}

/** The settings key that remembers which shipped starter slugs have already been seeded on this installation (a JSON list). */
const TRAINING_CASES_SEEDED_KEY = 'training_cases_seeded_slugs';

/**
 * The slugs already seeded here. Read straight from the database, not through getSetting() (which keeps its answer for the
 * whole request): a slug recorded a moment ago by another request must be seen at once. A missing or unreadable value is an
 * empty list.
 */
function trainingCaseSeededSlugs(): array {
    $list = json_decode((string) dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = ?", [TRAINING_CASES_SEEDED_KEY]), true);
    return is_array($list) ? array_values(array_unique(array_filter(array_map('strval', $list), static fn (string $s): bool => $s !== ''))) : [];
}

function trainingCaseSaveSeededSlugs(array $slugs): void {
    dbExecute(
        "INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
        [TRAINING_CASES_SEEDED_KEY, json_encode(array_values(array_unique($slugs)), JSON_UNESCAPED_UNICODE)]
    );
}

/**
 * Loads the starter cases shipped in includes/training-cases/*.json into the library, each ONE TIME. A shipped case is
 * inserted only when its slug has never been seeded on this installation; the slugs that have been are kept as a JSON list in
 * the settings table (TRAINING_CASES_SEEDED_KEY). It never updates an existing row (an edited starter stays as the admin left
 * it) and never brings back a deleted one. Per file: slug on the list, skip; slug not on the list but a row with that slug
 * exists (an installation that already has the starters from v3.505/3.506, or a slug that collides with a case of the
 * admin's, which is logged), record the slug and leave the row alone; no row, insert it as a starter and record the slug.
 * Returns how many rows were inserted. Cheap enough to call when the library or the staging list opens. $dir overrides the
 * shipped includes/training-cases folder (used by the tests).
 */
function trainingCaseSeedStarters(?string $dir = null): int {
    $inserted = 0;
    $changedList = false;
    $dir = $dir === null ? __DIR__ . '/training-cases' : rtrim($dir, '/\\');
    $seeded = trainingCaseSeededSlugs();
    $known = array_flip($seeded);
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $entry = json_decode((string) file_get_contents($file), true);
        $doc = is_array($entry) ? trainingCaseNormalize($entry['document'] ?? null) : null;
        $slug = is_array($entry) ? strtolower(trim((string) ($entry['slug'] ?? ''))) : '';
        if ($doc === null || $slug === '' || trainingCaseValidate($doc)) {
            error_log('[training-cases] starter file ' . basename($file) . ' is not a valid case');
            continue;
        }
        if (isset($known[$slug])) {
            continue;
        }
        $existing = dbFetchOne("SELECT id, is_starter FROM training_cases WHERE slug = ?", [$slug]);
        if ($existing) {
            if ((int) $existing['is_starter'] !== 1) {
                error_log('[training-cases] starter ' . $slug . ' (' . basename($file) . ') not loaded: the slug belongs to a case that is not a starter');
            }
        } elseif (trainingCaseInsertStarter($slug, $doc, trainingCaseMeta($entry), (string) json_encode($doc, JSON_UNESCAPED_UNICODE))) {
            $inserted++;
        }
        // Inserted now, already there, or inserted a moment ago by a concurrent request (insert returned false): in every
        // case this slug has been dealt with once and is never looked at again.
        $known[$slug] = true;
        $seeded[] = $slug;
        $changedList = true;
    }
    if ($changedList) {
        trainingCaseSaveSeededSlugs($seeded);
    }
    return $inserted;
}

/**
 * Inserts one starter row (the column default covers the old review columns, which the app no longer uses). Returns false,
 * without throwing, when the slug already exists (duplicate key: another request seeded it first); any other database error
 * still propagates.
 */
function trainingCaseInsertStarter(string $slug, array $doc, array $meta, string $json): bool {
    try {
        dbInsert(
            "INSERT INTO training_cases (slug, title, type_tag, difficulty, language, est_minutes, document, format_version, is_starter)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)",
            [$slug, $doc['title'], $meta['type_tag'], $meta['difficulty'], $meta['language'], $meta['est_minutes'], $json, TRAINING_CASE_FORMAT_VERSION]
        );
        return true;
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return false;
        }
        throw $e;
    }
}

/**
 * What the editor draws, from a document or from what an admin posted (raw, unvalidated JSON, already cut to
 * TRAINING_CASE_MAX_EDITOR_BYTES by the caller). It never repairs and never strips: a refused save re-draws the form with
 * what was typed, so the author can fix it. Every value is a string cut to the field's limit, the steps are a LIST with
 * the start step first, and only what the page can draw is kept (at most 30 steps, 8 choices a step, 24 findings).
 * Nothing here is trusted: the page prints it through h() and the editor script sets it with textContent.
 * Returns ['title', 'story', 'closing', 'findings' => [...], 'steps' => [['id', 'prompt', 'hint', 'choices' => [...]]]].
 */
function trainingCaseEditorModel($raw): array {
    $str = static function ($value, int $max): string {
        return is_scalar($value) ? mb_substr((string) $value, 0, $max) : '';
    };
    $model = ['title' => '', 'story' => '', 'closing' => '', 'findings' => [], 'steps' => []];
    if (!is_array($raw)) {
        return $model;
    }
    $model['title'] = $str($raw['title'] ?? '', 160);
    $model['story'] = $str($raw['story'] ?? '', 1500);
    $model['closing'] = $str($raw['closing'] ?? '', 1500);
    foreach (array_slice(is_array($raw['findings'] ?? null) ? array_values($raw['findings']) : [], 0, 24) as $finding) {
        if (is_array($finding)) {
            $model['findings'][] = [
                'label' => $str($finding['label'] ?? '', 60), 'value' => $str($finding['value'] ?? '', 60), 'unit' => $str($finding['unit'] ?? '', 20),
            ];
        }
    }
    $steps = [];
    foreach (is_array($raw['steps'] ?? null) ? $raw['steps'] : [] as $stepId => $step) {
        $stepId = trainingCaseId($stepId);
        if ($stepId === '' || !is_array($step) || isset($steps[$stepId])) {
            continue;
        }
        $choices = [];
        foreach (array_slice(is_array($step['choices'] ?? null) ? array_values($step['choices']) : [], 0, 8) as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $choices[] = [
                'id'       => trainingCaseId($choice['id'] ?? ''),
                'label'    => $str($choice['label'] ?? '', 200),
                'rating'   => $str($choice['rating'] ?? '', 20),
                'feedback' => $str($choice['feedback'] ?? '', 600),
                'next'     => trainingCaseId($choice['next'] ?? ''),
            ];
        }
        $steps[$stepId] = ['id' => $stepId, 'prompt' => $str($step['prompt'] ?? '', 400), 'hint' => $str($step['hint'] ?? '', 300), 'choices' => $choices];
        if (count($steps) >= 30) {
            break;
        }
    }
    $start = trainingCaseId($raw['start'] ?? '');
    if ($start !== '' && isset($steps[$start])) {
        $steps = [$start => $steps[$start]] + $steps;
    }
    $model['steps'] = array_values($steps);
    return $model;
}

/**
 * Picks one case from the library for "random": not archived, matching the filters (type, difficulty, language). Cases already staged in this scenario ($excludeCaseIds) are avoided while others remain; when
 * nothing else is left the whole pool is used. Returns a trainingCaseLoad() row, or null when the pool is empty.
 */
function trainingCaseDraw(array $filter, array $excludeCaseIds = []): ?array {
    $where = ['archived_at IS NULL'];
    $args = [];
    if (!empty($filter['type'])) {
        $where[] = 'type_tag = ?';
        $args[] = (string) $filter['type'];
    }
    if (!empty($filter['difficulty'])) {
        $where[] = 'difficulty = ?';
        $args[] = (int) $filter['difficulty'];
    }
    if (!empty($filter['language'])) {
        $where[] = 'language = ?';
        $args[] = (string) $filter['language'];
    }
    $ids = array_map('intval', array_column(dbFetchAll("SELECT id FROM training_cases WHERE " . implode(' AND ', $where), $args), 'id'));
    if (!$ids) {
        return null;
    }
    $fresh = array_values(array_diff($ids, array_map('intval', $excludeCaseIds)));
    $pool = $fresh ?: $ids;
    return trainingCaseLoad($pool[random_int(0, count($pool) - 1)]);
}
