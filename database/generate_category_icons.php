<?php

declare(strict_types=1);

/**
 * =====================================================================
 * Pastel Category Icon Generator (160x160 px) using GD
 * Generates aesthetic pastel icons for popular categories.
 * =====================================================================
 */

$categoriesDir = dirname(__DIR__) . '/public/assets/images/categories';
if (!is_dir($categoriesDir)) {
    mkdir($categoriesDir, 0777, true);
}

function hex2rgb(string $hex): array
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

function allocCol($img, string $hex, int $alpha = 0): int
{
    [$r, $g, $b] = hex2rgb($hex);
    return (int) imagecolorallocatealpha($img, $r, $g, $b, $alpha);
}

function drawCategoryBadge($img, int $w, int $h, string $bgHex, string $accentHex, string $symbolText): void
{
    imagealphablending($img, true);
    imagesavealpha($img, true);

    // Transparent canvas
    $trans = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $trans);

    // Soft circle shadow
    $shadow = allocCol($img, '#0f172a', 115);
    imagefilledellipse($img, (int) ($w / 2), (int) ($h / 2 + 5), (int) ($w - 18), (int) ($h - 18), $shadow);

    // Outer pastel ring
    $bgCol = allocCol($img, $bgHex);
    imagefilledellipse($img, (int) ($w / 2), (int) ($h / 2), (int) ($w - 16), (int) ($h - 16), $bgCol);

    // Inner bright circle
    $accentCol = allocCol($img, $accentHex);
    imagefilledellipse($img, (int) ($w / 2), (int) ($h / 2), (int) ($w - 38), (int) ($h - 38), $accentCol);

    // Inner white disc
    $white = allocCol($img, '#ffffff');
    imagefilledellipse($img, (int) ($w / 2), (int) ($h / 2), (int) ($w - 56), (int) ($h - 56), $white);

    // Render symbol / label with TTF
    $font = 'C:/Windows/Fonts/seguiemj.ttf';
    if (!file_exists($font)) {
        $font = 'C:/Windows/Fonts/tahomabd.ttf';
    }
    if (!file_exists($font)) {
        $font = 'C:/Windows/Fonts/arialbd.ttf';
    }

    if (file_exists($font)) {
        $txtCol = allocCol($img, '#1e293b');
        $bbox = imagettfbbox(30, 0, $font, $symbolText);
        if ($bbox !== false) {
            $textWidth = abs($bbox[2] - $bbox[0]);
            $textHeight = abs($bbox[7] - $bbox[1]);
            $tx = (int) (($w - $textWidth) / 2);
            $ty = (int) (($h + $textHeight) / 2) - 4;
            imagettftext($img, 30, 0, $tx, $ty, $txtCol, $font, $symbolText);
        }
    }
}

$categories = [
    'cat-writing.webp' => ['#ffe4e6', '#f43f5e', '✏'],
    'cat-notebooks.webp' => ['#ede9fe', '#8b5cf6', '📓'],
    'cat-art.webp' => ['#ecfdf5', '#10b981', '🎨'],
    'cat-office.webp' => ['#fef3c7', '#f59e0b', '📎'],
    'cat-pencils.webp' => ['#e0f2fe', '#0284c7', '📐'],
    'cat-pens.webp' => ['#ffedd5', '#f97316', '🖊'],
];

foreach ($categories as $filename => [$bgHex, $accentHex, $symbol]) {
    $img = imagecreatetruecolor(160, 160);
    drawCategoryBadge($img, 160, 160, $bgHex, $accentHex, $symbol);
    imagewebp($img, $categoriesDir . '/' . $filename, 92);
    imagedestroy($img);
}

echo "Category icons generated successfully in: " . $categoriesDir . PHP_EOL;
