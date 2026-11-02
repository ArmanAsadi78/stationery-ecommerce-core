<?php

declare(strict_types=1);

/**
 * =====================================================================
 * Commercial Stationery Composite Image Generator using GD
 * Generates rich, realistic stationery flat-lay compositions in WebP
 * with alpha transparency and realistic soft drop-shadows.
 * =====================================================================
 */

$bannersDir = dirname(__DIR__) . '/public/assets/images/banners';
if (!is_dir($bannersDir)) {
    mkdir($bannersDir, 0777, true);
}

// Helper: Convert HEX to RGB
function hexRgb(string $hex): array
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

// Helper: Color allocation with optional alpha (0 = opaque, 127 = fully transparent)
function col($img, string $hex, int $alpha = 0): int
{
    [$r, $g, $b] = hexRgb($hex);
    return imagecolorallocatealpha($img, $r, $g, $b, $alpha);
}

// Helper: Draw soft drop shadow for a rectangle
function drawRectShadow($img, int $x, int $y, int $w, int $h, int $radius, int $blur = 6): void
{
    for ($i = $blur; $i >= 1; $i--) {
        $alpha = 127 - (int) (18 * (1 - ($i / ($blur + 1))));
        $shadowCol = imagecolorallocatealpha($img, 15, 23, 42, $alpha);
        drawRoundedBox($img, $x - $i, $y + ($i / 2), $w + ($i * 2), $h + ($i * 1.5), $radius + $i, $shadowCol);
    }
}

// Helper: Draw rounded box
function drawRoundedBox($img, float $x, float $y, float $w, float $h, float $r, int $color): void
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

// Helper: Draw an aesthetic open dotted planner / journal
function drawOpenPlanner($img, int $x, int $y, int $w, int $h): void
{
    // Soft shadow underneath book
    drawRectShadow($img, $x, $y + 6, $w, $h, 12, 10);

    // Hardcover back / edge (rose/coral leather edge)
    $coverCol = col($img, '#f43f5e');
    $coverDark = col($img, '#be185d');
    drawRoundedBox($img, $x - 6, $y - 4, $w + 12, $h + 8, 12, $coverDark);
    drawRoundedBox($img, $x - 4, $y - 2, $w + 8, $h + 4, 10, $coverCol);

    // Cream pages left & right
    $pageCol = col($img, '#fafaf9');
    $pageLineCol = col($img, '#e7e5e4');
    $pageDotsCol = col($img, '#d6d3d1');
    $gutterCol = col($img, '#e2e8f0');

    $halfW = (int) (($w - 14) / 2);

    // Left Page
    drawRoundedBox($img, $x, $y, $halfW, $h, 6, $pageCol);
    // Right Page
    drawRoundedBox($img, $x + $halfW + 14, $y, $halfW, $h, 6, $pageCol);

    // Book spine / gutter shadow in center
    imagefilledrectangle($img, $x + $halfW, $y, $x + $halfW + 14, $y + $h, $gutterCol);

    // Dotted grid on pages
    for ($px = $x + 16; $px < $x + $halfW - 14; $px += 14) {
        for ($py = $y + 20; $py < $y + $h - 20; $py += 14) {
            imagesetpixel($img, $px, $py, $pageDotsCol);
            imagesetpixel($img, $px + 1, $py, $pageDotsCol);
        }
    }
    for ($px = $x + $halfW + 28; $px < $x + $w - 14; $px += 14) {
        for ($py = $y + 20; $py < $y + $h - 20; $py += 14) {
            imagesetpixel($img, $px, $py, $pageDotsCol);
            imagesetpixel($img, $px + 1, $py, $pageDotsCol);
        }
    }

    // Header simulated text blocks on left page
    $textPink = col($img, '#fb7185');
    $textSlate = col($img, '#94a3b8');
    drawRoundedBox($img, $x + 20, $y + 24, 75, 8, 3, $textPink);
    drawRoundedBox($img, $x + 20, $y + 40, 95, 5, 2, $textSlate);
    drawRoundedBox($img, $x + 20, $y + 52, 85, 5, 2, $textSlate);

    // Checkbox items on right page
    for ($ci = 0; $ci < 4; $ci++) {
        $cy = $y + 36 + ($ci * 24);
        $checkCol = ($ci === 0 || $ci === 1) ? col($img, '#10b981') : col($img, '#cbd5e1');
        drawRoundedBox($img, $x + $halfW + 30, $cy, 12, 12, 3, $checkCol);
        drawRoundedBox($img, $x + $halfW + 50, $cy + 3, 70 - ($ci * 8), 5, 2, $textSlate);
    }

    // Ribbon bookmark hanging out from bottom
    $ribbonCol = col($img, '#fb7185');
    $ribbonShadow = col($img, '#e11d48');
    imagefilledpolygon($img, [
        $x + $halfW + 4, $y + $h - 2,
        $x + $halfW + 12, $y + $h - 2,
        $x + $halfW + 18, $y + $h + 38,
        $x + $halfW + 8, $y + $h + 28,
        $x + $halfW + 2, $y + $h + 36,
    ], $ribbonCol);
}

