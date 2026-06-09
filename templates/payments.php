<?php
$accessIds = $isManager ? null : ($allowedAccountIds ?? []);
$payPerPage = max(10, min(500, (int)($_GET['pay_per_page'] ?? 100)));
$payPage = max(1, (int)($_GET['pay_page'] ?? 1));
$filterForCount = $filter;
$filterForCount['allowed_account_ids'] = $accessIds;
$payTotal = Payment::count($portal, $filterForCount);
$payPages = max(1, (int)ceil($payTotal / $payPerPage));
if ($payPage > $payPages) $payPage = $payPages;
$filter['limit'] = $payPerPage;
$filter['offset'] = ($payPage - 1) * $payPerPage;
$payments = Payment::list($portal, $filter);
$totals   = Payment::totals($portal, $filter['date_from'], $filter['date_to'], $accessIds);
// v21: статусы скрыты из интерфейса платежей; операции показываются единым списком
$pending  = [];
$paymentIdsForHistory = []; // v45: история платежей показывается независимо от выбранного периода и текущей страницы
$payHistLimit = max(10, min(100, (int)($_GET['pay_hist_limit'] ?? 20)));
$payHistPage = max(1, (int)($_GET['pay_hist_page'] ?? 1));
$payHistTotal = Payment::historyCount($portal, $paymentIdsForHistory);
$payHistPages = max(1, (int)ceil($payHistTotal / $payHistLimit));
if ($payHistPage > $payHistPages) $payHistPage = $payHistPages;
$paymentHistory = Payment::historyRows($portal, $paymentIdsForHistory, $payHistLimit, ($payHistPage - 1) * $payHistLimit);

function pay_clear_btn($key, $active) {
    return $active ? '<button type="button" class="filter-x" onclick="clearOneFilter(\''.$key.'\')" title="Сбросить этот фильтр">×</button>' : '';
}
function pay_status_norm($status) {
    $s = mb_strtolower(trim((string)$status));
    if ($s === '' || in_array($s, ['approved','подтверждено','confirm','confirmed'], true)) return 'approved';
    if (in_array($s, ['pending','на согласовании','согласование'], true)) return 'pending';
    if (in_array($s, ['paid','выплачено','оплачено'], true)) return 'paid';
    if (in_array($s, ['rejected','отклонено','отклонён','отклонен'], true)) return 'rejected';
    return in_array($s, ['approved','pending','paid','rejected'], true) ? $s : 'approved';
}
function pay_page_link($page, $perPage = null) {
    $params = $_GET;
    $params['page'] = 'payments';
    $params['pay_page'] = max(1, (int)$page);
    if ($perPage !== null) $params['pay_per_page'] = (int)$perPage;
    return '?' . http_build_query($params);
}
function pay_pager_html($cur, $pages, $total, $perPage) {
    if ($pages <= 1 && $total <= $perPage) return '';
    $from = $total ? (($cur - 1) * $perPage + 1) : 0;
    $to = min($total, $cur * $perPage);
    $html = '<div class="pager-bar"><div class="pager-info">Показано <b>'.$from.'–'.$to.'</b> из <b>'.$total.'</b></div><div class="pager-pages">';
    $start = max(1, $cur - 2); $end = min($pages, $cur + 2);
    if ($cur > 1) $html .= '<a class="pager-btn" href="'.htmlspecialchars(pay_page_link($cur-1)).'">‹</a>';
    if ($start > 1) { $html .= '<a class="pager-btn" href="'.htmlspecialchars(pay_page_link(1)).'">1</a>'; if ($start > 2) $html .= '<span class="pager-ellipsis">…</span>'; }
    for ($i=$start; $i<=$end; $i++) $html .= '<a class="pager-btn '.($i===$cur?'on':'').'" href="'.htmlspecialchars(pay_page_link($i)).'">'.$i.'</a>';
    if ($end < $pages) { if ($end < $pages-1) $html .= '<span class="pager-ellipsis">…</span>'; $html .= '<a class="pager-btn" href="'.htmlspecialchars(pay_page_link($pages)).'">'.$pages.'</a>'; }
    if ($cur < $pages) $html .= '<a class="pager-btn" href="'.htmlspecialchars(pay_page_link($cur+1)).'">›</a>';
    $html .= '</div></div>';
    return $html;
}
function pay_hist_link($page) {
    $q = $_GET; $q['page'] = 'payments'; $q['pay_hist_page'] = max(1,(int)$page);
    return '?' . http_build_query($q);
}
function pay_history_pager_html($cur,$pages,$total,$limit){
    if($pages<=1 && $total<=$limit) return '';
    $from=$total?(($cur-1)*$limit+1):0; $to=min($total,$cur*$limit);
    $html='<div class="hist-pager"><span>Показано <b>'.$from.'–'.$to.'</b> из <b>'.$total.'</b></span><div>';
    if($cur>1) $html.='<a href="'.htmlspecialchars(pay_hist_link($cur-1)).'">‹</a>';
    $start=max(1,$cur-2); $end=min($pages,$cur+2);
    if($start>1) $html.='<a href="'.htmlspecialchars(pay_hist_link(1)).'">1</a><em>…</em>';
    for($i=$start;$i<=$end;$i++) $html.='<a class="'.($i===$cur?'on':'').'" href="'.htmlspecialchars(pay_hist_link($i)).'">'.$i.'</a>';
    if($end<$pages) $html.='<em>…</em><a href="'.htmlspecialchars(pay_hist_link($pages)).'">'.$pages.'</a>';
    if($cur<$pages) $html.='<a href="'.htmlspecialchars(pay_hist_link($cur+1)).'">›</a>';
    return $html.'</div></div>';
}
?>

