<?php
set_time_limit(60);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/DB.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Payment.php';
require_once __DIR__ . '/src/Import.php';
require_once __DIR__ . '/src/Export.php';
require_once __DIR__ . '/src/Bitrix.php';
require_once __DIR__ . '/src/AccessControl.php';
require_once __DIR__ . '/src/Approval.php';

// ── Контекст Битрикс24 ──────────────────────────────────────────────
// В Firefox SESSION может работать, а в Chrome/Edge внутри iframe cookie часто
// ограничиваются. Поэтому используем два канала: REQUEST + SESSION.
function reqv($name, $alt = null) {
    if (isset($_REQUEST[$name])) return trim((string)$_REQUEST[$name]);
    if ($alt && isset($_REQUEST[$alt])) return trim((string)$_REQUEST[$alt]);
    return '';
}

$bxDomain  = reqv('DOMAIN', 'domain');
$bxAuth    = reqv('AUTH_ID');
$bxRefresh = reqv('REFRESH_ID');
$bxMember  = reqv('member_id');
$bxUser    = reqv('user_id');
$bxUserPos = reqv('user_position');
$earlyAction = preg_replace('/[^a-z_]/', '', (string)($_POST['action'] ?? $_GET['action'] ?? ''));
$isFetchRequest = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

// Поддержка portal/access_token из старой сессии
$portal      = $bxDomain ?: ($_SESSION['portal'] ?? (defined('APP_DEFAULT_PORTAL') ? APP_DEFAULT_PORTAL : '')); // v55: fallback для мобильного Bitrix24
$accessToken = $bxAuth   ?: ($_SESSION['access_token'] ?? '');
$refreshTok  = $bxRefresh ?: ($_SESSION['refresh_token'] ?? '');
$memberId    = $bxMember ?: ($_SESSION['member_id'] ?? '');
$userIdFromRequest = $bxUser ?: ($_SESSION['user_id'] ?? '');
$userPositionFromRequest = $bxUserPos ?: ($_SESSION['user_position'] ?? '');

if ($portal)      $_SESSION['portal'] = $portal;
if ($memberId)    $_SESSION['member_id'] = $memberId;
if ($userIdFromRequest) $_SESSION['user_id'] = $userIdFromRequest;
if ($userPositionFromRequest) $_SESSION['user_position'] = $userPositionFromRequest;
if ($accessToken) $_SESSION['access_token'] = $accessToken;
if ($refreshTok)  $_SESSION['refresh_token'] = $refreshTok;

if ($portal && $accessToken) {
    // Токен пришёл — сохраняем персонально для текущего пользователя/портала.
    $tKey = 'tok_' . substr(md5($portal . '_' . $memberId . '_' . $userIdFromRequest), 0, 16);
    $_SESSION[$tKey] = $accessToken;
    if ($refreshTok) $_SESSION[$tKey . '_r'] = $refreshTok;

    // Сохраняем токен только при обычном открытии приложения, а не на каждом AJAX/fetch.
    // Раньше все внутренние запросы с AUTH_ID били UPDATE в tokens и замедляли iframe.
    if ($earlyAction === '' && !$isFetchRequest) {
        try {
            DB::execute(
                "INSERT INTO tokens (portal,access_token,refresh_token,expires_at)
                 VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE
                 access_token=VALUES(access_token),refresh_token=VALUES(refresh_token),expires_at=VALUES(expires_at)",
                [$portal, $accessToken, $refreshTok, time()+3600]
            );
        } catch (Throwable $e) {
            app_log('Token save failed: ' . $e->getMessage());
        }
    }
} elseif ($portal && $memberId) {
    $tKey = 'tok_' . substr(md5($portal . '_' . $memberId . '_' . $userIdFromRequest), 0, 16);
    if (!empty($_SESSION[$tKey])) {
        $accessToken = $_SESSION[$tKey];
        $_SESSION['access_token'] = $accessToken;
    }
}

// v55: снимаем блокировку PHP-сессии до любых тяжёлых SQL/REST/AJAX-операций.
// Это особенно важно для мобильного Bitrix24 и параллельных запросов отчётов.
if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }

// ВАЖНО: для проверки прав не используем последний токен портала из БД.
// Иначе обычный сотрудник может попасть под токен администратора/руководителя.
// Если AUTH_ID потерян, ниже страница получит свежий токен через BX24.getAuth().

// Параметры, которые нужно добавлять во внутренние ссылки/fetch, чтобы Chrome/Edge
// не теряли контекст при блокировке сторонних cookies.
$APP_CONTEXT_PARAMS = array_filter([
    'DOMAIN'     => $portal,
    'AUTH_ID'    => $accessToken,
    'REFRESH_ID' => $refreshTok,
    'member_id'  => $memberId,
    'user_id'       => $userIdFromRequest,
    'user_position' => $userPositionFromRequest,
], static fn($v) => $v !== '' && $v !== null);

function app_context_query() {
    global $APP_CONTEXT_PARAMS;
    return http_build_query($APP_CONTEXT_PARAMS);
}

function app_link($params = []) {
    global $APP_CONTEXT_PARAMS;
    return '?' . http_build_query(array_merge($params, $APP_CONTEXT_PARAMS));
}

// Проверка авторизации
if (empty($portal)) {
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Финансовый учет</title></head><body style="font-family:Arial;padding:24px">';
    echo '<h3>Приложение нужно открывать из Битрикс24</h3>';
    echo '<p>Не передан параметр DOMAIN. Откройте приложение через портал: <a href="https://bima.bitrix24.ru/marketplace/app/85/">bima.bitrix24.ru</a>.</p>';
    echo '<p style="color:#666">Если приложение открыто внутри Bitrix24 и вы видите это сообщение, проверьте URL обработчика приложения и настройки доступа.</p>';
    echo '</body></html>';
    exit;
}

$page   = preg_replace('/[^a-z_]/', '', $_GET['page'] ?? 'dashboard');
$action = preg_replace('/[^a-z_]/', '', $_POST['action'] ?? $_GET['action'] ?? '');


