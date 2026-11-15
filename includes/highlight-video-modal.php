<?php
/**
 * VolunteerOps - Mission Highlight Video modal
 *
 * Shared by war-room.php and mission-archive.php (identical markup; the
 * caller is responsible for the isSystemAdmin() gate around the include and
 * for $missionId already being in scope). The actual rendering and upload
 * logic live in assets/js/mission-highlight-video.js (canvas/MediaRecorder)
 * and assets/js/mission-highlight-video-ui.js (this modal's wiring) -
 * mission-highlight-video.php is the storage endpoint.
 */
if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}
?>
<div class="modal fade" id="highlightVideoModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-film me-1"></i><?= t('video.modal_title') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="hvUnsupportedNotice" class="alert alert-warning d-none"><?= t('video.browser_unsupported') ?></div>
                <div id="hvNotGeneratedNotice" class="alert alert-light border d-none"><?= t('video.not_generated_yet') ?></div>

                <div id="hvExistingNotice" class="alert alert-light border d-none">
                    <video id="hvExistingPlayer" controls style="max-width:100%; max-height:260px;" class="d-block mb-2"></video>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span id="hvExistingLabel" class="small text-muted" data-template="<?= h(t('video.last_generated_by')) ?>"></span>
                        <div class="d-flex gap-2">
                            <a id="hvDownloadLink" class="btn btn-sm btn-outline-secondary" download><i class="bi bi-download me-1"></i><?= t('video.download_btn') ?></a>
                            <button type="button" id="hvRegenerateBtn" class="btn btn-sm btn-primary"><?= t('video.regenerate_btn') ?></button>
                        </div>
                    </div>
                </div>

                <div id="hvBuilderSection">
                    <h6 class="text-muted small text-uppercase fw-semibold mb-2"><?= t('video.select_media') ?></h6>
                    <div id="hvMediaGrid" class="d-flex flex-wrap gap-2 mb-3" style="max-height:220px; overflow-y:auto;" data-empty-label="<?= h(t('media.empty')) ?>">
                        <span class="text-muted small"><?= t('common.loading') ?></span>
                    </div>
                    <div id="hvTooManyWarning" class="alert alert-warning py-2 small d-none"><?= t('video.too_many_media_warning') ?></div>

                    <h6 class="text-muted small text-uppercase fw-semibold mb-2"><?= t('video.select_music') ?></h6>
                    <div id="hvMusicList" class="list-group mb-3" style="max-height:220px; overflow-y:auto;">
                        <span class="text-muted small p-2"><?= t('common.loading') ?></span>
                    </div>

                    <div class="mb-3">
                        <div class="btn-group" role="group">
                            <input type="radio" class="btn-check" name="hvOrientation" id="hvOrientationLandscape" value="landscape" checked>
                            <label class="btn btn-outline-secondary btn-sm" for="hvOrientationLandscape"><i class="bi bi-aspect-ratio me-1"></i><?= t('video.orientation_landscape') ?></label>
                            <input type="radio" class="btn-check" name="hvOrientation" id="hvOrientationPortrait" value="portrait">
                            <label class="btn btn-outline-secondary btn-sm" for="hvOrientationPortrait"><i class="bi bi-phone me-1"></i><?= t('video.orientation_portrait') ?></label>
                        </div>
                    </div>

                    <div id="hvErrorBox"
                         class="alert alert-danger py-2 small d-none"
                         data-msg-no-media="<?= h(t('video.no_media_selected_error')) ?>"
                         data-msg-save-failed="<?= h(t('video.save_failed')) ?>"
                         data-msg-unsupported="<?= h(t('video.browser_unsupported')) ?>"
                         data-msg-backgrounded="<?= h(t('video.keep_tab_open_warning')) ?>"></div>
                    <div id="hvKeepOpenWarning" class="alert alert-info py-2 small d-none"><?= t('video.keep_tab_open_warning') ?></div>

                    <div id="hvProgressWrap" class="mb-3 d-none">
                        <div class="progress" style="height:20px;">
                            <div id="hvProgressBar" class="progress-bar" role="progressbar" style="width:0%"></div>
                        </div>
                        <div id="hvProgressLabel" class="small text-muted mt-1" data-template="<?= h(t('video.generating_progress')) ?>"></div>
                    </div>

                    <button type="button" id="hvGenerateBtn" class="btn btn-primary"><i class="bi bi-magic me-1"></i><?= t('video.generate_action') ?></button>
                </div>
            </div>
        </div>
    </div>
</div>
