<?php
function rpt_norm_date($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1];
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value)) return $value;
    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : $value;
}
function rpt_human_date($value) {
    $value = rpt_norm_date($value);
    if ($value !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) return $m[3] . '.' . $m[2] . '.' . $m[1];
    return $value;
}
function rpt_human_datetime($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    try {
        $tz = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Dushanbe');
        $dt = new DateTime($value, $tz);
        $dt->setTimezone($tz);
        return $dt->format('d.m.Y H:i');
    } catch (Throwable $e) {
        $ts = strtotime($value);
        return $ts ? date('d.m.Y H:i', $ts) : $value;
    }
}
$dateExact = rpt_norm_date($_GET['date_exact'] ?? '');
$dateFrom = rpt_norm_date($_GET['date_from'] ?? '');
$dateTo   = rpt_norm_date($_GET['date_to']   ?? '');
if ($dateExact !== '') { $dateFrom = $dateExact; $dateTo = $dateExact; }
if ($dateFrom && $dateTo && $dateFrom > $dateTo) { $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp; }
$limitRaw = (int)($_GET['limit'] ?? 100);
$allowedLimits = [25,50,100,200,500,1000];
if ($limitRaw <= 0) $limitRaw = 100;
$limit = in_array($limitRaw, $allowedLimits, true) ? $limitRaw : min(1000, max(25, $limitRaw));
$offset   = max(0,(int)($_GET['offset'] ?? 0));

$fType  = $_GET['f_type']  ?? '';
$fPayId = trim((string)($_GET['f_payment_id'] ?? ''));
$fAcc   = $_GET['f_acc']   ?? '';
$fDept  = $_GET['f_dept']  ?? '';
$fSub   = $_GET['f_sub']   ?? '';
$fReg   = $_GET['f_reg']   ?? '';
$fCur   = $_GET['f_cur']   ?? '';
$fDesc  = trim($_GET['f_desc']  ?? '');
$fRec   = trim($_GET['f_rec']   ?? '');
$fLink  = trim($_GET['f_link']  ?? '');
$fCode  = trim($_GET['f_code']  ?? '');
$fOp    = trim($_GET['f_op']    ?? '');
$fDate  = rpt_norm_date($_GET['f_date'] ?? '');
$fRateDate = rpt_norm_date($_GET['rate_date_filter'] ?? '');
$fDebit = trim((string)($_GET['f_debit'] ?? ''));
$fCredit = trim((string)($_GET['f_credit'] ?? ''));

$canEdit = !empty($canEditReport);
$accessIds = $isManager ? null : ($allowedAccountIds ?? []);

if (!$isManager && $fAcc !== '') {
    $allowedForUrl = array_map('intval', $allowedAccountIds ?? []);
    if (!in_array((int)$fAcc, $allowedForUrl, true)) $fAcc = '';
}

$baseFilter = ['order'=>'asc'];
if ($dateFrom !== '') $baseFilter['date_from'] = $dateFrom;
if ($dateTo !== '') $baseFilter['date_to'] = $dateTo;
if ($fType) $baseFilter['type'] = $fType;
if ($fPayId !== '') $baseFilter['payment_id'] = $fPayId;
if ($fAcc)  $baseFilter['account_id'] = $fAcc;
if ($fRateDate) $baseFilter['exchange_rate_date'] = $fRateDate;
if ($fDate) $baseFilter['f_date'] = $fDate;
if ($fDebit !== '') $baseFilter['debit_amount'] = $fDebit;
if ($fCredit !== '') $baseFilter['credit_amount'] = $fCredit;
if ($fDept) $baseFilter['department'] = $fDept;
if ($fSub) $baseFilter['sub_department'] = $fSub;
if ($fReg !== '') $baseFilter['region'] = $fReg;
if ($fCur) $baseFilter['currency'] = $fCur;
if ($fCode !== '') $baseFilter['operation_code'] = $fCode;
if ($fDesc !== '') $baseFilter['description'] = $fDesc;
if ($fRec !== '') $baseFilter['recipient'] = $fRec;
if ($fLink !== '') $baseFilter['link'] = $fLink;
if ($fOp !== '') $baseFilter['operation_name'] = $fOp;
if (!$isManager) $baseFilter['allowed_account_ids'] = $allowedAccountIds ?? [];

$summary = Payment::summaryByFilter($portal, $baseFilter);
$totalCount = (int)($summary['count'] ?? 0);
$debitTotal = (float)($summary['income'] ?? 0);
$creditTotal = (float)($summary['expense'] ?? 0);
$profitTotal = $debitTotal - $creditTotal;
$listFilter = $baseFilter + ['limit'=>$limit,'offset'=>$offset];
$pays = Payment::list($portal, $listFilter);
// История изменений загружается лениво. В свернутом состоянии отчёт не делает лишние запросы к history,
// поэтому режим "Показать 1000" открывается заметно быстрее.
$histOpen = !empty($_GET['hist_open']);
$histLimit = max(10, min(100, (int)($_GET['hist_limit'] ?? 20)));
$histPage = max(1, (int)($_GET['hist_page'] ?? 1));
$histTotal = $histOpen ? Payment::historyCount($portal, []) : 0;
$histPages = max(1, (int)ceil($histTotal / $histLimit));
if ($histPage > $histPages) $histPage = $histPages;
$reportHistory = $histOpen ? Payment::historyRows($portal, [], $histLimit, ($histPage - 1) * $histLimit) : [];

// Варианты фильтров не грузим синхронно при открытии отчёта: на больших базах
// десятки DISTINCT-запросов блокировали загрузку дизайна. Списки подгружаются AJAX по фокусу/вводу.
function rpt_seed_options($cur) {
    $cur = trim((string)$cur);
    return $cur !== '' ? [$cur] : [];
}
$uPayIds = rpt_seed_options($fPayId);
$uDates = rpt_seed_options($fDate);
$uRateDates = rpt_seed_options($fRateDate);
$uDebitAmounts = rpt_seed_options($fDebit);
$uCreditAmounts = rpt_seed_options($fCredit);
$uDepts = rpt_seed_options($fDept);
$uSubs  = rpt_seed_options($fSub);
$uRegs  = rpt_seed_options($fReg);
$uCurs  = rpt_seed_options($fCur);
$uCodes = rpt_seed_options($fCode);
$uOps   = rpt_seed_options($fOp);
$uDescs = rpt_seed_options($fDesc);
$uRecs  = rpt_seed_options($fRec);
$uLinks = rpt_seed_options($fLink);