function jsonOut($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function app_normalize_date_value($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1];
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value)) return $value;
    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : $value;
}

function renderIdentityLoader() {
    global $portal;
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Проверка пользователя</title><script src="https://api.bitrix24.com/api/v1/"></script></head><body style="font-family:Arial,sans-serif;background:#f6f8fb;margin:0;padding:40px;color:#334155"><div style="max-width:760px;margin:60px auto;background:white;border-radius:16px;padding:32px;text-align:center;box-shadow:0 8px 24px rgba(15,23,42,.08)"><div style="font-size:42px;margin-bottom:14px">⏳</div><h3 style="margin:0 0 10px">Проверяем права доступа</h3><p style="margin:0 0 18px;color:#64748b">Получаем свежий токен и User ID текущего сотрудника из Bitrix24.</p><div id="msg" style="font-size:13px;color:#64748b">Подождите...</div></div><script>(function(){
function msg(t,c){var el=document.getElementById("msg"); if(el) el.innerHTML="<span style=\"color:"+(c||"#64748b")+"\">"+t+"</span>";}
function reloadWith(u, auth){
  var p = new URLSearchParams(location.search);
  auth = auth || {};
  var authId = auth.access_token || auth.AUTH_ID || auth.auth || p.get("AUTH_ID") || "";
  var refreshId = auth.refresh_token || auth.REFRESH_ID || p.get("REFRESH_ID") || "";
  var domain = auth.domain || auth.DOMAIN || p.get("DOMAIN") || p.get("domain") || '.json_encode($portal).';
  var memberId = auth.member_id || auth.MEMBER_ID || p.get("member_id") || "";
  if(domain) p.set("DOMAIN", domain);
  if(authId) p.set("AUTH_ID", authId);
  if(refreshId) p.set("REFRESH_ID", refreshId);
  if(memberId) p.set("member_id", memberId);
  if(u && u.ID) p.set("user_id", u.ID);
  if(u && u.WORK_POSITION) p.set("user_position", u.WORK_POSITION);
  p.set("identity_checked", "1");
  location.replace(location.pathname+"?"+p.toString());
}
if(typeof BX24==="undefined"){msg("Bitrix24 SDK не загружен. Откройте приложение из Bitrix24.","#dc2626");return;}
BX24.init(function(){
  var auth = {};
  try { auth = (typeof BX24.getAuth === "function") ? (BX24.getAuth() || {}) : {}; } catch(e) { auth = {}; }
  BX24.callMethod("profile",{},function(res){
    if(res && !res.error()) { reloadWith(res.data() || {}, auth); return; }
    if(auth.access_token || auth.AUTH_ID || auth.auth) { reloadWith({}, auth); return; }
    msg("Не удалось получить профиль пользователя Bitrix24. Закройте приложение и откройте заново из портала.","#dc2626");
  });
});
})();</script></body></html>';
    exit;
}

// Без реального user_id права доступа применять опасно: иначе обычный сотрудник может попасть
// под чужую/админскую сессию. Для HTML сначала получаем user_id через BX24 JS SDK.
if ((int)$userIdFromRequest <= 0) {
    // Bitrix24 может открывать iframe POST-запросом без action.
    // Это не операция приложения, поэтому нельзя отдавать JSON-ошибку — нужно отрисовать HTML-loader,
    // который через BX24 SDK получит свежий AUTH_ID и user.current.
    if ($action !== '') {
        jsonOut(['ok'=>false,'error'=>'Не удалось определить текущего пользователя Bitrix24. Обновите приложение.'], 403);
    }
    renderIdentityLoader();
}

// Права и пользователь: быстрый режим без обязательного Bitrix REST на каждую страницу.
$_currentUserLoaded = false;
$userVerified = false;
$currentUser = [];
$userPos     = $userPositionFromRequest ?: '';
$userId      = (int)($userIdFromRequest ?: 0);
if ($userId) $currentUser['ID'] = $userId;
if ($userPos) $currentUser['WORK_POSITION'] = $userPos;
$isManager   = false;
$canEditReport = false;

// v55: в мобильном приложении Bitrix24 REST profile/user.admin часто отвечает медленно
// или не проходит через WebView. Если Bitrix уже передал user_id, считаем личность определённой,
// а права всё равно берём из внутренних таблиц финансовой системы.
if ($userId > 0 && !APP_STRICT_B24_VERIFY) {
    $userVerified = true;
    $GLOBALS['userVerified'] = true;
    if (empty($currentUser['LAST_NAME'])) {
        $knownName = class_exists('Approval') ? Approval::userDisplayName($portal, $userId, '') : '';
        $currentUser['LAST_NAME'] = $knownName ?: ('Сотрудник #' . $userId);
    }
}

function loadCurrentUser($portal) {
    global $_currentUserLoaded, $currentUser, $userPos, $userId, $isManager;
    if ($_currentUserLoaded) return;
    $_currentUserLoaded = true;

    // Быстрый режим: не блокируем каждую страницу REST-запросом к Bitrix24.
    // Для доступа используются внутренние права по user_id/должности.
    if ($userId > 0 && !APP_STRICT_B24_VERIFY) {
        $GLOBALS['userVerified'] = true;
        if (empty($currentUser['ID'])) $currentUser['ID'] = $userId;
        if ($userPos && empty($currentUser['WORK_POSITION'])) $currentUser['WORK_POSITION'] = $userPos;
        if (empty($currentUser['LAST_NAME'])) {
            $knownName = class_exists('Approval') ? Approval::userDisplayName($portal, $userId, '') : '';
            $currentUser['LAST_NAME'] = $knownName ?: ('Сотрудник #' . $userId);
        }
        $isManager = AccessControl::isManager($portal, $userId, $userPos, $currentUser);
        $GLOBALS['canEditReport'] = AccessControl::canEditReport($portal, $userId, $userPos, $currentUser);
        return;
    }

    $apiUser = Bitrix::currentUser($portal);
    if (!empty($apiUser) && !empty($apiUser['ID'])) {
        $currentUser = array_merge($currentUser, $apiUser);
        $GLOBALS['userVerified'] = true;
        $userPos = $apiUser['WORK_POSITION'] ?? $userPos;
        $userId  = (int)($apiUser['ID'] ?? $userId);
        if ($userId) $_SESSION['user_id'] = $userId;
        if ($userPos) $_SESSION['user_position'] = $userPos;
    } else {
        // Без подтверждения через Bitrix24 не выдаём права по user_id из URL.
        $GLOBALS['userVerified'] = false;
        $isManager = false;
        $GLOBALS['canEditReport'] = false;
        return;
    }

    if ($userId && empty($currentUser['ID'])) $currentUser['ID'] = $userId;
    if ($userPos && empty($currentUser['WORK_POSITION'])) $currentUser['WORK_POSITION'] = $userPos;
    $isManager = AccessControl::isManager($portal, $userId, $userPos, $currentUser);
    $GLOBALS['canEditReport'] = AccessControl::canEditReport($portal, $userId, $userPos, $currentUser);
}


