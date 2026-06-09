-- ============================================================
-- UPDATE v17: видимость разделов системы + синхронизация код/статья
-- ============================================================

ALTER TABLE `account_permissions`
  ADD COLUMN IF NOT EXISTS `can_view_dashboard` TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `can_view_payments`  TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `can_add_payment`    TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `can_view_import`    TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `can_view_report`    TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `can_view_settings`  TINYINT(1) NOT NULL DEFAULT 0;

-- Руководители/администраторы видят все разделы системы.
UPDATE `account_permissions`
SET `can_view_dashboard` = 1,
    `can_view_payments`  = 1,
    `can_add_payment`    = 1,
    `can_view_import`    = 1,
    `can_view_report`    = 1,
    `can_view_settings`  = 1
WHERE `is_manager` = 1;

-- Если кому-то уже делегирован доступ к разделам настроек, показываем пункт "Настройки".
UPDATE `account_permissions`
SET `can_view_settings` = 1
WHERE COALESCE(`can_settings_accounts`,0)=1
   OR COALESCE(`can_settings_categories`,0)=1
   OR COALESCE(`can_settings_currencies`,0)=1
   OR COALESCE(`can_settings_departments`,0)=1
   OR COALESCE(`can_settings_import`,0)=1;

-- Полный доступ для руководителя BIMA User ID 13, если правило уже есть.
UPDATE `account_permissions`
SET `is_manager` = 1,
    `can_edit_report` = 1,
    `can_settings_accounts` = 1,
    `can_settings_categories` = 1,
    `can_settings_currencies` = 1,
    `can_settings_departments` = 1,
    `can_settings_import` = 1,
    `can_view_dashboard` = 1,
    `can_view_payments` = 1,
    `can_add_payment` = 1,
    `can_view_import` = 1,
    `can_view_report` = 1,
    `can_view_settings` = 1
WHERE `portal` = 'bima.bitrix24.ru'
  AND `bitrix_user_id` = 13;

INSERT INTO `account_permissions`
(`portal`,`account_id`,`bitrix_position`,`bitrix_user_id`,`is_manager`,`can_edit_report`,
 `can_settings_accounts`,`can_settings_categories`,`can_settings_currencies`,`can_settings_departments`,`can_settings_import`,
 `can_view_dashboard`,`can_view_payments`,`can_add_payment`,`can_view_import`,`can_view_report`,`can_view_settings`)
SELECT 'bima.bitrix24.ru',0,NULL,13,1,1,1,1,1,1,1,1,1,1,1,1,1
WHERE NOT EXISTS (
  SELECT 1 FROM `account_permissions`
  WHERE `portal`='bima.bitrix24.ru' AND `bitrix_user_id`=13
);

-- Индексы для быстрых проверок видимости разделов.
ALTER TABLE `account_permissions` ADD INDEX `idx_perm_view_dashboard` (`portal`(100),`bitrix_user_id`,`can_view_dashboard`);
ALTER TABLE `account_permissions` ADD INDEX `idx_perm_view_payments`  (`portal`(100),`bitrix_user_id`,`can_view_payments`);
ALTER TABLE `account_permissions` ADD INDEX `idx_perm_view_add`       (`portal`(100),`bitrix_user_id`,`can_add_payment`);
ALTER TABLE `account_permissions` ADD INDEX `idx_perm_view_import`    (`portal`(100),`bitrix_user_id`,`can_view_import`);
ALTER TABLE `account_permissions` ADD INDEX `idx_perm_view_report`    (`portal`(100),`bitrix_user_id`,`can_view_report`);
ALTER TABLE `account_permissions` ADD INDEX `idx_perm_view_settings`  (`portal`(100),`bitrix_user_id`,`can_view_settings`);
