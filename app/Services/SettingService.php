<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

class SettingService
{
    /** @var array<string, string>|null */
    private static ?array $cachedSettings = null;

    /**
     * Retrieve all settings as key-value pairs with fallback defaults.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$cachedSettings !== null) {
            return self::$cachedSettings;
        }

        $defaults = [
            'top_announcement_text' => 'ارسال سریع و رایگان به سراسر کشور برای خریدهای بالای ۳۰۰ هزار تومان 🚀',
            'support_phone' => '۰۲۱-۸۸۸۸۴۳۲۱',
            'support_email' => 'support@tahrirchi.com',
            'store_address' => 'تهران، میدان انقلاب، خیابان ۱۲ فروردین، پلاک ۴۲',
            'working_hours' => 'شنبه تا پنج‌شنبه: ۹ الی ۱۸',
            'footer_copyright' => '© ۲۰۲۶ تمامی حقوق مادی و معنوی برای فروشگاه تحریرچی محفوظ است.',
        ];

        try {
            $db = Database::getInstance();
            /** @var list<array{setting_key: string, setting_value: ?string}> $rows */
            $rows = $db->fetchAll("SELECT `setting_key`, `setting_value` FROM `site_settings`");
            $settings = $defaults;
            foreach ($rows as $row) {
                $key = $row['setting_key'];
                if ($key !== '') {
                    $settings[$key] = (string) ($row['setting_value'] ?? '');
                }
            }
            self::$cachedSettings = $settings;
            return self::$cachedSettings;
        } catch (Throwable) {
            return $defaults;
        }
    }

    /**
     * Retrieve single setting value by key.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    /**
     * Upsert a setting key-value pair.
     */
    public static function set(string $key, ?string $value): void
    {
        try {
            $db = Database::getInstance();
            $db->execute("
                INSERT INTO `site_settings` (`setting_key`, `setting_value`) 
                VALUES (:k, :v)
                ON DUPLICATE KEY UPDATE `setting_value` = :v2, `updated_at` = CURRENT_TIMESTAMP
            ", [
                ':k' => $key,
                ':v' => $value,
                ':v2' => $value,
            ]);
            self::flush();
        } catch (Throwable) {
            // Silently handle if db unavailable
        }
    }

    /**
     * Flush memory cache.
     */
    public static function flush(): void
    {
        self::$cachedSettings = null;
    }
}
