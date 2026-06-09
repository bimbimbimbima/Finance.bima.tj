-- ============================================================
--  ОБНОВЛЕНИЕ v3 — запустите в phpMyAdmin
-- ============================================================

-- Новые поля в payments
ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `currency` VARCHAR(10) DEFAULT 'TJS',
  ADD COLUMN IF NOT EXISTS `exchange_rate` DECIMAL(10,4) DEFAULT 1.0000,
  ADD COLUMN IF NOT EXISTS `amount_tjs` DECIMAL(15,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `transaction_link` VARCHAR(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `description` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `operation_code` INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `operation_type_name` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `department` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sub_department` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `region` TINYINT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `recipient_id` INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `recipient_name` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `account_type` ENUM('cash','bank') DEFAULT 'cash';

-- Таблица валют
CREATE TABLE IF NOT EXISTS `currencies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `code` VARCHAR(10) NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `rate` DECIMAL(10,4) DEFAULT 1.0000,
  `is_base` TINYINT DEFAULT 0,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `portal_code` (`portal`(100), `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица типов операций (справочник)
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
  `parent` VARCHAR(100) DEFAULT NULL,
  `color` VARCHAR(7) DEFAULT '#4A90D9'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица прав доступа к кассам
CREATE TABLE IF NOT EXISTS `account_permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `account_id` INT NOT NULL,
  `bitrix_position` VARCHAR(255) DEFAULT NULL,
  `bitrix_user_id` INT DEFAULT NULL,
  `is_manager` TINYINT DEFAULT 0,
  UNIQUE KEY `portal_acc_pos` (`portal`(100), `account_id`, `bitrix_position`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Базовые валюты
INSERT IGNORE INTO `currencies` (portal, code, name, rate, is_base) VALUES
('bima.bitrix24.ru', 'TJS', 'Таджикский сомони', 1.0000, 1),
('bima.bitrix24.ru', 'USD', 'Доллар США', 10.9000, 0),
('bima.bitrix24.ru', 'EUR', 'Евро', 11.8000, 0),
('bima.bitrix24.ru', 'RUB', 'Российский рубль', 0.1200, 0);

-- Типы операций из справочника
INSERT IGNORE INTO `operation_types` (portal, code, name, type) VALUES
('bima.bitrix24.ru', 1101, 'Оплата поставщику (товар)', 'expense'),
('bima.bitrix24.ru', 1102, 'Оплата поставщику (услуги)', 'expense'),
('bima.bitrix24.ru', 1201, 'Аванс поставщику', 'expense'),
('bima.bitrix24.ru', 2101, 'Поступление от покупателя (товар)', 'income'),
('bima.bitrix24.ru', 2102, 'Поступление от покупателя (услуги)', 'income'),
('bima.bitrix24.ru', 2201, 'Аванс от покупателя', 'income'),
('bima.bitrix24.ru', 3101, 'Перевод между счетами', 'transfer'),
('bima.bitrix24.ru', 3201, 'Внутренняя операция', 'transfer'),
('bima.bitrix24.ru', 4101, 'Выплата зарплаты', 'expense'),
('bima.bitrix24.ru', 4102, 'Аванс по зарплате', 'expense'),
('bima.bitrix24.ru', 4201, 'Премии и бонусы', 'expense'),
('bima.bitrix24.ru', 5101, 'Налог на прибыль', 'expense'),
('bima.bitrix24.ru', 5201, 'НДС', 'expense'),
('bima.bitrix24.ru', 5301, 'Страховые взносы', 'expense'),
('bima.bitrix24.ru', 5401, 'Налог на имущество', 'expense'),
('bima.bitrix24.ru', 6101, 'Покупка валюты', 'income'),
('bima.bitrix24.ru', 6201, 'Продажа валюты', 'expense');

-- Отделы
INSERT IGNORE INTO `departments` (portal, name, parent) VALUES
('bima.bitrix24.ru', 'ОМП', NULL),
('bima.bitrix24.ru', 'ГО', NULL),
('bima.bitrix24.ru', 'БО', NULL),
('bima.bitrix24.ru', 'ДМС', NULL),
('bima.bitrix24.ru', 'ОКП', NULL),
('bima.bitrix24.ru', 'ОПКП', NULL),
('bima.bitrix24.ru', 'ОПП', NULL),
('bima.bitrix24.ru', 'ОБП', NULL),
('bima.bitrix24.ru', 'ОМП', 'ОМП'),
('bima.bitrix24.ru', 'ГО', 'ГО'),
('bima.bitrix24.ru', 'ТОП', 'БО'),
('bima.bitrix24.ru', 'ФИА', 'ДМС'),
('bima.bitrix24.ru', 'Бухгалтерия', 'БО'),
('bima.bitrix24.ru', 'Маркетинг', 'БО'),
('bima.bitrix24.ru', 'ОЮС', 'БО'),
('bima.bitrix24.ru', 'Учет', 'БО'),
('bima.bitrix24.ru', 'Убытки', 'БО'),
('bima.bitrix24.ru', 'СБ', 'БО'),
('bima.bitrix24.ru', 'Перестрахование', 'БО'),
('bima.bitrix24.ru', 'ТИАС', 'БО'),
('bima.bitrix24.ru', 'АХО', 'БО'),
('bima.bitrix24.ru', 'ОКП', 'ОКП'),
('bima.bitrix24.ru', 'ОПКП', 'ОПКП'),
('bima.bitrix24.ru', 'ОПП', 'ОПП'),
('bima.bitrix24.ru', 'ОБП', 'ОБП'),
('bima.bitrix24.ru', 'ИТ', 'БО'),
('bima.bitrix24.ru', 'УЧР', 'БО'),
('bima.bitrix24.ru', 'ТЦ', 'БО');
ALTER TABLE payments MODIFY COLUMN status ENUM('pending','approved','rejected','paid') DEFAULT 'approved';
