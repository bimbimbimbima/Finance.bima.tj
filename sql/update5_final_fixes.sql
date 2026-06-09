-- ============================================================
-- UPDATE v5: финальные исправления прав, статусов и ускорения
-- Запустить в phpMyAdmin после загрузки файлов.
-- ============================================================

-- 1) Поле новой привилегии "Редактирование отчёта"
ALTER TABLE `account_permissions`
  ADD COLUMN IF NOT EXISTS `can_edit_report` TINYINT(1) NOT NULL DEFAULT 0;

-- 2) Убедиться, что статус "paid / Выплачено" разрешён
ALTER TABLE `payments`
  MODIFY COLUMN `status` ENUM('pending','approved','rejected','paid') DEFAULT 'approved';

-- 3) User ID 13 — руководитель/администратор приложения
INSERT INTO `account_permissions` (`portal`, `account_id`, `bitrix_position`, `bitrix_user_id`, `is_manager`, `can_edit_report`)
SELECT 'bima.bitrix24.ru', 0, NULL, 13, 1, 1
WHERE NOT EXISTS (
  SELECT 1 FROM `account_permissions`
  WHERE `portal`='bima.bitrix24.ru' AND `bitrix_user_id`=13 AND `is_manager`=1
);

-- 4) Индексы для ускорения фильтров, отчётов и платежей.
-- Если phpMyAdmin покажет Duplicate key name — этот индекс уже есть, это не ошибка приложения.
ALTER TABLE `payments` ADD INDEX `idx_fin_portal_date_status` (`portal`(100), `date`, `status`);
ALTER TABLE `payments` ADD INDEX `idx_fin_portal_account_date` (`portal`(100), `account_id`, `date`);
ALTER TABLE `payments` ADD INDEX `idx_fin_portal_type_date` (`portal`(100), `type`, `date`);
ALTER TABLE `account_permissions` ADD INDEX `idx_perm_portal_user` (`portal`(100), `bitrix_user_id`);
