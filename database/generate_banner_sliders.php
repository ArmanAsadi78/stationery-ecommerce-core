<?php

declare(strict_types=1);

/**
 * =====================================================================
 * Wide Promotional Banner Generator (1200x420 px) using PHP GD
 * Generates high-resolution, commercial pastel banners for the
 * homepage wide carousel (inspired by leading Iranian stationery brands).
 * =====================================================================
 */

$slidersDir = dirname(__DIR__) . '/public/assets/images/sliders';
if (!is_dir($slidersDir)) {
    mkdir($slidersDir, 0777, true);
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

function allocateCol($img, string $hex, int $alpha = 0): int
{
    [$r, $g, $b] = hexToRgb($hex);
    return (int) imagecolorallocatealpha($img, $r, $g, $b, $alpha);
}

function drawRoundedRect($img, float $x, float $y, float $w, float $h, float $r, int $color): void
{
    $x1 = (int) $x;
    $y1 = (int) $y;
    $x2 = (int) ($x + $w);
    $y2 = (int) ($y + $h);
    $r = min($r, $w / 2, $h / 2);

    imagefilledrectangle($img, (int) ($x1 + $r), $y1, (int) ($x2 - $r), $y2, $color);
    imagefilledrectangle($img, $x1, (int) ($y1 + $r), $x2, (int) ($y2 - $r), $color);
    imagefilledellipse($img, (int) ($x1 + $r), (int) ($y1 + $r), (int) ($r * 2), (int) ($r * 2), $color);
    imagefilledellipse($img, (int) ($x2 - $r), (int) ($y1 + $r), (int) ($r * 2), (int) ($r * 2), $color);
    imagefilledellipse($img, (int) ($x1 + $r), (int) ($y2 - $r), (int) ($r * 2), (int) ($r * 2), $color);
    imagefilledellipse($img, (int) ($x2 - $r), (int) ($y2 - $r), (int) ($r * 2), (int) ($r * 2), $color);
}

function drawLinearGradient($img, int $w, int $h, string $startHex, string $endHex): void
{
    [$r1, $g1, $b1] = hexToRgb($startHex);
    [$r2, $g2, $b2] = hexToRgb($endHex);

    for ($x = 0; $x < $w; $x++) {
        $ratio = $x / (float) $w;
        $r = (int) ($r1 + ($r2 - $r1) * $ratio);
        $g = (int) ($g1 + ($g2 - $g1) * $ratio);
        $b = (int) ($b1 + ($b2 - $b1) * $ratio);
        $col = imagecolorallocate($img, $r, $g, $b);
        imageline($img, $x, 0, $x, $h, $col);
    }
}

function drawBanner1(string $outputPath): void
{
    $w = 1200;
    $h = 420;
    $img = imagecreatetruecolor($w, $h);
    imagealphablending($img, true);
    imagesavealpha($img, true);

    // 1. Pastel Warm Rose / Peach Gradient Background
    drawLinearGradient($img, $w, $h, '#fff1f2', '#ffe4e6');

    // 2. Decorative Pastel Shapes & Soft Blobs
    $blob1 = allocateCol($img, '#fecdd3', 75);
    imagefilledellipse($img, 1050, 100, 480, 480, $blob1);

    $blob2 = allocateCol($img, '#fed7aa', 85);
    imagefilledellipse($img, 920, 360, 380, 380, $blob2);

    $blob3 = allocateCol($img, '#fbcfe8', 90);
    imagefilledellipse($img, 150, -50, 350, 350, $blob3);

    // Decorative floating dots
    for ($i = 0; $i < 30; $i++) {
        $dotX = mt_rand(40, $w - 40);
        $dotY = mt_rand(20, $h - 20);
        $dotR = mt_rand(4, 12);
        $dotCol = allocateCol($img, '#fda4af', mt_rand(60, 95));
        imagefilledellipse($img, $dotX, $dotY, $dotR, $dotR, $dotCol);
    }

    // 3. Right Side: Visual Artwork Composition (Open Planner, Cute Highlighters, Washi Tapes)
    // Dotted open notebook
    $bookX = 750;
    $bookY = 70;
    $bookW = 380;
    $bookH = 280;

    // Soft drop shadow
    $shadowCol = allocateCol($img, '#0f172a', 110);
    drawRoundedRect($img, $bookX - 10, $bookY + 12, $bookW + 20, $bookH + 16, 24, $shadowCol);

    // Notebook cover (Rose pastel)
    $coverCol = allocateCol($img, '#fb7185');
    drawRoundedRect($img, $bookX, $bookY, $bookW, $bookH, 20, $coverCol);

    // Notebook inner cream pages
    $pageCol = allocateCol($img, '#fffdfa');
    drawRoundedRect($img, $bookX + 16, $bookY + 14, $bookW - 32, $bookH - 28, 14, $pageCol);

    // Center seam & dotted lines
    $lineCol = allocateCol($img, '#f1f5f9');
    $seamCol = allocateCol($img, '#e2e8f0');
    imageline($img, (int) ($bookX + $bookW / 2), (int) ($bookY + 14), (int) ($bookX + $bookW / 2), (int) ($bookY + $bookH - 14), $seamCol);

    for ($ly = $bookY + 35; $ly < $bookY + $bookH - 30; $ly += 22) {
        imageline($img, $bookX + 35, $ly, (int) ($bookX + $bookW / 2 - 20), $ly, $lineCol);
        imageline($img, (int) ($bookX + $bookW / 2 + 20), $ly, $bookX + $bookW - 35, $ly, $lineCol);
    }

    // Cute pastel bookmark ribbon
    $ribbonCol = allocateCol($img, '#e11d48');
    imagefilledrectangle($img, (int) ($bookX + $bookW / 2 - 6), $bookY + 8, (int) ($bookX + $bookW / 2 + 6), $bookY + $bookH + 20, $ribbonCol);

    // Highlighters on the side
    $highlighters = [
        ['#f43f5e', 700, 110, -18],
        ['#38bdf8', 685, 170, -14],
        ['#a855f7', 670, 230, -10],
    ];
    foreach ($highlighters as [$colorHex, $hx, $hy, $angle]) {
        $hCol = allocateCol($img, $colorHex);
        $capCol = allocateCol($img, '#ffffff');
        drawRoundedRect($img, $hx, $hy, 140, 32, 10, $hCol);
        drawRoundedRect($img, $hx + 90, $hy, 45, 32, 6, $capCol);
    }

    // 4. Commercial Badges & Promo Ribbons
    // "جشنواره بهاره" top badge pill
    $pillCol = allocateCol($img, '#ffffff');
    $pillBorder = allocateCol($img, '#fecdd3');
    drawRoundedRect($img, 80, 60, 280, 44, 22, $pillCol);
    drawRoundedRect($img, 81, 61, 278, 42, 21, $pillBorder);

    // Large Discount Badge
    $badgeCol = allocateCol($img, '#e11d48');
    drawRoundedRect($img, 80, 130, 480, 80, 20, $badgeCol);

    // Secondary Feature Card
    $cardCol = allocateCol($img, '#ffffff', 20);
    drawRoundedRect($img, 80, 230, 480, 60, 16, $cardCol);

    // CTA Button "خرید و مشاهده تخفیف‌ها"
    $btnCol = allocateCol($img, '#0f172a');
    drawRoundedRect($img, 80, 310, 260, 52, 26, $btnCol);

    // 5. Render Texts with TTF (Tahomabd or Arialbd)
    $font = 'C:/Windows/Fonts/tahomabd.ttf';
    if (!file_exists($font)) {
        $font = 'C:/Windows/Fonts/arialbd.ttf';
    }

    if (file_exists($font)) {
        $txtDark = allocateCol($img, '#0f172a');
        $txtWhite = allocateCol($img, '#ffffff');
        $txtRose = allocateCol($img, '#e11d48');
        $txtMuted = allocateCol($img, '#64748b');

        // Note: For universal readability and proper rendering, we use clean commercial badge texts
        imagettftext($img, 13, 0, 110, 88, $txtRose, $font, 'TAHRIRCHI SPRING FESTIVAL');
        imagettftext($img, 24, 0, 110, 180, $txtWhite, $font, 'JAPANESE CUTE STATIONERY');
        imagettftext($img, 13, 0, 105, 267, $txtDark, $font, 'Zebra Sarasa | Sakura Pigma | Kokuyo Campus');
        imagettftext($img, 14, 0, 115, 343, $txtWhite, $font, 'SHOP NOW  ->');
    }

    imagewebp($img, $outputPath, 92);
    imagedestroy($img);
}

function drawBanner2(string $outputPath): void
{
    $w = 1200;
    $h = 420;
    $img = imagecreatetruecolor($w, $h);
    imagealphablending($img, true);
    imagesavealpha($img, true);

    // 1. Pastel Lavender & Soft Blue Gradient
    drawLinearGradient($img, $w, $h, '#ede9fe', '#e0e7ff');

    // 2. Modern Decorative Geometry & Pastel Circles
    $blob1 = allocateCol($img, '#ddd6fe', 75);
    imagefilledellipse($img, 1000, 200, 500, 500, $blob1);

    $blob2 = allocateCol($img, '#c7d2fe', 80);
    imagefilledellipse($img, 820, 80, 320, 320, $blob2);

    $blob3 = allocateCol($img, '#fbcfe8', 90);
    imagefilledellipse($img, 180, 360, 340, 340, $blob3);

    // Floating dots
    for ($i = 0; $i < 30; $i++) {
        $dotX = mt_rand(40, $w - 40);
        $dotY = mt_rand(20, $h - 20);
        $dotR = mt_rand(4, 12);
        $dotCol = allocateCol($img, '#a78bfa', mt_rand(65, 95));
        imagefilledellipse($img, $dotX, $dotY, $dotR, $dotR, $dotCol);
    }

    // 3. Right Visual: Premium Hardcover Bullet Journal with Golden Bookmark
    $bookX = 760;
    $bookY = 65;
    $bookW = 360;
    $bookH = 290;

    $shadowCol = allocateCol($img, '#0f172a', 110);
    drawRoundedRect($img, $bookX - 10, $bookY + 14, $bookW + 20, $bookH + 16, 24, $shadowCol);

    $coverCol = allocateCol($img, '#6366f1');
    drawRoundedRect($img, $bookX, $bookY, $bookW, $bookH, 20, $coverCol);

    $pageCol = allocateCol($img, '#fafaf9');
    drawRoundedRect($img, $bookX + 16, $bookY + 14, $bookW - 32, $bookH - 28, 14, $pageCol);

    $gridCol = allocateCol($img, '#e0e7ff');
    for ($gx = $bookX + 35; $gx < $bookX + $bookW - 35; $gx += 20) {
        for ($gy = $bookY + 35; $gy < $bookY + $bookH - 35; $gy += 20) {
            imagefilledellipse($img, $gx, $gy, 3, 3, $gridCol);
        }
    }

    // Washi Tapes Stack
    $washiColors = ['#f472b6', '#38bdf8', '#34d399', '#fbbf24'];
    $wx = 680;
    $wy = 160;
    foreach ($washiColors as $idx => $wc) {
        $wCol = allocateCol($img, $wc);
        $ringCol = allocateCol($img, '#ffffff');
        imagefilledellipse($img, $wx, $wy + ($idx * 38), 75, 45, $wCol);
        imagefilledellipse($img, $wx, $wy + ($idx * 38), 35, 20, $ringCol);
    }

    // 4. Promo Banners & Typography
    $pillCol = allocateCol($img, '#ffffff');
    $pillBorder = allocateCol($img, '#c7d2fe');
    drawRoundedRect($img, 80, 60, 320, 44, 22, $pillCol);
    drawRoundedRect($img, 81, 61, 318, 42, 21, $pillBorder);

    $badgeCol = allocateCol($img, '#4f46e5');
    drawRoundedRect($img, 80, 130, 520, 80, 20, $badgeCol);

    $cardCol = allocateCol($img, '#ffffff', 20);
    drawRoundedRect($img, 80, 230, 520, 60, 16, $cardCol);

    $btnCol = allocateCol($img, '#0f172a');
    drawRoundedRect($img, 80, 310, 260, 52, 26, $btnCol);

    $font = 'C:/Windows/Fonts/tahomabd.ttf';
    if (!file_exists($font)) {
        $font = 'C:/Windows/Fonts/arialbd.ttf';
    }

    if (file_exists($font)) {
        $txtDark = allocateCol($img, '#0f172a');
        $txtWhite = allocateCol($img, '#ffffff');
        $txtPurple = allocateCol($img, '#4f46e5');

        imagettftext($img, 13, 0, 110, 88, $txtPurple, $font, 'BULLET JOURNAL & PLANNER 2026');
        imagettftext($img, 23, 0, 110, 180, $txtWhite, $font, 'PREMIUM DOTTED NOTEBOOKS');
        imagettftext($img, 13, 0, 105, 267, $txtDark, $font, '160 GSM Bleedproof Paper | Stickers & Washi Tape');
        imagettftext($img, 14, 0, 115, 343, $txtWhite, $font, 'EXPLORE COLLECTION  ->');
    }

    imagewebp($img, $outputPath, 92);
    imagedestroy($img);
}

function drawBanner3(string $outputPath): void
{
    $w = 1200;
    $h = 420;
    $img = imagecreatetruecolor($w, $h);
    imagealphablending($img, true);
    imagesavealpha($img, true);

    // 1. Pastel Mint & Cream Gradient
    drawLinearGradient($img, $w, $h, '#ecfdf5', '#f0fdf4');

    // 2. Geometric Blobs
    $blob1 = allocateCol($img, '#a7f3d0', 75);
    imagefilledellipse($img, 1020, 210, 480, 480, $blob1);

    $blob2 = allocateCol($img, '#fed7aa', 85);
    imagefilledellipse($img, 850, 100, 340, 340, $blob2);

    for ($i = 0; $i < 30; $i++) {
        $dotX = mt_rand(40, $w - 40);
        $dotY = mt_rand(20, $h - 20);
        $dotR = mt_rand(4, 12);
        $dotCol = allocateCol($img, '#34d399', mt_rand(65, 95));
        imagefilledellipse($img, $dotX, $dotY, $dotR, $dotR, $dotCol);
    }

    // 3. Technical Rapidograph & Drawing Set Visual
    $caseX = 760;
    $caseY = 75;
    $caseW = 370;
    $caseH = 270;

    $shadowCol = allocateCol($img, '#0f172a', 110);
    drawRoundedRect($img, $caseX - 10, $caseY + 12, $caseW + 20, $caseH + 16, 24, $shadowCol);

    $caseCol = allocateCol($img, '#0f766e');
    drawRoundedRect($img, $caseX, $caseY, $caseW, $caseH, 20, $caseCol);

    $innerCol = allocateCol($img, '#134e4a');
    drawRoundedRect($img, $caseX + 16, $caseY + 16, $caseW - 32, $caseH - 32, 14, $innerCol);

    // Professional Rapidograph Pens in Case
    $penColors = ['#f8fafc', '#ea580c', '#38bdf8', '#fbbf24', '#e11d48'];
    foreach ($penColors as $idx => $pCol) {
        $py = $caseY + 36 + ($idx * 42);
        $pBar = allocateCol($img, $pCol);
        $metal = allocateCol($img, '#94a3b8');
        drawRoundedRect($img, $caseX + 40, $py, 220, 24, 6, $pBar);
        drawRoundedRect($img, $caseX + 265, $py + 6, 45, 12, 3, $metal);
    }

    // 4. Promo Banners & Typography
    $pillCol = allocateCol($img, '#ffffff');
    $pillBorder = allocateCol($img, '#a7f3d0');
    drawRoundedRect($img, 80, 60, 330, 44, 22, $pillCol);
    drawRoundedRect($img, 81, 61, 328, 42, 21, $pillBorder);

    $badgeCol = allocateCol($img, '#0f766e');
    drawRoundedRect($img, 80, 130, 520, 80, 20, $badgeCol);

    $cardCol = allocateCol($img, '#ffffff', 20);
    drawRoundedRect($img, 80, 230, 520, 60, 16, $cardCol);

    $btnCol = allocateCol($img, '#0f172a');
    drawRoundedRect($img, 80, 310, 260, 52, 26, $btnCol);

    $font = 'C:/Windows/Fonts/tahomabd.ttf';
    if (!file_exists($font)) {
        $font = 'C:/Windows/Fonts/arialbd.ttf';
    }

    if (file_exists($font)) {
        $txtDark = allocateCol($img, '#0f172a');
        $txtWhite = allocateCol($img, '#ffffff');
        $txtTeal = allocateCol($img, '#0f766e');

        imagettftext($img, 13, 0, 110, 88, $txtTeal, $font, 'PROFESSIONAL ARCHITECTURE & ART');
        imagettftext($img, 23, 0, 110, 180, $txtWhite, $font, 'ROTRING & FABER-CASTELL');
        imagettftext($img, 13, 0, 105, 267, $txtDark, $font, '100% Original German Technical Pens & Pencils');
        imagettftext($img, 14, 0, 115, 343, $txtWhite, $font, 'VIEW CATALOG  ->');
    }

    imagewebp($img, $outputPath, 92);
    imagedestroy($img);
}

// Generate the 3 banners
drawBanner1($slidersDir . '/banner-1.webp');
drawBanner2($slidersDir . '/banner-2.webp');
drawBanner3($slidersDir . '/banner-3.webp');

echo "Banners generated successfully in: " . $slidersDir . PHP_EOL;
