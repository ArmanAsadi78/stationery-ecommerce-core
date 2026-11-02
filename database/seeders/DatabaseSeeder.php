<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '3306';
$database = $_ENV['DB_DATABASE'] ?? 'stationery_shop';
$username = $_ENV['DB_USERNAME'] ?? 'root';
$password = $_ENV['DB_PASSWORD'] ?? '';
$charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

echo "========================================================\n";
echo " Database Seeder Runner\n";
echo " Seeding demo stationery data & test credentials...\n";
echo "========================================================\n\n";

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset={$charset}", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->beginTransaction();

    // 1. Seed Users (Argon2id passwords)
    echo "[1/5] Seeding Users (Admin & Customer)...\n";
    $userStmt = $pdo->prepare("
        INSERT INTO `users` (`full_name`, `email`, `phone`, `password_hash`, `role`, `is_active`)
        VALUES (:full_name, :email, :phone, :password_hash, :role, 1)
        ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`), `password_hash` = VALUES(`password_hash`)
    ");

    $adminPassword = password_hash('Admin@123456', PASSWORD_ARGON2ID);
    $userStmt->execute([
        ':full_name' => 'مدیر سامانه (سیستم)',
        ':email' => 'admin@stationery.local',
        ':phone' => '09120000001',
        ':password_hash' => $adminPassword,
        ':role' => 'admin',
    ]);
    $adminId = (int)$pdo->lastInsertId();

    $customerPassword = password_hash('Customer@123456', PASSWORD_ARGON2ID);
    $userStmt->execute([
        ':full_name' => 'آرمان علوی (کاربر دمو)',
        ':email' => 'customer@stationery.local',
        ':phone' => '09120000002',
        ':password_hash' => $customerPassword,
        ':role' => 'customer',
    ]);
    echo "       ✓ Admin: admin@stationery.local | Password: Admin@123456\n";
    echo "       ✓ Customer: customer@stationery.local | Password: Customer@123456\n";

    // 2. Seed Categories
    echo "[2/5] Seeding Categories (Hierarchical Stationery Taxonomy)...\n";
    $catStmt = $pdo->prepare("
        INSERT INTO `categories` (`parent_id`, `title`, `slug`, `description`, `sort_order`, `is_active`)
        VALUES (:parent_id, :title, :slug, :description, :sort_order, 1)
        ON DUPLICATE KEY UPDATE `title` = VALUES(`title`)
    ");

    $categories = [
        ['parent' => null, 'title' => 'نوشت‌افزار', 'slug' => 'writing-instruments', 'desc' => 'انواع خودکار، روان‌نویس، مداد و اتودهای مهندسی', 'sort' => 1],
        ['parent' => null, 'title' => 'کاغذ و دفتر', 'slug' => 'notebooks-and-paper', 'desc' => 'دفترهای یادداشت، مشق، کاغذ A4 و پلنر', 'sort' => 2],
        ['parent' => null, 'title' => 'لوازم اداری و بایگانی', 'slug' => 'office-supplies', 'desc' => 'زونکن، کلربوک، پوشه و چسب و قیچی', 'sort' => 3],
        ['parent' => null, 'title' => 'طراحی و نقاشی', 'slug' => 'art-and-drawing', 'desc' => 'مدادرنگی‌های تخصصی، آبرنگ و ماژیک راندو', 'sort' => 4],
    ];

    $categoryMap = [];
    foreach ($categories as $cat) {
        $catStmt->execute([
            ':parent_id' => $cat['parent'],
            ':title' => $cat['title'],
            ':slug' => $cat['slug'],
            ':description' => $cat['desc'],
            ':sort_order' => $cat['sort'],
        ]);
        $categoryMap[$cat['slug']] = (int)$pdo->lastInsertId();
    }

    // Subcategories for نوشت‌افزار
    $writingId = $categoryMap['writing-instruments'] ?: 1;
    $subCategories = [
        ['parent' => $writingId, 'title' => 'روان‌نویس و خودنویس', 'slug' => 'rollerball-pens', 'desc' => 'روان‌نویس‌های اداری و تجاری با دوام بالا', 'sort' => 1],
        ['parent' => $writingId, 'title' => 'اتود و مداد مکانیکی', 'slug' => 'mechanical-pencils', 'desc' => 'اتودهای مهندسی و رسم تکنیکال', 'sort' => 2],
    ];
    foreach ($subCategories as $sub) {
        $catStmt->execute([
            ':parent_id' => $sub['parent'],
            ':title' => $sub['title'],
            ':slug' => $sub['slug'],
            ':description' => $sub['desc'],
            ':sort_order' => $sub['sort'],
        ]);
        $categoryMap[$sub['slug']] = (int)$pdo->lastInsertId();
    }
    echo "       ✓ Categories and sub-categories inserted.\n";

    // 3. Seed Products
    echo "[3/5] Seeding Stationery Products & Inventory...\n";
    $prodStmt = $pdo->prepare("
        INSERT INTO `products` 
        (`category_id`, `title`, `slug`, `sku`, `barcode`, `brand`, `summary`, `description`, `price`, `compare_at_price`, `stock_quantity`, `is_active`, `is_featured`, `primary_image`)
        VALUES 
        (:category_id, :title, :slug, :sku, :barcode, :brand, :summary, :description, :price, :compare_at_price, :stock_quantity, 1, :is_featured, :primary_image)
        ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `price` = VALUES(`price`)
    ");

    $products = [
        [
            'category_id' => $categoryMap['rollerball-pens'] ?: $writingId,
            'title' => 'روان‌نویس اشنایدر مدل One Business سایز 0.6 میلی‌متر',
            'slug' => 'schneider-one-business-rollerball-pen-06mm',
            'sku' => 'SCH-OB-06',
            'barcode' => '4004675098719',
            'brand' => 'Schneider',
            'summary' => 'روان‌نویس با کیفیت آلمانی، مجهز به فناوری Super-Flow برای نگارش یکنواخت و جوهر ضدآب.',
            'desc' => '<p>روان‌نویس One Business اشنایدر یکی از محبوب‌ترین ابزارهای نگارش اداری و تجاری در جهان است. این محصول با نوک ساچمه‌ای استیل و سیستم گردش جوهر پیشرفته، از قطع شدن جوهر یا پس دادن آن کاملاً جلوگیری می‌کند.</p>',
            'price' => 125000,
            'compare_price' => 145000,
            'stock' => 120,
            'featured' => 1,
            'image' => '/assets/images/products/schneider-one-business.webp',
        ],
        [
            'category_id' => $categoryMap['mechanical-pencils'] ?: $writingId,
            'title' => 'اتود 0.5 میلی‌متری روترینگ مدل Tikky بدنه مات',
            'slug' => 'rotring-tikky-mechanical-pencil-05mm',
            'sku' => 'ROT-TIKKY-05',
            'barcode' => '3501179069116',
            'brand' => 'rOtring',
            'summary' => 'اتود مهندسی روترینگ تیکی، استاندارد طراحی و خوش‌دستی با گریپ ارگونومیک ضدلغزش.',
            'desc' => '<p>اتود روترینگ مدل Tikky آلمان با لوله راهنمای برنجی ثابت، برای طراحی دقیق با خط‌کش و شابلون ایده‌آل است. گریپ لاستیکی نرم مانع از خستگی انگشتان در طول ساعات طولانی نوشتن می‌شود.</p>',
            'price' => 240000,
            'compare_price' => 280000,
            'stock' => 75,
            'featured' => 1,
            'image' => '/assets/images/products/rotring-tikky-05.webp',
        ],
        [
            'category_id' => $categoryMap['art-and-drawing'] ?: 4,
            'title' => 'مداد رنگی 24 رنگ فابر-کاستل مدل Classic قوطی فلزی',
            'slug' => 'faber-castell-classic-colour-pencils-24-tin',
            'sku' => 'FC-CLASSIC-24T',
            'barcode' => '4005401158249',
            'brand' => 'Faber-Castell',
            'summary' => 'مداد رنگی کلاسیک فابرکاستل با رنگدانه‌های درخشان و مغزی مقاوم در برابر شکستن با فناوری SV.',
            'desc' => '<p>مجموعه مدادرنگی ۲۴ رنگ فابر کاستل در جعبه فلزی محافظ، انتخابی عالی برای هنرجویان، دانشجویان معماری و عاشقان نقاشی است. چوب به کار رفته از جنگل‌های پایدار تامین شده و دارای نشان اکو است.</p>',
            'price' => 590000,
            'compare_price' => 650000,
            'stock' => 45,
            'featured' => 1,
            'image' => '/assets/images/products/faber-castell-24.webp',
        ],
        [
            'category_id' => $categoryMap['notebooks-and-paper'] ?: 2,
            'title' => 'دفتر ۱۰۰ برگ سیمی جلد سخت پاپکو وزیری',
            'slug' => 'papco-spiral-hardcover-notebook-100-sheets',
            'sku' => 'PAP-NB-100',
            'barcode' => '6260145201103',
            'brand' => 'PAPCO',
            'summary' => 'دفتر یادداشت و مشق پاپکو با کاغذ ۷۰ گرم مرغوب و جلد گالینگوری سخت ضدآب.',
            'desc' => '<p>این دفتر با سیم دوتایی آبکاری شده استیل و جلد مقاوم مقوایی سخت، ماندگاری و استحکام بالایی برای استفاده دانشگاهی و اداری ارائه می‌دهد.</p>',
            'price' => 135000,
            'compare_price' => 150000,
            'stock' => 200,
            'featured' => 0,
            'image' => '/assets/images/products/papco-notebook-100.webp',
        ],
        [
            'category_id' => $writingId,
            'title' => 'خودکار پنتر مدل DP-105 نیم ژل نوک 0.7 بسته 4 رنگ',
            'slug' => 'panter-dp105-semi-gel-pen-4pack',
            'sku' => 'PAN-DP105-4P',
            'barcode' => '6261234500122',
            'brand' => 'Panter',
            'summary' => 'بسته ۴ رنگ اصلی خودکار نیم‌ژل پنتر، نرم، روان و بدون جوهرریزی.',
            'desc' => '<p>خودکار پنتر با نوک استیل ضدزنگ و جوهر با ویسکوزیته پایین، نگارش روان و تمیزی را با ضخامت خط 0.7 میلی‌متر رقم می‌زند.</p>',
            'price' => 88000,
            'compare_price' => 98000,
            'stock' => 150,
            'featured' => 0,
            'image' => '/assets/images/products/panter-dp105-4pack.webp',
        ]
    ];

    $productIds = [];
    foreach ($products as $p) {
        $prodStmt->execute([
            ':category_id' => $p['category_id'],
            ':title' => $p['title'],
            ':slug' => $p['slug'],
            ':sku' => $p['sku'],
            ':barcode' => $p['barcode'],
            ':brand' => $p['brand'],
            ':summary' => $p['summary'],
            ':description' => $p['desc'],
            ':price' => $p['price'],
            ':compare_at_price' => $p['compare_price'],
            ':stock_quantity' => $p['stock'],
            ':is_featured' => $p['featured'],
            ':primary_image' => $p['image'],
        ]);
        $productIds[$p['sku']] = (int)$pdo->lastInsertId();
    }
    echo "       ✓ 5 Stationery Products inserted.\n";

    // 4. Seed Product Variants
    echo "[4/5] Seeding Product Variants (Color options & nib sizes)...\n";
    $varStmt = $pdo->prepare("
        INSERT INTO `product_variants` 
        (`product_id`, `sku`, `attribute_name`, `attribute_value`, `color_hex`, `additional_price`, `stock_quantity`, `is_active`)
        VALUES 
        (:product_id, :sku, :attr_name, :attr_val, :color_hex, :additional_price, :stock, 1)
        ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`)
    ");

    $schneiderId = $productIds['SCH-OB-06'] ?: 1;
    $variants = [
        ['prod_id' => $schneiderId, 'sku' => 'SCH-OB-06-BLU', 'name' => 'رنگ جوهر', 'val' => 'آبی', 'hex' => '#0d6efd', 'price' => 0, 'stock' => 50],
        ['prod_id' => $schneiderId, 'sku' => 'SCH-OB-06-BLK', 'name' => 'رنگ جوهر', 'val' => 'مشکی', 'hex' => '#212529', 'price' => 0, 'stock' => 45],
        ['prod_id' => $schneiderId, 'sku' => 'SCH-OB-06-RED', 'name' => 'رنگ جوهر', 'val' => 'قرمز', 'hex' => '#dc3545', 'price' => 0, 'stock' => 25],
    ];
    foreach ($variants as $v) {
        $varStmt->execute([
            ':product_id' => $v['prod_id'],
            ':sku' => $v['sku'],
            ':attr_name' => $v['name'],
            ':attr_val' => $v['val'],
            ':color_hex' => $v['hex'],
            ':additional_price' => $v['price'],
            ':stock' => $v['stock'],
        ]);
    }
    echo "       ✓ Variants added for Schneider One Business.\n";

    // 5. Seed Discount Coupons
    echo "[5/5] Seeding Discount Coupons...\n";
    $couponStmt = $pdo->prepare("
        INSERT INTO `coupons` 
        (`code`, `type`, `value`, `max_discount_amount`, `min_order_amount`, `usage_limit`, `used_count`, `is_active`)
        VALUES 
        (:code, :type, :value, :max_discount, :min_order, :limit, 0, 1)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ");

    $coupons = [
        ['code' => 'WELCOME10', 'type' => 'percentage', 'val' => 10.00, 'max' => 50000, 'min' => 100000, 'limit' => 1000],
        ['code' => 'TAHRIR50', 'type' => 'fixed', 'val' => 50000, 'max' => null, 'min' => 250000, 'limit' => 500],
    ];
    foreach ($coupons as $c) {
        $couponStmt->execute([
            ':code' => $c['code'],
            ':type' => $c['type'],
            ':value' => $c['val'],
            ':max_discount' => $c['max'],
            ':min_order' => $c['min'],
            ':limit' => $c['limit'],
        ]);
    }
    echo "       ✓ Coupons WELCOME10 (10%) and TAHRIR50 (50,000 Tomans) inserted.\n";

    $pdo->commit();
    echo "\n Database Seeding successfully completed!\n";
    exit(0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n Seeding Failed: " . $e->getMessage() . "\n";
    echo " File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
    exit(1);
}
