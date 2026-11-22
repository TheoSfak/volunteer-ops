<?php
/**
 * VolunteerOps - Training case library (v3.505.0).
 * System admin only. The list of cases (starters included) and the actions on every one of them: edit, duplicate,
 * archive / restore, delete (confirmed; a starter too), export (one case, the selected ones or the whole list, as one JSON
 * file) and import (upload, a validated preview, then a confirm). Editing is on training-case-edit.php. The rules are in
 * includes/functions-training-cases.php.
 *
 * A case's text is untrusted plain text (an imported file can hold anything): every value is printed through h().
 * Archiving, editing or deleting never touches a snapshot already staged in a drill (the snapshot is a copy).
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
if (!isSystemAdmin()) {
    setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης σε αυτή τη σελίδα.');
    redirect('dashboard.php');
}

$pageTitle = t('case.lib.page_title');
$userId = (int) getCurrentUserId();
$showArchived = get('archived') === '1' || post('archived') === '1';
$listUrl = 'training-cases.php' . ($showArchived ? '?archived=1' : '');

/** The type's name in the viewer's language; an unknown tag is shown as it is (the caller escapes it). */
function trainingCaseTypeLabel(string $tag): string {
    return in_array($tag, TRAINING_CASE_TYPES, true) ? t('case.type.' . $tag) : $tag;
}

