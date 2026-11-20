<?php
/**
 * VolunteerOps - Training case editor (v3.505.0).
 * System admin only. ?id=N edits a case, no id creates one. A starter case is not edited in place (it is duplicated from
 * the library). One plain form: details, story, findings, step cards with their choices, closing lessons. The browser
 * script (assets/js/training-case-editor.js) draws the findings and the steps and puts the whole document as JSON in the
 * hidden `document` field on submit; this page cuts that field to a size limit BEFORE it is decoded, then trainingCaseSave()
 * normalises and validates it (nothing the browser built is trusted) and a refused save re-draws the form with what was
 * typed plus the reasons in plain language.
 *
 * Case text is untrusted: every value is printed through h() or handed to the script as JSON inside an escaped attribute.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
if (!isSystemAdmin()) {
    setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης σε αυτή τη σελίδα.');
    redirect('dashboard.php');
}

$userId = (int) getCurrentUserId();
$id = (int) get('id');
$isEdit = $id > 0;
$pageTitle = t($isEdit ? 'case.ed.page_edit' : 'case.ed.page_new');

$row = null;
if ($isEdit) {
    $row = trainingCaseLoad($id);
    if (!$row) {
        setFlash('error', t('case.err.not_found'));
        redirect('training-cases.php');
    }
    if ((int) $row['is_starter'] === 1) {
        setFlash('error', t('case.err.starter_locked'));
        redirect('training-cases.php');
    }
}

/** A posted value as a plain string (an array or nothing reads as ''), so the meta reader never sees a non-scalar. */
function trainingCasePostedScalar(string $key): string {
    return isset($_POST[$key]) && is_scalar($_POST[$key]) ? (string) $_POST[$key] : '';
}

$meta = trainingCaseMeta($row ?? []);
$source = $row['doc'] ?? null;   // what the form is drawn from: the stored document, or (after a refused save) what was posted
$errors = [];

if (isPost()) {
    verifyCsrf();
    $meta = trainingCaseMeta([
        'type_tag' => trainingCasePostedScalar('type_tag'), 'difficulty' => trainingCasePostedScalar('difficulty'),
        'language' => trainingCasePostedScalar('language'), 'est_minutes' => trainingCasePostedScalar('est_minutes'),
    ]);
    $raw = trainingCasePostedScalar('document');
    $source = null;
    if (strlen($raw) > TRAINING_CASE_MAX_EDITOR_BYTES) {
        // Checked before anything is decoded or normalised: the tag stripper is quadratic on adversarial input.
        $errors[] = trainingCaseErrorText(['code' => 'too_big', 'where' => '']);
    } else {
        $decoded = json_decode($raw, true, 32);
        if (!is_array($decoded)) {
            $errors[] = trainingCaseErrorText(['code' => 'unreadable', 'where' => '']);
        } else {
            $source = $decoded;
            $result = trainingCaseSave($isEdit ? $id : null, $meta, $decoded, $userId);
            if ($result['ok']) {
                setFlash('success', t('case.lib.msg_saved'));
                redirect('training-cases.php');
            }
            foreach ($result['errors'] as $error) {
                $errors[] = trainingCaseErrorText($error);
            }
        }
    }
    $errors = array_values(array_unique($errors));
}

$model = trainingCaseEditorModel($source);

// The words the editor script puts on the page, and the player's own words for the «Δοκιμή» try-out.
$edLabelNames = [
    'end', 'finding_label', 'finding_value', 'finding_unit', 'remove', 'step', 'step_start', 'step_id', 'prompt', 'hint', 'choices',
    'choice_label', 'rating', 'rating_best', 'rating_acceptable', 'rating_harmful', 'feedback', 'next', 'add_choice', 'remove_choice',
    'remove_step', 'move_up', 'move_down', 'bad_id', 'id_taken', 'try_invalid',
];
$edLabels = [];
foreach ($edLabelNames as $name) {
    $edLabels[$name] = t('case.ed.' . $name);
}
$playerKeys = [
    'case.dialog_label', 'case.banner', 'case.stop', 'case.begin', 'case.continue', 'case.close', 'case.retry', 'case.loading',
    'case.err_gone', 'case.rating.best', 'case.rating.acceptable', 'case.rating.harmful', 'case.end_title', 'case.label.excellent',
    'case.label.good', 'case.label.practice', 'case.your_path', 'case.best_path', 'case.lessons',
];
$playerLabels = [];
foreach ($playerKeys as $key) {
    $playerLabels[$key] = t($key);
}
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

