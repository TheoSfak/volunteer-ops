<?php
/**
 * VolunteerOps - Left menu theme
 *
 * One source of truth for the sidebar's sections, their colours, and how a
 * chosen colour is turned into something that can actually be read. Both
 * includes/header.php (which renders the menu) and settings.php (which lets an
 * admin change it) read from here, so the two can never disagree about which
 * sections exist or what a colour resolves to.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * The sections, in menu order.
 *
 * Keys are the data-sec attributes carried by header.php's heading divs, so
 * adding a section to the menu means adding it in both places. 'general' is
 * the untitled block of entries above the first heading; it takes a colour
 * like the rest but never folds, because it holds the dashboard link.
 */
function sidebarSections(): array
{
    return [
        'general'        => 'Κορυφή (χωρίς τίτλο)',
        'missions'       => 'Αποστολές',
        'manage'         => 'Διαχείριση',
        'training'       => 'Εκπαίδευση',
        'training-admin' => 'Διαχείριση Εκπαίδευσης',
        'admin'          => 'Διοίκηση',
        'inventory'      => 'Απόθεμα',
        'gamification'   => 'Gamification',
        'citizens'       => 'Πολίτες',
        'comms'          => 'Επικοινωνία',
        'system'         => 'Σύστημα',
    ];
}

/**
 * Shipped palette. Deliberately no blues: the menu's own background is navy,
 * and a blue section edge disappears into it.
 */
function sidebarDefaultPalette(): array
{
    return [
        'general'        => '#e2e8f0',
        'missions'       => '#4ade80',
        'manage'         => '#2dd4bf',
        'training'       => '#fb923c',
        'training-admin' => '#d97706',
        'admin'          => '#c084fc',
        'inventory'      => '#fde047',
        'gamification'   => '#f472b6',
        'citizens'       => '#a3e635',
        'comms'          => '#fb7185',
        'system'         => '#94a3b8',
    ];
}

/**
 * The lightest stop of the sidebar's gradient (#1e3c72 -> #2a5298 -> #1e3c72).
 * Contrast is measured against this one because light text on the lighter
 * stop is the worst case; anything that passes here passes further up and
 * down the menu too.
 */
function sidebarGround(): string
{
    return '#2a5298';
}

/**
 * The admin's palette, falling back per-entry to the shipped one.
 *
 * Per-entry rather than all-or-nothing on purpose: a stored palette written
 * before a new section existed should still colour every section it does
 * know about, and one bad value should not discard the other ten.
 */
function sidebarPalette(): array
{
    $defaults = sidebarDefaultPalette();
    $stored = json_decode((string) getSetting('sidebar_palette', ''), true);

    if (!is_array($stored)) {
        return $defaults;
    }

    $palette = [];
    foreach ($defaults as $key => $fallback) {
        $candidate = $stored[$key] ?? null;
        $palette[$key] = (is_string($candidate) && sidebarIsHex($candidate))
            ? strtolower($candidate)
            : $fallback;
    }

    return $palette;
}

/**
 * How the menu opens: 'current' shows only the section holding the page you
 * are on, 'expanded' shows everything, 'collapsed' folds everything. Whatever
 * a person has since toggled themselves wins over this in their own browser.
 */
function sidebarDefaultState(): string
{
    $state = (string) getSetting('sidebar_default_state', 'current');

    return in_array($state, ['current', 'expanded', 'collapsed'], true) ? $state : 'current';
}

function sidebarIsHex(string $value): bool
{
    return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $value);
}

function sidebarHexToRgb(string $hex): array
{
    $hex = ltrim($hex, '#');

    return [
        (int) hexdec(substr($hex, 0, 2)),
        (int) hexdec(substr($hex, 2, 2)),
        (int) hexdec(substr($hex, 4, 2)),
    ];
}

function sidebarRgbToHex(array $rgb): string
{
    return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
}

