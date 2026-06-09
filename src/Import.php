<?php
require_once dirname(__DIR__) . '/lib/SimpleXLSX.php';
use Shuchkin\SimpleXLSX;

class Import {

    public static function fromExcel($portal, $filepath, $accountId = null, $filename = '') {
        if (!file_exists($filepath)) return ['error' => 'Файл не найден'];
        $filename = trim((string)$filename) ?: basename((string)$filepath);

        // Справочник кодов → название статьи
        $opTypesRows = DB::fetchAll("SELECT code,name FROM operation_types WHERE portal=?", [$portal]);
        $opTypes = [];
        foreach ($opTypesRows as $r) $opTypes[(int)$r['code']] = $r['name'];

        // Категории (статьи) — ищем по коду в названии "1101 - Название"
        $catRows = DB::fetchAll("SELECT id,name FROM categories WHERE portal=?", [$portal]);
        $catMap  = [];
        foreach ($catRows as $r) {
            if (preg_match('/^(\d+)/', $r['name'], $m)) $catMap[(int)$m[1]] = $r['id'];
        }

        $xlsx = SimpleXLSX::parse($filepath);
        if (!$xlsx) return ['error' => 'Не удалось открыть файл: ' . SimpleXLSX::parseError()];

        // Ищем лист TJS
        $sheetIdx = 0;
        foreach ($xlsx->sheetNames() as $i => $name) {
            if (mb_strtolower(trim($name)) === 'tjs') { $sheetIdx = $i; break; }
        }

        $rows     = $xlsx->rows($sheetIdx);
        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $importLogId = null;
        try {
            $importLogId = DB::insert(
                "INSERT INTO import_log (portal,filename,rows_total,rows_imported,rows_skipped) VALUES (?,?,?,?,?)",
                [$portal, $filename, 0, 0, 0]
            );
        } catch (Throwable $e) {
            app_log('Import log create failed: ' . $e->getMessage());
        }

        // Строка 1 = итоги, строка 2 = заголовки → данные с индекса 2 (строка 3)
        foreach ($rows as $ri => $row) {
            if ($ri < 2) continue; // пропускаем первые 2 строки

            $rowNum = $ri + 1;

            $dateRaw   = $row[1]  ?? null; // B
            $income    = $row[2]  ?? null; // C — Приход
            $expense   = $row[3]  ?? null; // D — Расход
            $currency  = trim((string)($row[4]  ?? 'TJS')); // E
            $rate      = $row[5]  ?? 1;    // F
            $desc      = trim((string)($row[6]  ?? '')); // G
            $recipient = trim((string)($row[7]  ?? '')); // H
            $info      = trim((string)($row[8]  ?? '')); // I — ссылка
            $code      = $row[9]  ?? null; // J — код
            $dept      = trim((string)($row[11] ?? '')); // L — канал/отдел
            $region    = $row[13] ?? 0;    // N
            $subDept   = $row[14] ?? null; // O

            // Пропускаем строки без даты — молча (это итоговые/пустые строки)
            $date = self::parseDate($dateRaw);
            if (!$date) continue;

            // Определяем тип и сумму
            $incAmt = self::toFloat($income);
            $expAmt = self::toFloat($expense);

            // Пропускаем строки без суммы — молча
            if ($incAmt <= 0 && $expAmt <= 0) continue;

            $type   = $incAmt > 0 ? 'income' : 'expense';
            $amount = $incAmt > 0 ? $incAmt  : $expAmt;

            // Код операции (только числовой)
            $opCode = null;
            if ($code !== null && $code !== '' && !is_string($code)) {
                $opCode = (int)$code;
            } elseif (is_string($code) && is_numeric($code)) {
                $opCode = (int)$code;
            }

            $opName = $opCode ? ($opTypes[$opCode] ?? '') : '';
            $catId  = $opCode ? ($catMap[$opCode]  ?? null) : null;

            // Подотдел — пропускаем если это формула "=L3"
            $subDeptVal = '';
            if ($subDept !== null && is_string($subDept) && strpos($subDept, '=') === false) {
                $subDeptVal = trim($subDept);
            } elseif ($subDept !== null && !is_string($subDept)) {
                $subDeptVal = trim((string)$subDept);
            }

            $rate     = (float)($rate ?: 1);
            $amtTJS   = round($amount * $rate, 2);
            $regionV  = is_numeric($region) ? (int)$region : 0;

            // Прямая вставка в БД (без проверки баланса — импорт исторических данных)
            try {
                $hasNew = self::hasColumn('currency');
                if ($hasNew) {
                    $cols = "portal,type,operation_type,date,amount,account_id,
                          category_id,company,description,transaction_link,operation_code,
                          operation_type_name,department,sub_department,region,currency,
                          exchange_rate,amount_tjs,source,status";
                    $place = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
                    $vals = [
                        $portal, $type, $type, $date, $amount,
                        $accountId ?: null, $catId,
                        $recipient ?: null,
                        $desc      ?: null,
                        $info      ?: null,
                        $opCode, $opName ?: null,
                        $dept      ?: null,
                        $subDeptVal ?: null,
                        $regionV,
                        $currency ?: 'TJS', $rate, $amtTJS,
                        'import', 'approved',
                    ];
                    if ($importLogId && self::hasColumn('import_log_id')) {
                        $cols .= ",import_log_id";
                        $place .= ",?";
                        $vals[] = $importLogId;
                    }
                    DB::insert("INSERT INTO payments ($cols) VALUES ($place)", $vals);
                } else {
                    $cols = "portal,type,operation_type,date,amount,account_id,company,comment,source,status";
                    $place = "?,?,?,?,?,?,?,?,?,?";
                    $vals = [$portal,$type,$type,$date,$amount,$accountId?:null,$recipient?:null,$desc?:null,'import','approved'];
                    if ($importLogId && self::hasColumn('import_log_id')) {
                        $cols .= ",import_log_id";
                        $place .= ",?";
                        $vals[] = $importLogId;
                    }
                    DB::insert("INSERT INTO payments ($cols) VALUES ($place)", $vals);
                }
                $imported++;
            } catch (Exception $e) {
                $skipped++;
                if (count($errors) < 10) $errors[] = "Строка {$rowNum}: " . $e->getMessage();
            }
        }

        // Обновляем лог импорта
        try {
            $total = $imported + $skipped;
            if ($importLogId) {
                DB::execute(
                    "UPDATE import_log SET rows_total=?, rows_imported=?, rows_skipped=? WHERE id=? AND portal=?",
                    [$total, $imported, $skipped, $importLogId, $portal]
                );
            }
        } catch (Throwable $e) {}

        return ['total' => $imported+$skipped, 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors, 'import_log_id' => $importLogId];
    }

