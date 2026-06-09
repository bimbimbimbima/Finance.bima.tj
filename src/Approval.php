<?php
require_once dirname(__DIR__) . '/lib/SimpleXLSX.php';
use Shuchkin\SimpleXLSX;

class Approval {

    private static $routeSettingsByTypeCache = [];
    private static $routeSettingsLegacyCache = [];
    private const CORPORATE_COMMISSION_PRODUCTS = ['CI','CP','CE','CT','MI','GA','FI'];


    private static function nowSql() {
        return function_exists('app_now_sql') ? app_now_sql() : date('Y-m-d H:i:s');
    }

    private static function ensureV58Schema() {
        static $done = false;
        if ($done) return;
        $done = true;
        try { DB::execute("ALTER TABLE approval_route_settings ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 100"); } catch (Throwable $e) { app_log('Approval v58 sort_order schema: '.$e->getMessage()); }
        try { DB::execute("ALTER TABLE approval_route_settings ADD COLUMN IF NOT EXISTS is_enabled TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) { app_log('Approval v58 is_enabled schema: '.$e->getMessage()); }
        try { DB::execute("ALTER TABLE approval_route_settings ADD COLUMN IF NOT EXISTS stage_hint TEXT NULL"); } catch (Throwable $e) { app_log('Approval v60 stage_hint schema: '.$e->getMessage()); }
        try { DB::execute("ALTER TABLE approval_route_settings ADD COLUMN IF NOT EXISTS stage_icon VARCHAR(32) NULL"); } catch (Throwable $e) { app_log('Approval v60 stage_icon schema: '.$e->getMessage()); }
    }



    private static function ensureV62Schema() {
        static $done62 = false;
        if ($done62) return;
        $done62 = true;
        try { DB::execute("ALTER TABLE approval_requests ADD COLUMN IF NOT EXISTS request_type VARCHAR(30) NOT NULL DEFAULT 'regular'"); } catch (Throwable $e) { app_log('Approval v62 request_type schema: '.$e->getMessage()); }
        try { DB::execute("ALTER TABLE approval_requests ADD COLUMN IF NOT EXISTS product_series VARCHAR(50) DEFAULT NULL"); } catch (Throwable $e) { app_log('Approval v62 product_series schema: '.$e->getMessage()); }
        try { DB::execute("ALTER TABLE approval_requests ADD COLUMN IF NOT EXISTS commission_import_filename VARCHAR(255) DEFAULT NULL"); } catch (Throwable $e) { app_log('Approval v62 commission filename schema: '.$e->getMessage()); }
        try { DB::execute("ALTER TABLE approval_requests ADD COLUMN IF NOT EXISTS commission_import_path VARCHAR(500) DEFAULT NULL"); } catch (Throwable $e) { app_log('Approval v63 commission path schema: '.$e->getMessage()); }
        try { DB::execute("ALTER TABLE approval_requests ADD COLUMN IF NOT EXISTS commission_rows_count INT NOT NULL DEFAULT 0"); } catch (Throwable $e) { app_log('Approval v62 rows count schema: '.$e->getMessage()); }
        try { DB::execute("ALTER TABLE approval_requests ADD COLUMN IF NOT EXISTS commission_rows_json MEDIUMTEXT NULL"); } catch (Throwable $e) { app_log('Approval v62 rows json schema: '.$e->getMessage()); }
        try { DB::execute("ALTER TABLE approval_requests ADD COLUMN IF NOT EXISTS attachments_json MEDIUMTEXT NULL"); } catch (Throwable $e) { app_log('Approval v85 attachments schema: '.$e->getMessage()); }
        try { DB::execute("ALTER TABLE approval_requests ADD INDEX idx_approval_type_product (portal(100), request_type, product_series)"); } catch (Throwable $e) { /* index may already exist */ }
        self::ensureV108Schema();
    }

    private static function ensureV108Schema() {
        static $done108 = false;
        if ($done108) return;
        $done108 = true;
        try { DB::execute("ALTER TABLE approval_requests ADD COLUMN IF NOT EXISTS commission_groups_json MEDIUMTEXT NULL"); } catch (Throwable $e) { app_log('Approval v108 commission groups schema: '.$e->getMessage()); }
        try { DB::execute("ALTER TABLE approval_requests ADD COLUMN IF NOT EXISTS commission_series_json TEXT NULL"); } catch (Throwable $e) { app_log('Approval v108 commission series schema: '.$e->getMessage()); }
        try { DB::execute("ALTER TABLE approval_requests ADD INDEX idx_approval_portal_updated_id_v108 (portal(100), updated_at, id)"); } catch (Throwable $e) { /* index may already exist */ }
        try { DB::execute("ALTER TABLE approval_requests ADD INDEX idx_approval_portal_creator_id_v108 (portal(100), creator_id, id)"); } catch (Throwable $e) { /* index may already exist */ }
        try { DB::execute("ALTER TABLE approval_request_approvers ADD INDEX idx_approvers_user_stage_status_req_v108 (user_id, stage, status, request_id)"); } catch (Throwable $e) { /* index may already exist */ }
    }

    private static function moneyToFloat($val) {
        if ($val === null || $val === '' || $val === false) return 0.0;
        if (is_numeric($val)) return (float)$val;
        $s = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], (string)$val);
        $s = preg_replace('/[^0-9.\-]/', '', $s);
        return is_numeric($s) ? (float)$s : 0.0;
    }

    private static function safeCell($row, $idx) {
        return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
    }

    private static function sheetIndexByName($xlsx, $name) {
        try {
            foreach ($xlsx->sheetNames() as $i=>$n) {
                if (mb_strtolower(trim((string)$n)) === mb_strtolower(trim((string)$name))) return (int)$i;
            }
        } catch (Throwable $e) {}
        return 0;
    }

    private static function parseCommissionPivotGroups($xlsx) {
        $idx = self::sheetIndexByName($xlsx, 'Лист3');
        try { $rows = $xlsx->rows($idx); } catch (Throwable $e) { return []; }
        if (!$rows || count($rows) < 2) return [];
        $groups = [];
        $currentAllocation = '';
        $total = 0.0;
        foreach ($rows as $ri=>$row) {
            $label = trim((string)($row[0] ?? ''));
            if ($label === '' || mb_strtolower($label) === 'названия строк') continue;
            $amount = self::moneyToFloat($row[1] ?? 0);
            if (mb_stripos($label, 'общий итог') !== false) { $total = $amount; continue; }
            // В сводном листе сначала идёт аллокация, а под ней серии продуктов.
            $isAllocation = (mb_stripos($label, 'ОБП') !== false || mb_stripos($label, 'БИМА') !== false || mb_stripos($label, 'аллока') !== false);
            if ($isAllocation) { $currentAllocation = $label; continue; }
            if ($currentAllocation === '' || $amount <= 0) continue;
            $series = strtoupper(preg_replace('/\s+/u', '', $label));
            if ($series === '') continue;
            $groups[] = ['allocation'=>$currentAllocation, 'series'=>$series, 'amount'=>round($amount,2), 'count'=>0];
        }
        if (!$groups) return [];
        $sum = round(array_sum(array_map(static fn($g)=>(float)$g['amount'], $groups)), 2);
        if ($total > 0 && abs($total - $sum) > 0.02) {
            // Если сводная таблица содержит общий итог, но строки не совпали, всё равно возвращаем строки,
            // а итог будет пересчитан по ним. Это лучше, чем тянуть устаревший кэш формул.
        }
        return $groups;
    }

    public static function parseCommissionFile($filePath, $filename = '') {
        if (!is_file($filePath)) return ['error'=>'Файл импорта не найден'];
        $xlsx = SimpleXLSX::parse($filePath);
        if (!$xlsx) return ['error'=>'Не удалось открыть Excel: ' . SimpleXLSX::parseError()];
        $sheetIdx = self::sheetIndexByName($xlsx, 'Лист1');
        $rows = $xlsx->rows($sheetIdx);
        $sourceRows = [];
        $sourceIdx = self::sheetIndexByName($xlsx, 'ВСЕ кроме взр');
        if ($sourceIdx !== $sheetIdx) {
            try { $sourceRows = $xlsx->rows($sourceIdx); } catch (Throwable $e) { $sourceRows = []; }
        }
        $outRows = [];
        $groups = [];
        $seriesSet = [];
        $total = 0.0;
        $skipped = 0;
        foreach ($rows as $ri=>$row) {
            if ($ri === 0) continue;
            $policy = self::safeCell($row, 0);
            $series = strtoupper(preg_replace('/\s+/u', '', self::safeCell($row, 1)));
            $premiumRaw = $row[2] ?? null;
            $allocation = self::safeCell($row, 3);
            if ($policy === '' && $series === '' && $allocation === '') continue;
            $premium = self::moneyToFloat($premiumRaw);
            if ($premium == 0.0 && is_string($premiumRaw) && strpos($premiumRaw, '=') === 0 && preg_match('/T(\d+)\s*\*\s*4%/i', $premiumRaw, $m)) {
                $srcRowIndex = (int)$m[1] - 1;
                if (isset($sourceRows[$srcRowIndex])) $premium = round(self::moneyToFloat($sourceRows[$srcRowIndex][19] ?? 0) * 0.04, 2);
            }
            if ($premium == 0.0 && $policy !== '' && isset($sourceRows[$ri + 3])) {
                // В шаблоне строка 2 листа "Лист1" соответствует строке 5 листа "ВСЕ кроме взр".
                $premium = round(self::moneyToFloat($sourceRows[$ri + 3][19] ?? 0) * 0.04, 2);
            }
            if ($series === '' && preg_match('/^([A-ZА-Я0-9]{2,6})\s+/ui', $policy, $m)) $series = strtoupper($m[1]);
            if ($series === '' || $premium <= 0) { $skipped++; continue; }
            $allocation = $allocation !== '' ? $allocation : 'Без аллокации';
            $item = ['policy'=>$policy, 'series'=>$series, 'premium'=>round($premium,2), 'allocation'=>$allocation];
            $outRows[] = $item;
            $seriesSet[$series] = true;
            $key = $allocation . '|' . $series;
            if (empty($groups[$key])) $groups[$key] = ['allocation'=>$allocation, 'series'=>$series, 'amount'=>0.0, 'count'=>0];
            $groups[$key]['amount'] += $premium;
            $groups[$key]['count']++;
            $total += $premium;
        }
        $groups = array_values(array_map(static function($g){ $g['amount'] = round((float)$g['amount'], 2); return $g; }, $groups));
        // v67: сумму считаем по фактическим строкам листа импорта, а не по сводной таблице.
        // Сводный лист Excel часто остаётся с устаревшим кэшем после ручного изменения файла.
        // Поэтому Лист3 используется только как аварийный fallback, если строки Лист1 не распознаны.
        if (!$groups) {
            $pivotGroups = self::parseCommissionPivotGroups($xlsx);
            if ($pivotGroups) {
                $groups = $pivotGroups;
                $seriesSet = [];
                foreach ($groups as $g) { $s = strtoupper(trim((string)($g['series'] ?? ''))); if ($s !== '') $seriesSet[$s] = true; }
            }
        }
        usort($groups, static function($a,$b){
            $c = strcmp((string)$a['allocation'], (string)$b['allocation']);
            return $c !== 0 ? $c : strcmp((string)$a['series'], (string)$b['series']);
        });
        $seriesList = array_values(array_keys($seriesSet));
        $primarySeries = '';
        if (count($seriesList) >= 1) {
            // Не используем MIX: по комиссионным заявкам каждая серия и статья хранятся отдельно в группах.
            $primarySeries = $seriesList[0];
        }
        $seriesLabel = implode(', ', $seriesList);
        return [
            'ok'=>true,
            'filename'=>basename((string)$filename),
            'rows'=>count($outRows),
            'skipped'=>$skipped,
            'total'=>round(array_sum(array_map(static function($g){ return (float)($g['amount'] ?? 0); }, $groups)), 2),
            'series'=>$seriesList,
            'series_label'=>$seriesLabel,
            'primary_series'=>$primarySeries,
            'groups'=>$groups,
            'items'=>$outRows,
        ];
    }

    private static function categoryCodeFromName($name) {
        $name = trim((string)$name);
        if (preg_match('/^\s*([0-9]{2,10})\s*[-–—]/u', $name, $m)) return (string)$m[1];
        if (preg_match('/^\s*([0-9]{2,10})\b/u', $name, $m)) return (string)$m[1];
        return '';
    }

    private static function categoryCleanName($name) {
        return trim(preg_replace('/^\s*[0-9]{2,10}\s*[-–—]?\s*/u', '', (string)$name));
    }


    private static function commissionAllocationRegion($allocation) {
        $a = mb_strtoupper(trim((string)$allocation));
        $a = str_replace(['Ё'], ['Е'], $a);
        if ($a === '') return '';
        if (mb_stripos($a, 'БИМА ГО') !== false || mb_stripos($a, 'BIMA GO') !== false) return '0';
        if (mb_stripos($a, 'БИМА СОГД') !== false || mb_stripos($a, 'BIMA SOGD') !== false) return '1';
        if (mb_stripos($a, 'БИМА ХАТЛОН') !== false || mb_stripos($a, 'BIMA KHATLON') !== false) return '2';
        if (in_array($a, ['0','1','2'], true)) return $a;
        return '';
    }

    private static function commissionGroupKey($allocation, $series) {
        return trim((string)$allocation) . '|' . strtoupper(trim((string)$series));
    }

    private static function findCommissionCategory($portal, $series) {
        $series = strtoupper(trim((string)$series));
        if ($series === '' || $series === 'MIX' || $series === 'MULTI') return [0, '', '', ''];
        try {
            $rows = DB::fetchAll("SELECT id,name FROM categories WHERE portal=? AND type='expense' AND (LOWER(name) LIKE '%комис%' OR LOWER(name) LIKE '%commission%') ORDER BY name", [$portal]);
            $best = null; $fallback = null;
            foreach ($rows as $r) {
                $n = (string)($r['name'] ?? '');
                $clean = self::categoryCleanName($n);
                $hay = strtoupper($n . ' ' . $clean);
                if ($fallback === null && (mb_stripos($n, 'комис') !== false || mb_stripos($n, 'commission') !== false)) $fallback = $r;
                if (preg_match('/(^|[^A-ZА-Я0-9])' . preg_quote($series, '/') . '([^A-ZА-Я0-9]|$)/ui', $hay)) { $best = $r; break; }
            }
            $r = $best ?: null;
            if (!$r) return [0, '', '', ''];
            $name = (string)$r['name'];
            return [(int)$r['id'], self::categoryCodeFromName($name), self::categoryCleanName($name), $name];
        } catch (Throwable $e) { app_log('Approval::findCommissionCategory failed: '.$e->getMessage()); }
        return [0, '', '', ''];
    }

    public static function enrichCommissionParsed($portal, $parsed) {
        if (!is_array($parsed)) return $parsed;
        $groups = is_array($parsed['groups'] ?? null) ? $parsed['groups'] : [];
        $total = 0.0;
        foreach ($groups as &$g) {
            $series = strtoupper(trim((string)($g['series'] ?? '')));
            [$catId, $code, $cleanName, $fullName] = self::findCommissionCategory($portal, $series);
            $g['category_id'] = $catId ?: null;
            $g['operation_code'] = $code;
            $g['operation_type_name'] = $cleanName !== '' ? $cleanName : ('Комиссии - страхования' . ($series !== '' ? ' - ' . $series : ''));
            $g['category_name'] = $fullName;
            $g['amount'] = round((float)($g['amount'] ?? 0), 2);
            $total += (float)$g['amount'];
        }
        unset($g);
        $parsed['groups'] = $groups;
        $parsed['total'] = round($total, 2);
        $seriesList = array_values(array_unique(array_filter(array_map(static function($g){ return strtoupper(trim((string)($g['series'] ?? ''))); }, $groups))));
        $parsed['series'] = $seriesList;
        $parsed['series_label'] = implode(', ', $seriesList);
        $parsed['primary_series'] = $seriesList[0] ?? ''; // без MIX/MULTI, подробности хранятся в groups
        return $parsed;
    }