function rpt_status_norm($status) {
    $s = mb_strtolower(trim((string)$status));
    if ($s === '' || in_array($s, ['approved','подтверждено','confirm','confirmed'], true)) return 'approved';
    if (in_array($s, ['pending','на согласовании','согласование'], true)) return 'pending';
    if (in_array($s, ['paid','выплачено','оплачено'], true)) return 'paid';
    if (in_array($s, ['rejected','отклонено','отклонён','отклонен'], true)) return 'rejected';
    return in_array($s, ['approved','pending','paid','rejected'], true) ? $s : 'approved';
}

$reportCols = [
    'no'             => ['label'=>'№', 'class'=>'h-dark', 'min'=>'35px'],
    'op_number'      => ['label'=>'№ операции', 'class'=>'h-dark', 'min'=>'120px'],
    'date'           => ['label'=>'Дата', 'class'=>'h-dark', 'min'=>'95px'],
    'debit'          => ['label'=>'Дебет', 'class'=>'h-dark', 'min'=>'95px'],
    'credit'         => ['label'=>'Кредит', 'class'=>'h-dark', 'min'=>'95px'],
    'currency'       => ['label'=>'Вал.', 'class'=>'h-dark', 'min'=>'65px'],
    'rate'           => ['label'=>'Курс', 'class'=>'h-dark', 'min'=>'65px'],
    'description'    => ['label'=>'Описание', 'class'=>'h-mid', 'min'=>'180px'],
    'recipient'      => ['label'=>'Получатель', 'class'=>'h-mid', 'min'=>'150px'],
    'link'           => ['label'=>'Ссылка', 'class'=>'h-mid', 'min'=>'110px'],
    'code'           => ['label'=>'Код', 'class'=>'h-mid', 'min'=>'65px'],
    'operation'      => ['label'=>'Тип операции', 'class'=>'h-mid', 'min'=>'190px'],
    'department'     => ['label'=>'Отдел', 'class'=>'h-dark', 'min'=>'90px'],
    'account'        => ['label'=>'Банк/Касса', 'class'=>'h-dark', 'min'=>'120px'],
    'region'         => ['label'=>'Регион', 'class'=>'h-dark', 'min'=>'65px'],
    'sub_department' => ['label'=>'Подотдел', 'class'=>'h-dark', 'min'=>'95px'],
];
$defaultColKeys = array_keys($reportCols);
$colsParam = trim((string)($_GET['cols'] ?? ''));
$visibleColKeys = $colsParam !== '' ? array_values(array_intersect(explode(',', $colsParam), $defaultColKeys)) : $defaultColKeys;
if (empty($visibleColKeys)) $visibleColKeys = $defaultColKeys;
$reportMinWidth = 0;
foreach ($visibleColKeys as $__ck) {
    $reportMinWidth += (int)str_replace('px','', $reportCols[$__ck]['min'] ?? '110');
}
$reportMinWidth += $canEdit ? 90 : 0;
$reportMinWidth = max(920, $reportMinWidth);
function col_on($key) { global $visibleColKeys; return in_array($key, $visibleColKeys, true); }
function cols_query_value($keys) { return implode(',', $keys); }

function rf_keep($overrides = [], $remove = []) {
    $q = $_GET;
    foreach ($remove as $r) unset($q[$r]);
    foreach ($overrides as $k=>$v) {
        if ($v === null || $v === '') unset($q[$k]); else $q[$k] = $v;
    }
    $q['page'] = 'report';
    if (!array_key_exists('offset', $overrides)) unset($q['offset']);
    return app_link($q);
}
function clear_btn($key, $active) {
    if (!$active) return '';
    return '<button type="button" class="filter-x" onclick="clearOneFilter(\''.$key.'\')" title="Сбросить этот фильтр">×</button>';
}
function fsel2($name,$opts,$cur,$empty='Все'){
    echo "<div class='mini-filter'><select class='fi' onchange=\"RF.go('{$name}',this.value)\">";
    echo "<option value=''>$empty</option>";
    foreach($opts as $o){
        $sel = (string)$cur===(string)$o ? 'selected' : '';
        echo "<option value='".htmlspecialchars($o)."' $sel>".htmlspecialchars($o)."</option>";
    }
    echo "</select>" . clear_btn($name, $cur!=='') . "</div>";
}
function finp2($name,$cur,$ph){
    echo "<div class='mini-filter'><input class='fi' type='text' placeholder='".htmlspecialchars($ph)."' value='".htmlspecialchars($cur)."' onchange=\"RF.go('{$name}',this.value)\" onkeydown=\"if(event.key==='Enter')RF.go('{$name}',this.value)\">" . clear_btn($name, $cur!=='') . "</div>";
}
function fselLimited($name,$opts,$cur,$empty='Все'){
    echo "<div class='mini-filter'><select class='fi' onchange=\"RF.go('{$name}',this.value)\">";
    echo "<option value=''>$empty</option>";
    foreach($opts as $o){
        $text=(string)$o; if($text==='') continue;
        $sel = (string)$cur===$text ? 'selected' : '';
        $label = mb_strlen($text)>64 ? mb_substr($text,0,64).'…' : $text;
        echo "<option value='".htmlspecialchars($text)."' $sel>".htmlspecialchars($label)."</option>";
    }
    echo "</select>" . clear_btn($name, $cur!=='') . "</div>";
}
function fcombo($name,$opts,$cur,$ph){
    static $dlCounter = 0;
    $dlCounter++;
    $id = 'rf_menu_' . preg_replace('/[^a-z0-9_]/i','_', $name) . '_' . $dlCounter;
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    echo "<div class='mini-filter combo-filter report-combo-wrap'>";
    echo '<input class="fi report-combo-input" autocomplete="off" data-filter-field="'.htmlspecialchars($name).'" data-menu="'.htmlspecialchars($id).'" type="text" placeholder="'.htmlspecialchars($ph).'" value="'.htmlspecialchars($cur).'" onkeydown="if(event.key===&quot;Enter&quot;){event.preventDefault();RF.go(&quot;'.$safeName.'&quot;,this.value)}">';
    echo '<div class="report-combo-menu" id="'.htmlspecialchars($id).'" data-empty="Ничего не найдено">';
    foreach($opts as $o){
        $text=(string)$o; if($text==='') continue;
        $label = mb_strlen($text)>96 ? mb_substr($text,0,96).'…' : $text;
        echo "<button type='button' data-value='".htmlspecialchars($text, ENT_QUOTES, 'UTF-8')."'>".htmlspecialchars($label)."</button>";
    }
    echo "</div>" . clear_btn($name, $cur!=='') . "</div>";
}

