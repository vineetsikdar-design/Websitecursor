-- ZENTRAXX STORE - Database Schema
-- Import this file in phpMyAdmin, then edit config.php credentials.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `wallet_balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `referral_code` varchar(20) DEFAULT NULL,
  `referred_by` int unsigned DEFAULT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_email` (`email`),
  UNIQUE KEY `uniq_users_ref_code` (`referral_code`),
  KEY `idx_users_referred_by` (`referred_by`),
  CONSTRAINT `fk_users_referred_by` FOREIGN KEY (`referred_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `description` text,
  `price` decimal(12,2) NOT NULL,
  `stock` int unsigned NOT NULL DEFAULT '0',
  `download_link` varchar(255) DEFAULT NULL,
  `is_hidden` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_products_visible` (`is_hidden`, `stock`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `product_id` int unsigned DEFAULT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `total_amount` decimal(12,2) NOT NULL,
  `wallet_used` decimal(12,2) NOT NULL DEFAULT '0.00',
  `upi_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `utr` varchar(22) DEFAULT NULL,
  `screenshot_path` varchar(255) DEFAULT NULL,
  `screenshot_hash` char(64) DEFAULT NULL,
  `status` enum('pending','submitted','completed','cancelled') NOT NULL DEFAULT 'pending',
  `wallet_refunded` tinyint(1) NOT NULL DEFAULT '0',
  `admin_note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_orders_utr` (`utr`),
  UNIQUE KEY `uniq_orders_screenshot_hash` (`screenshot_hash`),
  KEY `idx_orders_user` (`user_id`),
  KEY `idx_orders_product` (`product_id`),
  KEY `idx_orders_status_time` (`status`,`created_at`),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_orders_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `id` tinyint unsigned NOT NULL DEFAULT '1',
  `site_name` varchar(100) NOT NULL DEFAULT 'ZENTRAXX STORE',
  `upi_id` varchar(120) NOT NULL DEFAULT 'demo@upi',
  `upi_name` varchar(120) NOT NULL DEFAULT 'Zentraxx Store',
  `payment_instructions` text,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`id`, `site_name`, `upi_id`, `upi_name`, `payment_instructions`)
VALUES
(1, 'ZENTRAXX STORE', 'demo@upi', 'Zentraxx Store', '1) Pay exact UPI amount.\n2) Enter valid UTR.\n3) Upload screenshot (JPG/PNG).')
ON DUPLICATE KEY UPDATE
`site_name` = VALUES(`site_name`),
`upi_id` = VALUES(`upi_id`),
`upi_name` = VALUES(`upi_name`),
`payment_instructions` = VALUES(`payment_instructions`);

COMMIT;