/** One small POST form with a single action button for a row of the list; with $confirm the browser asks first. Every value is escaped here. */
function trainingCaseActionForm(string $action, int $id, string $label, string $icon, string $buttonClass, bool $showArchived, string $confirm = ''): string {
    return '<form method="post" class="d-inline"' . ($confirm !== '' ? ' onsubmit="return confirm(' . h((string) json_encode($confirm, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) . ')"' : '') . '>' . csrfField()
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<input type="hidden" name="id" value="' . (int) $id . '">'
        . '<input type="hidden" name="archived" value="' . ($showArchived ? '1' : '0') . '">'
        . '<button type="submit" class="btn btn-sm ' . h($buttonClass) . '" title="' . h($label) . '"><i class="bi ' . h($icon) . '" aria-hidden="true"></i> ' . h($label) . '</button>'
        . '</form>';
}

// A POST is checked before anything is written: the seeding below is a write too.
if (isPost()) {
    verifyCsrf();
}
// The shipped starter cases are loaded when the library opens, each one once per installation (an edited or deleted one stays so).
trainingCaseSeedStarters();

$importPreview = null;   // ['json' => the file's text, 'cases' => the preview rows] after an upload
$importError = '';

if (isPost()) {
    $action = post('action');
    $id = (int) post('id');

    if ($action === 'import_preview') {
        // Stay on this response: the preview is shown here, with the file's text kept in a hidden field for the confirm.
        $file = $_FILES['import_file'] ?? null;
        $code = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        $tmp = is_array($file) && is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        if ($code === UPLOAD_ERR_NO_FILE) {
            $importError = t('case.lib.import_no_file');
        } elseif ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            $importError = t('case.import_too_big');
        } elseif ($code !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            $importError = t('case.lib.import_upload_failed');
        } elseif ((int) ($file['size'] ?? 0) > TRAINING_CASE_MAX_IMPORT_BYTES) {
            // Checked before the file is read: nothing is decoded or normalised past this size.
            $importError = t('case.import_too_big');
        } else {
            $json = (string) file_get_contents($tmp, false, null, 0, TRAINING_CASE_MAX_IMPORT_BYTES + 1);
            $read = trainingCaseImportRead($json);
            if (!$read['ok']) {
                $importError = t($read['error']);
            } elseif (!$read['cases']) {
                $importError = t('case.lib.import_empty');
            } else {
                $importPreview = ['json' => $json, 'cases' => $read['cases']];
            }
        }
    } else {
        switch ($action) {
            case 'duplicate':
                $result = trainingCaseDuplicate($id, $userId);
                if ($result['ok']) {
                    logAudit('duplicate_training_case', 'training_cases', (int) $result['id'], null, ['from' => $id]);
                    setFlash('success', t('case.lib.msg_duplicated'));
                } else {
                    setFlash('error', trainingCaseErrorText($result['errors'][0]));
                }
                break;

            case 'archive':
            case 'unarchive':
                if (!trainingCaseLoad($id)) {
                    setFlash('error', t('case.err.not_found'));
                    break;
                }
                trainingCaseArchive($id, $action === 'archive');
                logAudit($action . '_training_case', 'training_cases', $id);
                setFlash('success', t($action === 'archive' ? 'case.lib.msg_archived' : 'case.lib.msg_unarchived'));
                break;

            case 'delete':
                // For good, a starter included. A drill that already staged or played the case keeps its own copy.
                $doomed = trainingCaseLoad($id);
                if (!$doomed || !trainingCaseDelete($id)) {
                    setFlash('error', t('case.err.not_found'));
                    break;
                }
                logAudit('delete_training_case', 'training_cases', $id, null, ['title' => $doomed['title'], 'slug' => $doomed['slug']]);
                setFlash('success', t('case.lib.msg_deleted'));
                break;

            case 'export':
                // One case (its own button), the ticked ones, or - with nothing ticked - everything in the list.
                $ids = [];
                if ($id > 0) {
                    $ids = [$id];
                } elseif (is_array($_POST['ids'] ?? null)) {
                    $ids = array_map('intval', array_slice($_POST['ids'], 0, 200));
                }
                if (!$ids) {
                    $ids = array_map('intval', array_column(trainingCaseList(['include_archived' => $showArchived]), 'id'));
                }
                $json = trainingCaseExport(array_slice(array_values(array_unique(array_filter($ids))), 0, 200));
                $packed = json_decode($json, true);
                if (empty($packed['cases'])) {
                    setFlash('error', t('case.lib.err_nothing_to_export'));
                    break;
                }
                // A file this page's own import would refuse (too many bytes) or cut short (only the first cases are read) is not sent.
                if (strlen($json) > TRAINING_CASE_MAX_IMPORT_BYTES || count($packed['cases']) > TRAINING_CASE_MAX_IMPORT_CASES) {
                    setFlash('error', t('case.lib.err_export_too_big', [
                        'kb' => (int) round(TRAINING_CASE_MAX_IMPORT_BYTES / 1000),
                        'cases' => TRAINING_CASE_MAX_IMPORT_CASES,
                    ]));
                    break;
                }
                logAudit('export_training_cases', 'training_cases', null, null, ['count' => count($packed['cases'])]);
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="training-cases.json"');
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: private, no-store');
                echo $json;
                exit;

            case 'import_confirm':
                $json = isset($_POST['import_json']) && is_string($_POST['import_json']) ? $_POST['import_json'] : '';
                if (strlen($json) > TRAINING_CASE_MAX_IMPORT_BYTES) {
                    setFlash('error', t('case.import_too_big'));
                    break;
                }
                $imported = trainingCaseImport($json, $userId);
                if (!empty($imported['error'])) {
                    setFlash('error', t($imported['error']));
                    break;
                }
                logAudit('import_training_cases', 'training_cases', null, null, ['saved' => $imported['saved'], 'skipped' => $imported['skipped']]);
                setFlash($imported['saved'] > 0 ? 'success' : 'error', t('case.lib.import_result', ['saved' => $imported['saved'], 'skipped' => $imported['skipped']]));
                break;

            default:
                setFlash('error', t('case.lib.err_bad_request'));
        }
        redirect($listUrl);
    }
}

$cases = trainingCaseList(['include_archived' => $showArchived]);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
    <h1 class="h3 mb-0">
        <i class="bi bi-clipboard2-pulse me-2"></i><?= h(t('case.lib.page_title')) ?>
    </h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="training-case-edit.php"><i class="bi bi-plus-lg me-1"></i><?= h(t('case.lib.new')) ?></a>
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#importBox"><i class="bi bi-upload me-1"></i><?= h(t('case.lib.import')) ?></button>
        <button type="submit" form="exportForm" class="btn btn-outline-secondary"><i class="bi bi-download me-1"></i><?= h(t('case.lib.export_selected')) ?></button>
        <a class="btn btn-outline-secondary" href="<?= h($showArchived ? 'training-cases.php' : 'training-cases.php?archived=1') ?>"><?= h(t($showArchived ? 'case.lib.hide_archived' : 'case.lib.show_archived')) ?></a>
    </div>
