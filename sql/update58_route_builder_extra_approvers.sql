-- UPDATE v58: BPMN-style route builder order and stage toggle.
-- Optional: приложение добавляет эти поля автоматически при открытии настроек маршрута.
ALTER TABLE `approval_route_settings`
  ADD COLUMN IF NOT EXISTS `sort_order` INT NOT NULL DEFAULT 100,
  ADD COLUMN IF NOT EXISTS `is_enabled` TINYINT(1) NOT NULL DEFAULT 1;

UPDATE `approval_route_settings` SET `sort_order` = 20 WHERE `stage`='finance_route' AND (`sort_order` IS NULL OR `sort_order`=100);
UPDATE `approval_route_settings` SET `sort_order` = 40 WHERE `stage`='general_director' AND (`sort_order` IS NULL OR `sort_order`=100);
UPDATE `approval_route_settings` SET `sort_order` = 50 WHERE `stage`='security' AND (`sort_order` IS NULL OR `sort_order`=100);
UPDATE `approval_route_settings` SET `sort_order` = 60 WHERE `stage`='finance_director' AND (`sort_order` IS NULL OR `sort_order`=100);
UPDATE `approval_route_settings` SET `sort_order` = 70 WHERE `stage`='treasury' AND (`sort_order` IS NULL OR `sort_order`=100);
