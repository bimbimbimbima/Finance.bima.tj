-- ============================================================
-- UPDATE v108: ускорение карточек/маршрутов + защита конструктора этапов
-- ============================================================
-- 1) commission_groups_json / commission_series_json нужны, чтобы карточка комиссионной заявки
--    не декодировала огромный commission_rows_json с тысячами строк Excel.
-- 2) Индексы ниже ускоряют списки заявок, счётчики и ожидания согласования на больших объёмах.

SET @sql_108_1 := IF((SELECT COUNT(1) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'approval_requests' AND column_name = 'commission_groups_json') = 0,
  'ALTER TABLE `approval_requests` ADD COLUMN `commission_groups_json` MEDIUMTEXT NULL AFTER `commission_rows_json`',
  'SELECT "column commission_groups_json already exists"');
PREPARE stmt_108_1 FROM @sql_108_1;
EXECUTE stmt_108_1;
DEALLOCATE PREPARE stmt_108_1;

SET @sql_108_2 := IF((SELECT COUNT(1) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'approval_requests' AND column_name = 'commission_series_json') = 0,
  'ALTER TABLE `approval_requests` ADD COLUMN `commission_series_json` TEXT NULL AFTER `commission_groups_json`',
  'SELECT "column commission_series_json already exists"');
PREPARE stmt_108_2 FROM @sql_108_2;
EXECUTE stmt_108_2;
DEALLOCATE PREPARE stmt_108_2;

SET @sql_108_3 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'approval_requests' AND index_name = 'idx_approval_portal_updated_id_v108') = 0,
  'ALTER TABLE `approval_requests` ADD INDEX `idx_approval_portal_updated_id_v108` (`portal`(100), `updated_at`, `id`)',
  'SELECT "index idx_approval_portal_updated_id_v108 already exists"');
PREPARE stmt_108_3 FROM @sql_108_3;
EXECUTE stmt_108_3;
DEALLOCATE PREPARE stmt_108_3;

SET @sql_108_4 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'approval_requests' AND index_name = 'idx_approval_portal_creator_updated_id_v108') = 0,
  'ALTER TABLE `approval_requests` ADD INDEX `idx_approval_portal_creator_updated_id_v108` (`portal`(100), `creator_id`, `updated_at`, `id`)',
  'SELECT "index idx_approval_portal_creator_updated_id_v108 already exists"');
PREPARE stmt_108_4 FROM @sql_108_4;
EXECUTE stmt_108_4;
DEALLOCATE PREPARE stmt_108_4;

SET @sql_108_5 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'approval_requests' AND index_name = 'idx_approval_portal_stage_status_updated_v108') = 0,
  'ALTER TABLE `approval_requests` ADD INDEX `idx_approval_portal_stage_status_updated_v108` (`portal`(100), `current_stage`, `status`, `updated_at`, `id`)',
  'SELECT "index idx_approval_portal_stage_status_updated_v108 already exists"');
PREPARE stmt_108_5 FROM @sql_108_5;
EXECUTE stmt_108_5;
DEALLOCATE PREPARE stmt_108_5;

SET @sql_108_6 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'approval_requests' AND index_name = 'idx_approval_portal_type_series_v108') = 0,
  'ALTER TABLE `approval_requests` ADD INDEX `idx_approval_portal_type_series_v108` (`portal`(100), `request_type`, `product_series`, `id`)',
  'SELECT "index idx_approval_portal_type_series_v108 already exists"');
PREPARE stmt_108_6 FROM @sql_108_6;
EXECUTE stmt_108_6;
DEALLOCATE PREPARE stmt_108_6;

SET @sql_108_7 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'approval_request_approvers' AND index_name = 'idx_approvers_user_stage_status_req_v108') = 0,
  'ALTER TABLE `approval_request_approvers` ADD INDEX `idx_approvers_user_stage_status_req_v108` (`user_id`, `stage`, `status`, `request_id`)',
  'SELECT "index idx_approvers_user_stage_status_req_v108 already exists"');
PREPARE stmt_108_7 FROM @sql_108_7;
EXECUTE stmt_108_7;
DEALLOCATE PREPARE stmt_108_7;

SET @sql_108_8 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'approval_request_approvers' AND index_name = 'idx_approvers_request_stage_status_user_v108') = 0,
  'ALTER TABLE `approval_request_approvers` ADD INDEX `idx_approvers_request_stage_status_user_v108` (`request_id`, `stage`, `status`, `user_id`)',
  'SELECT "index idx_approvers_request_stage_status_user_v108 already exists"');
PREPARE stmt_108_8 FROM @sql_108_8;
EXECUTE stmt_108_8;
DEALLOCATE PREPARE stmt_108_8;

SET @sql_108_9 := IF((SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'approval_request_history' AND index_name = 'idx_history_request_id_desc_v108') = 0,
  'ALTER TABLE `approval_request_history` ADD INDEX `idx_history_request_id_desc_v108` (`request_id`, `id`)',
  'SELECT "index idx_history_request_id_desc_v108 already exists"');
PREPARE stmt_108_9 FROM @sql_108_9;
EXECUTE stmt_108_9;
DEALLOCATE PREPARE stmt_108_9;

-- Необязательная, но полезная миграция старых комиссионных заявок:
-- переносим только лёгкую часть groups/series из большого commission_rows_json.
UPDATE `approval_requests`
SET `commission_groups_json` = JSON_OBJECT(
      'groups', JSON_EXTRACT(`commission_rows_json`, '$.groups'),
      'total', JSON_EXTRACT(`commission_rows_json`, '$.total'),
      'series', JSON_EXTRACT(`commission_rows_json`, '$.series'),
      'series_label', JSON_UNQUOTE(JSON_EXTRACT(`commission_rows_json`, '$.series_label')),
      'primary_series', JSON_UNQUOTE(JSON_EXTRACT(`commission_rows_json`, '$.primary_series'))
    )
WHERE `request_type` = 'commission'
  AND (`commission_groups_json` IS NULL OR `commission_groups_json` = '')
  AND `commission_rows_json` IS NOT NULL
  AND JSON_VALID(`commission_rows_json`) = 1
  AND JSON_EXTRACT(`commission_rows_json`, '$.groups') IS NOT NULL;

UPDATE `approval_requests`
SET `commission_series_json` = JSON_EXTRACT(`commission_rows_json`, '$.series')
WHERE `request_type` = 'commission'
  AND (`commission_series_json` IS NULL OR `commission_series_json` = '')
  AND `commission_rows_json` IS NOT NULL
  AND JSON_VALID(`commission_rows_json`) = 1
  AND JSON_EXTRACT(`commission_rows_json`, '$.series') IS NOT NULL;
