-- Запустите этот файл в phpMyAdmin если приложение уже было установлено

ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `status` ENUM('pending','approved','rejected') DEFAULT 'approved',
  ADD COLUMN IF NOT EXISTS `transfer_to` INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `operation_type` ENUM('income','expense','transfer','topup') DEFAULT 'income';

UPDATE payments SET operation_type=type WHERE operation_type IS NULL OR operation_type='income';
UPDATE payments SET status='approved' WHERE status IS NULL;
