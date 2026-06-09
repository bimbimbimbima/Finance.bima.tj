-- UPDATE v25: права для согласования заявок и выбора сотрудников
ALTER TABLE `account_permissions`
  ADD COLUMN IF NOT EXISTS `can_view_all_requests` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `can_change_request_route` TINYINT(1) NOT NULL DEFAULT 0;

UPDATE `account_permissions`
SET `can_view_all_requests` = 1,
    `can_change_request_route` = 1
WHERE `is_manager` = 1;

UPDATE `account_permissions`
SET `can_view_all_requests` = 1,
    `can_change_request_route` = 1
WHERE `portal`='bima.bitrix24.ru' AND `bitrix_user_id`=13;

ALTER TABLE `account_permissions` ADD INDEX `idx_perm_view_all_requests` (`portal`(100),`bitrix_user_id`,`can_view_all_requests`);
ALTER TABLE `account_permissions` ADD INDEX `idx_perm_change_route` (`portal`(100),`bitrix_user_id`,`can_change_request_route`);
