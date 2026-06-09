-- UPDATE v60: editable BPMN route card details.
-- Optional: приложение добавляет эти поля автоматически при открытии настроек маршрута.
ALTER TABLE `approval_route_settings`
  ADD COLUMN IF NOT EXISTS `stage_hint` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `stage_icon` VARCHAR(32) NULL;
