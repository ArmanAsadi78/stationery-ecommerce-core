<?php

declare(strict_types=1);

/**
 * =====================================================================
 * High-Quality WebP Placeholder Generator for Stationery Products
 * Creates 400x400 crisp, modern WebP images with distinct stationery art
 * =====================================================================
 */

$targetDir = dirname(__DIR__) . '/public/assets/images/products';
$imagesDir = dirname(__DIR__) . '/public/assets/images';

if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}
if (!is_dir($imagesDir)) {
    mkdir($imagesDir, 0777, true);
}

function hexToRgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    return [
        (int) hexdec(substr($hex, 0, 2)),
        (int) hexdec(substr($hex, 2, 2)),
        (int) hexdec(substr($hex, 4, 2)),
    ];
}

function drawRoundedRect($img, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
{
    imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    imagefilledellipse($img, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}

function generateProductImage(string $filePath, string $brand, string $title, string $accentHex, string $type): void
{
    $width = 400;
    $height = 400;
    $img = imagecreatetruecolor($width, $height);
    imagesavealpha($img, true);

    // Background gradient: from very soft tinted white to pure white
    [$ar, $ag, $ab] = hexToRgb($accentHex);
    $bgTop = imagecolorallocate($img, 248, 250, 252);
    $bgBottom = imagecolorallocate($img, 241, 245, 249);
    $accentColor = imagecolorallocate($img, $ar, $ag, $ab);
    $darkColor = imagecolorallocate($img, 15, 23, 42);
    $mutedColor = imagecolorallocate($img, 100, 116, 139);
    $whiteColor = imagecolorallocate($img, 255, 255, 255);
    $lightAccent = imagecolorallocatealpha($img, $ar, $ag, $ab, 105);

    // Fill background
    for ($y = 0; $y < $height; $y++) {
        $ratio = $y / $height;
        $r = (int) (248 * (1 - $ratio) + 241 * $ratio);
        $g = (int) (250 * (1 - $ratio) + 245 * $ratio);
        $b = (int) (252 * (1 - $ratio) + 249 * $ratio);
        $col = imagecolorallocate($img, $r, $g, $b);
        imageline($img, 0, $y, $width, $y, $col);
    }

    // Outer subtle border
    $border = imagecolorallocate($img, 226, 232, 240);
    imagerectangle($img, 0, 0, $width - 1, $height - 1, $border);

    // Decorative soft circular glow
    imagefilledellipse($img, 200, 180, 220, 220, $lightAccent);
    imagefilledellipse($img, 200, 180, 180, 180, $whiteColor);

    // Draw customized stationery icon based on type
    switch ($type) {
        case 'pen': // Schneider One Business
            // Sleek Pen Body
            drawRoundedRect($img, 186, 70, 214, 250, 6, $darkColor);
            // Grip
            imagefilledrectangle($img, 186, 210, 214, 250, $accentColor);
            // Tip cone
            $points = [186, 250, 214, 250, 200, 280];
            imagefilledpolygon($img, $points, $mutedColor);
            // Nib tip
            imagefilledellipse($img, 200, 283, 6, 6, $accentColor);
            // Clip
            imagefilledrectangle($img, 212, 85, 218, 155, $accentColor);
            break;

        case 'pencil': // rOtring Tikky
            // Mechanical pencil body
            drawRoundedRect($img, 188, 70, 212, 245, 4, $mutedColor);
            // Iconic Red Ring of rOtring!
            $rotringRed = imagecolorallocate($img, 220, 38, 38);
            imagefilledrectangle($img, 186, 120, 214, 128, $rotringRed);
            // Metal Grip
            imagefilledrectangle($img, 188, 205, 212, 245, $accentColor);
            // Metal Tip Cone
            $points = [188, 245, 212, 245, 200, 282];
            imagefilledpolygon($img, $points, $mutedColor);
            // Thin Lead pipe
            imagefilledrectangle($img, 199, 282, 201, 292, $darkColor);
            // Push button eraser cap
            imagefilledrectangle($img, 192, 58, 208, 70, $mutedColor);
            break;

        case 'art': // Faber-Castell 24
            // Colored pencil box
            drawRoundedRect($img, 130, 95, 270, 265, 12, $accentColor);
            drawRoundedRect($img, 140, 105, 260, 255, 8, $whiteColor);
            // Draw pencil tips sticking out
            $palette = [
                imagecolorallocate($img, 239, 68, 68),
                imagecolorallocate($img, 249, 115, 22),
                imagecolorallocate($img, 234, 179, 8),
                imagecolorallocate($img, 34, 197, 94),
                imagecolorallocate($img, 59, 130, 246),
                imagecolorallocate($img, 168, 85, 247),
            ];
            $px = 150;
            foreach ($palette as $col) {
                // Pencil body
                imagefilledrectangle($img, $px, 120, $px + 14, 210, $col);
                // Sharpened wood cone
                $wood = imagecolorallocate($img, 254, 215, 170);
                imagefilledpolygon($img, [$px, 120, $px + 14, 120, $px + 7, 95], $wood);
                // Colored lead
                imagefilledpolygon($img, [$px + 4, 105, $px + 10, 105, $px + 7, 95], $col);
                $px += 18;
            }
            break;

        case 'notebook': // PAPCO Notebook
            // Hardcover notebook
            drawRoundedRect($img, 135, 80, 265, 270, 10, $accentColor);
            drawRoundedRect($img, 145, 88, 257, 262, 6, $whiteColor);
            // Lined pages
            $lineColor = imagecolorallocate($img, 203, 213, 225);
            for ($ly = 115; $ly < 240; $ly += 18) {
                imageline($img, 160, $ly, 245, $ly, $lineColor);
            }
            // Spiral Wire Binding rings on right
            $wireColor = imagecolorallocate($img, 71, 85, 105);
            for ($wy = 95; $wy <= 255; $wy += 16) {
                imagefilledellipse($img, 142, $wy, 10, 6, $wireColor);
                imagefilledellipse($img, 142, $wy, 6, 2, $whiteColor);
            }
            break;

        case 'pack': // Panter DP105 4-Pack
            // 4 Pens grouped (Blue, Black, Red, Green)
            $colors = [
                imagecolorallocate($img, 37, 99, 235),  // Blue
                imagecolorallocate($img, 30, 41, 59),   // Black
                imagecolorallocate($img, 220, 38, 38),  // Red
                imagecolorallocate($img, 22, 163, 74),  // Green
            ];
            $offsets = [150, 175, 200, 225];
            foreach ($colors as $idx => $penColor) {
                $ox = $offsets[$idx];
                drawRoundedRect($img, $ox, 85 + ($idx % 2 * 12), $ox + 18, 260 + ($idx % 2 * 12), 4, $penColor);
                imagefilledpolygon($img, [$ox, 260 + ($idx % 2 * 12), $ox + 18, 260 + ($idx % 2 * 12), $ox + 9, 282 + ($idx % 2 * 12)], $mutedColor);
            }
            break;

        default: // Fallback / Generic Placeholder
            drawRoundedRect($img, 150, 95, 250, 255, 12, $accentColor);
            drawRoundedRect($img, 160, 105, 240, 245, 8, $whiteColor);
            $points = [175, 220, 225, 220, 200, 140];
            imagefilledpolygon($img, $points, $lightAccent);
            imagefilledellipse($img, 200, 130, 20, 20, $accentColor);
            break;
    }

    // Top Brand Badge
    drawRoundedRect($img, 110, 22, 290, 52, 15, $whiteColor);
    imagerectangle($img, 110, 22, 290, 52, $border);
    $font = 4; // built-in GD font
    $brandText = strtoupper($brand);
    $textWidth = imagefontwidth($font) * strlen($brandText);
    $tx = (int) (200 - ($textWidth / 2));
    imagestring($img, $font, $tx, 30, $brandText, $darkColor);

    // Bottom Model Title Ribbon
    drawRoundedRect($img, 25, 335, 375, 380, 10, $whiteColor);
    imagerectangle($img, 25, 335, 375, 380, $border);
    $titleFont = 3;
    $titleDisplay = mb_strimwidth($title, 0, 42, "...");
    $tWidth = imagefontwidth($titleFont) * strlen($titleDisplay);
    $tx2 = (int) (200 - ($tWidth / 2));
    imagestring($img, $titleFont, max(30, $tx2), 350, $titleDisplay, $mutedColor);

    // Save as WebP
    imagewebp($img, $filePath, 90);
    imagedestroy($img);
    echo "       ✓ Generated: " . basename($filePath) . "\n";
}

echo "========================================================\n";
echo " Stationery WebP Placeholder Generator\n";
echo " Target: public/assets/images/products/\n";
echo "========================================================\n\n";

// 1. Schneider One Business
generateProductImage(
    $targetDir . '/schneider-one-business.webp',
    'Schneider Germany',
    'One Business 0.6mm Pen',
    '#0284c7',
    'pen'
);

// 2. rOtring Tikky 0.5
generateProductImage(
    $targetDir . '/rotring-tikky-05.webp',
    'rOtring Germany',
    'Tikky 0.5mm Mechanical Pencil',
    '#dc2626',
    'pencil'
);

// 3. Faber-Castell 24
generateProductImage(
    $targetDir . '/faber-castell-24.webp',
    'Faber-Castell',
    'Classic 24 Colour Pencils',
    '#16a34a',
    'art'
);

// 4. PAPCO 100 Sheets Notebook
generateProductImage(
    $targetDir . '/papco-notebook-100.webp',
    'PAPCO Original',
    'Spiral Hardcover Notebook 100',
    '#4f46e5',
    'notebook'
);

// 5. Panter Semi-Gel 4-Pack
generateProductImage(
    $targetDir . '/panter-dp105-4pack.webp',
    'Panter Pen',
    'DP-105 Semi-Gel 4-Pack',
    '#f97316',
    'pack'
);

// 6. Generic Default Fallback Placeholder
generateProductImage(
    $imagesDir . '/placeholder.webp',
    'TAHRIRCHI',
    'Stationery Product',
    '#3b82f6',
    'default'
);

echo "\n All WebP placeholder images successfully generated!\n";