<div class="page-header">
  <h1>📋 Платежи</h1>
  <div class="header-actions">
    <a href="<?=app_link(array_merge($_GET,['page'=>'payments','action'=>'export_excel']))?>" class="btn btn-primary">⬇ Excel</a>
    <a href="<?=app_link(['page'=>'add'])?>" class="btn btn-primary">+ Добавить</a>
    <button type="button" class="btn btn-secondary" id="btnDelSelected" style="display:none;background:#dc2626;border-color:#dc2626" onclick="deleteSelected()">🗑 Удалить выбранные</button>
  </div>
</div>


<!-- Фильтр -->
<form method="GET" class="filter-bar">
  <input type="hidden" name="page" value="payments">
  <div class="filter-group"><label>С</label>
    <div class="filter-inline"><input type="date" name="date_from" value="<?=htmlspecialchars($filter['date_from'])?>"><?=pay_clear_btn('date_from', !empty($_GET['date_from']))?></div>
  </div>
  <div class="filter-group"><label>По</label>
    <div class="filter-inline"><input type="date" name="date_to" value="<?=htmlspecialchars($filter['date_to'])?>"><?=pay_clear_btn('date_to', !empty($_GET['date_to']))?></div>
  </div>
  <div class="filter-group"><label>Тип</label>
    <div class="filter-inline"><select name="type">
      <option value="">Все</option>
      <option value="income"  <?=($filter['type']==='income') ?'selected':''?>>Приход</option>
      <option value="expense" <?=($filter['type']==='expense')?'selected':''?>>Расход</option>
    </select><?=pay_clear_btn('type', !empty($filter['type']))?></div>
  </div>
  <div class="filter-group"><label>Счёт</label>
    <div class="filter-inline"><select name="account_id">
      <option value="">Все</option>
      <?php foreach ($accounts as $a): ?>
      <option value="<?=$a['id']?>" <?=($filter['account_id']==$a['id'])?'selected':''?>><?=htmlspecialchars($a['name'])?></option>
      <?php endforeach; ?>
    </select><?=pay_clear_btn('account_id', !empty($filter['account_id']))?></div>
  </div>
  <div class="filter-group"><label>Показать</label>
    <select name="pay_per_page" onchange="this.form.pay_page.value=1;(this.form.requestSubmit?this.form.requestSubmit():this.form.submit())">
      <?php foreach ([25,50,100,200,500] as $n): ?><option value="<?=$n?>" <?=$payPerPage===$n?'selected':''?>><?=$n?></option><?php endforeach; ?>
    </select>
  </div>
  <input type="hidden" name="pay_page" value="<?=$payPage?>">
  <button type="submit" class="btn btn-primary">Применить</button>
  <a href="?page=payments" class="btn btn-light">Сброс всех</a>
</form>

