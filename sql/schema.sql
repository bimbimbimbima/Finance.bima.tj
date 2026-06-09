-- ============================================================
--  Финансовое приложение для Битрикс24
--  Запустите этот файл в phpMyAdmin на InfinityFree
-- ============================================================

CREATE TABLE IF NOT EXISTS `tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `access_token` TEXT NOT NULL,
  `refresh_token` TEXT NOT NULL,
  `expires_at` INT NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `portal` (`portal`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `accounts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `balance` DECIMAL(15,2) DEFAULT 0.00,
  `currency` VARCHAR(10) DEFAULT 'сом',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('income','expense') NOT NULL,
  `color` VARCHAR(7) DEFAULT '#4A90D9',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `type` ENUM('income','expense') NOT NULL,
  `date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `account_id` INT DEFAULT NULL,
  `category_id` INT DEFAULT NULL,
  `deal_id` INT DEFAULT NULL,
  `company` VARCHAR(255) DEFAULT NULL,
  `comment` TEXT DEFAULT NULL,
  `source` ENUM('manual','import') DEFAULT 'manual',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `portal` (`portal`(100)),
  KEY `date` (`date`),
  KEY `type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `import_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `filename` VARCHAR(255),
  `rows_total` INT DEFAULT 0,
  `rows_imported` INT DEFAULT 0,
  `rows_skipped` INT DEFAULT 0,
  `imported_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Тестовые категории (добавятся при установке)
-- INSERT INTO categories (portal, name, type) VALUES
-- ('example.bitrix24.ru', 'Продажи', 'income'),
-- ('example.bitrix24.ru', 'Зарплата', 'expense'),
-- ('example.bitrix24.ru', 'Аренда', 'expense');

-- ============================================================
--  ОБНОВЛЕНИЕ: запустите в phpMyAdmin если уже установлено
-- ============================================================
-- ALTER TABLE `payments`
--   ADD COLUMN IF NOT EXISTS `status` ENUM('pending','approved','rejected') DEFAULT 'approved',
--   ADD COLUMN IF NOT EXISTS `transfer_to` INT DEFAULT NULL,
--   ADD COLUMN IF NOT EXISTS `operation_type` ENUM('income','expense','transfer','topup') DEFAULT 'income';
-- UPDATE payments SET operation_type=type WHERE operation_type='income' AND type='expense';
