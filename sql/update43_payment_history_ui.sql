-- UPDATE v43: история добавления/изменения платежей и отчёта
CREATE TABLE IF NOT EXISTS `payment_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `payment_id` INT DEFAULT NULL,
  `action` VARCHAR(50) NOT NULL,
  `actor_id` INT DEFAULT NULL,
  `actor_name` VARCHAR(255) DEFAULT NULL,
  `before_json` MEDIUMTEXT NULL,
  `after_json` MEDIUMTEXT NULL,
  `comment` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_payment_history_payment` (`portal`(100), `payment_id`, `created_at`),
  KEY `idx_payment_history_portal` (`portal`(100), `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
