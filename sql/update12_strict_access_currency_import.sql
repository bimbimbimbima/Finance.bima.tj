-- ============================================================
-- UPDATE v12: строгие права, разделы настроек, фиксация курса,
-- прогресс импорта и редактирование существующих прав
-- ============================================================

CREATE TABLE IF NOT EXISTS `account_permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `account_id` INT NOT NULL DEFAULT 0,
  `bitrix_position` VARCHAR(255) DEFAULT NULL,
  `bitrix_user_id` INT DEFAULT NULL,
  `is_manager` TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `account_permissions`
  ADD COLUMN IF NOT EXISTS `can_edit_report` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `can_settings_accounts` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `can_settings_categories` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `can_settings_currencies` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `can_settings_departments` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `can_settings_import` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `import_log_id` INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `exchange_rate_date` DATE DEFAULT NULL;

ALTER TABLE `payments`
  MODIFY COLUMN `status` ENUM('pending','approved','rejected','paid') DEFAULT 'approved';

CREATE TABLE IF NOT EXISTS `currency_rates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `code` VARCHAR(10) NOT NULL,
  `rate_date` DATE NOT NULL,
  `rate` DECIMAL(15,6) NOT NULL DEFAULT 1.000000,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_currency_rate` (`portal`(100),`code`,`rate_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Фиксируем TJS-суммы для старых записей. После этого изменение текущего курса
-- не меняет вчерашние/прошлые отчёты.
UPDATE `payments`
SET `amount_tjs` = `amount` * COALESCE(NULLIF(`exchange_rate`,0),1),
    `exchange_rate_date` = COALESCE(`exchange_rate_date`, `date`)
WHERE `amount` IS NOT NULL
  AND (`amount_tjs` IS NULL OR `amount_tjs`=0);

-- Руководитель BIMA: User ID 13. Даём полный доступ ко всем настройкам и отчётам.
INSERT INTO `account_permissions`
(`portal`, `account_id`, `bitrix_position`, `bitrix_user_id`, `is_manager`, `can_edit_report`,
 `can_settings_accounts`, `can_settings_categories`, `can_settings_currencies`, `can_settings_departments`, `can_settings_import`)
SELECT 'bima.bitrix24.ru', 0, NULL, 13, 1, 1, 1, 1, 1, 1, 1
WHERE NOT EXISTS (
  SELECT 1 FROM `account_permissions`
  WHERE `portal`='bima.bitrix24.ru' AND `bitrix_user_id`=13 AND `is_manager`=1
);

UPDATE `account_permissions`
SET `can_edit_report`=1,
    `can_settings_accounts`=1,
    `can_settings_categories`=1,
    `can_settings_currencies`=1,
    `can_settings_departments`=1,
    `can_settings_import`=1
WHERE `portal`='bima.bitrix24.ru' AND `bitrix_user_id`=13 AND `is_manager`=1;
