-- UPDATE v32: region fix, permissions employee name, report exchange-rate date filter support
ALTER TABLE `account_permissions`
  ADD COLUMN IF NOT EXISTS `bitrix_user_name` VARCHAR(255) DEFAULT NULL AFTER `bitrix_user_id`;

ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `exchange_rate_date` DATE DEFAULT NULL;

UPDATE `payments`
SET `exchange_rate_date` = COALESCE(`exchange_rate_date`, `date`)
WHERE `exchange_rate_date` IS NULL;

ALTER TABLE `payments`
  ADD INDEX `idx_fin_exchange_rate_date` (`portal`(100), `exchange_rate_date`);