// Helper: Draw a sleek pen / mechanical pencil
function drawStationeryPen($img, int $x, int $y, int $len, int $thick, string $bodyHex, string $accentHex, bool $isMechanical = false): void
{
    // Drop shadow
    drawRectShadow($img, $x + 4, $y + 4, $len, $thick, $thick / 2, 4);

    $bodyCol = col($img, $bodyHex);
    $accentCol = col($img, $accentHex);
    $silver = col($img, '#cbd5e1');
    $darkSilver = col($img, '#64748b');
    $black = col($img, '#0f172a');

    // Pen Body
    drawRoundedBox($img, $x, $y, $len, $thick, $thick / 2, $bodyCol);

    // Grip section
    drawRoundedBox($img, $x + $len - 38, $y, 28, $thick, 2, $accentCol);

    // Grip texture lines
    for ($gx = $x + $len - 35; $gx < $x + $len - 12; $gx += 4) {
        imageline($img, $gx, $y + 1, $gx, $y + $thick - 2, $darkSilver);
    }

    // Metal cone tip at right
    imagefilledpolygon($img, [
        $x + $len - 2, $y + 1,
        $x + $len + 16, $y + ($thick / 2),
        $x + $len - 2, $y + $thick - 2,
    ], $silver);

    if ($isMechanical) {
        // Thin metal lead pipe
        imagefilledrectangle($img, $x + $len + 16, $y + ($thick / 2) - 1, $x + $len + 24, $y + ($thick / 2) + 1, $silver);
        // Lead point
        imagefilledrectangle($img, $x + $len + 24, $y + ($thick / 2) - 1, $x + $len + 28, $y + ($thick / 2) + 1, $black);
    }

    // Metal Clip & push button on left end
    imagefilledrectangle($img, $x - 6, $y + 2, $x, $y + $thick - 3, $silver);
    // Push button
    imagefilledrectangle($img, $x - 12, $y + 3, $x - 6, $y + $thick - 4, $accentCol);
    // Clip bar
    imagefilledrectangle($img, $x - 2, $y - 3, $x + 42, $y, $silver);
}

// Helper: Draw a pastel highlighter marker
function drawHighlighter($img, int $x, int $y, string $colorHex): void
{
    $w = 120;
    $h = 32;

    drawRectShadow($img, $x + 2, $y + 4, $w, $h, 10, 5);

    $bodyCol = col($img, $colorHex);
    $white = col($img, '#ffffff');
    $darkAccent = col($img, '#be185d');

    // Main barrel
    drawRoundedBox($img, $x, $y, $w - 24, $h, 8, $bodyCol);
    // White branding label patch
    drawRoundedBox($img, $x + 24, $y + 7, 54, $h - 14, 4, $white);

    // Chisel Tip holder
    imagefilledrectangle($img, $x + $w - 24, $y + 4, $x + $w - 12, $y + $h - 5, col($img, '#475569'));

    // Chisel slanted tip
    imagefilledpolygon($img, [
        $x + $w - 12, $y + 6,
        $x + $w, $y + 10,
        $x + $w - 4, $y + $h - 6,
        $x + $w - 12, $y + $h - 8,
    ], col($img, '#f43f5e'));
}

// Helper: Draw washi tape strip
function drawWashiTape($img, int $x, int $y, int $w, int $h, string $colorHex, string $patternHex): void
{
    $tapeCol = col($img, $colorHex, 35);
    $patCol = col($img, $patternHex, 45);

    drawRoundedBox($img, $x, $y, $w, $h, 2, $tapeCol);

    // Diagonal stripes on washi tape
    for ($i = -20; $i < $w + 20; $i += 12) {
        imageline($img, $x + $i, $y, $x + $i + 14, $y + $h, $patCol);
        imageline($img, $x + $i + 1, $y, $x + $i + 15, $y + $h, $patCol);
    }
}

