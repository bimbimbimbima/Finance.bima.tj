-- UPDATE v91: ускорение отчёта и корректное время/права
-- Если индекс уже есть, сообщение Duplicate key name не критично.

ALTER TABLE `payments`
  ADD INDEX `idx_payments_portal_date_id_v91` (`portal`(100), `date`, `id`);

ALTER TABLE `payments`
  ADD INDEX `idx_payments_portal_account_date_v91` (`portal`(100), `account_id`, `date`, `id`);

ALTER TABLE `payment_history`
  ADD INDEX `idx_payment_history_portal_created_v91` (`portal`(100), `created_at`, `id`);
