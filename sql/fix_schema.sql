-- ============================================================
-- FIX: Запустите в phpMyAdmin если операции не сохраняются
-- ============================================================

-- Добавляем недостающие колонки (IF NOT EXISTS — безопасно)
ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `operation_type`      ENUM('income','expense','transfer','topup') DEFAULT 'income',
  ADD COLUMN IF NOT EXISTS `status`              ENUM('pending','approved','rejected','paid') DEFAULT 'approved',
  ADD COLUMN IF NOT EXISTS `transfer_to`         INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `currency`            VARCHAR(10) DEFAULT 'TJS',
  ADD COLUMN IF NOT EXISTS `exchange_rate`       DECIMAL(10,4) DEFAULT 1.0000,
  ADD COLUMN IF NOT EXISTS `amount_tjs`          DECIMAL(15,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `transaction_link`    VARCHAR(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `description`         TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `operation_code`      INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `operation_type_name` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `department`          VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sub_department`      VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `region`              TINYINT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `recipient_id`        INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `recipient_name`      VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `account_type`        ENUM('cash','bank') DEFAULT 'cash',
  ADD COLUMN IF NOT EXISTS `approved_by_id`      INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `approved_by_name`    VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `contact_b24_id`      INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `contact_name`        VARCHAR(255) DEFAULT NULL;

-- Обновляем ENUM статуса (добавляем paid)
ALTER TABLE `payments` MODIFY COLUMN `status`
  ENUM('pending','approved','rejected','paid') DEFAULT 'approved';

-- Таблица валют
CREATE TABLE IF NOT EXISTS `currencies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `code` VARCHAR(10) NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `rate` DECIMAL(10,4) DEFAULT 1.0000,
  `is_base` TINYINT DEFAULT 0,
  UNIQUE KEY `portal_code` (`portal`(100), `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица типов операций
CREATE TABLE IF NOT EXISTS `operation_types` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `code` INT NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `type` ENUM('income','expense','transfer') DEFAULT 'expense',
  UNIQUE KEY `portal_code` (`portal`(100), `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица отделов
CREATE TABLE IF NOT EXISTS `departments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `parent` VARCHAR(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица прав доступа
CREATE TABLE IF NOT EXISTS `account_permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `account_id` INT NOT NULL,
  `bitrix_position` VARCHAR(255) DEFAULT NULL,
  `bitrix_user_id` INT DEFAULT NULL,
  `is_manager` TINYINT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Базовые валюты (для портала)
INSERT IGNORE INTO `currencies` (portal, code, name, rate, is_base) VALUES
('bima.bitrix24.ru', 'TJS', 'Таджикский сомони', 1.0000, 1),
('bima.bitrix24.ru', 'USD', 'Доллар США', 10.9000, 0),
('bima.bitrix24.ru', 'EUR', 'Евро', 11.8000, 0),
('bima.bitrix24.ru', 'RUB', 'Российский рубль', 0.1200, 0);

-- Новая привилегия: право редактирования отчёта
ALTER TABLE `account_permissions`
  ADD COLUMN IF NOT EXISTS `can_edit_report` TINYINT DEFAULT 0;