// Безопасность: если есть AUTH_ID, сверяем реального пользователя по текущему токену
// до расчёта прав. Это предотвращает ситуацию, когда в URL остался user_id руководителя.
try { if (!empty($accessToken) && APP_STRICT_B24_VERIFY) loadCurrentUser($portal); } catch (Throwable $e) { app_log('Early user verify failed: ' . $e->getMessage()); }

// Если User ID есть, но текущий токен Bitrix24 не подтвердил пользователя,
// не рисуем страницу с ошибочными правами. Сначала получаем свежий AUTH_ID через BX24 SDK.
if (empty($GLOBALS['userVerified'])) {
    // Если это обычное открытие страницы Bitrix24 через iframe, даже POST, показываем loader,
    // а не сырой JSON. JSON возвращаем только для реальных action-запросов приложения.
    if ($action !== '') {
        jsonOut(['ok'=>false,'error'=>'Пользователь Bitrix24 не подтверждён. Обновите приложение из портала.'], 403);
    }
    renderIdentityLoader();
}

// После проверки token → user.current пересобираем контекст, чтобы во внутренние ссылки
// не уходил старый user_id руководителя из чужой/кэшированной ссылки.
$APP_CONTEXT_PARAMS = array_filter([
    'DOMAIN'     => $portal,
    'AUTH_ID'    => $accessToken,
    'REFRESH_ID' => $refreshTok,
    'member_id'  => $memberId,
    'user_id'       => $userId ?: $userIdFromRequest,
    'user_position' => $userPos ?: $userPositionFromRequest,
], static fn($v) => $v !== '' && $v !== null);

// ── ПРАВА ДОСТУПА К КАССАМ ─────────────────────────────────
// Важно: права рассчитываются ДО action-обработчиков, чтобы сотрудник не мог
// через прямой POST/GET добавить, удалить, изменить статус или экспортировать чужую кассу.
$allAccounts = [];
$categories = [];
$accounts = [];
$allowedAccountIds = null; // null = все кассы, [] = ни одной кассы.
$accessContextReady = false;

function refreshAccessContext($portal, $force = false) {
    global $allAccounts, $categories, $accounts, $allowedAccountIds, $isManager, $userId, $userPos, $currentUser, $userVerified, $accessContextReady;
    if ($accessContextReady && !$force) return;
    try {
        $allAccounts = DB::fetchAll("SELECT * FROM accounts WHERE portal=? ORDER BY name", [$portal]);
        $categories  = DB::fetchAll("SELECT * FROM categories WHERE portal=? ORDER BY type,name", [$portal]);
        // Не выдаём права по одному user_id из URL, если текущий AUTH_ID не подтверждён через user.current.
        if (empty($userVerified)) {
            $isManager = false;
            $accounts = [];
            $allowedAccountIds = [];
            $accessContextReady = true;
            return;
        }
        $isManager   = AccessControl::isManager($portal, $userId, $userPos, $currentUser);
        if ($isManager) {
            $accounts = $allAccounts;
            $allowedAccountIds = null;
        } else {
            $accounts = AccessControl::getAllowedAccounts($portal, $userId, $userPos, $allAccounts);
            $ids = array_values(array_map('intval', array_column($accounts, 'id')));
            $allowedAccountIds = $ids; // строгий режим: пустой массив означает нет доступа.
        }
        $accessContextReady = true;
    } catch (Throwable $e) {
        $allAccounts = $categories = $accounts = [];
        $allowedAccountIds = $isManager ? null : [];
        $accessContextReady = true;
        app_log('refreshAccessContext failed: ' . $e->getMessage());
    }
}

refreshAccessContext($portal);

// ── ВИДИМОСТЬ РАЗДЕЛОВ СИСТЕМЫ ─────────────────────────────
$rawVisibleSections = !empty($GLOBALS['userVerified'])
    ? AccessControl::allowedSystemSections($portal, $userId, $userPos, $isManager, $currentUser)
    : [];
$GLOBALS['canAddPaymentSection'] = !empty($rawVisibleSections['add']) || !empty($rawVisibleSections['payments']);
$visibleSections = $rawVisibleSections;
unset($visibleSections['add']); // v55: отдельный раздел «Добавить» скрыт, добавление остаётся через «Платежи».
$GLOBALS['visibleSections'] = $visibleSections;
function can_section($section) {
    if ($section === 'add') return !empty($GLOBALS['canAddPaymentSection']);
    return !empty($GLOBALS['visibleSections'][$section]);
}
function deny_section_json($sectionName = 'раздел') {
    jsonOut(['ok'=>false,'error'=>'Нет доступа к разделу: '.$sectionName], 403);
}

// Если пользователь открыл приложение без конкретной страницы и дашборд скрыт,
// переносим его на первый доступный раздел. Прямую ссылку на запрещённый раздел блокируем.
if (!can_section($page)) {
    if (!isset($_GET['page']) && !empty($visibleSections)) {
        $page = array_key_first($visibleSections);
    } else {
        $GLOBALS['accessDeniedMessage'] = 'Нет доступа к разделу: ' . ($page ?: 'неизвестный раздел');
        $page = 'denied';
    }
}