$formAction = 'training-case-edit.php' . ($isEdit ? '?id=' . (int) $id : '');
$typeOptions = [];
foreach (TRAINING_CASE_TYPES as $tag) {
    $typeOptions[$tag] = t('case.type.' . $tag);
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-clipboard2-pulse me-2"></i><?= h($pageTitle) ?></h1>
    <a class="btn btn-outline-secondary" href="training-cases.php"><i class="bi bi-arrow-left me-1"></i><?= h(t('case.ed.back')) ?></a>
</div>

<?= showFlash() ?>

<?php if ($isEdit && (int) $row['reviewed'] === 1): ?>
    <div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('case.ed.reviewed_reset')) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger" role="alert">
        <strong><?= h(t('case.ed.errors_title')) ?></strong>
        <ul class="mb-0">
            <?php foreach ($errors as $message): ?>
                <li><?= h($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<noscript><div class="alert alert-danger"><?= h(t('case.ed.js_missing')) ?></div></noscript>
<div id="caseEditorMsg" class="alert alert-warning d-none" role="alert"></div>

<form method="post" id="caseEditorForm" action="<?= h($formAction) ?>"
      data-model="<?= h((string) json_encode($model, $jsonFlags)) ?>"
      data-labels="<?= h((string) json_encode($edLabels, $jsonFlags)) ?>"
      data-player-labels="<?= h((string) json_encode($playerLabels, $jsonFlags)) ?>">
    <?= csrfField() ?>
    <input type="hidden" name="document" value="">

    <div class="card mb-3">
        <div class="card-header"><strong><?= h(t('case.ed.details')) ?></strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-lg-6">
                    <label class="form-label" for="caseTitle"><?= h(t('case.ed.title')) ?></label>
                    <input type="text" class="form-control" id="caseTitle" maxlength="160" required value="<?= h($model['title']) ?>">
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label" for="caseType"><?= h(t('case.ed.type')) ?></label>
                    <select class="form-select" id="caseType" name="type_tag">
                        <?php foreach ($typeOptions as $tag => $label): ?>
                            <option value="<?= h($tag) ?>" <?= h($meta['type_tag'] === $tag ? 'selected' : '') ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label" for="caseDifficulty"><?= h(t('case.ed.difficulty')) ?></label>
                    <select class="form-select" id="caseDifficulty" name="difficulty">
                        <?php for ($d = 1; $d <= 3; $d++): ?>
                            <option value="<?= (int) $d ?>" <?= h((int) $meta['difficulty'] === $d ? 'selected' : '') ?>><?= h(t('case.ed.difficulty_' . $d)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label" for="caseLanguage"><?= h(t('case.ed.language')) ?></label>
                    <select class="form-select" id="caseLanguage" name="language">
                        <option value="el" <?= h($meta['language'] === 'el' ? 'selected' : '') ?>><?= h(t('case.lang.el')) ?></option>
                        <option value="en" <?= h($meta['language'] === 'en' ? 'selected' : '') ?>><?= h(t('case.lang.en')) ?></option>
                    </select>
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label" for="caseMinutes"><?= h(t('case.ed.minutes')) ?></label>
                    <input type="number" class="form-control" id="caseMinutes" name="est_minutes" min="1" max="30" value="<?= (int) $meta['est_minutes'] ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong><?= h(t('case.ed.story')) ?></strong></div>
        <div class="card-body">
            <p class="small text-muted"><?= h(t('case.ed.story_help')) ?></p>
            <textarea class="form-control" id="caseStory" rows="5" maxlength="1500" aria-label="<?= h(t('case.ed.story')) ?>"><?= h($model['story']) ?></textarea>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong><?= h(t('case.ed.findings')) ?></strong>
            <button type="button" class="btn btn-sm btn-outline-primary" id="addFinding"><i class="bi bi-plus-lg me-1"></i><?= h(t('case.ed.add_finding')) ?></button>
        </div>
        <div class="card-body">
            <p class="small text-muted"><?= h(t('case.ed.findings_help')) ?></p>
            <div id="caseFindings"></div>
        </div>
    </div>

    <h2 class="h5 mt-4"><?= h(t('case.ed.steps')) ?></h2>
    <p class="small text-muted"><?= h(t('case.ed.steps_help')) ?></p>
    <div id="caseSteps"></div>
    <button type="button" class="btn btn-outline-primary mb-4" id="addStep"><i class="bi bi-plus-lg me-1"></i><?= h(t('case.ed.add_step')) ?></button>

    <div class="card mb-3">
        <div class="card-header"><strong><?= h(t('case.ed.closing')) ?></strong></div>
        <div class="card-body">
            <p class="small text-muted"><?= h(t('case.ed.closing_help')) ?></p>
            <textarea class="form-control" id="caseClosing" rows="4" maxlength="1500" aria-label="<?= h(t('case.ed.closing')) ?>"><?= h($model['closing']) ?></textarea>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-5">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= h(t('case.ed.save')) ?></button>
        <button type="button" class="btn btn-outline-success" id="tryOut"><i class="bi bi-play-circle me-1"></i><?= h(t('case.ed.try')) ?></button>
        <a class="btn btn-outline-secondary" href="training-cases.php"><?= h(t('case.lib.cancel')) ?></a>
        <span class="small text-muted"><?= h(t('case.ed.try_note')) ?></span>
    </div>
</form>

<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/training-case.js?v=<?= APP_VERSION ?>.<?= (int) @filemtime(__DIR__ . '/assets/js/training-case.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/training-case-editor.js?v=<?= APP_VERSION ?>.<?= (int) @filemtime(__DIR__ . '/assets/js/training-case-editor.js') ?>"></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
