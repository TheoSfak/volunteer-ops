<?php
/**
 * VolunteerOps - Missing Person Search Guide
 * Standalone bilingual explainer (viewer's own language, via t()) for the
 * missing-person-specific Action Room tools — LPB search rings, exposure
 * urgency, weather compass — plus general search rules. Linked from the
 * missing-person card's header (war-room.php, missingPersonCard). Own
 * doctype, no shared header.php/sidebar, same structural shape as
 * mission-report-print.php — but bilingual (that file is deliberately
 * Greek-only for archival byte-stability, which doesn't apply to a live
 * reference page like this one).
 *
 * requireLogin() only, deliberately no extra role/participant gate: every
 * field volunteer needs this context just as much as managers do, same
 * reasoning the missing-person card's own header link uses.
 *
 * Optional ?mission_id= — when present, highlights that mission's own
 * subject_category and adds a link back to its Action Room. Absent, the
 * page still renders fully (generic tool explanations + current on/off
 * state for this org).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/lpb-rings.php';
requireLogin();

$missionId = (int) get('mission_id');
$missingPerson = $missionId ? loadMissingPersonForMission($missionId) : null;
$missionCategory = $missingPerson['subject_category'] ?? null;

$ringsOn = getSetting('search_rings_enabled', '0') === '1';
$exposureOn = getSetting('exposure_urgency_enabled', '0') === '1';
$weatherOn = getSetting('weather_map_compass_enabled', '0') === '1';

$viewerLang = getCurrentUser()['language'] ?? DEFAULT_LANGUAGE;

// Server-side equivalent of war-room.php's jsLocale-based toLocaleString()
// formatting for the ring table — same "one decimal, locale separator" idea.
function formatKmForGuide(int $meters, string $lang): string {
    $km = round($meters / 1000, 1);
    $formatted = number_format($km, 1, $lang === 'en' ? '.' : ',', '');
    return $formatted;
}
?><!DOCTYPE html>
<html lang="<?= h($viewerLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('guide.page_title') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet" integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/LbrixFETvWa5a6sESd" crossorigin="anonymous">
    <style>
        body { background: #f4f4f2; padding: 24px 16px 60px; }
        .guide-wrap { max-width: 840px; margin: 0 auto; }
        .guide-hero { background: linear-gradient(135deg, #172554, #b91c1c); color: #fff; border-radius: 16px; padding: 28px 30px; margin-bottom: 20px; }
        .guide-hero h1 { font-size: 1.5rem; font-weight: 800; margin: 0 0 6px; }
        .guide-hero p { margin: 0; opacity: .92; font-size: .95rem; }
        .guide-card { background: #fff; border: 1px solid #eee; border-radius: 14px; padding: 22px 24px; margin-bottom: 16px; }
        .guide-card h2 { font-size: 1.15rem; font-weight: 800; margin-bottom: 10px; color: #172554; }
        .guide-status { display: inline-block; font-size: .8rem; font-weight: 700; padding: 3px 10px; border-radius: 999px; margin-bottom: 10px; }
        .guide-status.on { background: #dcfce7; color: #166534; }
        .guide-status.off { background: #f3f4f6; color: #4b5563; }
        .guide-caveat { background: #fef3c7; border: 1px solid #fde68a; border-radius: 10px; padding: 10px 14px; font-size: .88rem; color: #78350f; margin-top: 10px; }
        table.guide-ring-table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: .9rem; }
        table.guide-ring-table th, table.guide-ring-table td { padding: 7px 10px; border-bottom: 1px solid #eee; text-align: center; }
        table.guide-ring-table th:first-child, table.guide-ring-table td:first-child { text-align: left; }
        table.guide-ring-table tr.guide-ring-highlight { background: #ede9fe; font-weight: 700; }
        .guide-rules li { margin-bottom: 8px; }
        .guide-back { display: inline-block; margin-bottom: 16px; color: #fff; text-decoration: none; font-size: .9rem; }
        .guide-back:hover { text-decoration: underline; color: #fff; }
    </style>
</head>
<body>
<div class="guide-wrap">
    <div class="guide-hero">
        <?php if ($missionId): ?>
        <a class="guide-back" href="<?= h(rtrim(BASE_URL, '/')) ?>/war-room.php?id=<?= $missionId ?>"><?= t('guide.back_to_action_room') ?></a>
        <?php endif; ?>
        <h1><i class="bi bi-person-bounding-box me-1"></i> <?= t('guide.page_title') ?></h1>
        <p><?= t('guide.intro') ?></p>
    </div>

    <div class="guide-card">
        <h2><i class="bi bi-bullseye me-1"></i> <?= t('guide.rings_section_title') ?></h2>
        <span class="guide-status <?= $ringsOn ? 'on' : 'off' ?>"><?= t($ringsOn ? 'guide.rings_status_on' : 'guide.rings_status_off') ?></span>
        <p><?= t('guide.rings_explainer') ?></p>

        <?php if ($missionId): ?>
            <?php if ($missionCategory && isset(LPB_RING_TABLE[$missionCategory])): ?>
            <p><strong><?= t('guide.mission_category_label') ?></strong> <?= h(lpbCategoryLabel($missionCategory, $viewerLang)) ?></p>
            <?php else: ?>
            <p class="text-muted"><?= t('guide.mission_category_missing') ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <table class="guide-ring-table">
            <thead>
                <tr>
                    <th><?= t('guide.rings_table_category') ?></th>
                    <th><?= t('guide.rings_table_25') ?></th>
                    <th><?= t('guide.rings_table_50') ?></th>
                    <th><?= t('guide.rings_table_75') ?></th>
                    <th><?= t('guide.rings_table_95') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (LPB_RING_TABLE as $key => $radii): ?>
                <tr<?= $key === $missionCategory ? ' class="guide-ring-highlight"' : '' ?>>
                    <td><?= h(lpbCategoryLabel($key, $viewerLang)) ?></td>
                    <td><?= h(formatKmForGuide($radii[0], $viewerLang)) ?> <?= $viewerLang === 'en' ? 'km' : 'χλμ' ?></td>
                    <td><?= h(formatKmForGuide($radii[1], $viewerLang)) ?> <?= $viewerLang === 'en' ? 'km' : 'χλμ' ?></td>
                    <td><?= h(formatKmForGuide($radii[2], $viewerLang)) ?> <?= $viewerLang === 'en' ? 'km' : 'χλμ' ?></td>
                    <td><?= h(formatKmForGuide($radii[3], $viewerLang)) ?> <?= $viewerLang === 'en' ? 'km' : 'χλμ' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="guide-caveat"><i class="bi bi-exclamation-triangle me-1"></i> <?= t('guide.rings_caveat') ?></div>
    </div>

    <div class="guide-card">
        <h2><i class="bi bi-thermometer-half me-1"></i> <?= t('guide.exposure_section_title') ?></h2>
        <span class="guide-status <?= $exposureOn ? 'on' : 'off' ?>"><?= t($exposureOn ? 'guide.exposure_status_on' : 'guide.exposure_status_off') ?></span>
        <p><?= t('guide.exposure_explainer') ?></p>
        <div class="guide-caveat"><i class="bi bi-exclamation-triangle me-1"></i> <?= t('guide.exposure_caveat') ?></div>
    </div>

    <div class="guide-card">
        <h2><i class="bi bi-compass me-1"></i> <?= t('guide.weather_section_title') ?></h2>
        <span class="guide-status <?= $weatherOn ? 'on' : 'off' ?>"><?= t($weatherOn ? 'guide.weather_status_on' : 'guide.weather_status_off') ?></span>
        <p><?= t('guide.weather_explainer') ?></p>
    </div>

    <div class="guide-card">
        <h2><i class="bi bi-signpost-split me-1"></i> <?= t('guide.rules_section_title') ?></h2>
        <ul class="guide-rules">
            <li><?= t('guide.rules_pls') ?></li>
            <li><?= t('guide.rules_probability') ?></li>
            <li><?= t('guide.rules_direction') ?></li>
            <li><?= t('guide.rules_urgency') ?></li>
            <li><?= t('guide.rules_coverage') ?></li>
        </ul>
    </div>

    <div class="guide-card">
        <h2><i class="bi bi-people me-1"></i> <?= t('guide.category_rules_section_title') ?></h2>
        <p class="text-muted small"><?= t('guide.category_rules_intro') ?></p>
        <div class="accordion" id="categoryRulesAccordion">
            <?php foreach (LPB_RING_TABLE as $catKey => $catRadii): $isCurrentCat = $catKey === $missionCategory; ?>
            <div class="accordion-item">
                <h3 class="accordion-header">
                    <button class="accordion-button<?= $isCurrentCat ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#catRule-<?= h($catKey) ?>" aria-expanded="<?= $isCurrentCat ? 'true' : 'false' ?>">
                        <?= h(lpbCategoryLabel($catKey, $viewerLang)) ?>
                        <?php if ($isCurrentCat): ?><span class="badge bg-primary ms-2"><?= t('guide.category_rules_current_badge') ?></span><?php endif; ?>
                    </button>
                </h3>
                <div id="catRule-<?= h($catKey) ?>" class="accordion-collapse collapse<?= $isCurrentCat ? ' show' : '' ?>" data-bs-parent="#categoryRulesAccordion">
                    <div class="accordion-body">
                        <ul class="guide-rules mb-0">
                            <li><?= t('guide.category_rules.' . $catKey . '.1') ?></li>
                            <li><?= t('guide.category_rules.' . $catKey . '.2') ?></li>
                            <li><?= t('guide.category_rules.' . $catKey . '.3') ?></li>
                        </ul>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="guide-caveat mt-3"><i class="bi bi-exclamation-triangle me-1"></i> <?= t('guide.category_rules_caveat') ?></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
</body>
</html>