// ── ACTIONS ─────────────────────────────────────────────────


function report_filter_map($field = null) {
    $map = [
        'f_payment_id'    => ['distinct'=>'payment_id',         'filter'=>'payment_id'],
        'f_date'          => ['distinct'=>'date',               'filter'=>'f_date'],
        'rate_date_filter'=> ['distinct'=>'exchange_rate_date', 'filter'=>'exchange_rate_date'],
        'f_debit'         => ['distinct'=>'debit_amount',       'filter'=>'debit_amount'],
        'f_credit'        => ['distinct'=>'credit_amount',      'filter'=>'credit_amount'],
        'f_dept'          => ['distinct'=>'department',         'filter'=>'department'],
        'f_sub'           => ['distinct'=>'sub_department',     'filter'=>'sub_department'],
        'f_reg'           => ['distinct'=>'region',             'filter'=>'region'],
        'f_cur'           => ['distinct'=>'currency',           'filter'=>'currency'],
        'f_code'          => ['distinct'=>'operation_code',     'filter'=>'operation_code'],
        'f_op'            => ['distinct'=>'operation_name',     'filter'=>'operation_name'],
        'f_desc'          => ['distinct'=>'description',        'filter'=>'description'],
        'f_rec'           => ['distinct'=>'recipient',          'filter'=>'recipient'],
        'f_link'          => ['distinct'=>'link',               'filter'=>'link'],
    ];
    return $field === null ? $map : ($map[$field] ?? null);
}

function build_report_filter_from_request($ignoreParam = '') {
    global $isManager, $allowedAccountIds;
    $filter = ['order'=>'asc'];
    $dateExact = app_normalize_date_value($_GET['date_exact'] ?? '');
    $dateFrom = app_normalize_date_value($_GET['date_from'] ?? '');
    $dateTo   = app_normalize_date_value($_GET['date_to'] ?? '');
    if ($dateExact !== '') { $dateFrom = $dateExact; $dateTo = $dateExact; }
    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) { $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp; }
    if ($dateFrom !== '') $filter['date_from'] = $dateFrom;
    if ($dateTo !== '') $filter['date_to'] = $dateTo;
    if ($ignoreParam !== 'f_type' && trim((string)($_GET['f_type'] ?? '')) !== '') $filter['type'] = trim((string)$_GET['f_type']);
    $map = report_filter_map();
    foreach ($map as $getKey => $meta) {
        if ($getKey === $ignoreParam) continue;
        $v = trim((string)($_GET[$getKey] ?? ''));
        if ($v === '') continue;
        if (in_array($getKey, ['f_date','rate_date_filter'], true)) $v = app_normalize_date_value($v);
        $filter[$meta['filter']] = $v;
    }
    if ($ignoreParam !== 'f_acc') {
        $acc = trim((string)($_GET['f_acc'] ?? ($_GET['account_id'] ?? '')));
        if ($acc !== '') $filter['account_id'] = (int)$acc;
    }
    if (!$isManager) $filter['allowed_account_ids'] = $allowedAccountIds ?? [];
    return $filter;
}

function current_actor_payload() {
    global $portal, $userId, $currentUser;
    $name = trim((string)(($currentUser['LAST_NAME'] ?? '') . ' ' . ($currentUser['NAME'] ?? '')));
    if ($name === '') $name = trim((string)($currentUser['NAME'] ?? ''));
    $id = (int)$userId;
    if ($id > 0 && ($name === '' || preg_match('/^(User|Сотрудник)\s*#?\s*\d+$/ui', $name))) {
        try {
            $resolved = class_exists('Approval') ? Approval::userDisplayName($portal, $id, $name) : '';
            if ($resolved !== '') $name = $resolved;
        } catch (Throwable $e) { app_log('current_actor_payload name resolve failed: ' . $e->getMessage()); }
    }
    return ['id' => $id, 'name' => $name ?: 'Система'];

}


if ($action === 'approval_waiting_count') {
    if (!can_section('requests')) jsonOut(['ok'=>true,'waiting_count'=>0]);
    try {
        loadCurrentUser($portal);
        $waitingCount = class_exists('Approval') ? Approval::waitingCount($portal, $userId, $isManager) : 0;
        jsonOut(['ok'=>true,'waiting_count'=>(int)$waitingCount]);
    } catch (Throwable $e) {
        jsonOut(['ok'=>true,'waiting_count'=>0]);
    }
}

if ($action === 'report_filter_options') {
    if (!can_section('report')) deny_section_json('Отчёты');
    try {
        $field = preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['field'] ?? ''));
        $meta = report_filter_map($field);
        if (!$meta) jsonOut(['ok'=>false,'error'=>'Неизвестный фильтр'], 400);
        $filter = build_report_filter_from_request($field);
        $q = trim((string)($_GET['q'] ?? ''));
        if (in_array($field, ['f_date','rate_date_filter'], true)) $q = app_normalize_date_value($q);
        $limit = max(20, min(500, (int)($_GET['limit'] ?? 150)));
        $values = Payment::distinctValues($portal, $filter, $meta['distinct'], $limit, $q);
        jsonOut(['ok'=>true,'field'=>$field,'values'=>$values]);
    } catch (Throwable $e) {
        jsonOut(['ok'=>false,'error'=>$e->getMessage()], 500);
    }
}

if ($action === 'report_fragment') {
    if (!can_section('report')) deny_section_json('Отчёты');
    try {
        loadCurrentUser($portal);
        refreshAccessContext($portal);
        $canEditReport = AccessControl::canEditReport($portal, $userId, $userPos, $currentUser);
        $GLOBALS['canEditReport'] = $canEditReport;
        $GLOBALS['REPORT_FRAGMENT'] = true;
        $GLOBALS['REPORT_FAST_OPTIONS'] = true;
        ob_start();
        require __DIR__ . '/templates/report.php';
        $html = ob_get_clean();
        jsonOut(['ok'=>true,'html'=>$html]);
    } catch (Throwable $e) {
        if (ob_get_level()) ob_end_clean();
        jsonOut(['ok'=>false,'error'=>$e->getMessage()], 500);
    }
}


