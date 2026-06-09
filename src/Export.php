<?php
class Export {

    private static function allColumns() {
        return [
            'no'             => '№',
            'op_number'      => '№ операции',
            'date'           => 'Дата транзакции',
            'debit'          => 'Дебет',
            'credit'         => 'Кредит',
            'currency'       => 'Валюта',
            'rate'           => 'Курс валюты',
            'description'    => 'Описание платежа',
            'recipient'      => 'Получатель денег',
            'link'           => 'Ссылка на транзакцию',
            'code'           => 'Код',
            'operation'      => 'Тип операции',
            'department'     => 'Отдел инициатора',
            'account'        => 'Банк/Касса',
            'region'         => 'Локация/регион',
            'sub_department' => 'Подотдел',
        ];
    }

    private static function normalizeColumns($columns = null) {
        $all = self::allColumns();
        if (is_string($columns)) $columns = array_filter(explode(',', $columns));
        if (!is_array($columns) || empty($columns)) return array_keys($all);
        $columns = array_values(array_intersect($columns, array_keys($all)));
        return $columns ?: array_keys($all);
    }

    private static function rowData($p, $n) {
        $type = $p['effective_type'] ?? $p['type'] ?? 'expense';
        $isIncome = $type === 'income';
        $catName  = $p['category_name'] ?? '';
        $code     = $p['operation_code'] ?? '';
        $opName   = $p['operation_type_name'] ?? '';
        if (!$code && $catName && preg_match('/^(\d+)\s*[-–]\s*(.+)$/u', $catName, $m)) {
            $code = $m[1]; $opName = $opName ?: $m[2];
        } elseif (!$opName) { $opName = $catName; }
        $amount = (float)($p['amount_effective'] ?? $p['amount'] ?? 0);
        return [
            'no'             => $n,
            'op_number'      => 'Операция #' . (string)($p['id'] ?? ''),
            'date'           => !empty($p['date']) ? date('d.m.Y', strtotime($p['date'])) : '',
            'debit'          => $isIncome ? number_format($amount, 2, '.', ' ') : '',
            'credit'         => !$isIncome ? number_format($amount, 2, '.', ' ') : '',
            'currency'       => $p['currency'] ?? 'TJS',
            'rate'           => $p['exchange_rate'] ?? 1,
            'description'    => $p['description'] ?? $p['comment'] ?? '',
            'recipient'      => $p['recipient_name'] ?? $p['company'] ?? '',
            'link'           => ($p['transaction_link'] ?? '') ?: ((($p['source'] ?? '') === 'approval') ? ($p['comment'] ?? '') : ''),
            'code'           => $code,
            'operation'      => $opName,
            'department'     => $p['department'] ?? '',
            'account'        => $p['account_name'] ?? '',
            'region'         => $p['region'] ?? 0,
            'sub_department' => $p['sub_department'] ?? '',
        ];
    }


    private static function writableDirCandidates() {
        $candidates = [];
        if (defined('APP_EXPORT_TMP_DIR')) $candidates[] = APP_EXPORT_TMP_DIR;
        $candidates[] = dirname(__DIR__) . '/tmp';
        $candidates[] = dirname(__DIR__) . '/logs';
        $sys = sys_get_temp_dir();
        if ($sys) $candidates[] = $sys;
        return array_values(array_unique(array_filter($candidates)));
    }

