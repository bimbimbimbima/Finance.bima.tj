<?php
require_once dirname(__DIR__) . '/src/AccessControl.php';
require_once dirname(__DIR__) . '/src/Approval.php';

$currentUser = $GLOBALS['currentUser'] ?? [];
$userId      = (int)($GLOBALS['userId'] ?? $_SESSION['user_id'] ?? $_REQUEST['user_id'] ?? 0);
$userPos     = (string)($GLOBALS['userPos'] ?? $_SESSION['user_position'] ?? $_REQUEST['user_position'] ?? ($currentUser['WORK_POSITION'] ?? ''));
if ($userId && empty($currentUser['ID'])) $currentUser['ID'] = $userId;
if ($userPos && empty($currentUser['WORK_POSITION'])) $currentUser['WORK_POSITION'] = $userPos;
$identityVerified = !empty($GLOBALS['userVerified']);
$isManager = $identityVerified ? AccessControl::isManager($portal, $userId, $userPos, $currentUser) : false;

$allowedTabs = $identityVerified ? AccessControl::allowedSettingsTabs($portal, $userId, $userPos, $isManager, $currentUser) : [];
if (empty($allowedTabs)) {
    echo '<div class="empty-state" style="padding:60px 20px">
        <div style="font-size:48px;margin-bottom:16px">🔐</div>
        <h3>Доступ запрещён</h3>
        <p style="color:var(--color-text-secondary);margin-top:8px">У вас нет прав на раздел настроек.</p>
    </div>';
    return;
}

$msg = '';
$err = '';
$tab = preg_replace('/[^a-z_]/', '', $_GET['tab'] ?? array_key_first($allowedTabs));
if (!isset($allowedTabs[$tab])) {
    $tab = array_key_first($allowedTabs);
}

