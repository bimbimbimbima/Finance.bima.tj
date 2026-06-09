-- ============================================================
-- UPDATE v105: раздельные комиссионные маршруты + ускорение под большие объёмы
-- ============================================================
-- Скрипт безопасен для повторного запуска: индексы создаются только если их ещё нет.

SET @sql_1 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'approval_requests' AND index_name = 'idx_approval_portal_type_updated_v105') = 0,
  'ALTER TABLE `approval_requests` ADD INDEX `idx_approval_portal_type_updated_v105` (`portal`(100), `request_type`, `updated_at`, `id`)',
  'SELECT "index idx_approval_portal_type_updated_v105 already exists"');
PREPARE stmt_1 FROM @sql_1;
EXECUTE stmt_1;
DEALLOCATE PREPARE stmt_1;

SET @sql_2 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'approval_requests' AND index_name = 'idx_approval_portal_stage_updated_v105') = 0,
  'ALTER TABLE `approval_requests` ADD INDEX `idx_approval_portal_stage_updated_v105` (`portal`(100), `current_stage`, `status`, `updated_at`, `id`)',
  'SELECT "index idx_approval_portal_stage_updated_v105 already exists"');
PREPARE stmt_2 FROM @sql_2;
EXECUTE stmt_2;
DEALLOCATE PREPARE stmt_2;

SET @sql_3 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'approval_requests' AND index_name = 'idx_approval_portal_creator_updated_v105') = 0,
  'ALTER TABLE `approval_requests` ADD INDEX `idx_approval_portal_creator_updated_v105` (`portal`(100), `creator_id`, `updated_at`, `id`)',
  'SELECT "index idx_approval_portal_creator_updated_v105 already exists"');
PREPARE stmt_3 FROM @sql_3;
EXECUTE stmt_3;
DEALLOCATE PREPARE stmt_3;

SET @sql_4 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'approval_requests' AND index_name = 'idx_approval_portal_manager_updated_v105') = 0,
  'ALTER TABLE `approval_requests` ADD INDEX `idx_approval_portal_manager_updated_v105` (`portal`(100), `manager_id`, `updated_at`, `id`)',
  'SELECT "index idx_approval_portal_manager_updated_v105 already exists"');
PREPARE stmt_4 FROM @sql_4;
EXECUTE stmt_4;
DEALLOCATE PREPARE stmt_4;

SET @sql_5 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'approval_request_approvers' AND index_name = 'idx_approvers_request_stage_status_user_v105') = 0,
  'ALTER TABLE `approval_request_approvers` ADD INDEX `idx_approvers_request_stage_status_user_v105` (`request_id`, `stage`, `status`, `user_id`)',
  'SELECT "index idx_approvers_request_stage_status_user_v105 already exists"');
PREPARE stmt_5 FROM @sql_5;
EXECUTE stmt_5;
DEALLOCATE PREPARE stmt_5;

SET @sql_6 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'approval_request_approvers' AND index_name = 'idx_approvers_user_status_request_v105') = 0,
  'ALTER TABLE `approval_request_approvers` ADD INDEX `idx_approvers_user_status_request_v105` (`user_id`, `status`, `request_id`, `stage`)',
  'SELECT "index idx_approvers_user_status_request_v105 already exists"');
PREPARE stmt_6 FROM @sql_6;
EXECUTE stmt_6;
DEALLOCATE PREPARE stmt_6;

SET @sql_7 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'approval_request_history' AND index_name = 'idx_history_request_created_v105') = 0,
  'ALTER TABLE `approval_request_history` ADD INDEX `idx_history_request_created_v105` (`request_id`, `created_at`, `id`)',
  'SELECT "index idx_history_request_created_v105 already exists"');
PREPARE stmt_7 FROM @sql_7;
EXECUTE stmt_7;
DEALLOCATE PREPARE stmt_7;

SET @sql_8 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'approval_route_settings' AND index_name = 'idx_route_portal_stage_sort_v105') = 0,
  'ALTER TABLE `approval_route_settings` ADD INDEX `idx_route_portal_stage_sort_v105` (`portal`(100), `stage`, `sort_order`)',
  'SELECT "index idx_route_portal_stage_sort_v105 already exists"');