    public static function deleteImportBatch($portal, $importLogId, $allowedAccountIds = null) {
        $importLogId = (int)$importLogId;
        if ($importLogId <= 0) return 0;
        if (!self::hasColumn('import_log_id')) {
            throw new RuntimeException('Колонка import_log_id отсутствует. Выполните SQL update11.');
        }
        $where = ["portal=?", "source='import'", "import_log_id=?"];
        $params = [$portal, $importLogId];
        if (is_array($allowedAccountIds) && !in_array(0, array_map('intval', $allowedAccountIds), true)) {
            $allowed = array_values(array_unique(array_filter(array_map('intval', $allowedAccountIds), static fn($id)=>$id>0)));
            if (empty($allowed)) return 0;
            $where[] = 'account_id IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
            foreach ($allowed as $id) $params[] = $id;
        }
        $deleted = DB::execute("DELETE FROM payments WHERE " . implode(' AND ', $where), $params);
        // Лог удаляем только если по нему не осталось платежей.
        $left = DB::fetchOne("SELECT COUNT(*) c FROM payments WHERE portal=? AND import_log_id=?", [$portal, $importLogId]);
        if ((int)($left['c'] ?? 0) === 0) {
            DB::execute("DELETE FROM import_log WHERE id=? AND portal=?", [$importLogId, $portal]);
        }
        return $deleted;
    }

    private static function hasColumn($col) {
        static $c = [];
        if (!isset($c[$col])) {
            try { DB::fetchOne("SELECT {$col} FROM payments LIMIT 1"); $c[$col]=true; }
            catch(Exception $e) { $c[$col]=false; }
        }
        return $c[$col];
    }

    private static function parseDate($val) {
        if ($val === null || $val === '' || $val === false) return false;
        if ($val instanceof \DateTime || $val instanceof \DateTimeInterface) return $val->format('Y-m-d');
        if (is_float($val) || (is_int($val) && $val > 20000)) {
            $v = (float)$val;
            if ($v > 20000 && $v < 300000) return date('Y-m-d', (int)(($v - 25569) * 86400));
        }
        $s = trim((string)$val);
        if ($s === '') return false;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $s, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
        if (is_numeric($s)) {
            $v = (float)$s;
            if ($v > 20000 && $v < 300000) return date('Y-m-d', (int)(($v - 25569) * 86400));
        }
        return false;
    }

    private static function toFloat($val) {
        if ($val === null || $val === '' || $val === false) return 0.0;
        if (is_numeric($val)) return (float)$val;
        $s = str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], (string)$val);
        return is_numeric($s) ? (float)$s : 0.0;
    }
}
