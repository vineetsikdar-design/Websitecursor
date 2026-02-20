-- ZENTRAXX STORE - Database Schema
-- Import this in phpMyAdmin (or run install.php)

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  wallet DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  referral_code VARCHAR(20) NULL,
  referred_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_referral (referral_code),
  KEY idx_users_referred_by (referred_by),
  CONSTRAINT fk_users_referred_by FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  price DECIMAL(10,2) NOT NULL,
  stock INT NOT NULL DEFAULT 0,
  is_visible TINYINT(1) NOT NULL DEFAULT 1,
  file_path VARCHAR(255) NULL,
  file_name VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_products_visible (is_visible),
  KEY idx_products_stock (stock)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  status ENUM('pending','submitted','completed','cancelled') NOT NULL DEFAULT 'pending',
  price DECIMAL(10,2) NOT NULL,
  wallet_used DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  upi_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  utr VARCHAR(22) NULL,
  screenshot_path VARCHAR(255) NULL,
  screenshot_sha256 CHAR(64) NULL,
  stock_released TINYINT(1) NOT NULL DEFAULT 0,
  wallet_refunded TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at DATETIME NULL,
  completed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_utr (utr),
  UNIQUE KEY uq_orders_shot (screenshot_sha256),
  KEY idx_orders_user (user_id),
  KEY idx_orders_product (product_id),
  KEY idx_orders_status (status),
  KEY idx_orders_created (created_at),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_orders_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin account (change password after login)
INSERT IGNORE INTO users (id, email, password_hash, wallet, is_admin, referral_code, referred_by, created_at)
VALUES (1, 'admin@zentraxx.local', '$2y$10$donUVm57u.v4LqLdUw4HYejslYu1fMaA4DMisha68DeBxrgpj.oDe', 0.00, 1, 'ADMIN', NULL, NOW());

-- Sample product (optional)
INSERT IGNORE INTO products (id, name, description, price, stock, is_visible, file_path, file_name, created_at, updated_at)
VALUES (1, 'Sample Digital Item', 'This is a sample product. Admin can delete/hide it and upload a real digital file.', 99.00, 10, 1, NULL, NULL, NOW(), NOW());

