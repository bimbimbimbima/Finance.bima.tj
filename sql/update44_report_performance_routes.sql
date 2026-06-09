-- ============================================================
-- UPDATE v44: быстрый отчет, индексы, маршрут без финального
-- финансиста, специальный маршрут страховых комиссий
-- ============================================================

-- Индексы для отчета и фильтров по большим массивам операций.
ALTER TABLE `payments` ADD INDEX `idx_pay_portal_date_id_v44` (`portal`(100), `date`, `id`);
ALTER TABLE `payments` ADD INDEX `idx_pay_portal_account_date_v44` (`portal`(100), `account_id`, `date`);
ALTER TABLE `payments` ADD INDEX `idx_pay_portal_rate_date_v44` (`portal`(100), `exchange_rate_date`);
ALTER TABLE `payments` ADD INDEX `idx_pay_portal_currency_v44` (`portal`(100), `currency`);
ALTER TABLE `payments` ADD INDEX `idx_pay_portal_code_v44` (`portal`(100), `operation_code`);
ALTER TABLE `payments` ADD INDEX `idx_pay_portal_dept_v44` (`portal`(100), `department`(100));
ALTER TABLE `payments` ADD INDEX `idx_pay_portal_subdept_v44` (`portal`(100), `sub_department`(100));
ALTER TABLE `payments` ADD INDEX `idx_pay_portal_region_v44` (`portal`(100), `region`);
ALTER TABLE `payments` ADD INDEX `idx_pay_portal_category_v44` (`portal`(100), `category_id`);

-- Общие индексы истории: история теперь выводится независимо от периода отчёта.
ALTER TABLE `payment_history` ADD INDEX `idx_payment_history_portal_created_v44` (`portal`(100), `created_at`);

-- Новый этап специального маршрута: генеральный директор.
INSERT INTO `approval_route_settings`
(`portal`, `stage`, `stage_label`, `approver_ids`, `approver_names`, `threshold_amount`)
VALUES
('bima.bitrix24.ru', 'general_director', 'Генеральный директор', '', '', 15000.00)
ON DUPLICATE KEY UPDATE
  `stage_label` = VALUES(`stage_label`),
  `threshold_amount` = CASE WHEN `threshold_amount` IS NULL OR `threshold_amount` < 15000 THEN 15000 ELSE `threshold_amount` END;

-- Убираем старый этап финального согласования финансиста из настроек маршрута.
DELETE FROM `approval_route_settings`
WHERE `stage` = 'finance_final';

-- Порог финдиректора по новой логике — 15 000 TJS.
UPDATE `approval_route_settings`
SET `threshold_amount` = 15000.00
WHERE `portal` = 'bima.bitrix24.ru'
  AND `stage` IN ('finance_director','treasury','security','general_director','finance_route');
