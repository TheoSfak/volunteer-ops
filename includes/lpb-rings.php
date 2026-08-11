<?php
/**
 * "LPB search rings" — statistical concentric search-radius rings drawn on
 * the Action Room live map around a missing person's last-seen position,
 * inspired by the "Lost Person Behavior" (LPB) ring tools professional SAR
 * teams reference (Koester/ISRID-style statistical distance-from-IPP data).
 *
 * IMPORTANT — READ BEFORE EDITING THE NUMBERS BELOW:
 * The category list and distances in LPB_RING_TABLE are illustrative
 * placeholders, hand-compiled from general, widely-published SAR training
 * ranges. They are NOT a reproduction of any proprietary dataset (e.g. the
 * Koester ISRID database behind lpb.findsar.com), and they have NOT been
 * reviewed by anyone with formal SAR search-planning training. Treat this
 * table as a rough starting point only. Before any organisation relies on
 * these rings operationally, someone with SAR search-planning background
 * should validate/replace the numbers — same posture this codebase already
 * takes with includes/weather.php's computeExposureUrgency(): a clearly
 * caveated planning aid, never presented as an authoritative answer. This is
 * also why the feature is gated behind 'search_rings_enabled' (settings.php,
 * default OFF) and why mission_missing_persons.subject_category is a free
 * VARCHAR, not an ENUM — the category set itself is expected to change.
 *
 * Public entry point: none needed at call time — war-room.php reads
 * LPB_RING_TABLE directly (both server-side for the category <select> and
 * client-side via json_encode(), so PHP and JS can never drift) and calls
 * lpbCategoryLabel() (includes/i18n.php) for display labels.
 */

// category slug => [r25, r50, r75, r95] distance-from-last-seen-point, in
// METERS, approximating the 25th/50th/75th/95th percentile of where similar
// past cases were eventually found.
define('LPB_RING_TABLE', [
    'child_1_6'     => [100,  250,  600,  1500],
    'child_7_12'    => [400,  1000, 2000, 4000],
    'despondent'    => [500,  1200, 2500, 6000],
    'dementia'      => [300,  800,  1600, 3500],
    'elderly'       => [400,  1000, 2000, 4500],
    'hiker'         => [800,  1600, 3500, 8000],
    'hunter'        => [1000, 2200, 4500, 9500],
    'climber_biker' => [1000, 2500, 5500, 12000],
    'other'         => [500,  1500, 3500, 8000],
]);

// Safety valve for computeMissionRingCoverage() (includes/functions-warroom.php)
// — above this radius, coverage % is simply not computed for that ring (no
// error, just no badge). Grid cell count is already bounded regardless of
// radius, but the covered-check cost scales with how many volunteer_pings
// fall inside the ring's bounding box, and a ring's bbox has zero admin
// discretion the way a hand-drawn sector's does — an uncapped 12km-radius
// ring on a well-attended multi-hour mission is a real synchronous-PHP-
// timeout risk, not a hypothetical one. 5000m keeps every category's 25%/50%
// ring meaningful and most categories' 75% ring too, while bounding
// worst-case bbox to 10km×10km. One named constant, easy to retune later.
define('RING_COVERAGE_MAX_RADIUS_METERS', 5000);

// Greek fallback labels, used by lpbCategoryLabel() only when no
// missing_person.subject_category.<key> translation key is set — kept here
// rather than config.php's other *_LABELS constants so the whole
// "provisional, needs SAR review" surface stays in one file.
define('LPB_CATEGORY_LABELS', [
    'child_1_6'     => 'Παιδί (1–6 ετών)',
    'child_7_12'    => 'Παιδί (7–12 ετών)',
    'despondent'    => 'Άτομο σε ψυχολογική κρίση',
    'dementia'      => 'Άτομο με άνοια / Alzheimer',
    'elderly'       => 'Ηλικιωμένος/η',
    'hiker'         => 'Πεζοπόρος',
    'hunter'        => 'Κυνηγός',
    'climber_biker' => 'Ορειβάτης / Ποδηλάτης βουνού',
    'other'         => 'Άλλο / άγνωστο',
]);
