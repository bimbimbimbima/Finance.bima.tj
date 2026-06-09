-- UPDATE v35: approval UI/settings/report support and notification de-duplication
CREATE TABLE IF NOT EXISTS `approval_notifications_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `request_id` INT NOT NULL,
  `stage_code` VARCHAR(50) NOT NULL,
  `action_type` VARCHAR(50) NOT NULL,
  `receiver_user_id` INT NOT NULL,
  `event_key` VARCHAR(64) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_approval_notification` (`event_key`),
  KEY `idx_approval_notification_request` (`portal`(100),`request_id`),
  KEY `idx_approval_notification_receiver` (`portal`(100),`receiver_user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `approval_requests`
  ADD COLUMN IF NOT EXISTS `title` VARCHAR(255) DEFAULT NULL AFTER `currency`;

UPDATE `approval_requests`
SET `title` = LEFT(`description`, 120)
WHERE (`title` IS NULL OR `title` = '') AND `description` IS NOT NULL AND `description` <> '';