/** "74,222,128" - the form CSS needs so rgba(var(--sc), .13) works. */
function sidebarRgbTriplet(string $hex): string
{
    return implode(',', sidebarHexToRgb($hex));
}

/** Composite $rgb at $alpha over $over. */
function sidebarBlend(array $rgb, float $alpha, array $over): array
{
    $out = [];
    foreach ([0, 1, 2] as $i) {
        $out[$i] = (int) round($rgb[$i] * $alpha + $over[$i] * (1 - $alpha));
    }

    return $out;
}

function sidebarLuminance(array $rgb): float
{
    $linear = [];
    foreach ($rgb as $channel) {
        $c = $channel / 255;
        $linear[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
    }

    return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
}

function sidebarContrast(array $a, array $b): float
{
    $la = sidebarLuminance($a);
    $lb = sidebarLuminance($b);

    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/**
 * Everything the tone derivation depends on, in one place.
 *
 * The three alphas mirror .sidebar-sec, .sidebar-sec-h and the strip's
 * darkening layer in header.php - change one there and change it here, or
 * every derived tone silently drifts. settings.php also reads this to draw its
 * live preview, so the preview and the real menu cannot disagree about the
 * numbers; only the walk in sidebarReadableTone() is written twice.
 */
function sidebarToneConfig(): array
{
    return [
        'ground' => sidebarGround(),
        'body'   => 0.13,
        'strip'  => 0.16,
        'darken' => 0.18,
        'target' => 4.5,
        'steps'  => 25,
    ];
}

/**
 * What a section's heading actually sits on.
 *
 * Three layers, and missing any of them is how contrast gets overestimated:
 * the zone's own fill, the heading strip's further tint, and the black the
 * strip lays over both. Measuring against the strip alone reads about half a
 * point too generous.
 */
function sidebarHeadingBand(array $rgb): array
{
    $cfg    = sidebarToneConfig();
    $ground = sidebarHexToRgb($cfg['ground']);
    $body   = sidebarBlend($rgb, $cfg['body'], $ground);
    $strip  = sidebarBlend($rgb, $cfg['strip'], $body);

    return sidebarBlend([0, 0, 0], $cfg['darken'], $strip);
}

/**
 * Lighten a section's colour until it can be read on its own heading band.
 *
 * An admin picking a colour is choosing an identity for a section, not
 * auditing contrast, and the saturated end of most hues is unreadable against
 * this navy - the shipped palette measured as low as 2,26:1 before any of this
 * was applied to it. So the picker stores one colour and this derives the
 * second: the first step toward white that clears 4.5:1, which is what a
 * 0.7rem heading needs. Walking in steps rather than solving directly keeps
 * the result the lightest tone that still carries the hue, instead of jumping
 * to a white that carries none.
 */
function sidebarReadableTone(string $hex): string
{
    $cfg  = sidebarToneConfig();
    $rgb  = sidebarHexToRgb($hex);
    $band = sidebarHeadingBand($rgb);

    for ($step = 0; $step <= $cfg['steps']; $step++) {
        $t = $step / $cfg['steps'];
        $candidate = [
            (int) round($rgb[0] + (255 - $rgb[0]) * $t),
            (int) round($rgb[1] + (255 - $rgb[1]) * $t),
            (int) round($rgb[2] + (255 - $rgb[2]) * $t),
        ];

        if (sidebarContrast($candidate, $band) >= $cfg['target']) {
            return sidebarRgbToHex($candidate);
        }
    }

    return '#ffffff';
}

/**
 * Every section resolved to the two tones the CSS wants: --sc fills, --sl is
 * read. Returned as "r,g,b" triplets, ready to drop into custom properties.
 */
function sidebarResolvedPalette(): array
{
    $resolved = [];
    foreach (sidebarPalette() as $key => $hex) {
        $resolved[$key] = [
            'hex'  => $hex,
            'fill' => sidebarRgbTriplet($hex),
            'read' => sidebarRgbTriplet(sidebarReadableTone($hex)),
        ];
    }

    return $resolved;
}