<!-- Итоги -->
<div class="summary-bar">
  <span class="text-income">Приход: <strong><?=number_format($totals['income'],2,'.',' ')?> <?=CURRENCY?></strong></span>
  <span class="text-expense">Расход: <strong><?=number_format($totals['expense'],2,'.',' ')?> <?=CURRENCY?></strong></span>
  <span>Прибыль: <strong class="<?=$totals['profit']>=0?'text-income':'text-expense'?>"><?=number_format($totals['profit'],2,'.',' ')?> <?=CURRENCY?></strong></span>
  <span class="count">Записей: <?=$payTotal?></span>
  <label style="margin-left:auto;display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:var(--color-text-secondary)">
    <input type="checkbox" id="checkAll" onchange="toggleAll(this)"> Выбрать все
  </label>
</div>

<details class="history-panel payment-history-panel">
  <summary>История добавления и изменений платежей</summary>
  <div class="history-toolbar">
    <span>Показать строк:</span>
    <select onchange="payHistoryLimit(this.value)">
      <?php foreach([10,20,50,100] as $hl): ?><option value="<?=$hl?>" <?=$payHistLimit===$hl?'selected':''?>><?=$hl?></option><?php endforeach; ?>
    </select>
  </div>
  <?php if (empty($paymentHistory)): ?>
    <div class="history-empty">История платежей пока пустая.</div>
  <?php else: ?>
    <div class="history-list">
      <?php foreach ($paymentHistory as $h): ?>
        <div class="history-item">
          <div class="history-top">
            <b><?=htmlspecialchars(Payment::historyActionLabel($h['action']))?></b>
            <span><?=htmlspecialchars(date('d.m.Y H:i', strtotime($h['created_at'])))?></span>
          </div>
          <div class="history-meta">
            Операция #<?=htmlspecialchars((string)($h['payment_id'] ?: '—'))?> · <?=htmlspecialchars($h['actor_name'] ?: 'Система')?>
          </div>
          <?php if (!empty($h['comment'])): ?><div class="history-comment"><?=htmlspecialchars($h['comment'])?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?=pay_history_pager_html($payHistPage,$payHistPages,$payHistTotal,$payHistLimit)?>
  <?php endif; ?>
</details>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success">Запись(и) удалены.</div>
<?php endif; ?>

<?php if (empty($payments)): ?>
<div class="empty-state"><p>За выбранный период нет данных.</p><a href="<?=app_link(['page'=>'add'])?>" class="btn btn-primary">Добавить первый платёж</a></div>
<?php else: ?>
<?=pay_pager_html($payPage, $payPages, $payTotal, $payPerPage)?>
<div class="tbl-wrap payment-table-wrap" style="overflow-x:auto">
<table class="data-table payments-report-table" style="min-width:1280px;font-size:12px">
  <thead>
    <tr>
      <th style="width:30px"><input type="checkbox" id="checkAll2" onchange="toggleAll(this)"></th>
      <th>Дата</th>
      <th>Дебет</th>
      <th>Кредит</th>
      <th>Вал.</th>
      <th>Курс</th>
      <th>Описание</th>
      <th>Получатель</th>
      <th>Ссылка</th>
      <th>Код</th>
      <th>Статья / Тип операции</th>
      <th>Отдел</th>
      <th>Банк/Касса</th>
      <th>Регион</th>
      <th>Подотдел</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($payments as $p):
    $isIncome = (($p['effective_type'] ?? $p['type']) === 'income');
    $opType   = $p['operation_type'] ?? $p['type'];
    $st       = pay_status_norm($p['status'] ?? 'approved');
    $catName  = $p['category_name']??'';
    $code     = $p['operation_code']??'';
    $opName   = $p['operation_type_name']??'';
    if(!$code&&$catName&&preg_match('/^(\d+)\s*[-–]\s*(.+)$/',$catName,$m)){$code=$m[1];$opName=$m[2];}
    elseif(!$opName){$opName=$catName;}
    $desc=$p['description']??$p['comment']??'';
    $rec=$p['recipient_name']??$p['company']??'';
    $link=$p['transaction_link']??'';
    $cur=$p['currency']??'TJS';
    $rate=$p['exchange_rate']??1;
    $rowBg=$st==='pending'?'background:#fefce8':($st==='paid'?'background:#f0fdf4':($st==='rejected'?'opacity:.6':''));
  ?>
  <tr class="<?=$st==='rejected'?'pay-row-rejected':''?>" style="<?=$rowBg?>">
    <td><input type="checkbox" class="pay-chk" value="<?=$p['id']?>"></td>
    <td style="white-space:nowrap"><?=date('d.m.Y',strtotime($p['date']))?></td>
    <!-- Дебет -->
    <td style="background:#E8F5E9;text-align:right;font-weight:<?=$isIncome?'600':'400'?>;color:<?=$isIncome?'#1B5E20':'#aaa'?>">
      <?=$isIncome?number_format((float)($p['amount_effective'] ?? $p['amount']),2,'.',' '):''?>
    </td>
    <!-- Кредит -->
    <td style="background:#FFEBEE;text-align:right;font-weight:<?=!$isIncome?'600':'400'?>;color:<?=!$isIncome?'#B71C1C':'#aaa'?>">
      <?=!$isIncome?number_format((float)($p['amount_effective'] ?? $p['amount']),2,'.',' '):''?>
    </td>
    <td style="text-align:center"><?=$cur?></td>
    <td style="text-align:right"><?=$rate!=1?number_format($rate,2,'.',','):'1'?></td>
    <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=htmlspecialchars($desc)?>"><?=htmlspecialchars($desc)?></td>
    <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=htmlspecialchars($rec)?>"><?=htmlspecialchars($rec)?></td>
    <td style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=htmlspecialchars($link)?>"><?=htmlspecialchars($link)?></td>
    <td style="text-align:center;font-weight:700"><?=$code?></td>
    <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=htmlspecialchars($opName)?>"><?=htmlspecialchars($opName)?></td>
    <td style="text-align:center"><?=htmlspecialchars($p['department']??'')?></td>
    <td style="text-align:center">
      <?=htmlspecialchars($p['account_name']??'')?>
      <?php if(!empty($p['transfer_to_name']) && ($isManager || in_array((int)($p['transfer_to'] ?? 0), $allowedAccountIds ?? [], true))): ?> → <?=htmlspecialchars($p['transfer_to_name'])?><?php endif; ?>
    </td>
    <td style="text-align:center"><?=$p['region']??0?></td>
    <td style="text-align:center"><?=htmlspecialchars($p['sub_department']??'')?></td>
    <td>
      <a href="?action=delete_payment&id=<?=$p['id']?>" class="btn-delete" onclick="return confirm('Удалить запись?')">✕</a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?=pay_pager_html($payPage, $payPages, $payTotal, $payPerPage)?>
