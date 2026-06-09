-- ============================================================
-- UPDATE v22: раздел согласования заявок, маршруты, этапы, права
-- ============================================================

ALTER TABLE `account_permissions`
  ADD COLUMN IF NOT EXISTS `can_view_requests` TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `can_create_request` TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `can_approve_request` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `can_settings_approval` TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `approval_route_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `stage` VARCHAR(50) NOT NULL,
  `stage_label` VARCHAR(255) NOT NULL,
  `approver_ids` TEXT NULL,
  `approver_names` TEXT NULL,
  `threshold_amount` DECIMAL(15,2) NOT NULL DEFAULT 5000.00,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_approval_route` (`portal`(100), `stage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `approval_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `creator_id` INT NOT NULL,
  `creator_name` VARCHAR(255) DEFAULT NULL,
  `manager_id` INT NOT NULL,
  `manager_name` VARCHAR(255) DEFAULT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'TJS',
  `description` TEXT NOT NULL,
  `category_id` INT DEFAULT NULL,
  `account_id` INT DEFAULT NULL,
  `operation_code` VARCHAR(50) DEFAULT NULL,
  `operation_type_name` VARCHAR(255) DEFAULT NULL,
  `director_ids` TEXT NULL,
  `director_names` TEXT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'waiting_manager',
  `current_stage` VARCHAR(50) NOT NULL DEFAULT 'manager',
  `return_stage` VARCHAR(50) DEFAULT NULL,
  `final_payment_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_approval_portal_stage` (`portal`(100), `current_stage`, `status`),
  KEY `idx_approval_creator` (`portal`(100), `creator_id`),
  KEY `idx_approval_manager` (`portal`(100), `manager_id`),
  KEY `idx_approval_updated` (`portal`(100), `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `approval_request_approvers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `request_id` INT NOT NULL,
  `stage` VARCHAR(50) NOT NULL,
  `user_id` INT NOT NULL,
  `user_name` VARCHAR(255) DEFAULT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
  `decided_by_id` INT DEFAULT NULL,
  `decided_by_name` VARCHAR(255) DEFAULT NULL,
  `decided_at` DATETIME DEFAULT NULL,
  `comment` TEXT NULL,
  KEY `idx_approver_request` (`request_id`),
  KEY `idx_approver_user_stage` (`user_id`, `stage`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `approval_request_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `request_id` INT NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `from_stage` VARCHAR(50) DEFAULT NULL,
  `to_stage` VARCHAR(50) DEFAULT NULL,
  `user_id` INT DEFAULT NULL,
  `user_name` VARCHAR(255) DEFAULT NULL,
  `comment` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_history_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Базовые этапы маршрута. Сотрудников можно потом изменить в Настройки -> Согласование заявок.
INSERT INTO `approval_route_settings` (`portal`,`stage`,`stage_label`,`approver_ids`,`approver_names`,`threshold_amount`) VALUES
('bima.bitrix24.ru','finance_route','Финансисты, принимающие заявку и выбирающие маршрут','','',5000.00),
('bima.bitrix24.ru','finance_final','Финансисты финального согласования','','',5000.00),
('bima.bitrix24.ru','security','Служба безопасности','','',5000.00),
('bima.bitrix24.ru','treasury','Казначеи','','',5000.00),
('bima.bitrix24.ru','finance_director','Финансовый директор','','',5000.00)
ON DUPLICATE KEY UPDATE stage_label=VALUES(stage_label);

-- Руководитель/админ приложения получает доступ к новому разделу и настройкам маршрута.
UPDATE `account_permissions`
SET `can_view_requests`=1,
    `can_create_request`=1,
    `can_approve_request`=1,
    `can_settings_approval`=1,
    `can_view_settings`=1
WHERE `is_manager`=1;

UPDATE `account_permissions`
SET `is_manager`=1,
    `can_view_requests`=1,
    `can_create_request`=1,
    `can_approve_request`=1,
    `can_settings_approval`=1,
    `can_view_settings`=1
WHERE `portal`='bima.bitrix24.ru' AND `bitrix_user_id`=13;
