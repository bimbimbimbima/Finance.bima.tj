<?php
require_once dirname(__DIR__) . '/src/Approval.php';

$currentUser = $GLOBALS['currentUser'] ?? [];
$userId = (int)($GLOBALS['userId'] ?? 0);
$userName = trim(($currentUser['LAST_NAME'] ?? '') . ' ' . ($currentUser['NAME'] ?? '')) ?: ('User #' . $userId);
$isManager = !empty($GLOBALS['isManager']);
$userPos = $GLOBALS['userPos'] ?? '';
$mode = preg_replace('/[^a-z_]/', '', $_GET['mode'] ?? 'waiting');
if (!in_array($mode, ['waiting','mine','all','new'], true)) $mode = 'waiting';
$q = trim((string)($_GET['q'] ?? ''));
$detailOnly = !empty($_GET['detail_only']);
$reqPerPage = max(10, min(200, (int)($_GET['req_per_page'] ?? 25)));
$reqPage = max(1, (int)($_GET['req_page'] ?? 1));
$canViewAllRequests = AccessControl::hasFlag($portal, $userId, $userPos, 'can_view_all_requests', $isManager, $currentUser);
$canChangeRequestRoute = AccessControl::hasFlag($portal, $userId, $userPos, 'can_change_request_route', false, $currentUser);
if ($detailOnly || $mode === 'new') {
    // v108: при открытии карточки через AJAX не пересчитываем и не перерисовываем весь список.
    $reqTotal = 0;
    $reqPages = 1;
    $requests = [];
} else {
    $reqTotal = Approval::count($portal, $mode, $userId, $isManager, ['q'=>$q], $canViewAllRequests);
    $reqPages = max(1, (int)ceil($reqTotal / $reqPerPage));
    if ($reqPage > $reqPages) $reqPage = $reqPages;
    $requests = Approval::list($portal, $mode, $userId, $isManager, ['q'=>$q, 'limit'=>$reqPerPage, 'offset'=>($reqPage-1)*$reqPerPage], $canViewAllRequests);
}
$routeSettings = Approval::getRouteSettings($portal);
$categories = DB::fetchAll("SELECT * FROM categories WHERE portal=? ORDER BY type,name", [$portal]);
$accounts = $GLOBALS['accounts'] ?? DB::fetchAll("SELECT * FROM accounts WHERE portal=? ORDER BY name", [$portal]);
$deptRows = DB::fetchAll("SELECT * FROM departments WHERE portal=? ORDER BY parent,name", [$portal]);
$deptMain = array_values(array_filter($deptRows, static fn($d)=>empty($d['parent'])));
$deptSub  = array_values(array_filter($deptRows, static fn($d)=>!empty($d['parent'])));
$canCreateRequest = AccessControl::hasFlag($portal, $userId, $userPos, 'can_create_request', $isManager, $currentUser);
$canDeleteRequest = AccessControl::hasFlag($portal, $userId, $userPos, 'can_delete_request', $isManager, $currentUser);
$canFinanceControl = AccessControl::hasStrictFlag($portal, $userId, $userPos, 'can_change_request_route');
$waitingCount = Approval::waitingCount($portal, $userId, $isManager);
$detailId = (int)($_GET['id'] ?? 0);
$requestIds = array_values(array_map(static fn($r)=>(int)$r['id'], $requests));
if (!$detailOnly && $detailId && $mode !== 'new' && !in_array($detailId, $requestIds, true)) {
    $detailId = 0;
}
$detail = $detailId ? Approval::getLight($portal, $detailId) : null;
$detailAllowed = $detail ? Approval::canView($portal, $detail, $userId, $isManager, $canViewAllRequests) : false;
$detailApprovers = ($detail && $detailAllowed) ? Approval::routeTimelineForRequest($portal, $detail, Approval::approvers($detailId)) : [];
$detailHistory = ($detail && $detailAllowed) ? Approval::historyRows($detailId) : [];
$isClosedDetail = $detail && in_array((string)($detail['current_stage'] ?? ''), ['completed','rejected'], true);
$latestReworkComment = '';
if ($detailHistory) {
    foreach ($detailHistory as $h) {
        if (($h['action'] ?? '') === 'rework' && trim((string)($h['comment'] ?? '')) !== '') {
            $latestReworkComment = trim((string)$h['comment']);
            break;
        }
    }
}

$displayUserName = static function($id, $stored = '') use ($portal) {
    return Approval::userDisplayName($portal, (int)$id, (string)$stored);
};
$creatorDisplayName = $detail ? $displayUserName($detail['creator_id'] ?? 0, $detail['creator_name'] ?? '') : '';
$managerDisplayName = $detail ? $displayUserName($detail['manager_id'] ?? 0, $detail['manager_name'] ?? '') : '';
$detailCommissionGroups = commission_groups_from_detail($detail);
$detailCommissionSeriesLabel = commission_series_label_from_groups($detailCommissionGroups);
$detailCommissionTotal = commission_groups_total($detailCommissionGroups);
$detailAttachments = $detail ? Approval::attachments($detail) : [];
$detailCanActNow = ($detail && $detailAllowed) ? can_act_detail($portal, $detail, $userId, $isManager) : false;
$detailOnFinanceEditStage = $detailCanActNow && in_array((string)($detail['current_stage'] ?? ''), ['finance_route','finance_rework'], true);
$showCommissionReadOnlySummary = (($detail['request_type'] ?? '') === 'commission' && $detailCommissionGroups && !$detailOnFinanceEditStage);
$templateId = (int)($_GET['template_id'] ?? 0);
$requestTemplate = null;
if ($mode === 'new' && $templateId > 0 && $canCreateRequest) {
    $tmp = Approval::get($portal, $templateId);
    if ($tmp && (($tmp['request_type'] ?? 'regular') === 'regular') && (int)($tmp['creator_id'] ?? 0) === (int)$userId) {
        $requestTemplate = $tmp;
    }
}
$requestTemplates = ($mode === 'new' && $canCreateRequest) ? Approval::templateList($portal, $userId, $isManager, $canViewAllRequests, 18) : [];

// Не открываем автоматически последнюю/первую заявку.
// Пользователь сам нажимает на нужную строку; так в "Мои заявки" и "Все доступные" не показывается случайная последняя заявка.


