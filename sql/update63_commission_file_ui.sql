-- UPDATE v63: хранение файла импорта комиссионной заявки
ALTER TABLE `approval_requests`
  ADD COLUMN IF NOT EXISTS `commission_import_path` VARCHAR(500) DEFAULT NULL;
