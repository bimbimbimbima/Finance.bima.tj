-- ============================================================
-- UPDATE v30: поля отдел/подотдел/регион для согласования
-- ============================================================

ALTER TABLE `approval_requests`
  ADD COLUMN IF NOT EXISTS `department` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sub_department` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `region` VARCHAR(255) DEFAULT NULL;

ALTER TABLE `approval_requests`
  ADD INDEX `idx_approval_department_region` (`portal`(100), `department`(100), `sub_department`(100), `region`(100));
