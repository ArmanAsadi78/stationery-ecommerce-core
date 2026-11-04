<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

new \App\Core\Application();

use App\Core\Database;

$pdo = Database::getInstance()->getConnection();

echo "1. Checking categories icon_image column...\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM `categories` LIKE 'icon_image'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `categories` ADD COLUMN `icon_image` VARCHAR(255) NULL AFTER `image_url`");
        echo "Added icon_image column to categories.\n";
    } else {
        echo "icon_image column already exists in categories.\n";
    }
} catch (\Throwable $e) {
    echo "Error checking column: " . $e->getMessage() . "\n";
}

echo "2. Updating categories icon_image values...\n";
$catIcons = [
    'writing-instruments' => 'images/categories/cat-writing.webp',
    'notebooks-and-paper' => 'images/categories/cat-notebooks.webp',
    'office-supplies' => 'images/categories/cat-office.webp',
    'art-and-drawing' => 'images/categories/cat-art.webp',
    'rollerball-pens' => 'images/categories/cat-pens.webp',
    'mechanical-pencils' => 'images/categories/cat-pencils.webp',
];

$stmt = $pdo->prepare("UPDATE `categories` SET `icon_image` = :icon WHERE `slug` = :slug");
foreach ($catIcons as $slug => $icon) {
    $stmt->execute(['icon' => $icon, 'slug' => $slug]);
    echo "Updated category '$slug' -> '$icon'\n";
}

echo "3. Creating promo_banners table...\n";
$pdo->exec("
CREATE TABLE IF NOT EXISTS `promo_banners` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `badge_text` VARCHAR(150) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `button_text` VARCHAR(100) NOT NULL,
    `target_url` VARCHAR(255) NOT NULL,
    `image_path` VARCHAR(255) NOT NULL,
    `position` ENUM('banner_right', 'banner_left') NOT NULL UNIQUE,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_promo_banners_active` (`is_active`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "promo_banners table ready.\n";

echo "4. Seeding promo_banners...\n";
$seedBanners = [
    [
        'badge_text' => '🎀 کالکشن کیوت بولت ژورنال',
        'title' => 'پک‌های هدیه و دفترهای نقطه‌ای',
        'description' => 'شامل دفترهای جلدسخت، استیکرهای ژورنال و چسب‌های واشی با تخفیف هیجان‌انگیز',
        'button_text' => 'مشاهده کالکشن ژورنال ←',
        'target_url' => '/products?category=notebooks-and-paper',
        'image_path' => 'images/banners/promo-journal.webp',
        'position' => 'banner_right',
        'is_active' => 1,
    ],
    [
        'badge_text' => '📐 نوشت‌افزار تکنیکال و دقیق',
        'title' => 'روترینگ و فابرکاستل آلمان',
        'description' => 'ضمانت اصالت فیزیکی تمامی اتودها، نوک‌ها و مدادرنگی‌های تخصصی با بسته‌بندی هاردباکس',
        'button_text' => 'خرید محصولات مهندسی ←',
        'target_url' => '/products?category=writing-instruments',
        'image_path' => 'images/banners/promo-technical.webp',
        'position' => 'banner_left',
        'is_active' => 1,
    ],
];

$bannerStmt = $pdo->prepare("
INSERT INTO `promo_banners` (`badge_text`, `title`, `description`, `button_text`, `target_url`, `image_path`, `position`, `is_active`)
VALUES (:badge_text, :title, :description, :button_text, :target_url, :image_path, :position, :is_active)
ON DUPLICATE KEY UPDATE
    `badge_text` = VALUES(`badge_text`),
    `title` = VALUES(`title`),
    `description` = VALUES(`description`),
    `button_text` = VALUES(`button_text`),
    `target_url` = VALUES(`target_url`),
    `image_path` = VALUES(`image_path`),
    `is_active` = VALUES(`is_active`)
");

foreach ($seedBanners as $banner) {
    $bannerStmt->execute($banner);
    echo "Upserted promo banner: {$banner['position']}\n";
}

echo "Setup completed successfully!\n";