    private static function commissionDescription($series, $parsed, $userDescription = '') {
        $seriesLabel = trim((string)($parsed['series_label'] ?? ''));
        $series = strtoupper(trim((string)$series));
        if ($seriesLabel === '' && $series !== '' && !in_array($series, ['MIX','MULTI'], true)) $seriesLabel = $series;
        $basePrefix = 'Комиссионное вознаграждение по страховым продуктам';
        $base = $basePrefix . ($seriesLabel !== '' ? ' — ' . $seriesLabel : '');
        $userDescription = trim((string)$userDescription);
        if ($userDescription !== '') {
            $lines = preg_split('/\R/u', $userDescription) ?: [];
            $clean = [];
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '') continue;
                // Убираем старые автоматические строки, чтобы комментарий не дублировался.
                if (mb_stripos($line, $basePrefix) === 0) continue;
                $clean[] = $line;
            }
            $userDescription = trim(implode("
", $clean));
        }
        return $userDescription !== '' ? ($base . "
" . $userDescription) : $base;
    }

    private static function storeCommissionImportFile($portal, $tmpPath, $originalName) {
        $originalName = basename((string)$originalName);
        if ($tmpPath === '' || !is_file($tmpPath)) return ['', ''];
        $root = dirname(__DIR__) . '/uploads/commission';
        $bucket = substr(md5((string)$portal), 0, 12) . '/' . date('Y/m');
        $dir = $root . '/' . $bucket;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (!is_dir($dir) || !is_writable($dir)) return ['', ''];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','xls'], true)) $ext = 'xlsx';
        $safeBase = preg_replace('/[^A-Za-zА-Яа-я0-9._-]+/u', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $safeBase = trim($safeBase, '._-') ?: 'commission_import';
        $fileName = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(8)), 0, 12) . '_' . mb_substr($safeBase, 0, 70) . '.' . $ext;
        $dest = $dir . '/' . $fileName;
        $ok = is_uploaded_file($tmpPath) ? @move_uploaded_file($tmpPath, $dest) : @copy($tmpPath, $dest);
        if (!$ok || !is_file($dest)) return ['', ''];
        @chmod($dest, 0664);
        return ['uploads/commission/' . $bucket . '/' . $fileName, $originalName];
    }


    private static function normalizeUploadArray($files) {
        if (!is_array($files) || empty($files['name'])) return [];
        $out = [];
        if (is_array($files['name'])) {
            $count = count($files['name']);
            for ($i=0; $i<$count; $i++) {
                $out[] = [
                    'name'=>$files['name'][$i] ?? '',
                    'type'=>$files['type'][$i] ?? '',
                    'tmp_name'=>$files['tmp_name'][$i] ?? '',
                    'error'=>$files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size'=>$files['size'][$i] ?? 0,
                ];
            }
        } else {
            $out[] = $files;
        }
        return $out;
    }

