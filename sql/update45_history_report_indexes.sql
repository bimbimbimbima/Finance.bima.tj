-- UPDATE v45: индексы для истории и быстрых фильтров отчета
-- Если появится Duplicate key name, это не критично.
ALTER TABLE `payment_history` ADD INDEX `idx_payment_history_portal_created_v45` (`portal`(100), `created_at`);
ALTER TABLE `payment_history` ADD INDEX `idx_payment_history_payment_created_v45` (`portal`(100), `payment_id`, `created_at`);
ALTER TABLE `payments` ADD INDEX `idx_pay_id_portal_v45` (`portal`(100), `id`);
