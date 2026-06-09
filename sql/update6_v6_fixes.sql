-- Finance B24 v6 fixes
-- Safe to run multiple times where supported by current MySQL/MariaDB.

-- Нормализация статусов старых записей
UPDATE payments SET status='approved' WHERE status IS NULL OR TRIM(status)='' OR LOWER(TRIM(status)) IN ('подтверждено','оплачено');
UPDATE payments SET status='paid'     WHERE LOWER(TRIM(status))='выплачено';
UPDATE payments SET status='pending'  WHERE LOWER(TRIM(status))='на согласовании';
UPDATE payments SET status='rejected' WHERE LOWER(TRIM(status))='отклонено';

-- Заполнение amount_tjs для старых ручных платежей
UPDATE payments SET amount_tjs = amount * COALESCE(NULLIF(exchange_rate,0),1) WHERE (amount_tjs IS NULL OR amount_tjs=0) AND amount IS NOT NULL;

-- Индексы для быстрой загрузки платежей, отчётов и дашборда
ALTER TABLE payments ADD INDEX idx_portal_date_status (portal, date, status);
ALTER TABLE payments ADD INDEX idx_portal_account_date (portal, account_id, date);
ALTER TABLE payments ADD INDEX idx_portal_type_date (portal, type, date);
ALTER TABLE payments ADD INDEX idx_portal_operation_type_date (portal, operation_type, date);
ALTER TABLE categories ADD INDEX idx_categories_portal_type (portal, type);
ALTER TABLE accounts ADD INDEX idx_accounts_portal (portal);