function report_active_filters_html($filters) {
    $labels = [
        'date_exact'=>'Дата периода','date_from'=>'Дата периода с','date_to'=>'Дата периода по','f_payment_id'=>'№ операции','f_date'=>'Дата','f_debit'=>'Дебет','f_credit'=>'Кредит',
        'f_cur'=>'Валюта','rate_date_filter'=>'Дата курса','f_desc'=>'Описание','f_rec'=>'Получатель','f_link'=>'Ссылка',
        'f_code'=>'Код','f_op'=>'Тип операции','f_dept'=>'Отдел','f_acc'=>'Банк/Касса','f_reg'=>'Регион','f_sub'=>'Подотдел','f_type'=>'Тип'
    ];
    $html = '';
    foreach ($filters as $key=>$value) {
        $value = trim((string)$value);
        if ($value === '') continue;
        $label = $labels[$key] ?? $key;
        $safeKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        $html .= '<span class="active-filter-chip"><b>'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</b>: '.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'<button type="button" onclick="clearOneFilter(\''.$safeKey.'\')" title="Сбросить этот фильтр">×</button></span>';
    }
    return $html ? '<div class="active-filters-panel"><span>Активные фильтры:</span>'.$html.'</div>' : '';
}
$pages = $limit>0 ? max(1, (int)ceil($totalCount/$limit)) : 1;
$curPage = $limit>0 ? ((int)floor($offset/$limit)+1) : 1;
function report_hist_link($page) {
    $q = $_GET; $q['page'] = 'report'; $q['hist_open'] = 1; $q['hist_page'] = max(1,(int)$page);
    return app_link($q);
}
function history_pager_html($cur,$pages,$total,$limit){
    if($pages<=1 && $total<=$limit) return '';
    $from = $total ? (($cur-1)*$limit+1) : 0; $to = min($total,$cur*$limit);
    $html = '<div class="hist-pager"><span>Показано <b>'.$from.'–'.$to.'</b> из <b>'.$total.'</b></span><div>';
    if($cur>1) $html .= '<a href="'.htmlspecialchars(report_hist_link($cur-1)).'">‹</a>';
    $start=max(1,$cur-2); $end=min($pages,$cur+2);
    if($start>1) $html .= '<a href="'.htmlspecialchars(report_hist_link(1)).'">1</a><em>…</em>';
    for($i=$start;$i<=$end;$i++) $html .= '<a class="'.($i===$cur?'on':'').'" href="'.htmlspecialchars(report_hist_link($i)).'">'.$i.'</a>';
    if($end<$pages) $html .= '<em>…</em><a href="'.htmlspecialchars(report_hist_link($pages)).'">'.$pages.'</a>';
    if($cur<$pages) $html .= '<a href="'.htmlspecialchars(report_hist_link($cur+1)).'">›</a>';
    return $html.'</div></div>';
}
?>

<div class="page-header">
  <h1>📈 Кассовые операции</h1>
  <div class="header-actions">
    <a href="<?=app_link(array_merge($_GET,['action'=>'export_excel','cols'=>cols_query_value($visibleColKeys)]))?>" class="btn btn-primary">⬇ Excel</a>
  </div>
</div>

<form method="GET" id="rf" class="filter-bar report-filter-v104">
  <input type="hidden" name="page" value="report">
  <?php foreach($_GET as $k=>$v): if(in_array($k,['page','date_from','date_to','date_exact','limit','offset','f_date','rate_date_filter','f_date1','f_date2'])) continue; ?>
  <input type="hidden" name="<?=htmlspecialchars($k)?>" value="<?=htmlspecialchars($v)?>">
  <?php endforeach; ?>
  <div class="filter-group filter-period-group">
    <label>Дата периода</label>
    <div class="filter-date-range">
      <span>с даты</span><div class="filter-inline"><input type="date" name="date_from" value="<?=htmlspecialchars($dateFrom)?>"><?=clear_btn('date_from', $dateFrom !== '')?></div>
      <span>по дату</span><div class="filter-inline"><input type="date" name="date_to" value="<?=htmlspecialchars($dateTo)?>"><?=clear_btn('date_to', $dateTo !== '')?></div>
    </div>
  </div>
  <div class="filter-group"><label>Показать</label>
    <select name="limit">
      <?php foreach([25,50,100,200,500,1000] as $l): ?><option value="<?=$l?>" <?=$limit==$l?'selected':''?>><?=$l?></option><?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-primary">Применить</button>
  <a href="<?=app_link(['page'=>'report','date_from'=>date('Y-m-01'),'date_to'=>date('Y-m-d'),'limit'=>$limit,'cols'=>cols_query_value($visibleColKeys)])?>" class="btn btn-light">Этот месяц</a>
  <a href="<?=app_link(['page'=>'report','date_from'=>date('Y-01-01'),'date_to'=>date('Y-12-31'),'limit'=>$limit,'cols'=>cols_query_value($visibleColKeys)])?>" class="btn btn-light">Этот год</a>
  <a href="<?=app_link(['page'=>'report','limit'=>$limit,'cols'=>cols_query_value($visibleColKeys)])?>" class="btn btn-light" style="background:#fee2e2;color:#dc2626;border-color:#fca5a5">✕ Сброс всех</a>
