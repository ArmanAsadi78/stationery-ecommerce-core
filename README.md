# 🖋️ Stationery E-Commerce Core (تحریرچی)
> **High-Performance, Modular MVC E-Commerce Engine built from scratch with PHP 8.2+, MySQL, FastRoute, Twig & PHPUnit.**

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B%20%7C%208.4-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![Architecture](https://img.shields.io/badge/Architecture-Clean%20MVC%20%2B%20Service%20%2B%20Repository-blue)](https://en.wikipedia.org/wiki/Multitier_architecture)
[![Database](https://img.shields.io/badge/Database-MySQL%20(3NF%20%2B%20InnoDB)-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com)
[![Testing](https://img.shields.io/badge/Tests-PHPUnit%2011-388E3C?logo=phpunit&logoColor=white)](https://phpunit.de)
[![Static Analysis](https://img.shields.io/badge/PHPStan-Level%206-orange)](https://phpstan.org)
[![Security](https://img.shields.io/badge/Security-Argon2id%20%7C%20CSRF%20%7C%20Strict%20Cookies-critical)](https://owasp.org)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

---

## 📖 Overview | درباره پروژه

**فروشگاه آنلاین تخصصی لوازم‌التحریر (تحریرچی)** یک پلتفرم فروشگاهی مدرن و فوق‌سریع است که به صورت کاملاً اختصاصی (Native) و بدون وابستگی به CMSهای آماده مانند وردپرس طراحی شده است. این پروژه به منظور دستیابی به دو هدف اساسی بنا نهاده شده است:

1. **کیفیت تجاری و عملیاتی:** آماده برای میزبانی در هاست لینوکسی/ابری، تراکنش‌های بانکی مقاوم در برابر Race Condition، کشینگ هوشمند و سازگاری کامل با وب‌سرویس ترب (Torob Integration).
2. **استاندارد سطح بالای مهندسی نرم‌افزار برای رزومه:** معماری تفکیک‌شده MVC به همراه Service Layer و Repository Pattern، رعایت دقیق استانداردهای PSR (شامل PSR-4 و PSR-12)، تست‌های خودکار با PHPUnit و تحلیل ایستای کد با PHPStan (Level 6).

---

## 🏛️ System Architecture | معماری نرم‌افزار

پروژه از الگوی معماری لایه‌ای بهره می‌برد تا اصل تفکیک وظایف (**Separation of Concerns - SoC**) و قابلیت تست‌پذیری به حداکثر برسد:

```
┌─────────────────────────────────────────────────────────────┐
│                       HTTP Request                          │
└──────────────────────────────┬──────────────────────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────┐
│              Public Entry Point (public/index.php)          │
│   - Dotenv Bootstrap & Global Exception Handler             │
│   - PSR-4 Autoloading & Config Initialization               │
└──────────────────────────────┬──────────────────────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────┐
│                 Routing Layer (FastRoute)                   │
│   - Unicode / Persian Slug Matcher                          │
│   - Middleware Pipeline (CSRF Guard, Auth, Security Headers)│
└──────────────────────────────┬──────────────────────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────┐
│                     Controllers                             │
│   - Validates incoming DTOs / Request data                  │
│   - Delegates business logic to Service Layer               │
└──────────────────────────────┬──────────────────────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────┐
│                    Service Layer (Business Logic)           │
│   - OrderService: Concurrency Lock (FOR UPDATE)             │
│   - CartService: Price, tax & discount calculations         │
│   - TorobService: Output generation & server-side caching   │
│   - PaymentService: 2-Phase verification (Authority/Verify) │
└──────────────────────────────┬──────────────────────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────┐
│            Repository Layer (Data Access Pattern)           │
│   - ProductRepository, OrderRepository, UserRepository      │
│   - Prevents N+1 Query issues with Eager Loading            │
│   - Strict PDO Prepared Statements (Zero SQL Injection)     │
└──────────────────────────────┬──────────────────────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────┐
│           MySQL 9+ Database (InnoDB, utf8mb4, 3NF)          │
└─────────────────────────────────────────────────────────────┘
```

---

## 📂 Project Directory Structure | ساختار پوشه‌بندی

```text
├── app/
│   ├── Controllers/             # Controllers (Handling requests & returning Views/JSON)
│   │   ├── Admin/               # Administrative backend controllers
│   │   ├── Api/                 # External API endpoints (e.g. Torob integration)
│   │   ├── CartController.php   # AJAX Drawer Cart controller
│   │   ├── CheckoutController.php # Order submission & inventory check
│   │   ├── HomeController.php   # Homepage & featured stationery products
│   │   └── ProductController.php # Catalog, filtering & Persian slug resolver
│   ├── Core/                    # Framework Core components
│   │   ├── Application.php      # Application orchestrator
│   │   ├── Database.php         # PDO connection singleton & transaction manager
│   │   ├── Request.php          # Encapsulated HTTP request & sanitize filters
│   │   ├── Response.php         # JSON & HTML response emitters
│   │   ├── Router.php           # FastRoute wrapper with Unicode support
│   │   ├── Session.php          # Secure cookie & session manager
│   │   ├── View.php             # Twig template engine bootstrap & custom filters
│   │   └── helpers.php          # Global utility functions (base_path, env, escape)
│   ├── Middlewares/             # HTTP Middleware pipeline
│   │   ├── AuthMiddleware.php   # Authenticated route guard
│   │   └── CsrfMiddleware.php   # CSRF token validator for POST/PUT/DELETE
│   ├── Models/                  # Domain entities & representations
│   ├── Repositories/            # Data Access Layer (Repository Pattern)
│   │   ├── Contracts/           # Repository interfaces
│   │   ├── CategoryRepository.php
│   │   ├── CouponRepository.php
│   │   ├── OrderRepository.php  # Handles pessimistic locking queries
│   │   └── ProductRepository.php # Eager loading & multi-attribute filter queries
│   ├── Services/                # Application & Domain Services (Business Logic)
│   │   ├── CartService.php      # Unit-testable Cart mathematics
│   │   ├── OrderService.php     # DB Transactions + SELECT FOR UPDATE inventory lock
│   │   ├── PaymentService.php   # 2-Phase verification payment flow
│   │   └── TorobService.php     # Torob feed generator with JSON caching
│   └── Views/                   # Twig Templates (RTL & Modern UI)
│       ├── layouts/             # Master layout, header, footer, drawer cart
│       ├── home/                # Homepage template
│       ├── products/            # Catalog, filters, and product details
│       ├── cart/                # Dedicated cart view
│       └── checkout/            # Checkout form & payment gateway redirect
├── config/                      # Environment-driven configuration files
│   ├── app.php
│   ├── database.php
│   └── payment.php
├── database/                    # Database migrations and seeders
│   ├── schema.sql               # 3NF relational schema with foreign keys and indexes
│   ├── migrate.php              # Automated schema execution script
│   └── seeders/
│       └── DatabaseSeeder.php   # Demo data generator (Products, Variants, Admin, Coupons)
├── public/                      # Web server document root
│   ├── .htaccess                # Front Controller rewriting, Gzip & Caching rules
│   ├── index.php                # Single application entry point
│   └── assets/                  # Public web assets
│       ├── css/                 # RTL stylesheet & UI styles
│       ├── js/                  # Vanilla JS / AJAX Drawer Cart scripts
│       ├── images/              # Optimized WebP product images
│       └── fonts/               # Persian web fonts (Vazirmatn / Shabnam)
├── storage/                     # Runtime data (git-ignored)
│   ├── cache/                   # Twig template cache & Torob cached JSON
│   ├── logs/                    # Audit logs & isolated payment transactions
│   └── uploads/                 # Uploaded product image files
├── tests/                       # Automated test suite
│   ├── TestCase.php             # Base test case
│   ├── Unit/                    # Unit tests for business logic
│   └── Feature/                 # Feature & integration tests
├── .env.example                 # Template environment variables
├── .gitignore                   # Standard gitignore for PHP projects
├── .htaccess                    # Root-level redirector to /public
├── composer.json                # Dependencies, PSR-4 autoloading & scripts
├── phpstan.neon                 # Static analysis configuration (Level 6)
├── phpunit.xml                  # PHPUnit test runner configuration
└── README.md                    # Project documentation
```

---

## 🚀 Quick Start Guide | راهنمای راه‌اندازی سریع

### ۱. نیازمندی‌های سیستم (Prerequisites)
- **PHP:** نسخه 8.2 یا بالاتر (سازگار با PHP 8.4)
- **اکستنشن‌های مورد نیاز PHP:** `pdo_mysql`, `mbstring`, `json`, `gd` (جهت تبدیل تصاویر به WebP)
- **MySQL / MariaDB:** نسخه 8.0 یا بالاتر (تست شده روی MySQL 9.1)
- **وب‌سرور:** Apache با ماژول‌های فعال `mod_rewrite`, `mod_headers`, `mod_deflate`, `mod_expires`
- **Composer:** ابزار مدیریت وابستگی‌های PHP

---

### ۲. مراحل نصب و اجرا (Step-by-Step Installation)

#### ۱) کلون یا باز کردن پروژه در محیط WAMP
پروژه را درون مسیر `www` در ومپ (یا مسیر دلخواه دارای دسترسی آپاچی) قرار دهید:
```bash
cd "e:\طراحی سایت\سایت لوازم تحریر"
```

#### ۲) نصب پکیج‌های وابسته با Composer
```bash
composer install
```
*(یا در صورت استفاده از فایل اختصاصی phar: `php composer.phar install`)*

#### ۳) تنظیم فایل متغیرهای محیطی (`.env`)
فایل `.env.example` را کپی کرده و به نام `.env` ذخیره کنید، سپس اطلاعات اتصال به پایگاه‌داده را در آن بررسی فرمایید:
```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=stationery_shop
DB_USERNAME=root
DB_PASSWORD=
```

#### ۴) اجرای مایگریشن خودکار جداول پایگاه‌داده
برای ساخت دیتابیس `stationery_shop` و تمامی ۱۲ جدول استاندارد 3NF، دستور زیر را اجرا کنید:
```bash
php database/migrate.php
# یا از طریق کامپوزر:
composer run migrate
```

#### ۵) اجرای Seeder داده‌های دمو و تستی
جهت درج محصولات لوازم‌التحریر (روان‌نویس اشنایدر، اتود روترینگ، مدادرنگی فابر-کاستل و...)، تنوع رنگ‌ها، کدهای تخفیف و حساب‌های کاربری:
```bash
php database/seeders/DatabaseSeeder.php
# یا از طریق کامپوزر:
composer run seed
```

---

## 🔑 اطلاعات ورود پیش‌فرض (Test Credentials)

| نقش (Role) | ایمیل (Email) | رمز عبور (Password) | دسترسی |
| :--- | :--- | :--- | :--- |
| **مدیر ارشد (Admin)** | `admin@stationery.local` | `Admin@123456` | مدیریت محصولات، سفارش‌ها و انبار |
| **کاربر دمو (Customer)** | `customer@stationery.local` | `Customer@123456` | ثبت سفارش، سبد خرید، اعمال تخفیف |

---

## 🧪 اجرای تست‌ها و تضمین کیفیت (Testing & QA)

### ۱. اجرای تست‌های خودکار با PHPUnit
```bash
composer test
# یا
vendor/bin/phpunit --testdox
```

### ۲. اجرای تحلیل ایستای کد با PHPStan (Level 6)
```bash
composer analyse
# یا
vendor/bin/phpstan analyse
```

---

## 🛡️ ویژگی‌های مهندسی‌شده و استانداردهای کلیدی

### ۱. پیشگیری از Race Condition و رقابت بر سر موجودی
- استفاده از **Pessimistic Locking** (`SELECT stock_quantity FROM products WHERE id = :id FOR UPDATE`) درون یک Database Transaction یکپارچه هنگام ثبت سفارش.
- ثبت تراکنش در جدول حسابرسی `inventory_logs` جهت رهگیری لحظه‌ای کسر یا بازگشت موجودی.

### ۲. اتصال بهینه و ایمن با ترب (Torob Integration)
- اندپوینت `/api/torob-products` مطابق مستندات رسمی ترب پیاده‌سازی شده و از مکانیزم کشینگ سروری در جدول `torob_cache` و پوشه `storage/cache/` با TTL قابل تنظیم بهره می‌برد تا از فشار مستقیم به دیتابیس در کرال‌های متوالی جلوگیری شود.

### ۳. امنیت درگاه پرداخت (2-Phase Verification)
- تراکنش‌ها ابتدا در جدول `payments` با وضعیت `pending` ثبت می‌شوند.
- در مرحله کال‌بک، مبلغ و شناسه تراکنش با استعلام سمت سرور اعتبارسنجی شده و تنها در صورت تأیید پاسخ بانک، وضعیت به `verified` تغییر کرده و سفارش نهایی می‌شود.
- ذخیره ایزوله لاگ‌های مالی در `storage/logs/payments.log`.

### ۴. سازگاری کامل WAMP با سرور لینوکس
- تفکیک مسیرها با ثابت استاندارد `DIRECTORY_SEPARATOR`.
- نام‌گذاری دقیق کلاس‌ها و فایل‌ها بر اساس استاندارد PSR-4 با حساسیت به حروف کوچک و بزرگ (Case-Sensitivity).
- پیکربندی `.htaccess` دو مرحله‌ای (Root + Public) جهت هدایت امن درخواست‌ها به Front Controller.

---

## 📝 راهنمای کامیت‌ها (Conventional Commits Guide)

برای توسعه هر ویژگی از قالب استاندارد زیر استفاده می‌شود:

- `feat(core): setup clean mvc architecture, fastroute and twig engine`
- `feat(db): design 3nf relational schema and automated seeder`
- `feat(cart): implement ajax drawer cart with pessimistic stock validation`
- `feat(torob): add cached api endpoint for torob product indexing`
- `fix(checkout): resolve race condition using select for update transaction`
- `test(order): add phpunit tests for order calculation and out-of-stock scenarios`
- `refactor(repo): optimize eager loading queries to eliminate n+1 overhead`

---

## 📄 License
This project is open-source software licensed under the [MIT license](LICENSE).
