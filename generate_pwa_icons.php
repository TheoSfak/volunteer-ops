<?php
/**
 * Generate PWA icons for VolunteerOps
 * Run once: php generate_pwa_icons.php
 * Creates icons in assets/icons/ with the VO shield design
 */

$sizes = [72, 96, 128, 144, 152, 192, 384, 512];
$outDir = __DIR__ . '/assets/icons';

if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

foreach ($sizes as $size) {
    // Standard icon
    $img = createIcon($size, false);
    imagepng($img, "$outDir/icon-{$size}.png");
    imagedestroy($img);
    echo "Created icon-{$size}.png\n";
}

// Maskable icon (512 only) - extra padding for safe zone
$img = createIcon(512, true);
imagepng($img, "$outDir/icon-maskable-512.png");
imagedestroy($img);
echo "Created icon-maskable-512.png\n";

echo "\nAll icons generated in $outDir\n";

function createIcon(int $size, bool $maskable): GdImage {
    $img = imagecreatetruecolor($size, $size);
    imagesavealpha($img, true);

    // Colors
    $bgDark  = imagecolorallocate($img, 30, 60, 114);   // #1e3c72
    $bgMid   = imagecolorallocate($img, 42, 82, 152);   // #2a5298
    $white   = imagecolorallocate($img, 255, 255, 255);
    $gold    = imagecolorallocate($img, 255, 215, 0);    // #FFD700
    $accent  = imagecolorallocate($img, 102, 126, 234);  // #667eea (accent)

    // Fill background with dark blue
    imagefill($img, 0, 0, $bgDark);

    // Gradient effect - draw horizontal bands
    for ($y = 0; $y < $size; $y++) {
        $ratio = $y / $size;
        $r = (int)(30 + (42 - 30) * sin($ratio * M_PI));
        $g = (int)(60 + (82 - 60) * sin($ratio * M_PI));
        $b = (int)(114 + (152 - 114) * sin($ratio * M_PI));
        $color = imagecolorallocate($img, $r, $g, $b);
        imageline($img, 0, $y, $size - 1, $y, $color);
    }

    // Calculate safe zone for content
    $padding = $maskable ? (int)($size * 0.2) : (int)($size * 0.1);
    $inner = $size - 2 * $padding;
    $cx = $size / 2;
    $cy = $size / 2;

    // Draw shield outline (using ellipse arcs as approximation)
    $shieldW = (int)($inner * 0.7);
    $shieldH = (int)($inner * 0.85);
    $shieldTop = $cy - $shieldH * 0.45;
    $shieldBot = $cy + $shieldH * 0.55;

    // Shield body - filled polygon
    $shieldPoints = [];
    $steps = 40;
    // Top left curve
    for ($i = 0; $i <= $steps / 4; $i++) {
        $t = $i / ($steps / 4);
        $x = $cx - $shieldW / 2 + $shieldW * 0.15 * (1 - cos($t * M_PI / 2));
        $y = $shieldTop + $shieldH * 0.3 * sin($t * M_PI / 2);
        $shieldPoints[] = (int)$x;
        $shieldPoints[] = (int)$y;
    }
    // Left side going down to point
    for ($i = 0; $i <= $steps / 4; $i++) {
        $t = $i / ($steps / 4);
        $x = $cx - $shieldW / 2 + $shieldW * 0.15 + ($shieldW / 2 - $shieldW * 0.15) * $t;
        $y = $shieldTop + $shieldH * 0.3 + ($shieldBot - $shieldTop - $shieldH * 0.3) * $t;
        $shieldPoints[] = (int)$x;
        $shieldPoints[] = (int)$y;
    }
    // Right side going up from point
    for ($i = 0; $i <= $steps / 4; $i++) {
        $t = $i / ($steps / 4);
        $x = $cx + ($shieldW / 2 - $shieldW * 0.15) * (1 - $t) + ($shieldW / 2 - $shieldW * 0.15) * $t;
        $y = $shieldBot - ($shieldBot - $shieldTop - $shieldH * 0.3) * $t;
        $shieldPoints[] = (int)$x;
        $shieldPoints[] = (int)$y;
    }
    // Top right curve
    for ($i = 0; $i <= $steps / 4; $i++) {
        $t = $i / ($steps / 4);
        $x = $cx + $shieldW / 2 - $shieldW * 0.15 * (1 - sin($t * M_PI / 2));
        $y = $shieldTop + $shieldH * 0.3 * (1 - $t);
        $shieldPoints[] = (int)$x;
        $shieldPoints[] = (int)$y;
    }

    // Draw filled shield with accent color border
    imagesetthickness($img, max(2, (int)($size * 0.005)));
    imagefilledpolygon($img, $shieldPoints, count($shieldPoints) / 2, $accent);

    // Smaller inner shield
    $innerScale = 0.88;
    $innerPoints = [];
    for ($i = 0; $i < count($shieldPoints); $i += 2) {
        $innerPoints[] = (int)($cx + ($shieldPoints[$i] - $cx) * $innerScale);
        $innerPoints[] = (int)($cy + ($shieldPoints[$i + 1] - $cy) * $innerScale);
    }
    imagefilledpolygon($img, $innerPoints, count($innerPoints) / 2, $bgDark);

    // Draw "V" letter in the center
    $fontSize = (int)($inner * 0.35);
    $letterX = $cx;
    $letterY = $cy + (int)($inner * 0.05);

    // "V" with thick lines
    $thick = max(3, (int)($size * 0.04));
    imagesetthickness($img, $thick);

    $vTop = $letterY - $fontSize / 2;
    $vBot = $letterY + $fontSize / 3;
    $vLeft = $letterX - $fontSize / 3;
    $vRight = $letterX + $fontSize / 3;

    imageline($img, (int)$vLeft, (int)$vTop, (int)$letterX, (int)$vBot, $gold);
    imageline($img, (int)$letterX, (int)$vBot, (int)$vRight, (int)$vTop, $gold);

    // Small checkmark accent below V
    $checkSize = (int)($inner * 0.08);
    $checkY = $vBot + $checkSize * 1.5;
    imagesetthickness($img, max(2, (int)($size * 0.02)));
    imageline($img, (int)($cx - $checkSize), (int)$checkY, (int)$cx, (int)($checkY + $checkSize * 0.6), $white);
    imageline($img, (int)$cx, (int)($checkY + $checkSize * 0.6), (int)($cx + $checkSize * 1.2), (int)($checkY - $checkSize * 0.4), $white);

    imagesetthickness($img, 1);

    return $img;
}