PREPARE stmt_8 FROM @sql_8;
EXECUTE stmt_8;
DEALLOCATE PREPARE stmt_8;

SET @sql_9 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payments' AND index_name = 'idx_pay_portal_id_date_v105') = 0,
  'ALTER TABLE `payments` ADD INDEX `idx_pay_portal_id_date_v105` (`portal`(100), `id`, `date`)',
  'SELECT "index idx_pay_portal_id_date_v105 already exists"');
PREPARE stmt_9 FROM @sql_9;
EXECUTE stmt_9;
DEALLOCATE PREPARE stmt_9;

SET @sql_10 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payments' AND index_name = 'idx_pay_portal_code_date_id_v105') = 0,
  'ALTER TABLE `payments` ADD INDEX `idx_pay_portal_code_date_id_v105` (`portal`(100), `operation_code`, `date`, `id`)',
  'SELECT "index idx_pay_portal_code_date_id_v105 already exists"');
PREPARE stmt_10 FROM @sql_10;
EXECUTE stmt_10;
DEALLOCATE PREPARE stmt_10;

SET @sql_11 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payments' AND index_name = 'idx_pay_portal_region_date_id_v105') = 0,
  'ALTER TABLE `payments` ADD INDEX `idx_pay_portal_region_date_id_v105` (`portal`(100), `region`, `date`, `id`)',
  'SELECT "index idx_pay_portal_region_date_id_v105 already exists"');
PREPARE stmt_11 FROM @sql_11;
EXECUTE stmt_11;
DEALLOCATE PREPARE stmt_11;

SET @sql_12 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payments' AND index_name = 'idx_pay_portal_currency_date_id_v105') = 0,
  'ALTER TABLE `payments` ADD INDEX `idx_pay_portal_currency_date_id_v105` (`portal`(100), `currency`, `date`, `id`)',
  'SELECT "index idx_pay_portal_currency_date_id_v105 already exists"');
PREPARE stmt_12 FROM @sql_12;
EXECUTE stmt_12;
DEALLOCATE PREPARE stmt_12;

SET @sql_13 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payments' AND index_name = 'idx_pay_portal_category_date_id_v105') = 0,
  'ALTER TABLE `payments` ADD INDEX `idx_pay_portal_category_date_id_v105` (`portal`(100), `category_id`, `date`, `id`)',
  'SELECT "index idx_pay_portal_category_date_id_v105 already exists"');
PREPARE stmt_13 FROM @sql_13;
EXECUTE stmt_13;
DEALLOCATE PREPARE stmt_13;

-- Дублирование текущего комиссионного маршрута в новый маршрут:
-- cb_* = "Комиссионный маршрут коробочные продукты и остальные (без корпоратов)".
-- Если cb_* уже настроен вручную, он не перезаписывается.
INSERT INTO `approval_route_settings`
(`portal`, `stage`, `stage_label`, `approver_ids`, `approver_names`, `threshold_amount`, `sort_order`, `is_enabled`, `stage_hint`, `stage_icon`)
SELECT
  src.`portal`,
  CONCAT('cb_', CASE WHEN LEFT(src.`stage`, 2) = 'c_' THEN SUBSTRING(src.`stage`, 3) ELSE src.`stage` END) AS `stage`,
  src.`stage_label`,
  src.`approver_ids`,
  src.`approver_names`,
  src.`threshold_amount`,
  COALESCE(src.`sort_order`, 100),
  COALESCE(src.`is_enabled`, 1),
  src.`stage_hint`,
  src.`stage_icon`
FROM `approval_route_settings` src
WHERE LEFT(src.`stage`, 3) <> 'cb_'
  AND LEFT(src.`stage`, 2) <> 'r_'
  AND LEFT(src.`stage`, 7) <> 'option_'
  AND src.`stage` <> ''
ON DUPLICATE KEY UPDATE
  `stage` = VALUES(`stage`);
