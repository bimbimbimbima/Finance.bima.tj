-- UPDATE v85: дополнительные файлы заявки и корректировки комиссионного отчёта

ALTER TABLE `approval_requests`
  ADD COLUMN IF NOT EXISTS `attachments_json` MEDIUMTEXT NULL;