</form>

<div class="rep-summary">
  <div class="rs-i"><div class="rs-l">Дебет (Приход)</div><div class="rs-v" style="color:#1F4E79"><?=number_format($debitTotal,2,'.',' ')?> TJS</div></div>
  <div class="rs-i"><div class="rs-l">Кредит (Расход)</div><div class="rs-v" style="color:#c0392b"><?=number_format($creditTotal,2,'.',' ')?> TJS</div></div>
  <div class="rs-i"><div class="rs-l">Баланс</div><div class="rs-v" style="color:<?=$profitTotal>=0?'#27ae60':'#e74c3c'?>"><?=number_format($profitTotal,2,'.',' ')?> TJS</div></div>
  <div class="rs-i"><div class="rs-l">Показано / Всего</div><div class="rs-v"><?=count($pays)?> / <?=$totalCount?></div></div>
  <?php if ($canEdit): ?><div class="rs-i"><div class="rs-l">Режим</div><div class="rs-v" style="color:#2563eb">Редактирование включено</div></div><?php endif; ?>
</div>
<?php
$__activeFilterValues = [
    'date_from'=>$dateFrom,'date_to'=>$dateTo,'f_payment_id'=>$fPayId,'f_date'=>$fDate,'f_debit'=>$fDebit,'f_credit'=>$fCredit,
    'f_cur'=>$fCur,'rate_date_filter'=>$fRateDate,'f_desc'=>$fDesc,'f_rec'=>$fRec,'f_link'=>$fLink,
    'f_code'=>$fCode,'f_op'=>$fOp,'f_dept'=>$fDept,'f_acc'=>$fAcc,'f_reg'=>$fReg,'f_sub'=>$fSub,'f_type'=>$fType
];
echo report_active_filters_html($__activeFilterValues);
?>

<details class="history-panel report-history-panel" <?= $histOpen ? 'open' : '' ?>>
  <summary>История изменений отчёта и операций</summary>
  <div class="history-toolbar">
    <span>Показать строк:</span>
    <select onchange="var p=new URLSearchParams(location.search);p.set('page','report');p.set('hist_open','1');p.set('hist_limit',this.value);p.set('hist_page','1');reportNavigate(p);">
      <?php foreach([10,20,50,100] as $hl): ?><option value="<?=$hl?>" <?=$histLimit===$hl?'selected':''?>><?=$hl?></option><?php endforeach; ?>
    </select>
  </div>
  <?php if (!$histOpen): ?>
    <div class="history-empty"><a class="btn btn-light btn-sm" href="<?=htmlspecialchars(report_hist_link(1))?>">Загрузить историю изменений</a></div>
  <?php elseif (empty($reportHistory)): ?>
    <div class="history-empty">История изменений пока пустая.</div>
  <?php else: ?>
    <div class="history-list">
      <?php foreach ($reportHistory as $h): ?>
        <div class="history-item">
          <div class="history-top">
            <b><?=htmlspecialchars(Payment::historyActionLabel($h['action']))?></b>
            <span><?=htmlspecialchars(rpt_human_datetime($h['created_at']))?></span>
          </div>
          <div class="history-meta">Операция #<?=htmlspecialchars((string)($h['payment_id'] ?: '—'))?> · <?=htmlspecialchars(Payment::historyActorLabel($portal, $h))?></div>
          <?php if (!empty($h['comment'])): ?><div class="history-comment"><?=htmlspecialchars($h['comment'])?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?=history_pager_html($histPage,$histPages,$histTotal,$histLimit)?>
  <?php endif; ?>
</details>

<details class="columns-panel">
  <summary>Настроить столбцы отчёта</summary>
  <div class="columns-grid">
    <?php foreach($reportCols as $key=>$meta): ?>
      <label><input type="checkbox" class="col-toggle" value="<?=htmlspecialchars($key)?>" <?=col_on($key)?'checked':''?>> <?=htmlspecialchars($meta['label'])?></label>
    <?php endforeach; ?>
  </div>
  <div class="columns-actions">
    <button type="button" class="btn btn-primary" onclick="applyReportColumns()">Применить столбцы</button>
    <button type="button" class="btn btn-light" onclick="resetReportColumns()">Показать все</button>
  </div>
</details>

