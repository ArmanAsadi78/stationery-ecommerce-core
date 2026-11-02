-- =====================================================================
-- DATABASE SCHEMA: Stationery E-Commerce Platform
-- Charset: utf8mb4 (Full Unicode support for Persian / Farsi characters)
-- Collation: utf8mb4_unicode_ci
-- Engine: InnoDB (Full ACID compliance & Row-Level Locking support)
-- Normalized Form: 3NF (Third Normal Form)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `stationery_shop`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `stationery_shop`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `wishlists`;
DROP TABLE IF EXISTS `user_addresses`;
DROP TABLE IF EXISTS `contact_messages`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `torob_cache`;
DROP TABLE IF EXISTS `inventory_logs`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `coupons`;
DROP TABLE IF EXISTS `product_images`;
DROP TABLE IF EXISTS `product_variants`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. USERS TABLE
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `phone` VARCHAR(20) NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('customer', 'admin') NOT NULL DEFAULT 'customer',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `remember_token` VARCHAR(100) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_active` (`is_active`),
    INDEX `idx_users_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. CATEGORIES TABLE (Self-referencing for Sub-categories)
-- ---------------------------------------------------------------------
CREATE TABLE `categories` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `parent_id` BIGINT UNSIGNED NULL,
    `title` VARCHAR(150) NOT NULL,
    `slug` VARCHAR(191) NOT NULL UNIQUE,
    `description` TEXT NULL,
    `image_url` VARCHAR(255) NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_categories_parent` 
        FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) 
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_categories_parent` (`parent_id`),
    INDEX `idx_categories_active_order` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. PRODUCTS TABLE