if ($action === 'add_payment'  && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!can_section('add')) deny_section_json('Добавить');
    try {
        $result = Payment::add($portal, $_POST, $isManager ? null : $allowedAccountIds, current_actor_payload());
        if (is_array($result) && isset($result['error'])) jsonOut(['ok'=>false,'error'=>$result['error']]);
        jsonOut(['ok'=>!empty($result),'id'=>$result]);
    } catch (Throwable $e) {
        error_log('add_payment: '.$e->getMessage());
        jsonOut(['ok'=>false,'error'=>$e->getMessage()], 500);
    }
}

if ($action === 'delete_payment' && isset($_GET['id'])) {
    try { loadCurrentUser($portal); refreshAccessContext($portal); Payment::delete($portal, (int)$_GET['id'], $isManager ? null : $allowedAccountIds, current_actor_payload()); } catch (Throwable $e) {}
    header('Location: '.APP_URL.'/index.php'.app_link(['page'=>'payments','deleted'=>'1'])); exit;
}

if ($action === 'delete_multiple' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        loadCurrentUser($portal); refreshAccessContext($portal); foreach ($ids as $id) if ($id>0) Payment::delete($portal, $id, $isManager ? null : $allowedAccountIds, current_actor_payload());
        jsonOut(['ok'=>true]);
    } catch (Throwable $e) { jsonOut(['ok'=>false,'error'=>$e->getMessage()], 500); }
}

if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id     = (int)($_POST['id']??0);
        $status = preg_replace('/[^a-z]/','', $_POST['status']??'');
        if ($id && in_array($status,['approved','pending','paid','rejected'])) {
            loadCurrentUser($portal);
            refreshAccessContext($portal);
            $updated = Payment::updateStatus($portal,$id,$status,$userId,trim(($currentUser['LAST_NAME']??'').' '.($currentUser['NAME']??'')),$isManager ? null : $allowedAccountIds,current_actor_payload());
            if (!$updated) jsonOut(['ok'=>false,'error'=>'Запись не найдена или нет доступа к кассе'], 403);
            jsonOut(['ok'=>true]);
        } else jsonOut(['ok'=>false,'error'=>'Неверные данные']);
    } catch (Throwable $e) { jsonOut(['ok'=>false,'error'=>$e->getMessage()], 500); }
}

if ($action === 'approve_payment' && isset($_GET['id'])) {
    try {
        loadCurrentUser($portal);
        refreshAccessContext($portal);
        Payment::updateStatus($portal,(int)$_GET['id'],'approved',$userId,
            trim(($currentUser['LAST_NAME']??'').' '.($currentUser['NAME']??'')),$isManager ? null : $allowedAccountIds,current_actor_payload());
    } catch (Throwable $e) {}
    header('Location: '.APP_URL.'/index.php'.app_link(['page'=>'payments'])); exit;
}

if ($action === 'reject_payment' && isset($_GET['id'])) {
    try {
        loadCurrentUser($portal);
        refreshAccessContext($portal);
        Payment::updateStatus($portal,(int)$_GET['id'],'rejected',$userId,
            trim(($currentUser['LAST_NAME']??'').' '.($currentUser['NAME']??'')),$isManager ? null : $allowedAccountIds,current_actor_payload());
    } catch (Throwable $e) {}
    header('Location: '.APP_URL.'/index.php'.app_link(['page'=>'payments'])); exit;
}

if ($action === 'import_excel' && isset($_FILES['excel'])) {
    if (!can_section('import')) deny_section_json('Импорт');
    set_time_limit(300);
    $isAjaxImport = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    $ext   = strtolower(pathinfo($_FILES['excel']['name']??'', PATHINFO_EXTENSION));
    $accId = (int)($_POST['account_id']??0);
    if (!in_array($ext,['xlsx','xls'])) {
        $importResult = ['error'=>'Допускается только .xlsx файл'];
    } else {
        try {
            if (!$isManager && !AccessControl::canAccessAccount($portal, $userId, $userPos, $accId, false)) {
                $importResult = ['error'=>'Нет права импортировать операции в выбранную кассу'];
            } else {
                $importResult = Import::fromExcel($portal,$_FILES['excel']['tmp_name'],$accId?:null,$_FILES['excel']['name'] ?? '');
            }
        }
        catch (Throwable $e) { $importResult = ['error'=>$e->getMessage()]; }
    }
    if ($isAjaxImport) {
        if (!empty($importResult['error'])) jsonOut(['ok'=>false] + $importResult, 400);
        jsonOut(['ok'=>true] + $importResult);
    }
    $page = 'import';
}

if ($action === 'delete_imported') {
    if (!can_section('import')) deny_section_json('Импорт');
    $importResult = ['error'=>'Массовое удаление импортированных данных отключено. Используйте удаление конкретного файла импорта в истории.'];
    $page = 'import';
}


if ($action === 'delete_import_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!can_section('import')) deny_section_json('Импорт');
    try {
        $importId = (int)($_POST['import_id'] ?? 0);
        if ($importId <= 0) jsonOut(['ok'=>false,'error'=>'Не выбран файл импорта'], 400);
        if (!Payment::hasImportLogColumn()) {
            jsonOut(['ok'=>false,'error'=>'Для удаления отдельного файла выполните sql/update11_access_report_import.sql'], 400);
        }
        $deleted = Import::deleteImportBatch($portal, $importId, $isManager ? null : $allowedAccountIds);
        jsonOut(['ok'=>true,'deleted'=>$deleted]);
    } catch (Throwable $e) { jsonOut(['ok'=>false,'error'=>$e->getMessage()], 500); }
}