<?php if (empty($pays)): ?>
<div class="empty-state report-empty-state"><div class="report-empty-inner"><div class="report-empty-icon">⌕</div><p>Ничего не найдено по выбранным фильтрам</p><small>Исправьте значение фильтра или сбросьте нужный фильтр в блоке "Активные фильтры" выше.</small></div></div>
<?php else: ?>
<div style="overflow-x:auto" class="report-table-wrap">
<table class="rep-table resizable-report-table" id="reportTable" style="min-width:<?=$reportMinWidth?>px;table-layout:fixed">
  <colgroup id="reportColGroup">
    <?php foreach($reportCols as $key=>$meta): if(!col_on($key)) continue; ?>
      <col data-col="<?=htmlspecialchars($key)?>" style="width:<?=$meta['min']?>">
    <?php endforeach; ?>
    <?php if ($canEdit): ?><col data-col="action" style="width:90px"><?php endif; ?>
  </colgroup>
  <thead>
    <tr>
      <?php foreach($reportCols as $key=>$meta): if(!col_on($key)) continue; ?>
        <th class="<?=$meta['class']?> resizable-th" data-rcol="<?=htmlspecialchars($key)?>" style="min-width:<?=$meta['min']?>"><?=htmlspecialchars($meta['label'])?><span class="r-resize"></span></th>
      <?php endforeach; ?>
      <?php if ($canEdit): ?><th class="h-dark resizable-th" data-rcol="action" style="min-width:80px">Действие<span class="r-resize"></span></th><?php endif; ?>
    </tr>
    <tr>
      <?php if(col_on('no')): ?><td class="fth"></td><?php endif; ?>
      <?php if(col_on('op_number')): ?><td class="fth"><?php fcombo('f_payment_id',$uPayIds,$fPayId,'№ операции'); ?></td><?php endif; ?>
      <?php if(col_on('date')): ?><td class="fth"><?php fcombo('f_date',$uDates,$fDate,'Дата'); ?></td><?php endif; ?>
      <?php if(col_on('debit')): ?><td class="fth"><?php fcombo('f_debit',$uDebitAmounts,$fDebit,'Сумма дебета'); ?></td><?php endif; ?>
      <?php if(col_on('credit')): ?><td class="fth"><?php fcombo('f_credit',$uCreditAmounts,$fCredit,'Сумма кредита'); ?></td><?php endif; ?>
      <?php if(col_on('currency')): ?><td class="fth"><?php fcombo('f_cur',$uCurs,$fCur,'Вал.'); ?></td><?php endif; ?>
      <?php if(col_on('rate')): ?><td class="fth"><?php fcombo('rate_date_filter',$uRateDates,$fRateDate,'Дата курса'); ?></td><?php endif; ?>
      <?php if(col_on('description')): ?><td class="fth"><?php fcombo('f_desc',$uDescs,$fDesc,'Описание'); ?></td><?php endif; ?>
      <?php if(col_on('recipient')): ?><td class="fth"><?php fcombo('f_rec',$uRecs,$fRec,'Получатель'); ?></td><?php endif; ?>
      <?php if(col_on('link')): ?><td class="fth"><?php fcombo('f_link',$uLinks,$fLink,'Ссылка'); ?></td><?php endif; ?>
      <?php if(col_on('code')): ?><td class="fth"><?php fcombo('f_code',$uCodes,$fCode,'Код'); ?></td><?php endif; ?>
      <?php if(col_on('operation')): ?><td class="fth"><?php fcombo('f_op',$uOps,$fOp,'Операция'); ?></td><?php endif; ?>
      <?php if(col_on('department')): ?><td class="fth"><?php fcombo('f_dept',$uDepts,$fDept,'Отдел'); ?></td><?php endif; ?>
      <?php if(col_on('account')): ?><td class="fth"><div class="mini-filter"><select class="fi" onchange="RF.go('f_acc',this.value)"><option value="">Все</option><?php foreach($accounts as $a): ?><option value="<?=$a['id']?>" <?=$fAcc==$a['id']?'selected':''?>><?=htmlspecialchars($a['name'])?></option><?php endforeach; ?></select><?=clear_btn('f_acc',$fAcc!=='')?></div></td><?php endif; ?>
      <?php if(col_on('region')): ?><td class="fth"><?php fcombo('f_reg',$uRegs,$fReg,'Рег.'); ?></td><?php endif; ?>
      <?php if(col_on('sub_department')): ?><td class="fth"><?php fcombo('f_sub',$uSubs,$fSub,'Подотдел'); ?></td><?php endif; ?>
      <?php if ($canEdit): ?><td class="fth"></td><?php endif; ?>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($pays as $i=>$p):
    $type = $p['effective_type'] ?? $p['type'];
    $isIncome = $type === 'income';
    $amount = (float)($p['amount_effective'] ?? $p['amount']);
    $st = rpt_status_norm($p['status'] ?? 'approved');
    $isRejectedStatus = ($st === 'rejected');
    $catName = $p['category_name']??'';
    $code = $p['operation_code']??'';
    $opName = $p['operation_type_name']??'';
    if(!$code&&$catName&&preg_match('/^(\d+)\s*[-–]\s*(.+)$/u',$catName,$m)){$code=$m[1];$opName=$opName?:$m[2];}
    elseif(!$opName){$opName=$catName;}
    $desc=$p['description']??$p['comment']??'';
    $rec=$p['recipient_name']??$p['company']??'';
    $link=($p['transaction_link']??'') ?: (($p['source']??'')==='approval' ? ($p['comment']??'') : '');
    $rowNo = $offset + $i + 1;
  ?>
  <tr data-pay-id="<?=$p['id']?>" class="<?=$isRejectedStatus?'report-row-rejected':''?>">
    <?php if($canEdit && !col_on('operation')): ?><input type="hidden" data-field="type" value="<?=htmlspecialchars($type)?>"><?php endif; ?>
    <?php if(col_on('no')): ?><td class="tnum"><?=$rowNo?></td><?php endif; ?>
    <?php if(col_on('op_number')): ?><td class="tnum op-num">Операция #<?=htmlspecialchars((string)$p['id'])?></td><?php endif; ?>
    <?php if(col_on('date')): ?><td class="tdat"><?php if($canEdit): ?><input class="ri" type="date" data-field="date" value="<?=htmlspecialchars($p['date'])?>"><?php else: ?><?=date('d.m.Y',strtotime($p['date']))?><?php endif; ?></td><?php endif; ?>
    <?php if(col_on('debit')): ?><td class="<?=$isIncome?'tdv':'tde'?>"><?php if($canEdit): ?><input class="ri amount-debit" type="number" step="0.01" value="<?=$isIncome?htmlspecialchars((string)$amount):''?>"><?php else: ?><?=$isIncome?number_format($amount,2,'.',' '):''?><?php endif; ?></td><?php endif; ?>
    <?php if(col_on('credit')): ?><td class="<?=!$isIncome?'tcv':'tce'?>"><?php if($canEdit): ?><input class="ri amount-credit" type="number" step="0.01" value="<?=!$isIncome?htmlspecialchars((string)$amount):''?>"><?php else: ?><?=!$isIncome?number_format($amount,2,'.',' '):''?><?php endif; ?></td><?php endif; ?>
    <?php if(col_on('currency')): ?><td><?php if($canEdit): ?><input class="ri" data-field="currency" value="<?=htmlspecialchars($p['currency']??'TJS')?>"><?php else: ?><?=htmlspecialchars($p['currency']??'TJS')?><?php endif; ?></td><?php endif; ?>
    <?php if(col_on('rate')): ?><td><?php if($canEdit): ?><input class="ri" type="number" step="0.0001" data-field="exchange_rate" value="<?=htmlspecialchars((string)($p['exchange_rate']??1))?>"><?php else: ?><?=htmlspecialchars((string)($p['exchange_rate']??1))?><?php endif; ?></td><?php endif; ?>
    <?php if(col_on('description')): ?><td class="tdes"><?php if($canEdit): ?><input class="ri wide" data-field="description" value="<?=htmlspecialchars($desc)?>"><?php else: ?><?=htmlspecialchars($desc)?><?php endif; ?></td><?php endif; ?>
    <?php if(col_on('recipient')): ?><td><?php if($canEdit): ?><input class="ri wide" data-field="recipient_name" value="<?=htmlspecialchars($rec)?>"><?php else: ?><?=htmlspecialchars($rec)?><?php endif; ?></td><?php endif; ?>
    <?php if(col_on('link')): ?><td><?php if($canEdit): ?><input class="ri wide" data-field="transaction_link" value="<?=htmlspecialchars($link)?>"><?php else: ?><?=htmlspecialchars($link)?><?php endif; ?></td><?php endif; ?>
    <?php if(col_on('code')): ?><td><?php if($canEdit): ?><input class="ri" type="number" data-field="operation_code" value="<?=htmlspecialchars((string)$code)?>"><?php else: ?><?=htmlspecialchars((string)$code)?><?php endif; ?></td><?php endif; ?>
    <?php if(col_on('operation')): ?><td><?php if($canEdit): ?><select class="ri" data-field="type"><option value="income" <?=$type==='income'?'selected':''?>>Приход</option><option value="expense" <?=$type==='expense'?'selected':''?>>Расход</option></select><input class="ri wide mt2" data-field="operation_type_name" value="<?=htmlspecialchars($opName)?>"><?php else: ?><?=htmlspecialchars($opName)?><?php endif; ?></td><?php endif; ?>
    <?php if(col_on('department')): ?><td><?php if($canEdit): ?><input class="ri" data-field="department" value="<?=htmlspecialchars($p['department']??'')?>"><?php else: ?><?=htmlspecialchars($p['department']??'')?><?php endif; ?></td><?php endif; ?>
    <?php if(col_on('account')): ?><td><?php if($canEdit): ?><select class="ri" data-field="account_id"><?php foreach($accounts as $a): ?><option value="<?=$a['id']?>" <?=$p['account_id']==$a['id']?'selected':''?>><?=htmlspecialchars($a['name'])?></option><?php endforeach; ?></select><?php else: ?><?=htmlspecialchars($p['account_name']??'')?><?php endif; ?></td><?php endif; ?>
    <?php if(col_on('region')): ?><td><?php if($canEdit): ?><select class="ri" data-field="region"><option value="0" <?=(string)($p['region']??'')==='0'?'selected':''?>>0</option><option value="1" <?=(string)($p['region']??'')==='1'?'selected':''?>>1</option><option value="2" <?=(string)($p['region']??'')==='2'?'selected':''?>>2</option></select><?php else: ?><?=htmlspecialchars((string)($p['region']??0))?><?php endif; ?></td><?php endif; ?>
    <?php if(col_on('sub_department')): ?><td><?php if($canEdit): ?><input class="ri" data-field="sub_department" value="<?=htmlspecialchars($p['sub_department']??'')?>"><?php else: ?><?=htmlspecialchars($p['sub_department']??'')?><?php endif; ?></td><?php endif; ?>
    <?php if ($canEdit): ?><td><button type="button" class="btn btn-primary btn-xs" onclick="saveReportRow(this)">💾</button></td><?php endif; ?>
  </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr class="rfoot"><td colspan="<?=max(1, count($visibleColKeys)+($canEdit?1:0))?>">
      <div class="report-total-box">
        <span class="total-title">ИТОГО</span>
        <span class="total-pill total-debit">Дебет <b><?=number_format($debitTotal,2,'.',' ')?> TJS</b></span>
        <span class="total-pill total-credit">Кредит <b><?=number_format($creditTotal,2,'.',' ')?> TJS</b></span>
        <span class="total-pill <?=$profitTotal>=0?'total-profit':'total-loss'?>">Баланс <b><?=number_format($profitTotal,2,'.',' ')?> TJS</b></span>
      </div>
    </td></tr></tfoot>