<?php endif; ?>

<style>
.pay-row-rejected td{text-decoration:line-through;color:#94a3b8!important}.pay-row-rejected{background:#f8fafc!important;opacity:.65}.hist-pager{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-top:10px;font-size:12px;color:#64748b;flex-wrap:wrap}.hist-pager div{display:flex;gap:4px;align-items:center}.hist-pager a{padding:5px 9px;border:1px solid #dbe3ef;border-radius:8px;background:#fff;color:#0f172a;text-decoration:none}.hist-pager a.on{background:#2563eb;color:#fff;border-color:#2563eb}.hist-pager em{font-style:normal;color:#94a3b8;padding:0 3px}
</style>

<script>
// Чекбоксы
function toggleAll(src) {
  document.querySelectorAll('.pay-chk').forEach(function(c){ c.checked=src.checked; });
  document.getElementById('checkAll').checked = src.checked;
  document.getElementById('checkAll2').checked = src.checked;
  updateDelBtn();
}
document.querySelectorAll('.pay-chk').forEach(function(c){
  c.addEventListener('change', updateDelBtn);
});
function updateDelBtn() {
  var cnt = document.querySelectorAll('.pay-chk:checked').length;
  var btn = document.getElementById('btnDelSelected');
  if (btn) { btn.style.display = cnt>0 ? '' : 'none'; btn.textContent = '🗑 Удалить выбранные ('+cnt+')'; }
}

function payHistoryLimit(v) {
  var p = new URLSearchParams(window.location.search);
  p.set('page','payments');
  p.set('pay_hist_limit', v);
  p.set('pay_hist_page', '1');
  window.location.href = window.appUrl('?' + p.toString());
}

// Удалить выбранные
async function deleteSelected() {
  var ids = Array.from(document.querySelectorAll('.pay-chk:checked')).map(function(c){ return c.value; });
  if (!ids.length) return;
  if (!confirm('Удалить '+ids.length+' записей?')) return;
  try {
    var fd = new FormData();
    ids.forEach(function(id){ fd.append('ids[]',id); });
    var r = await fetch('?action=delete_multiple', {method:'POST',body:fd});
    var d = await r.json();
    if (d.ok) window.location.href=window.appUrl('?page=payments&deleted=1');
    else alert('Ошибка удаления');
  } catch(e) { window.location.href=window.appUrl('?page=payments&deleted=1'); }
}

</script>