// Helper: Draw cute sticky note pad
function drawStickyNote($img, int $x, int $y, int $size, string $colorHex, string $noteText = ''): void
{
    drawRectShadow($img, $x, $y + 4, $size, $size, 6, 6);
    $bgCol = col($img, $colorHex);
    drawRoundedBox($img, $x, $y, $size, $size, 5, $bgCol);

    // Folded corner effect
    $foldCol = col($img, '#f1f5f9', 50);
    imagefilledpolygon($img, [
        $x + $size - 14, $y + $size,
        $x + $size, $y + $size - 14,
        $x + $size - 14, $y + $size - 14,
    ], $foldCol);

    // Lines
    $lineCol = col($img, '#475569', 85);
    for ($ly = $y + 16; $ly < $y + $size - 16; $ly += 12) {
        imageline($img, $x + 12, $ly, $x + $size - 16, $ly, $lineCol);
    }
}

// Helper: Draw technical drafting triangle (گونیا)
function drawDraftingTriangle($img, int $x, int $y, int $size): void
{
    $acrylic = col($img, '#e0f2fe', 80);
    $acrylicEdge = col($img, '#38bdf8', 40);
    $markCol = col($img, '#0284c7');

    // Shadow
    imagefilledpolygon($img, [
        $x + 4, $y + $size + 4,
        $x + $size + 4, $y + $size + 4,
        $x + 4, $y + 4,
    ], col($img, '#0f172a', 115));

    // Outer Triangle
    imagefilledpolygon($img, [
        $x, $y + $size,
        $x + $size, $y + $size,
        $x, $y,
    ], $acrylic);

    // Inner cutout triangle
    $innerX = $x + 22;
    $innerY = $y + ($size / 2) + 8;
    $innerSize = (int) ($size / 3);
    imagefilledpolygon($img, [
        $innerX, $y + $size - 18,
        $innerX + $innerSize * 1.4, $y + $size - 18,
        $innerX, $innerY,
    ], col($img, '#ffffff', 127)); // clear cutout

    // Millimeter tick marks along bottom
    for ($tx = $x + 6; $tx < $x + $size - 12; $tx += 6) {
        $tickH = (($tx - $x) % 30 === 0) ? 8 : 4;
        imageline($img, (int) $tx, $y + $size, (int) $tx, $y + $size - $tickH, $markCol);
    }
}

// Helper: Draw precision compass (پرگار مهندسی)
function drawPrecisionCompass($img, int $x, int $y): void
{
    $silver = col($img, '#94a3b8');
    $darkSilver = col($img, '#475569');
    $red = col($img, '#f43f5e');

    // Shadow
    imageline($img, $x + 4, $y + 8, $x - 36, $y + 118, col($img, '#0f172a', 115));
    imageline($img, $x + 4, $y + 8, $x + 44, $y + 118, col($img, '#0f172a', 115));

    // Head hinge wheel
    imagefilledellipse($img, $x, $y, 20, 20, $darkSilver);
    imagefilledellipse($img, $x, $y, 14, 14, $silver);
    imagefilledellipse($img, $x, $y, 6, 6, $red);

    // Compass legs
    imagesetthickness($img, 4);
    imageline($img, $x - 3, $y + 6, $x - 40, $y + 114, $silver);
    imageline($img, $x + 3, $y + 6, $x + 40, $y + 114, $silver);

    // Precision needle and lead holder points
    imagesetthickness($img, 2);
    imageline($img, $x - 40, $y + 114, $x - 46, $y + 130, col($img, '#334155')); // Needle
    imageline($img, $x + 40, $y + 114, $x + 46, $y + 130, col($img, '#0f172a')); // Lead
    imagesetthickness($img, 1);
}

// =====================================================================
// 1. GENERATE HERO COMPOSITE FLAT-LAY (hero-showcase.webp - 540x480)
// =====================================================================
echo "Generating hero-showcase.webp...\n";
$heroW = 540;
$heroH = 480;
$heroImg = imagecreatetruecolor($heroW, $heroH);
imagealphablending($heroImg, false);
imagesavealpha($heroImg, true);
$transparent = imagecolorallocatealpha($heroImg, 0, 0, 0, 127);
imagefill($heroImg, 0, 0, $transparent);
imagealphablending($heroImg, true);