function settings_can($tabName) {
    global $portal, $userId, $userPos, $isManager, $currentUser;
    return AccessControl::canSettingsTab($portal, $userId, $userPos, $tabName, $isManager, $currentUser);
}
function settings_denied(&$err) {
    $err = '⛔ Нет права на изменение этого раздела настроек';
}
function flag_post($name) { return isset($_POST[$name]) ? 1 : 0; }
function position_options($current='', $formId='') {
    $opts = ['Стажер','Младший специалист','Специалист','Ведущий специалист','Главный специалист','Руководитель','Руководитель отдела','Директор','Генеральный директор'];
    $formAttr = $formId !== '' ? ' form="'.htmlspecialchars($formId).'"' : '';
    $html = '<select name="pos"'.$formAttr.'>';
    $html .= '<option value="">— без должности —</option>';
    foreach ($opts as $o) { $sel = (mb_strtolower($current)===mb_strtolower($o)) ? ' selected' : ''; $html .= '<option value="'.htmlspecialchars($o).'"'.$sel.'>'.htmlspecialchars($o).'</option>'; }
    return $html.'</select>';
}
function local_user_name($uid, $portal) {
    $uid = (int)$uid; if ($uid<=0) return '';
    foreach (Bitrix::getLocalKnownUsers($portal, '') as $u) if ((int)$u['ID']===$uid) return $u['FULL_NAME'];
    return 'Сотрудник #'.$uid;
}
function perm_flags_from_post() {
    return [
        'can_edit_report'          => flag_post('edit_report'),
        'can_settings_accounts'    => flag_post('set_accounts'),
        'can_settings_categories'  => flag_post('set_categories'),
        'can_settings_currencies'  => flag_post('set_currencies'),
        'can_settings_departments' => flag_post('set_departments'),
        'can_settings_import'      => flag_post('set_import'),
        'can_settings_approval'    => flag_post('set_approval'),
        'can_view_dashboard'       => flag_post('view_dashboard'),
        'can_view_payments'        => flag_post('view_payments'),
        'can_add_payment'          => flag_post('view_payments'), // v55: добавление доступно через раздел Платежи
        'can_view_import'          => flag_post('view_import'),
        'can_view_report'          => flag_post('view_report'),
        'can_view_settings'        => flag_post('view_settings'),
        'can_view_requests'        => flag_post('view_requests'),
        'can_create_request'       => flag_post('create_request'),
        'can_approve_request'      => flag_post('approve_request'),
        'can_delete_request'       => flag_post('delete_request'),
        'can_view_all_requests'    => flag_post('view_all_requests'),
        'can_change_request_route' => flag_post('change_request_route'),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['sa'] ?? '';

    if (in_array($act, ['add_acc','del_acc'], true)) {
        if (!settings_can('accounts')) settings_denied($err);
        elseif ($act==='add_acc') {
            $n=trim($_POST['n']??'');
            $bal=(float)($_POST['balance']??0);
            if ($n) { DB::insert("INSERT INTO accounts (portal,name,balance) VALUES (?,?,?)",[$portal,$n,$bal]); $msg='✅ Счёт добавлен'; }
        } elseif ($act==='del_acc') {
            DB::execute("DELETE FROM accounts WHERE id=? AND portal=?",[(int)$_POST['id'],$portal]); $msg='✅ Удалено';
        }
    }

    if (in_array($act, ['add_cat','del_cat'], true)) {
        if (!settings_can('categories')) settings_denied($err);
        elseif ($act==='add_cat') {
            $n=trim($_POST['n']??''); $t=$_POST['t']??'expense';
            if ($n) { DB::insert("INSERT INTO categories (portal,name,type) VALUES (?,?,?)",[$portal,$n,$t]); $msg='✅ Статья добавлена'; }
        } elseif ($act==='del_cat') {
            DB::execute("DELETE FROM categories WHERE id=? AND portal=?",[(int)$_POST['id'],$portal]); $msg='✅ Удалено';
        }
    }

    if (in_array($act, ['save_cur','del_cur'], true)) {
        if (!settings_can('currencies')) settings_denied($err);
        elseif ($act==='save_cur') {
            $code=strtoupper(trim($_POST['code']??'')); $name=trim($_POST['name']??''); $rate=(float)($_POST['rate']??1);
            if ($rate <= 0) $rate = 1;
            $rateDate = $_POST['rate_date'] ?? date('Y-m-d');
            if ($code&&$name) {
                // Обновляем текущий курс для новых операций. Уже сохранённые платежи не пересчитываются,
                // потому что в payments хранится amount_tjs как снимок суммы на дату операции.
                DB::execute("INSERT INTO currencies (portal,code,name,rate) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),rate=VALUES(rate)",
                    [$portal,$code,$name,$rate]);
                try {
                    DB::execute("INSERT INTO currency_rates (portal,code,rate_date,rate) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE rate=VALUES(rate)",
                        [$portal,$code,$rateDate,$rate]);
                } catch (Throwable $e) { app_log('currency_rates save failed: '.$e->getMessage()); }
                $msg='✅ Валюта сохранена. Старые отчёты не пересчитаны';
            }
        } elseif ($act==='del_cur') {
            DB::execute("DELETE FROM currencies WHERE id=? AND portal=? AND is_base=0",[(int)$_POST['id'],$portal]); $msg='✅ Удалено';
        }
    }

    if (in_array($act, ['add_dept','del_dept'], true)) {
        if (!settings_can('departments')) settings_denied($err);
        elseif ($act==='add_dept') {
            $n=trim($_POST['n']??''); $p=trim($_POST['p']??'')?:null;
            if ($n) { DB::insert("INSERT INTO departments (portal,name,parent) VALUES (?,?,?)",[$portal,$n,$p]); $msg='✅ Добавлено'; }
        } elseif ($act==='del_dept') {
            DB::execute("DELETE FROM departments WHERE id=? AND portal=?",[(int)$_POST['id'],$portal]); $msg='✅ Удалено';
        }
    }

    if (in_array($act, ['add_perm','edit_perm','del_perm'], true)) {
        // Права доступа меняет только руководитель/админ. Делегированного доступа к этому табу нет.
        if (!$isManager) settings_denied($err);
        elseif ($act==='add_perm') {
            $accId=(int)($_POST['acc']??0); $pos=trim($_POST['pos']??''); $uid=(int)($_POST['uid']??0); $uname=trim($_POST['uname']??''); $mgr=isset($_POST['mgr']);
            $flags = perm_flags_from_post();
            if ($accId || $mgr || $uid || $pos !== '' || array_sum($flags)>0) {
                AccessControl::addPermission($portal,$accId,$pos,$uid,$mgr,$flags,$uname); $msg='✅ Право добавлено';
            }
        } elseif ($act==='edit_perm') {
            $flags = perm_flags_from_post();
            AccessControl::updatePermission($portal,(int)$_POST['id'],(int)($_POST['acc']??0),trim($_POST['pos']??''),(int)($_POST['uid']??0),isset($_POST['mgr']),$flags,trim($_POST['uname']??''));
            $msg='✅ Право обновлено';
        } elseif ($act==='del_perm') {
            AccessControl::deletePermission($portal,(int)$_POST['id']); $msg='✅ Удалено';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $act === 'save_approval_route') {
        if (!settings_can('approval')) settings_denied($err);
        else {
            $stages = $_POST['stage'] ?? [];
            $labels = $_POST['stage_label'] ?? [];
            $ids = $_POST['approver_ids'] ?? [];
            $names = $_POST['approver_names'] ?? [];
            $orders = $_POST['sort_order'] ?? [];
            $enabled = $_POST['stage_enabled'] ?? [];
            $hints = $_POST['stage_hint'] ?? [];
            $icons = $_POST['stage_icon'] ?? [];
            $threshold = (float)($_POST['threshold_amount'] ?? 15000);
            $rawRouteType = preg_replace('/[^a-z0-9_]/','',(string)($_POST['route_type'] ?? 'commission'));
            $routeType = in_array($rawRouteType, ['regular','commission','commission_box','commission2'], true) ? $rawRouteType : 'commission';
            foreach ($stages as $i=>$stage) {
                $stage = preg_replace('/[^a-z0-9_]/','',(string)$stage);
                Approval::saveRouteSetting(
                    $portal,
                    $stage,
                    $ids[$i] ?? '',
                    $names[$i] ?? '',
                    $threshold,
                    $labels[$i] ?? '',
                    $orders[$i] ?? (($i+1)*10),
                    isset($enabled[$stage]) ? 1 : 0,
                    $hints[$i] ?? '',
                    $icons[$i] ?? '',
                    $routeType
                );
            }
            foreach (($_POST['remove_stage'] ?? []) as $stageToRemove) {
                Approval::deleteRouteSetting($portal, $stageToRemove, $routeType);
            }
            $msg = '✅ Маршрут согласования сохранён';
        }
}

$accounts    = DB::fetchAll("SELECT * FROM accounts WHERE portal=? ORDER BY name",[$portal]);
$categories  = DB::fetchAll("SELECT * FROM categories WHERE portal=? ORDER BY type,name",[$portal]);
$currencies  = DB::fetchAll("SELECT * FROM currencies WHERE portal=? ORDER BY is_base DESC,code",[$portal]);
$permissions = AccessControl::getPermissions($portal);
$departments = DB::fetchAll("SELECT * FROM departments WHERE portal=? ORDER BY parent,name",[$portal]);
$rateFilterDate = trim((string)($_GET['rate_date_filter'] ?? ''));
try {
    $rateHistory = $rateFilterDate !== ''
        ? DB::fetchAll("SELECT * FROM currency_rates WHERE portal=? AND rate_date=? ORDER BY rate_date DESC, code", [$portal,$rateFilterDate])
        : DB::fetchAll("SELECT * FROM currency_rates WHERE portal=? ORDER BY rate_date DESC, code LIMIT 100", [$portal]);
} catch (Throwable $e) { $rateHistory = []; }
$deptParents = DB::fetchAll("SELECT DISTINCT name FROM departments WHERE portal=? AND parent IS NULL ORDER BY name",[$portal]);
?>

<div class="page-header"><h1>⚙️ Настройки</h1></div>
<?php if ($msg): ?><div class="alert alert-success"><?=$msg?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?=$err?></div><?php endif; ?>

<div class="stabs stabs-apple">
  <?php foreach ($allowedTabs as $k=>$label): ?>
    <a href="<?=app_link(['page'=>'settings','tab'=>$k])?>" class="stab <?=$tab===$k?'on':''?>"><?=$label?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab==='accounts'): ?>
<div class="form-card">
  <h3>Счета и кассы</h3>
  <form method="POST" class="inline-form">
    <input type="hidden" name="sa" value="add_acc">
    <input type="text" name="n" placeholder="Название счёта" required>
    <input type="number" name="balance" step="0.01" placeholder="Начальный остаток" style="width:150px">
    <button type="submit" class="btn btn-primary">Добавить</button>
  </form>
  <?php $bals = Payment::accountBalances($portal); $balMap = array_column($bals,'balance','id'); ?>
  <div class="tbl-wrap mt12">
    <table class="data-table"><thead><tr><th>Название</th><th>Баланс TJS</th><th></th></tr></thead><tbody>
    <?php foreach ($accounts as $a): ?>
      <tr><td><?=htmlspecialchars($a['name'])?></td><td class="<?=($balMap[$a['id']]??0)>=0?'text-income':'text-expense'?>"><?=number_format($balMap[$a['id']]??0,2,'.',' ')?></td>
      <td><form method="POST" style="display:inline"><input type="hidden" name="sa" value="del_acc"><input type="hidden" name="id" value="<?=$a['id']?>"><button class="btn-delete" onclick="return confirm('Удалить?')">✕</button></form></td></tr>
    <?php endforeach; ?></tbody></table>
  </div>
</div>

<?php elseif ($tab==='categories'): ?>
<div class="form-card">
  <h3>Статьи (Типы операций)</h3>
  <p class="hint">Формат: <code>1101 - Название</code> — в отчёте код и описание разделяются автоматически.</p>
  <form method="POST" class="inline-form"><input type="hidden" name="sa" value="add_cat"><input type="text" name="n" placeholder="1101 - Оплата поставщику" required style="flex:3"><select name="t"><option value="expense">📉 Расход</option><option value="income">📈 Приход</option></select><button type="submit" class="btn btn-primary">Добавить</button></form>
  <div class="settings-two-grid settings-categories-grid">
    <?php foreach (['income'=>'📈 Приход', 'expense'=>'📉 Расход'] as $ct=>$title): ?>
    <div class="settings-list-card"><div class="sub-title"><?=$title?></div><div class="tbl-wrap settings-scroll"><table class="data-table"><tbody>
      <?php foreach (array_filter($categories,fn($c)=>$c['type']===$ct) as $c): ?>
      <tr><td style="font-size:12px"><?=htmlspecialchars($c['name'])?></td><td style="width:30px"><form method="POST" style="display:inline"><input type="hidden" name="sa" value="del_cat"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="btn-delete" onclick="return confirm('Удалить?')">✕</button></form></td></tr>
      <?php endforeach; ?>
    </tbody></table></div></div>
    <?php endforeach; ?>
  </div>
</div>

<?php elseif ($tab==='currencies'): ?>
<div class="form-card">
  <h3>Валюты и курсы</h3>
  <p class="hint">Курс сохраняется для новых операций. Старые отчёты не меняются, потому что сумма в TJS фиксируется в платеже на момент создания/импорта.</p>
  <form method="POST" class="inline-form" style="margin-bottom:14px"><input type="hidden" name="sa" value="save_cur"><input type="text" name="code" placeholder="USD" maxlength="10" style="width:70px" required><input type="text" name="name" placeholder="Название валюты" required style="flex:2"><input type="date" name="rate_date" value="<?=date('Y-m-d')?>"><input type="number" name="rate" step="0.0001" placeholder="Курс к TJS" required style="width:130px"><button type="submit" class="btn btn-primary">Добавить</button></form>
  <div class="tbl-wrap"><table class="data-table"><thead><tr><th>Код</th><th>Название</th><th>Курс к TJS</th><th>Дата курса</th><th>Базовая</th><th></th></tr></thead><tbody>
  <?php foreach ($currencies as $c): ?>
    <tr><td><strong><?=htmlspecialchars($c['code'])?></strong></td><td><?=htmlspecialchars($c['name'])?></td><td><form method="POST" style="display:flex;gap:6px;align-items:center"><input type="hidden" name="sa" value="save_cur"><input type="hidden" name="code" value="<?=htmlspecialchars($c['code'])?>"><input type="hidden" name="name" value="<?=htmlspecialchars($c['name'])?>"><input type="number" name="rate" step="0.0001" value="<?=htmlspecialchars((string)$c['rate'])?>" style="width:110px;padding:5px 8px;border-radius:5px;border:1px solid var(--color-border-secondary);font-size:13px"><input type="date" name="rate_date" value="<?=date('Y-m-d')?>" style="width:130px;padding:5px 8px;border-radius:5px;border:1px solid var(--color-border-secondary);font-size:13px"><button type="submit" class="btn btn-primary" style="padding:5px 10px;font-size:12px">💾</button></form></td><td><?=date('d.m.Y')?></td><td><?=$c['is_base']?'✅ Базовая':'—'?></td><td><?php if (!$c['is_base']): ?><form method="POST" style="display:inline"><input type="hidden" name="sa" value="del_cur"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="btn-delete" onclick="return confirm('Удалить валюту?')">✕</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <div class="form-card mt12 currency-history-card">
    <h3>История изменения курсов</h3>
    <form method="GET" class="inline-form" style="margin-bottom:10px">
      <?php foreach (($GLOBALS['APP_CONTEXT_PARAMS'] ?? []) as $k=>$v): ?><input type="hidden" name="<?=htmlspecialchars($k)?>" value="<?=htmlspecialchars($v)?>"><?php endforeach; ?>
      <input type="hidden" name="page" value="settings"><input type="hidden" name="tab" value="currencies">
      <input type="date" name="rate_date_filter" value="<?=htmlspecialchars($rateFilterDate)?>">
      <button class="btn btn-primary">Показать</button>
      <a class="btn btn-light" href="<?=app_link(['page'=>'settings','tab'=>'currencies'])?>">Сброс</a>
    </form>
    <div class="tbl-wrap"><table class="data-table"><thead><tr><th>Дата изменения</th><th>Валюта</th><th>Курс</th></tr></thead><tbody>
      <?php foreach ($rateHistory as $rh): ?><tr><td><?=htmlspecialchars($rh['rate_date'])?></td><td><?=htmlspecialchars($rh['code'])?></td><td><?=htmlspecialchars((string)$rh['rate'])?></td></tr><?php endforeach; ?>
      <?php if (!$rateHistory): ?><tr><td colspan="3" class="empty-cell">Нет записей по курсам</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
</div>

<?php elseif ($tab==='departments'): ?>
<div class="form-card">
  <h3>Отделы и подотделы</h3>
  <form method="POST" class="inline-form"><input type="hidden" name="sa" value="add_dept"><input type="text" name="n" placeholder="Название" required style="flex:2"><select name="p" style="flex:1"><option value="">— Главный отдел —</option><?php foreach ($deptParents as $dp): ?><option value="<?=htmlspecialchars($dp['name'])?>"><?=htmlspecialchars($dp['name'])?> (подотдел)</option><?php endforeach; ?></select><button type="submit" class="btn btn-primary">Добавить</button></form>
  <div class="settings-two-grid settings-departments-grid">
  <?php foreach ([0=>'🏢 Отделы', 1=>'📁 Подотделы'] as $isSub=>$title): ?>
    <div class="settings-list-card"><div class="sub-title"><?=$title?></div><div class="tbl-wrap settings-scroll"><table class="data-table"><tbody>
    <?php foreach (array_filter($departments,fn($d)=>$isSub ? (bool)$d['parent'] : !$d['parent']) as $d): ?>
      <tr><td><?=htmlspecialchars($d['name'])?><?php if($isSub): ?><div style="color:#64748b;font-size:11px"><?=htmlspecialchars($d['parent']??'')?></div><?php endif; ?></td><td style="width:30px"><form method="POST" style="display:inline"><input type="hidden" name="sa" value="del_dept"><input type="hidden" name="id" value="<?=$d['id']?>"><button class="btn-delete" onclick="return confirm('Удалить?')">✕</button></form></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  <?php endforeach; ?>
  </div>
</div>

<?php elseif ($tab==='access' && $isManager): ?>
<div class="form-card">
  <h3>🔐 Права доступа</h3>
  <p class="hint">Обычный сотрудник видит только назначенные кассы и только разрешённые разделы системы. Раздел "Права" доступен только руководителю/администратору.</p>
  <form method="POST" id="permAccessForm104" class="rights104-form" style="margin-top:14px">
    <input type="hidden" name="sa" value="add_perm">

    <div class="rights104-fields">
      <label class="rights104-field">
        <span>Касса / счёт</span>
        <select name="acc"><option value="0">Все / без кассы</option><?php foreach ($accounts as $a): ?><option value="<?=$a['id']?>"><?=htmlspecialchars($a['name'])?></option><?php endforeach; ?></select>
      </label>
      <label class="rights104-field">
        <span>Должность</span>
        <?=position_options('')?>
      </label>
      <label class="rights104-field rights104-user-field perm-user-field">
        <span>Сотрудник Bitrix24</span>
        <input type="hidden" name="uid" class="perm-user-id">
        <input type="hidden" name="uname" class="perm-user-name-hidden">
        <input type="text" class="perm-user-name" placeholder="Выберите сотрудника" readonly>
        <button type="button" class="btn btn-light btn-sm js-perm-pick-user">Выбрать</button>
      </label>
    </div>

    <div class="rights104-groups">
      <div class="rights104-card rights104-role-card">
        <div class="rights104-card-title">Роль</div>
        <label class="rights104-check"><input type="checkbox" name="mgr"><span>Руководитель</span></label>
      </div>
      <div class="rights104-card rights104-settings-card">
        <div class="rights104-card-title">Настройки</div>
        <label class="rights104-check"><input type="checkbox" name="set_accounts"><span>Счета / кассы</span></label>
        <label class="rights104-check"><input type="checkbox" name="set_categories"><span>Статьи</span></label>
        <label class="rights104-check"><input type="checkbox" name="set_currencies"><span>Валюты</span></label>
        <label class="rights104-check"><input type="checkbox" name="set_departments"><span>Отделы</span></label>
        <label class="rights104-check"><input type="checkbox" name="set_approval"><span>Маршрут заявок</span></label>
      </div>
      <div class="rights104-card rights104-wide-card rights104-visibility-card">
        <div class="rights104-card-title">Видимость разделов системы</div>
        <div class="rights104-visibility-grid">
          <label class="rights104-check rights104-flat-check"><input type="checkbox" name="view_dashboard" checked> <span>Дашборд</span></label>
          <label class="rights104-check rights104-flat-check"><input type="checkbox" name="view_payments" checked> <span>Платежи</span></label>
          <label class="rights104-check rights104-flat-check"><input type="checkbox" name="view_import"> <span>Импорт</span></label>

          <details class="rights104-details rights104-report-details">
            <summary class="rights104-summary">
              <span class="rights104-card-main">
                <label class="rights104-check rights104-section-check" onclick="event.stopPropagation()"><input type="checkbox" name="view_report" checked> <span>Отчёты</span></label>
              </span>
              <span class="rights104-card-action"><span class="show-label">Раскрыть</span><span class="hide-label">Скрыть</span></span>
            </summary>
            <div class="rights104-nested-body">
              <label class="rights104-check rights104-sub-check"><input type="checkbox" name="edit_report"> <span>Редактирование отчёта</span></label>
            </div>
          </details>

          <label class="rights104-check rights104-flat-check"><input type="checkbox" name="view_settings"> <span>Настройки</span></label>

          <details class="rights104-details rights104-approval-details">
            <summary class="rights104-summary">
              <span class="rights104-card-main">
                <label class="rights104-check rights104-section-check" onclick="event.stopPropagation()"><input type="checkbox" name="view_requests" checked> <span>Согласование</span></label>
              </span>
              <span class="rights104-card-action"><span class="show-label">Раскрыть</span><span class="hide-label">Скрыть</span></span>
            </summary>
            <div class="rights104-nested-body">
              <label class="rights104-check rights104-sub-check"><input type="checkbox" name="create_request" checked> <span>Создать заявку</span></label>
              <label class="rights104-check rights104-sub-check"><input type="checkbox" name="approve_request"> <span>Согласовывать</span></label>
              <label class="rights104-check rights104-sub-check"><input type="checkbox" name="delete_request"> <span>Удалять заявки</span></label>
              <label class="rights104-check rights104-sub-check"><input type="checkbox" name="view_all_requests"> <span>Все заявки</span></label>
              <label class="rights104-check rights104-sub-check"><input type="checkbox" name="change_request_route"> <span>Изменять маршрут</span></label>
            </div>
          </details>
        </div>
      </div>
    </div>

    <div class="rights104-actions">
      <button type="submit" class="btn btn-primary rights104-submit">Добавить право</button>
    </div>
  </form>
  <div class="tbl-wrap mt12"><table class="data-table perm-table"><thead><tr><th>Касса</th><th>Должность</th><th>Сотрудник</th><th>Руковод.</th><th>Счета</th><th>Статьи</th><th>Валюты</th><th>Отделы</th><th>Маршр.</th><th>Дашб.</th><th>Плат.</th><th>Имп.</th><th>Отч.</th><th>Ред. отч.</th><th>Настр.</th><th>Заявки</th><th>Созд.</th><th>Согл.</th><th>Удал.</th><th>Все заявки</th><th>Изм. маршрут</th><th></th></tr></thead><tbody>
  <?php foreach ($permissions as $p): $fid='perm-edit-'.(int)$p['id']; ?>
    <form id="<?=$fid?>" method="POST">
      <input type="hidden" name="sa" value="edit_perm">
      <input type="hidden" name="id" value="<?=$p['id']?>">
    </form>
    <tr>
      <td><select name="acc" form="<?=$fid?>"><option value="0">Все/без кассы</option><?php foreach ($accounts as $a): ?><option value="<?=$a['id']?>" <?=((int)$p['account_id']===(int)$a['id'])?'selected':''?>><?=htmlspecialchars($a['name'])?></option><?php endforeach; ?></select></td>
      <td><?=position_options($p['bitrix_position']??'', $fid)?></td>
      <td><input type="hidden" name="uid" form="<?=$fid?>" class="perm-user-id" value="<?=htmlspecialchars((string)($p['bitrix_user_id']??''))?>"><input type="hidden" name="uname" form="<?=$fid?>" class="perm-user-name-hidden" value="<?=htmlspecialchars((string)($p['bitrix_user_name']??local_user_name($p['bitrix_user_id']??0, $portal)))?>"><input type="text" class="perm-user-name" value="<?=htmlspecialchars((string)($p['bitrix_user_name']??local_user_name($p['bitrix_user_id']??0, $portal)))?>" readonly><button type="button" class="btn btn-light btn-sm js-perm-pick-user" data-form="<?=$fid?>">Выбрать</button></td>
      <td><input type="checkbox" name="mgr" form="<?=$fid?>" <?=!empty($p['is_manager'])?'checked':''?>></td>
      <td><input type="checkbox" name="set_accounts" form="<?=$fid?>" <?=!empty($p['can_settings_accounts'])?'checked':''?>></td>
      <td><input type="checkbox" name="set_categories" form="<?=$fid?>" <?=!empty($p['can_settings_categories'])?'checked':''?>></td>
      <td><input type="checkbox" name="set_currencies" form="<?=$fid?>" <?=!empty($p['can_settings_currencies'])?'checked':''?>></td>
      <td><input type="checkbox" name="set_departments" form="<?=$fid?>" <?=!empty($p['can_settings_departments'])?'checked':''?>></td>
      <td><input type="checkbox" name="set_approval" form="<?=$fid?>" <?=!empty($p['can_settings_approval'])?'checked':''?>></td>
      <td><input type="checkbox" name="view_dashboard" form="<?=$fid?>" <?=!empty($p['can_view_dashboard'])?'checked':''?>></td>
      <td><input type="checkbox" name="view_payments" form="<?=$fid?>" <?=!empty($p['can_view_payments'])?'checked':''?>></td>
      <td><input type="checkbox" name="view_import" form="<?=$fid?>" <?=!empty($p['can_view_import'])?'checked':''?>></td>
      <td><input type="checkbox" name="view_report" form="<?=$fid?>" <?=!empty($p['can_view_report'])?'checked':''?>></td>
      <td><input type="checkbox" name="edit_report" form="<?=$fid?>" <?=!empty($p['can_edit_report'])?'checked':''?>></td>
      <td><input type="checkbox" name="view_settings" form="<?=$fid?>" <?=!empty($p['can_view_settings'])?'checked':''?>></td>
      <td><input type="checkbox" name="view_requests" form="<?=$fid?>" <?=!empty($p['can_view_requests'])?'checked':''?>></td>
      <td><input type="checkbox" name="create_request" form="<?=$fid?>" <?=!empty($p['can_create_request'])?'checked':''?>></td>
      <td><input type="checkbox" name="approve_request" form="<?=$fid?>" <?=!empty($p['can_approve_request'])?'checked':''?>></td>
      <td><input type="checkbox" name="delete_request" form="<?=$fid?>" <?=!empty($p['can_delete_request'])?'checked':''?>></td>
      <td><input type="checkbox" name="view_all_requests" form="<?=$fid?>" <?=!empty($p['can_view_all_requests'])?'checked':''?>></td>
      <td><input type="checkbox" name="change_request_route" form="<?=$fid?>" <?=!empty($p['can_change_request_route'])?'checked':''?>></td>
      <td style="white-space:nowrap">
        <button class="btn btn-primary" form="<?=$fid?>" style="padding:5px 8px;font-size:12px">💾</button>
        <form method="POST" style="display:inline"><input type="hidden" name="sa" value="del_perm"><input type="hidden" name="id" value="<?=$p['id']?>"><button class="btn-delete" onclick="return confirm('Удалить?')">✕</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($permissions)): ?><tr><td colspan="22" style="text-align:center;color:var(--color-text-secondary);padding:20px">Нет правил. Обычные сотрудники не видят кассы до назначения права.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>


<style>
/* v92: понятная вертикальная структура прав без смещения соседних разделов */
.perm-groups{grid-template-columns:repeat(3,minmax(260px,1fr))!important;align-items:stretch!important}
.perm-group-card,.perm-role-card,.perm-visibility-card{min-height:100%!important;display:flex!important;flex-direction:column!important;gap:8px!important}
.perm-visibility-card{grid-column:span 1!important}
.perm-visibility-list,.perm-v92-list{display:flex!important;flex-direction:column!important;gap:8px!important;width:100%!important}
.perm-right-row,.perm-group-card>label,.perm-nested-card summary{min-height:38px!important;display:flex!important;align-items:center!important;gap:8px!important;border:1px solid rgba(226,232,240,.96)!important;border-radius:14px!important;background:rgba(255,255,255,.78)!important;padding:8px 10px!important;margin:0!important;box-sizing:border-box!important;width:100%!important}
.perm-right-row input,.perm-section-check input,.perm-group-card>label input{flex:0 0 auto!important}
.perm-section-check{display:inline-flex!important;align-items:center!important;gap:8px!important;font-weight:900!important;margin:0!important;min-width:0!important}
.perm-section-check span,.perm-right-row span{min-width:0!important;overflow:hidden!important;text-overflow:ellipsis!important}
.perm-nested-card{border:1px solid rgba(148,163,184,.34)!important;border-radius:16px!important;background:rgba(248,250,252,.74)!important;padding:0!important;margin:0!important;box-shadow:none!important;overflow:hidden!important}
.perm-nested-card summary{cursor:pointer!important;justify-content:space-between!important;border:0!important;border-radius:16px!important;background:rgba(255,255,255,.86)!important;list-style:none!important}
.perm-nested-card summary::-webkit-details-marker{display:none!important}
.perm-nested-card summary:after{content:'▾';font-size:13px;color:#64748b;transition:transform .16s ease;margin-left:4px}
.perm-nested-card:not([open]) summary:after{transform:rotate(-90deg)}
.perm-open-hint{font-size:11px!important;font-weight:800!important;color:#64748b!important;background:rgba(241,245,249,.92)!important;border:1px solid rgba(226,232,240,.9)!important;border-radius:999px!important;padding:4px 8px!important;white-space:nowrap!important;margin-left:auto!important}
.perm-nested-body{display:flex!important;flex-direction:column!important;gap:8px!important;padding:8px!important;margin:0!important}
.perm-sub-row{background:rgba(255,255,255,.92)!important;padding-left:28px!important}
.perm-report-right[open],.perm-approval-right[open]{box-shadow:0 10px 26px rgba(15,23,42,.055)!important}
@media(max-width:1200px){.perm-groups{grid-template-columns:1fr 1fr!important}.perm-visibility-card{grid-column:1/-1!important}}
@media(max-width:760px){.perm-groups{grid-template-columns:1fr!important}.perm-open-hint{display:none!important}}
</style>
<script>
(function(){
var permInput=null;
function fullName(u){return (u.FULL_NAME||u.fullName||u.name||u.NAME_FORMATTED||((u.LAST_NAME||u.lastName||'')+' '+(u.NAME||u.firstName||'')).trim()||u.title||u.label||('Сотрудник #'+(u.ID||u.id||u.USER_ID||''))).trim();}
function normalizeUser(u){if(!u)return null;if(u.user)u=u.user;var id=u.ID||u.id||u.USER_ID||u.user_id||u.value||u.VALUE;if(!id)return null;return{ID:String(id),FULL_NAME:fullName(u),WORK_POSITION:u.WORK_POSITION||u.position||u.subtitle||''};}
function fillPermUser(u){u=normalizeUser(u); if(!u||!permInput)return; var wrap=permInput.closest('.perm-user-field')||permInput.parentElement; var hid=wrap.querySelector('.perm-user-id'); var name=wrap.querySelector('.perm-user-name'); var hname=wrap.querySelector('.perm-user-name-hidden'); if(hid)hid.value=u.ID; if(name)name.value=u.FULL_NAME; if(hname)hname.value=u.FULL_NAME;}
function choosePermUser(btn){permInput=btn; if(typeof BX24!=='undefined'&&typeof BX24.selectUser==='function'){BX24.selectUser(function(u){fillPermUser(u);});return;} alert('Окно выбора Bitrix24 недоступно. Проверьте права локального приложения на пользователей.');}
document.querySelectorAll('.js-perm-pick-user').forEach(btn=>btn.addEventListener('click',()=>choosePermUser(btn)));
})();
</script>

<style>
/* v102: окончательная фиксация прав строго внутри карточек */
#permAccessForm102.perm-form-v19,
#permAccessForm102.perm-form-v19 *{box-sizing:border-box!important;}
#permAccessForm102.perm-form-v19{width:100%!important;max-width:100%!important;min-width:0!important;overflow:visible!important;padding:14px!important;}
#permAccessForm102 .perm-fields-row,
#permAccessForm102 .perm-fields-row.perm-fields-v94{display:grid!important;grid-template-columns:minmax(220px,1fr) minmax(260px,1.1fr) minmax(360px,1.35fr)!important;gap:12px!important;align-items:end!important;width:100%!important;max-width:100%!important;min-width:0!important;margin:0 0 14px!important;}
#permAccessForm102 .perm-field{display:grid!important;grid-template-columns:1fr!important;gap:6px!important;min-width:0!important;width:100%!important;}
#permAccessForm102 .perm-field>span{display:block!important;font-size:12px!important;font-weight:900!important;color:#475569!important;text-align:left!important;}
#permAccessForm102 .perm-field select,
#permAccessForm102 .perm-field input[type=text]{width:100%!important;max-width:100%!important;min-width:0!important;height:42px!important;min-height:42px!important;border-radius:14px!important;}
#permAccessForm102 .perm-user-field{display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;grid-template-areas:'title title' 'input button'!important;gap:6px 8px!important;align-items:end!important;}
#permAccessForm102 .perm-user-field>span{grid-area:title!important;}
#permAccessForm102 .perm-user-field .perm-user-name{grid-area:input!important;}
#permAccessForm102 .perm-user-field .js-perm-pick-user{grid-area:button!important;height:42px!important;min-height:42px!important;min-width:120px!important;}
#permAccessForm102 .perm-groups,
#permAccessForm102 .perm-groups.perm-groups-v94{display:grid!important;grid-template-columns:minmax(280px,1fr) minmax(420px,1.35fr)!important;gap:14px!important;align-items:stretch!important;justify-items:stretch!important;width:100%!important;max-width:100%!important;min-width:0!important;overflow:visible!important;margin:0!important;padding:0!important;}
#permAccessForm102 .perm-group-card,
#permAccessForm102 .perm-role-card-v94,
#permAccessForm102 .perm-settings-card-v94,
#permAccessForm102 .perm-visibility-card-v94{position:relative!important;left:auto!important;right:auto!important;top:auto!important;bottom:auto!important;inset:auto!important;transform:none!important;float:none!important;clear:none!important;width:100%!important;max-width:100%!important;min-width:0!important;margin:0!important;padding:16px!important;overflow:hidden!important;display:flex!important;flex-direction:column!important;align-items:stretch!important;justify-content:flex-start!important;gap:8px!important;}
#permAccessForm102 .perm-role-card-v94{grid-column:1!important;grid-row:1!important;min-height:300px!important;height:100%!important;}
#permAccessForm102 .perm-settings-card-v94{grid-column:2!important;grid-row:1!important;min-height:300px!important;height:100%!important;}
#permAccessForm102 .perm-visibility-card-v94,
#permAccessForm102 .perm-visibility-card{grid-column:1 / -1!important;grid-row:auto!important;min-height:220px!important;height:auto!important;}
#permAccessForm102 .perm-group-title{display:block!important;width:100%!important;margin:0 0 6px!important;padding:0!important;min-height:18px!important;text-align:left!important;flex:0 0 auto!important;}
#permAccessForm102 .perm-group-card>label,
#permAccessForm102 .perm-item-v101,
#permAccessForm102 .perm-right-row,
#permAccessForm102 .perm-flat-card,
#permAccessForm102 .perm-water-subcard,
#permAccessForm102 .perm-section-check{position:relative!important;left:auto!important;right:auto!important;top:auto!important;bottom:auto!important;inset:auto!important;transform:none!important;float:none!important;clear:none!important;display:flex!important;flex-direction:row!important;align-items:center!important;justify-content:center!important;gap:10px!important;width:100%!important;max-width:100%!important;min-width:0!important;height:44px!important;min-height:44px!important;max-height:44px!important;margin:0!important;padding:0 12px!important;border:1px solid rgba(203,213,225,.78)!important;border-radius:16px!important;background:linear-gradient(135deg,rgba(255,255,255,.96),rgba(248,250,252,.86))!important;color:#334155!important;font-size:12px!important;font-weight:850!important;line-height:1.2!important;text-align:center!important;text-indent:0!important;white-space:nowrap!important;overflow:hidden!important;opacity:1!important;visibility:visible!important;}
#permAccessForm102 input[type=checkbox],
#permAccessForm102 .perm-group-card input[type=checkbox],
#permAccessForm102 .perm-item-v101 input[type=checkbox],
#permAccessForm102 .perm-section-check input[type=checkbox]{appearance:auto!important;-webkit-appearance:auto!important;position:static!important;left:auto!important;right:auto!important;top:auto!important;bottom:auto!important;inset:auto!important;transform:none!important;float:none!important;clear:none!important;display:inline-block!important;flex:0 0 16px!important;width:16px!important;height:16px!important;min-width:16px!important;min-height:16px!important;max-width:16px!important;max-height:16px!important;margin:0!important;padding:0!important;align-self:center!important;justify-self:center!important;opacity:1!important;visibility:visible!important;accent-color:#2563eb!important;}
#permAccessForm102 .perm-group-card>label>span,
#permAccessForm102 .perm-item-v101>span,
#permAccessForm102 .perm-right-row>span,
#permAccessForm102 .perm-flat-card>span,
#permAccessForm102 .perm-water-subcard>span,
#permAccessForm102 .perm-section-check>span{position:static!important;display:block!important;flex:0 1 auto!important;min-width:0!important;max-width:calc(100% - 26px)!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;text-align:center!important;color:#334155!important;opacity:1!important;visibility:visible!important;}
#permAccessForm102 .perm-visibility-list,
#permAccessForm102 .perm-visibility-list.perm-v93-list{display:grid!important;grid-template-columns:repeat(3,minmax(220px,1fr))!important;gap:10px!important;align-items:start!important;justify-items:stretch!important;width:100%!important;max-width:100%!important;min-width:0!important;margin:0!important;padding:0!important;overflow:visible!important;}
#permAccessForm102 details.perm-water-card{position:relative!important;left:auto!important;right:auto!important;top:auto!important;bottom:auto!important;transform:none!important;display:block!important;width:100%!important;max-width:100%!important;min-width:0!important;height:auto!important;min-height:44px!important;margin:0!important;padding:0!important;overflow:hidden!important;border:1px solid rgba(203,213,225,.78)!important;border-radius:16px!important;background:linear-gradient(135deg,rgba(255,255,255,.96),rgba(248,250,252,.86))!important;}
#permAccessForm102 details.perm-water-card:not([open]){height:44px!important;min-height:44px!important;max-height:44px!important;}
#permAccessForm102 details.perm-water-card[open]{height:auto!important;min-height:44px!important;max-height:none!important;grid-column:auto!important;}
#permAccessForm102 details.perm-water-card>summary{position:relative!important;display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;align-items:center!important;gap:10px!important;width:100%!important;max-width:100%!important;min-width:0!important;height:44px!important;min-height:44px!important;max-height:44px!important;margin:0!important;padding:0 10px!important;overflow:hidden!important;list-style:none!important;cursor:pointer!important;border:0!important;background:transparent!important;}
#permAccessForm102 details.perm-water-card>summary::-webkit-details-marker{display:none!important;}
#permAccessForm102 .perm-card-main{position:static!important;display:flex!important;align-items:center!important;justify-content:center!important;width:100%!important;min-width:0!important;max-width:100%!important;height:100%!important;margin:0!important;padding:0!important;overflow:hidden!important;}
#permAccessForm102 .perm-card-main .perm-section-check{border:0!important;background:transparent!important;height:100%!important;min-height:0!important;max-height:none!important;padding:0!important;margin:0!important;}
#permAccessForm102 .perm-card-action{position:static!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;justify-self:end!important;align-self:center!important;min-width:88px!important;max-width:100px!important;height:30px!important;min-height:30px!important;max-height:30px!important;margin:0!important;padding:0 10px!important;border-radius:999px!important;white-space:nowrap!important;font-size:11px!important;font-weight:900!important;}
#permAccessForm102 .perm-water-card .perm-nested-body{display:grid!important;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))!important;gap:8px!important;width:100%!important;max-width:100%!important;min-width:0!important;margin:0!important;padding:10px!important;overflow:visible!important;}
#permAccessForm102 .perm-actions-bottom{display:flex!important;justify-content:center!important;width:100%!important;margin-top:14px!important;padding-top:12px!important;border-top:1px solid rgba(226,232,240,.9)!important;}
#permAccessForm102 .perm-submit{width:min(280px,100%)!important;justify-content:center!important;}
@media(max-width:1180px){#permAccessForm102 .perm-fields-row,#permAccessForm102 .perm-fields-row.perm-fields-v94{grid-template-columns:1fr 1fr!important;}#permAccessForm102 .perm-user-field{grid-column:1 / -1!important;}#permAccessForm102 .perm-groups,#permAccessForm102 .perm-groups.perm-groups-v94{grid-template-columns:1fr!important;}#permAccessForm102 .perm-role-card-v94,#permAccessForm102 .perm-settings-card-v94,#permAccessForm102 .perm-visibility-card-v94{grid-column:1!important;min-height:0!important;height:auto!important;}#permAccessForm102 .perm-visibility-list,#permAccessForm102 .perm-visibility-list.perm-v93-list{grid-template-columns:repeat(2,minmax(220px,1fr))!important;}}
@media(max-width:760px){#permAccessForm102 .perm-fields-row,#permAccessForm102 .perm-fields-row.perm-fields-v94,#permAccessForm102 .perm-visibility-list,#permAccessForm102 .perm-visibility-list.perm-v93-list,#permAccessForm102 .perm-water-card .perm-nested-body{grid-template-columns:1fr!important;}#permAccessForm102 .perm-user-field{grid-template-columns:1fr!important;grid-template-areas:'title' 'input' 'button'!important;}#permAccessForm102 .perm-user-field .js-perm-pick-user{width:100%!important;}}
.route-mode-card-v101 span:empty{display:none!important;}
</style>

<?php elseif ($tab==='approval'): ?>
<div class="form-card approval-settings approval-settings-premium route-settings-flow route-builder-card route-bpmn-card-v59">
  <div class="section-title-row route-settings-head">
    <div>
      <h3>🧩 BPMN-конструктор маршрута согласования</h3>
      <p class="hint">Маршрут собран как процесс: узлы можно менять местами стрелками или перетаскиванием за ручку, назначать согласующих, менять название, должность/роль, описание и иконку этапа.</p>
    </div>
    <button type="button" class="btn btn-primary btn-sm js-add-route-stage">+ Добавить этап</button>
  </div>
  <?php
    $activeRouteType = preg_replace('/[^a-z0-9_]/','',(string)($_GET['route_type'] ?? 'regular'));
    if (!in_array($activeRouteType, ['regular','commission','commission_box'], true)) $activeRouteType = 'regular';
    $routeTabs = Approval::routeTypeTabs();
    $routeTypeMeta = [];
    foreach ($routeTabs as $rt=>$rtTitle) $routeTypeMeta[$rt] = ['title'=>$rtTitle,'hint'=>Approval::routeTypeHint($rt)];
    $routes = Approval::getRouteSettingsByType($portal, $activeRouteType);
    $stageHints = [
      'finance_route'=>'Финансист принимает заявку, проверяет данные и выбирает кассу/счёт.',
      'general_director'=>'Можно использовать как обязательный или специальный этап.',
      'security'=>'Промежуточная проверка службы безопасности.',
      'treasury'=>'Казначейский этап перед завершением и созданием платежа.',
      'finance_director'=>'Подключается по порогу суммы, указанному ниже.'
    ];
    $stageIcons = [
      'finance_route'=>'👤',
      'general_director'=>'👤',
      'security'=>'👤',
      'finance_director'=>'👤',
      'treasury'=>'👤'
    ];
    $orderedStages = Approval::orderedConfigurableStages($portal, $activeRouteType);
    unset($orderedStages['finance_route']);
    $financeRoute = $routes['finance_route'] ?? [];
  ?>
  <form method="POST" class="approval-route-form route-flow-form route-builder-form">
    <input type="hidden" name="sa" value="save_approval_route">
    <input type="hidden" name="route_type" value="<?=htmlspecialchars($activeRouteType)?>">
    <div id="routeRemovedStages"></div>

    <div class="route-type-tabs-v101 route-type-tabs-v105">
      <?php foreach ($routeTabs as $rt=>$rtTitle): ?>
        <a class="<?=$activeRouteType===$rt?'on':''?>" href="<?=app_link(['page'=>'settings','tab'=>'approval','route_type'=>$rt])?>"><?=htmlspecialchars($rtTitle)?></a>
      <?php endforeach; ?>
    </div>
    <div class="route-mode-card-v101">
      <div>
        <b><?=htmlspecialchars($routeTypeMeta[$activeRouteType]['title'])?></b>
        <?php if (!empty($routeTypeMeta[$activeRouteType]['hint'])): ?><span><?=htmlspecialchars($routeTypeMeta[$activeRouteType]['hint'])?></span><?php endif; ?>
      </div>
    </div>

    <div class="route-bpmn-toolbar">
      <span><?=htmlspecialchars($routeTypeMeta[$activeRouteType]['title'])?></span>
      <em>Двигайте этапы только за ручку ⋮⋮ или стрелками. Все данные карточки относятся только к выбранному типу маршрута.</em>
    </div>

    <div class="route-bpmn-canvas" id="approvalRouteBuilder">
      <div class="bpmn-fixed-node bpmn-start"><i>●</i><b>Старт</b><small>Заявитель</small></div>
      <div class="bpmn-line">→</div>
      <div class="bpmn-fixed-node"><i>👤</i><b>Руководитель</b><small>Выбирается в заявке</small></div>
      <div class="bpmn-line">→</div>
      <div class="bpmn-fixed-node approval-route-node fixed-route-settings" data-stage="finance_route">
        <input type="hidden" name="stage[]" value="finance_route">
        <input type="hidden" name="sort_order[]" value="20">
        <input type="hidden" name="approver_ids[]" value="<?=htmlspecialchars($financeRoute['approver_ids'] ?? '')?>">
        <input type="hidden" name="stage_enabled[finance_route]" value="1">
        <div class="bpmn-node-head route-card-edit-head">
          <input class="route-icon-input" name="stage_icon[]" value="👤" maxlength="8" title="Иконка">
          <input class="route-label-input" name="stage_label[]" value="<?=htmlspecialchars($financeRoute['stage_label'] ?? 'Финансисты')?>" placeholder="Название/должность этапа">
        </div>
        <label class="route-mini-label">Описание карточки</label>
        <textarea class="route-hint-input" name="stage_hint[]" rows="2" placeholder="Описание этапа"><?=htmlspecialchars($financeRoute['stage_hint'] ?? 'Первичная проверка и настройка параметров платежа')?></textarea>
        <textarea name="approver_names[]" rows="2" placeholder="Выберите финансистов" readonly><?=htmlspecialchars($financeRoute['approver_names'] ?? '')?></textarea>
        <div class="bpmn-node-actions"><button type="button" class="btn btn-primary btn-sm js-settings-load-users">Сотрудники</button><button type="button" class="btn btn-light btn-sm js-settings-clear-users">Очистить</button></div>
      </div>
      <div class="bpmn-line">→</div>
      <div class="bpmn-fixed-node"><i>👤</i><b>Директор департамента</b><small>Выбирается в заявке</small></div>
      <div class="bpmn-line">→</div>

      <?php $n=4; foreach ($orderedStages as $stage=>$label):
        $r=$routes[$stage] ?? [];
        $enabled = !isset($r['is_enabled']) || (int)$r['is_enabled'] === 1;
        $isCustom = (bool)preg_match('/^custom_[a-z0-9_]+$/', (string)$stage);
        $icon = '👤';
        $hint = trim((string)($r['stage_hint'] ?? '')) ?: ($stageHints[$stage] ?? 'Дополнительный этап стандартного маршрута.');
      ?>
        <div class="approval-route-node bpmn-card route-draggable <?=$isCustom?'custom-route-node':''?> stage-<?=htmlspecialchars($stage)?>" draggable="true" data-stage="<?=htmlspecialchars($stage)?>">
          <button type="button" class="bpmn-drag-handle" title="Перетащить этап">⋮⋮</button>
          <input type="hidden" name="stage[]" value="<?=htmlspecialchars($stage)?>">
          <input type="hidden" name="sort_order[]" class="js-sort-order" value="<?=htmlspecialchars((string)($r['sort_order'] ?? ($n*10)))?>">
          <input type="hidden" name="approver_ids[]" value="<?=htmlspecialchars($r['approver_ids'] ?? '')?>">
          <div class="bpmn-node-head route-card-edit-head">
            <span class="route-step-no js-route-no"><?=str_pad((string)$n,2,'0',STR_PAD_LEFT)?></span>
            <input class="route-icon-input" name="stage_icon[]" value="<?=htmlspecialchars($icon)?>" maxlength="8" title="Иконка">
            <label class="route-enabled-switch" title="Включить этап"><input type="checkbox" name="stage_enabled[<?=htmlspecialchars($stage)?>]" <?=$enabled?'checked':''?>><span></span></label>
          </div>
          <label class="route-mini-label">Название / должность этапа</label>
          <input class="route-label-input" name="stage_label[]" value="<?=htmlspecialchars($label)?>" placeholder="Название/должность этапа">
          <label class="route-mini-label">Описание карточки</label>
          <textarea class="route-hint-input" name="stage_hint[]" rows="2" placeholder="Описание этапа"><?=htmlspecialchars($hint)?></textarea>
          <textarea name="approver_names[]" rows="2" placeholder="Согласующие не выбраны" readonly><?=htmlspecialchars($r['approver_names'] ?? '')?></textarea>
          <div class="bpmn-node-actions">
            <button type="button" class="route-move js-route-left" title="Сдвинуть влево">←</button>
            <button type="button" class="route-move js-route-right" title="Сдвинуть вправо">→</button>
            <button type="button" class="btn btn-primary btn-sm js-settings-load-users" data-stage="<?=htmlspecialchars($stage)?>">Сотрудники</button>
            <button type="button" class="btn btn-light btn-sm js-settings-clear-users">Очистить</button>
            <?php if($isCustom): ?><button type="button" class="route-remove js-route-remove" title="Удалить этап">×</button><?php endif; ?>
          </div>
        </div>
        <div class="bpmn-line dynamic-line">→</div>
      <?php $n++; endforeach; ?>

      <div class="approval-route-node bpmn-card bpmn-threshold" draggable="false">
        <div class="bpmn-node-head"><span class="route-step-no">Σ</span><span class="bpmn-icon">₮</span></div>
        <input class="route-label-input" value="Порог финдиректора" readonly>
        <small class="route-subtitle">Если сумма больше порога, включается финансовый директор.</small>
        <label class="threshold-label">Сумма порога, TJS
          <input type="number" step="0.01" name="threshold_amount" value="<?=htmlspecialchars((string)($routes['finance_director']['threshold_amount'] ?? 15000))?>">
        </label>
      </div>
      <div class="bpmn-line">→</div>
      <div class="bpmn-fixed-node bpmn-end"><i>✓</i><b>Финиш</b><small>Создание платежа</small></div>
    </div>

    <template id="routeCustomTemplate">
      <div class="approval-route-node bpmn-card route-draggable custom-route-node" draggable="true" data-stage="__STAGE__">
        <button type="button" class="bpmn-drag-handle" title="Перетащить этап">⋮⋮</button>
        <input type="hidden" name="stage[]" value="__STAGE__">
        <input type="hidden" name="sort_order[]" class="js-sort-order" value="100">
        <input type="hidden" name="approver_ids[]" value="">
        <div class="bpmn-node-head route-card-edit-head">
          <span class="route-step-no js-route-no">00</span>
          <input class="route-icon-input" name="stage_icon[]" value="👤" maxlength="8" title="Иконка">
          <label class="route-enabled-switch" title="Включить этап"><input type="checkbox" name="stage_enabled[__STAGE__]" checked><span></span></label>
        </div>
        <label class="route-mini-label">Название / должность этапа</label>
        <input class="route-label-input" name="stage_label[]" value="Новый этап согласования" placeholder="Название/должность этапа">
        <label class="route-mini-label">Описание карточки</label>
        <textarea class="route-hint-input" name="stage_hint[]" rows="2" placeholder="Описание этапа">Новый узел выбранного маршрута. Назначьте согласующих.</textarea>
        <textarea name="approver_names[]" rows="2" placeholder="Согласующие не выбраны" readonly></textarea>
        <div class="bpmn-node-actions">
          <button type="button" class="route-move js-route-left" title="Сдвинуть влево">←</button>
          <button type="button" class="route-move js-route-right" title="Сдвинуть вправо">→</button>
          <button type="button" class="btn btn-primary btn-sm js-settings-load-users">Сотрудники</button>
          <button type="button" class="btn btn-light btn-sm js-settings-clear-users">Очистить</button>
          <button type="button" class="route-remove js-route-remove" title="Удалить этап">×</button>
        </div>
      </div>
      <div class="bpmn-line dynamic-line">→</div>
    </template>

    <div class="perm-actions-bottom route-save-row"><button class="btn btn-primary">Сохранить маршрут</button></div>
  </form>
</div>
<div id="settingsUserPicker" class="b24-user-picker" style="display:none">
  <div class="picker-box picker-box-top">
    <h3>Сотрудники Bitrix24</h3>
    <div class="hint">Выберите сотрудников по ФИО. ID вручную вводить не нужно.</div>
    <input type="text" id="settingsUserSearch" placeholder="Поиск по ФИО">
    <div id="settingsUserList" class="picker-list"></div>
    <div class="picker-actions mt8">
      <button type="button" class="btn btn-light" onclick="document.getElementById('settingsUserPicker').style.display='none'">Закрыть</button>
    </div>
  </div>
</div>
<script>
(function(){
var card=null;
function fullName(u){return (u.FULL_NAME||u.fullName||u.name||u.NAME_FORMATTED||((u.LAST_NAME||u.lastName||'')+' '+(u.NAME||u.firstName||'')).trim()||u.title||u.label||('Сотрудник #'+(u.ID||u.id||u.USER_ID||''))).trim();}
function normalizeUser(u){if(!u)return null;if(u.user)u=u.user;var id=u.ID||u.id||u.USER_ID||u.user_id||u.value||u.VALUE;if(!id)return null;return{ID:String(id),FULL_NAME:fullName(u),WORK_POSITION:u.WORK_POSITION||u.position||u.subtitle||''};}
function normalizeUsers(data){if(!data)return[];if(Array.isArray(data))return data.map(normalizeUser).filter(Boolean);if(data.result)return normalizeUsers(data.result);return Object.keys(data).map(k=>{var v=data[k];if(v&&typeof v==='object'){if(!v.ID&&!v.id)v.ID=k;return normalizeUser(v);}return normalizeUser({ID:k,FULL_NAME:String(v)});}).filter(Boolean);}
function appendUnique(el,value,sep){if(!el)return;var cur=(el.value||'').trim();var arr=cur?cur.split(sep).map(v=>v.trim()).filter(Boolean):[];if(!arr.includes(String(value).trim()))arr.push(String(value).trim());el.value=arr.join(sep===','?',':'\n');}
function addUser(u){u=normalizeUser(u); if(!u||!card)return;var ids=card.querySelector('[name="approver_ids[]"]');var names=card.querySelector('[name="approver_names[]"]');appendUnique(ids,u.ID,',');appendUnique(names,u.FULL_NAME,'\n');}
function render(users){var list=document.getElementById('settingsUserList');list.innerHTML='';users=normalizeUsers(users); if(!users.length){list.innerHTML='<div class="hint">Список сотрудников не получен. Проверьте права приложения Bitrix24 на просмотр сотрудников или используйте стандартное окно выбора сотрудников.</div>';return;} users.forEach(u=>{var div=document.createElement('div');div.className='picker-user';div.innerHTML='<b>'+u.FULL_NAME+'</b><span>#'+u.ID+' '+(u.WORK_POSITION||'')+'</span>';div.onclick=()=>addUser(u);list.appendChild(div);});}
function load(q){var list=document.getElementById('settingsUserList'); if(list)list.innerHTML='<div class="hint">Загрузка сотрудников...</div>'; if(typeof BX24!=='undefined'&&BX24.callMethod){BX24.callMethod('user.get',{ACTIVE:true,FILTER:q?{'%NAME':q,'%LAST_NAME':q}:{},order:{LAST_NAME:'ASC'},select:['ID','NAME','LAST_NAME','WORK_POSITION']},res=>{if(res&&!res.error())render(res.data()||[]);else render([]);});}else render([])}
function nativePicker(){if(typeof BX24==='undefined')return false;if(typeof BX24.selectUsers==='function'){BX24.selectUsers(function(users){normalizeUsers(users).forEach(addUser);});return true;}if(typeof BX24.selectUser==='function'){BX24.selectUser(function(user){addUser(user);});return true;}return false;}
function nodeFrom(el){return el ? el.closest('.approval-route-node,.approval-route-card,.bpmn-card,.bpmn-fixed-node') : null;}
function routeNodeFrom(el){return el ? el.closest('.route-draggable') : null;}
function dynamicNodes(){var wrap=document.getElementById('approvalRouteBuilder'); if(!wrap)return[]; return Array.prototype.filter.call(wrap.children,function(el){return el.classList&&el.classList.contains('route-draggable');});}
function makeLine(){var line=document.createElement('div'); line.className='bpmn-line dynamic-line'; line.textContent='→'; return line;}
function renderRouteOrder(nodes){var wrap=document.getElementById('approvalRouteBuilder'); if(!wrap)return; var threshold=wrap.querySelector('.bpmn-threshold'); if(!threshold)return; wrap.querySelectorAll('.dynamic-line').forEach(function(l){l.remove();}); nodes.forEach(function(node){if(node&&node.classList&&node.classList.contains('route-draggable')){node.setAttribute('draggable','false');wrap.insertBefore(node, threshold); wrap.insertBefore(makeLine(), threshold);}}); renumberRoute();}
function renumberRoute(){var i=4; dynamicNodes().forEach(function(node){var no=node.querySelector('.js-route-no'); var sort=node.querySelector('.js-sort-order'); if(no)no.textContent=String(i).padStart(2,'0'); if(sort)sort.value=i*10; i++;});}
function moveNode(node,dir){
 if(!node||!node.classList.contains('route-draggable'))return;
 var nodes=dynamicNodes();
 var idx=nodes.indexOf(node);
 if(idx<0)return;
 var ni=idx+(dir<0?-1:1);
 if(ni<0||ni>=nodes.length)return;
 var tmp=nodes[idx];
 nodes[idx]=nodes[ni];
 nodes[ni]=tmp;
 renderRouteOrder(nodes);
 node.classList.add('route-just-moved');
 setTimeout(function(){node.classList.remove('route-just-moved');},260);
}
function addCustomStage(){var wrap=document.getElementById('approvalRouteBuilder'); var tpl=document.getElementById('routeCustomTemplate'); if(!wrap||!tpl)return; var code='custom_'+Date.now().toString(36)+'_'+Math.floor(Math.random()*1000); var html=tpl.innerHTML.replaceAll('__STAGE__',code); var holder=document.createElement('div'); holder.innerHTML=html.trim(); var node=holder.firstElementChild; var nodes=dynamicNodes(); nodes.push(node); renderRouteOrder(nodes); setTimeout(()=>{var inp=node.querySelector('.route-label-input'); if(inp){inp.focus(); inp.select();}},30);}
function removeCustomStage(node){if(!node)return; var stage=node.dataset.stage||''; var removed=document.getElementById('routeRemovedStages'); if(stage&&removed){var i=document.createElement('input'); i.type='hidden'; i.name='remove_stage[]'; i.value=stage; removed.appendChild(i);} node.remove(); renderRouteOrder(dynamicNodes());}

document.addEventListener('click',function(e){
 var btn=e.target.closest('.js-settings-load-users'); if(btn){card=nodeFrom(btn); if(nativePicker())return; var p=document.getElementById('settingsUserPicker'); if(p)p.style.display='flex';load('');return;}
 var clr=e.target.closest('.js-settings-clear-users'); if(clr){var c=nodeFrom(clr); if(!c)return; var ids=c.querySelector('[name="approver_ids[]"]'); var names=c.querySelector('[name="approver_names[]"]'); if(ids)ids.value=''; if(names)names.value='';return;}
 var addBtn=e.target.closest('.js-add-route-stage');
 if(addBtn){
  e.preventDefault();
  e.stopPropagation();
  if(e.stopImmediatePropagation) e.stopImmediatePropagation();
  var now=Date.now();
  if(window.__routeAddStageLock && (now-window.__routeAddStageLock)<650) return;
  window.__routeAddStageLock=now;
  addCustomStage();
  return;
 }
 var left=e.target.closest('.js-route-left'); if(left){e.preventDefault(); e.stopPropagation(); moveNode(routeNodeFrom(left),-1);return;}
 var right=e.target.closest('.js-route-right'); if(right){e.preventDefault(); e.stopPropagation(); moveNode(routeNodeFrom(right),1);return;}
 var rem=e.target.closest('.js-route-remove'); if(rem){e.preventDefault(); e.stopPropagation(); if(e.stopImmediatePropagation)e.stopImmediatePropagation(); var n=routeNodeFrom(rem); if(!n)return; if(confirm('Удалить этот этап?'))removeCustomStage(n);return;}
});
var builder=document.getElementById('approvalRouteBuilder');
if(builder){
 var pointerDrag=null;
 dynamicNodes().forEach(function(n){n.setAttribute('draggable','false');});
 function dropIndexByPointer(x, draggedNode){
   var nodes=dynamicNodes().filter(function(n){return n!==draggedNode;});
   for(var i=0;i<nodes.length;i++){
     var r=nodes[i].getBoundingClientRect();
     if(x < r.left + r.width/2) return i;
   }
   return nodes.length;
 }
 function finishPointerDrag(ev){
   if(!pointerDrag)return;
   var d=pointerDrag;
   window.removeEventListener('pointermove',movePointerDrag,true);
   window.removeEventListener('pointerup',finishPointerDrag,true);
   d.node.classList.remove('dragging');
   d.node.style.transform='';
   var nodes=dynamicNodes().filter(function(n){return n!==d.node;});
   var idx=dropIndexByPointer((ev&&ev.clientX)||d.lastX||d.startX, d.node);
   idx=Math.max(0,Math.min(idx,nodes.length));
   nodes.splice(idx,0,d.node);
   // Если пользователь просто кликнул по ручке без реального переноса — возвращаем прежний порядок.
   if(Math.abs((d.lastX||d.startX)-d.startX)<8 && Math.abs((d.lastY||d.startY)-d.startY)<8){nodes=d.startNodes;}
   renderRouteOrder(nodes);
   d.node.classList.add('route-just-moved');
   setTimeout(function(){d.node.classList.remove('route-just-moved');},260);
   pointerDrag=null;
 }
 function movePointerDrag(ev){
   if(!pointerDrag)return;
   pointerDrag.lastX=ev.clientX; pointerDrag.lastY=ev.clientY;
   var dx=ev.clientX-pointerDrag.startX;
   pointerDrag.node.style.transform='translateX('+dx+'px) scale(.985)';
   ev.preventDefault();
 }
 builder.addEventListener('pointerdown',function(e){
   var handle=e.target.closest('.bpmn-drag-handle');
   if(!handle)return;
   var node=routeNodeFrom(handle);
   if(!node)return;
   pointerDrag={node:node,startX:e.clientX,startY:e.clientY,lastX:e.clientX,lastY:e.clientY,startNodes:dynamicNodes().slice()};
   node.classList.add('dragging');
   if(handle.setPointerCapture){try{handle.setPointerCapture(e.pointerId);}catch(err){}}
   window.addEventListener('pointermove',movePointerDrag,true);
   window.addEventListener('pointerup',finishPointerDrag,true);
   e.preventDefault(); e.stopPropagation();
 }, true);
 builder.addEventListener('dragstart',function(e){e.preventDefault();return false;}, true);
 renumberRoute();
}
var s=document.getElementById('settingsUserSearch');if(s){var t;s.addEventListener('input',()=>{clearTimeout(t);t=setTimeout(()=>load(s.value),300);});}
})();
</script>

<?php endif; ?>

<style>
.stabs{display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap}.stab{padding:8px 14px;border-radius:6px;font-size:13px;text-decoration:none;color:var(--color-text-secondary);background:var(--color-background-primary);border:1px solid var(--color-border-tertiary);transition:.15s}.stab:hover{background:var(--color-background-secondary)}.stab.on{background:#2563eb;color:#fff;border-color:#2563eb}.form-card{background:var(--color-background-primary);border-radius:12px;padding:20px 24px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:16px}.form-card h3{font-size:15px;font-weight:600;margin-bottom:14px}.inline-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.inline-form input,.inline-form select{padding:8px 10px;border-radius:6px;border:1px solid var(--color-border-secondary);font-size:13px;font-family:inherit}.mt12{margin-top:12px}.sub-title{font-size:12px;font-weight:600;color:var(--color-text-secondary);margin-bottom:8px;text-transform:uppercase;letter-spacing:.4px}.hint{font-size:12px;color:var(--color-text-secondary);margin-bottom:12px;line-height:1.5}.fg-label{font-size:12px;font-weight:600;color:var(--color-text-secondary);display:block;margin-bottom:4px}.perm-grid{display:grid;grid-template-columns:1.1fr 1.4fr .7fr repeat(12,auto) auto;gap:8px;align-items:center}.perm-grid input,.perm-grid select,.perm-table input,.perm-table select{padding:6px 8px;border:1px solid var(--color-border-secondary);border-radius:5px;font-size:12px}.perm-grid label{font-size:12px;display:flex;align-items:center;gap:4px;white-space:nowrap}.perm-table th,.perm-table td{font-size:11px}.perm-table input[type=text]{width:150px}.perm-table input[type=number]{width:70px}.perm-table select{max-width:140px}.perm-user-name{width:170px}.perm-user-field .btn{margin-top:4px}.perm-table .perm-user-name{width:150px;padding:6px 8px;border:1px solid var(--color-border-secondary);border-radius:5px;font-size:12px}
@media(max-width:1100px){.perm-grid{grid-template-columns:1fr 1fr}.perm-grid button{grid-column:1/-1}}


/* v19: адаптивное добавление прав и аккуратная таблица прав */
.perm-form-v19{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:14px;overflow:hidden}
.perm-fields-row{display:grid;grid-template-columns:minmax(180px,1fr) minmax(220px,1.4fr) minmax(110px,.6fr) auto;gap:10px;align-items:end;margin-bottom:12px}
.perm-field{display:flex;flex-direction:column;gap:5px;font-size:12px;color:#64748b;font-weight:600;min-width:0}
.perm-field input,.perm-field select{width:100%;min-width:0;background:#fff}
.perm-submit{height:34px;justify-content:center;white-space:nowrap}
.perm-groups{display:grid;grid-template-columns:minmax(180px,.8fr) minmax(260px,1fr) minmax(320px,1.4fr);gap:10px;align-items:stretch}
.perm-group-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:10px 12px;display:flex;gap:9px 14px;align-content:flex-start;align-items:center;flex-wrap:wrap;min-width:0}
.perm-group-title{flex-basis:100%;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.35px;margin-bottom:2px}
.perm-group-card label{font-size:12px;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;color:#334155;background:#f8fafc;border:1px solid #e5e7eb;border-radius:999px;padding:5px 8px}
.perm-group-card input[type=checkbox]{width:14px;height:14px;margin:0}
.perm-table{min-width:1180px}
.perm-table th,.perm-table td{text-align:center;vertical-align:middle}
.perm-table th:nth-child(1),.perm-table td:nth-child(1),.perm-table th:nth-child(2),.perm-table td:nth-child(2){text-align:left}
.tbl-wrap.mt12{border-radius:12px;border:1px solid #e2e8f0;background:#fff}

@media(max-width:1280px){
  .perm-fields-row{grid-template-columns:1fr 1fr 120px;}
  .perm-submit{grid-column:1/-1;width:100%}
  .perm-groups{grid-template-columns:1fr 1fr}
  .perm-group-wide{grid-column:1/-1}
}
@media(max-width:860px){
  .perm-fields-row{grid-template-columns:1fr}
  .perm-groups{grid-template-columns:1fr}
  .perm-group-wide{grid-column:auto}
  .perm-group-card label{white-space:normal}
}


/* v20: адаптивные настройки и кнопка добавления прав внизу */
.settings-two-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-top:16px;align-items:start}
.settings-list-card{min-width:0}
.settings-scroll{max-height:420px;overflow:auto;-webkit-overflow-scrolling:touch}
.settings-scroll .data-table{min-width:0}
.settings-scroll .data-table td{word-break:break-word}
.perm-fields-row{grid-template-columns:minmax(180px,1fr) minmax(220px,1.4fr) minmax(110px,.6fr)!important}
.perm-actions-bottom{display:flex;justify-content:flex-end;margin-top:14px;padding-top:12px;border-top:1px solid #e2e8f0}
.perm-actions-bottom .perm-submit{min-width:180px;height:38px}
@media(max-width:900px){.settings-two-grid{grid-template-columns:1fr}.perm-fields-row{grid-template-columns:1fr!important}.perm-actions-bottom .perm-submit{width:100%}}

.approval-route-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}.approval-route-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px;display:flex;flex-direction:column;gap:8px}.approval-route-card label{display:flex;flex-direction:column;gap:5px;font-size:12px;color:#64748b;font-weight:600}.approval-route-card input,.approval-route-card textarea{width:100%;min-width:0}.route-title{font-weight:700;color:#1e293b;font-size:13px}.b24-user-picker{position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.45);align-items:center;justify-content:center}.picker-box{width:min(620px,92vw);max-height:78vh;overflow:auto;background:#fff;border-radius:14px;padding:18px;box-shadow:0 20px 50px rgba(0,0,0,.25)}.picker-list{display:grid;gap:6px;margin:12px 0;max-height:420px;overflow:auto}.picker-user{padding:9px 11px;border:1px solid #e2e8f0;border-radius:9px;cursor:pointer;background:#f8fafc}.picker-user:hover{background:#e0f2fe}.picker-user span{display:block;color:#64748b;font-size:12px;margin-top:2px}


.route-builder-card{background:linear-gradient(180deg,rgba(255,255,255,.94),rgba(248,250,252,.86))!important;border:1px solid rgba(226,232,240,.9)!important;border-radius:28px!important;box-shadow:0 24px 70px rgba(15,23,42,.10),inset 0 1px rgba(255,255,255,.8)!important}.route-bpmn-fixed{display:grid;grid-template-columns:minmax(180px,1fr) auto minmax(180px,1fr) auto minmax(180px,1fr);gap:10px;align-items:stretch;margin:12px 0 18px}.route-node{padding:14px;border-radius:22px;border:1px solid rgba(203,213,225,.85);background:linear-gradient(180deg,rgba(255,255,255,.96),rgba(248,250,252,.88));box-shadow:0 12px 30px rgba(15,23,42,.07);display:grid;gap:5px}.route-node span{width:34px;height:34px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;background:#eff6ff;color:#1d4ed8;font-weight:900}.route-node b{font-weight:850;letter-spacing:-.02em;color:#0f172a}.route-node small{color:#64748b;line-height:1.35}.route-arrow{align-self:center;font-weight:900;color:#94a3b8;font-size:22px}.route-builder-hint{font-size:12px;color:#64748b;font-weight:700;margin:8px 0 10px}.bpmn-sortable{display:grid!important;grid-template-columns:1fr!important;gap:12px!important}.bpmn-card{border-radius:24px!important;background:linear-gradient(180deg,rgba(255,255,255,.96),rgba(248,250,252,.88))!important;border:1px solid rgba(226,232,240,.9)!important;box-shadow:0 14px 38px rgba(15,23,42,.08)!important;cursor:grab}.bpmn-card.dragging{opacity:.58;cursor:grabbing;transform:scale(.99)}.route-step-top{display:grid!important;grid-template-columns:auto 1fr auto auto!important;align-items:center!important;gap:10px!important}.route-title-wrap{min-width:0}.route-enabled-switch{display:inline-flex!important;align-items:center!important;justify-content:center!important;margin:0!important}.route-enabled-switch input{display:none}.route-enabled-switch span{width:42px;height:24px;border-radius:999px;background:#cbd5e1;position:relative;box-shadow:inset 0 1px 4px rgba(15,23,42,.18)}.route-enabled-switch span:before{content:"";position:absolute;width:20px;height:20px;border-radius:999px;background:#fff;left:2px;top:2px;box-shadow:0 2px 8px rgba(15,23,42,.18);transition:.18s}.route-enabled-switch input:checked+span{background:#2563eb}.route-enabled-switch input:checked+span:before{transform:translateX(18px)}.route-move-actions{display:flex;gap:4px}.route-move{width:30px;height:30px;border-radius:10px;border:1px solid rgba(203,213,225,.85);background:rgba(255,255,255,.9);font-weight:900;color:#334155;cursor:pointer}.route-move:hover{background:#eff6ff;color:#1d4ed8}.bpmn-locked{cursor:default!important;border-color:rgba(147,197,253,.8)!important}.bpmn-threshold{cursor:default!important;background:linear-gradient(135deg,rgba(255,251,235,.96),rgba(255,255,255,.88))!important}@media(max-width:820px){.route-bpmn-fixed{grid-template-columns:1fr}.route-arrow{display:none}.route-step-top{grid-template-columns:auto 1fr!important}.route-enabled-switch,.route-move-actions{grid-column:2}.route-enabled-switch{justify-self:start}}



/* v59: настоящий BPMN-конструктор маршрута */
.route-bpmn-card-v59{border-radius:30px!important;padding:20px!important;background:linear-gradient(180deg,rgba(255,255,255,.96),rgba(248,250,252,.88))!important;box-shadow:0 26px 78px rgba(15,23,42,.11),inset 0 1px rgba(255,255,255,.9)!important}
.route-bpmn-card-v59 .route-settings-head{align-items:flex-start;gap:12px}.route-bpmn-card-v59 .route-settings-head .btn{white-space:nowrap;border-radius:999px!important}
.route-bpmn-toolbar{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:14px 0 12px;padding:10px 12px;border:1px solid rgba(226,232,240,.85);border-radius:18px;background:rgba(248,250,252,.72)}
.route-bpmn-toolbar span{font-weight:900;color:#0f172a}.route-bpmn-toolbar em{font-style:normal;color:#64748b;font-size:12px;font-weight:650}
.route-bpmn-canvas{display:flex;align-items:stretch;gap:8px;overflow-x:auto;padding:18px 12px 20px;border:1px dashed rgba(148,163,184,.65);border-radius:26px;background:linear-gradient(135deg,rgba(248,250,252,.86),rgba(255,255,255,.80));scrollbar-width:thin;min-height:235px}
.bpmn-fixed-node,.approval-route-node{flex:0 0 220px;min-width:220px;position:relative;border-radius:22px;border:1px solid rgba(203,213,225,.88);background:linear-gradient(180deg,rgba(255,255,255,.98),rgba(248,250,252,.88));box-shadow:0 12px 34px rgba(15,23,42,.08),inset 0 1px rgba(255,255,255,.75)}
.bpmn-fixed-node{display:grid;grid-template-rows:auto auto 1fr;gap:7px;padding:15px}.bpmn-fixed-node i,.bpmn-icon{width:38px;height:38px;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;background:#eff6ff;color:#1d4ed8;font-style:normal;font-weight:900;box-shadow:inset 0 1px rgba(255,255,255,.9)}
.bpmn-fixed-node b{font-size:14px;letter-spacing:-.02em;color:#0f172a}.bpmn-fixed-node small{font-size:12px;color:#64748b;line-height:1.35}.bpmn-start i{background:#dcfce7;color:#15803d}.bpmn-end i{background:#dcfce7;color:#15803d}
.bpmn-line{flex:0 0 28px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-weight:900;font-size:22px;align-self:center}.dynamic-line{color:#60a5fa}
.approval-route-node{display:grid;grid-template-rows:auto auto auto 1fr auto;gap:8px;padding:13px;cursor:grab}.approval-route-node.dragging{opacity:.62;transform:scale(.985);cursor:grabbing}.bpmn-drag-handle{position:absolute;top:8px;right:8px;border:0;background:rgba(241,245,249,.8);border-radius:10px;width:28px;height:28px;cursor:grab;color:#64748b;font-weight:900}.bpmn-drag-handle:hover{background:#dbeafe;color:#1d4ed8}
.bpmn-node-head{display:flex!important;align-items:center!important;gap:8px!important;padding-right:30px}.route-step-no{width:32px!important;height:32px!important;border-radius:12px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;background:#2563eb!important;color:#fff!important;font-weight:900!important;font-size:12px!important;box-shadow:0 8px 18px rgba(37,99,235,.22)!important}.route-label-input{width:100%!important;border:0!important;background:transparent!important;padding:0!important;font-size:14px!important;font-weight:850!important;color:#0f172a!important;letter-spacing:-.02em!important}.route-label-input:not([readonly]){background:#fff!important;border:1px solid rgba(203,213,225,.86)!important;border-radius:12px!important;padding:7px 9px!important}.route-subtitle{font-size:11px!important;color:#64748b!important;line-height:1.35!important;font-weight:600!important}.approval-route-node textarea{width:100%!important;min-height:54px!important;border-radius:14px!important;border:1px solid rgba(203,213,225,.86)!important;background:rgba(255,255,255,.88)!important;font-size:12px!important;color:#334155!important;resize:vertical!important;padding:8px!important}
.bpmn-node-actions{display:flex;gap:5px;align-items:center;flex-wrap:wrap}.bpmn-node-actions .btn{border-radius:999px!important;padding:6px 9px!important;font-size:11px!important;min-height:28px!important}.route-move,.route-remove{width:28px!important;height:28px!important;border-radius:999px!important;border:1px solid rgba(203,213,225,.9)!important;background:rgba(255,255,255,.92)!important;color:#334155!important;font-weight:900!important;cursor:pointer}.route-move:hover{background:#dbeafe!important;color:#1d4ed8!important}.route-remove{background:#fff1f2!important;color:#dc2626!important;border-color:#fecdd3!important}.route-remove:hover{background:#fee2e2!important}.custom-route-node{border-color:rgba(96,165,250,.72)!important;background:linear-gradient(180deg,rgba(239,246,255,.96),rgba(255,255,255,.90))!important}.bpmn-threshold{cursor:default!important;background:linear-gradient(135deg,rgba(255,251,235,.96),rgba(255,255,255,.88))!important}.bpmn-threshold .threshold-label{font-size:12px!important;font-weight:700!important;color:#64748b!important;display:grid!important;gap:5px!important}.bpmn-threshold input[type=number]{width:100%!important;border-radius:12px!important}
.route-enabled-switch{margin-left:auto!important}.route-enabled-switch input{display:none!important}.route-enabled-switch span{width:40px!important;height:23px!important;border-radius:999px!important;background:#cbd5e1!important;position:relative!important;display:block!important;box-shadow:inset 0 1px 4px rgba(15,23,42,.18)!important}.route-enabled-switch span:before{content:"";position:absolute;width:19px;height:19px;border-radius:999px;background:#fff;left:2px;top:2px;box-shadow:0 2px 8px rgba(15,23,42,.18);transition:.18s}.route-enabled-switch input:checked+span{background:#2563eb!important}.route-enabled-switch input:checked+span:before{transform:translateX(17px)}
@media(max-width:900px){.route-bpmn-card-v59 .route-settings-head{display:grid}.route-bpmn-toolbar{display:grid}.route-bpmn-canvas{min-height:220px}.bpmn-fixed-node,.approval-route-node{flex-basis:205px;min-width:205px}}

.fixed-route-settings{cursor:default!important;grid-template-rows:auto auto auto 1fr auto!important}
.fixed-route-settings textarea{width:100%!important;min-height:48px!important;border-radius:14px!important;border:1px solid rgba(203,213,225,.86)!important;background:rgba(255,255,255,.88)!important;font-size:12px!important;color:#334155!important;resize:vertical!important;padding:8px!important}
.fixed-route-settings .bpmn-node-actions{margin-top:2px}



/* v61: stable one-step left/right movement in BPMN route builder */
.route-draggable.route-just-moved{outline:2px solid rgba(37,99,235,.28)!important;outline-offset:3px!important;transition:outline .18s ease, transform .18s ease!important;}
.route-move{user-select:none!important;-webkit-user-select:none!important;}

/* v60: стабильный BPMN drag/drop и редактируемая карточка маршрута */
.route-card-edit-head{display:grid!important;grid-template-columns:auto 1fr auto!important;align-items:center!important;gap:8px!important;padding-right:34px!important}
.route-icon-input{width:38px!important;height:38px!important;min-width:38px!important;border:1px solid rgba(191,219,254,.9)!important;border-radius:14px!important;background:#eff6ff!important;color:#1d4ed8!important;font-size:18px!important;text-align:center!important;font-weight:900!important;padding:0!important;box-shadow:inset 0 1px rgba(255,255,255,.9)!important}
.route-mini-label{font-size:10px!important;color:#64748b!important;font-weight:800!important;text-transform:uppercase!important;letter-spacing:.04em!important;margin-top:2px!important;margin-bottom:-4px!important}
.route-hint-input{width:100%!important;min-height:48px!important;border-radius:14px!important;border:1px solid rgba(203,213,225,.86)!important;background:rgba(255,255,255,.88)!important;font-size:12px!important;color:#334155!important;resize:vertical!important;padding:8px!important;font-weight:500!important;line-height:1.35!important}
.approval-route-node .route-label-input{width:100%!important;background:#fff!important;border:1px solid rgba(203,213,225,.86)!important;border-radius:12px!important;padding:7px 9px!important;font-size:13px!important;font-weight:760!important;color:#0f172a!important;letter-spacing:-.01em!important}
.approval-route-node textarea[readonly]{cursor:default!important}
.route-draggable input,.route-draggable textarea,.route-draggable button,.route-draggable label{cursor:auto!important}
.route-draggable .bpmn-drag-handle{cursor:grab!important;touch-action:none!important}
.route-draggable.dragging{outline:2px solid rgba(37,99,235,.35)!important;outline-offset:2px!important}
.bpmn-fixed-node.fixed-route-settings{grid-template-rows:auto auto 1fr auto!important}
.fixed-route-settings .route-card-edit-head{padding-right:0!important}
.fixed-route-settings .route-label-input{font-size:13px!important}
@media(max-width:900px){.route-card-edit-head{grid-template-columns:auto 1fr!important}.route-card-edit-head .route-enabled-switch{grid-column:2;justify-self:start}}
</style>


<style>
/* v97 inline override: права доступа читаемы в iframe Bitrix24 */
.form-card .perm-form-v19{display:block!important;width:100%!important;max-width:100%!important;min-width:0!important;overflow:hidden!important;box-sizing:border-box!important;padding:14px!important}.form-card .perm-form-v19 *{box-sizing:border-box!important}.form-card .perm-form-v19 .perm-fields-row,.form-card .perm-form-v19 .perm-fields-row.perm-fields-v94{display:grid!important;grid-template-columns:minmax(180px,1fr) minmax(220px,1.15fr) minmax(320px,1.45fr)!important;gap:12px!important;align-items:end!important;width:100%!important;max-width:100%!important;min-width:0!important;margin:12px 0 14px!important}.form-card .perm-form-v19 .perm-field,.form-card .perm-form-v19 .perm-field-wide,.form-card .perm-form-v19 .perm-user-field{min-width:0!important;width:100%!important;max-width:100%!important;margin:0!important}.form-card .perm-form-v19 .perm-field>span{display:block!important;margin:0 0 6px!important;min-height:16px!important;font-size:12px!important;line-height:1.25!important;font-weight:850!important;color:#475569!important}.form-card .perm-form-v19 .perm-field select,.form-card .perm-form-v19 .perm-field input[type=text]{width:100%!important;max-width:100%!important;min-width:0!important;height:42px!important;min-height:42px!important;margin:0!important;border-radius:14px!important;line-height:42px!important}.form-card .perm-form-v19 .perm-user-field{display:grid!important;grid-template-columns:minmax(0,1fr) 112px!important;grid-template-rows:auto 42px!important;gap:6px 8px!important}.form-card .perm-form-v19 .perm-user-field>span,.form-card .perm-form-v19 .perm-user-field>input[type=hidden]{grid-column:1/-1!important}.form-card .perm-form-v19 .perm-user-field .perm-user-name{grid-column:1!important;grid-row:2!important}.form-card .perm-form-v19 .perm-user-field .js-perm-pick-user,.form-card .perm-form-v19 .perm-user-field .btn{grid-column:2!important;grid-row:2!important;width:112px!important;min-width:112px!important;height:42px!important;min-height:42px!important;padding:0 12px!important;margin:0!important;align-self:stretch!important;justify-content:center!important;border-radius:14px!important;white-space:nowrap!important}.form-card .perm-form-v19 .perm-groups,.form-card .perm-form-v19 .perm-groups.perm-groups-v94{display:grid!important;grid-template-columns:minmax(210px,.75fr) minmax(280px,1fr)!important;gap:12px!important;align-items:start!important;width:100%!important;max-width:100%!important;min-width:0!important;margin:0!important}.form-card .perm-form-v19 .perm-role-card-v94,.form-card .perm-form-v19 .perm-settings-card-v94,.form-card .perm-form-v19 .perm-visibility-card-v94,.form-card .perm-form-v19 .perm-group-card{width:100%!important;max-width:100%!important;min-width:0!important;height:auto!important;min-height:0!important;margin:0!important;padding:14px!important;display:flex!important;flex-direction:column!important;align-items:stretch!important;justify-content:flex-start!important;overflow:hidden!important}.form-card .perm-form-v19 .perm-role-card-v94{grid-column:1!important}.form-card .perm-form-v19 .perm-settings-card-v94{grid-column:2!important}.form-card .perm-form-v19 .perm-visibility-card-v94,.form-card .perm-form-v19 .perm-visibility-card{grid-column:1/-1!important;min-height:0!important}.form-card .perm-form-v19 .perm-group-title{width:100%!important;margin:0 0 10px!important;padding:0!important;font-size:12px!important;line-height:1.25!important;font-weight:950!important;letter-spacing:.04em!important;color:#172033!important}.form-card .perm-form-v19 .perm-role-card-v94>label,.form-card .perm-form-v19 .perm-settings-card-v94>label,.form-card .perm-form-v19 .perm-group-card>label{display:flex!important;align-items:center!important;justify-content:flex-start!important;gap:8px!important;width:100%!important;max-width:100%!important;min-width:0!important;min-height:40px!important;margin:0 0 8px!important;padding:9px 11px!important;border-radius:15px!important;white-space:normal!important;overflow:hidden!important;text-align:left!important}.form-card .perm-form-v19 .perm-role-card-v94>label:last-child,.form-card .perm-form-v19 .perm-settings-card-v94>label:last-child{margin-bottom:0!important}.form-card .perm-form-v19 .perm-visibility-list,.form-card .perm-form-v19 .perm-visibility-list.perm-v93-list{display:grid!important;grid-template-columns:1fr!important;gap:8px!important;width:100%!important;max-width:100%!important;min-width:0!important;margin:0!important;padding:0!important}.form-card .perm-form-v19 .perm-flat-card,.form-card .perm-form-v19 .perm-water-card,.form-card .perm-form-v19 .perm-water-subcard{width:100%!important;max-width:100%!important;min-width:0!important;margin:0!important;overflow:hidden!important}.form-card .perm-form-v19 .perm-flat-card,.form-card .perm-form-v19 .perm-water-subcard{display:flex!important;align-items:center!important;justify-content:flex-start!important;gap:8px!important;min-height:40px!important;padding:9px 11px!important;text-align:left!important}.form-card .perm-form-v19 .perm-water-card>summary{display:grid!important;grid-template-columns:minmax(0,1fr) max-content!important;align-items:center!important;gap:10px!important;width:100%!important;max-width:100%!important;min-width:0!important;min-height:42px!important;padding:9px 11px!important;cursor:pointer!important;overflow:hidden!important}.form-card .perm-form-v19 .perm-water-card>summary::-webkit-details-marker{display:none!important}.form-card .perm-form-v19 .perm-card-main,.form-card .perm-form-v19 .perm-section-check{display:flex!important;align-items:center!important;justify-content:flex-start!important;gap:8px!important;width:100%!important;max-width:100%!important;min-width:0!important;margin:0!important;padding:0!important;overflow:hidden!important;text-align:left!important}.form-card .perm-form-v19 .perm-card-action{justify-self:end!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;width:auto!important;min-width:88px!important;max-width:104px!important;height:30px!important;min-height:30px!important;padding:0 12px!important;margin:0!important;border-radius:999px!important;white-space:nowrap!important;font-size:11px!important;font-weight:900!important}.form-card .perm-form-v19 .perm-card-main span,.form-card .perm-form-v19 .perm-section-check span,.form-card .perm-form-v19 .perm-flat-card span,.form-card .perm-form-v19 .perm-water-subcard span,.form-card .perm-form-v19 .perm-group-card label{min-width:0!important;max-width:100%!important;white-space:normal!important;overflow-wrap:anywhere!important;text-align:left!important}.form-card .perm-form-v19 input[type=checkbox]{flex:0 0 14px!important;width:14px!important;height:14px!important;min-width:14px!important;margin:0!important}.form-card .perm-form-v19 .perm-water-card .perm-nested-body{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:8px!important;width:100%!important;max-width:100%!important;min-width:0!important;padding:0 11px 11px!important}.form-card .perm-form-v19 .perm-actions-bottom{display:flex!important;justify-content:flex-end!important;width:100%!important;margin-top:12px!important;padding-top:12px!important;border-top:1px solid #e2e8f0!important}.form-card .perm-form-v19 .perm-actions-bottom .perm-submit{min-width:180px!important;height:38px!important;justify-content:center!important}.form-card .tbl-wrap.mt12{width:100%!important;max-width:100%!important;overflow-x:auto!important;overflow-y:visible!important}.form-card .tbl-wrap.mt12 .perm-table{width:max-content!important;min-width:1850px!important}@media(max-width:1100px){.form-card .perm-form-v19 .perm-fields-row,.form-card .perm-form-v19 .perm-fields-row.perm-fields-v94{grid-template-columns:1fr 1fr!important}.form-card .perm-form-v19 .perm-user-field{grid-column:1/-1!important}}@media(max-width:860px){.form-card .perm-form-v19 .perm-fields-row,.form-card .perm-form-v19 .perm-fields-row.perm-fields-v94,.form-card .perm-form-v19 .perm-groups,.form-card .perm-form-v19 .perm-groups.perm-groups-v94,.form-card .perm-form-v19 .perm-water-card .perm-nested-body{grid-template-columns:1fr!important}.form-card .perm-form-v19 .perm-role-card-v94,.form-card .perm-form-v19 .perm-settings-card-v94,.form-card .perm-form-v19 .perm-visibility-card-v94{grid-column:1!important}.form-card .perm-form-v19 .perm-user-field{grid-template-columns:1fr!important;grid-template-rows:auto 42px 42px!important}.form-card .perm-form-v19 .perm-user-field .perm-user-name{grid-column:1!important;grid-row:2!important}.form-card .perm-form-v19 .perm-user-field .js-perm-pick-user,.form-card .perm-form-v19 .perm-user-field .btn{grid-column:1!important;grid-row:3!important;width:100%!important;min-width:0!important}.form-card .perm-form-v19 .perm-actions-bottom .perm-submit{width:100%!important}}
</style>

<style>
/* v98 inline override: одинаковая высота карточек в правах */
.form-card .perm-form-v19 .perm-groups,
.form-card .perm-form-v19 .perm-groups.perm-groups-v94{align-items:stretch!important;grid-auto-rows:auto!important}
.form-card .perm-form-v19 .perm-role-card-v94,
.form-card .perm-form-v19 .perm-settings-card-v94{height:100%!important;min-height:286px!important}
.form-card .perm-form-v19 .perm-role-card-v94{justify-content:flex-start!important}
.form-card .perm-form-v19 .perm-role-card-v94>label,
.form-card .perm-form-v19 .perm-settings-card-v94>label,
.form-card .perm-form-v19 .perm-flat-card,
.form-card .perm-form-v19 .perm-water-subcard,
.form-card .perm-form-v19 .perm-water-card>summary{height:44px!important;min-height:44px!important;max-height:44px!important}
.form-card .perm-form-v19 .perm-flat-card,
.form-card .perm-form-v19 .perm-water-subcard,
.form-card .perm-form-v19 .perm-role-card-v94>label,
.form-card .perm-form-v19 .perm-settings-card-v94>label,
.form-card .perm-form-v19 .perm-water-card>summary{padding-top:0!important;padding-bottom:0!important}
.form-card .perm-form-v19 .perm-visibility-list,
.form-card .perm-form-v19 .perm-visibility-list.perm-v93-list{grid-auto-rows:minmax(44px,auto)!important}
.form-card .perm-form-v19 .perm-water-card:not([open]){height:44px!important;min-height:44px!important}
.form-card .perm-form-v19 .perm-water-card[open]{height:auto!important;min-height:44px!important}
@media(max-width:860px){.form-card .perm-form-v19 .perm-role-card-v94,.form-card .perm-form-v19 .perm-settings-card-v94{min-height:0!important;height:auto!important}}
</style>

<style>
/* v99: финальная фиксация расположения прав внутри карточек */
.form-card .perm-form-v19,
.form-card .perm-form-v19 *{box-sizing:border-box!important}
.form-card .perm-form-v19{overflow:visible!important}
.form-card .perm-form-v19 .perm-groups,
.form-card .perm-form-v19 .perm-groups.perm-groups-v94{
  display:grid!important;
  grid-template-columns:minmax(240px,.85fr) minmax(340px,1.15fr)!important;
  gap:14px!important;
  align-items:stretch!important;
  width:100%!important;
  max-width:100%!important;
}
.form-card .perm-form-v19 .perm-role-card-v94,
.form-card .perm-form-v19 .perm-settings-card-v94,
.form-card .perm-form-v19 .perm-visibility-card-v94{
  position:relative!important;
  overflow:visible!important;
  display:flex!important;
  flex-direction:column!important;
  align-items:stretch!important;
  justify-content:flex-start!important;
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  padding:14px!important;
}
.form-card .perm-form-v19 .perm-role-card-v94{grid-column:1!important;min-height:286px!important;height:100%!important}
.form-card .perm-form-v19 .perm-settings-card-v94{grid-column:2!important;min-height:286px!important;height:100%!important}
.form-card .perm-form-v19 .perm-visibility-card-v94,
.form-card .perm-form-v19 .perm-visibility-card{grid-column:1/-1!important;height:auto!important;min-height:0!important}
.form-card .perm-form-v19 .perm-role-card-v94 > label,
.form-card .perm-form-v19 .perm-settings-card-v94 > label{
  position:static!important;
  inset:auto!important;
  transform:none!important;
  float:none!important;
  display:flex!important;
  flex-direction:row!important;
  align-items:center!important;
  justify-content:flex-start!important;
  gap:9px!important;
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  height:42px!important;
  min-height:42px!important;
  max-height:42px!important;
  margin:0 0 8px!important;
  padding:0 12px!important;
  overflow:visible!important;
  opacity:1!important;
  visibility:visible!important;
  text-align:left!important;
  text-indent:0!important;
  white-space:normal!important;
  color:#334155!important;
  font-size:12px!important;
  line-height:1.25!important;
  font-weight:800!important;
}
.form-card .perm-form-v19 .perm-role-card-v94 > label input[type=checkbox],
.form-card .perm-form-v19 .perm-settings-card-v94 > label input[type=checkbox],
.form-card .perm-form-v19 .perm-flat-card > input[type=checkbox],
.form-card .perm-form-v19 .perm-water-subcard > input[type=checkbox],
.form-card .perm-form-v19 .perm-section-check > input[type=checkbox]{
  position:static!important;
  inset:auto!important;
  transform:none!important;
  float:none!important;
  flex:0 0 16px!important;
  width:16px!important;
  height:16px!important;
  min-width:16px!important;
  min-height:16px!important;
  margin:0!important;
  opacity:1!important;
  visibility:visible!important;
}
.form-card .perm-form-v19 .perm-role-card-v94 > label span,
.form-card .perm-form-v19 .perm-settings-card-v94 > label span,
.form-card .perm-form-v19 .perm-flat-card span,
.form-card .perm-form-v19 .perm-water-subcard span,
.form-card .perm-form-v19 .perm-section-check span{
  display:inline!important;
  flex:1 1 auto!important;
  min-width:0!important;
  max-width:100%!important;
  overflow:visible!important;
  text-overflow:clip!important;
  white-space:normal!important;
  color:#334155!important;
  opacity:1!important;
  visibility:visible!important;
  text-align:left!important;
}
.form-card .perm-form-v19 .perm-visibility-list,
.form-card .perm-form-v19 .perm-visibility-list.perm-v93-list{
  display:grid!important;
  grid-template-columns:repeat(3,minmax(0,1fr))!important;
  gap:10px!important;
  align-items:start!important;
}
.form-card .perm-form-v19 .perm-flat-card,
.form-card .perm-form-v19 .perm-water-card,
.form-card .perm-form-v19 .perm-water-subcard{
  position:static!important;
  transform:none!important;
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  margin:0!important;
  overflow:visible!important;
}
.form-card .perm-form-v19 .perm-flat-card,
.form-card .perm-form-v19 .perm-water-subcard{
  display:flex!important;
  align-items:center!important;
  justify-content:flex-start!important;
  gap:9px!important;
  height:42px!important;
  min-height:42px!important;
  max-height:42px!important;
  padding:0 12px!important;
}
.form-card .perm-form-v19 .perm-water-card > summary{
  display:grid!important;
  grid-template-columns:minmax(0,1fr) auto!important;
  align-items:center!important;
  gap:10px!important;
  height:42px!important;
  min-height:42px!important;
  max-height:42px!important;
  padding:0 12px!important;
  overflow:visible!important;
}
.form-card .perm-form-v19 .perm-water-card:not([open]){height:42px!important;min-height:42px!important;max-height:42px!important}
.form-card .perm-form-v19 .perm-water-card[open]{height:auto!important;min-height:42px!important;max-height:none!important;grid-column:1/-1!important}
.form-card .perm-form-v19 .perm-water-card .perm-nested-body{
  display:grid!important;
  grid-template-columns:repeat(2,minmax(0,1fr))!important;
  gap:8px!important;
  width:100%!important;
  padding:0 12px 12px!important;
}
.form-card .perm-form-v19 .perm-card-main,
.form-card .perm-form-v19 .perm-section-check{
  position:static!important;
  display:flex!important;
  align-items:center!important;
  justify-content:flex-start!important;
  gap:9px!important;
  width:100%!important;
  min-width:0!important;
  margin:0!important;
  padding:0!important;
  overflow:visible!important;
}
.form-card .perm-form-v19 .perm-card-action{justify-self:end!important;min-width:92px!important;height:30px!important;min-height:30px!important}
@media(max-width:1200px){
  .form-card .perm-form-v19 .perm-visibility-list,
  .form-card .perm-form-v19 .perm-visibility-list.perm-v93-list{grid-template-columns:repeat(2,minmax(0,1fr))!important}
}
@media(max-width:860px){
  .form-card .perm-form-v19 .perm-groups,
  .form-card .perm-form-v19 .perm-groups.perm-groups-v94,
  .form-card .perm-form-v19 .perm-visibility-list,
  .form-card .perm-form-v19 .perm-visibility-list.perm-v93-list,
  .form-card .perm-form-v19 .perm-water-card .perm-nested-body{grid-template-columns:1fr!important}
  .form-card .perm-form-v19 .perm-role-card-v94,
  .form-card .perm-form-v19 .perm-settings-card-v94,
  .form-card .perm-form-v19 .perm-visibility-card-v94{grid-column:1!important;min-height:0!important;height:auto!important}
}
</style>



<style>
/* v100: окончательное выравнивание прав строго внутри карточек + режим обычного маршрута */
.form-card .perm-form-v19{width:100%!important;max-width:100%!important;min-width:0!important;overflow:hidden!important;padding:14px!important;box-sizing:border-box!important;}
.form-card .perm-form-v19 *{box-sizing:border-box!important;}
.form-card .perm-form-v19 .perm-groups,
.form-card .perm-form-v19 .perm-groups.perm-groups-v94{display:grid!important;grid-template-columns:minmax(250px,.8fr) minmax(360px,1.2fr)!important;gap:14px!important;align-items:stretch!important;justify-items:stretch!important;width:100%!important;max-width:100%!important;min-width:0!important;overflow:hidden!important;}
.form-card .perm-form-v19 .perm-role-card-v94,
.form-card .perm-form-v19 .perm-settings-card-v94,
.form-card .perm-form-v19 .perm-visibility-card-v94{position:relative!important;left:auto!important;right:auto!important;top:auto!important;transform:none!important;float:none!important;width:100%!important;max-width:100%!important;min-width:0!important;margin:0!important;padding:16px!important;overflow:hidden!important;display:flex!important;flex-direction:column!important;align-items:stretch!important;justify-content:flex-start!important;}
.form-card .perm-form-v19 .perm-role-card-v94{grid-column:1!important;min-height:286px!important;height:100%!important;}
.form-card .perm-form-v19 .perm-settings-card-v94{grid-column:2!important;min-height:286px!important;height:100%!important;}
.form-card .perm-form-v19 .perm-visibility-card-v94,
.form-card .perm-form-v19 .perm-visibility-card{grid-column:1 / -1!important;min-height:0!important;height:auto!important;}
.form-card .perm-form-v19 .perm-group-title{display:block!important;width:100%!important;margin:0 0 12px!important;padding:0!important;}
.form-card .perm-form-v19 .perm-role-card-v94 > label,
.form-card .perm-form-v19 .perm-settings-card-v94 > label,
.form-card .perm-form-v19 .perm-flat-card,
.form-card .perm-form-v19 .perm-water-subcard,
.form-card .perm-form-v19 .perm-section-check{position:relative!important;left:auto!important;right:auto!important;top:auto!important;bottom:auto!important;inset:auto!important;transform:none!important;float:none!important;display:grid!important;grid-template-columns:18px minmax(0,1fr)!important;align-items:center!important;justify-content:stretch!important;gap:10px!important;width:100%!important;max-width:100%!important;min-width:0!important;height:42px!important;min-height:42px!important;max-height:42px!important;margin:0 0 8px!important;padding:0 12px!important;overflow:hidden!important;text-align:left!important;white-space:normal!important;}
.form-card .perm-form-v19 .perm-role-card-v94 > label:last-child,
.form-card .perm-form-v19 .perm-settings-card-v94 > label:last-child,
.form-card .perm-form-v19 .perm-flat-card:last-child,
.form-card .perm-form-v19 .perm-water-subcard:last-child{margin-bottom:0!important;}
.form-card .perm-form-v19 .perm-role-card-v94 > label input[type=checkbox],
.form-card .perm-form-v19 .perm-settings-card-v94 > label input[type=checkbox],
.form-card .perm-form-v19 .perm-flat-card > input[type=checkbox],
.form-card .perm-form-v19 .perm-water-subcard > input[type=checkbox],
.form-card .perm-form-v19 .perm-section-check > input[type=checkbox]{position:static!important;left:auto!important;right:auto!important;top:auto!important;bottom:auto!important;inset:auto!important;transform:none!important;float:none!important;display:block!important;grid-column:1!important;justify-self:start!important;align-self:center!important;width:16px!important;height:16px!important;min-width:16px!important;min-height:16px!important;margin:0!important;opacity:1!important;visibility:visible!important;}
.form-card .perm-form-v19 .perm-role-card-v94 > label span,
.form-card .perm-form-v19 .perm-settings-card-v94 > label span,
.form-card .perm-form-v19 .perm-flat-card span,
.form-card .perm-form-v19 .perm-water-subcard span,
.form-card .perm-form-v19 .perm-section-check span{display:block!important;grid-column:2!important;min-width:0!important;max-width:100%!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;color:#334155!important;text-align:left!important;opacity:1!important;visibility:visible!important;}
.form-card .perm-form-v19 .perm-visibility-list,
.form-card .perm-form-v19 .perm-visibility-list.perm-v93-list{display:grid!important;grid-template-columns:repeat(auto-fit,minmax(240px,1fr))!important;gap:10px!important;align-items:start!important;justify-items:stretch!important;width:100%!important;max-width:100%!important;min-width:0!important;margin:0!important;padding:0!important;overflow:hidden!important;}
.form-card .perm-form-v19 .perm-water-card{position:relative!important;left:auto!important;right:auto!important;transform:none!important;display:block!important;width:100%!important;max-width:100%!important;min-width:0!important;margin:0!important;overflow:hidden!important;}
.form-card .perm-form-v19 .perm-water-card > summary{position:relative!important;display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;align-items:center!important;gap:10px!important;width:100%!important;max-width:100%!important;min-width:0!important;height:42px!important;min-height:42px!important;max-height:42px!important;margin:0!important;padding:0 12px!important;overflow:hidden!important;}
.form-card .perm-form-v19 .perm-water-card > summary .perm-card-main{display:block!important;width:100%!important;min-width:0!important;overflow:hidden!important;}
.form-card .perm-form-v19 .perm-card-action{position:static!important;justify-self:end!important;align-self:center!important;min-width:92px!important;height:30px!important;min-height:30px!important;margin:0!important;}
.form-card .perm-form-v19 .perm-water-card .perm-nested-body{display:grid!important;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))!important;gap:8px!important;width:100%!important;max-width:100%!important;min-width:0!important;padding:0 12px 12px!important;overflow:hidden!important;}
.form-card .perm-form-v19 .perm-water-card:not([open]){height:42px!important;min-height:42px!important;max-height:42px!important;}
.form-card .perm-form-v19 .perm-water-card[open]{height:auto!important;min-height:42px!important;max-height:none!important;grid-column:auto!important;}
.route-mode-panel-v100{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin:12px 0 14px;}
.route-mode-card-v100{display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,300px);gap:12px;align-items:center;padding:14px;border-radius:20px;background:rgba(255,255,255,.82);border:1px solid rgba(226,232,240,.9);box-shadow:0 12px 28px rgba(15,23,42,.06);}
.route-mode-card-v100.muted{grid-template-columns:1fr;background:rgba(248,250,252,.72);}
.route-mode-card-v100 b{display:block;font-size:13px;color:#0f172a;margin-bottom:3px;}
.route-mode-card-v100 span{display:block;font-size:12px;color:#64748b;line-height:1.35;}
.route-mode-card-v100 select{width:100%;min-height:42px;border-radius:14px;border:1px solid #dbe3ef;padding:0 12px;background:#fff;}
@media(max-width:860px){.form-card .perm-form-v19 .perm-groups,.form-card .perm-form-v19 .perm-groups.perm-groups-v94,.route-mode-card-v100{grid-template-columns:1fr!important}.form-card .perm-form-v19 .perm-role-card-v94,.form-card .perm-form-v19 .perm-settings-card-v94,.form-card .perm-form-v19 .perm-visibility-card-v94{grid-column:1!important;min-height:0!important;height:auto!important}.form-card .perm-form-v19 .perm-visibility-list,.form-card .perm-form-v19 .perm-visibility-list.perm-v93-list{grid-template-columns:1fr!important}}
</style>


<style>
/* v101: окончательная изоляция блока прав и отдельный выбор маршрута */
#permAccessForm101{display:block!important;width:100%!important;max-width:100%!important;min-width:0!important;overflow:visible!important;padding:14px!important;box-sizing:border-box!important;}
#permAccessForm101 *{box-sizing:border-box!important;}
#permAccessForm101 .perm-fields-row{display:grid!important;grid-template-columns:minmax(190px,1fr) minmax(230px,1.1fr) minmax(340px,1.35fr)!important;gap:12px!important;align-items:end!important;width:100%!important;margin:12px 0 14px!important;}
#permAccessForm101 .perm-field{display:grid!important;grid-template-columns:1fr!important;gap:6px!important;min-width:0!important;}
#permAccessForm101 .perm-field select,#permAccessForm101 .perm-field input[type=text]{width:100%!important;min-width:0!important;min-height:42px!important;border-radius:14px!important;}
#permAccessForm101 .perm-user-field{display:grid!important;grid-template-columns:minmax(0,1fr) 112px!important;grid-template-rows:auto 42px!important;gap:6px 8px!important;}
#permAccessForm101 .perm-user-field>span,#permAccessForm101 .perm-user-field>input[type=hidden]{grid-column:1/-1!important;}
#permAccessForm101 .perm-user-field .perm-user-name{grid-column:1!important;grid-row:2!important;}
#permAccessForm101 .perm-user-field .js-perm-pick-user{grid-column:2!important;grid-row:2!important;width:112px!important;height:42px!important;margin:0!important;justify-content:center!important;}
#permAccessForm101 .perm-groups{display:grid!important;grid-template-columns:minmax(250px,.8fr) minmax(360px,1.2fr)!important;gap:14px!important;align-items:stretch!important;width:100%!important;max-width:100%!important;min-width:0!important;overflow:visible!important;}
#permAccessForm101 .perm-group-card{position:relative!important;left:auto!important;right:auto!important;top:auto!important;bottom:auto!important;inset:auto!important;transform:none!important;float:none!important;display:flex!important;flex-direction:column!important;align-items:stretch!important;justify-content:flex-start!important;width:100%!important;max-width:100%!important;min-width:0!important;margin:0!important;padding:16px!important;overflow:hidden!important;}
#permAccessForm101 .perm-role-card-v94{grid-column:1!important;min-height:286px!important;height:100%!important;}
#permAccessForm101 .perm-settings-card-v94{grid-column:2!important;min-height:286px!important;height:100%!important;}
#permAccessForm101 .perm-visibility-card-v94{grid-column:1/-1!important;min-height:0!important;height:auto!important;}
#permAccessForm101 .perm-group-title{display:block!important;width:100%!important;margin:0 0 12px!important;padding:0!important;line-height:1.25!important;text-align:left!important;}
#permAccessForm101 .perm-visibility-list{display:grid!important;grid-template-columns:repeat(auto-fit,minmax(250px,1fr))!important;gap:10px!important;width:100%!important;max-width:100%!important;min-width:0!important;margin:0!important;padding:0!important;overflow:visible!important;}
#permAccessForm101 .perm-item-v101{position:relative!important;left:auto!important;right:auto!important;top:auto!important;bottom:auto!important;inset:auto!important;transform:none!important;float:none!important;display:flex!important;flex-direction:row!important;align-items:center!important;justify-content:flex-start!important;gap:10px!important;width:100%!important;max-width:100%!important;min-width:0!important;min-height:42px!important;height:42px!important;margin:0 0 8px!important;padding:0 12px!important;border-radius:15px!important;overflow:hidden!important;text-align:left!important;white-space:normal!important;color:#334155!important;background:rgba(255,255,255,.84)!important;border:1px solid rgba(226,232,240,.92)!important;}
#permAccessForm101 .perm-visibility-list>.perm-item-v101{margin:0!important;}
#permAccessForm101 .perm-item-v101 input[type=checkbox]{appearance:auto!important;-webkit-appearance:checkbox!important;position:static!important;display:inline-block!important;float:none!important;transform:none!important;opacity:1!important;visibility:visible!important;flex:0 0 16px!important;width:16px!important;height:16px!important;min-width:16px!important;min-height:16px!important;margin:0!important;accent-color:#2563eb!important;}
#permAccessForm101 .perm-item-v101 span{position:static!important;display:block!important;flex:1 1 auto!important;min-width:0!important;max-width:100%!important;margin:0!important;padding:0!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;text-align:left!important;color:#334155!important;font-size:12px!important;font-weight:820!important;line-height:1.25!important;opacity:1!important;visibility:visible!important;}
#permAccessForm101 .perm-water-card{position:relative!important;display:block!important;width:100%!important;min-width:0!important;max-width:100%!important;margin:0!important;border-radius:18px!important;overflow:hidden!important;background:rgba(255,255,255,.76)!important;border:1px solid rgba(226,232,240,.92)!important;}
#permAccessForm101 .perm-water-card>summary{display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;align-items:center!important;gap:10px!important;width:100%!important;height:42px!important;min-height:42px!important;margin:0!important;padding:0 12px!important;list-style:none!important;cursor:pointer!important;overflow:hidden!important;}
#permAccessForm101 .perm-water-card>summary::-webkit-details-marker{display:none!important;}
#permAccessForm101 .perm-card-main{display:block!important;min-width:0!important;overflow:hidden!important;}
#permAccessForm101 .perm-section-check.perm-item-v101{height:30px!important;min-height:30px!important;margin:0!important;padding:0!important;border:0!important;background:transparent!important;}
#permAccessForm101 .perm-card-action{position:static!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;justify-self:end!important;min-width:92px!important;height:30px!important;min-height:30px!important;margin:0!important;padding:0 12px!important;border-radius:999px!important;background:#f1f5f9!important;border:1px solid #e2e8f0!important;color:#64748b!important;font-size:11px!important;font-weight:900!important;white-space:nowrap!important;}
#permAccessForm101 .perm-card-action .hide-label{display:none!important;}
#permAccessForm101 .perm-water-card[open] .perm-card-action .show-label{display:none!important;}
#permAccessForm101 .perm-water-card[open] .perm-card-action .hide-label{display:inline!important;}
#permAccessForm101 .perm-nested-body{display:grid!important;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))!important;gap:8px!important;width:100%!important;padding:0 12px 12px!important;overflow:visible!important;}
#permAccessForm101 .perm-nested-body .perm-item-v101{margin:0!important;}
#permAccessForm101 .perm-actions-bottom{display:flex!important;justify-content:flex-end!important;width:100%!important;margin-top:12px!important;padding-top:12px!important;border-top:1px solid #e2e8f0!important;}
.route-type-tabs-v101{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 12px;padding:6px;border-radius:18px;background:rgba(241,245,249,.85);border:1px solid rgba(226,232,240,.9);width:max-content;max-width:100%;}
.route-type-tabs-v101 a{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 16px;border-radius:14px;text-decoration:none;color:#475569;font-size:13px;font-weight:900;border:1px solid transparent;}
.route-type-tabs-v101 a.on{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;box-shadow:0 10px 22px rgba(37,99,235,.20);border-color:rgba(255,255,255,.45);}
.route-mode-card-v101{display:grid;grid-template-columns:1fr;gap:4px;margin:0 0 14px;padding:14px 16px;border-radius:20px;background:rgba(255,255,255,.82);border:1px solid rgba(226,232,240,.9);box-shadow:0 12px 28px rgba(15,23,42,.06);}
.route-mode-card-v101 b{display:block;font-size:14px;color:#0f172a;margin-bottom:3px;}.route-mode-card-v101 span{display:block;font-size:12px;color:#64748b;line-height:1.4;}
@media(max-width:1100px){#permAccessForm101 .perm-fields-row{grid-template-columns:1fr 1fr!important}#permAccessForm101 .perm-user-field{grid-column:1/-1!important}}
@media(max-width:860px){#permAccessForm101 .perm-fields-row,#permAccessForm101 .perm-groups,#permAccessForm101 .perm-visibility-list,#permAccessForm101 .perm-nested-body{grid-template-columns:1fr!important}#permAccessForm101 .perm-role-card-v94,#permAccessForm101 .perm-settings-card-v94,#permAccessForm101 .perm-visibility-card-v94{grid-column:1!important;min-height:0!important;height:auto!important}.route-type-tabs-v101{width:100%}.route-type-tabs-v101 a{flex:1 1 160px}}
</style>
