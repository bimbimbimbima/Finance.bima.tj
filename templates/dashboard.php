<?php
if (empty($portal)) {
    echo "<div style='color:red;padding:20px'>Ошибка: portal не определён.</div>";
    return;
}

$year   = (int)($_GET['year'] ?? date('Y'));
$selectedCatGroup = trim((string)($_GET['cat_group'] ?? ''));

$accessIds = $isManager ? null : ($allowedAccountIds ?? []);
$months = Payment::chartByMonth($portal, $year, $accessIds);
$chart  = [];
for ($i = 1; $i <= 12; $i++) $chart[$i] = ['income' => 0, 'expense' => 0];
foreach ($months as $r) $chart[(int)$r['month']][$r['type']] = (float)$r['total'];

$totals   = Payment::totals($portal, $year . '-01-01', $year . '-12-31', $accessIds);
$accounts = Payment::accountBalances($portal, $accessIds);
$catGroups = Payment::categoryGroups($portal, $year . '-01-01', $year . '-12-31', 'expense', $accessIds);

$activeGroup = null;
if ($selectedCatGroup !== '') {
    foreach ($catGroups as $g) {
        if ($g['name'] === $selectedCatGroup) { $activeGroup = $g; break; }
    }
}
$cats = $activeGroup ? ($activeGroup['children'] ?? []) : $catGroups;

$thisMonth = date('Y-m');
$mTotals   = Payment::totals($portal, $thisMonth . '-01', date('Y-m-t'), $accessIds);

$approvalStats = ['active'=>0,'completed'=>0,'amount_active'=>0,'amount_completed'=>0];
try {
    $row = DB::fetchOne("SELECT 
        SUM(CASE WHEN current_stage NOT IN ('completed','rejected') THEN 1 ELSE 0 END) active,
        SUM(CASE WHEN current_stage='completed' THEN 1 ELSE 0 END) completed,
        SUM(CASE WHEN current_stage NOT IN ('completed','rejected') THEN amount ELSE 0 END) amount_active,
        SUM(CASE WHEN current_stage='completed' THEN amount ELSE 0 END) amount_completed
        FROM approval_requests WHERE portal=?", [$portal]);
    if ($row) $approvalStats = array_merge($approvalStats, array_map('floatval', $row));
} catch (Throwable $e) { /* approval module may be not installed */ }
$maxMonth  = 0;
foreach ($chart as $m) $maxMonth = max($maxMonth, $m['income'], $m['expense']);
$maxMonth = $maxMonth ?: 1;
$maxCat = 0;
foreach ($cats as $c) $maxCat = max($maxCat, (float)$c['total']);
$maxCat = $maxCat ?: 1;
$monthNames = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
?>

<div class="page-header">
  <h1>📊 Финансовый дашборд</h1>
  <div class="year-switcher">
    <a href="<?=app_link(['page'=>'dashboard','year'=>$year-1,'cat_group'=>$selectedCatGroup])?>" class="btn-sm">◀</a>
    <span><?=$year?></span>
    <a href="<?=app_link(['page'=>'dashboard','year'=>$year+1,'cat_group'=>$selectedCatGroup])?>" class="btn-sm">▶</a>
  </div>
</div>

<div class="cards">
  <div class="card income">
    <div class="card-label">Приход за год</div>
    <div class="card-value"><?=number_format($totals['income'],  2, '.', ' ')?> <span><?=CURRENCY?></span></div>
  </div>
  <div class="card expense">
    <div class="card-label">Расход за год</div>
    <div class="card-value"><?=number_format($totals['expense'], 2, '.', ' ')?> <span><?=CURRENCY?></span></div>
  </div>
  <div class="card profit <?=$totals['profit'] >= 0 ? 'positive' : 'negative'?>">
    <div class="card-label">Прибыль за год</div>
    <div class="card-value"><?=number_format($totals['profit'],  2, '.', ' ')?> <span><?=CURRENCY?></span></div>
  </div>
  <div class="card neutral">
    <div class="card-label">Приход этот месяц</div>
    <div class="card-value"><?=number_format($mTotals['income'], 2, '.', ' ')?> <span><?=CURRENCY?></span></div>
  </div>
  <div class="card neutral approval-card">
    <div class="card-label">Заявки на согласовании</div>
    <div class="card-value"><?=(int)$approvalStats['active']?> <span>шт.</span></div>
    <div class="card-sub"><?=number_format((float)$approvalStats['amount_active'], 2, '.', ' ')?> <?=CURRENCY?></div>
  </div>
  <div class="card income approval-card">
    <div class="card-label">Согласовано заявок</div>
    <div class="card-value"><?=(int)$approvalStats['completed']?> <span>шт.</span></div>
    <div class="card-sub"><?=number_format((float)$approvalStats['amount_completed'], 2, '.', ' ')?> <?=CURRENCY?></div>
  </div>
</div>

<div class="charts-row">
  <div class="chart-box wide">
    <div class="chart-title">Динамика по месяцам</div>
    <div class="css-month-chart" aria-label="Динамика по месяцам">
      <?php $i=0; foreach ($chart as $m): $i++; ?>
      <div class="cm-col" title="<?=$monthNames[$i-1]?>: приход <?=number_format($m['income'],2,'.',' ')?>, расход <?=number_format($m['expense'],2,'.',' ')?>">
        <div class="cm-bars">
          <span class="cm-bar cm-income" style="height:<?=max(3, round($m['income']/$maxMonth*100))?>%"></span>
          <span class="cm-bar cm-expense" style="height:<?=max(3, round($m['expense']/$maxMonth*100))?>%"></span>
        </div>
        <div class="cm-label"><?=$monthNames[$i-1]?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="chart-legend"><span class="lg-income"></span> Приход <span class="lg-expense"></span> Расход</div>
  </div>

  <div class="chart-box cat-box">
    <div class="chart-title cat-title-row">
      <span><?=$activeGroup ? 'Состав категории: '.htmlspecialchars($activeGroup['name']) : 'Расходы по категориям'?></span>
      <?php if ($activeGroup): ?>
        <a class="cat-reset" href="<?=app_link(['page'=>'dashboard','year'=>$year])?>">Все</a>
      <?php endif; ?>
    </div>
    <form method="GET" class="cat-filter">
      <input type="hidden" name="page" value="dashboard">
      <input type="hidden" name="year" value="<?=$year?>">
      <select name="cat_group" onchange="this.form.requestSubmit?this.form.requestSubmit():this.form.submit()">
        <option value="">Все категории</option>
        <?php foreach ($catGroups as $g): ?>
          <option value="<?=htmlspecialchars($g['name'])?>" <?=$selectedCatGroup===$g['name']?'selected':''?>><?=htmlspecialchars($g['name'])?> — <?=number_format($g['total'],2,'.',' ')?> <?=CURRENCY?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if (empty($cats)): ?>
      <div class="empty-mini">Нет расходов за выбранный год</div>
    <?php else: ?>
      <div class="cat-list">
      <?php foreach ($cats as $c):
        $name = (string)$c['name'];
        $code = $c['code'] ?? '';
        $sum = (float)$c['total'];
        $pct = max(2, round($sum/$maxCat*100));
        $label = $activeGroup && $code ? ($code . ' - ' . $name) : $name;
      ?>
        <div class="cat-row" title="<?=htmlspecialchars($label)?> — <?=number_format($sum,2,'.',' ')?> <?=CURRENCY?>">
          <div class="cat-name"><?=htmlspecialchars($label)?></div>
          <div class="cat-val"><?=number_format($sum,2,'.',' ')?> <?=CURRENCY?></div>
          <div class="cat-bar"><span style="width:<?=$pct?>%"></span></div>
          <?php if (!$activeGroup && !empty($c['children'])): ?>
            <div class="cat-children"><?=count($c['children'])?> подстатей</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($accounts)): ?>
