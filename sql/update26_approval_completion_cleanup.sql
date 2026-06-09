-- UPDATE v26: согласование — единый список финансистов и фиксация оплаты по завершённым заявкам

ALTER TABLE `approval_requests`
  ADD COLUMN IF NOT EXISTS `final_payment_id` INT DEFAULT NULL;

-- Больше не используем отдельный этап настройки "финальные финансисты":
-- для приёма и финального согласования используется один список "Финансисты".
DELETE FROM `approval_route_settings`
WHERE `stage` = 'finance_final';

UPDATE `approval_route_settings`
SET `stage_label` = 'Финансисты'
WHERE `stage` = 'finance_route';
