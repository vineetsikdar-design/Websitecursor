-- ZENTRAXX STORE - MySQL schema (v2)
-- Import this in phpMyAdmin, then edit config.php with your DB credentials, then open install.php.

SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(64) NOT NULL,
  `value` TEXT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  username VARCHAR(50) NOT NULL,
  display_name VARCHAR(80) NULL,
  avatar_url VARCHAR(255) NULL,
  password_hash VARCHAR(255) NOT NULL,
  wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  referral_code VARCHAR(32) NOT NULL,
  referred_by INT UNSIGNED NULL,
  referral_eligible_until DATETIME NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  is_banned TINYINT(1) NOT NULL DEFAULT 0,
  welcome_seen_at DATETIME NULL,
  orders_last_seen_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_referral (referral_code),
  KEY idx_users_referred_by (referred_by),
  CONSTRAINT fk_users_referred_by FOREIGN KEY (referred_by) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  image_url VARCHAR(255) NULL,
  image_path VARCHAR(255) NULL,
  is_hidden TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name),
  KEY idx_categories_hidden (is_hidden),
  KEY idx_categories_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id INT UNSIGNED NULL,
  type ENUM('key','file','account') NOT NULL DEFAULT 'key',
  name VARCHAR(190) NOT NULL,
  short_desc VARCHAR(255) NULL,
  description TEXT NULL,
  image_url VARCHAR(255) NULL,
  image_path VARCHAR(255) NULL,
  download_file VARCHAR(255) NULL,
  stock INT NOT NULL DEFAULT 0, -- used mainly for account-type (single stock)
  is_hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_products_category (category_id),
  KEY idx_products_hidden (is_hidden),
  KEY idx_products_type (type),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_variants (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id INT UNSIGNED NOT NULL,
  label VARCHAR(80) NOT NULL, -- e.g. 7 DAYS
  price DECIMAL(12,2) NOT NULL,
  stock INT NOT NULL DEFAULT 0,
  is_hidden TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_variants_product (product_id),
  KEY idx_variants_hidden (is_hidden),
  CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupons (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(32) NOT NULL,
  discount_type ENUM('percent','flat') NOT NULL,
  discount_value DECIMAL(12,2) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  max_uses_total INT NULL,
  max_uses_per_user INT NULL,
  min_order_amount DECIMAL(12,2) NULL,
  applies_to ENUM('all','category','product','type') NOT NULL DEFAULT 'all',
  category_id INT UNSIGNED NULL,
  product_id INT UNSIGNED NULL,
  product_type ENUM('key','file','account') NULL,
  note VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_coupons_code (code),
  KEY idx_coupons_active (is_active),
  CONSTRAINT fk_coupons_category FOREIGN KEY (category_id) REFERENCES categories(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_coupons_product FOREIGN KEY (product_id) REFERENCES products(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  status ENUM('pending','submitted','completed','cancelled') NOT NULL DEFAULT 'pending',
  payment_method ENUM('upi','binance','wallet') NULL,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  wallet_used DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  pay_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  coupon_code VARCHAR(32) NULL,
  referral_paid TINYINT(1) NOT NULL DEFAULT 0,
  referral_paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  telegram_username VARCHAR(64) NULL,
  whatsapp_number VARCHAR(32) NULL,
  reference_id VARCHAR(32) NULL, -- UTR / TXID
  screenshot_path VARCHAR(255) NULL,
  screenshot_sha256 CHAR(64) NULL,
  screenshot_deleted_at DATETIME NULL,
  delivery_json MEDIUMTEXT NULL,
  delivered_at DATETIME NULL,
  cancel_reason VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at DATETIME NULL,
  completed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_orders_user (user_id),
  KEY idx_orders_status_created (status, created_at),
  KEY idx_orders_updated (updated_at),
  UNIQUE KEY uq_orders_reference (reference_id),
  UNIQUE KEY uq_orders_screenshot_hash (screenshot_sha256),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  variant_id INT UNSIGNED NULL,
  product_type ENUM('key','file','account') NOT NULL,
  product_name VARCHAR(190) NOT NULL,
  variant_label VARCHAR(80) NULL,
  qty INT NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_items_order (order_id),
  KEY idx_items_product (product_id),
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupon_uses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  coupon_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_coupon_user (coupon_id, user_id),
  KEY idx_coupon_order (order_id),
  CONSTRAINT fk_coupon_uses_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_coupon_uses_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_coupon_uses_order FOREIGN KEY (order_id) REFERENCES orders(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_topups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method ENUM('upi','binance') NOT NULL,
  telegram_username VARCHAR(64) NULL,
  whatsapp_number VARCHAR(32) NULL,
  reference_id VARCHAR(32) NOT NULL,
  screenshot_path VARCHAR(255) NULL,
  screenshot_sha256 CHAR(64) NULL,
  screenshot_deleted_at DATETIME NULL,
  status ENUM('pending','approved','cancelled') NOT NULL DEFAULT 'pending',
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  cancel_reason VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_topups_reference (reference_id),
  UNIQUE KEY uq_topups_screenshot_hash (screenshot_sha256),
  KEY idx_topups_user (user_id),
  KEY idx_topups_status_created (status, created_at),
  CONSTRAINT fk_topups_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_topups_admin FOREIGN KEY (reviewed_by) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_resets_user (user_id),
  KEY idx_resets_expires (expires_at),
  CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Defaults (admin can change in admin panel)
INSERT IGNORE INTO settings (`key`, `value`) VALUES
('installed', '0'),
('maintenance_mode', '0'),
('stop_signup', '0'),
('stop_login', '0'),
('wallet_enabled', '0'),
('wallet_mode', 'partial'),
('upi_vpa', ''),
('upi_payee', 'ZENTRAXX STORE'),
('binance_id', ''),
('announcement_enabled', '0'),
('announcement_text', ''),
('welcome_enabled', '1'),
('welcome_text', 'Welcome to ZENTRAXX STORE!'),
('offer_enabled', '0'),
('offer_title', 'Daily Offer'),
('offer_text', ''),
('offer_image_url', ''),
('telegram_bot_token', ''),
('telegram_chat_id', ''),
('smtp_enabled', '0'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_user', ''),
('smtp_pass', ''),
('smtp_from_email', ''),
('smtp_from_name', 'ZENTRAXX STORE'),
('notify_email', '');