-- ---------------------------------------------------------------------
CREATE TABLE `products` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(191) NOT NULL UNIQUE,
    `sku` VARCHAR(64) NOT NULL UNIQUE,
    `barcode` VARCHAR(64) NULL UNIQUE,
    `brand` VARCHAR(100) NULL,
    `summary` VARCHAR(500) NULL,
    `description` LONGTEXT NULL,
    `specifications` TEXT NULL,
    `price` DECIMAL(12, 0) UNSIGNED NOT NULL COMMENT 'Base price in Tomans',
    `compare_at_price` DECIMAL(12, 0) UNSIGNED NULL COMMENT 'Original price for discount badge',
    `stock_quantity` INT NOT NULL DEFAULT 0 COMMENT 'Available base stock',
    `min_order_quantity` INT UNSIGNED NOT NULL DEFAULT 1,
    `max_order_quantity` INT UNSIGNED NOT NULL DEFAULT 10,
    `primary_image` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
    `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_products_category` 
        FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX `idx_products_category` (`category_id`),
    INDEX `idx_products_status_stock` (`is_active`, `stock_quantity`),
    INDEX `idx_products_brand` (`brand`),
    INDEX `idx_products_price` (`price`),
    INDEX `idx_products_featured` (`is_featured`),
    FULLTEXT KEY `ft_product_search` (`title`, `summary`, `brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. PRODUCT VARIANTS TABLE (e.g. Colors, Nib Sizes, Pack Sizes)
-- ---------------------------------------------------------------------
CREATE TABLE `product_variants` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `sku` VARCHAR(64) NOT NULL UNIQUE,
    `attribute_name` VARCHAR(50) NOT NULL COMMENT 'e.g., رنگ، سایز نوک، تعداد در بسته',
    `attribute_value` VARCHAR(50) NOT NULL COMMENT 'e.g., آبی، ۰.۷ میلی‌متر، ۲۴ تایی',
    `color_hex` VARCHAR(10) NULL COMMENT 'Hex code for visual color swatches',
    `additional_price` DECIMAL(12, 0) NOT NULL DEFAULT 0 COMMENT 'Price offset relative to base product price',
    `stock_quantity` INT NOT NULL DEFAULT 0 COMMENT 'Variant specific inventory count',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_variants_product` 
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_variants_product` (`product_id`),
    INDEX `idx_variants_stock` (`stock_quantity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. PRODUCT IMAGES TABLE (Gallery with WebP paths)
-- ---------------------------------------------------------------------
CREATE TABLE `product_images` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `original_path` VARCHAR(255) NOT NULL,
    `webp_path` VARCHAR(255) NOT NULL,
    `alt_text` VARCHAR(255) NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_images_product` 
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_images_product` (`product_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. COUPONS TABLE (Discount Codes)
-- ---------------------------------------------------------------------
CREATE TABLE `coupons` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `type` ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
    `value` DECIMAL(12, 2) NOT NULL COMMENT 'Percentage value or fixed Tomans',
    `max_discount_amount` DECIMAL(12, 0) UNSIGNED NULL COMMENT 'Ceiling for percentage discounts',
    `min_order_amount` DECIMAL(12, 0) UNSIGNED NOT NULL DEFAULT 0,
    `usage_limit` INT UNSIGNED NULL COMMENT 'Max global redemptions',
    `used_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `starts_at` TIMESTAMP NULL,
    `expires_at` TIMESTAMP NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_coupons_code_active` (`code`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. ORDERS TABLE (Core Order Entity)
-- ---------------------------------------------------------------------
CREATE TABLE `orders` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_number` VARCHAR(32) NOT NULL UNIQUE,
    `user_id` BIGINT UNSIGNED NULL,
    `coupon_id` BIGINT UNSIGNED NULL,
    `subtotal` DECIMAL(12, 0) UNSIGNED NOT NULL COMMENT 'Sum of items',
    `discount_amount` DECIMAL(12, 0) UNSIGNED NOT NULL DEFAULT 0,
    `shipping_cost` DECIMAL(12, 0) UNSIGNED NOT NULL DEFAULT 0,
    `total_amount` DECIMAL(12, 0) UNSIGNED NOT NULL COMMENT 'Payable amount in Tomans',
    `status` ENUM('pending_payment', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending_payment',
    `recipient_name` VARCHAR(100) NOT NULL,
    `recipient_phone` VARCHAR(20) NOT NULL,
    `shipping_province` VARCHAR(100) NOT NULL,
    `shipping_city` VARCHAR(100) NOT NULL,
    `shipping_postal_code` VARCHAR(20) NOT NULL,
    `shipping_address` TEXT NOT NULL,
    `customer_notes` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_orders_user` 
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_orders_coupon` 
        FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) 
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_orders_user` (`user_id`),
    INDEX `idx_orders_status` (`status`),
    INDEX `idx_orders_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. ORDER ITEMS TABLE (Historical Snapshot - 3NF preserved)
-- ---------------------------------------------------------------------
CREATE TABLE `order_items` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `variant_id` BIGINT UNSIGNED NULL,
    `product_title` VARCHAR(255) NOT NULL COMMENT 'Snapshot title at purchase',
    `variant_details` VARCHAR(255) NULL COMMENT 'Snapshot variant string at purchase',
    `unit_price` DECIMAL(12, 0) UNSIGNED NOT NULL COMMENT 'Effective unit price at purchase',
    `quantity` INT UNSIGNED NOT NULL,
    `subtotal` DECIMAL(12, 0) UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_items_order` 
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_items_product` 
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_items_variant` 
        FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) 
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_order_items_order` (`order_id`),
    INDEX `idx_order_items_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. PAYMENTS TABLE (Bank / Gateway Transactions)
-- ---------------------------------------------------------------------
CREATE TABLE `payments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` BIGINT UNSIGNED NOT NULL,
    `gateway` VARCHAR(50) NOT NULL DEFAULT 'zarinpal',
    `authority` VARCHAR(100) NULL UNIQUE COMMENT 'Gateway token / authority code',
    `reference_id` VARCHAR(100) NULL UNIQUE COMMENT 'Bank tracking / RRN reference code',
    `amount` DECIMAL(12, 0) UNSIGNED NOT NULL,
    `status` ENUM('pending', 'verified', 'failed') NOT NULL DEFAULT 'pending',
    `card_pan` VARCHAR(24) NULL COMMENT 'Masked Card PAN: 603799******1234',
    `gateway_response` JSON NULL COMMENT 'Raw callback & verification payload for audit',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_payments_order` 
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX `idx_payments_order` (`order_id`),
    INDEX `idx_payments_authority` (`authority`),
    INDEX `idx_payments_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. INVENTORY LOGS TABLE (Audit trail for inventory deductions & rollbacks)
-- ---------------------------------------------------------------------
CREATE TABLE `inventory_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `variant_id` BIGINT UNSIGNED NULL,
    `order_id` BIGINT UNSIGNED NULL,
    `quantity_change` INT NOT NULL COMMENT 'Negative for deduction, positive for restore',
    `balance_after` INT NOT NULL,
    `reason` VARCHAR(100) NOT NULL COMMENT 'e.g., order_reservation, order_cancelled, manual_adjustment',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_inv_product` 
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_inv_variant` 
        FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) 
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_inv_product` (`product_id`),
    INDEX `idx_inv_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. AUDIT LOGS TABLE (Security & System Event Logging)
-- ---------------------------------------------------------------------
CREATE TABLE `audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NULL,
    `event` VARCHAR(100) NOT NULL COMMENT 'e.g., auth.login, checkout.pessimistic_lock, payment.verified',
    `entity_type` VARCHAR(50) NULL,
    `entity_id` BIGINT UNSIGNED NULL,
    `payload` JSON NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_audit_event` (`event`),
    INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
    INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. TOROB CACHE TABLE (High-performance cached snapshot for Torob API)
-- ---------------------------------------------------------------------
CREATE TABLE `torob_cache` (
    `cache_key` VARCHAR(64) PRIMARY KEY,
    `content` MEDIUMTEXT NOT NULL,
    `generated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NOT NULL,
    INDEX `idx_torob_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. CONTACT MESSAGES TABLE (Customer Inquiries & Feedback)
-- ---------------------------------------------------------------------
CREATE TABLE `contact_messages` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(20) NULL,
    `subject` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_contact_is_read` (`is_read`),
    INDEX `idx_contact_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 14. USER ADDRESSES TABLE (Customer Saved Delivery Addresses)
-- ---------------------------------------------------------------------
CREATE TABLE `user_addresses` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(50) NOT NULL DEFAULT 'آدرس اصلی',
    `recipient_name` VARCHAR(100) NOT NULL,
    `recipient_phone` VARCHAR(20) NOT NULL,
    `province` VARCHAR(100) NOT NULL,
    `city` VARCHAR(100) NOT NULL,
    `postal_code` VARCHAR(20) NOT NULL,
    `address` TEXT NOT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_user_addresses_user` 
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_user_addresses_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15. WISHLISTS TABLE (Customer Saved Favorites)
-- ---------------------------------------------------------------------
CREATE TABLE `wishlists` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_wishlists_user` 
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_wishlists_product` 
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY `uq_user_product` (`user_id`, `product_id`),
    INDEX `idx_wishlists_user` (`user_id`),
    INDEX `idx_wishlists_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