<div class="section-title">Остатки по счетам</div>
<div class="accounts-table">
  <table>
    <thead><tr><th class="acc-name-th">Счёт / Касса</th><th class="acc-balance-th">Остаток</th></tr></thead>
    <tbody>
    <?php foreach ($accounts as $a): ?>
    <tr>
      <td class="acc-name-td"><?=htmlspecialchars($a['name'])?></td>
      <td class="acc-balance-td <?=$a['balance'] >= 0 ? 'text-income' : 'text-expense'?>">
        <?=number_format($a['balance'], 2, '.', ' ')?> <?=CURRENCY?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<style>
.css-month-chart{height:250px;display:grid;grid-template-columns:repeat(12,1fr);gap:12px;align-items:end;padding:16px 12px 8px;border:1px solid #edf1f5;border-radius:10px;background:#fff}
.cm-col{height:100%;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;min-width:0}
.cm-bars{height:205px;width:100%;display:flex;align-items:flex-end;justify-content:center;gap:5px;border-bottom:1px solid #dfe5ec}
.cm-bar{display:block;width:14px;border-radius:5px 5px 0 0;transition:.15s}.cm-income{background:#16a34a}.cm-expense{background:#ef4444}.cm-label{font-size:12px;color:#64748b;margin-top:6px;white-space:nowrap}.chart-legend{display:flex;align-items:center;gap:8px;margin-top:10px;font-size:12px;color:#64748b;justify-content:center}.chart-legend span{width:12px;height:12px;border-radius:3px;display:inline-block}.lg-income{background:#16a34a}.lg-expense{background:#ef4444}
.cat-box{min-width:520px;flex:1.25}.cat-title-row{display:flex;align-items:center;justify-content:space-between;gap:10px}.cat-filter{margin:0 0 10px}.cat-filter select{width:100%;font-size:12px}.cat-reset{font-size:12px;color:#2563eb;text-decoration:none}.cat-list{max-height:380px;overflow:auto;padding-right:6px}.cat-row{display:grid;grid-template-columns:minmax(260px,1fr) auto;gap:6px 12px;padding:9px 0;border-bottom:1px solid #eef2f7}.cat-row:last-child{border-bottom:none}.cat-name{font-size:12px;color:#0f172a;white-space:normal;line-height:1.35;overflow:visible;overflow-wrap:anywhere;word-break:break-word}.cat-val{font-size:12px;color:#475569;font-weight:600;white-space:nowrap;text-align:right}.cat-bar{grid-column:1 / -1;height:7px;background:#eef2f7;border-radius:999px;overflow:hidden}.cat-bar span{display:block;height:100%;background:#2563eb;border-radius:999px}.cat-children{grid-column:1 / -1;font-size:11px;color:#64748b}.empty-mini{padding:30px;text-align:center;color:#64748b;font-size:13px;background:#f8fafc;border-radius:10px}
@media(max-width:900px){.css-month-chart{gap:6px}.cm-bar{width:10px}.cat-box{min-width:260px}}
.approval-card .card-sub{font-size:12px;color:#64748b;margin-top:5px;font-weight:600}.accounts-table th{padding:12px 14px;text-align:left;background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#334155;font-size:13px}.accounts-table .acc-balance-th{text-align:right}.accounts-table .acc-name-td{font-weight:600;color:#0f172a}.accounts-table .acc-balance-td{text-align:right;font-variant-numeric:tabular-nums;font-weight:800;white-space:nowrap}
</style>
