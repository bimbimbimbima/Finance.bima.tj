-- ============================================================
-- UPDATE v4.1: Chrome/Edge iframe + права редактирования отчёта
-- Запустить в phpMyAdmin после обновления файлов приложения.
-- ============================================================

ALTER TABLE `account_permissions`
  ADD COLUMN IF NOT EXISTS `can_edit_report` TINYINT DEFAULT 0;

