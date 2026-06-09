-- ============================================================
-- UPDATE v31: название заявки, регион 0/1/2, фильтры отчёта/курсов,
-- выбор сотрудника в правах доступа и адаптив
-- ============================================================

ALTER TABLE `approval_requests`
  ADD COLUMN IF NOT EXISTS `title` VARCHAR(255) DEFAULT NULL AFTER `currency`;

UPDATE `approval_requests`
SET `title` = LEFT(`description`, 120)
WHERE (`title` IS NULL OR `title` = '') AND `description` IS NOT NULL AND `description` <> '';

-- Для уже созданных платежей из заявок приводим описание к формату "Название (заявки)",
-- если связанная заявка найдена по final_payment_id.
UPDATE `payments` p
JOIN `approval_requests` ar ON ar.final_payment_id = p.id
SET p.description = CONCAT(COALESCE(NULLIF(ar.title,''), CONCAT('Заявка ', ar.id)), ' (заявки)')
WHERE p.portal = ar.portal
  AND p.source = 'approval';

-- На случай если таблицы истории курсов ещё нет.
CREATE TABLE IF NOT EXISTS `currency_rates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal` VARCHAR(255) NOT NULL,
  `code` VARCHAR(10) NOT NULL,
  `rate_date` DATE NOT NULL,
  `rate` DECIMAL(15,6) NOT NULL DEFAULT 1.000000,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_currency_rate` (`portal`(100),`code`,`rate_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `approval_requests`
  ADD INDEX `idx_approval_title` (`portal`(100), `title`(120));
