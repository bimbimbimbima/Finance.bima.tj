-- UPDATE v62: комиссионные заявки и импорт внутри заявки

ALTER TABLE `approval_requests`
  ADD COLUMN IF NOT EXISTS `request_type` VARCHAR(30) NOT NULL DEFAULT 'regular',
  ADD COLUMN IF NOT EXISTS `product_series` VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `commission_import_filename` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `commission_rows_count` INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `commission_rows_json` MEDIUMTEXT NULL;

ALTER TABLE `approval_requests`
  ADD INDEX `idx_approval_type_product` (`portal`(100), `request_type`, `product_series`);