// 1.1 Open Bullet Journal in center
drawOpenPlanner($heroImg, 40, 90, 310, 240);

// 1.2 Sticky notes around planner
drawStickyNote($heroImg, 380, 70, 110, '#fef9c3'); // Butter yellow note
drawStickyNote($heroImg, 395, 210, 105, '#fce7f3'); // Pastel pink note

// 1.3 Highlighters & Pens
drawHighlighter($heroImg, 60, 380, '#fecdd3'); // Rose highlighter at bottom
drawStationeryPen($heroImg, 65, 345, 240, 14, '#1e293b', '#f43f5e', true); // Sleek Rotring mechanical pencil
drawStationeryPen($heroImg, 80, 425, 220, 12, '#bae6fd', '#0284c7', false); // Blue rollerball pen

// 1.4 Washi tape strips
drawWashiTape($heroImg, 25, 60, 80, 22, '#fde68a', '#f59e0b');
drawWashiTape($heroImg, 330, 350, 95, 22, '#dcfce7', '#10b981');

// 1.5 Floating aesthetic paperclip
$clipCol = col($heroImg, '#f59e0b');
imagesetthickness($heroImg, 3);
drawRoundedBox($heroImg, 220, 60, 32, 14, 6, $clipCol);
imagesetthickness($heroImg, 1);

imagewebp($heroImg, $bannersDir . '/hero-showcase.webp', 95);
imagedestroy($heroImg);
echo "✔ Saved: public/assets/images/banners/hero-showcase.webp\n";


// =====================================================================
// 2. GENERATE PROMO JOURNAL COMPOSITE (promo-journal.webp - 360x320)
// =====================================================================
echo "Generating promo-journal.webp...\n";
$jourW = 360;
$jourH = 320;
$jourImg = imagecreatetruecolor($jourW, $jourH);
imagealphablending($jourImg, false);
imagesavealpha($jourImg, true);
imagefill($jourImg, 0, 0, $transparent);
imagealphablending($jourImg, true);

// Open aesthetic planner
drawOpenPlanner($jourImg, 30, 40, 240, 190);

// Two colorful pastel highlighters
drawHighlighter($jourImg, 40, 250, '#fce7f3');
drawStationeryPen($jourImg, 50, 288, 200, 12, '#fed7aa', '#ea580c', false);

// Washi Tape and Sticky note
drawWashiTape($jourImg, 190, 20, 75, 20, '#ede9fe', '#8b5cf6');
drawStickyNote($jourImg, 245, 130, 85, '#fef3c7');

imagewebp($jourImg, $bannersDir . '/promo-journal.webp', 95);
imagedestroy($jourImg);
echo "✔ Saved: public/assets/images/banners/promo-journal.webp\n";


// =====================================================================
// 3. GENERATE PROMO TECHNICAL COMPOSITE (promo-technical.webp - 360x320)
// =====================================================================
echo "Generating promo-technical.webp...\n";
$techW = 360;
$techH = 320;
$techImg = imagecreatetruecolor($techW, $techH);
imagealphablending($techImg, false);
imagesavealpha($techImg, true);
imagefill($techImg, 0, 0, $transparent);
imagealphablending($techImg, true);

// Technical Triangle Ruler
drawDraftingTriangle($techImg, 30, 30, 180);

// Precision Engineering Compass
drawPrecisionCompass($techImg, 260, 40);

// Sleek Professional Rotring Drafting Pencils
drawStationeryPen($techImg, 40, 230, 230, 14, '#0f172a', '#e11d48', true); // Matte Black Rotring 0.5
drawStationeryPen($techImg, 45, 265, 220, 12, '#94a3b8', '#0284c7', true); // Silver Drafting Pencil 0.7

// Steel Ruler markings across top
$rulerCol = col($techImg, '#e2e8f0');
$rulerMark = col($techImg, '#475569');
drawRoundedBox($techImg, 20, 10, 240, 22, 4, $rulerCol);
for ($rx = 26; $rx < 250; $rx += 6) {
    $rh = (($rx - 20) % 30 === 0) ? 10 : 5;
    imageline($techImg, $rx, 10, $rx, 10 + $rh, $rulerMark);
}

imagewebp($techImg, $bannersDir . '/promo-technical.webp', 95);
imagedestroy($techImg);
echo "✔ Saved: public/assets/images/banners/promo-technical.webp\n";

echo "All 3 composite commercial banner images successfully generated!\n";