</div>
<p class="text-muted small mb-3"><?= h(t('case.lib.intro')) ?> <?= h(t('case.lib.export_hint')) ?></p>

<?= showFlash() ?>

<?php if ($importError !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= h($importError) ?></div>
<?php endif; ?>

<form method="post" id="exportForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="export">
    <input type="hidden" name="archived" value="<?= h($showArchived ? '1' : '0') ?>">
</form>

<?php if ($importPreview !== null): ?>
    <div class="card mb-4 border-primary">
        <div class="card-header"><strong><?= h(t('case.lib.import_title')) ?></strong></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= h(t('case.lib.col_title')) ?></th>
                        <th><?= h(t('case.lib.col_type')) ?></th>
                        <th><?= h(t('case.lib.col_difficulty')) ?></th>
                        <th><?= h(t('case.lib.col_minutes')) ?></th>
                        <th><?= h(t('case.lib.import_col_verdict')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $validCount = 0; foreach ($importPreview['cases'] as $previewCase): ?>
                        <?php $valid = empty($previewCase['errors']); if ($valid) { $validCount++; } ?>
                        <tr>
                            <td><?= h($previewCase['title'] !== '' ? $previewCase['title'] : t('case.lib.import_untitled')) ?></td>
                            <td><?= h(trainingCaseTypeLabel((string) $previewCase['meta']['type_tag'])) ?></td>
                            <td><?= h(str_repeat('★', (int) $previewCase['meta']['difficulty'])) ?></td>
                            <td><?= (int) $previewCase['meta']['est_minutes'] ?></td>
                            <td>
                                <?php if ($valid): ?>
                                    <span class="badge bg-success"><?= h(t('case.lib.import_ok')) ?></span>
                                    <?php if (!empty($previewCase['clash'])): ?>
                                        <div class="small text-muted"><?= h(t('case.lib.import_clash')) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-danger"><?= h(t('case.lib.import_invalid')) ?></span>
                                    <ul class="small mb-0 ps-3">
                                        <?php foreach (array_slice($previewCase['errors'], 0, 10) as $previewError): ?>
                                            <li><?= h(trainingCaseErrorText($previewError)) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
            <?php if ($validCount === 0): ?>
                <span class="text-danger small"><?= h(t('case.lib.import_none_valid')) ?></span>
            <?php endif; ?>
            <form method="post" class="d-flex gap-2">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="import_confirm">
                <input type="hidden" name="archived" value="<?= h($showArchived ? '1' : '0') ?>">
                <input type="hidden" name="import_json" value="<?= h($importPreview['json']) ?>">
                <button type="submit" class="btn btn-primary" <?= h($validCount === 0 ? 'disabled' : '') ?>><i class="bi bi-check-lg me-1"></i><?= h(t('case.lib.import_confirm')) ?></button>
                <a class="btn btn-outline-secondary" href="<?= h($listUrl) ?>"><?= h(t('case.lib.cancel')) ?></a>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="collapse mb-4 <?= h($importError !== '' ? 'show' : '') ?>" id="importBox">
    <div class="card card-body">
        <h2 class="h6"><?= h(t('case.lib.import_title')) ?></h2>
        <p class="small text-muted"><?= h(t('case.lib.import_help')) ?></p>
        <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-end">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="import_preview">
            <input type="hidden" name="archived" value="<?= h($showArchived ? '1' : '0') ?>">
            <div>
                <label class="form-label small mb-1" for="importFile"><?= h(t('case.lib.import_file')) ?></label>
                <input type="file" class="form-control" id="importFile" name="import_file" accept="application/json,.json" required>
            </div>
            <button type="submit" class="btn btn-outline-primary"><?= h(t('case.lib.import_check')) ?></button>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle admin-mobile-cards">
            <thead class="table-light">
                <tr>
                    <th width="40"></th>
                    <th><?= h(t('case.lib.col_title')) ?></th>
                    <th><?= h(t('case.lib.col_type')) ?></th>
                    <th><?= h(t('case.lib.col_difficulty')) ?></th>
                    <th><?= h(t('case.lib.col_language')) ?></th>
                    <th><?= h(t('case.lib.col_minutes')) ?></th>
                    <th><?= h(t('case.lib.col_status')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($cases)): ?>
                    <tr><td colspan="8" class="text-center py-4 text-muted"><?= h(t('case.lib.empty')) ?></td></tr>
                <?php else: ?>
                    <?php foreach ($cases as $c): ?>
                        <?php
                        $caseId = (int) $c['id'];
                        $isStarter = (int) $c['is_starter'] === 1;
                        $isArchived = !empty($c['archived_at']);
                        $stars = max(1, min(3, (int) $c['difficulty']));
                        ?>
                        <tr class="<?= h($isArchived ? 'table-secondary text-muted' : '') ?>">
                            <td data-label="<?= h(t('case.lib.select')) ?>">
                                <input type="checkbox" class="form-check-input" name="ids[]" value="<?= (int) $caseId ?>" form="exportForm" aria-label="<?= h(t('case.lib.select')) ?>">
                            </td>
                            <td data-label="<?= h(t('case.lib.col_title')) ?>"><strong><?= h($c['title']) ?></strong></td>
                            <td data-label="<?= h(t('case.lib.col_type')) ?>"><span class="badge bg-info text-dark"><?= h(trainingCaseTypeLabel((string) $c['type_tag'])) ?></span></td>
                            <td data-label="<?= h(t('case.lib.col_difficulty')) ?>" title="<?= h(t('case.lib.difficulty_n', ['n' => $stars])) ?>"><?= h(str_repeat('★', $stars) . str_repeat('☆', 3 - $stars)) ?></td>
                            <td data-label="<?= h(t('case.lib.col_language')) ?>"><?= h($c['language'] === 'en' ? t('case.lang.en') : t('case.lang.el')) ?></td>
                            <td data-label="<?= h(t('case.lib.col_minutes')) ?>"><?= (int) $c['est_minutes'] ?></td>
                            <td data-label="<?= h(t('case.lib.col_status')) ?>">
                                <?php if ($isStarter): ?><span class="badge bg-primary"><?= h(t('case.lib.badge_starter')) ?></span><?php endif; ?>
                                <?php if ($isArchived): ?><span class="badge bg-secondary"><?= h(t('case.lib.badge_archived')) ?></span><?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="training-case-edit.php?id=<?= (int) $caseId ?>"><i class="bi bi-pencil" aria-hidden="true"></i> <?= h(t('case.lib.act_edit')) ?></a>
                                <?= trainingCaseActionForm('duplicate', $caseId, t('case.lib.act_duplicate'), 'bi-files', 'btn-outline-secondary', $showArchived) ?>
                                <?= trainingCaseActionForm('export', $caseId, t('case.lib.act_export'), 'bi-download', 'btn-outline-secondary', $showArchived) ?>
                                <?php if ($isArchived): ?>
                                    <?= trainingCaseActionForm('unarchive', $caseId, t('case.lib.act_unarchive'), 'bi-arrow-counterclockwise', 'btn-outline-secondary', $showArchived) ?>
                                <?php else: ?>
                                    <?= trainingCaseActionForm('archive', $caseId, t('case.lib.act_archive'), 'bi-archive', 'btn-outline-secondary', $showArchived) ?>
                                <?php endif; ?>
                                <?= trainingCaseActionForm('delete', $caseId, t('case.lib.act_delete'), 'bi-trash', 'btn-outline-danger', $showArchived, t('case.lib.confirm_delete', ['title' => (string) $c['title']])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