    private static function storeRequestAttachments($portal, $files) {
        $stored = [];
        foreach (self::normalizeUploadArray($files) as $f) {
            $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
            $tmp = (string)($f['tmp_name'] ?? '');
            $orig = basename((string)($f['name'] ?? ''));
            if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp) || $orig === '') continue;
            $root = dirname(__DIR__) . '/uploads/requests';
            $bucket = substr(md5((string)$portal), 0, 12) . '/' . date('Y/m');
            $dir = $root . '/' . $bucket;
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            if (!is_dir($dir) || !is_writable($dir)) continue;
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowed = ['pdf','xlsx','xls','doc','docx','jpg','jpeg','png','webp','txt','zip','rar'];
            if (!in_array($ext, $allowed, true)) $ext = 'bin';
            $safeBase = preg_replace('/[^A-Za-zА-Яа-я0-9._-]+/u', '_', pathinfo($orig, PATHINFO_FILENAME));
            $safeBase = trim($safeBase, '._-') ?: 'file';
            $fileName = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(8)), 0, 12) . '_' . mb_substr($safeBase, 0, 70) . '.' . $ext;
            $dest = $dir . '/' . $fileName;
            $size = (int)($f['size'] ?? 0);
            if ($size <= 0 && is_file($tmp)) $size = (int)@filesize($tmp);
            $mime = trim((string)($f['type'] ?? ''));
            if ($mime === '' && function_exists('mime_content_type') && is_file($tmp)) $mime = (string)@mime_content_type($tmp);
            $backupB64 = '';
            // v89: резервное хранение делаем только для небольших файлов.
            // Большие base64-вложения легко превышают max_allowed_packet и дают MySQL error 2006.
            if ($size > 0 && $size <= 512 * 1024 && is_file($tmp)) {
                $raw = @file_get_contents($tmp);
                if ($raw !== false) $backupB64 = base64_encode($raw);
            }
            if (@move_uploaded_file($tmp, $dest) && is_file($dest)) {
                @chmod($dest, 0664);
                $item = [
                    'name'=>$orig,
                    'path'=>'uploads/requests/' . $bucket . '/' . $fileName,
                    'size'=>$size,
                    'mime'=>$mime ?: 'application/octet-stream',
                ];
                if ($backupB64 !== '') $item['data_b64'] = $backupB64;
                $stored[] = $item;
            }
        }
        return $stored;
    }

    public static function requestAttachmentAbsolutePath($relativePath) {
        $relativePath = ltrim(str_replace(['..', '\\'], ['', '/'], (string)$relativePath), '/');
        if ($relativePath === '' || strpos($relativePath, 'uploads/requests/') !== 0) return '';
        $path = dirname(__DIR__) . '/' . $relativePath;
        return is_file($path) ? $path : '';
    }

    public static function attachments($req) {
        if (empty($req['attachments_json'])) return [];
        $arr = json_decode((string)$req['attachments_json'], true);
        return is_array($arr) ? array_values(array_filter($arr, static fn($x)=>is_array($x))) : [];
    }

    private static function encodeAttachmentsForDb(array $attachments) {
        if (!$attachments) return null;
        $json = json_encode($attachments, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json !== false && strlen($json) <= 1400000) return $json;

        // Если общий JSON всё равно получился крупным, сохраняем только метаданные и путь к файлу.
        // Это защищает создание заявки от SQLSTATE[HY000] 2006 MySQL server has gone away.
        foreach ($attachments as &$item) {
            if (is_array($item)) unset($item['data_b64']);
        }
        unset($item);
        $json = json_encode($attachments, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return ($json !== false && strlen($json) <= 1400000) ? $json : null;
    }

    private static function commissionGroupsPayload($parsed) {
        if (!is_array($parsed)) return null;
        $groups = is_array($parsed['groups'] ?? null) ? array_values($parsed['groups']) : [];
        if (!$groups) return null;
        $series = array_values(array_unique(array_filter(array_map(static fn($g)=>strtoupper(trim((string)($g['series'] ?? ''))), $groups))));
        $payload = [
            'groups' => $groups,
            'total' => round((float)($parsed['total'] ?? array_sum(array_map(static fn($g)=>(float)($g['amount'] ?? 0), $groups))), 2),
            'series' => $series,
            'series_label' => (string)($parsed['series_label'] ?? ''),
            'primary_series' => (string)($parsed['primary_series'] ?? ''),
        ];
        if ($payload['series_label'] === '') $payload['series_label'] = implode(', ', $payload['series']);
        if ($payload['primary_series'] === '') $payload['primary_series'] = $payload['series'][0] ?? '';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : null;
    }

    private static function commissionSeriesPayload($parsed) {
        if (!is_array($parsed)) return null;
        $series = [];
        foreach ((array)($parsed['series'] ?? []) as $s) {
            $s = self::normalizeProductSeries($s);
            if ($s !== '') $series[$s] = true;
        }
        foreach ((array)($parsed['groups'] ?? []) as $g) if (is_array($g)) {
            $s = self::normalizeProductSeries($g['series'] ?? '');
            if ($s !== '') $series[$s] = true;
        }
        $json = json_encode(array_values(array_keys($series)), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : null;
    }

    private static function decodeCommissionGroupsOnly($req) {
        $sources = [];
        if (!empty($req['commission_groups_json'])) $sources[] = (string)$req['commission_groups_json'];
        if (!empty($req['commission_rows_json'])) $sources[] = (string)$req['commission_rows_json'];
        foreach ($sources as $raw) {
            $parsed = json_decode($raw, true);
            if (!is_array($parsed)) continue;
            $groups = is_array($parsed['groups'] ?? null) ? $parsed['groups'] : [];
            if ($groups) return [$groups, $parsed];
        }
        return [[], []];
    }

    public static function commissionFileAbsolutePath($relativePath) {
        $relativePath = ltrim(str_replace(['..', '\\'], ['', '/'], (string)$relativePath), '/');
        if ($relativePath === '' || strpos($relativePath, 'uploads/commission/') !== 0) return '';
        $path = dirname(__DIR__) . '/' . $relativePath;
        return is_file($path) ? $path : '';
    }


    public static function commissionFallbackExcelText($req) {
        if (empty($req) || (($req['request_type'] ?? '') !== 'commission')) return '';
        [$groups, $parsed] = self::decodeCommissionGroupsOnly($req);
        if (!$groups) return '';
        $lines = [];
        $lines[] = ['Аллокация','Серия','Код статьи','Название статьи','Сумма TJS'];
        $total = 0.0;
        foreach ($groups as $g) {
            $amount = round((float)($g['amount'] ?? 0), 2);
            $total += $amount;
            $lines[] = [
                (string)($g['allocation'] ?? ''),
                (string)($g['series'] ?? ''),
                (string)($g['operation_code'] ?? ''),
                (string)($g['operation_type_name'] ?? ''),
                number_format($amount, 2, '.', ''),
            ];
        }
        $lines[] = ['','','','Общая сумма',number_format($total, 2, '.', '')];
        $txt = '';
        foreach ($lines as $row) {
            $txt .= implode("\t", array_map(static function($v){
                $v = str_replace(["\t", "\r", "\n"], ' ', (string)$v);
                return $v;
            }, $row)) . "\r\n";
        }
        if (function_exists('mb_convert_encoding')) return "\xFF\xFE" . mb_convert_encoding($txt, 'UTF-16LE', 'UTF-8');
        return $txt;
    }

    public static function stages() {
        return [
            'creator' => 'Заявитель',
            'manager' => 'Руководитель заявителя',
            'finance_route' => 'Финансист: приём и выбор маршрута',
            'department_director' => 'Директор департамента',
            'finance_final' => 'Финансист: финальное согласование',
            'general_director' => 'Генеральный директор',
            'security' => 'Служба безопасности',
            'treasury' => 'Казначей',
            'finance_director' => 'Финансовый директор',
            'creator_rework' => 'Доработка у заявителя',
            'finance_rework' => 'Доработка у финансиста',
            'completed' => 'Завершено',
            'rejected' => 'Отклонено',
        ];
    }

    private static function baseConfigurableStages() {
        return [
            // Один общий список финансистов используется и для приёма заявки, и для финального согласования.
            'finance_route' => 'Финансисты',
            'general_director' => 'Генеральный директор',
            'security' => 'Служба безопасности',
            'finance_director' => 'Финансовый директор',
            'treasury' => 'Казначеи',
        ];
    }

    public static function configurableStages() {
        return self::baseConfigurableStages();
    }

    private static function isCustomRouteStage($stage) {
        return (bool)preg_match('/^custom_[a-z0-9_]+$/', (string)$stage);
    }

    private static function isConfigurableRouteStage($stage) {
        $stage = (string)$stage;
        return isset(self::baseConfigurableStages()[$stage]) || self::isCustomRouteStage($stage);
    }

    private static function defaultStageOrder($stage) {
        $map = ['finance_route'=>20,'general_director'=>40,'security'=>50,'finance_director'=>60,'treasury'=>70];
        return $map[$stage] ?? 80;
    }

    public static function actionLabels() {
        return [
            'created' => 'Заявка создана',
            'approved' => 'Согласовано',
            'move' => 'Передано на этап',
            'route' => 'Маршрут выбран',
            'rework' => 'Возвращено на доработку',
            'resubmit' => 'Повторно отправлено',
            'reassigned' => 'Исполнители этапа изменены',
            'extra_added' => 'Добавлены дополнительные согласующие',
            'completed' => 'Заявка полностью согласована',
            'payment_created' => 'Расходная операция создана',
            'payment_error' => 'Ошибка создания расходной операции',
            'rejected' => 'Отклонено',
            'deleted' => 'Удалено',
        ];
    }

    public static function approverStatusLabel($s) {
        $map = ['pending'=>'Ожидает','planned'=>'Ожидается','approved'=>'Согласовано','skipped'=>'Пропущено','rejected'=>'Отклонено','reworked'=>'Доработано'];
        return $map[$s] ?? $s;
    }

    public static function approverStatusLabelForStage($status, $stage) {
        if ($status === 'approved' && (string)$stage === 'creator') return 'Создано';
        if ($status === 'approved' && in_array((string)$stage, ['creator_rework','finance_rework'], true)) {
            return 'Доработано';
        }
        return self::approverStatusLabel($status);
    }

    public static function actionLabel($a) {
        $m = self::actionLabels();
        return $m[$a] ?? $a;
    }

    public static function stageLabel($stage) {
        $stage = (string)$stage;
        if (preg_match('/^extra_(.+)_([0-9]+)$/', $stage)) return 'Доп. согласование';
        $st = self::stages();
        return $st[$stage] ?? $stage;
    }

    private static function parseIds($raw) {
        if (is_array($raw)) $raw = implode(',', $raw);
        preg_match_all('/\d+/', (string)$raw, $m);
        return array_values(array_unique(array_filter(array_map('intval', $m[0] ?? []), static fn($v)=>$v>0)));
    }

    private static function parseNames($raw) {
        $raw = trim((string)$raw);
        if ($raw === '') return [];
        $parts = preg_split('/[\n;,]+/u', $raw);
        return array_values(array_filter(array_map(static fn($v)=>trim((string)$v), $parts)));
    }

    public static function usersToText($ids, $names = []) {
        $ids = self::parseIds($ids);
        $names = is_array($names) ? $names : self::parseNames($names);
        $out = [];
        foreach ($ids as $i=>$id) {
            $name = trim((string)($names[$i] ?? ''));
            $out[] = $name ? ($name . ' #' . $id) : ('#' . $id);
        }
        return implode(', ', $out);
    }

    private static function normalizeRouteType($routeType) {
        $routeType = preg_replace('/[^a-z0-9_]/', '', (string)$routeType);
        if ($routeType === 'regular') return 'regular';
        if (in_array($routeType, ['commission2','commission_box','commissionbox','box'], true)) return 'commission_box';
        return 'commission';
    }

    private static function routeStagePrefix($routeType) {
        $type = self::normalizeRouteType($routeType);
        if ($type === 'regular') return 'r_';
        if ($type === 'commission_box') return 'cb_';
        return 'c_';
    }

    private static function invalidateRouteSettingsCache($portal = null) {
        if ($portal === null || $portal === '') {
            self::$routeSettingsByTypeCache = [];
            self::$routeSettingsLegacyCache = [];
            return;
        }
        foreach (array_keys(self::$routeSettingsByTypeCache) as $key) {
            if (strpos($key, (string)$portal . '|') === 0) unset(self::$routeSettingsByTypeCache[$key]);
        }
        unset(self::$routeSettingsLegacyCache[(string)$portal]);
    }

    private static function storageStage($stage, $routeType = 'commission') {
        $stage = preg_replace('/[^a-z0-9_]/', '', (string)$stage);
        if ($stage === '' || strpos($stage, 'option_') === 0) return $stage;
        return self::routeStagePrefix($routeType) . $stage;
    }

    private static function isTypedStorageStage($storageStage, $routeType, &$plainStage = null) {
        $prefix = self::routeStagePrefix($routeType);
        $storageStage = (string)$storageStage;
        if (strpos($storageStage, $prefix) !== 0) return false;
        $plain = substr($storageStage, strlen($prefix));
        if (!self::isConfigurableRouteStage($plain)) return false;
        $plainStage = $plain;
        return true;
    }

    private static function defaultEnabledForType($stage, $routeType) {
        $routeType = self::normalizeRouteType($routeType);
        if ($routeType === 'regular' && in_array((string)$stage, ['general_director','security'], true)) return 0;
        return 1;
    }

    public static function routeTypeTitle($routeType) {
        $routeType = self::normalizeRouteType($routeType);
        if ($routeType === 'regular') return 'Обычный маршрут';
        if ($routeType === 'commission_box') return 'Комиссионный маршрут коробочные продукты и остальные (без корпоратов)';
        return 'Комиссионный маршрут';
    }

    public static function routeTypeHint($routeType) {
        $routeType = self::normalizeRouteType($routeType);
        if ($routeType === 'commission') return 'Используется только когда в комиссионном Excel все серии относятся к корпоративным продуктам: CI, CP, CE, CT, MI, GA, FI.';
        if ($routeType === 'commission_box') return 'Используется для коробочных и остальных комиссионных продуктов, если в Excel есть хотя бы одна серия вне CI, CP, CE, CT, MI, GA, FI.';
        return '';
    }

    public static function routeTypeTabs() {
        return [
            'regular' => self::routeTypeTitle('regular'),
            'commission' => self::routeTypeTitle('commission'),
            'commission_box' => self::routeTypeTitle('commission_box'),
        ];
    }

    public static function corporateCommissionProducts() {
        return self::CORPORATE_COMMISSION_PRODUCTS;
    }

    public static function getRouteSettingsByType($portal, $routeType = 'commission') {
        self::ensureV58Schema();
        $routeType = self::normalizeRouteType($routeType);
        $cacheKey = (string)$portal . '|' . $routeType;
        if (isset(self::$routeSettingsByTypeCache[$cacheKey])) return self::$routeSettingsByTypeCache[$cacheKey];
        try { $rows = DB::fetchAll("SELECT * FROM approval_route_settings WHERE portal=? ORDER BY sort_order, id", [$portal]); }
        catch (Throwable $e) {
            app_log('Approval::getRouteSettingsByType order fallback: '.$e->getMessage());
            try { $rows = DB::fetchAll("SELECT * FROM approval_route_settings WHERE portal=? ORDER BY id", [$portal]); }
            catch (Throwable $e2) { $rows = []; }
        }
        $out = [];
        $legacy = [];
        foreach ($rows as $r) {
            $storageStage = (string)($r['stage'] ?? '');
            $plainStage = '';
            if (self::isTypedStorageStage($storageStage, $routeType, $plainStage)) {
                $r['storage_stage'] = $storageStage;
                $r['stage'] = $plainStage;
                $out[$plainStage] = $r;
                continue;
            }
            // Старые непомеченные настройки оставляем как резерв для комиссионного маршрута,
            // чтобы уже настроенный маршрут не пропал после обновления.
            if ($routeType === 'commission' && self::isConfigurableRouteStage($storageStage)) {
                $r['storage_stage'] = $storageStage;
                $legacy[$storageStage] = $r;
            }
        }
        foreach ($legacy as $stage=>$r) {
            if (!isset($out[$stage])) $out[$stage] = $r;
        }
        if ($routeType === 'commission_box' && empty($out)) {
            // Первое открытие нового маршрута: показываем копию текущего комиссионного маршрута,
            // чтобы администратор мог быстро отредактировать только отличия.
            foreach ($rows as $r) {
                $storageStage = (string)($r['stage'] ?? '');
                $plainStage = '';
                if (self::isTypedStorageStage($storageStage, 'commission', $plainStage)) {
                    $r['storage_stage'] = self::storageStage($plainStage, 'commission_box');
                    $r['stage'] = $plainStage;
                    $out[$plainStage] = $r;
                } elseif (self::isConfigurableRouteStage($storageStage)) {
                    $r['storage_stage'] = self::storageStage($storageStage, 'commission_box');
                    $r['stage'] = $storageStage;
                    $out[$storageStage] = $r;
                }
            }
        }
        foreach (self::baseConfigurableStages() as $stage=>$label) {
            if (empty($out[$stage])) {
                $out[$stage] = [
                    'stage'=>$stage,
                    'storage_stage'=>self::storageStage($stage, $routeType),
                    'stage_label'=>$label,
                    'approver_ids'=>'',
                    'approver_names'=>'',
                    'threshold_amount'=>15000,
                    'sort_order'=>self::defaultStageOrder($stage),
                    'is_enabled'=>self::defaultEnabledForType($stage, $routeType),
                    'stage_hint'=>'',
                    'stage_icon'=>'',
                ];
            } else {
                if (!isset($out[$stage]['storage_stage'])) $out[$stage]['storage_stage'] = self::storageStage($stage, $routeType);
                if (!isset($out[$stage]['sort_order'])) $out[$stage]['sort_order'] = self::defaultStageOrder($stage);
                if (!isset($out[$stage]['is_enabled'])) $out[$stage]['is_enabled'] = self::defaultEnabledForType($stage, $routeType);
                if (trim((string)($out[$stage]['stage_label'] ?? '')) === '') $out[$stage]['stage_label'] = $label;
            }
        }
        uasort($out, static function($a,$b){
            $ao = (int)($a['sort_order'] ?? 100); $bo = (int)($b['sort_order'] ?? 100);
            if ($ao === $bo) return strcmp((string)($a['stage'] ?? ''), (string)($b['stage'] ?? ''));
            return $ao <=> $bo;
        });
        self::$routeSettingsByTypeCache[$cacheKey] = $out;
        return $out;
    }

    public static function getRouteSettings($portal) {
        self::ensureV58Schema();
        $cacheKey = (string)$portal;
        if (isset(self::$routeSettingsLegacyCache[$cacheKey])) return self::$routeSettingsLegacyCache[$cacheKey];
        try { $rows = DB::fetchAll("SELECT * FROM approval_route_settings WHERE portal=? ORDER BY sort_order, id", [$portal]); }
        catch (Throwable $e) {
            app_log('Approval::getRouteSettings v58 order fallback: '.$e->getMessage());
            try { $rows = DB::fetchAll("SELECT * FROM approval_route_settings WHERE portal=? ORDER BY id", [$portal]); }
            catch (Throwable $e2) { $rows = []; }
        }
        $out = [];
        foreach ($rows as $r) $out[$r['stage']] = $r;
        foreach (self::baseConfigurableStages() as $stage=>$label) {
            if (empty($out[$stage])) {
                $out[$stage] = [
                    'stage'=>$stage,
                    'stage_label'=>$label,
                    'approver_ids'=>'',
                    'approver_names'=>'',
                    'threshold_amount'=>15000,
                    'sort_order'=>self::defaultStageOrder($stage),
                    'is_enabled'=>1,
                    'stage_hint'=>'',
                    'stage_icon'=>'',
                ];
            } else {
                if (!isset($out[$stage]['sort_order'])) $out[$stage]['sort_order'] = self::defaultStageOrder($stage);
                if (!isset($out[$stage]['is_enabled'])) $out[$stage]['is_enabled'] = 1;
                if (trim((string)($out[$stage]['stage_label'] ?? '')) === '') $out[$stage]['stage_label'] = $label;
            }
        }
        uasort($out, static function($a,$b){
            $ao = (int)($a['sort_order'] ?? 100); $bo = (int)($b['sort_order'] ?? 100);
            if ($ao === $bo) return strcmp((string)($a['stage'] ?? ''), (string)($b['stage'] ?? ''));
            return $ao <=> $bo;
        });
        self::$routeSettingsLegacyCache[$cacheKey] = $out;
        return $out;
    }

    public static function orderedConfigurableStages($portal, $routeType = 'commission') {
        $routes = self::getRouteSettingsByType($portal, $routeType);
        $out = [];
        foreach ($routes as $stage=>$r) {
            if (!self::isConfigurableRouteStage($stage)) continue;
            $out[$stage] = trim((string)($r['stage_label'] ?? '')) ?: (self::baseConfigurableStages()[$stage] ?? 'Дополнительный этап');
        }
        foreach (self::baseConfigurableStages() as $stage=>$label) if (!isset($out[$stage])) $out[$stage] = $label;
        return $out;
    }

    public static function saveRouteSetting($portal, $stage, $ids, $names = '', $threshold = 15000, $stageLabel = '', $sortOrder = 100, $isEnabled = 1, $stageHint = '', $stageIcon = '', $routeType = 'commission') {
        self::ensureV58Schema();
        $stage = preg_replace('/[^a-z0-9_]/', '', (string)$stage);
        if (!self::isConfigurableRouteStage($stage)) return false;
        $storageStage = self::storageStage($stage, $routeType);
        $idsList = self::parseIds($ids);
        $namesList = self::parseNames($names);
        $idsText = implode(',', $idsList);
        $namesText = implode("
", $namesList);
        $label = trim((string)$stageLabel) ?: (self::baseConfigurableStages()[$stage] ?? 'Дополнительный этап');
        $hint = trim((string)$stageHint);
        $icon = trim((string)$stageIcon);
        if (mb_strlen($icon) > 32) $icon = mb_substr($icon, 0, 32);
        $sortOrder = (int)$sortOrder;
        if ($sortOrder <= 0) $sortOrder = self::defaultStageOrder($stage);
        try {
            DB::execute("INSERT INTO approval_route_settings (portal,stage,stage_label,approver_ids,approver_names,threshold_amount,sort_order,is_enabled,stage_hint,stage_icon)
                         VALUES (?,?,?,?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE stage_label=VALUES(stage_label),approver_ids=VALUES(approver_ids),approver_names=VALUES(approver_names),threshold_amount=VALUES(threshold_amount),sort_order=VALUES(sort_order),is_enabled=VALUES(is_enabled),stage_hint=VALUES(stage_hint),stage_icon=VALUES(stage_icon)",
                [$portal,$storageStage,$label,$idsText,$namesText,(float)$threshold,$sortOrder,(int)(!!$isEnabled),$hint,$icon]);
        } catch (Throwable $e) {
            app_log('Approval::saveRouteSetting v60 fallback: '.$e->getMessage());
            try {
                DB::execute("INSERT INTO approval_route_settings (portal,stage,stage_label,approver_ids,approver_names,threshold_amount,sort_order,is_enabled)
                             VALUES (?,?,?,?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE stage_label=VALUES(stage_label),approver_ids=VALUES(approver_ids),approver_names=VALUES(approver_names),threshold_amount=VALUES(threshold_amount),sort_order=VALUES(sort_order),is_enabled=VALUES(is_enabled)",
                    [$portal,$storageStage,$label,$idsText,$namesText,(float)$threshold,$sortOrder,(int)(!!$isEnabled)]);
            } catch (Throwable $e2) {
                app_log('Approval::saveRouteSetting v58 fallback: '.$e2->getMessage());
                DB::execute("INSERT INTO approval_route_settings (portal,stage,stage_label,approver_ids,approver_names,threshold_amount)
                             VALUES (?,?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE stage_label=VALUES(stage_label),approver_ids=VALUES(approver_ids),approver_names=VALUES(approver_names),threshold_amount=VALUES(threshold_amount)",
                    [$portal,$storageStage,$label,$idsText,$namesText,(float)$threshold]);
            }
        }
        self::invalidateRouteSettingsCache($portal);
        return true;
    }

    public static function getRouteOption($portal, $name, $default = '') {
        self::ensureV58Schema();
        $name = preg_replace('/[^a-z0-9_]/', '', (string)$name);
        if ($name === '') return $default;
        $stage = 'option_' . $name;
        try {
            $row = DB::fetchOne("SELECT stage_label FROM approval_route_settings WHERE portal=? AND stage=? LIMIT 1", [$portal, $stage]);
            $value = trim((string)($row['stage_label'] ?? ''));
            return $value !== '' ? $value : $default;
        } catch (Throwable $e) {
            app_log('Approval::getRouteOption failed: '.$e->getMessage());
            return $default;
        }
    }

    public static function saveRouteOption($portal, $name, $value) {
        self::ensureV58Schema();
        $name = preg_replace('/[^a-z0-9_]/', '', (string)$name);
        $value = preg_replace('/[^a-z0-9_]/', '', (string)$value);
        if ($name === '') return false;
        $stage = 'option_' . $name;
        if ($value === '') $value = 'short';
        try {
            DB::execute("INSERT INTO approval_route_settings (portal,stage,stage_label,approver_ids,approver_names,threshold_amount,sort_order,is_enabled,stage_hint,stage_icon)
                         VALUES (?,?,?,?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE stage_label=VALUES(stage_label),sort_order=VALUES(sort_order),is_enabled=VALUES(is_enabled),stage_hint=VALUES(stage_hint),stage_icon=VALUES(stage_icon)",
                [$portal,$stage,$value,'','',15000,-1000,0,'system option','']);
        } catch (Throwable $e) {
            app_log('Approval::saveRouteOption v100 fallback: '.$e->getMessage());
            DB::execute("INSERT INTO approval_route_settings (portal,stage,stage_label,approver_ids,approver_names,threshold_amount)
                         VALUES (?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE stage_label=VALUES(stage_label)",
                [$portal,$stage,$value,'','',15000]);
        }
        self::invalidateRouteSettingsCache($portal);
        return true;
    }

    public static function deleteRouteSetting($portal, $stage, $routeType = 'commission') {
        self::ensureV58Schema();
        $stage = preg_replace('/[^a-z0-9_]/', '', (string)$stage);
        if (!self::isCustomRouteStage($stage)) return false;
        $storage = self::storageStage($stage, $routeType);
        DB::execute("DELETE FROM approval_route_settings WHERE portal=? AND stage=?", [$portal, $storage]);
        // Для комиссионного маршрута удаляем и старую непомеченную запись, иначе она снова появится как legacy fallback.
        if (self::normalizeRouteType($routeType) === 'commission') {
            DB::execute("DELETE FROM approval_route_settings WHERE portal=? AND stage=?", [$portal, $stage]);
        }
        self::invalidateRouteSettingsCache($portal);
        return true;
    }

    public static function stageLabelFor($portal, $stage) {
        $stage = (string)$stage;
        if (preg_match('/^extra_(.+)_([0-9]+)$/', $stage, $m)) {
            return 'Доп. согласование после этапа: ' . self::stageLabelFor($portal, $m[1]);
        }
        $routes = self::getRouteSettings($portal);
        if (!empty($routes[$stage]['stage_label'])) return (string)$routes[$stage]['stage_label'];
        return self::stageLabel($stage);
    }

    public static function stageLabelForRequest($portal, $request, $stage) {
        $stage = (string)$stage;
        if (preg_match('/^extra_(.+)_([0-9]+)$/', $stage, $m)) {
            return 'Доп. согласование после этапа: ' . self::stageLabelForRequest($portal, $request, $m[1]);
        }
        $req = is_array($request) ? $request : [];
        $routeType = self::routeTypeForRequest($req);
        if (self::isConfigurableRouteStage($stage)) {
            $routes = self::getRouteSettingsByType($portal, $routeType);
            if (!empty($routes[$stage]['stage_label'])) return (string)$routes[$stage]['stage_label'];
        }
        return self::stageLabel($stage);
    }

    private static function settingIds($portal, $stage, $routeType = 'commission') {
        if ($stage === 'finance_final') $stage = 'finance_route';
        $s = self::getRouteSettingsByType($portal, $routeType);
        return self::parseIds($s[$stage]['approver_ids'] ?? '');
    }

    private static function settingNames($portal, $stage, $routeType = 'commission') {
        if ($stage === 'finance_final') $stage = 'finance_route';
        $s = self::getRouteSettingsByType($portal, $routeType);
        return self::parseNames($s[$stage]['approver_names'] ?? '');
    }

    private static function normalizeProductSeries($value) {
        $series = strtoupper(trim((string)$value));
        $series = preg_replace('/[^A-Z0-9]+/', '', $series);
        return $series ?: '';
    }

    private static function extractCommissionSeries($req) {
        $series = [];
        $add = static function($value) use (&$series) {
            $s = Approval::normalizeProductSeries($value);
            if ($s !== '') $series[$s] = true;
        };
        if (!empty($req['commission_series_json'])) {
            $decodedSeries = json_decode((string)$req['commission_series_json'], true);
            if (is_array($decodedSeries)) foreach ($decodedSeries as $s) $add($s);
        }
        if (!empty($req['commission_groups_json'])) {
            $parsedGroups = json_decode((string)$req['commission_groups_json'], true);
            if (is_array($parsedGroups)) {
                foreach ((array)($parsedGroups['series'] ?? []) as $s) $add($s);
                foreach ((array)($parsedGroups['groups'] ?? []) as $g) if (is_array($g)) $add($g['series'] ?? '');
            }
        }
        if (!$series && !empty($req['commission_rows_json'])) {
            $parsed = json_decode((string)$req['commission_rows_json'], true);
            if (is_array($parsed)) {
                foreach ((array)($parsed['series'] ?? []) as $s) $add($s);
                foreach ((array)($parsed['groups'] ?? []) as $g) if (is_array($g)) $add($g['series'] ?? '');
                if (!$series) foreach ((array)($parsed['items'] ?? []) as $it) if (is_array($it)) $add($it['series'] ?? '');
            }
        }
        if (!empty($req['product_series'])) $add($req['product_series']);
        return array_values(array_keys($series));
    }

    private static function isCorporateCommissionOnly($req) {
        $series = self::extractCommissionSeries($req);
        if (!$series) return self::isSpecialInsuranceCommission($req);
        foreach ($series as $s) {
            if (!in_array($s, self::CORPORATE_COMMISSION_PRODUCTS, true)) return false;
        }
        return true;
    }

    private static function routeTypeForRequest($req) {
        if (($req['request_type'] ?? '') === 'commission') {
            return self::isCorporateCommissionOnly($req) ? 'commission' : 'commission_box';
        }
        return self::isSpecialInsuranceCommission($req) ? 'commission' : 'regular';
    }

    public static function routeTypeForRequestLabel($req) {
        return self::routeTypeTitle(self::routeTypeForRequest($req));
    }

    private static function addApprovers($requestId, $stage, $ids, $names = []) {
        $ids = self::parseIds($ids);
        $names = is_array($names) ? $names : self::parseNames($names);
        DB::execute("DELETE FROM approval_request_approvers WHERE request_id=? AND stage=? AND status='pending'", [$requestId,$stage]);
        foreach ($ids as $i=>$id) {
            DB::insert("INSERT INTO approval_request_approvers (request_id,stage,user_id,user_name,status) VALUES (?,?,?,?, 'pending')",
                [$requestId,$stage,$id,trim((string)($names[$i] ?? ''))]);
        }
    }

    private static function history($requestId, $action, $fromStage, $toStage, $userId, $userName, $comment = '') {
        DB::insert("INSERT INTO approval_request_history (request_id,action,from_stage,to_stage,user_id,user_name,comment,created_at) VALUES (?,?,?,?,?,?,?,?)",
            [$requestId,$action,$fromStage,$toStage,(int)$userId,$userName,$comment,self::nowSql()]);
    }

    private static function userName($user) {
        $name = trim(($user['LAST_NAME'] ?? '') . ' ' . ($user['NAME'] ?? ''));
        return $name ?: ('User #' . (int)($user['ID'] ?? 0));
    }

    private static function isPlaceholderUserName($name, $userId = 0) {
        $name = trim((string)$name);
        if ($name === '') return true;
        if (preg_match('/^(User|Сотрудник)\s*#?\s*\d+$/ui', $name)) return true;
        if ($userId > 0 && preg_match('/^#?\s*'.preg_quote((string)(int)$userId, '/').'$/u', $name)) return true;
        return false;
    }

    public static function userDisplayName($portal, $userId, $stored = '') {
        $userId = (int)$userId;
        $stored = trim((string)$stored);
        if (!self::isPlaceholderUserName($stored, $userId)) return $stored;
        if ($userId <= 0) return $stored !== '' ? $stored : 'Система';
        static $cache = [];
        $ck = (string)$portal . '|' . $userId;
        if (array_key_exists($ck, $cache)) return $cache[$ck];
        $name = '';
        try {
            $rows = [];
            $rows[] = DB::fetchOne("SELECT bitrix_user_name AS name FROM account_permissions WHERE portal=? AND bitrix_user_id=? AND bitrix_user_name IS NOT NULL AND bitrix_user_name<>'' LIMIT 1", [$portal,$userId]);
            $rows[] = DB::fetchOne("SELECT creator_name AS name FROM approval_requests WHERE portal=? AND creator_id=? AND creator_name IS NOT NULL AND creator_name<>'' ORDER BY id DESC LIMIT 1", [$portal,$userId]);
            $rows[] = DB::fetchOne("SELECT manager_name AS name FROM approval_requests WHERE portal=? AND manager_id=? AND manager_name IS NOT NULL AND manager_name<>'' ORDER BY id DESC LIMIT 1", [$portal,$userId]);
            $rows[] = DB::fetchOne("SELECT aa.user_name AS name FROM approval_request_approvers aa JOIN approval_requests ar ON ar.id=aa.request_id WHERE ar.portal=? AND aa.user_id=? AND aa.user_name IS NOT NULL AND aa.user_name<>'' ORDER BY aa.id DESC LIMIT 1", [$portal,$userId]);
            $rows[] = DB::fetchOne("SELECT h.user_name AS name FROM approval_request_history h JOIN approval_requests ar ON ar.id=h.request_id WHERE ar.portal=? AND h.user_id=? AND h.user_name IS NOT NULL AND h.user_name<>'' ORDER BY h.id DESC LIMIT 1", [$portal,$userId]);
            foreach ($rows as $r) {
                $candidate = trim((string)($r['name'] ?? ''));
                if (!self::isPlaceholderUserName($candidate, $userId)) { $name = $candidate; break; }
            }
            if ($name === '' && class_exists('Bitrix')) {
                $u = Bitrix::getUserById($portal, $userId);
                $candidate = trim(($u['LAST_NAME'] ?? '') . ' ' . ($u['NAME'] ?? '') . ' ' . ($u['SECOND_NAME'] ?? ''));
                if (!self::isPlaceholderUserName($candidate, $userId)) $name = $candidate;
            }
        } catch (Throwable $e) { app_log('Approval::userDisplayName failed: '.$e->getMessage()); }
        if ($name === '') $name = 'Сотрудник #' . $userId;
        $cache[$ck] = $name;
        return $name;
    }

    private static function normalizedCreatorName($portal, $creatorId, $data, $user) {
        $name = trim((string)($data['creator_name'] ?? ''));
        if (self::isPlaceholderUserName($name, (int)$creatorId)) $name = self::userName($user);
        if (self::isPlaceholderUserName($name, (int)$creatorId)) $name = self::userDisplayName($portal, (int)$creatorId, $name);
        return $name;
    }

    private static function setStage($requestId, $stage, $status = null, $returnStage = null) {
        $status = $status ?: ('waiting_' . $stage);
        DB::execute("UPDATE approval_requests SET current_stage=?, status=?, return_stage=?, updated_at=? WHERE id=?", [$stage,$status,$returnStage,self::nowSql(),$requestId]);
    }

    private static function diffText($old, $new, $fields) {
        $labels = [
            'amount'=>'Сумма','currency'=>'Валюта','description'=>'Описание','manager_name'=>'Руководитель',
            'category_name'=>'Статья','operation_code'=>'Код','operation_type_name'=>'Название статьи','account_name'=>'Счёт/касса',
            'title'=>'Название заявки','director_names'=>'Директор департамента','department'=>'Отдел заявителя','sub_department'=>'Подотдел заявителя','region'=>'Регион','product_series'=>'Серия продукта'
        ];
        $lines = [];
        foreach ($fields as $key=>$labelKey) {
            $label = $labels[$labelKey] ?? $labelKey;
            $a = trim((string)($old[$key] ?? ''));
            $b = trim((string)($new[$key] ?? ''));
            if ($a !== $b) $lines[] = $label . ': было "' . ($a === '' ? 'пусто' : $a) . '", стало "' . ($b === '' ? 'пусто' : $b) . '"';
        }
        return $lines ? implode("\n", $lines) : '';
    }

    public static function create($portal, $data, $user) {
        self::ensureV62Schema();
        $creatorId = (int)($user['ID'] ?? 0);
        if ($creatorId <= 0) return ['error'=>'Не определён заявитель Bitrix24'];
        $requestType = trim((string)($data['request_type'] ?? 'regular')) === 'commission' ? 'commission' : 'regular';
        $title = trim((string)($data['title'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        if ($title === '') return ['error'=>'Введите название заявки'];

        $productSeries = strtoupper(preg_replace('/[^A-ZА-Я0-9]+/ui', '', (string)($data['product_series'] ?? '')));
        $commissionParsed = null;
        $commissionJson = null;
        $commissionFilename = '';
        $commissionImportPath = '';
        $commissionRowsCount = 0;
        $commissionGroupsJson = null;
        $commissionSeriesJson = null;
        $attachments = self::storeRequestAttachments($portal, $_FILES['request_files'] ?? []);
        $attachmentsJson = self::encodeAttachmentsForDb($attachments);

        if ($requestType === 'commission') {
            if (!empty($_FILES['commission_file']['tmp_name']) && is_uploaded_file($_FILES['commission_file']['tmp_name'])) {
                $tmpPath = $_FILES['commission_file']['tmp_name'];
                $origName = $_FILES['commission_file']['name'] ?? '';
                $commissionParsed = self::parseCommissionFile($tmpPath, $origName);
                if (!empty($commissionParsed['error'])) return ['error'=>$commissionParsed['error']];
                $commissionParsed = self::enrichCommissionParsed($portal, $commissionParsed);
                $commissionFilename = (string)($commissionParsed['filename'] ?? $origName);
                $commissionRowsCount = (int)($commissionParsed['rows'] ?? 0);
                $data['amount'] = (float)($commissionParsed['total'] ?? 0);
                if ($productSeries === '') $productSeries = strtoupper(trim((string)($commissionParsed['primary_series'] ?? '')));
                [$storedPath, $storedOriginal] = self::storeCommissionImportFile($portal, $tmpPath, $origName);
                if ($storedPath !== '') {
                    $commissionImportPath = $storedPath;
                    if ($storedOriginal !== '') $commissionFilename = $storedOriginal;
                }
            }
            if (!$commissionParsed) return ['error'=>'Загрузите Excel-файл комиссионной заявки'];
            if ($productSeries === '') $productSeries = strtoupper(trim((string)($commissionParsed['primary_series'] ?? '')));
            $description = self::commissionDescription($productSeries, $commissionParsed ?: [], $description);
            $firstGroup = is_array($commissionParsed['groups'][0] ?? null) ? $commissionParsed['groups'][0] : [];
            $data['operation_code'] = (string)($firstGroup['operation_code'] ?? '');
            $data['operation_type_name'] = (string)($firstGroup['operation_type_name'] ?? '');
            $data['category_id'] = !empty($firstGroup['category_id']) ? (int)$firstGroup['category_id'] : 0;
            $commissionJson = json_encode($commissionParsed, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $commissionGroupsJson = self::commissionGroupsPayload($commissionParsed);
            $commissionSeriesJson = self::commissionSeriesPayload($commissionParsed);
        } else {
            if ($description === '') return ['error'=>'Введите описание заявки'];
        }

        $amount = (float)($data['amount'] ?? 0);
        if ($amount < 0) $amount = abs($amount);
        if ($amount <= 0) return ['error'=>'Введите сумму заявки'];
        $managerId = (int)($data['manager_id'] ?? 0);
        if ($managerId <= 0) return ['error'=>'Выберите руководителя'];
        $creatorName = self::normalizedCreatorName($portal, $creatorId, $data, $user);
        $managerName = self::userDisplayName($portal, $managerId, trim((string)($data['manager_name'] ?? '')));
        $currency = 'TJS';

        $categoryId = (int)($data['category_id'] ?? 0);
        $operationCode = trim((string)($data['operation_code'] ?? ''));
        $operationTypeName = trim((string)($data['operation_type_name'] ?? ''));
        if ($requestType !== 'commission' && $categoryId <= 0 && $operationCode === '') return ['error'=>'Выберите статью или укажите код статьи'];
        if ($categoryId > 0) {
            $cat = DB::fetchOne("SELECT id,name FROM categories WHERE portal=? AND id=? LIMIT 1", [$portal,$categoryId]);
            if ($cat) {
                $catName = (string)($cat['name'] ?? '');
                if ($operationCode === '' && preg_match('/^\s*(\d{2,8})\s*[-–—]/u', $catName, $m)) $operationCode = $m[1];
                if ($operationTypeName === '') $operationTypeName = trim(preg_replace('/^\s*\d{2,8}\s*[-–—]\s*/u','',$catName));
            } else {
                $categoryId = 0;
            }
        }
        $department = trim((string)($data['department'] ?? ''));
        $subDepartment = trim((string)($data['sub_department'] ?? ''));
        $region = array_key_exists('region', $data) ? trim((string)$data['region']) : '';
        if ($department === '') return ['error'=>'Выберите отдел заявителя'];
        if ($subDepartment === '') return ['error'=>'Выберите подотдел заявителя'];
        if ($region === '' || !in_array($region, ['0','1','2'], true)) return ['error'=>'Выберите регион 0, 1 или 2'];
        $directorIds = self::parseIds($data['director_ids'] ?? '');
        $directorNames = self::parseNames($data['director_names'] ?? '');
        if (!$directorIds) return ['error'=>'Выберите директора департамента'];

        // Защита от дублей: если браузер/iframe отправил одну и ту же форму несколько раз,
        // возвращаем уже созданную заявку вместо повторной вставки.
        $recentDuplicate = DB::fetchOne("SELECT id FROM approval_requests WHERE portal=? AND creator_id=? AND request_type=? AND title=? AND amount=? AND description=? AND created_at >= DATE_SUB(NOW(), INTERVAL 20 SECOND) ORDER BY id DESC LIMIT 1", [$portal,$creatorId,$requestType,$title,$amount,$description]);
        if ($recentDuplicate && !empty($recentDuplicate['id'])) return (int)$recentDuplicate['id'];

        return DB::transaction(function() use ($portal,$title,$description,$amount,$currency,$creatorId,$creatorName,$managerId,$managerName,$categoryId,$operationCode,$operationTypeName,$directorIds,$directorNames,$department,$subDepartment,$region,$requestType,$productSeries,$commissionFilename,$commissionImportPath,$commissionRowsCount,$commissionJson,$commissionGroupsJson,$commissionSeriesJson,$attachmentsJson) {
            $id = DB::insert("INSERT INTO approval_requests
                (portal,creator_id,creator_name,manager_id,manager_name,amount,currency,title,description,category_id,operation_code,operation_type_name,director_ids,director_names,department,sub_department,region,request_type,product_series,commission_import_filename,commission_import_path,commission_rows_count,commission_rows_json,commission_groups_json,commission_series_json,attachments_json,status,current_stage,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'waiting_manager','manager',?,?)",
                [$portal,$creatorId,$creatorName,$managerId,$managerName,$amount,$currency,$title,$description,($categoryId ?: null),($operationCode ?: null),($operationTypeName ?: null),implode(',',$directorIds),implode("\n",$directorNames),$department,$subDepartment,$region,$requestType,($productSeries ?: null),($commissionFilename ?: null),($commissionImportPath ?: null),(int)$commissionRowsCount,($commissionJson ?: null),($commissionGroupsJson ?: null),($commissionSeriesJson ?: null),($attachmentsJson ?: null),self::nowSql(),self::nowSql()]);
            self::addApprovers($id, 'manager', [$managerId], [$managerName]);
            self::history($id,'created','', 'manager', $creatorId, $creatorName, $requestType === 'commission' ? 'Комиссионная заявка создана' : 'Заявка создана');
            self::notifyStage($portal, $id, 'manager');
            return $id;
        });
    }

    public static function canView($portal, $request, $userId, $isManager = false, $canViewAll = false) {
        if ($isManager || $canViewAll) return true;
        $userId = (int)$userId;
        if ($userId <= 0 || empty($request)) return false;
        if ((int)$request['creator_id'] === $userId || (int)$request['manager_id'] === $userId) return true;
        $r = DB::fetchOne("SELECT id FROM approval_request_approvers WHERE request_id=? AND user_id=? LIMIT 1", [(int)$request['id'],$userId]);
        if ($r) return true;
        foreach (['regular','commission','commission_box'] as $routeType) {
            foreach (self::configurableStages() as $stage=>$_) {
                if (in_array($userId, self::settingIds($portal,$stage,$routeType), true)) return true;
            }
        }
        return false;
    }

    public static function isFinanceController($portal, $userId, $isManager = false, $routeType = null) {
        if ($isManager) return true;
        $userId = (int)$userId;
        $routeTypes = $routeType ? [self::normalizeRouteType($routeType)] : ['regular','commission','commission_box'];
        foreach ($routeTypes as $rt) {
            if (in_array($userId, self::settingIds($portal,'finance_route',$rt), true) || in_array($userId, self::settingIds($portal,'finance_final',$rt), true)) return true;
        }
        return false;
    }

    public static function canAct($portal, $request, $stage, $userId, $isManager = false) {
        if (empty($request) || in_array((string)($request['current_stage'] ?? $stage), ['completed','rejected'], true)) return false;
        if ($isManager) return true;
        $userId = (int)$userId;
        if ($userId <= 0) return false;
        $routeType = self::routeTypeForRequest($request);
        if ($stage === 'creator_rework') return (int)$request['creator_id'] === $userId;
        if ($stage === 'finance_rework') return self::isFinanceController($portal, $userId, false, $routeType);
        $ap = DB::fetchOne("SELECT id FROM approval_request_approvers WHERE request_id=? AND stage=? AND user_id=? AND status='pending' LIMIT 1", [(int)$request['id'],$stage,$userId]);
        if ($ap) return true;
        if ($stage !== 'manager' && in_array($stage, array_keys(self::configurableStages()), true)) return in_array($userId, self::settingIds($portal,$stage,$routeType), true);
        return false;
    }

    public static function get($portal, $id) {
        self::ensureV62Schema();
        return DB::fetchOne("SELECT ar.*, c.name AS category_name, a.name AS account_name FROM approval_requests ar
            LEFT JOIN categories c ON c.id=ar.category_id
            LEFT JOIN accounts a ON a.id=ar.account_id
            WHERE ar.portal=? AND ar.id=? LIMIT 1", [$portal,(int)$id]);
    }

    public static function getLight($portal, $id) {
        self::ensureV62Schema();
        self::ensureV108Schema();
        return DB::fetchOne("SELECT ar.id, ar.portal, ar.creator_id, ar.creator_name, ar.manager_id, ar.manager_name,
                   ar.amount, ar.currency, ar.title, ar.description, ar.category_id, ar.operation_code, ar.operation_type_name,
                   ar.director_ids, ar.director_names, ar.department, ar.sub_department, ar.region,
                   ar.request_type, ar.product_series, ar.commission_import_filename, ar.commission_import_path,
                   ar.commission_rows_count, ar.commission_groups_json, ar.commission_series_json, ar.attachments_json,
                   ar.account_id, ar.status, ar.current_stage, ar.return_stage, ar.final_payment_id, ar.created_at, ar.updated_at,
                   c.name AS category_name, a.name AS account_name
            FROM approval_requests ar
            LEFT JOIN categories c ON c.id=ar.category_id
            LEFT JOIN accounts a ON a.id=ar.account_id
            WHERE ar.portal=? AND ar.id=? LIMIT 1", [$portal,(int)$id]);
    }

    public static function syncCompletedPayments($portal, $limit = 50) {
        try {
            $rows = DB::fetchAll("SELECT id FROM approval_requests WHERE portal=? AND current_stage='completed' AND status='completed' AND (final_payment_id IS NULL OR final_payment_id=0) AND account_id IS NOT NULL AND account_id>0 ORDER BY updated_at DESC LIMIT " . (int)$limit, [$portal]);
            foreach ($rows as $r) {
                self::createPaymentForCompletedRequest($portal, (int)$r['id'], 0, 'Система');
            }
        } catch (Throwable $e) {
            app_log('Approval::syncCompletedPayments failed: ' . $e->getMessage());
        }
    }

    private static function buildListWhere($portal, $mode, $userId, $isManager = false, $filters = [], $canViewAll = false) {
        $where = ["ar.portal=?"];
        $params = [$portal];
        $mode = $mode ?: 'waiting';
        if (!$isManager && !$canViewAll) {
            if ($mode === 'mine') {
                $where[] = "ar.creator_id=?"; $params[] = (int)$userId;
            } elseif ($mode === 'waiting') {
                $where[] = "(EXISTS(SELECT 1 FROM approval_request_approvers aa WHERE aa.request_id=ar.id AND aa.user_id=? AND aa.stage=ar.current_stage AND aa.status='pending') OR (ar.current_stage='creator_rework' AND ar.creator_id=?))";
                $params[] = (int)$userId; $params[] = (int)$userId;
            } else {
                // Обычный заявитель в общем списке видит только свои заявки.
                // Все заявки доступны только по отдельному праву can_view_all_requests или руководителю.
                $where[] = "ar.creator_id=?"; $params[] = (int)$userId;
            }
        } elseif ($mode === 'mine') { $where[] = "ar.creator_id=?"; $params[] = (int)$userId; }
        elseif ($mode === 'waiting') {
            // "Мои согласования" всегда показывает только то, что реально ожидает действия текущего сотрудника.
            // Руководитель/админ смотрит все заявки во вкладке "Все доступные", а не через красный счётчик.
            $where[] = "(EXISTS(SELECT 1 FROM approval_request_approvers aa WHERE aa.request_id=ar.id AND aa.user_id=? AND aa.stage=ar.current_stage AND aa.status='pending') OR (ar.current_stage='creator_rework' AND ar.creator_id=?))";
            $params[] = (int)$userId; $params[] = (int)$userId;
        }
        if (!empty($filters['status'])) { $where[] = "ar.status=?"; $params[] = $filters['status']; }
        if (!empty($filters['q'])) {
            // v108: быстрый поиск по списку заявок. Не ищем по MEDIUMTEXT description,
            // потому что при сотнях тысяч/миллионах строк leading LIKE по описанию блокирует страницу.
            // Подробное описание остаётся доступным в карточке заявки.
            $rawQ = trim((string)$filters['q']);
            $likeQ = '%' . $rawQ . '%';
            if (preg_match('/^#?\d+$/', $rawQ)) {
                $where[] = "(ar.id=? OR ar.title LIKE ? OR ar.creator_name LIKE ? OR ar.manager_name LIKE ? OR ar.operation_code LIKE ? OR ar.operation_type_name LIKE ?)";
                array_push($params, (int)ltrim($rawQ, '#'), $likeQ, $likeQ, $likeQ, $likeQ, $likeQ);
            } else {
                $where[] = "(ar.title LIKE ? OR ar.creator_name LIKE ? OR ar.manager_name LIKE ? OR ar.operation_code LIKE ? OR ar.operation_type_name LIKE ?)";
                array_push($params, $likeQ, $likeQ, $likeQ, $likeQ, $likeQ);
            }
        }
        return [$where, $params];
    }

    public static function templateList($portal, $userId, $isManager = false, $canViewAll = false, $limit = 20) {
        self::ensureV62Schema();
        $limit = max(1, min(50, (int)$limit));
        $userId = (int)$userId;
        if ($userId <= 0) return [];
        return DB::fetchAll("SELECT ar.id, ar.title, ar.amount, ar.status, ar.updated_at, ar.operation_code, ar.operation_type_name, ar.department, ar.sub_department, ar.region
            FROM approval_requests ar
            WHERE ar.portal=? AND ar.request_type='regular' AND ar.creator_id=?
            ORDER BY ar.updated_at DESC, ar.id DESC
            LIMIT " . (int)$limit, [$portal, $userId]);
    }

    public static function count($portal, $mode, $userId, $isManager = false, $filters = [], $canViewAll = false) {
        [$where, $params] = self::buildListWhere($portal, $mode, $userId, $isManager, $filters, $canViewAll);
        $row = DB::fetchOne("SELECT COUNT(*) AS c FROM approval_requests ar WHERE " . implode(' AND ', $where), $params);
        return (int)($row['c'] ?? 0);
    }

    public static function list($portal, $mode, $userId, $isManager = false, $filters = [], $canViewAll = false) {
        // v55: список заявок не должен запускать фоновую синхронизацию платежей при каждом открытии.
        [$where, $params] = self::buildListWhere($portal, $mode, $userId, $isManager, $filters, $canViewAll);
        $limit = isset($filters['limit']) ? max(1, min(1000, (int)$filters['limit'])) : 300;
        $offset = isset($filters['offset']) ? max(0, (int)$filters['offset']) : 0;
        // В списке не тянем MEDIUMTEXT-поля commission_rows_json/attachments_json/description:
        // при сотнях тысяч и миллионах заявок это резко снижает объём памяти и время ответа.
        return DB::fetchAll("SELECT ar.id, ar.title, ar.creator_id, ar.creator_name, ar.manager_id, ar.manager_name,
                   ar.amount, ar.currency, ar.status, ar.current_stage, ar.updated_at, ar.request_type, ar.product_series,
                   ar.operation_code, ar.operation_type_name, ar.department, ar.sub_department, ar.region,
                   c.name AS category_name, a.name AS account_name
            FROM approval_requests ar
            LEFT JOIN categories c ON c.id=ar.category_id
            LEFT JOIN accounts a ON a.id=ar.account_id
            WHERE ".implode(' AND ', $where)." ORDER BY ar.updated_at DESC, ar.id DESC LIMIT ".(int)$limit." OFFSET ".(int)$offset, $params);
    }

    public static function waitingCount($portal, $userId, $isManager = false) {
        // Счётчик уведомлений — это только личные ожидающие действия, а не все незавершённые заявки.
        $r = DB::fetchOne("SELECT COUNT(*) c FROM approval_requests ar WHERE ar.portal=? AND (EXISTS(SELECT 1 FROM approval_request_approvers aa WHERE aa.request_id=ar.id AND aa.user_id=? AND aa.stage=ar.current_stage AND aa.status='pending') OR (ar.current_stage='creator_rework' AND ar.creator_id=?))", [$portal,(int)$userId,(int)$userId]);
        return (int)($r['c'] ?? 0);
    }

    public static function approvers($requestId) { return DB::fetchAll("SELECT * FROM approval_request_approvers WHERE request_id=? ORDER BY id", [(int)$requestId]); }

    private static function plannedRouteStages($portal, $req) {
        // Единая карта маршрута для карточки заявки: показываем полный план заранее
        // для обычного, корпоративного комиссионного и комиссионного маршрута коробочных/прочих продуктов.
        $stages = ['creator', 'manager', 'finance_route', 'department_director'];
        foreach (self::postDirectorSequence($portal, $req) as $stage) $stages[] = $stage;
        return array_values(array_unique(array_filter($stages, static fn($v)=>trim((string)$v) !== '')));
    }

    private static function plannedApproverRowsForStage($portal, $req, $stage) {
        $stage = preg_replace('/[^a-z0-9_]/', '', (string)$stage);
        $ids = [];
        $names = [];
        $routeType = self::routeTypeForRequest($req);
        if ($stage === 'creator') {
            $ids = [(int)($req['creator_id'] ?? 0)];
            $names = [$req['creator_name'] ?? ''];
        } elseif ($stage === 'manager') {
            $ids = [(int)($req['manager_id'] ?? 0)];
            $names = [$req['manager_name'] ?? ''];
        } elseif ($stage === 'department_director') {
            $ids = self::parseIds($req['director_ids'] ?? '');
            $names = self::parseNames($req['director_names'] ?? '');
        } elseif ($stage === 'creator_rework') {
            $ids = [(int)($req['creator_id'] ?? 0)];
            $names = [$req['creator_name'] ?? ''];
        } elseif ($stage === 'finance_rework') {
            $ids = self::settingIds($portal, 'finance_route', $routeType);
            $names = self::settingNames($portal, 'finance_route', $routeType);
        } elseif ($stage === 'finance_final' || self::isConfigurableRouteStage($stage)) {
            $ids = self::settingIds($portal, $stage, $routeType);
            $names = self::settingNames($portal, $stage, $routeType);
        }

        $rows = [];
        $ids = self::parseIds($ids);
        $names = is_array($names) ? $names : self::parseNames($names);
        foreach ($ids as $i=>$id) {
            if ((int)$id <= 0) continue;
            $rows[] = [
                'id' => 0,
                'request_id' => (int)($req['id'] ?? 0),
                'stage' => $stage,
                'user_id' => (int)$id,
                'user_name' => trim((string)($names[$i] ?? '')),
                'status' => ($stage === 'creator' ? 'approved' : 'planned'),
                'decided_by_id' => null,
                'decided_by_name' => '',
                'decided_at' => null,
                'comment' => ($stage === 'creator' ? 'Заявка создана' : ''),
                'is_planned' => 1,
            ];
        }
        if (!$rows && in_array($stage, ['finance_route','department_director','general_director','security','finance_director','treasury'], true)) {
            $rows[] = [
                'id' => 0,
                'request_id' => (int)($req['id'] ?? 0),
                'stage' => $stage,
                'user_id' => 0,
                'user_name' => 'Не настроено',
                'status' => 'planned',
                'decided_by_id' => null,
                'decided_by_name' => '',
                'decided_at' => null,
                'comment' => 'Этап есть в маршруте, но исполнитель не выбран в настройках.',
                'is_planned' => 1,
            ];
        }
        return $rows;
    }

    public static function routeTimeline($portal, $requestId) {
        $requestId = (int)$requestId;
        $req = self::getLight($portal, $requestId);
        $actual = self::approvers($requestId);
        return self::routeTimelineForRequest($portal, $req, $actual);
    }

    public static function routeTimelineForRequest($portal, $req, $actual = null) {
        if (!$req) return is_array($actual) ? $actual : [];
        $requestId = (int)($req['id'] ?? 0);
        $actual = is_array($actual) ? $actual : self::approvers($requestId);

        $byStage = [];
        $stageOrderByFirstId = [];
        foreach ($actual as $row) {
            $stage = (string)($row['stage'] ?? '');
            if ($stage === '') continue;
            $row['is_planned'] = 0;
            $byStage[$stage][] = $row;
            if (!isset($stageOrderByFirstId[$stage])) $stageOrderByFirstId[$stage] = (int)($row['id'] ?? 0);
        }

        $plannedStages = self::plannedRouteStages($portal, $req);
        $out = [];
        $usedStages = [];
        $appendStage = static function($stage, $rows) use (&$out, &$usedStages) {
            $stage = (string)$stage;
            foreach ($rows as $r) $out[] = $r;
            $usedStages[$stage] = true;
        };

        foreach ($plannedStages as $stage) {
            if (isset($byStage[$stage])) $appendStage($stage, $byStage[$stage]);
            else $appendStage($stage, self::plannedApproverRowsForStage($portal, $req, $stage));

            // Дополнительные согласующие, добавленные после конкретного этапа, показываем сразу после базового этапа.
            $extras = [];
            foreach ($byStage as $actualStage=>$rows) {
                if (!empty($usedStages[$actualStage])) continue;
                if (preg_match('/^extra_' . preg_quote($stage, '/') . '_[0-9]+$/', $actualStage)) {
                    $extras[$actualStage] = $rows;
                }
            }
            uksort($extras, static function($a,$b) use ($stageOrderByFirstId) {
                return (int)($stageOrderByFirstId[$a] ?? 0) <=> (int)($stageOrderByFirstId[$b] ?? 0);
            });
            foreach ($extras as $extraStage=>$rows) $appendStage($extraStage, $rows);
        }

        // Всё, что не входит в план (например, доработки или старые нестандартные этапы), не теряем.
        uasort($byStage, static function($a,$b){
            $ai = (int)($a[0]['id'] ?? 0); $bi = (int)($b[0]['id'] ?? 0);
            return $ai <=> $bi;
        });
        foreach ($byStage as $stage=>$rows) {
            if (!empty($usedStages[$stage])) continue;
            foreach ($rows as $r) $out[] = $r;
        }
        return $out;
    }

    public static function historyRows($requestId, $limit = 50, $offset = 0) {
        $limit = max(1, min(200, (int)$limit));
        $offset = max(0, (int)$offset);
        return DB::fetchAll("SELECT * FROM approval_request_history WHERE request_id=? ORDER BY id DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset, [(int)$requestId]);
    }

    public static function historyCount($requestId) {
        $row = DB::fetchOne("SELECT COUNT(*) AS c FROM approval_request_history WHERE request_id=?", [(int)$requestId]);
        return (int)($row['c'] ?? 0);
    }

    private static function isExtraStage($stage) {
        return (bool)preg_match('/^extra_(.+)_([0-9]+)$/', (string)$stage);
    }

    private static function extraBaseStage($stage) {
        return preg_match('/^extra_(.+)_([0-9]+)$/', (string)$stage, $m) ? $m[1] : '';
    }

    private static function nextExtraStageCode($requestId, $afterStage) {
        $afterStage = preg_replace('/[^a-z0-9_]/', '', (string)$afterStage);
        $rows = DB::fetchAll("SELECT stage FROM approval_request_approvers WHERE request_id=? AND stage LIKE ?", [(int)$requestId, 'extra_'.$afterStage.'_%']);
        $max = 0;
        foreach ($rows as $r) if (preg_match('/^extra_'.preg_quote($afterStage,'/').'_([0-9]+)$/', (string)($r['stage'] ?? ''), $m)) $max = max($max, (int)$m[1]);
        return 'extra_' . $afterStage . '_' . ($max + 1);
    }

    private static function pendingExtraStagesAfter($requestId, $afterStage) {
        $afterStage = preg_replace('/[^a-z0-9_]/', '', (string)$afterStage);
        if ($afterStage === '') return [];
        $rows = DB::fetchAll("SELECT stage, MIN(id) AS min_id FROM approval_request_approvers WHERE request_id=? AND stage LIKE ? AND status='pending' GROUP BY stage ORDER BY min_id ASC", [(int)$requestId, 'extra_'.$afterStage.'_%']);
        return array_values(array_map(static fn($r)=>(string)$r['stage'], $rows));
    }

    private static function addApproversForStage($portal, $requestId, $stage, $req = null) {
        if (!$req) $req = self::get($portal, $requestId) ?: [];
        $routeType = self::routeTypeForRequest($req);
        if ($stage === 'manager') self::addApprovers($requestId, 'manager', [(int)($req['manager_id'] ?? 0)], [$req['manager_name'] ?? '']);
        elseif ($stage === 'department_director') self::addApprovers($requestId,'department_director',self::parseIds($req['director_ids'] ?? ''),self::parseNames($req['director_names'] ?? ''));
        elseif ($stage === 'finance_rework') self::addApprovers($requestId,'finance_rework', self::settingIds($portal,'finance_route',$routeType), self::settingNames($portal,'finance_route',$routeType));
        elseif ($stage === 'creator_rework') self::addApprovers($requestId,'creator_rework', [(int)($req['creator_id'] ?? 0)], [$req['creator_name'] ?? '']);
        elseif ($stage === 'finance_final' || self::isConfigurableRouteStage($stage)) self::addStageApproversFromSettings($portal, $requestId, $stage, $routeType);
    }

    private static function postDirectorSequence($portal, $req) {
        $routeType = self::routeTypeForRequest($req);
        $routes = self::getRouteSettingsByType($portal, $routeType);
        $amount = (float)($req['amount'] ?? 0);
        $threshold = (float)($routes['finance_director']['threshold_amount'] ?? 15000);
        if ($threshold <= 0) $threshold = 15000;
        $seq = [];
        foreach ($routes as $stage=>$r) {
            if ($stage === 'finance_route') continue;
            if (!self::isConfigurableRouteStage($stage)) continue;
            if (isset($r['is_enabled']) && (int)$r['is_enabled'] === 0) continue;
            if (self::isCustomRouteStage($stage) && empty(self::parseIds($r['approver_ids'] ?? ''))) continue;
            if ($stage === 'finance_director' && $amount <= $threshold) continue;
            $seq[] = $stage;
        }
        if (!in_array('treasury', $seq, true)) $seq[] = 'treasury';
        return array_values(array_unique($seq));
    }

    private static function baseNextStage($portal, $fromStage, $req) {
        if (self::isExtraStage($fromStage)) $fromStage = self::extraBaseStage($fromStage);
        if ($fromStage === 'manager') return 'finance_route';
        if ($fromStage === 'finance_route') return 'department_director';
        if ($fromStage === 'finance_final') return self::thresholdNextStage($portal, (float)($req['amount'] ?? 0), self::routeTypeForRequest($req));
        $seq = self::postDirectorSequence($portal, $req);
        if ($fromStage === 'department_director') return $seq[0] ?? 'completed';
        $idx = array_search($fromStage, $seq, true);
        if ($idx !== false) return $seq[$idx + 1] ?? 'completed';
        if ($fromStage === 'treasury') return 'completed';
        return 'completed';
    }

    private static function completeRequest($portal, $requestId, $fromStage, $userId, $userName, $comment = '') {
        self::setStage($requestId,'completed','completed');
        self::history($requestId,'completed',$fromStage,'completed',$userId,$userName,$comment ?: 'Заявка полностью согласована');
        self::createPaymentForCompletedRequest($portal, $requestId, $userId, $userName);
        self::notifyFinal($portal,$requestId,'Ваша заявка согласована');
    }

    private static function routeTo($portal, $requestId, $fromStage, $toStage, $userId, $userName, $comment = '', $returnStage = null) {
        if ($toStage === 'completed' || $toStage === '') { self::completeRequest($portal, $requestId, $fromStage, $userId, $userName, $comment); return; }
        self::setStage($requestId, $toStage, 'waiting_'.$toStage, $returnStage);
        self::addApproversForStage($portal, $requestId, $toStage);
        self::history($requestId,'move',$fromStage,$toStage,$userId,$userName,$comment);
        self::notifyStage($portal, $requestId, $toStage);
    }

    private static function advanceAfter($portal, $requestId, $afterStage, $userId, $userName, $comment = '') {
        $extraStages = self::pendingExtraStagesAfter($requestId, $afterStage);
        if ($extraStages) {
            $toStage = $extraStages[0];
            self::setStage($requestId, $toStage, 'waiting_'.$toStage);
            self::history($requestId,'move',$afterStage,$toStage,$userId,$userName,$comment ?: 'Передано на дополнительное согласование');
            self::notifyStage($portal, $requestId, $toStage);
            return;
        }
        $freshReq = self::get($portal, $requestId) ?: [];
        $next = self::baseNextStage($portal, $afterStage, $freshReq);
        self::routeTo($portal, $requestId, $afterStage, $next, $userId, $userName, $comment);
    }

    public static function addExtraApprovers($portal, $requestId, $afterStage, $ids, $names, $userId, $userName, $comment = '') {
        $requestId = (int)$requestId;
        $afterStage = preg_replace('/[^a-z0-9_]/', '', (string)$afterStage);
        if (!$requestId || $afterStage === '' || in_array($afterStage, ['completed','rejected','creator_rework','finance_rework'], true)) return ['error'=>'Нельзя добавить доп. согласующих к этому этапу'];
        $ids = self::parseIds($ids);
        $names = self::parseNames($names);
        if (!$ids) return ['error'=>'Выберите дополнительных согласующих'];
        $stage = self::nextExtraStageCode($requestId, $afterStage);
        self::addApprovers($requestId, $stage, $ids, $names);
        $label = 'После этапа: ' . self::stageLabelFor($portal, $afterStage) . '. Доп. согласующие: ' . self::usersToText($ids, $names);
        if (trim((string)$comment) !== '') $label .= "
Комментарий: " . trim((string)$comment);
        self::history($requestId,'extra_added',$afterStage,$stage,(int)$userId,$userName,$label);
        return ['ok'=>true, 'redirect'=>false, 'message'=>'Дополнительные согласующие добавлены в маршрут'];
    }

    private static function approveCurrentPending($requestId, $stage, $userId, $userName, $comment = '') {
        $n = DB::execute("UPDATE approval_request_approvers SET status='approved', decided_by_id=?, decided_by_name=?, decided_at=?, comment=? WHERE request_id=? AND stage=? AND user_id=? AND status='pending'", [(int)$userId,$userName,self::nowSql(),$comment,(int)$requestId,$stage,(int)$userId]);
        if ($n === 0) DB::execute("UPDATE approval_request_approvers SET status='skipped', decided_by_id=?, decided_by_name=?, decided_at=?, comment='Согласовано другим участником этапа' WHERE request_id=? AND stage=? AND status='pending'", [(int)$userId,$userName,self::nowSql(),(int)$requestId,$stage]);
        else DB::execute("UPDATE approval_request_approvers SET status='skipped', decided_by_id=?, decided_by_name=?, decided_at=?, comment='Согласовано другим участником этапа' WHERE request_id=? AND stage=? AND status='pending' AND user_id<>?", [(int)$userId,$userName,self::nowSql(),(int)$requestId,$stage,(int)$userId]);
    }

    private static function addStageApproversFromSettings($portal, $requestId, $stage, $routeType = 'commission') { self::addApprovers($requestId, $stage, self::settingIds($portal,$stage,$routeType), self::settingNames($portal,$stage,$routeType)); }

    private static function isSpecialInsuranceCommission($req) {
        $series = self::extractCommissionSeries($req);
        if ($series) {
            foreach ($series as $s) {
                if (!in_array($s, self::CORPORATE_COMMISSION_PRODUCTS, true)) return false;
            }
            return true;
        }
        $hay = strtoupper(implode(' ', [
            $req['category_name'] ?? '',
            $req['operation_type_name'] ?? '',
            $req['operation_code'] ?? '',
            $req['title'] ?? '',
            $req['description'] ?? '',
        ]));
        return (bool)preg_match('/(^|[^A-Z0-9])(CI|CP|CE|CT|MI|GA|FI)([^A-Z0-9]|$)/', $hay);
    }

    private static function thresholdNextStage($portal, $amount, $routeType = 'commission') {
        $routes = self::getRouteSettingsByType($portal, $routeType);
        $threshold = (float)($routes['finance_director']['threshold_amount'] ?? 15000);
        if ($threshold <= 0) $threshold = 15000;
        return ((float)$amount > $threshold) ? 'finance_director' : 'treasury';
    }

    private static function normalizeCommissionGroupsFromPost($data, $oldReq = [], $portal = '') {
        if (($oldReq['request_type'] ?? '') !== 'commission') return [];
        if (!array_key_exists('commission_group_amount', $data)) return [];
        $amounts = (array)($data['commission_group_amount'] ?? []);
        $allocs = (array)($data['commission_group_allocation'] ?? []);
        $seriesArr = (array)($data['commission_group_series'] ?? []);
        $codes = (array)($data['commission_group_code'] ?? []);
        $names = (array)($data['commission_group_name'] ?? []);
        $catIds = (array)($data['commission_group_category_id'] ?? []);
        [$oldGroups, $oldParsed] = self::decodeCommissionGroupsOnly($oldReq);
        $groups = [];
        $seriesSet = [];
        $total = 0.0;
        $max = max(count($amounts), count($allocs), count($seriesArr), count($codes), count($names));
        for ($i=0; $i<$max; $i++) {
            $old = is_array($oldGroups[$i] ?? null) ? $oldGroups[$i] : [];
            $allocation = trim((string)($allocs[$i] ?? ($old['allocation'] ?? '')));
            $series = strtoupper(preg_replace('/\s+/u', '', (string)($seriesArr[$i] ?? ($old['series'] ?? ''))));
            $amount = self::moneyToFloat($amounts[$i] ?? ($old['amount'] ?? 0));
            $code = trim((string)($codes[$i] ?? ($old['operation_code'] ?? '')));
            $name = trim((string)($names[$i] ?? ($old['operation_type_name'] ?? '')));
            $catId = !empty($catIds[$i]) ? (int)$catIds[$i] : (!empty($old['category_id']) ? (int)$old['category_id'] : 0);
            if ($series === '' && $allocation === '' && $amount <= 0 && $code === '' && $name === '') continue;
            if ($series !== '' && ($code === '' || $name === '' || $catId <= 0) && $portal !== '') {
                [$foundCatId, $foundCode, $foundName] = self::findCommissionCategory($portal, $series);
                if ($catId <= 0) $catId = (int)$foundCatId;
                if ($code === '') $code = (string)$foundCode;
                if ($name === '') $name = (string)$foundName;
            }
            if ($name === '') $name = 'Комиссии - страхования' . ($series !== '' ? ' - ' . $series : '');
            $allocation = $allocation !== '' ? $allocation : 'Без аллокации';
            $groups[] = [
                'allocation'=>$allocation,
                'series'=>$series,
                'amount'=>round(max(0, $amount), 2),
                'count'=>(int)($old['count'] ?? 0),
                'category_id'=>$catId ?: null,
                'operation_code'=>$code,
                'operation_type_name'=>$name,
                'category_name'=>trim($code . ' - ' . $name),
            ];
            if ($series !== '') $seriesSet[$series] = true;
            $total += max(0, $amount);
        }
        if (!$groups) return [];
        $oldParsed['groups'] = $groups;
        $oldParsed['total'] = round($total, 2);
        $oldParsed['series'] = array_values(array_keys($seriesSet));
        $oldParsed['series_label'] = implode(', ', array_keys($seriesSet));
        $oldParsed['primary_series'] = array_key_first($seriesSet) ?: '';
        $rowsCount = 0;
        foreach ($groups as $g) $rowsCount += (int)($g['count'] ?? 0);
        $fullJson = json_encode($oldParsed, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return [
            'commission_rows_json' => $fullJson,
            'commission_groups_json' => self::commissionGroupsPayload($oldParsed),
            'commission_series_json' => self::commissionSeriesPayload($oldParsed),
            'commission_rows_count' => $rowsCount ?: (int)($oldReq['commission_rows_count'] ?? 0),
            'amount' => round($total, 2),
            'product_series' => mb_substr((string)($oldParsed['series_label'] ?? ''), 0, 50),
        ];
    }

    private static function normalizeUpdateData($data, $oldReq = [], $portal = '') {
        $out = [];
        if (array_key_exists('amount', $data)) $out['amount'] = max(0, (float)$data['amount']);
        $out['currency'] = 'TJS';
        if (array_key_exists('title', $data)) $out['title'] = trim((string)$data['title']) ?: null;
        if (array_key_exists('description', $data)) $out['description'] = trim((string)$data['description']);
        if (array_key_exists('manager_id', $data)) $out['manager_id'] = (int)$data['manager_id'];
        if (array_key_exists('manager_name', $data)) $out['manager_name'] = trim((string)$data['manager_name']);
        if (array_key_exists('category_id', $data)) $out['category_id'] = (int)$data['category_id'] ?: null;
        if (array_key_exists('account_id', $data)) $out['account_id'] = (int)$data['account_id'] ?: null;
        if (array_key_exists('department', $data)) $out['department'] = trim((string)$data['department']) ?: null;
        if (array_key_exists('sub_department', $data)) $out['sub_department'] = trim((string)$data['sub_department']) ?: null;
        $__region = array_key_exists('region', $data) ? trim((string)$data['region']) : null;
        if (array_key_exists('region', $data)) $out['region'] = ($__region === '') ? null : $__region;
        if (array_key_exists('operation_code', $data)) $out['operation_code'] = trim((string)$data['operation_code']) ?: null;
        if (array_key_exists('operation_type_name', $data)) $out['operation_type_name'] = trim((string)$data['operation_type_name']) ?: null;
        if (array_key_exists('product_series', $data)) $out['product_series'] = strtoupper(preg_replace('/[^A-ZА-Я0-9]+/ui', '', (string)$data['product_series'])) ?: null;
        if (array_key_exists('director_ids', $data)) $out['director_ids'] = implode(',', self::parseIds($data['director_ids']));
        if (array_key_exists('director_names', $data)) $out['director_names'] = implode("\n", self::parseNames($data['director_names']));
        $commissionFields = self::normalizeCommissionGroupsFromPost($data, $oldReq, $portal);
        if ($commissionFields) $out = array_merge($out, $commissionFields);
        return $out;
    }

    private static function updateRequestFields($id, $fields) {
        if (!$fields) return;
        $sets = []; $vals = [];
        foreach ($fields as $k=>$v) { $sets[] = "`{$k}`=?"; $vals[] = $v; }
        $sets[] = "updated_at=?"; $vals[] = self::nowSql(); $vals[] = (int)$id;
        DB::execute("UPDATE approval_requests SET ".implode(',', $sets)." WHERE id=?", $vals);
    }



    private static function commissionPartnerLink($allocation) {
        $a = mb_strtoupper(trim((string)$allocation));
        $a = str_replace(['Ё'], ['Е'], $a);
        if ($a === '') return '';
        if (mb_stripos($a, 'ХАТЛОН') !== false) return 'ХАТЛОН (БИМА) 107';
        if (mb_stripos($a, 'СОГД') !== false) return 'СОГД (БИМА) 270';
        if (mb_stripos($a, 'БИМА ГО') !== false || mb_stripos($a, 'BIMA GO') !== false || mb_stripos($a, ' ГО') !== false) return 'БИМА ОНЛАЙН 61';
        return trim((string)$allocation);
    }

    private static function createPaymentForCompletedRequest($portal, $requestId, $userId, $userName) {
        try {
            $req = self::get($portal, $requestId);
            if (!$req) return 0;
            if (empty($req['account_id'])) {
                self::history($requestId, 'payment_error', 'treasury', 'completed', $userId, $userName, 'Платёж не создан: не выбрана касса/счёт.');
                return 0;
            }
            if (!empty($req['final_payment_id'])) return (int)$req['final_payment_id'];

            if (($req['request_type'] ?? '') === 'commission') {
                $parsed = [];
                if (!empty($req['commission_rows_json'])) {
                    $tmp = json_decode((string)$req['commission_rows_json'], true);
                    if (is_array($tmp)) $parsed = $tmp;
                }
                $groups = is_array($parsed['groups'] ?? null) ? $parsed['groups'] : [];
                $items = is_array($parsed['items'] ?? null) ? $parsed['items'] : [];
                if (!$groups) {
                    $groups = [[
                        'allocation' => $req['department'] ?: 'Комиссионная заявка',
                        'series' => $req['product_series'] ?: '',
                        'amount' => (float)$req['amount'],
                        'count' => (int)($req['commission_rows_count'] ?? 0),
                    ]];
                }

                // Группы нужны для выбора статьи/кода и для корректировок финансиста.
                $groupMap = [];
                foreach ($groups as $g) {
                    $series = strtoupper(trim((string)($g['series'] ?? ($req['product_series'] ?? ''))));
                    $alloc = trim((string)($g['allocation'] ?? '')) ?: 'Без аллокации';
                    $key = self::commissionGroupKey($alloc, $series);
                    $opCode = trim((string)($g['operation_code'] ?? ''));
                    $opName = trim((string)($g['operation_type_name'] ?? ''));
                    $catId = !empty($g['category_id']) ? (int)$g['category_id'] : 0;
                    if ($catId <= 0 || $opCode === '' || $opName === '') {
                        [$foundCatId, $foundCode, $foundName] = self::findCommissionCategory($portal, $series);
                        if ($catId <= 0) $catId = (int)$foundCatId;
                        if ($opCode === '') $opCode = (string)$foundCode;
                        if ($opName === '') $opName = (string)$foundName;
                    }
                    if ($opName === '') $opName = 'Комиссии - страхования' . ($series ? ' - ' . $series : '');
                    $groupMap[$key] = [
                        'allocation'=>$alloc,
                        'series'=>$series,
                        'amount'=>round((float)($g['amount'] ?? 0), 2),
                        'category_id'=>$catId,
                        'operation_code'=>$opCode,
                        'operation_type_name'=>$opName,
                    ];
                }

                $createdIds = [];
                $creatorName = trim((string)($req['creator_name'] ?? ''));

                if ($items) {
                    // v80: для отчёта создаём отдельную операцию по каждому полису,
                    // чтобы в столбце "Описание" был виден номер полиса.
                    $itemsByKey = [];
                    foreach ($items as $it) {
                        $series = strtoupper(trim((string)($it['series'] ?? '')));
                        $alloc = trim((string)($it['allocation'] ?? '')) ?: 'Без аллокации';
                        $amt = round((float)($it['premium'] ?? 0), 2);
                        if ($series === '' || $amt <= 0) continue;
                        $key = self::commissionGroupKey($alloc, $series);
                        if (!isset($itemsByKey[$key])) $itemsByKey[$key] = [];
                        $itemsByKey[$key][] = $it;
                    }

                    foreach ($itemsByKey as $key=>$bucket) {
                        $g = $groupMap[$key] ?? null;
                        if (!$g) {
                            [$alloc, $series] = array_pad(explode('|', $key, 2), 2, '');
                            [$catId, $opCode, $opName] = self::findCommissionCategory($portal, $series);
                            $g = ['allocation'=>$alloc,'series'=>$series,'amount'=>array_sum(array_map(static fn($x)=>(float)($x['premium'] ?? 0), $bucket)),'category_id'=>$catId,'operation_code'=>$opCode,'operation_type_name'=>$opName ?: ('Комиссии - страхования'.($series ? ' - '.$series : ''))];
                        }
                        $groupTarget = round((float)($g['amount'] ?? 0), 2);
                        if ($groupTarget <= 0) continue;
                        $sourceSum = round(array_sum(array_map(static fn($x)=>(float)($x['premium'] ?? 0), $bucket)), 2);
                        $ratio = $sourceSum > 0 ? ($groupTarget / $sourceSum) : 1;
                        $running = 0.0;
                        $lastIndex = count($bucket) - 1;
                        foreach ($bucket as $idx=>$it) {
                            $series = strtoupper(trim((string)($it['series'] ?? $g['series'] ?? '')));
                            $alloc = trim((string)($it['allocation'] ?? $g['allocation'] ?? '')) ?: 'Без аллокации';
                            $policy = trim((string)($it['policy'] ?? ''));
                            $baseAmt = round((float)($it['premium'] ?? 0), 2);
                            $amt = ($idx === $lastIndex) ? round($groupTarget - $running, 2) : round($baseAmt * $ratio, 2);
                            $running += $amt;
                            if ($amt <= 0) continue;
                            $opCode = trim((string)($g['operation_code'] ?? ''));
                            $opName = trim((string)($g['operation_type_name'] ?? ''));
                            $catId = !empty($g['category_id']) ? (int)$g['category_id'] : 0;
                            if ($opName === '') $opName = 'Комиссии - страхования' . ($series ? ' - ' . $series : '');
                            $desc = $opName . ' ' . ($policy !== '' ? $policy : 'не указан') . ' / ' . $alloc . ' (заявка ' . $requestId . ')';
                            $paymentData = [
                                'type' => 'expense',
                                'operation_type' => 'expense',
                                'date' => function_exists('app_today_sql') ? app_today_sql() : date('Y-m-d'),
                                'amount' => $amt,
                                'amount_tjs' => $amt,
                                'account_id' => (int)$req['account_id'],
                                'category_id' => $catId > 0 ? $catId : (!empty($req['category_id']) ? (int)$req['category_id'] : null),
                                'currency' => 'TJS',
                                'exchange_rate' => 1,
                                'exchange_rate_date' => function_exists('app_today_sql') ? app_today_sql() : date('Y-m-d'),
                                'status' => 'approved',
                                'description' => $desc,
                                'transaction_link' => self::commissionPartnerLink($alloc),
                                'comment' => 'Автоматически создано после согласования комиссионной заявки ' . $requestId . '. ' . $desc,
                                'source' => 'approval',
                                'operation_code' => $opCode,
                                'operation_type_name' => $opName,
                                'recipient_name' => $req['creator_name'] ?? null,
                                'company' => $req['creator_name'] ?? null,
                                'department' => $req['department'] ?? null,
                                'sub_department' => $req['sub_department'] ?? null,
                                'region' => self::commissionAllocationRegion($alloc) ?: ($req['region'] ?? null),
                            ];
                            $pid = Payment::add($portal, $paymentData, null);
                            if (is_array($pid) && isset($pid['error'])) {
                                self::history($requestId, 'payment_error', 'treasury', 'completed', $userId, $userName, 'Платёж не создан: ' . $pid['error']);
                                continue;
                            }
                            if ($pid) $createdIds[] = (int)$pid;
                        }
                    }
                } else {
                    // Fallback: если детальных полисов нет, оставляем старую групповую выгрузку.
                    foreach ($groups as $g) {
                        $series = strtoupper(trim((string)($g['series'] ?? ($req['product_series'] ?? ''))));
                        $alloc = trim((string)($g['allocation'] ?? '')) ?: 'Без аллокации';
                        $amt = (float)($g['amount'] ?? 0);
                        if ($amt <= 0) continue;
                        $opCode = trim((string)($g['operation_code'] ?? ''));
                        $opName = trim((string)($g['operation_type_name'] ?? ''));
                        $catId = !empty($g['category_id']) ? (int)$g['category_id'] : 0;
                        if ($catId <= 0 || $opCode === '' || $opName === '') {
                            [$foundCatId, $foundCode, $foundName] = self::findCommissionCategory($portal, $series);
                            if ($catId <= 0) $catId = (int)$foundCatId;
                            if ($opCode === '') $opCode = (string)$foundCode;
                            if ($opName === '') $opName = (string)$foundName;
                        }
                        if ($opName === '') $opName = 'Комиссии - страхования' . ($series ? ' - ' . $series : '');
                        $desc = $opName . ' / ' . $alloc . ' (заявка ' . $requestId . ')';
                        $paymentData = [
                            'type' => 'expense',
                            'operation_type' => 'expense',
                            'date' => function_exists('app_today_sql') ? app_today_sql() : date('Y-m-d'),
                            'amount' => $amt,
                            'amount_tjs' => $amt,
                            'account_id' => (int)$req['account_id'],
                            'category_id' => $catId > 0 ? $catId : (!empty($req['category_id']) ? (int)$req['category_id'] : null),
                            'currency' => 'TJS',
                            'exchange_rate' => 1,
                            'exchange_rate_date' => function_exists('app_today_sql') ? app_today_sql() : date('Y-m-d'),
                            'status' => 'approved',
                            'description' => $desc,
                            'transaction_link' => self::commissionPartnerLink($alloc),
                            'comment' => 'Автоматически создано после согласования комиссионной заявки ' . $requestId . '. ' . $desc,
                            'source' => 'approval',
                            'operation_code' => $opCode,
                            'operation_type_name' => $opName,
                            'recipient_name' => $req['creator_name'] ?? null,
                            'company' => $req['creator_name'] ?? null,
                            'department' => $req['department'] ?? null,
                            'sub_department' => $req['sub_department'] ?? null,
                            'region' => self::commissionAllocationRegion($alloc) ?: ($req['region'] ?? null),
                        ];
                        $pid = Payment::add($portal, $paymentData, null);
                        if (is_array($pid) && isset($pid['error'])) {
                            self::history($requestId, 'payment_error', 'treasury', 'completed', $userId, $userName, 'Платёж не создан: ' . $pid['error']);
                            continue;
                        }
                        if ($pid) $createdIds[] = (int)$pid;
                    }
                }

                if ($createdIds) {
                    DB::execute("UPDATE approval_requests SET final_payment_id=? WHERE id=?", [$createdIds[0], $requestId]);
                    self::history($requestId, 'payment_created', 'treasury', 'completed', $userId, $userName, 'Создано операций по комиссионной заявке: ' . count($createdIds) . '. Общая сумма: ' . number_format((float)$req['amount'],2,'.',' ') . ' TJS');
                    return (int)$createdIds[0];
                }
                self::history($requestId, 'payment_error', 'treasury', 'completed', $userId, $userName, 'Платёж не создан: нет данных комиссионного импорта с суммой больше 0.');
                return 0;
            }
            $paymentData = [
                'type' => 'expense',
                'operation_type' => 'expense',
                'date' => function_exists('app_today_sql') ? app_today_sql() : date('Y-m-d'),
                'amount' => (float)$req['amount'],
                'amount_tjs' => (float)$req['amount'],
                'account_id' => (int)$req['account_id'],
                'category_id' => !empty($req['category_id']) ? (int)$req['category_id'] : null,
                'currency' => 'TJS',
                'exchange_rate' => 1,
                'exchange_rate_date' => function_exists('app_today_sql') ? app_today_sql() : date('Y-m-d'),
                'status' => 'approved',
                'description' => trim((string)($req['title'] ?? '')) !== '' ? ((string)$req['title'] . ' (заявки)') : ('Заявка #' . $requestId . ' (заявки)'),
                // В отчёте столбец "Ссылка" используется для отображения полного описания заявки.
                'transaction_link' => (string)($req['description'] ?? ''),
                'comment' => 'Автоматически создано после согласования заявки #' . $requestId . '. Описание: ' . (string)$req['description'],
                'source' => 'approval',
                'operation_code' => $req['operation_code'] ?? null,
                'operation_type_name' => $req['operation_type_name'] ?? ($req['category_name'] ?? null),
                'recipient_name' => $req['creator_name'] ?? null,
                'company' => $req['creator_name'] ?? null,
                'department' => $req['department'] ?? null,
                'sub_department' => $req['sub_department'] ?? null,
                'region' => $req['region'] ?? null,
            ];
            $pid = Payment::add($portal, $paymentData, null);
            if (is_array($pid) && isset($pid['error'])) {
                self::history($requestId, 'payment_error', 'treasury', 'completed', $userId, $userName, 'Платёж не создан: ' . $pid['error']);
                return 0;
            }
            if ($pid) {
                DB::execute("UPDATE approval_requests SET final_payment_id=? WHERE id=?", [(int)$pid, $requestId]);
                self::history($requestId, 'payment_created', 'treasury', 'completed', $userId, $userName, 'Создана расходная операция #' . (int)$pid . ' на сумму ' . number_format((float)$req['amount'],2,'.',' ') . ' TJS');
                return (int)$pid;
            }
        } catch (Throwable $e) {
            app_log('Approval::createPaymentForCompletedRequest failed: '.$e->getMessage());
            self::history($requestId, 'payment_error', 'treasury', 'completed', $userId, $userName, 'Ошибка создания платежа: ' . $e->getMessage());
        }
        return 0;
    }

    public static function action($portal, $id, $action, $data, $user, $isManager = false) {
        $id = (int)$id;
        $req = self::get($portal, $id);
        if (!$req) return ['error'=>'Заявка не найдена'];
        $stage = $req['current_stage'];
        $userId = (int)($user['ID'] ?? 0);
        $userName = self::userDisplayName($portal, $userId, self::userName($user));
        $comment = trim((string)($data['comment'] ?? ''));
        $action = preg_replace('/[^a-z0-9_]/', '', (string)$action);
        if ($action === 'delete') {
            if (!$isManager && !AccessControl::hasFlag($portal, $userId, $user['WORK_POSITION'] ?? '', 'can_delete_request', false, $user)) return ['error'=>'Нет права удалять заявки'];
            DB::execute("DELETE FROM approval_request_approvers WHERE request_id=?", [$id]);
            DB::execute("DELETE FROM approval_request_history WHERE request_id=?", [$id]);
            DB::execute("DELETE FROM approval_requests WHERE id=? AND portal=?", [$id,$portal]);
            return ['ok'=>true, 'redirect'=>true];
        }
        $canRouteMaintenance = in_array($action, ['reassign','add_extra'], true)
            && AccessControl::hasFlag($portal, $userId, $user['WORK_POSITION'] ?? '', 'can_change_request_route', false, $user);
        if (!$canRouteMaintenance && !self::canAct($portal, $req, $stage, $userId, $isManager)) return ['error'=>'Нет права на действие по текущему этапу'];
        if (in_array($stage, ['completed','rejected'], true)) return ['error'=>'Заявка уже завершена'];

        return DB::transaction(function() use ($portal,$id,$req,$stage,$userId,$userName,$comment,$action,$data,$user,$isManager) {
            if ($action === 'reject') {
                return ['error'=>'Отклонение заявки отключено. Используйте возврат на доработку.'];
            }
            if ($action === 'rework') {
                $target = preg_replace('/[^a-z0-9_]/','',(string)($data['target'] ?? 'finance'));
                if ($stage === 'manager') $target = 'creator';
                self::approveCurrentPending($id,$stage,$userId,$userName,$comment);
                if ($target === 'creator' || $stage === 'finance_route') {
                    self::setStage($id,'creator_rework','rework_creator',$stage);
                    self::addApprovers($id,'creator_rework', [(int)$req['creator_id']], [$req['creator_name'] ?? ('User #'.(int)$req['creator_id'])]);
                    self::history($id,'rework',$stage,'creator_rework',$userId,$userName,$comment);
                    self::notifyStage($portal,$id,'creator_rework');
                } else {
                    self::setStage($id,'finance_rework','rework_finance',$stage);
                    $routeType = self::routeTypeForRequest($req);
                    self::addApprovers($id,'finance_rework', self::settingIds($portal,'finance_route',$routeType), self::settingNames($portal,'finance_route',$routeType));
                    self::history($id,'rework',$stage,'finance_rework',$userId,$userName,$comment);
                    self::notifyStage($portal,$id,'finance_rework');
                }
                return ['ok'=>true, 'redirect'=>false, 'message'=>'Заявка успешно отправлена на доработку'];
            }
            if ($action === 'resubmit') {
                $old = self::get($portal,$id);
                $fields = self::normalizeUpdateData($data, $old, $portal);
                if ($stage === 'creator_rework') {
                    if (empty($fields['description'])) unset($fields['description']);
                    self::approveCurrentPending($id,$stage,$userId,$userName,$comment ?: 'Доработка выполнена');
                    self::updateRequestFields($id,$fields);
                    $new = self::get($portal,$id);
                    $diff = self::diffText($old,$new,['amount'=>'amount','currency'=>'currency','description'=>'description','manager_name'=>'manager_name','category_name'=>'category_name','operation_code'=>'operation_code','operation_type_name'=>'operation_type_name','department'=>'department','sub_department'=>'sub_department','region'=>'region','director_names'=>'director_names']);
                    // Возвращаем заявку на этап, который отправил её на доработку.
                    // Уже согласованные ранние этапы не сбрасываются повторно.
                    $returnStage = $new['return_stage'] ?: 'manager';
                    self::setStage($id,$returnStage,'waiting_'.$returnStage);
                    if ($returnStage === 'manager') {
                        self::addApprovers($id, 'manager', [(int)$new['manager_id']], [$new['manager_name']]);
                    } elseif ($returnStage === 'department_director') {
                        self::addApprovers($id,'department_director',self::parseIds($new['director_ids']),self::parseNames($new['director_names']));
                    } else {
                        self::addStageApproversFromSettings($portal,$id,$returnStage,self::routeTypeForRequest($new));
                    }
                    self::history($id,'resubmit',$stage,$returnStage,$userId,$userName,trim($comment."\n".$diff));
                    self::notifyStage($portal,$id,$returnStage);
                    return ['ok'=>true, 'redirect'=>false, 'message'=>'Заявка успешно возвращена на согласование'];
                }
                if ($stage === 'finance_rework') {
                    self::approveCurrentPending($id,$stage,$userId,$userName,$comment ?: 'Доработка выполнена');
                    self::updateRequestFields($id,$fields);
                    $new = self::get($portal,$id);
                    $returnStage = $new['return_stage'] ?: 'department_director';
                    $diff = self::diffText($old,$new,['category_name'=>'category_name','operation_code'=>'operation_code','operation_type_name'=>'operation_type_name','account_name'=>'account_name','department'=>'department','sub_department'=>'sub_department','region'=>'region','director_names'=>'director_names']);
                    self::setStage($id,$returnStage,'waiting_'.$returnStage);
                    if ($returnStage === 'department_director') self::addApprovers($id,'department_director',self::parseIds($new['director_ids']),self::parseNames($new['director_names']));
                    else self::addStageApproversFromSettings($portal,$id,$returnStage,self::routeTypeForRequest($new));
                    self::history($id,'resubmit',$stage,$returnStage,$userId,$userName,trim($comment."\n".$diff));
                    self::notifyStage($portal,$id,$returnStage);
                    return ['ok'=>true, 'redirect'=>false, 'message'=>'Заявка успешно возвращена на согласование'];
                }
                return ['error'=>'Этот этап не находится на доработке'];
            }
            if ($action === 'route' && $stage === 'finance_route') {
                $directorIds = self::parseIds($data['director_ids'] ?? '');
                $directorNames = self::parseNames($data['director_names'] ?? '');
                if (empty($directorIds)) return ['error'=>'Выберите директора департамента'];
                if (($req['request_type'] ?? '') !== 'commission' && empty($data['category_id']) && trim((string)($data['operation_code'] ?? '')) === '') return ['error'=>'Выберите статью или укажите код статьи'];
                if (empty($data['account_id'])) return ['error'=>'Выберите кассу/счёт для списания после согласования'];
                if (trim((string)($data['department'] ?? '')) === '') return ['error'=>'Выберите отдел заявителя'];
                if (trim((string)($data['sub_department'] ?? '')) === '') return ['error'=>'Выберите подотдел заявителя'];
                // ВАЖНО: регион может быть значением '0'. В PHP '0' часто ошибочно воспринимается как пустое значение.
                // Берём значение строго из POST, а если форма была сохранена ранее — из заявки.
                $__regionCheck = array_key_exists('region', $data)
                    ? trim((string)$data['region'])
                    : trim((string)($req['region'] ?? ''));
                if ($__regionCheck === '' || !in_array($__regionCheck, ['0','1','2'], true)) {
                    return ['error'=>'Выберите регион 0, 1 или 2'];
                }
                $data['region'] = $__regionCheck;
                $fields = self::normalizeUpdateData($data, $req, $portal);
                $fields['director_ids'] = implode(',',$directorIds);
                $fields['director_names'] = implode("\n",$directorNames);
                self::updateRequestFields($id,$fields);
                self::approveCurrentPending($id,$stage,$userId,$userName,$comment);
                self::history($id,'route',$stage,$stage,$userId,$userName,$comment ?: 'Маршрут выбран финансистом');
                self::advanceAfter($portal,$id,$stage,$userId,$userName,$comment ?: 'Маршрут выбран финансистом');
                return ['ok'=>true, 'redirect'=>false, 'message'=>'Маршрут выбран и заявка отправлена дальше'];
            }
            if ($action === 'add_extra') {
                $canChangeRoute = AccessControl::hasFlag($portal, $userId, $user['WORK_POSITION'] ?? '', 'can_change_request_route', false, $user);
                if (!$canChangeRoute) return ['error'=>'Нет права добавлять дополнительных согласующих'];
                $afterStage = preg_replace('/[^a-z0-9_]/','',(string)($data['after_stage'] ?? $stage));
                return self::addExtraApprovers($portal, $id, $afterStage ?: $stage, $data['extra_approver_ids'] ?? '', $data['extra_approver_names'] ?? '', $userId, $userName, $comment);
            }
            if ($action === 'reassign') {
                $canChangeRoute = AccessControl::hasFlag($portal, $userId, $user['WORK_POSITION'] ?? '', 'can_change_request_route', false, $user);
                if (!$canChangeRoute) return ['error'=>'Нет права изменять запущенный маршрут'];
                $ids = self::parseIds($data['approver_ids'] ?? '');
                $names = self::parseNames($data['approver_names'] ?? '');
                if (!$ids) return ['error'=>'Выберите новых исполнителей этапа'];
                self::addApprovers($id,$stage,$ids,$names);
                self::history($id,'reassigned',$stage,$stage,$userId,$userName,'Новые исполнители: '.self::usersToText($ids,$names));
                self::notifyStage($portal,$id,$stage);
                return ['ok'=>true, 'redirect'=>false, 'message'=>'Исполнители текущего этапа обновлены'];
            }
            if ($action !== 'approve') return ['error'=>'Неизвестное действие'];

            self::approveCurrentPending($id,$stage,$userId,$userName,$comment);
            self::history($id,'approved',$stage,$stage,$userId,$userName,$comment);
            if (self::isExtraStage($stage)) self::advanceAfter($portal,$id,self::extraBaseStage($stage),$userId,$userName,$comment);
            elseif (in_array($stage, ['manager','department_director','finance_final','general_director','security','finance_director','treasury'], true) || self::isCustomRouteStage($stage)) self::advanceAfter($portal,$id,$stage,$userId,$userName,$comment);
            else return ['error'=>'На этом этапе обычное согласование недоступно'];
            return ['ok'=>true, 'redirect'=>false, 'message'=>'Заявка успешно согласована'];
        });
    }


    private static function notifyOnce($portal, $requestId, $stage, $action, $receiverUserId, $message, $subject = '') {
        $receiverUserId = (int)$receiverUserId;
        if ($receiverUserId <= 0 || trim((string)$message) === '') return false;
        $eventKey = md5((int)$requestId . '|' . (string)$stage . '|' . (string)$action . '|' . $receiverUserId);

        try {
            DB::execute("CREATE TABLE IF NOT EXISTS approval_notifications_log (
              id INT AUTO_INCREMENT PRIMARY KEY,
              portal VARCHAR(255) NOT NULL,
              request_id INT NOT NULL,
              stage_code VARCHAR(50) NOT NULL,
              action_type VARCHAR(50) NOT NULL,
              receiver_user_id INT NOT NULL,
              event_key VARCHAR(64) NOT NULL,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_approval_notification (event_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $exists = DB::fetchOne("SELECT id FROM approval_notifications_log WHERE event_key=? LIMIT 1", [$eventKey]);
            if ($exists) return false;
        } catch (Throwable $e) {
            app_log('Approval::notifyOnce log check failed: '.$e->getMessage());
        }

        $send = function() use ($portal, $requestId, $stage, $action, $receiverUserId, $message, $subject, $eventKey) {
            $delivered = false;
            try {
                $delivered = Bitrix::notifyUser($portal, $receiverUserId, $message) || $delivered;
            } catch (Throwable $e) {
                app_log('Approval notification Bitrix delivery failed: ' . $e->getMessage());
            }
            try {
                if ($subject !== '') {
                    // Почта — резервный канал. Ошибка mail() не должна ломать согласование.
                    Bitrix::emailUsers($portal, [$receiverUserId], $subject, $message);
                }
            } catch (Throwable $e) {
                app_log('Approval notification email delivery failed: ' . $e->getMessage());
            }
            if ($delivered) {
                try {
                    DB::execute("INSERT IGNORE INTO approval_notifications_log (portal,request_id,stage_code,action_type,receiver_user_id,event_key,created_at) VALUES (?,?,?,?,?,?,?)",
                        [$portal,(int)$requestId,(string)$stage,(string)$action,$receiverUserId,$eventKey,self::nowSql()]);
                } catch (Throwable $e) { app_log('Approval::notifyOnce log insert after delivery failed: '.$e->getMessage()); }
            } else {
                app_log('Approval notification was not delivered: request='.$requestId.', stage='.$stage.', receiver='.$receiverUserId.'. Check local app scopes: im, user.');
            }
        };
        if (class_exists('DB') && method_exists('DB', 'afterCommit')) {
            DB::afterCommit(function() use ($send) { register_shutdown_function($send); });
        } else {
            register_shutdown_function($send);
        }
        return true;
    }

    public static function notifyStage($portal, $requestId, $stage) {
        try {
            $req = self::get($portal,$requestId);
            if (!$req) return;
            $ids = [];
            if (self::isExtraStage($stage)) {
                $rows = DB::fetchAll("SELECT user_id FROM approval_request_approvers WHERE request_id=? AND stage=? AND status='pending'", [(int)$requestId, (string)$stage]);
                $ids = array_values(array_map(static fn($r)=>(int)($r['user_id'] ?? 0), $rows));
            }
            elseif ($stage === 'manager') $ids = [(int)$req['manager_id']];
            elseif ($stage === 'creator_rework') $ids = [(int)$req['creator_id']];
            elseif ($stage === 'department_director') $ids = self::parseIds($req['director_ids'] ?? '');
            elseif ($stage === 'finance_rework') $ids = self::settingIds($portal,'finance_route', self::routeTypeForRequest($req));
            elseif (in_array($stage, array_keys(self::configurableStages()), true)) $ids = self::settingIds($portal,$stage, self::routeTypeForRequest($req));
            $ids = array_values(array_filter(array_unique($ids), static fn($v)=>$v>0));
            $appText = 'Откройте приложение "Финансовая система СПО БИМА" и рассмотрите заявку.';
            $text = 'Вам поступила заявка #' . $requestId . ' на согласование. Этап: ' . self::stageLabelFor($portal, $stage) . '. Сумма: ' . number_format((float)$req['amount'],2,'.',' ') . ' TJS. ' . $appText;
            foreach ($ids as $uid) self::notifyOnce($portal, $requestId, $stage, 'stage_to_approver', $uid, $text, 'Новая заявка на согласование #' . $requestId);

            $creatorId = (int)($req['creator_id'] ?? 0);
            if ($creatorId > 0 && $stage !== 'creator_rework') {
                $creatorText = 'Ваша заявка #' . $requestId . ' передана на этап: ' . self::stageLabelFor($portal, $stage) . '. Сумма: ' . number_format((float)$req['amount'],2,'.',' ') . ' TJS.';
                self::notifyOnce($portal, $requestId, $stage, 'stage_to_creator', $creatorId, $creatorText, 'Статус заявки #' . $requestId);
            }
        } catch (Throwable $e) { app_log('Approval::notifyStage failed: '.$e->getMessage()); }
    }

    public static function notifyFinal($portal, $requestId, $message) {
        try {
            $req = self::get($portal,$requestId);
            if (!$req) return;
            $ids = array_values(array_filter(array_unique([(int)$req['creator_id'], (int)$req['manager_id']]), static fn($v)=>$v>0));
            $text = $message . ' #' . $requestId . '. Сумма: ' . number_format((float)$req['amount'],2,'.',' ') . ' TJS';
            foreach ($ids as $uid) self::notifyOnce($portal, $requestId, 'completed', 'final', $uid, $text, 'Результат согласования заявки #' . $requestId);
        } catch (Throwable $e) { app_log('Approval::notifyFinal failed: '.$e->getMessage()); }
    }
}