function req_status_label($s) {
    $s = (string)$s;
    if (preg_match('/^waiting_extra_/u', $s)) return 'Доп. согласование';
    $map = [
        'waiting_manager'=>'На руководителе','waiting_finance_route'=>'У финансиста','waiting_department_director'=>'У директора департамента',
        'waiting_finance_final'=>'У финансиста','waiting_general_director'=>'У генерального директора','waiting_security'=>'У СБ','waiting_treasury'=>'У казначея','waiting_finance_director'=>'У финдиректора',
        'rework_creator'=>'Доработка у заявителя','rework_finance'=>'Доработка у финансиста','completed'=>'Завершено','rejected'=>'Отклонено'
    ];
    return $map[$s] ?? $s;
}
function can_act_detail($portal, $detail, $userId, $isManager) { return $detail && Approval::canAct($portal, $detail, $detail['current_stage'] ?? '', $userId, $isManager); }
function category_code_from_name($name) { return preg_match('/^\s*(\d{2,6})\s*[-–—]/u',(string)$name,$m) ? $m[1] : ''; }
function category_clean_name($name) { return preg_replace('/^\s*\d{2,6}\s*[-–—]\s*/u','',(string)$name); }
function categories_json($categories) {
    $out=[]; foreach($categories as $c){$out[]=['id'=>(int)$c['id'],'name'=>$c['name'],'code'=>category_code_from_name($c['name']),'clean'=>category_clean_name($c['name'])];}
    return json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

function commission_groups_from_detail($detail) {
    if (!$detail || (($detail['request_type'] ?? '') !== 'commission')) return [];
    foreach (['commission_groups_json','commission_rows_json'] as $field) {
        $raw = (string)($detail[$field] ?? '');
        if ($raw === '') continue;
        $parsed = json_decode($raw, true);
        if (is_array($parsed['groups'] ?? null)) return $parsed['groups'];
    }
    return [];
}
function commission_series_label_from_groups($groups) {
    $series = [];
    foreach ($groups as $g) { $s = strtoupper(trim((string)($g['series'] ?? ''))); if ($s !== '') $series[$s] = true; }
    return implode(', ', array_keys($series));
}

function commission_groups_total($groups) {
    $total = 0.0;
    foreach ((array)$groups as $g) $total += (float)($g['amount'] ?? 0);
    return round($total, 2);
}

function commission_allocation_region($allocation) {
    $a = mb_strtoupper(trim((string)$allocation));
    $a = str_replace(['Ё'], ['Е'], $a);
    if ($a === '') return '';
    if (mb_stripos($a, 'БИМА ГО') !== false || mb_stripos($a, 'BIMA GO') !== false) return '0';
    if (mb_stripos($a, 'БИМА СОГД') !== false || mb_stripos($a, 'BIMA SOGD') !== false) return '1';
    if (mb_stripos($a, 'БИМА ХАТЛОН') !== false || mb_stripos($a, 'BIMA KHATLON') !== false) return '2';
    return in_array($a, ['0','1','2'], true) ? $a : (string)$allocation;
}


$categoryJson = categories_json($categories);
$deptJson = json_encode(array_map(static fn($d)=>['id'=>(int)$d['id'],'name'=>(string)$d['name'],'parent'=>(string)($d['parent'] ?? '')], $deptRows), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$regionOptions = ['0'=>'0', '1'=>'1', '2'=>'2'];
function region_select_html($name, $current = '') {
    $opts = ['0','1','2'];
    $safeName = htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8');
    $html = '<select name="'.$safeName.'" required class="region-select">';
    $html .= '<option value="">Не выбрано</option>';
    foreach ($opts as $o) {
        $sel = ((string)$current === (string)$o) ? ' selected' : '';
        $safeValue = htmlspecialchars((string)$o, ENT_QUOTES, 'UTF-8');
        $html .= '<option value="'.$safeValue.'"'.$sel.'>Регион '.$safeValue.'</option>';
    }
    return $html . '</select>';
}
function display_region($value) {
    $v = trim((string)$value);
    return $v === '' ? 'Не выбран' : $v;
}
function req_page_link($page, $perPage = null) {
    $params = $_GET;
    $params['page'] = 'requests';
    $params['req_page'] = max(1, (int)$page);
    if ($perPage !== null) $params['req_per_page'] = (int)$perPage;
    return '?' . http_build_query($params);
}
function req_pager_html($cur, $pages, $total, $perPage) {
    if ($pages <= 1 && $total <= $perPage) return '';
    $from = $total ? (($cur - 1) * $perPage + 1) : 0;
    $to = min($total, $cur * $perPage);
    $html = '<div class="pager-bar request-pager"><div class="pager-info">Показано <b>'.$from.'–'.$to.'</b> из <b>'.$total.'</b></div><div class="pager-pages">';
    $start = max(1, $cur - 2); $end = min($pages, $cur + 2);
    if ($cur > 1) $html .= '<a class="pager-btn" href="'.htmlspecialchars(req_page_link($cur-1)).'">‹</a>';
    if ($start > 1) { $html .= '<a class="pager-btn" href="'.htmlspecialchars(req_page_link(1)).'">1</a>'; if ($start > 2) $html .= '<span class="pager-ellipsis">…</span>'; }
    for ($i=$start; $i<=$end; $i++) $html .= '<a class="pager-btn '.($i===$cur?'on':'').'" href="'.htmlspecialchars(req_page_link($i)).'">'.$i.'</a>';
    if ($end < $pages) { if ($end < $pages-1) $html .= '<span class="pager-ellipsis">…</span>'; $html .= '<a class="pager-btn" href="'.htmlspecialchars(req_page_link($pages)).'">'.$pages.'</a>'; }
    if ($cur < $pages) $html .= '<a class="pager-btn" href="'.htmlspecialchars(req_page_link($cur+1)).'">›</a>';
    $html .= '</div></div>';
    return $html;
}
?>

<div class="page-header request-header">
  <h1>🧾 Согласование заявок</h1>
  <?php if ($canCreateRequest): ?><a class="btn btn-primary" href="<?=app_link(['page'=>'requests','mode'=>'new'])?>">+ Новая заявка</a><?php endif; ?>
</div>

<div class="request-tabs">
  <a class="<?=$mode==='waiting'?'on':''?>" href="<?=app_link(['page'=>'requests','mode'=>'waiting'])?>">Мои согласования <?php if($waitingCount>0): ?><span class="req-counter">+<?=$waitingCount?></span><?php endif; ?></a>
  <a class="<?=$mode==='mine'?'on':''?>" href="<?=app_link(['page'=>'requests','mode'=>'mine'])?>">Мои заявки</a>
  <a class="<?=$mode==='all'?'on':''?>" href="<?=app_link(['page'=>'requests','mode'=>'all'])?>">Все доступные</a>
</div>

<?php if ($mode === 'new' && !$canCreateRequest): ?>
<div class="alert alert-error">У вас нет права создавать заявки.</div>
<?php elseif ($mode === 'new'): ?>
<?php
$tpl = is_array($requestTemplate) ? $requestTemplate : [];
$tplTitle = trim((string)($tpl['title'] ?? ''));
$tplAmount = (float)($tpl['amount'] ?? 0);
$tplCategoryId = (string)($tpl['category_id'] ?? '');
$tplOperationCode = trim((string)($tpl['operation_code'] ?? ''));
$tplOperationName = trim((string)($tpl['operation_type_name'] ?? ''));
$tplDepartment = trim((string)($tpl['department'] ?? ''));
$tplSubDepartment = trim((string)($tpl['sub_department'] ?? ''));
$tplRegion = trim((string)($tpl['region'] ?? ''));
$tplManagerId = (int)($tpl['manager_id'] ?? 0);
$tplManagerName = $tplManagerId ? $displayUserName($tplManagerId, $tpl['manager_name'] ?? '') : '';
$tplDirectorIds = trim((string)($tpl['director_ids'] ?? ''));
$tplDirectorNames = trim((string)($tpl['director_names'] ?? ''));
$tplDescription = trim((string)($tpl['description'] ?? ''));
?>
<div class="form-card request-card create-request-card">
  <div class="section-title-row"><div><h3><?= $requestTemplate ? 'Новая заявка по шаблону' : 'Новая заявка на согласование' ?></h3></div></div>
  <?php if ($requestTemplate): ?><div class="alert alert-info template-note">Данные взяты из заявки #<?= (int)$requestTemplate['id'] ?>. Можно изменить любые поля и отправить как новую заявку. Дополнительные файлы из старой заявки не копируются.</div><?php endif; ?>
  <?php if ($requestTemplates): ?>
  <details class="request-template-library">
    <summary><span>Существующие шаблоны заявок</span><em>выберите созданную ранее обычную заявку</em></summary>
    <div class="request-template-list">
      <?php foreach ($requestTemplates as $rt): ?>
        <?php $isCurrentTemplate = $requestTemplate && (int)$requestTemplate['id'] === (int)$rt['id']; ?>
        <a class="request-template-item <?=$isCurrentTemplate?'active':''?>" href="<?=app_link(['page'=>'requests','mode'=>'new','template_id'=>$rt['id']])?>">
          <b><?=htmlspecialchars($rt['title'] ?: ('Заявка '.$rt['id']))?></b>
          <span>#<?= (int)$rt['id'] ?> · <?=number_format((float)$rt['amount'],2,'.',' ')?> TJS · <?=htmlspecialchars($rt['updated_at'] ?? '')?></span>
          <small><?=htmlspecialchars(trim(($rt['operation_code'] ? $rt['operation_code'].' — ' : '').($rt['operation_type_name'] ?? '')) ?: 'без статьи')?><?=trim((string)($rt['department'] ?? '')) !== '' ? ' · '.htmlspecialchars($rt['department']) : ''?></small>
        </a>
      <?php endforeach; ?>
    </div>
  </details>
  <?php endif; ?>
  <form class="ajax-form create-request-form" enctype="multipart/form-data" data-success-redirect="<?=app_link(['page'=>'requests','mode'=>'mine'])?>" method="POST" action="<?=app_link(['action'=>'approval_create'])?>">
    <input type="hidden" name="client_nonce" value="<?=htmlspecialchars(bin2hex(random_bytes(12)))?>">
    <input type="hidden" name="creator_name" value="<?=htmlspecialchars($userName)?>">
    <input type="hidden" name="currency" value="TJS">
    <div class="request-type-switch">
      <label class="request-type-pill active"><input type="radio" name="request_type" value="regular" checked> Обычная заявка</label>
      <label class="request-type-pill"><input type="radio" name="request_type" value="commission"> Комиссионная заявка</label>
    </div>
    <div class="form-row request-create-grid">
      <div class="form-group full emphasized-field"><label>Название заявки <span class="required-mark">*</span></label><input type="text" name="title" value="<?=htmlspecialchars($tplTitle)?>" required placeholder="Краткое название, например: Оплата счёта поставщика"><div class="field-hint">Это название будет отображаться в списках, маршруте и отчёте.</div></div>

      <div class="form-group commission-only full commission-upload-box" hidden><label>Импорт комиссионного файла <span class="required-mark">*</span></label><input type="file" name="commission_file" class="js-commission-file" accept=".xlsx,.xls"><div class="commission-preview js-commission-preview" style="display:none"></div></div>
      <div class="form-group full request-extra-files-box"><label>Дополнительные файлы</label><input type="file" name="request_files[]" multiple accept=".pdf,.xlsx,.xls,.doc,.docx,.jpg,.jpeg,.png,.webp,.txt,.zip,.rar"><div class="field-hint">Можно приложить счёт, акт, договор или другой файл. <?= $requestTemplate ? 'Файлы из шаблона не копируются, при необходимости загрузите их заново.' : 'Это не импорт комиссионной заявки.' ?></div></div>
      <input type="hidden" name="product_series" class="js-product-series">

      <div class="form-group"><label>Сумма <span class="required-mark">*</span></label><input type="number" name="amount" class="js-request-amount" step="0.01" min="0" value="<?= $tplAmount > 0 ? htmlspecialchars(number_format($tplAmount, 2, '.', '')) : '' ?>" required placeholder="0.00"></div>
      <div class="form-group disabled-field"><label>Валюта</label><input value="TJS" disabled></div>
      <div class="form-group disabled-field"><label>Этап согласования</label><input value="Первый этап: руководитель" disabled></div>
      <input type="hidden" name="category_id" class="js-category-id regular-tech-hidden" value="<?=htmlspecialchars($tplCategoryId)?>">
      <div class="form-group regular-tech"><label>Код статьи <span class="required-mark">*</span></label><input type="text" name="operation_code" class="js-operation-code" value="<?=htmlspecialchars($tplOperationCode)?>" placeholder="Введите или выберите код" required></div>
      <div class="form-group regular-tech"><label>Название статьи <span class="required-mark">*</span></label><input type="text" name="operation_type_name" class="js-operation-name" value="<?=htmlspecialchars($tplOperationName)?>" placeholder="Заполнится по коду или введите вручную" required><div class="category-suggestions code-name-suggestions"></div></div>
      <div class="form-group"><label>Отдел заявителя <span class="required-mark">*</span></label><select name="department" class="js-dept-main" required><option value="">Не выбрано</option><?php foreach ($deptMain as $d): ?><option value="<?=htmlspecialchars($d['name'])?>" <?=((string)$tplDepartment===(string)$d['name'])?'selected':''?>><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Регион <span class="required-mark">*</span></label><?=region_select_html("region", $tplRegion)?><div class="hint small">Выберите 0, 1 или 2</div></div>
      <div class="form-group"><label>Подотдел заявителя <span class="required-mark">*</span></label><select name="sub_department" class="js-dept-sub" data-current="<?=htmlspecialchars($tplSubDepartment)?>" required><?php if ($tplSubDepartment !== ''): ?><option value="<?=htmlspecialchars($tplSubDepartment)?>" selected><?=htmlspecialchars($tplSubDepartment)?></option><?php else: ?><option value="">Сначала выберите отдел</option><?php endif; ?></select></div>
      <div class="form-group employee-select-group"><label>Руководитель <span class="required-mark">*</span></label><input type="hidden" name="manager_id" class="b24-user-id" value="<?= $tplManagerId ?: '' ?>" required><input type="text" name="manager_name" value="<?=htmlspecialchars($tplManagerName)?>" placeholder="Выберите из Bitrix24" class="b24-user-name" readonly required><button type="button" class="btn btn-light btn-sm js-load-users" data-target="manager" data-multiple="0">Выбрать</button></div>
      <div class="form-group employee-select-group"><label>Директор департамента <span class="required-mark">*</span></label><input type="hidden" name="director_ids" value="<?=htmlspecialchars($tplDirectorIds)?>" required><textarea name="director_names" rows="2" placeholder="Выберите из Bitrix24" readonly required><?=htmlspecialchars($tplDirectorNames)?></textarea><div class="picker-actions"><button type="button" class="btn btn-light btn-sm js-load-users" data-target="director" data-multiple="1">Выбрать</button><button type="button" class="btn btn-light btn-sm js-clear-users" data-target="director">Очистить</button></div></div>
      <div class="form-group full"><label>Описание заявки</label><textarea name="description" rows="4" class="js-request-description" required placeholder="Назначение платежа, контрагент, основание, срок оплаты, документы"><?=htmlspecialchars($tplDescription)?></textarea></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary btn-lg"><?= $requestTemplate ? 'Использовать этот шаблон и отправить' : 'Отправить на согласование' ?></button><a class="btn btn-light btn-lg" href="<?=app_link(['page'=>'requests','mode'=>'new'])?>">Сбросить поля</a></div>
  </form>
</div>
<?php else: ?>
<div class="filter-bar">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;width:100%">
    <?php foreach (($GLOBALS['APP_CONTEXT_PARAMS'] ?? []) as $k=>$v): ?><input type="hidden" name="<?=htmlspecialchars($k)?>" value="<?=htmlspecialchars($v)?>"><?php endforeach; ?>
    <input type="hidden" name="page" value="requests"><input type="hidden" name="mode" value="<?=htmlspecialchars($mode)?>">
    <div class="filter-group"><label>Поиск</label><input type="text" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Название, описание, заявитель"></div>
    <div class="filter-group"><label>Показать</label><select name="req_per_page" onchange="this.form.req_page.value=1;(this.form.requestSubmit?this.form.requestSubmit():this.form.submit())">
      <?php foreach ([10,25,50,100,200] as $n): ?><option value="<?=$n?>" <?=$reqPerPage===$n?'selected':''?>><?=$n?></option><?php endforeach; ?>
    </select></div>
    <input type="hidden" name="req_page" value="<?=$reqPage?>">
    <button class="btn btn-primary">Применить</button><a class="btn btn-light" href="<?=app_link(['page'=>'requests','mode'=>$mode])?>">Сброс</a>
  </form>
</div>

<div class="requests-grid <?=$detailId?'detail-open':''?>">
  <div class="requests-list-shell">
  <?=req_pager_html($reqPage, $reqPages, $reqTotal, $reqPerPage)?>
  <div class="table-wrap requests-list">
    <table class="data-table requests-table-modern"><thead><tr><th>№</th><th>Название заявки</th><th>Заявитель</th><th>Сумма</th><th>Этап</th><th>Статус</th><th>Обновлено</th></tr></thead><tbody>
    <?php if (!$requests): ?><tr><td colspan="7" class="empty-cell">Нет заявок</td></tr><?php endif; ?>
    <?php foreach ($requests as $r): ?>
      <tr class="js-request-row <?=$detailId===(int)$r['id']?'row-active':''?>" data-href="<?=app_link(['page'=>'requests','mode'=>$mode,'id'=>$r['id']])?>">
        <td><?=$r['id']?></td><td><b><?=htmlspecialchars($r['title'] ?: ('Заявка '.$r['id']))?></b></td><td><?=htmlspecialchars($displayUserName($r['creator_id'] ?? 0, $r['creator_name'] ?? ''))?></td><td><b><?=number_format((float)$r['amount'],2,'.',' ')?></b> TJS</td><td><?=htmlspecialchars(Approval::stageLabelFor($portal, $r['current_stage']))?></td><td><span class="req-badge <?=htmlspecialchars($r['status'])?>"><?=htmlspecialchars(req_status_label($r['status']))?></span></td><td><?=htmlspecialchars($r['updated_at'])?></td>
      </tr>
    <?php endforeach; ?></tbody></table>
  </div>
  <?=req_pager_html($reqPage, $reqPages, $reqTotal, $reqPerPage)?>
  </div>

  <div class="request-detail apple-detail">
  <?php if (!$detailId): ?>
    <div class="empty-state"><p>Нажмите на заявку в списке, чтобы открыть маршрут, историю и доступные действия.</p></div>
  <?php elseif (!$detailAllowed): ?>
    <div class="alert alert-error">Нет доступа к выбранной заявке.</div>
  <?php else: ?>
    <div class="form-card request-card request-detail-page request-detail-card-v69">
      <div class="request-detail-toolbar"><a class="request-detail-close" href="<?=app_link(['page'=>'requests','mode'=>$mode])?>">← Назад к списку</a><div class="detail-toolbar-actions"><span class="request-detail-mode">Карточка заявки</span></div></div>
      <div class="request-head-card-v74">
        <div class="request-head-cell request-head-number"><span>Заявка</span><b>№<?=$detail['id']?></b></div>
        <div class="request-head-cell request-head-title"><span>Название заявки</span><b><?=htmlspecialchars($detail['title'] ?: ('Заявка '.$detail['id']))?></b></div>
        <div class="request-head-cell request-head-status"><span>Статус</span><em class="req-badge <?=htmlspecialchars($detail['status'])?>"><?=htmlspecialchars(req_status_label($detail['status']))?></em></div>
      </div>
      <div class="req-meta">
        <div><span>Заявитель</span><b><?=htmlspecialchars($creatorDisplayName)?></b></div>
        <div><span>Сумма</span><b><?=number_format((float)(($detail['request_type'] ?? '') === 'commission' && $detailCommissionGroups ? $detailCommissionTotal : $detail['amount']),2,'.',' ')?> TJS</b></div>
        <div><span>Тип заявки</span><b><?=($detail['request_type'] ?? 'regular') === 'commission' ? 'Комиссионная' : 'Обычная'?></b></div>
        <?php if (($detail['request_type'] ?? '') === 'commission'): ?><div><span>Маршрут</span><b><?=htmlspecialchars(Approval::routeTypeForRequestLabel($detail))?></b></div><?php endif; ?>
        <?php if (($detail['request_type'] ?? '') === 'commission'): ?>
        <div><span>Файл импорта</span><b><?php if (!empty($detail['commission_import_path']) || !empty($detail['commission_rows_json'])): ?><a class="commission-file-link plain-file-link" href="<?=app_link(['action'=>'approval_commission_file','id'=>$detail['id']])?>" target="_blank"><?=htmlspecialchars(($detail['commission_import_filename'] ?? '') ?: 'Открыть файл')?></a><?php else: ?><?=htmlspecialchars(($detail['commission_import_filename'] ?? '') ?: 'Не загружен')?><?php endif; ?></b></div>
        <?php endif; ?>
        <div><span>Касса/счёт</span><b><?=htmlspecialchars($detail['account_name'] ?: 'Не выбрана')?></b></div>
        <?php if (($detail['request_type'] ?? '') !== 'commission'): ?><div><span>Код статьи</span><b><?=htmlspecialchars($detail['operation_code'] ?: 'Не указан')?></b></div><div><span>Название статьи</span><b><?=htmlspecialchars($detail['operation_type_name'] ?: 'Не указано')?></b></div><?php endif; ?>
        <div><span>Отдел заявителя</span><b><?=htmlspecialchars($detail['department'] ?: 'Не выбран')?></b></div>
        <div><span>Регион</span><b><?=htmlspecialchars(display_region($detail['region'] ?? ''))?></b></div>
        <div><span>Подотдел заявителя</span><b><?=htmlspecialchars($detail['sub_department'] ?: 'Не выбран')?></b></div>
      </div>
      <div class="req-description"><?=nl2br(htmlspecialchars($detail['description']))?></div>
      <?php if ($detailAttachments): ?>
      <details class="request-collapsible request-attachments-card">
        <summary><span>Дополнительные файлы</span><em class="collapsed-label">Показать</em><em class="opened-label">Свернуть</em></summary>
        <div class="request-files-list">
          <?php foreach ($detailAttachments as $i=>$f): ?>
            <a class="request-file-chip" href="<?=app_link(['action'=>'approval_attachment_file','id'=>$detail['id'],'file'=>$i])?>" target="_blank"><span>📎</span><b><?=htmlspecialchars($f['name'] ?? ('Файл '.($i+1)))?></b></a>
          <?php endforeach; ?>
        </div>
      </details>
      <?php endif; ?>
      <?php if ($showCommissionReadOnlySummary): ?>
      <details class="commission-card-summary">
        <summary>Статьи и суммы по файлу</summary>
        <div class="commission-summary-scroll"><table class="commission-summary-table-v80"><thead><tr><th>Регион</th><th>Серия</th><th>Сумма</th></tr></thead><tbody>
        <?php foreach ($detailCommissionGroups as $g): ?>
          <?php $rowAmount = (float)($g['amount'] ?? 0); $rowSeries = trim((string)($g['series'] ?? '')); ?>
          <tr class="commission-main-row">
            <td><?=htmlspecialchars(commission_allocation_region($g['allocation'] ?? ''))?></td>
            <td><span class="commission-series-name"><?=htmlspecialchars($rowSeries)?></span></td>
            <td><?=number_format($rowAmount,2,'.',' ')?> TJS</td>
          </tr>
        <?php endforeach; ?>
        </tbody><tfoot><tr class="commission-overall-total-row"><td colspan="2">Общий итог</td><td><?=number_format((float)$detailCommissionTotal,2,'.',' ')?> TJS</td></tr></tfoot></table></div>
      </details>
      <?php endif; ?>
      <?php if (!$isClosedDetail && $latestReworkComment && in_array((string)$detail['current_stage'], ['creator_rework','finance_rework'], true)): ?>
        <div class="rework-note"><b>Комментарий к доработке:</b><br><?=nl2br(htmlspecialchars($latestReworkComment))?></div>
      <?php endif; ?>

      <details class="request-collapsible request-route-collapsible">
        <summary>
          <span>Маршрут заявки</span>
          <em class="collapsed-label">Показать</em>
          <em class="opened-label">Свернуть</em>
        </summary>
        <?php if ($detail): ?><div class="route-type-note">Полный план маршрута: <b><?=htmlspecialchars(Approval::routeTypeForRequestLabel($detail))?></b>. Будущие этапы показаны заранее со статусом "Ожидается".</div><?php endif; ?>
        <div class="approval-timeline collapsible-body">
          <?php foreach ($detailApprovers as $a): ?>
            <?php $isCurrentStagePoint = ((string)$a['stage'] === (string)($detail['current_stage'] ?? '') && (string)($a['status'] ?? '') === 'pending'); $isMyRoutePoint = ($isCurrentStagePoint && (int)$a['user_id'] === (int)$userId); ?>
            <div class="tl-item <?=$a['status']?> stage-<?=htmlspecialchars($a['stage'])?> <?=$isCurrentStagePoint?'tl-current-stage':''?> <?=$isMyRoutePoint?'tl-my-turn':''?>"><b><?=htmlspecialchars(Approval::stageLabelForRequest($portal, $detail, $a['stage']))?></b><span><?=htmlspecialchars($displayUserName($a['user_id'] ?? 0, $a['user_name'] ?? ''))?></span><em><?=htmlspecialchars(Approval::approverStatusLabelForStage($a['status'], $a['stage']))?></em><?php if(!empty($a['decided_by_name'])): ?><small>Решение: <?=htmlspecialchars($a['decided_by_name'])?></small><?php endif; ?><?php if(!empty($a['comment'])): ?><small class="tl-comment">Комментарий: <?=nl2br(htmlspecialchars($a['comment']))?></small><?php endif; ?></div>
          <?php endforeach; ?>
        </div>
      </details>

      <?php if (!$isClosedDetail && $canFinanceControl && !in_array($detail['current_stage'], ['completed','rejected','creator_rework'], true)): ?>
      <div class="route-control-trigger">
        <button type="button" class="btn btn-light js-open-reassign-modal">Редактировать исполнителей</button>
      </div>
      <div id="reassignModal" class="approval-modal-overlay" style="display:none">
        <div class="approval-modal-card">
          <div class="modal-head"><div><h3>Редактировать исполнителей этапа</h3><p>Можно заменить текущих исполнителей, если сотрудник отсутствует. Заявка останется на текущем этапе.</p></div><button type="button" class="modal-x js-close-reassign-modal">×</button></div>
          <form class="ajax-form" data-reload="1" method="POST" action="<?=app_link(['action'=>'approval_action'])?>">
            <input type="hidden" name="id" value="<?=$detail['id']?>"><input type="hidden" name="do" value="reassign">
            <input type="hidden" name="approver_ids"><textarea name="approver_names" rows="3" placeholder="Выберите новых исполнителей текущего этапа" readonly required></textarea>
            <div class="picker-actions modal-actions"><button type="button" class="btn btn-light btn-sm js-load-users" data-target="reassign" data-multiple="1">Выбрать сотрудников</button><button type="button" class="btn btn-light btn-sm js-clear-users" data-target="reassign">Очистить</button><button class="btn btn-primary btn-sm">Сохранить исполнителей</button></div>
          </form>
          <div class="extra-approver-divider"><span>или</span></div>
          <form class="ajax-form add-extra-approvers-form" data-reload="1" method="POST" action="<?=app_link(['action'=>'approval_action'])?>">
            <input type="hidden" name="id" value="<?=$detail['id']?>"><input type="hidden" name="do" value="add_extra"><input type="hidden" name="after_stage" value="<?=htmlspecialchars($detail['current_stage'])?>">
            <label class="extra-approver-label">Дополнительные согласующие после текущего этапа</label>
            <input type="hidden" name="extra_approver_ids"><textarea name="extra_approver_names" rows="3" placeholder="Заявка пойдёт к ним по очереди после текущего этапа, а затем продолжит стандартный маршрут" readonly required></textarea>
            <textarea name="comment" rows="2" placeholder="Комментарий, зачем добавлены доп. согласующие"></textarea>
            <div class="picker-actions modal-actions"><button type="button" class="btn btn-light btn-sm js-load-users" data-target="extra" data-multiple="1">Выбрать доп. согласующих</button><button type="button" class="btn btn-light btn-sm js-clear-users" data-target="extra">Очистить</button><button class="btn btn-primary btn-sm">Добавить в маршрут</button></div>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!$isClosedDetail && can_act_detail($portal, $detail, $userId, $isManager)): ?>
        <h4 class="current-action-title">Действие по текущему этапу</h4>
        <?php $stage=$detail['current_stage']; ?>
        <?php if ($stage === 'finance_route'): ?>
        <form class="ajax-form finance-route-form" data-reload="1" method="POST" action="<?=app_link(['action'=>'approval_action'])?>">
          <input type="hidden" name="id" value="<?=$detail['id']?>"><input type="hidden" name="do" value="route"><input type="hidden" name="currency" value="TJS">
          <div class="form-row">
            <?php if (($detail['request_type'] ?? '') !== 'commission'): ?>
            <input type="hidden" name="category_id" class="js-category-id" value="<?=htmlspecialchars((string)($detail['category_id'] ?? ''))?>">
            <div class="form-group"><label>Код статьи</label><input type="text" name="operation_code" class="js-operation-code" value="<?=htmlspecialchars($detail['operation_code'] ?? '')?>"></div>
            <div class="form-group"><label>Название статьи</label><input type="text" name="operation_type_name" class="js-operation-name" value="<?=htmlspecialchars($detail['operation_type_name'] ?? '')?>"><div class="category-suggestions code-name-suggestions"></div></div>
            <?php endif; ?>
            <div class="form-group"><label>Касса/счёт</label><select name="account_id"><option value="">Не выбрано</option><?php foreach ($accounts as $a): ?><option value="<?=$a['id']?>" <?=((int)($detail['account_id'] ?? 0)===(int)$a['id'])?'selected':''?>><?=htmlspecialchars($a['name'])?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Отдел заявителя</label><select name="department" class="js-dept-main"><option value="">Не выбрано</option><?php foreach ($deptMain as $d): ?><option value="<?=htmlspecialchars($d['name'])?>" <?=(($detail['department']??'')===$d['name'])?'selected':''?>><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Регион</label><?=region_select_html("region", $detail["region"] ?? "")?><div class="hint small">Выберите 0, 1 или 2</div></div>
            <div class="form-group"><label>Подотдел заявителя</label><select name="sub_department" class="js-dept-sub" data-current="<?=htmlspecialchars($detail['sub_department'] ?? '')?>"><option value="">Не выбрано</option><?php foreach ($deptSub as $d): ?><option value="<?=htmlspecialchars($d['name'])?>" <?=(($detail['sub_department']??'')===$d['name'])?'selected':''?>><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?></select></div>
          </div>

          <?php if (($detail['request_type'] ?? '') === 'commission' && $detailCommissionGroups): ?>
          <div class="commission-edit-panel">
            <div class="commission-edit-head"><b>Статьи и суммы по файлу</b></div>
            <div class="commission-summary-scroll"><table><thead><tr><th>Аллокация</th><th>Серия</th><th>Код</th><th>Название статьи</th><th>Сумма</th></tr></thead><tbody>
            <?php foreach ($detailCommissionGroups as $idx=>$g): ?>
              <tr>
                <td><input type="text" name="commission_group_allocation[]" value="<?=htmlspecialchars($g['allocation'] ?? '')?>"></td>
                <td><input type="text" name="commission_group_series[]" value="<?=htmlspecialchars($g['series'] ?? '')?>"></td>
                <td><input type="hidden" name="commission_group_category_id[]" class="js-commission-category-id" value="<?=htmlspecialchars((string)($g['category_id'] ?? ''))?>"><input type="text" name="commission_group_code[]" class="js-commission-code" value="<?=htmlspecialchars($g['operation_code'] ?? '')?>"></td>
                <td class="commission-name-cell"><input type="text" name="commission_group_name[]" class="js-commission-name" value="<?=htmlspecialchars($g['operation_type_name'] ?? '')?>"><div class="category-suggestions commission-code-suggestions"></div></td>
                <td><input type="number" step="0.01" min="0" name="commission_group_amount[]" value="<?=htmlspecialchars(number_format((float)($g['amount'] ?? 0),2,'.',''))?>"></td>
              </tr>
            <?php endforeach; ?>
            </tbody><tfoot><tr><td colspan="4">Общая сумма</td><td><span class="js-commission-edit-total"><?=number_format((float)$detailCommissionTotal,2,'.',' ')?> TJS</span></td></tr></tfoot></table></div>
          </div>
          <?php endif; ?>
          <div class="form-row"><div class="form-group full employee-select-group"><label>Директор департамента</label><input type="hidden" name="director_ids" value="<?=htmlspecialchars($detail['director_ids'] ?? '')?>" required><textarea name="director_names" rows="2" placeholder="Выберите одного или нескольких сотрудников из Bitrix24" readonly required><?=htmlspecialchars($detail['director_names'] ?? '')?></textarea><div class="picker-actions"><button type="button" class="btn btn-light btn-sm js-load-users" data-target="director" data-multiple="1">Выбрать сотрудников</button><button type="button" class="btn btn-light btn-sm js-clear-users" data-target="director">Очистить</button></div></div></div>
          <div class="form-row"><div class="form-group full"><label>Комментарий</label><textarea name="comment" rows="2"></textarea></div></div>
          <button class="btn btn-primary">Выбрать маршрут и отправить директору</button>
        </form>
        <?php elseif ($stage === 'creator_rework'): ?>
        <form class="ajax-form" data-reload="1" method="POST" action="<?=app_link(['action'=>'approval_action'])?>">
          <input type="hidden" name="id" value="<?=$detail['id']?>"><input type="hidden" name="do" value="resubmit"><input type="hidden" name="currency" value="TJS">
          <div class="form-row"><div class="form-group full"><label>Название заявки</label><input type="text" name="title" value="<?=htmlspecialchars($detail['title'] ?? '')?>"></div></div>
          <div class="form-row">
            <div class="form-group"><label>Сумма</label><input type="number" name="amount" step="0.01" min="0" value="<?=htmlspecialchars((string)$detail['amount'])?>" required></div>
            <div class="form-group disabled-field"><label>Валюта</label><input value="TJS" disabled></div>
            <?php if (($detail['request_type'] ?? '') !== 'commission'): ?>
            <input type="hidden" name="category_id" class="js-category-id" value="<?=htmlspecialchars((string)($detail['category_id'] ?? ''))?>">
            <div class="form-group"><label>Код статьи</label><input type="text" name="operation_code" class="js-operation-code" value="<?=htmlspecialchars($detail['operation_code'] ?? '')?>"></div>
            <div class="form-group"><label>Название статьи</label><input type="text" name="operation_type_name" class="js-operation-name" value="<?=htmlspecialchars($detail['operation_type_name'] ?? '')?>"><div class="category-suggestions code-name-suggestions"></div></div>
            <?php endif; ?>
            <div class="form-group"><label>Отдел заявителя</label><select name="department" class="js-dept-main"><option value="">Не выбрано</option><?php foreach ($deptMain as $d): ?><option value="<?=htmlspecialchars($d['name'])?>" <?=(($detail['department']??'')===$d['name'])?'selected':''?>><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Регион</label><?=region_select_html("region", $detail["region"] ?? "")?></div>
            <div class="form-group"><label>Подотдел заявителя</label><select name="sub_department" class="js-dept-sub" data-current="<?=htmlspecialchars($detail['sub_department'] ?? '')?>"><option value="">Не выбрано</option><?php foreach ($deptSub as $d): ?><option value="<?=htmlspecialchars($d['name'])?>" <?=(($detail['sub_department']??'')===$d['name'])?'selected':''?>><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?></select></div>
          </div>
          <div class="form-row"><div class="form-group full employee-select-group"><label>Директор департамента</label><input type="hidden" name="director_ids" value="<?=htmlspecialchars($detail['director_ids'] ?? '')?>"><textarea name="director_names" rows="2" readonly><?=htmlspecialchars($detail['director_names'] ?? '')?></textarea><div class="picker-actions"><button type="button" class="btn btn-light btn-sm js-load-users" data-target="director" data-multiple="1">Выбрать сотрудников</button><button type="button" class="btn btn-light btn-sm js-clear-users" data-target="director">Очистить</button></div></div></div>
          <div class="form-row"><div class="form-group full"><label>Описание заявки</label><textarea name="description" rows="5" required><?=htmlspecialchars($detail['description'])?></textarea></div></div>
          <div class="form-row"><div class="form-group employee-select-group"><label>Руководитель</label><input type="hidden" name="manager_id" value="<?=htmlspecialchars((string)$detail['manager_id'])?>" required><input type="text" name="manager_name" value="<?=htmlspecialchars($managerDisplayName)?>" readonly required><button type="button" class="btn btn-light btn-sm js-load-users" data-target="manager" data-multiple="0">Выбрать другого</button></div></div>
          <textarea name="comment" rows="3" placeholder="Что исправлено" style="width:100%"></textarea><button class="btn btn-primary mt8">Вернуть на согласование</button>
        </form>
        <?php elseif ($stage === 'finance_rework'): ?>
        <form class="ajax-form" data-reload="1" method="POST" action="<?=app_link(['action'=>'approval_action'])?>">
          <input type="hidden" name="id" value="<?=$detail['id']?>"><input type="hidden" name="do" value="resubmit"><input type="hidden" name="currency" value="TJS">
          <div class="form-row"><div class="form-group full"><label>Название заявки</label><input type="text" name="title" value="<?=htmlspecialchars($detail['title'] ?? '')?>"></div></div>
          <div class="form-row">
            <?php if (($detail['request_type'] ?? '') !== 'commission'): ?>
            <input type="hidden" name="category_id" class="js-category-id" value="<?=htmlspecialchars((string)$detail['category_id'])?>">
            <div class="form-group"><label>Код статьи</label><input type="text" name="operation_code" class="js-operation-code" value="<?=htmlspecialchars($detail['operation_code'])?>"></div>
            <div class="form-group"><label>Название статьи</label><input type="text" name="operation_type_name" class="js-operation-name" value="<?=htmlspecialchars($detail['operation_type_name'])?>"><div class="category-suggestions code-name-suggestions"></div></div>
            <?php endif; ?>
            <div class="form-group"><label>Касса/счёт</label><select name="account_id"><option value="">Не выбрано</option><?php foreach ($accounts as $a): ?><option value="<?=$a['id']?>" <?=((int)$detail['account_id']===(int)$a['id'])?'selected':''?>><?=htmlspecialchars($a['name'])?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Отдел заявителя</label><select name="department" class="js-dept-main"><option value="">Не выбрано</option><?php foreach ($deptMain as $d): ?><option value="<?=htmlspecialchars($d['name'])?>" <?=($detail['department']??'')===$d['name']?'selected':''?>><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Регион</label><?=region_select_html("region", $detail["region"] ?? "")?><div class="hint small">Выберите 0, 1 или 2</div></div>
            <div class="form-group"><label>Подотдел заявителя</label><select name="sub_department" class="js-dept-sub" data-current="<?=htmlspecialchars($detail['sub_department'] ?? '')?>"><option value="">Не выбрано</option><?php foreach ($deptSub as $d): ?><option value="<?=htmlspecialchars($d['name'])?>" <?=($detail['sub_department']??'')===$d['name']?'selected':''?>><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?></select></div>
          </div>
          <?php if (($detail['request_type'] ?? '') === 'commission' && $detailCommissionGroups): ?>
          <div class="commission-edit-panel">
            <div class="commission-edit-head"><b>Статьи и суммы по файлу</b></div>
            <div class="commission-summary-scroll"><table><thead><tr><th>Аллокация</th><th>Серия</th><th>Код</th><th>Название статьи</th><th>Сумма</th></tr></thead><tbody>
            <?php foreach ($detailCommissionGroups as $idx=>$g): ?>
              <tr>
                <td><input type="text" name="commission_group_allocation[]" value="<?=htmlspecialchars($g['allocation'] ?? '')?>"></td>
                <td><input type="text" name="commission_group_series[]" value="<?=htmlspecialchars($g['series'] ?? '')?>"></td>
                <td><input type="hidden" name="commission_group_category_id[]" class="js-commission-category-id" value="<?=htmlspecialchars((string)($g['category_id'] ?? ''))?>"><input type="text" name="commission_group_code[]" class="js-commission-code" value="<?=htmlspecialchars($g['operation_code'] ?? '')?>"></td>
                <td class="commission-name-cell"><input type="text" name="commission_group_name[]" class="js-commission-name" value="<?=htmlspecialchars($g['operation_type_name'] ?? '')?>"><div class="category-suggestions commission-code-suggestions"></div></td>
                <td><input type="number" step="0.01" min="0" name="commission_group_amount[]" value="<?=htmlspecialchars(number_format((float)($g['amount'] ?? 0),2,'.',''))?>"></td>
              </tr>
            <?php endforeach; ?>
            </tbody><tfoot><tr><td colspan="4">Общая сумма</td><td><span class="js-commission-edit-total"><?=number_format((float)$detailCommissionTotal,2,'.',' ')?> TJS</span></td></tr></tfoot></table></div>
          </div>
          <?php endif; ?>
          <div class="form-row"><div class="form-group full employee-select-group"><label>Директор департамента</label><input type="hidden" name="director_ids" value="<?=htmlspecialchars($detail['director_ids'])?>"><textarea name="director_names" rows="2" readonly><?=htmlspecialchars($detail['director_names'])?></textarea><div class="picker-actions"><button type="button" class="btn btn-light btn-sm js-load-users" data-target="director" data-multiple="1">Выбрать сотрудников</button><button type="button" class="btn btn-light btn-sm js-clear-users" data-target="director">Очистить</button></div></div></div>
          <textarea name="comment" rows="3" placeholder="Что исправлено" style="width:100%"></textarea><button class="btn btn-primary mt8">Вернуть на согласование</button>
        </form>
        <?php else: ?>
        <div class="approval-actions approval-actions-compact">
          <form class="ajax-form" data-reload="1" method="POST" action="<?=app_link(['action'=>'approval_action'])?>"><input type="hidden" name="id" value="<?=$detail['id']?>"><input type="hidden" name="do" value="approve"><textarea name="comment" rows="2" placeholder="Комментарий"></textarea><button class="btn btn-primary approval-action-btn">Согласовать</button></form>
          <form class="ajax-form" data-reload="1" method="POST" action="<?=app_link(['action'=>'approval_action'])?>"><input type="hidden" name="id" value="<?=$detail['id']?>"><input type="hidden" name="do" value="rework"><?php if($stage==='manager'): ?><input type="hidden" name="target" value="creator"><div class="hint">Руководитель может вернуть заявку только заявителю на доработку.</div><?php else: ?><select name="target"><option value="finance">На доработку финансисту</option><option value="creator">На доработку заявителю</option></select><?php endif; ?><textarea name="comment" rows="2" placeholder="Причина доработки" required></textarea><button class="btn btn-danger approval-action-btn">Отправить на доработку</button></form>
        </div>
        <?php endif; ?>
      <?php elseif(!$isClosedDetail): ?>
        <div class="alert alert-warning">Сейчас заявка находится не на вашем этапе.</div>
      <?php endif; ?>

      <?php if($canDeleteRequest): ?>
      <form class="ajax-form" data-success-redirect="<?=app_link(['page'=>'requests','mode'=>$mode])?>" method="POST" action="<?=app_link(['action'=>'approval_action'])?>" style="margin-top:10px"><input type="hidden" name="id" value="<?=$detail['id']?>"><input type="hidden" name="do" value="delete"><button class="btn btn-light" onclick="return confirm('Удалить заявку?')">Удалить заявку</button></form>
      <?php endif; ?>

      <details class="request-collapsible request-history-collapsible">
        <summary>
          <span>История заявки</span>
          <em class="collapsed-label">Показать</em>
          <em class="opened-label">Свернуть</em>
        </summary>
        <div class="req-history collapsible-body"><?php foreach ($detailHistory as $h): ?><div><b><?=htmlspecialchars($h['created_at'])?></b> <?=htmlspecialchars(!empty($h['user_id']) ? $displayUserName($h['user_id'], $h['user_name'] ?? '') : ($h['user_name'] ?: 'Система'))?>: <?=htmlspecialchars(Approval::actionLabel($h['action']))?> <?=htmlspecialchars(Approval::stageLabelFor($portal, $h['from_stage']))?> → <?=htmlspecialchars(Approval::stageLabelFor($portal, $h['to_stage']))?> <span><?=nl2br(htmlspecialchars($h['comment']))?></span></div><?php endforeach; ?></div>
      </details>
    </div>
  <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div id="approvalToast" class="approval-toast-overlay" style="display:none!important" hidden>
  <div class="approval-toast-card">
    <div class="approval-toast-icon">✓</div>
    <h3 id="approvalToastTitle">Действие выполнено</h3>
    <p id="approvalToastText"></p>
    <div class="approval-toast-actions">
      <button type="button" class="btn btn-primary" id="approvalToastBack">Вернуться к заявкам</button>
      <button type="button" class="btn btn-light" id="approvalToastClose">Закрыть</button>
    </div>
  </div>
</div>
<div id="b24UserPicker" class="b24-user-picker" style="display:none">
  <div class="picker-box picker-box-top">
    <h3>Сотрудники Bitrix24</h3>
    <div class="hint">Начните вводить ФИО. Если Bitrix24 не отдаёт полный список сотрудников, будут показаны сотрудники, уже встречавшиеся в правах, маршрутах и заявках.</div>
    <input type="text" id="b24UserSearch" placeholder="Поиск по ФИО">
    <div id="b24UserList" class="picker-list"></div>
    <div class="picker-actions mt8"><button type="button" class="btn btn-light" onclick="document.getElementById('b24UserPicker').style.display='none'">Закрыть</button></div>
  </div>
</div>

<style>
.request-detail-toolbar{display:flex;justify-content:space-between;gap:10px;align-items:center}.detail-toolbar-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.template-note{margin:10px 0 16px}.request-template-btn{white-space:nowrap}
</style>
<style>
/* v100: окно результата показывается только после реального действия и закрывается всегда */
#approvalToast.approval-toast-overlay{position:fixed!important;inset:0!important;z-index:2147483647!important;display:none!important;align-items:flex-start!important;justify-content:center!important;padding:4vh 18px 18px!important;background:rgba(15,23,42,.50)!important;backdrop-filter:blur(8px)!important;-webkit-backdrop-filter:blur(8px)!important;box-sizing:border-box!important}
#approvalToast.approval-toast-overlay.is-visible:not([hidden]){display:flex!important}
#approvalToast .approval-toast-card{transform:none!important;margin:0 auto!important;width:min(620px,94vw)!important;max-height:76vh!important;overflow:auto!important;border-radius:28px!important;pointer-events:auto!important}
@media(max-width:760px){#approvalToast.approval-toast-overlay{padding-top:4vh!important}#approvalToast .approval-toast-card{width:min(94vw,520px)!important}}
#approvalToast.approval-toast-overlay:not(.is-visible),#approvalToast.approval-toast-overlay[hidden]{display:none!important}
#approvalToast.approval-toast-overlay.is-visible:not([hidden]){display:flex!important}
</style>

<script>
(function(){
var CATEGORIES = <?=$categoryJson?>;
var DEPARTMENTS = <?=$deptJson?>;
function escapeHtml(value){return String(value ?? '').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
var pickerTarget = '';
var pickerMultiple = false;
var pickerForm = null;

function fullName(u){return (u.FULL_NAME || u.fullName || u.name || u.NAME_FORMATTED || ((u.LAST_NAME||u.lastName||'')+' '+(u.NAME||u.firstName||'')).trim() || u.title || u.label || ('Сотрудник #' + (u.ID||u.id||u.USER_ID||''))).trim();}
function normalizeUser(u){if(!u)return null;if(u.user)u=u.user;var id=u.ID||u.id||u.USER_ID||u.user_id||u.value||u.VALUE;if(!id)return null;return{ID:String(id),NAME:u.NAME||u.firstName||'',LAST_NAME:u.LAST_NAME||u.lastName||'',WORK_POSITION:u.WORK_POSITION||u.position||u.subtitle||'',FULL_NAME:fullName(u)}}
function normalizeUsers(data){if(!data)return[];if(Array.isArray(data))return data.map(normalizeUser).filter(Boolean);if(data.result)return normalizeUsers(data.result);return Object.keys(data).map(k=>{var v=data[k];if(v&&typeof v==='object'){if(!v.ID&&!v.id)v.ID=k;return normalizeUser(v)}return normalizeUser({ID:k,FULL_NAME:String(v)})}).filter(Boolean)}
function setFieldValue(sel,value){var el=document.querySelector(sel);if(el)el.value=value;}
function appendListValue(el,value,sep){if(!el)return;var current=(el.value||'').trim();var values=current?current.split(sep).map(v=>v.trim()).filter(Boolean):[];if(!values.includes(String(value).trim()))values.push(String(value).trim());el.value=values.join(sep===','?',':'\n');}
function targetEls(){
 var scope=pickerForm||document;
 if(pickerTarget==='manager')return{ids:scope.querySelector('[name="manager_id"]'),names:scope.querySelector('[name="manager_name"]'),single:true};
 if(pickerTarget==='director')return{ids:scope.querySelector('[name="director_ids"]'),names:scope.querySelector('[name="director_names"]')};
 if(pickerTarget==='reassign')return{ids:document.querySelector('[name="approver_ids"]'),names:document.querySelector('[name="approver_names"]')};
 if(pickerTarget==='extra')return{ids:scope.querySelector('[name="extra_approver_ids"]'),names:scope.querySelector('[name="extra_approver_names"]')};
 return{};
}
function fillUser(u){u=normalizeUser(u);if(!u)return;var e=targetEls();if(!e.ids||!e.names)return;if(e.single){e.ids.value=u.ID;e.names.value=u.FULL_NAME;document.getElementById('b24UserPicker').style.display='none';}else{appendListValue(e.ids,u.ID,',');appendListValue(e.names,u.FULL_NAME,'\n');}}
function renderUsers(users){var list=document.getElementById('b24UserList');list.innerHTML='';users=normalizeUsers(users);if(!users.length){list.innerHTML='<div class="hint">Список сотрудников не получен. Проверьте право локального приложения Bitrix24 на пользователей. Резервно отображаются только сотрудники, уже сохранённые в правах, маршрутах и заявках.</div>';return;}users.forEach(u=>{var div=document.createElement('div');div.className='picker-user';div.innerHTML='<b>'+u.FULL_NAME+'</b><span>'+ (u.WORK_POSITION||'') +'</span>';div.onclick=()=>fillUser(u);list.appendChild(div);});}
function loadUsers(q){
 var list=document.getElementById('b24UserList');if(list)list.innerHTML='<div class="hint">Загрузка сотрудников...</div>';
 var serverFallback=()=>fetch('<?=app_link(['action'=>'approval_users'])?>&q='+encodeURIComponent(q||''),{headers:{'Accept':'application/json'}}).then(r=>r.json()).then(j=>renderUsers(j.users||[])).catch(()=>renderUsers([]));
 if(typeof BX24!=='undefined'&&BX24.callMethod){var filter=q?{'%NAME':q,'%LAST_NAME':q}:{};BX24.callMethod('user.get',{ACTIVE:true,FILTER:filter,order:{LAST_NAME:'ASC'},select:['ID','NAME','LAST_NAME','WORK_POSITION']},function(res){if(res&&!res.error()){var data=res.data()||[]; if(data.length){renderUsers(data);} else {serverFallback();}}else serverFallback();});}else serverFallback();
}
function showFallbackPicker(target,multiple){pickerTarget=target;pickerMultiple=!!multiple;document.getElementById('b24UserPicker').style.display='flex';loadUsers('');}
function nativePicker(){
 if(typeof BX24==='undefined')return false;
 try{
  if(pickerMultiple && typeof BX24.selectUsers==='function'){
    BX24.selectUsers(function(users){normalizeUsers(users).forEach(fillUser);});return true;
  }
  if(!pickerMultiple && typeof BX24.selectUser==='function'){
    BX24.selectUser(function(user){fillUser(user);});return true;
  }
  if(typeof BX24.selectUsers==='function'){
    BX24.selectUsers(function(users){normalizeUsers(users).forEach(fillUser);});return true;
  }
 }catch(e){console.warn('Bitrix user picker failed',e);}
 return false;
}
function chooseUsers(target,multiple,source){pickerTarget=target;pickerMultiple=!!multiple;pickerForm=source?(source.closest('form')||null):null;if(nativePicker())return;showFallbackPicker(target,multiple);}

if(!window.__approvalPickerClickBound){
 window.__approvalPickerClickBound = true;
 document.addEventListener('click',function(e){
  var loadBtn=e.target.closest('.js-load-users'); if(loadBtn){chooseUsers(loadBtn.dataset.target||'',loadBtn.dataset.multiple==='1',loadBtn);}
  var clearBtn=e.target.closest('.js-clear-users'); if(clearBtn){pickerTarget=clearBtn.dataset.target||'';pickerForm=clearBtn.closest('form')||null;var els=targetEls();if(els.ids)els.ids.value='';if(els.names)els.names.value='';}
 });
}
var s=document.getElementById('b24UserSearch');if(s){var t;s.addEventListener('input',()=>{clearTimeout(t);t=setTimeout(()=>loadUsers(s.value),300);});}

function initDepartmentLinks(scope){
 var root=scope||document;
 root.querySelectorAll('select.js-dept-main').forEach(function(main){
  if(main.dataset.deptBound==='1')return;
  main.dataset.deptBound='1';
  var form=main.closest('form')||root;
  var sub=form.querySelector('select.js-dept-sub');
  if(!sub)return;
  function render(){
    var parent=main.value||'';
    var current=sub.dataset.current || sub.value || '';
    var rows=DEPARTMENTS.filter(function(d){return d.parent && d.parent===parent;});
    var html='<option value="">'+(parent?'Не выбрано':'Сначала выберите отдел')+'</option>';
    rows.forEach(function(d){html+='<option value="'+escapeHtml(d.name)+'">'+escapeHtml(d.name)+'</option>';});
    if(current && !rows.some(function(d){return d.name===current;})) html+='<option value="'+escapeHtml(current)+'">'+escapeHtml(current)+'</option>';
    sub.innerHTML=html;
    if(current) sub.value=current;
    sub.dataset.current='';
  }
  main.addEventListener('change',function(){sub.dataset.current='';render();});
  render();
 });
}

function hideApprovalToast(){
 var t=document.getElementById('approvalToast');
 if(!t)return;
 t.classList.remove('is-visible');
 t.hidden = true;
 t.style.setProperty('display','none','important');
 t.dataset.redirectUrl='';
 clearTimeout(t._timer);
}
window.hideApprovalToast=hideApprovalToast;
function showApprovalToast(message,type,redirectUrl,autoMs){
 var t=document.getElementById('approvalToast'); if(!t){alert(message); if(redirectUrl) location.href=redirectUrl; return;}
 if(t.parentNode !== document.body){document.body.appendChild(t);}
 var title=document.getElementById('approvalToastTitle'); var text=document.getElementById('approvalToastText'); var icon=t.querySelector('.approval-toast-icon');
 t.className='approval-toast-overlay '+(type||'ok');
 t.dataset.redirectUrl=redirectUrl||'';
 if(icon) icon.textContent=(type==='err')?'!':'✓';
 if(title) title.textContent=(type==='err')?'Ошибка':'Готово';
 if(text) text.textContent=message||'Действие выполнено успешно';
 t.hidden = false;
 t.classList.add('is-visible');
 t.style.setProperty('display','flex','important');
 clearTimeout(t._timer);
 var back=document.getElementById('approvalToastBack');
 if(back){ back.style.display=redirectUrl?'inline-flex':'none'; }
 if(autoMs!==0) t._timer=setTimeout(function(){ if(redirectUrl){location.href=redirectUrl;} else {hideApprovalToast();} }, autoMs||5000);
}
hideApprovalToast();
var initialToast=document.getElementById('approvalToast'); if(initialToast && initialToast.parentNode !== document.body){document.body.appendChild(initialToast);} hideApprovalToast(); setTimeout(hideApprovalToast,0);
if(!window.__approvalToastCloseBound){
 window.__approvalToastCloseBound=true;
 document.addEventListener('click',function(e){
  var toast=document.getElementById('approvalToast');
  if(!toast || toast.hidden || toast.style.display==='none')return;
  if(e.target.closest('#approvalToastClose')){e.preventDefault();hideApprovalToast();return;}
  if(e.target.closest('#approvalToastBack')){e.preventDefault();var u=toast.dataset.redirectUrl||''; if(u){location.href=u;} else {hideApprovalToast();} return;}
  if(e.target===toast){e.preventDefault();hideApprovalToast();return;}
 });
 document.addEventListener('keydown',function(e){if(e.key==='Escape')hideApprovalToast();});
}
function refreshRequestsArea(){
 var grid=document.querySelector('.requests-grid'); if(!grid)return Promise.resolve();
 grid.classList.add('is-refreshing');
 return fetch(location.href,{headers:{'X-Requested-With':'fetch','Cache-Control':'no-store'}})
  .then(r=>r.text()).then(html=>{var doc=new DOMParser().parseFromString(html,'text/html'); var ng=doc.querySelector('.requests-grid'); if(ng&&grid){grid.outerHTML=ng.outerHTML; document.querySelectorAll('.category-search-group').forEach(initCategorySearch);initCategoryCodeNameAuto(document);initCommissionGroupCategoryAuto(document);initCommissionCategoryDatalists(document);initCommissionEditTotals(document);initDepartmentLinks(document);initRequestTypeForm(document);}})
  .catch(()=>{})
  .finally(()=>{var g=document.querySelector('.requests-grid'); if(g)g.classList.remove('is-refreshing');});
}
if(!window.__approvalSubmitBound){
 window.__approvalSubmitBound = true;
 window.__approvalActiveSubmit = null;
 document.addEventListener('submit',function(e){
  var form=e.target.closest('.ajax-form'); if(!form)return; e.preventDefault();
  if(form.dataset.submitting==='1') return;
  if(form.querySelector('[name="manager_id"]')&&!form.querySelector('[name="manager_id"]').value){showApprovalToast('Выберите руководителя из списка Bitrix24','err',null,0);return;}
  if(form.querySelector('[name="director_ids"]')&&form.querySelector('[name="director_ids"]').required&&!form.querySelector('[name="director_ids"]').value){showApprovalToast('Выберите директора департамента из списка Bitrix24','err',null,0);return;}
  var btn=form.querySelector('button[type="submit"],button:not([type])');
  form.dataset.submitting='1';
  if(btn){btn.disabled=true;btn.dataset.oldText=btn.textContent;btn.textContent='Отправка...';}
  var controller = new AbortController();
  window.__approvalActiveSubmit = controller;
  fetch(form.action,{method:'POST',body:new FormData(form),headers:{'Accept':'application/json'},signal:controller.signal})
   .then(function(r){return window.parseAppJsonResponse ? window.parseAppJsonResponse(r, window.cleanAppUrlForPage('?page=requests', false), {silentReload:false}) : r.json();})
   .then(function(j){if(!j.ok)throw new Error(j.error||'Ошибка'); if(typeof window.updateApprovalCounters==='function' && typeof j.waiting_count!=='undefined'){window.updateApprovalCounters(j.waiting_count);} var msg=j.message||'Действие выполнено успешно'; var redirectUrl=''; if(form.dataset.successRedirect){redirectUrl=form.dataset.successRedirect;} else if(j.redirect && !form.dataset.reload){redirectUrl='<?=app_link(['page'=>'requests','mode'=>$mode])?>';} showApprovalToast(msg,'ok',redirectUrl,0); var rm=document.getElementById('reassignModal'); if(rm)rm.style.display='none'; if(!redirectUrl){setTimeout(()=>refreshRequestsArea(),250);} })
   .catch(function(err){if(err.name!=='AbortError')showApprovalToast(err.message,'err',null,0);})
   .finally(function(){form.dataset.submitting='0'; if(btn){btn.disabled=false;btn.textContent=btn.dataset.oldText||btn.textContent;}});
 });
}
if(!window.__approvalDetailClickBound){
 window.__approvalDetailClickBound = true;
 window.__approvalDetailAbort = null;
 document.addEventListener('click',function(e){
 var row=e.target.closest('.js-request-row'); if(row&&row.dataset.href){
   e.preventDefault();
   document.querySelectorAll('.js-request-row').forEach(r=>r.classList.remove('row-active')); row.classList.add('row-active');
   var detail=document.querySelector('.request-detail'); if(detail){detail.classList.add('is-loading');}
   if(window.__approvalDetailAbort){try{window.__approvalDetailAbort.abort();}catch(err){}}
   window.__approvalDetailAbort=new AbortController();
   var detailUrl;
   try{ detailUrl=new URL(row.dataset.href, location.href); detailUrl.searchParams.set('detail_only','1'); detailUrl=detailUrl.toString(); }catch(_){ detailUrl=row.dataset.href+(row.dataset.href.indexOf('?')>=0?'&':'?')+'detail_only=1'; }
   fetch(detailUrl,{headers:{'X-Requested-With':'fetch','Cache-Control':'no-store'},signal:window.__approvalDetailAbort.signal}).then(r=>r.text()).then(html=>{
     var doc=new DOMParser().parseFromString(html,'text/html');
     var nd=doc.querySelector('.request-detail');
     var grid=document.querySelector('.requests-grid');
     if(nd&&detail){
       detail.innerHTML=nd.innerHTML;
       if(grid) grid.classList.add('detail-open');
       document.body.classList.add('approval-detail-modal-open');
       document.documentElement.classList.add('approval-detail-modal-open');
       try{ window.scrollTo({top:0,left:0,behavior:'instant'}); }catch(_){ try{ window.scrollTo(0,0); }catch(__){} }
       history.pushState(null,'',row.dataset.href);
       document.querySelectorAll('.category-search-group').forEach(initCategorySearch);
       initCategoryCodeNameAuto(detail);
       initCommissionGroupCategoryAuto(detail);
       initCommissionCategoryDatalists(detail);
       initCommissionEditTotals(detail);
       initCommissionSeriesTotals(detail);
       initDepartmentLinks(detail);initRequestTypeForm(detail);
     } else if(window.appLooksLikeHtmlChallenge && window.appLooksLikeHtmlChallenge(html)) {
       location.href=row.dataset.href;
     }
   }).catch(err=>{if(err.name!=='AbortError')location.href=row.dataset.href;}).finally(()=>{if(detail)detail.classList.remove('is-loading');});
 }
 var closeDetail=e.target.closest('.request-detail-close'); if(closeDetail){
   e.preventDefault();
   var grid=document.querySelector('.requests-grid'); var detail=document.querySelector('.request-detail');
   if(grid) grid.classList.remove('detail-open');
   document.body.classList.remove('approval-detail-modal-open');
   document.documentElement.classList.remove('approval-detail-modal-open');
   document.querySelectorAll('.js-request-row').forEach(r=>r.classList.remove('row-active'));
   if(detail) detail.innerHTML='<div class="empty-state"><p>Нажмите на заявку в списке, чтобы открыть маршрут, историю и доступные действия.</p></div>';
   history.pushState(null,'',closeDetail.href);
 }
 var open=e.target.closest('.js-open-reassign-modal'); if(open){var m=document.getElementById('reassignModal'); if(m)m.style.display='flex';}
 var close=e.target.closest('.js-close-reassign-modal'); if(close){var m=document.getElementById('reassignModal'); if(m)m.style.display='none';}
 });
}


function initRequestTypeForm(scope){
 var root=scope||document;
 root.querySelectorAll('.create-request-form').forEach(function(form){
  if(form.dataset.reqTypeBound==='1')return; form.dataset.reqTypeBound='1';
  var amount=form.querySelector('[name="amount"]');
  var desc=form.querySelector('.js-request-description');
  var series=form.querySelector('.js-product-series');
  var preview=form.querySelector('.js-commission-preview');
  function isCommission(){var r=form.querySelector('[name="request_type"]:checked');return r&&r.value==='commission';}
  function renderType(){
    var comm=isCommission();
    form.classList.toggle('is-commission', comm);
    form.classList.toggle('is-regular', !comm);
    form.querySelectorAll('.request-type-pill').forEach(function(p){var inp=p.querySelector('input');p.classList.toggle('active', !!inp&&inp.checked);});
    form.querySelectorAll('.commission-only').forEach(function(el){el.hidden=!comm; el.style.display=comm?'block':'none';});
    form.querySelectorAll('.regular-category,.regular-tech').forEach(function(el){el.hidden=comm; el.style.display=comm?'none':'block';});
    form.querySelectorAll('.regular-tech input').forEach(function(el){ if(el.name==='operation_code'||el.name==='operation_type_name'){ el.required=!comm; } });
    if(amount){amount.readOnly=comm;amount.classList.toggle('disabled-input',comm); if(comm && !amount.value) amount.value='0.00';}
    if(desc){desc.required=!comm; if(comm && !desc.placeholder) desc.placeholder='Комментарий к комиссионной заявке';}
    if(!comm){ if(amount){amount.readOnly=false;amount.classList.remove('disabled-input');amount.dataset.lockedByImport='0';} if(preview){preview.style.display='none';preview.innerHTML='';} if(series)series.value=''; }
  }
  form.querySelectorAll('[name="request_type"]').forEach(function(r){r.addEventListener('change',renderType);});
  var file=form.querySelector('.js-commission-file');
  if(file){file.addEventListener('change',function(){
    if(!file.files||!file.files[0])return;
    if(preview){preview.style.display='block';preview.innerHTML='<div class="commission-loading">Читаю файл...</div>';}
    var fd=new FormData(); fd.append('commission_file',file.files[0]);
    fetch('<?=app_link(['action'=>'approval_commission_preview'])?>',{method:'POST',body:fd,headers:{'Accept':'application/json'}})
     .then(function(r){return window.parseAppJsonResponse ? window.parseAppJsonResponse(r, window.cleanAppUrlForPage('?page=requests&mode=create', false), {silentReload:false}) : r.json();})
     .then(function(j){if(!j.ok)throw new Error(j.error||'Ошибка импорта');
       var label=String(j.series_label || ((j.series&&j.series.length)?j.series.join(', '):'') || '').toUpperCase();
       var total=0;
       if(Array.isArray(j.groups)){j.groups.forEach(function(g){total+=Number(g.amount||0);});}
       if(!total) total=Number(j.total||0);
       if(amount){amount.value=(Number(total||0)).toFixed(2);amount.readOnly=true;amount.dataset.lockedByImport='1';}
       if(series){series.value=String(j.primary_series || (label?'MULTI':'') || '').toUpperCase();}
       if(desc){
         var base='Комиссионное вознаграждение по страховым продуктам' + (label ? ' — '+label : '');
         var current=(desc.value||'').trim();
         var lines=current.split(/\r?\n/).map(function(v){return v.trim();}).filter(function(v){return v && v.indexOf('Комиссионное вознаграждение по страховым продуктам')!==0;});
         desc.value=base + (lines.length ? '\n' + lines.join('\n') : '');
       }
       if(preview){preview.innerHTML='<div class="commission-compact-ok"><b>Файл импортирован.</b><span>'+Number(total||0).toFixed(2)+' TJS</span></div>';}
     })
     .catch(function(err){if(preview){preview.style.display='block';preview.innerHTML='<div class="alert alert-error">'+escapeHtml(err.message)+'</div>';}});
  });}
  renderType();
 });
}

function initCategoryCodeNameAuto(scope){
 var root=scope||document;
 root.querySelectorAll('form').forEach(function(form){
  var code=form.querySelector('.js-operation-code'); var name=form.querySelector('.js-operation-name'); var hidden=form.querySelector('.js-category-id');
  if(!code||!name||code.dataset.codeAutoBound==='1')return; code.dataset.codeAutoBound='1';
  var box=name.parentElement ? name.parentElement.querySelector('.code-name-suggestions') : null;
  function pick(c){ if(hidden)hidden.value=c.id||''; if(code)c.code&&(code.value=c.code); if(name)name.value=c.clean||c.name||''; if(box){box.innerHTML='';box.style.display='none';} }
  function rows(q){ q=String(q||'').toLowerCase().trim(); return CATEGORIES.filter(function(c){return !q || String(c.code||'').toLowerCase().includes(q) || String(c.clean||'').toLowerCase().includes(q) || String(c.name||'').toLowerCase().includes(q);}).slice(0,12); }
  function render(q){ if(!box)return; var list=rows(q); box.innerHTML=list.map(function(c){return '<div data-id="'+c.id+'"><b>'+(c.code?escapeHtml(c.code)+' — ':'')+escapeHtml(c.clean||c.name)+'</b><span>'+escapeHtml(c.name||'')+'</span></div>';}).join('') || '<em>Нет совпадений</em>'; box.style.display='block'; box.querySelectorAll('div[data-id]').forEach(function(d){d.onclick=function(){var c=CATEGORIES.find(function(x){return String(x.id)===String(d.dataset.id)}); if(c)pick(c);};}); }
  code.addEventListener('input',function(){var v=code.value.trim(); if(hidden)hidden.value=''; var c=CATEGORIES.find(function(x){return String(x.code||'')===v;}); if(c)pick(c); else render(v);});
  code.addEventListener('focus',function(){render(code.value);});
  name.addEventListener('input',function(){if(hidden)hidden.value=''; render(name.value);});
  name.addEventListener('focus',function(){render(name.value||code.value);});
  document.addEventListener('click',function(e){if(box && !form.contains(e.target)){box.style.display='none';}});
 });
}

function initCommissionGroupCategoryAuto(scope){
 var root=scope||document;
 root.querySelectorAll('.commission-edit-panel tbody tr').forEach(function(row){
  if(row.dataset.commissionAutoBound==='1')return; row.dataset.commissionAutoBound='1';
  var code=row.querySelector('.js-commission-code');
  var name=row.querySelector('.js-commission-name');
  var hidden=row.querySelector('.js-commission-category-id');
  if(!code||!name)return;
  function ensureBox(input, cls){
    var cell=input.closest('td') || input.parentElement || row;
    if(cell) cell.style.position='relative';
    var box=cell.querySelector('.'+cls);
    if(!box){box=document.createElement('div');box.className='category-suggestions commission-code-suggestions '+cls;cell.appendChild(box);}
    return box;
  }
  var codeBox=ensureBox(code,'commission-code-suggestions-code');
  var nameBox=ensureBox(name,'commission-code-suggestions-name');
  function hideBoxes(){codeBox.style.display='none';nameBox.style.display='none';}
  function norm(v){return String(v||'').toLowerCase().replace(/ё/g,'е').trim();}
  function pick(c){
    if(hidden)hidden.value=c.id||'';
    code.value=c.code||'';
    name.value=c.clean||c.name||'';
    hideBoxes();
  }
  function rows(q){
    q=norm(q);
    var list=CATEGORIES.filter(function(c){
      var hay=norm((c.code||'')+' '+(c.clean||'')+' '+(c.name||''));
      return !q || hay.indexOf(q)>=0 || norm(c.code||'').indexOf(q)>=0;
    });
    if(!list.length && q.length>=2){
      list=CATEGORIES.filter(function(c){
        var hay=norm((c.code||'')+' '+(c.clean||'')+' '+(c.name||''));
        var parts=q.split(/\s+/).filter(Boolean);
        return parts.some(function(p){return p.length>=2 && hay.indexOf(p.slice(0,Math.max(2,p.length-1)))>=0;});
      });
    }
    return list.slice(0,18);
  }
  function render(q, target){
    var box=(target===code)?codeBox:nameBox;
    var other=(target===code)?nameBox:codeBox;
    other.style.display='none';
    var list=rows(q);
    box.innerHTML=list.map(function(c){return '<div data-id="'+c.id+'"><b>'+(c.code?escapeHtml(c.code)+' — ':'')+escapeHtml(c.clean||c.name)+'</b><span>'+escapeHtml(c.name||'')+'</span></div>';}).join('') || '<em>Нет совпадений</em>';
    box.style.display='block';
    box.querySelectorAll('div[data-id]').forEach(function(d){d.onmousedown=function(ev){ev.preventDefault();};d.onclick=function(){var c=CATEGORIES.find(function(x){return String(x.id)===String(d.dataset.id)}); if(c)pick(c);};});
  }
  function exactByCode(v){v=String(v||'').trim(); return CATEGORIES.find(function(x){return String(x.code||'')===v;});}
  function exactByName(v){v=norm(v); if(!v)return null; return CATEGORIES.find(function(x){return norm(x.clean||x.name)===v || norm(x.name)===v;});}
  code.addEventListener('input',function(){if(hidden)hidden.value=''; var c=exactByCode(code.value); if(c)pick(c); else render(code.value, code);});
  code.addEventListener('focus',function(){render(code.value||name.value, code);});
  name.addEventListener('input',function(){if(hidden)hidden.value=''; var c=exactByName(name.value); if(c)pick(c); else render(name.value||code.value, name);});
  name.addEventListener('focus',function(){render(name.value||code.value, name);});
  document.addEventListener('click',function(e){if(!row.contains(e.target)){hideBoxes();}});
 });
}

function initCommissionEditTotals(scope){
 var root=scope||document;
 root.querySelectorAll('.commission-edit-panel').forEach(function(panel){
  if(panel.dataset.totalBound==='1')return; panel.dataset.totalBound='1';
  var out=panel.querySelector('.js-commission-edit-total');
  function recalc(){
   var t=0; panel.querySelectorAll('input[name="commission_group_amount[]"]').forEach(function(inp){
    var v=String(inp.value||'').replace(/\s+/g,'').replace(',', '.');
    var n=parseFloat(v); if(!isNaN(n)) t+=Math.max(0,n);
   });
   if(out) out.textContent=t.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,' ')+' TJS';
  }
  panel.addEventListener('input', function(e){ if(e.target && e.target.name==='commission_group_amount[]') recalc(); });
  recalc();
 });
}


function initCommissionSeriesTotals(scope){
 var root=scope||document;
 root.querySelectorAll('.commission-summary-table-v80 .commission-series-toggle-row').forEach(function(row){
  if(row.dataset.seriesToggleBound==='1')return; row.dataset.seriesToggleBound='1';
  var next=row.nextElementSibling;
  if(!next || !next.classList.contains('commission-series-total-row'))return;
  var btn=row.querySelector('.commission-series-expand-btn');
  function setOpen(open){
    next.hidden=!open;
    next.classList.toggle('is-open', open);
    row.classList.toggle('is-expanded', open);
    if(btn){btn.setAttribute('aria-expanded', open?'true':'false'); btn.textContent=open?'Свернуть итог':'Развернуть итог';}
  }
  setOpen(false);
  row.addEventListener('click',function(e){
    if(e.target && e.target.closest('a,input,select,textarea')) return;
    e.preventDefault();
    setOpen(next.hidden);
  });
  if(btn){btn.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();setOpen(next.hidden);});}
 });
}

function initCategorySearch(group){var input=group.querySelector('.js-category-search');var hidden=group.querySelector('.js-category-id');var code=group.closest('form').querySelector('.js-operation-code');var name=group.closest('form').querySelector('.js-operation-name');var box=group.querySelector('.category-suggestions');if(!input||!box)return;function pick(c){input.value=c.name; if(hidden)hidden.value=c.id; if(code&&c.code)code.value=c.code; if(name)name.value=c.clean||c.name; box.innerHTML='';box.style.display='none';}function render(q){q=(q||'').toLowerCase().trim();var rows=CATEGORIES.filter(c=>!q||String(c.name).toLowerCase().includes(q)||String(c.clean||'').toLowerCase().includes(q)||String(c.code).includes(q));box.innerHTML=rows.map(c=>'<div data-id="'+c.id+'"><b>'+(c.code?c.code+' — ':'')+c.clean+'</b><span>'+c.name+'</span></div>').join('') || '<em>Нет совпадений. Можно оставить ручной код и название.</em>';box.style.display='block';box.querySelectorAll('div[data-id]').forEach(d=>d.onclick=()=>{var c=CATEGORIES.find(x=>String(x.id)===d.dataset.id);if(c)pick(c);});}input.addEventListener('input',()=>{if(hidden)hidden.value='';render(input.value);});input.addEventListener('focus',()=>render(input.value));document.addEventListener('click',e=>{if(!group.contains(e.target)){box.style.display='none';}});if(code){code.addEventListener('input',()=>{var c=CATEGORIES.find(x=>x.code&&String(x.code)===String(code.value).trim());if(c)pick(c);});}}

function initCommissionCategoryDatalists(root){
 var scope=root||document;
 // v74: отключаем нативные datalist, чтобы подсказка была только одна и открывалась строго под редактируемым полем.
 scope.querySelectorAll('.js-commission-code,.js-commission-name').forEach(function(inp){
   inp.removeAttribute('list');
 });
}

document.querySelectorAll('.category-search-group').forEach(initCategorySearch);
initCategoryCodeNameAuto(document);
initCommissionGroupCategoryAuto(document);
initCommissionCategoryDatalists(document);
initCommissionEditTotals(document);
initCommissionSeriesTotals(document);
initDepartmentLinks(document);
initRequestTypeForm(document);
if(document.querySelector('.requests-grid.detail-open')){document.body.classList.add('approval-detail-modal-open');document.documentElement.classList.add('approval-detail-modal-open');try{window.scrollTo(0,0);}catch(_){}}
})();
</script>