if ($action === 'update_report_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!can_section('report')) deny_section_json('Отчёты');
    try {
        loadCurrentUser($portal);
        refreshAccessContext($portal);
        $canEditReport = AccessControl::canEditReport($portal, $userId, $userPos, $currentUser);
        if (!$canEditReport) jsonOut(['ok'=>false,'error'=>'Нет права редактировать отчёт'], 403);
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) jsonOut(['ok'=>false,'error'=>'Не выбран платёж'], 400);
        $ok = Payment::updateFromReport($portal, $id, $_POST, $isManager ? null : $allowedAccountIds, current_actor_payload());
        if (is_array($ok) && isset($ok['error'])) jsonOut(['ok'=>false,'error'=>$ok['error']], 400);
        if (!$ok) jsonOut(['ok'=>false,'error'=>'Запись не найдена или нет доступа к кассе'], 403);
        jsonOut(['ok'=>true]);
    } catch (Throwable $e) { jsonOut(['ok'=>false,'error'=>$e->getMessage()], 500); }
}



if ($action === 'approval_commission_preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!can_section('requests')) deny_section_json('Согласование заявок');
    try {
        if (empty($_FILES['commission_file']['tmp_name']) || !is_uploaded_file($_FILES['commission_file']['tmp_name'])) {
            jsonOut(['ok'=>false,'error'=>'Выберите Excel-файл комиссионной заявки'], 400);
        }
        $parsed = Approval::parseCommissionFile($_FILES['commission_file']['tmp_name'], $_FILES['commission_file']['name'] ?? '');
        if (!empty($parsed['error'])) jsonOut(['ok'=>false,'error'=>$parsed['error']], 400);
        $parsed = Approval::enrichCommissionParsed($portal, $parsed);
        // Для предпросмотра не отправляем все строки, чтобы iframe не тормозил.
        unset($parsed['items']);
        jsonOut(['ok'=>true] + $parsed);
    } catch (Throwable $e) { jsonOut(['ok'=>false,'error'=>$e->getMessage()], 500); }
}


if ($action === 'approval_commission_file') {
    if (!can_section('requests')) deny_section_json('Согласование заявок');
    try {
        loadCurrentUser($portal);
        refreshAccessContext($portal);
        $id = (int)($_GET['id'] ?? 0);
        $req = $id > 0 ? Approval::get($portal, $id) : null;
        if (!$req) { http_response_code(404); echo 'Файл не найден'; exit; }
        $canViewAllReq = AccessControl::hasFlag($portal, $userId, $userPos, 'can_view_all_requests', $isManager, $currentUser);
        if (!Approval::canView($portal, $req, $userId, $isManager, $canViewAllReq)) { http_response_code(403); echo 'Нет доступа'; exit; }
        $path = Approval::commissionFileAbsolutePath($req['commission_import_path'] ?? '');
        $name = trim((string)($req['commission_import_filename'] ?? 'commission_import.xlsx')) ?: 'commission_import.xlsx';
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if ($path !== '') {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header("Content-Disposition: attachment; filename=\"" . rawurlencode($name) . "\"; filename*=UTF-8''" . rawurlencode($name));
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: private, no-store, max-age=0');
            readfile($path);
            exit;
        }
        // Если оригинальный файл был удалён при обновлении проекта/деплое, отдаём восстановленную таблицу по данным заявки.
        $fallback = Approval::commissionFallbackExcelText($req);
        if ($fallback !== '') {
            $fallbackName = preg_replace('/\.(xlsx|xls)$/iu', '', $name) . '_recovered.xls';
            header('Content-Type: application/vnd.ms-excel; charset=UTF-16LE');
            header("Content-Disposition: attachment; filename=\"" . rawurlencode($fallbackName) . "\"; filename*=UTF-8''" . rawurlencode($fallbackName));
            header('Content-Length: ' . strlen($fallback));
            header('Cache-Control: private, no-store, max-age=0');
            echo $fallback;
            exit;
        }
        http_response_code(404); echo 'Файл импорта не найден'; exit;
    } catch (Throwable $e) { http_response_code(500); echo 'Ошибка скачивания файла: ' . $e->getMessage(); exit; }
}

if ($action === 'approval_attachment_file') {
    if (!can_section('requests')) deny_section_json('Согласование заявок');
    try {
        loadCurrentUser($portal);
        refreshAccessContext($portal);
        $id = (int)($_GET['id'] ?? 0);
        $idx = max(0, (int)($_GET['file'] ?? 0));
        $req = $id > 0 ? Approval::get($portal, $id) : null;
        if (!$req) { http_response_code(404); echo 'Файл не найден'; exit; }
        $canViewAllReq = AccessControl::hasFlag($portal, $userId, $userPos, 'can_view_all_requests', $isManager, $currentUser);
        if (!Approval::canView($portal, $req, $userId, $isManager, $canViewAllReq)) { http_response_code(403); echo 'Нет доступа'; exit; }
        $files = Approval::attachments($req);
        $file = $files[$idx] ?? null;
        if (!$file) { http_response_code(404); echo 'Файл не найден'; exit; }
        $name = basename((string)($file['name'] ?? 'file')) ?: 'file';
        $path = !empty($file['path']) ? Approval::requestAttachmentAbsolutePath($file['path']) : '';
        if ($path === '' && !empty($file['data_b64'])) {
            $raw = base64_decode((string)$file['data_b64'], true);
            if ($raw !== false) {
                while (ob_get_level() > 0) { @ob_end_clean(); }
                $mime = trim((string)($file['mime'] ?? '')) ?: 'application/octet-stream';
                header('Content-Type: ' . $mime);
                header("Content-Disposition: attachment; filename=\"" . rawurlencode($name) . "\"; filename*=UTF-8''" . rawurlencode($name));
                header('Content-Length: ' . strlen($raw));
                header('Cache-Control: private, no-store, max-age=0');
                echo $raw;
                exit;
            }
        }
        if ($path === '') { http_response_code(404); echo 'Файл не найден. Если заявка была создана до резервного хранения файлов или папка uploads уже была удалена, старый файл восстановить из базы невозможно.'; exit; }
        while (ob_get_level() > 0) { @ob_end_clean(); }
        $mime = function_exists('mime_content_type') ? @mime_content_type($path) : '';
        if (!$mime) $mime = trim((string)($file['mime'] ?? '')) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header("Content-Disposition: attachment; filename=\"" . rawurlencode($name) . "\"; filename*=UTF-8''" . rawurlencode($name));
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store, max-age=0');
        readfile($path);
        exit;
    } catch (Throwable $e) { http_response_code(500); echo 'Ошибка скачивания файла: ' . $e->getMessage(); exit; }
}