</table>
</div>
<?php if($pages>1): ?>
<div class="pag">
  <?php for($i=1;$i<=$pages;$i++): $o=($i-1)*$limit; if($i==1 || $i==$pages || abs($i-$curPage)<=2): ?>
    <a class="pgb <?=$i==$curPage?'pgba':''?>" href="<?=rf_keep(['offset'=>$o])?>"><?=$i?></a>
  <?php elseif(abs($i-$curPage)==3): ?><span class="pgdots">...</span><?php endif; endfor; ?>
  <span class="pgi">Страница <?=$curPage?> из <?=$pages?></span>
</div>
<?php endif; ?>
<?php endif; ?>

<style>
.report-row-rejected{opacity:.62;background:#f8fafc!important}.report-row-rejected td{text-decoration:line-through;color:#94a3b8!important}.report-row-rejected input,.report-row-rejected select{text-decoration:line-through!important;color:#94a3b8!important;background:#f1f5f9!important}.rep-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-bottom:14px}.rs-i{background:#fff;border-radius:10px;padding:12px 14px;box-shadow:0 1px 4px rgba(0,0,0,.06)}.rs-l{font-size:12px;color:#64748b}.rs-v{font-size:18px;font-weight:700;margin-top:4px}.rep-table{width:100%;border-collapse:collapse;background:#fff}.h-dark{background:#1F4E79;color:#fff;padding:7px 5px;font-size:11px;text-align:center}.h-mid{background:#2E75B6;color:#fff;padding:7px 5px;font-size:11px;text-align:center}.fth{background:#f3f6fa;padding:4px;border:1px solid #dde3ea}.fi,.ri{width:100%;padding:4px 5px;border-radius:4px;border:1px solid #cbd5e1;font-size:11px;box-sizing:border-box;background:#fff}.ri.wide{min-width:120px}.mini-filter{display:flex;gap:3px;align-items:center}.filter-x{border:0;background:#fee2e2;color:#dc2626;border-radius:4px;cursor:pointer;font-weight:700;padding:2px 6px}.rep-table td{padding:5px 6px;border:1px solid #e8ecf0;vertical-align:middle;font-size:12px}.tnum{color:#94a3b8;text-align:center;background:#fafafa}.tdat{text-align:center}.tde{background:#f0fff4;text-align:right}.tdv{background:#E8F5E9;color:#1B5E20;font-weight:600;text-align:right}.tce{background:#fff5f5;text-align:right}.tcv{background:#FFEBEE;color:#B71C1C;font-weight:600;text-align:right}.tdes{background:#FFF8F0}.rfoot td{background:#f5f7fa;border-top:2px solid #1F4E79}.pag{display:flex;gap:4px;margin-top:14px;flex-wrap:wrap;align-items:center;justify-content:center}.pgb{padding:6px 11px;border-radius:5px;background:#fff;border:1px solid #d6dde6;text-decoration:none;font-size:13px;color:#0f172a}.pgba{background:#2563eb;color:#fff;border-color:#2563eb;font-weight:700}.pgdots{padding:6px 4px;font-size:13px;color:#64748b;margin-left:8px}.btn-xs{padding:5px 8px;font-size:12px}.mt2{margin-top:2px}.columns-panel{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.05)}.columns-panel summary{font-weight:600;cursor:pointer;color:#334155}.columns-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px 12px;margin-top:12px}.columns-grid label{font-size:12px;color:#334155;display:flex;gap:6px;align-items:center}.columns-actions{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}.report-table-wrap{border-radius:10px;border:1px solid #e2e8f0;background:#fff}.filter-inline{display:flex;gap:4px;align-items:center}.filter-date-range{display:grid;grid-template-columns:auto minmax(145px,1fr) auto minmax(145px,1fr);gap:6px;align-items:center}.filter-date-range span{font-size:12px;color:#64748b;font-weight:700}.filter-period-group{min-width:360px}.hist-pager{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-top:10px;font-size:12px;color:#64748b;flex-wrap:wrap}.hist-pager div{display:flex;gap:4px;align-items:center}.hist-pager a{padding:5px 9px;border:1px solid #dbe3ef;border-radius:8px;background:#fff;color:#0f172a;text-decoration:none}.hist-pager a.on{background:#2563eb;color:#fff;border-color:#2563eb}.hist-pager em{font-style:normal;color:#94a3b8;padding:0 3px}

.resizable-th{position:relative;user-select:none}.r-resize{position:absolute;right:0;top:0;bottom:0;width:7px;cursor:col-resize;background:rgba(255,255,255,.10)}.r-resize:hover{background:rgba(255,255,255,.35)}.report-total-box{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:8px 4px}.total-title{font-weight:800;color:#0f172a;background:#e2e8f0;border-radius:999px;padding:7px 12px;letter-spacing:.3px}.total-pill{display:inline-flex;gap:8px;align-items:center;border-radius:999px;padding:8px 13px;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.08)}.total-pill b{font-size:14px}.total-debit{background:#dcfce7;color:#166534}.total-credit{background:#fee2e2;color:#991b1b}.total-profit{background:#dbeafe;color:#1d4ed8}.total-loss{background:#ffedd5;color:#c2410c}.report-table-wrap{overflow:auto!important;max-width:100%;max-height:min(68vh,720px);contain:layout paint}.rep-table{table-layout:fixed}.rep-table td{overflow:hidden;text-overflow:ellipsis}.rep-table .ri{min-width:0}.rep-table .ri.wide{min-width:100%}.combo-filter input.fi{min-width:0}.report-table-wrap thead th{position:sticky;top:0;z-index:6}.report-table-wrap thead tr:nth-child(2) td{position:sticky;top:30px;z-index:5}.report-table-wrap thead td{box-shadow:0 1px 0 rgba(15,23,42,.06)}@media(max-width:760px){.report-total-box{align-items:stretch}.total-title,.total-pill{width:100%;justify-content:space-between}.rep-summary{grid-template-columns:1fr!important}.filter-date-range{grid-template-columns:1fr}.filter-period-group{min-width:0;width:100%}}
@media(max-width:900px){.rep-summary{grid-template-columns:1fr 1fr}.columns-grid{grid-template-columns:1fr 1fr}.page-header .header-actions{width:100%;justify-content:flex-start}}@media(max-width:620px){.rep-summary,.columns-grid{grid-template-columns:1fr}.columns-actions .btn{width:100%;justify-content:center}}
</style>

<script>
function reportNavigate(params){
  var url = '?' + params.toString();
  if (window.loadReportFragment && document.getElementById('reportAsyncRoot')) window.loadReportFragment(url);
  else window.location.href = window.appUrl(url);
}
var RF = { go: function(k, v) { var p = new URLSearchParams(window.location.search); if (v) p.set(k, v); else p.delete(k); p.delete('offset'); reportNavigate(p); } };
function applyReportColumns(){
  var keys = Array.from(document.querySelectorAll('.col-toggle:checked')).map(function(i){return i.value;});
  var p = new URLSearchParams(window.location.search);
  if (keys.length) p.set('cols', keys.join(',')); else p.delete('cols');
  p.delete('offset');
  reportNavigate(p);
}
function resetReportColumns(){
  var p = new URLSearchParams(window.location.search);
  p.delete('cols'); p.delete('offset');
  reportNavigate(p);
}

function initReportResize(){
  var table=document.getElementById('reportTable');
  var cg=document.getElementById('reportColGroup');
  if(!table||!cg) return;
  var key='finance_report_col_widths_v21';
  var saved={};
  try{ saved=JSON.parse(localStorage.getItem(key)||'{}'); }catch(e){ saved={}; }
  Array.from(cg.children).forEach(function(col){
    var name=col.getAttribute('data-col');
    if(saved[name]) col.style.width=saved[name]+'px';
  });
  table.querySelectorAll('th[data-rcol]').forEach(function(th){
    var name=th.getAttribute('data-rcol');
    var handle=th.querySelector('.r-resize');
    if(!handle) return;
    handle.addEventListener('mousedown',function(e){
      e.preventDefault(); e.stopPropagation();
      var col=Array.from(cg.children).find(function(c){return c.getAttribute('data-col')===name;});
      var startX=e.pageX;
      var startW=col ? col.getBoundingClientRect().width : th.getBoundingClientRect().width;
      document.body.style.cursor='col-resize';
      function move(ev){
        var w=Math.max(50, Math.round(startW + (ev.pageX-startX)));
        if(col) col.style.width=w+'px';
        saved[name]=w;
      }
      function up(){
        document.removeEventListener('mousemove',move);
        document.removeEventListener('mouseup',up);
        document.body.style.cursor='';
        try{ localStorage.setItem(key, JSON.stringify(saved)); }catch(e){}
      }
      document.addEventListener('mousemove',move);
      document.addEventListener('mouseup',up);
    });
  });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initReportResize); else initReportResize();

function initReportFilterOptions(){
  var timers = new WeakMap();
  var controllers = new WeakMap();
  var optionCache = new Map();
  function getMenu(input){ return document.getElementById(input.dataset.menu || ''); }
  function closeAll(except){
    document.querySelectorAll('.report-combo-menu.open').forEach(function(m){ if(m !== except) m.classList.remove('open'); });
  }
  function showMenu(input){ var m=getMenu(input); if(!m) return; closeAll(m); m.classList.add('open'); }
  function hideMenu(input){ var m=getMenu(input); if(m) m.classList.remove('open'); }
  function fillList(input, values){
    var menu = getMenu(input); if (!menu) return;
    var cur = input.value || '';
    var seen = {}; var html = '';
    function add(v){ v = String(v == null ? '' : v); if(!v || seen[v]) return; seen[v]=1; var label = v.length > 96 ? v.slice(0,96)+'…' : v; html += '<button type="button" data-value="'+v.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')+'">'+label.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</button>'; }
    if (cur) add(cur);
    (values||[]).forEach(add);
    menu.innerHTML = html || '<em>Ничего не найдено</em>';
    menu.querySelectorAll('button[data-value]').forEach(function(btn){
      btn.addEventListener('mousedown', function(e){ e.preventDefault(); input.value = btn.dataset.value || ''; RF.go(input.dataset.filterField, input.value); });
    });
    showMenu(input);
  }
  function load(input){
    if (!input || input.dataset.loading === '1') return;
    var field = input.dataset.filterField || '';
    if (!field) return;
    var p = new URLSearchParams(window.location.search);
    p.set('page','report'); p.set('action','report_filter_options'); p.set('field',field); p.set('limit','180');
    if ((input.value||'').trim()) p.set('q', input.value.trim()); else p.delete('q');
    var cacheKey = field + '|' + ((input.value||'').trim());
    if (optionCache.has(cacheKey)) { fillList(input, optionCache.get(cacheKey)); return; }
    if (controllers.has(input)) { try { controllers.get(input).abort(); } catch(e){} }
    var ac = window.AbortController ? new AbortController() : null;
    if (ac) controllers.set(input, ac);
    input.dataset.loading = '1';
    var fetchOpts = {headers:{'Accept':'application/json','X-Requested-With':'fetch'}, cache:'no-store'};
    if (ac) fetchOpts.signal = ac.signal;
    fetch(window.appUrl('?' + p.toString()), fetchOpts)
      .then(function(r){return window.parseAppJsonResponse ? window.parseAppJsonResponse(r, window.cleanAppUrlForPage('?page=report', true), {silentReload:false}) : r.json();})
      .then(function(j){ var vals = (j && j.ok) ? (j.values || []) : []; optionCache.set(cacheKey, vals); fillList(input, vals); })
      .catch(function(e){ if (e && e.name === 'AbortError') return; fillList(input, []); })
      .finally(function(){ input.dataset.loading = '0'; });
  }
  document.querySelectorAll('.report-combo-input').forEach(function(input){
    if (input.dataset.lazyBound === '1') return;
    input.dataset.lazyBound = '1';
    var menu = getMenu(input);
    if (menu) {
      menu.querySelectorAll('button[data-value]').forEach(function(btn){ btn.addEventListener('mousedown', function(e){ e.preventDefault(); input.value = btn.dataset.value || ''; RF.go(input.dataset.filterField, input.value); }); });
    }
    input.addEventListener('focus', function(){ load(input); });
    input.addEventListener('input', function(){ clearTimeout(timers.get(input)); timers.set(input, setTimeout(function(){ load(input); }, 260)); });
    input.addEventListener('blur', function(){ setTimeout(function(){ hideMenu(input); }, 180); });
  });
  document.addEventListener('mousedown', function(e){ if(!e.target.closest || !e.target.closest('.report-combo-wrap')) closeAll(); });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initReportFilterOptions); else initReportFilterOptions();

async function saveReportRow(btn) {
  var tr = btn.closest('tr');
  var fd = new FormData();
  fd.set('id', tr.dataset.payId);
  tr.querySelectorAll('[data-field]').forEach(function(el){ fd.set(el.dataset.field, el.value || ''); });
  var typeEl = tr.querySelector('[data-field="type"]');
  var type = typeEl ? typeEl.value : '';
  var debitEl = tr.querySelector('.amount-debit');
  var creditEl = tr.querySelector('.amount-credit');
  var debit = debitEl ? debitEl.value : '';
  var credit = creditEl ? creditEl.value : '';
  if (debitEl || creditEl) {
    if (!type) type = debit ? 'income' : 'expense';
    fd.set('type', type);
    fd.set('amount', type === 'income' ? (debit || credit || '0') : (credit || debit || '0'));
  }
  btn.disabled = true; var old = btn.textContent; btn.textContent = '...';
  try {
    var r = await fetch(window.appUrl('?action=update_report_payment'), {method:'POST', body:fd, credentials:'include'});
    var d = window.parseAppJsonResponse ? await window.parseAppJsonResponse(r, window.cleanAppUrlForPage('?page=report', true), {silentReload:false}) : await r.json();
    if (d.ok) { btn.textContent = '✅'; setTimeout(function(){ if(window.loadReportFragment) window.loadReportFragment(); else location.reload(); }, 250); }
    else { alert('Ошибка: ' + (d.error || 'не удалось сохранить')); btn.textContent = old; btn.disabled = false; }
  } catch(e) { alert('Ошибка: ' + e.message); btn.textContent = old; btn.disabled = false; }
}
</script>
