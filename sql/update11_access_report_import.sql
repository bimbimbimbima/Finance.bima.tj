-- ============================================================
-- UPDATE v11: строгие права по кассам, редактирование отчёта,
-- удаление отдельного импортированного файла и ускорение фильтров
-- ============================================================

-- 1) Новая привилегия "Редактирование отчёта"
ALTER TABLE `account_permissions`
  ADD COLUMN IF NOT EXISTS `can_edit_report` TINYINT(1) NOT NULL DEFAULT 0;

-- 2) Привязка платежей к конкретному файлу импорта
ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `import_log_id` INT DEFAULT NULL;

-- 3) Статус paid должен поддерживаться
ALTER TABLE `payments`
  MODIFY COLUMN `status` ENUM('pending','approved','rejected','paid') DEFAULT 'approved';

-- 4) Нормализация старых сумм в TJS
UPDATE `payments`
SET `amount_tjs` = `amount` * COALESCE(NULLIF(`exchange_rate`,0),1)
WHERE (`amount_tjs` IS NULL OR `amount_tjs`=0) AND `amount` IS NOT NULL;

-- 5) Руководитель приложения BIMA: User ID 13
INSERT INTO `account_permissions` (`portal`, `account_id`, `bitrix_position`, `bitrix_user_id`, `is_manager`, `can_edit_report`)
SELECT 'bima.bitrix24.ru', 0, NULL, 13, 1, 1
WHERE NOT EXISTS (
  SELECT 1 FROM `account_permissions`
  WHERE `portal`='bima.bitrix24.ru' AND `bitrix_user_id`=13 AND `is_manager`=1
);

-- 6) Индексы. Если phpMyAdmin покажет Duplicate key name — индекс уже есть, это не критично.
ALTER TABLE `payments` ADD INDEX `idx_fin_portal_account_date2` (`portal`(100), `account_id`, `date`);
ALTER TABLE `payments` ADD INDEX `idx_fin_portal_status_date2` (`portal`(100), `status`, `date`);
ALTER TABLE `payments` ADD INDEX `idx_fin_import_log_id` (`portal`(100), `import_log_id`);
ALTER TABLE `account_permissions` ADD INDEX `idx_perm_user_manager` (`portal`(100), `bitrix_user_id`, `is_manager`);
ALTER TABLE `account_permissions` ADD INDEX `idx_perm_user_account` (`portal`(100), `bitrix_user_id`, `account_id`);
ALTER TABLE `account_permissions` ADD INDEX `idx_perm_edit_report` (`portal`(100), `bitrix_user_id`, `can_edit_report`);