if ($action === 'approval_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!can_section('requests')) deny_section_json('Согласование заявок');
    try {
        loadCurrentUser($portal);
        if (!AccessControl::hasFlag($portal, $userId, $userPos, 'can_create_request', $isManager, $currentUser)) { jsonOut(['ok'=>false,'error'=>'Нет права создавать заявки'], 403); }
        $result = Approval::create($portal, $_POST, $currentUser);
        if (is_array($result) && isset($result['error'])) jsonOut(['ok'=>false,'error'=>$result['error']], 400);
        jsonOut(['ok'=>true,'id'=>$result]);
    } catch (Throwable $e) { jsonOut(['ok'=>false,'error'=>$e->getMessage()], 500); }
}

if ($action === 'approval_action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!can_section('requests')) deny_section_json('Согласование заявок');
    try {
        loadCurrentUser($portal);
        refreshAccessContext($portal);
        $result = Approval::action($portal, (int)($_POST['id'] ?? 0), $_POST['do'] ?? '', $_POST, $currentUser, $isManager);
        if (is_array($result) && isset($result['error'])) jsonOut(['ok'=>false,'error'=>$result['error']], 400);
        $waitingCount = 0;
        try { $waitingCount = Approval::waitingCount($portal, $userId, $isManager); } catch (Throwable $e) { $waitingCount = 0; }
        jsonOut(['ok'=>true, 'waiting_count'=>$waitingCount] + (is_array($result) ? $result : []));
    } catch (Throwable $e) { jsonOut(['ok'=>false,'error'=>$e->getMessage()], 500); }
}

if ($action === 'approval_route_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!AccessControl::canSettingsTab($portal, $userId, $userPos, 'approval', $isManager, $currentUser)) deny_section_json('Настройки согласования');
        $stage = $_POST['stage'] ?? '';
        $ids = $_POST['approver_ids'] ?? '';
        $names = $_POST['approver_names'] ?? '';
        $threshold = (float)($_POST['threshold_amount'] ?? 15000);
        $rawRouteType = preg_replace('/[^a-z0-9_]/','',(string)($_POST['route_type'] ?? 'commission'));
        $routeType = in_array($rawRouteType, ['regular','commission','commission_box','commission2'], true) ? $rawRouteType : 'commission';
        Approval::saveRouteSetting($portal, $stage, $ids, $names, $threshold, '', 100, 1, '', '', $routeType);
        jsonOut(['ok'=>true]);
    } catch (Throwable $e) { jsonOut(['ok'=>false,'error'=>$e->getMessage()], 500); }
}

if ($action === 'approval_users') {
    try { jsonOut(['ok'=>true,'users'=>Bitrix::getUsers($portal, $_GET['q'] ?? '')]); }
    catch (Throwable $e) { jsonOut(['ok'=>false,'users'=>[]]); }
}

if ($action === 'export_excel') {
    $exportPage = preg_replace('/[^a-z_]/', '', (string)($_GET['page'] ?? 'report'));
    if ($exportPage === 'payments') {
        if (!can_section('payments')) deny_section_json('Платежи');
    } else {
        if (!can_section('report')) deny_section_json('Отчёты');
    }
    try {
        if ($exportPage === 'payments') {
            // Экспорт из раздела "Платежи" должен брать именно текущий список платежей,
            // а не набор колонок/фильтров отчёта.
            $pf = [
                'order'  => 'desc',
                'limit'  => 1000000,
                'offset' => 0,
                'export' => true,
                'date_from' => trim((string)($_GET['date_from'] ?? date('Y-m-01'))),
                'date_to'   => trim((string)($_GET['date_to']   ?? date('Y-m-d'))),
                'status'    => '',
            ];
            if (!empty($_GET['type'])) $pf['type'] = trim((string)$_GET['type']);
            if (!empty($_GET['account_id'])) $pf['account_id'] = (int)$_GET['account_id'];
            if (!empty($_GET['category_id'])) $pf['category_id'] = (int)$_GET['category_id'];
            if (!$isManager) $pf['allowed_account_ids'] = $allowedAccountIds;
            Export::toExcel(Payment::exportIterator($portal, $pf, 2000), Payment::totalsByFilter($portal, $pf), 'платежи_' . date('Y-m-d') . '.xlsx');
        }

        $exportDateExact = app_normalize_date_value($_GET['date_exact'] ?? '');
        $f = ['order'=>'asc','limit'=>1000000,'offset'=>0,'export'=>true];
        if (isset($_GET['date_from']) && trim((string)$_GET['date_from']) !== '') $f['date_from'] = app_normalize_date_value($_GET['date_from']);
        if (isset($_GET['date_to']) && trim((string)$_GET['date_to']) !== '') $f['date_to'] = app_normalize_date_value($_GET['date_to']);
        if ($exportDateExact !== '') { $f['date_from'] = $exportDateExact; $f['date_to'] = $exportDateExact; }
        if (!empty($_GET['f_type'])) $f['type'] = $_GET['f_type'];
        if (!empty($_GET['type'])) $f['type'] = $_GET['type'];
        if (!empty($_GET['f_payment_id'])) $f['payment_id'] = trim((string)$_GET['f_payment_id']);
        if (!empty($_GET['f_debit'])) $f['debit_amount'] = trim((string)$_GET['f_debit']);
        if (!empty($_GET['f_credit'])) $f['credit_amount'] = trim((string)$_GET['f_credit']);
        $reqAcc = $_GET['f_acc'] ?? ($_GET['account_id'] ?? '');
        if ($reqAcc !== '') $f['account_id'] = (int)$reqAcc;
        $map = [
            'f_date'=>'f_date','rate_date_filter'=>'exchange_rate_date','f_dept'=>'department','f_sub'=>'sub_department','f_reg'=>'region','f_cur'=>'currency',
            'f_code'=>'operation_code','f_desc'=>'description','f_rec'=>'recipient','f_link'=>'link','f_op'=>'operation_name'
        ];
        foreach ($map as $get=>$key) if (isset($_GET[$get]) && trim((string)$_GET[$get]) !== '') {
            $val = trim((string)$_GET[$get]);
            if (in_array($get, ['f_date','rate_date_filter'], true)) $val = app_normalize_date_value($val);
            $f[$key] = $val;
        }
        if (!$isManager) $f['allowed_account_ids'] = $allowedAccountIds;
        $exportCols = $_GET['cols'] ?? null;
        Export::toExcel(Payment::exportIterator($portal, $f, 2000), Payment::totalsByFilter($portal,$f), 'отчет_'.date('Y-m-d').'.xlsx', $exportCols);
    } catch (Throwable $e) {
        error_log('export_excel failed: ' . $e->getMessage());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo 'Ошибка выгрузки Excel. Проверьте журнал сервера: ' . $e->getMessage();
        exit;
    }
}

