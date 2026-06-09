-- ============================================================
-- UPDATE v24: доработка согласования, удаление заявок, права
-- ============================================================

ALTER TABLE `account_permissions`
  ADD COLUMN IF NOT EXISTS `can_delete_request` TINYINT(1) NOT NULL DEFAULT 0;

-- Руководителям/админам финансовой системы разрешаем удаление заявок.
UPDATE `account_permissions`
SET `can_delete_request` = 1
WHERE `is_manager` = 1;

-- User ID 13 получает право удаления заявок, если правило есть.
UPDATE `account_permissions`
SET `can_delete_request` = 1
WHERE `portal`='bima.bitrix24.ru' AND `bitrix_user_id`=13;

-- Индексы для ускорения списка согласований и ожидающих действий.
ALTER TABLE `approval_request_approvers`
  ADD INDEX `idx_approval_pending_user` (`user_id`,`status`,`stage`,`request_id`);

ALTER TABLE `approval_requests`
  ADD INDEX `idx_approval_portal_status_stage` (`portal`(100),`status`,`current_stage`);