    private static function ensureWritableDir($dir) {
        $dir = rtrim((string)$dir, DIRECTORY_SEPARATOR);
        if ($dir === '') return false;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_dir($dir)) return false;
        $test = $dir . DIRECTORY_SEPARATOR . 'fb24_write_test_' . getmypid() . '_' . mt_rand() . '.tmp';
        $fh = @fopen($test, 'wb');
        if (!$fh) return false;
        @fwrite($fh, '1');
        @fclose($fh);
        @unlink($test);
        return true;
    }

    private static function createTempFile($prefix) {
        $errors = [];
        foreach (self::writableDirCandidates() as $dir) {
            $dir = rtrim((string)$dir, DIRECTORY_SEPARATOR);
            if (!self::ensureWritableDir($dir)) {
                $errors[] = $dir . ' недоступна для записи';
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$prefix) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.tmp';
            $fh = @fopen($path, 'xb');
            if ($fh) {
                @fclose($fh);
                return $path;
            }

            $path = @tempnam($dir, $prefix);
            if ($path !== false) return $path;
            $errors[] = $dir . ' tempnam вернул false';
        }
        throw new RuntimeException('Не удалось создать временный файл Excel. Проверьте права на папку tmp или logs. Детали: ' . implode('; ', $errors));
    }

    private static function x($value) {
        return htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private static function colName($num) {
        $name = '';
        while ($num > 0) {
            $rem = ($num - 1) % 26;
            $name = chr(65 + $rem) . $name;
            $num = (int)(($num - $rem) / 26);
        }
        return $name;
    }

    private static function normalizeXlsxFilename($filename) {
        $filename = trim((string)$filename);
        if ($filename === '') $filename = 'export.xlsx';
        $filename = preg_replace('/\.(xls|xlsx)$/iu', '', $filename) . '.xlsx';
        return $filename;
    }

    private static function dispositionHeader($filename) {
        $fallback = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $filename);
        if ($fallback === '' || $fallback === '.xlsx') $fallback = 'export.xlsx';
        return 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }

    private static function styleFor($columnKey, $rowKind) {
        if ($rowKind === 'header') return 1;
        if ($rowKind === 'total') return 7;
        if ($rowKind === 'rejected') return 9;
        if ($columnKey === 'no') return 2;
        if ($columnKey === 'debit') return 3;
        if ($columnKey === 'credit') return 4;
        if (in_array($columnKey, ['description','recipient','link'], true)) return 5;
        if (in_array($columnKey, ['department','account','region','sub_department'], true)) return 6;
        return 8;
    }

    private static function rowXml($rowNumber, array $values, array $columns, $kind = 'body') {
        $max = max(count($columns), 1);
        $height = $kind === 'header' ? '24' : ($kind === 'total' ? '22' : '20');
        $xml = '<row r="'.$rowNumber.'" ht="'.$height.'" customHeight="1">';
        $cNum = 0;
        foreach ($values as $value) {
            $cNum++;
            $ref = self::colName($cNum) . $rowNumber;
            $columnKey = $columns[$cNum-1] ?? '';
            $style = self::styleFor($columnKey, $kind);
            $xml .= '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.self::x($value).'</t></is></c>';
        }
        if ($kind === 'total' && count($values) === 1 && $max > 1) {
            for ($cNum = 2; $cNum <= $max; $cNum++) {
                $ref = self::colName($cNum) . $rowNumber;
                $xml .= '<c r="'.$ref.'" s="7"/>';
            }
        }
        return $xml . '</row>';
    }

    private static function writeWorksheetFile($payments, $totals, $columns) {
        $tmp = self::createTempFile('fb24_sheet');
        $fh = fopen($tmp, 'wb');
        if (!$fh) throw new RuntimeException('Не удалось открыть временный файл Excel.');

        $colXml = '<cols>';
        $widthByKey = [
            'no'=>7,'op_number'=>18,'date'=>13,'debit'=>14,'credit'=>14,'currency'=>10,'rate'=>12,
            'description'=>38,'recipient'=>26,'link'=>24,'code'=>12,'operation'=>34,'department'=>24,
            'account'=>22,'region'=>13,'sub_department'=>20,
        ];
        $max = max(count($columns), 1);
        for ($i = 1; $i <= $max; $i++) {
            $key = $columns[$i-1] ?? '';
            $w = $widthByKey[$key] ?? 18;
            $colXml .= '<col min="'.$i.'" max="'.$i.'" width="'.$w.'" customWidth="1"/>';
        }
        $colXml .= '</cols>';

        fwrite($fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' .
            '<sheetFormatPr defaultRowHeight="18"/>' . $colXml . '<sheetData>');

        $labels = self::allColumns();
        $head = [];
        foreach ($columns as $c) $head[] = $labels[$c] ?? $c;
        $rowNumber = 1;
        fwrite($fh, self::rowXml($rowNumber, $head, $columns, 'header'));

        $n = 0;
        foreach ($payments as $p) {
            $n++;
            // Excel physical row limit: 1,048,576. Header + total leave 1,048,574 data rows.
            if ($n > 1048574) break;
            $rowNumber++;
            $row = self::rowData($p, $n);
            $line = [];
            foreach ($columns as $c) $line[] = $row[$c] ?? '';
            $status = strtolower(trim((string)($p['status'] ?? 'approved')));
            fwrite($fh, self::rowXml($rowNumber, $line, $columns, $status === 'rejected' ? 'rejected' : 'body'));
        }

        $rowNumber++;
        $totalLine = ['ИТОГО: Дебет ' . number_format($totals['income'] ?? 0, 2, '.', ' ') .
            '   Кредит ' . number_format($totals['expense'] ?? 0, 2, '.', ' ') .
            '   Баланс ' . number_format($totals['profit'] ?? (($totals['income'] ?? 0) - ($totals['expense'] ?? 0)), 2, '.', ' ')];
        fwrite($fh, self::rowXml($rowNumber, $totalLine, $columns, 'total'));

        $lastCol = self::colName($max);
        fwrite($fh, '</sheetData><autoFilter ref="A1:'.$lastCol.'1"/>' .
            '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>' .
            '</worksheet>');
        fclose($fh);
        return $tmp;
    }

    private static function stylesXml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="6">
    <font><sz val="11"/><color rgb="FF111827"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FF1B5E20"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFB71C1C"/><name val="Calibri"/></font>
    <font><sz val="11"/><color rgb="FF6B7280"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FF111827"/><name val="Calibri"/></font>
  </fonts>
  <fills count="9">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF5F5F5"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFE8F5E9"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFEBEE"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFF8F0"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFEBF5FB"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF5F7FA"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="3">
    <border/>
    <border><left style="thin"><color rgb="FFD9D9D9"/></left><right style="thin"><color rgb="FFD9D9D9"/></right><top style="thin"><color rgb="FFD9D9D9"/></top><bottom style="thin"><color rgb="FFD9D9D9"/></bottom></border>
    <border><left style="thin"><color rgb="FFD9D9D9"/></left><right style="thin"><color rgb="FFD9D9D9"/></right><top style="medium"><color rgb="FF1F4E79"/></top><bottom style="thin"><color rgb="FFD9D9D9"/></bottom></border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="10">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="2" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <xf numFmtId="0" fontId="3" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="0" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="5" fillId="8" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>
    <xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>
  </cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>';
    }

    private static function partString($name, $data) {
        return ['name' => $name, 'data' => (string)$data, 'path' => null];
    }

    private static function partFile($name, $path) {
        return ['name' => $name, 'data' => null, 'path' => $path];
    }

    private static function rawDeflateAvailable() {
        return function_exists('gzdeflate') && function_exists('deflate_init') && function_exists('deflate_add') && defined('ZLIB_ENCODING_RAW');
    }

    private static function crcToInt($crcHex) {
        $crcHex = substr(str_pad((string)$crcHex, 8, '0', STR_PAD_LEFT), -8);
        return hexdec($crcHex);
    }

    private static function compressStringPart(array $part) {
        $data = (string)($part['data'] ?? '');
        if (!self::rawDeflateAvailable() || $data === '') {
            $part['method'] = 0;
            $part['size'] = strlen($data);
            $part['csize'] = strlen($data);
            $part['crc'] = crc32($data);
            $part['payload_data'] = $data;
            return $part;
        }

        $compressed = gzdeflate($data, 6);
        if ($compressed === false) {
            $compressed = $data;
            $method = 0;
        } else {
            $method = 8;
        }
        $part['method'] = $method;
        $part['size'] = strlen($data);
        $part['csize'] = strlen($compressed);
        $part['crc'] = crc32($data);
        $part['payload_data'] = $compressed;
        return $part;
    }

    private static function compressFilePart(array $part) {
        $path = (string)($part['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('Не найден временный файл Excel для архивации.');
        }

        if (!self::rawDeflateAvailable()) {
            $part['method'] = 0;
            $part['size'] = filesize($path);
            $part['csize'] = filesize($path);
            $part['crc'] = self::crcToInt(hash_file('crc32b', $path));
            $part['payload_path'] = $path;
            return $part;
        }

        $compressedPath = self::createTempFile('fb24_deflate');
        $in = fopen($path, 'rb');
        $out = fopen($compressedPath, 'wb');
        if (!$in || !$out) {
            if ($in) fclose($in);
            if ($out) fclose($out);
            if (is_file($compressedPath)) @unlink($compressedPath);
            throw new RuntimeException('Не удалось подготовить сжатие Excel-файла.');
        }

        $ctx = deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]);
        if (!$ctx) {
            fclose($in); fclose($out);
            if (is_file($compressedPath)) @unlink($compressedPath);
            $part['method'] = 0;
            $part['size'] = filesize($path);
            $part['csize'] = filesize($path);
            $part['crc'] = self::crcToInt(hash_file('crc32b', $path));
            $part['payload_path'] = $path;
            return $part;
        }

        $hash = hash_init('crc32b');
        $size = 0;
        while (!feof($in)) {
            $chunk = fread($in, 1048576);
            if ($chunk === false) break;
            if ($chunk === '') continue;
            $size += strlen($chunk);
            hash_update($hash, $chunk);
            $deflated = deflate_add($ctx, $chunk, ZLIB_NO_FLUSH);
            if ($deflated !== false && $deflated !== '') fwrite($out, $deflated);
        }
        $finish = deflate_add($ctx, '', ZLIB_FINISH);
        if ($finish !== false && $finish !== '') fwrite($out, $finish);
        fclose($in);
        fclose($out);

        $part['method'] = 8;
        $part['size'] = $size;
        $part['csize'] = filesize($compressedPath);
        $part['crc'] = self::crcToInt(hash_final($hash));
        $part['payload_path'] = $compressedPath;
        $part['cleanup_path'] = $compressedPath;
        return $part;
    }

    private static function prepareZipParts(array $parts) {
        $prepared = [];
        foreach ($parts as $part) {
            if (!empty($part['path'])) {
                $prepared[] = self::compressFilePart($part);
            } else {
                $prepared[] = self::compressStringPart($part);
            }
        }
        return $prepared;
    }

    private static function cleanupPreparedZipParts(array $parts) {
        foreach ($parts as $part) {
            $p = $part['cleanup_path'] ?? '';
            if ($p && is_file($p)) @unlink($p);
        }
    }

    private static function writeZipToFile($zipPath, array $parts) {
        $out = fopen($zipPath, 'wb');
        if (!$out) throw new RuntimeException('Не удалось создать файл Excel.');
        $central = '';
        $offset = 0;
        $now = getdate();
        $dosTime = (($now['hours'] & 0x1F) << 11) | (($now['minutes'] & 0x3F) << 5) | ((int)($now['seconds'] / 2) & 0x1F);
        $year = max(1980, (int)$now['year']);
        $dosDate = (($year - 1980) << 9) | (((int)$now['mon'] & 0x0F) << 5) | ((int)$now['mday'] & 0x1F);

        foreach ($parts as $part) {
            $name = str_replace('\\', '/', (string)$part['name']);
            $size = (int)($part['size'] ?? 0);
            $csize = (int)($part['csize'] ?? $size);
            $crc = (int)($part['crc'] ?? 0);
            $method = (int)($part['method'] ?? 0);
            $nameLen = strlen($name);
            $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, $dosTime, $dosDate, $crc, $csize, $size, $nameLen, 0) . $name;
            fwrite($out, $localHeader);
            if (!empty($part['payload_path'])) {
                $in = fopen($part['payload_path'], 'rb');
                if (!$in) throw new RuntimeException('Не удалось прочитать временный файл Excel.');
                stream_copy_to_stream($in, $out);
                fclose($in);
            } else {
                fwrite($out, (string)($part['payload_data'] ?? ''));
            }
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, $dosTime, $dosDate, $crc, $csize, $size, $nameLen, 0, 0, 0, 0, 0, $offset) . $name;
            $offset += strlen($localHeader) + $csize;
        }
        $cdOffset = $offset;
        $cdSize = strlen($central);
        fwrite($out, $central);
        $count = count($parts);
        fwrite($out, pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $cdSize, $cdOffset, 0));
        fclose($out);
    }

    private static function outputXlsx($payments, $totals, $filename, $columns) {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $worksheetPath = self::writeWorksheetFile($payments, $totals, $columns);
        $zipPath = self::createTempFile('fb24_xlsx');
        try {
            $parts = [
                self::partString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>'),
                self::partString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>'),
                self::partString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Данные" sheetId="1" r:id="rId1"/></sheets></workbook>'),
                self::partString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>'),
                self::partFile('xl/worksheets/sheet1.xml', $worksheetPath),
                self::partString('xl/styles.xml', self::stylesXml()),
                self::partString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>Finance B24</dc:creator><cp:lastModifiedBy>Finance B24</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:modified></cp:coreProperties>'),
                self::partString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Finance B24</Application></Properties>'),
            ];
            $preparedParts = self::prepareZipParts($parts);
            try {
                self::writeZipToFile($zipPath, $preparedParts);
            } finally {
                self::cleanupPreparedZipParts($preparedParts ?? []);
            }

            if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', '0');
            while (ob_get_level() > 0) { @ob_end_clean(); }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: ' . self::dispositionHeader($filename));
            header('Content-Length: ' . filesize($zipPath));
            header('Content-Transfer-Encoding: binary');
            header('X-Accel-Buffering: no');
            header('Cache-Control: no-store, no-cache, must-revalidate');

            $fp = fopen($zipPath, 'rb');
            if (!$fp) throw new RuntimeException('Не удалось прочитать готовый Excel-файл.');
            while (!feof($fp)) {
                echo fread($fp, 1048576);
                if (function_exists('flush')) @flush();
            }
            fclose($fp);
        } finally {
            if (is_file($worksheetPath)) @unlink($worksheetPath);
            if (is_file($zipPath)) @unlink($zipPath);
        }
        exit;
    }

    public static function toExcel($payments, $totals, $filename = 'report.xlsx', $columns = null) {
        $columns = self::normalizeColumns($columns);
        $filename = self::normalizeXlsxFilename($filename);
        self::outputXlsx($payments, $totals, $filename, $columns);
    }

    public static function toCSV($payments, $filename = 'report.csv', $columns = null) {
        $columns = self::normalizeColumns($columns);
        $labels = self::allColumns();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache');
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        fputcsv($out, array_map(fn($c)=>$labels[$c] ?? $c, $columns), ';');
        $n = 0;
        foreach ($payments as $p) {
            $n++;
            $row = self::rowData($p, $n);
            fputcsv($out, array_map(fn($c)=>$row[$c] ?? '', $columns), ';');
        }
        fclose($out);
        exit;
    }
}
