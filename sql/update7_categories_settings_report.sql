-- update7: фиксы дашборда, отчетов и производительности.
-- Если часть индексов уже есть, phpMyAdmin может показать Duplicate key name — это не критично.

-- Нормализация сумм в TJS для старых ручных записей.
UPDATE payments
SET amount_tjs = amount * COALESCE(NULLIF(exchange_rate,0),1)
WHERE (amount_tjs IS NULL OR amount_tjs = 0) AND amount IS NOT NULL;

-- Нормализация пустых статусов: такие записи должны участвовать в отчетах.
UPDATE payments
SET status = 'approved'
WHERE status IS NULL OR TRIM(status) = '';

-- Индексы для быстрого открытия платежей, отчета и дашборда.
CREATE INDEX idx_payments_portal_date_id ON payments (portal, date, id);
CREATE INDEX idx_payments_portal_type_date ON payments (portal, type, date);
CREATE INDEX idx_payments_portal_status_date ON payments (portal, status, date);
CREATE INDEX idx_payments_portal_account_date ON payments (portal, account_id, date);
CREATE INDEX idx_payments_portal_category_date ON payments (portal, category_id, date);