if ($action === 'export_csv') {
    jsonOut(['ok'=>false,'error'=>'CSV export disabled'], 410);
}

if ($action === 'api_users') {
    try { jsonOut(Bitrix::getUsers($portal,$_GET['q']??'')); }
    catch (Throwable $e) { jsonOut([]); }
}

if ($action === 'api_contacts') {
    try { jsonOut(['contacts'=>Bitrix::getContacts($portal,$_GET['q']??''),'companies'=>Bitrix::getCompanies($portal,$_GET['q']??'')]); }
    catch (Throwable $e) { jsonOut(['contacts'=>[],'companies'=>[]]); }
}

// Для страниц с правами доступа заранее загружаем текущего пользователя.
// Это устраняет ошибку, когда настройки показывали 'Доступ запрещён', хотя User ID 13 является руководителем.
if (in_array($page, ['settings','requests'], true)) {
    try { loadCurrentUser($portal); } catch (Throwable $e) { app_log('Preload current user failed: ' . $e->getMessage()); }
}

// ── ДАННЫЕ ДЛЯ СТРАНИЦ ──────────────────────────────────────
// Синхронизируем завершённые заявки с расходными операциями до построения дашборда/отчёта.
// v55: не синхронизируем заявки с платежами при каждом открытии любой страницы.
// Это был один из главных источников зависаний iframe. Синхронизация запускается в Approval::action().
if (false && $page !== 'report') { try { Approval::syncCompletedPayments($portal, 20); } catch (Throwable $e) { app_log('Approval sync before render failed: ' . $e->getMessage()); } }
// Обновляем справочники после action-блоков. Для обычного сотрудника остаётся строгий список касс.
refreshAccessContext($portal);
$rawVisibleSections = !empty($GLOBALS['userVerified'])
    ? AccessControl::allowedSystemSections($portal, $userId, $userPos, $isManager, $currentUser)
    : [];
$GLOBALS['canAddPaymentSection'] = !empty($rawVisibleSections['add']) || !empty($rawVisibleSections['payments']);
$visibleSections = $rawVisibleSections;
unset($visibleSections['add']);
$GLOBALS['visibleSections'] = $visibleSections;
$canEditReport = !empty($GLOBALS['userVerified']) ? AccessControl::canEditReport($portal, $userId, $userPos, $currentUser) : false;
$GLOBALS['canEditReport'] = $canEditReport;

$filter = [
    'date_from'   => $_GET['date_from']   ?? date('Y-m-01'),
    'date_to'     => $_GET['date_to']     ?? date('Y-m-d'),
    'type'        => $_GET['type']        ?? '',
    // v21: статус скрыт из интерфейса платежей и не используется как фильтр
    'status'      => '',
    'account_id'  => $_GET['account_id']  ?? '',
    'category_id' => $_GET['category_id'] ?? '',
];
if (!$isManager) {
    $filter['allowed_account_ids'] = $allowedAccountIds;
}

if ($action === 'page_fragment') {
    try {
        if (!can_section($page)) deny_section_json($page ?: 'раздел');
        $tplDir = __DIR__ . '/templates/';
        ob_start();
        switch ($page) {
            case 'dashboard': require $tplDir . 'dashboard.php'; break;
            case 'payments':  require $tplDir . 'payments.php';  break;
            case 'add':       require $tplDir . 'add_payment.php'; break;
            case 'import':    require $tplDir . 'import.php'; break;
            case 'report':
                echo '<div id="reportAsyncRoot" class="report-async-root report-empty-root"><div class="report-shell-skeleton"><div class="page-header"><h1>📈 Кассовые операции</h1><div class="skeleton-btn"></div></div><div class="filter-bar skeleton-filter"><div></div><div></div><div></div><div></div></div><div class="rep-summary skeleton-summary"><div></div><div></div><div></div><div></div></div><div class="report-table-wrap skeleton-table"><div></div><div></div><div></div><div></div><div></div></div></div></div>';
                break;
            case 'requests':  require $tplDir . 'requests.php'; break;
            case 'settings':  require $tplDir . 'settings.php'; break;
            default:          require $tplDir . 'dashboard.php';
        }
        $html = ob_get_clean();
        jsonOut(['ok'=>true,'page'=>$page,'html'=>$html]);
    } catch (Throwable $e) {
        if (ob_get_level()) ob_end_clean();
        jsonOut(['ok'=>false,'error'=>$e->getMessage()], 500);
    }
}

require __DIR__ . '/templates/layout.php';
