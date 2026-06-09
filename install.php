<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/DB.php';
require_once __DIR__ . '/src/Auth.php';

/**
 * install.php для локального приложения Bitrix24.
 * ВАЖНО: здесь нет редиректа на OAuth.
 * Bitrix24 при открытии приложения сам передаёт AUTH_ID / REFRESH_ID / DOMAIN.
 * Мы принимаем эти параметры, сохраняем токен и сразу переводим пользователя в index.php.
 */

function bx_req($name, $alt = null) {
    if (isset($_REQUEST[$name])) {
        if (is_array($_REQUEST[$name])) return '';
        return trim((string)$_REQUEST[$name]);
    }
    if ($alt && isset($_REQUEST[$alt])) {
        if (is_array($_REQUEST[$alt])) return '';
        return trim((string)$_REQUEST[$alt]);
    }
    return '';
}

function bx_auth_param($name, $fallbackNames = []) {
    if (isset($_REQUEST[$name]) && !is_array($_REQUEST[$name])) {
        return trim((string)$_REQUEST[$name]);
    }
    foreach ($fallbackNames as $n) {
        if (isset($_REQUEST[$n]) && !is_array($_REQUEST[$n])) {
            return trim((string)$_REQUEST[$n]);
        }
    }
    // Некоторые варианты Bitrix/SDK могут передать auth как массив.
    if (isset($_REQUEST['auth']) && is_array($_REQUEST['auth'])) {
        $map = [
            'AUTH_ID'    => ['access_token', 'AUTH_ID', 'auth'],
            'REFRESH_ID' => ['refresh_token', 'REFRESH_ID'],
        ];
        foreach (($map[$name] ?? []) as $k) {
            if (!empty($_REQUEST['auth'][$k]) && !is_array($_REQUEST['auth'][$k])) {
                return trim((string)$_REQUEST['auth'][$k]);
            }
        }
    }
    return '';
}

$domain    = bx_req('DOMAIN', 'domain');
$authId    = bx_auth_param('AUTH_ID', ['access_token', 'auth']);
$refreshId = bx_auth_param('REFRESH_ID', ['refresh_token']);
$memberId  = bx_req('member_id', 'MEMBER_ID');
$lang      = bx_req('LANG', 'lang');
$protocol  = bx_req('PROTOCOL', 'protocol');
$userId    = bx_req('user_id', 'USER_ID');
$userPos   = bx_req('user_position', 'USER_POSITION');

function finance_session_key($portal, $memberId, $userId) {
    return 'bx_' . md5((string)$portal . '_' . (string)$memberId . '_' . (string)$userId);
}

function finance_bx_rest($portal, $method, $token) {
    if (!$portal || !$token || !$method) return [];
    $url = 'https://' . preg_replace('/[^a-zA-Z0-9.\-]/', '', $portal) . '/rest/' . $method . '.json';
    $body = http_build_query(['auth' => $token]);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = $resp ? json_decode($resp, true) : null;
        return is_array($data) ? $data : [];
    }

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 8,
        'ignore_errors' => true,
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        'content'       => $body,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $data = $resp ? json_decode($resp, true) : null;
    return is_array($data) ? $data : [];
}

function finance_bx_current_user($portal, $token) {
    if (!$portal || !$token) return [];
    $user = [];

    // profile работает на basic scope и не требует user/user_basic.
    $profile = finance_bx_rest($portal, 'profile', $token);
    if (!empty($profile['result']) && is_array($profile['result'])) {
        $user = array_merge($user, $profile['result']);
    }

    // user.admin тоже basic scope. Нужен, чтобы админ Битрикс24 видел настройки.
    $admin = finance_bx_rest($portal, 'user.admin', $token);
    if (array_key_exists('result', $admin)) {
        $isAdmin = !empty($admin['result']);
        $user['ADMIN'] = $isAdmin;
        $user['IS_ADMIN'] = $isAdmin;
    }

    // Если в локальном приложении добавлен scope user/user_basic, добираем должность.
    // Если нет — игнорируем insufficient_scope и не ломаем установку.
    $uc = finance_bx_rest($portal, 'user.current', $token);
    if (!empty($uc['result']) && is_array($uc['result'])) {
        $user = array_merge($user, $uc['result']);
    }

    return !empty($user['ID']) ? $user : [];
}

// Основной сценарий: Bitrix24 передал токен автоматически.
if ($domain && $authId) {
    // Получаем текущего пользователя сразу на сервере, чтобы страница настроек/права
    // не зависели от сторонних cookies и повторного BX24.user.current в браузере.
    $bxUser = finance_bx_current_user($domain, $authId);
    if (!$userId && !empty($bxUser['ID'])) {
        $userId = (string)$bxUser['ID'];
    }
    if (!$userPos && !empty($bxUser['WORK_POSITION'])) {
        $userPos = (string)$bxUser['WORK_POSITION'];
    }

    $sKey = finance_session_key($domain, $memberId, $userId);

    $_SESSION['portal']              = $domain;
    $_SESSION['member_id']           = $memberId;
    $_SESSION['user_id']             = $userId;
    $_SESSION['user_position']       = $userPos;
    $_SESSION['session_key']         = $sKey;
    $_SESSION['access_token']        = $authId;
    $_SESSION['refresh_token']       = $refreshId;
    $_SESSION[$sKey . '_token']      = $authId;
    $_SESSION[$sKey . '_refresh']    = $refreshId;

    try {
        DB::execute(
            "INSERT INTO tokens (portal, access_token, refresh_token, expires_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
             access_token=VALUES(access_token),
             refresh_token=VALUES(refresh_token),
             expires_at=VALUES(expires_at)",
            [$domain, $authId, $refreshId, time() + 3600]
        );
        self_install($domain);
    } catch (Throwable $e) {
        app_log('install token save/self_install error: ' . $e->getMessage());
    }

    // Передаём контекст дальше через URL. Это нужно для Chrome/Edge,
    // где PHP SESSION внутри iframe может не сохраниться.
    $redirectParams = array_filter([
        'DOMAIN'        => $domain,
        'AUTH_ID'       => $authId,
        'REFRESH_ID'    => $refreshId,
        'member_id'     => $memberId,
        'user_id'       => $userId,
        'user_position' => $userPos,
        'LANG'          => $lang,
        'PROTOCOL'      => $protocol,
        'install_done'  => '1',
    ], static fn($v) => $v !== '' && $v !== null);

    $indexUrl = APP_URL . '/index.php?' . http_build_query($redirectParams);

    // КРИТИЧНО: Bitrix24 считает локальное приложение установленным только после
    // вызова BX24.installFinish() на странице установки. Поэтому здесь нельзя
    // сразу делать PHP redirect на index.php — обычные пользователи увидят
    // "администратор ещё не завершил установку".
    ?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Завершение установки</title>
<script src="//api.bitrix24.com/api/v1/"></script>
<style>
body{font-family:Arial,sans-serif;background:#f0f4f8;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;color:#1f2937}
.box{background:#fff;padding:32px 36px;border-radius:14px;box-shadow:0 8px 30px rgba(15,23,42,.10);max-width:620px;line-height:1.55;text-align:center}
h2{margin:0 0 12px;color:#1a5276}.muted{color:#64748b;font-size:14px}.ok{background:#ecfdf5;color:#065f46;padding:12px;border-radius:10px;margin-top:14px;font-size:14px}.err{background:#fff1f2;color:#991b1b;padding:12px;border-radius:10px;margin-top:14px;font-size:14px}.btn{display:inline-block;margin-top:16px;background:#2563eb;color:#fff;text-decoration:none;padding:10px 16px;border-radius:10px}
</style>
</head>
<body>
<div class="box" id="box">
  <h2>💰 Финансовый учёт</h2>
  <p>Завершаем установку приложения в Битрикс24...</p>
  <p class="muted">Портал: <b><?=htmlspecialchars($domain, ENT_QUOTES, 'UTF-8')?></b></p>
  <div class="ok">Токен получен. User ID: <?=htmlspecialchars((string)$userId, ENT_QUOTES, 'UTF-8')?></div>
  <a class="btn" id="manualLink" href="<?=htmlspecialchars($indexUrl, ENT_QUOTES, 'UTF-8')?>">Открыть приложение</a>
</div>
<script>
(function(){
  var targetUrl = <?=json_encode($indexUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
  var finished = false;

  function goNext() {
    if (finished) return;
    finished = true;
    window.location.replace(targetUrl);
  }

  function showError(message) {
    var box = document.getElementById('box');
    if (box) {
      box.insertAdjacentHTML('beforeend', '<div class="err">' + String(message || 'Не удалось автоматически завершить установку') + '</div>');
    }
  }

  // Даже если SDK не загрузится из-за хостинга/браузера, даём перейти вручную,
  // но для полного завершения установки администратору нужен успешный installFinish().
  if (typeof BX24 === 'undefined' || !BX24.init) {
    showError('BX24 SDK не загружен. Проверьте открытие install.php именно из Битрикс24.');
    return;
  }

  BX24.init(function(){
    try {
      if (typeof BX24.installFinish === 'function') {
        BX24.installFinish();
      }
      var box = document.getElementById('box');
      if (box) box.insertAdjacentHTML('beforeend', '<div class="ok">Установка отмечена как завершённая. Открываем приложение...</div>');
      setTimeout(goNext, 700);
    } catch (e) {
      console.error('BX24.installFinish failed', e);
      showError('Ошибка BX24.installFinish: ' + (e && e.message ? e.message : e));
    }
  });
})();
</script>
</body>
</html><?php
    exit;
}

// Если токен не пришёл, не отправляем пользователя на OAuth.
// Для локального приложения это обычно означает, что приложение открыто не из Bitrix24,
// либо Bitrix24/хостинг не передал параметры в iframe.
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Финансовый учёт</title>
<script src="//api.bitrix24.com/api/v1/"></script>
<style>
body{font-family:Arial,sans-serif;background:#f0f4f8;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;color:#1f2937}
.box{background:#fff;padding:32px 36px;border-radius:14px;box-shadow:0 8px 30px rgba(15,23,42,.10);max-width:620px;line-height:1.55}
h2{margin:0 0 12px;color:#1a5276}.muted{color:#64748b;font-size:14px}.err{background:#fff1f2;color:#991b1b;padding:12px;border-radius:10px;margin-top:14px;font-size:14px}.ok{background:#ecfdf5;color:#065f46;padding:12px;border-radius:10px;margin-top:14px;font-size:14px}
code{background:#f1f5f9;padding:2px 5px;border-radius:5px}
</style>
</head>
<body>
<div class="box" id="box">
  <h2>💰 Финансовый учёт</h2>
  <p>Ожидаем автоматический токен от Битрикс24.</p>
  <p class="muted">Редирект на OAuth отключён. Приложение должно открываться из портала Bitrix24 и получать <code>AUTH_ID</code>, <code>REFRESH_ID</code>, <code>DOMAIN</code> автоматически.</p>
  <div class="err">Токен <code>AUTH_ID</code> не получен. Проверьте URL обработчика приложения и открывайте приложение только из Bitrix24.</div>
</div>
<script>
(function(){
  if (typeof BX24 === 'undefined' || !BX24.init) return;
  BX24.init(function(){
    var params = new URLSearchParams(window.location.search);
    if (params.get('AUTH_ID')) return;

    // Fallback: иногда Bitrix24 SDK уже знает auth-данные, хотя серверу они
    // не пришли в REQUEST. В этом случае собираем их в JS и повторно открываем
    // install.php уже с AUTH_ID/REFRESH_ID/DOMAIN/member_id.
    try {
      var auth = (typeof BX24.getAuth === 'function') ? (BX24.getAuth() || {}) : {};
      var authId = auth.access_token || auth.AUTH_ID || auth.auth || '';
      var refreshId = auth.refresh_token || auth.REFRESH_ID || '';
      var domain = auth.domain || auth.DOMAIN || params.get('DOMAIN') || params.get('domain') || '';
      var memberId = auth.member_id || auth.MEMBER_ID || params.get('member_id') || '';

      if (authId && domain) {
        params.set('DOMAIN', domain);
        params.set('AUTH_ID', authId);
        if (refreshId) params.set('REFRESH_ID', refreshId);
        if (memberId) params.set('member_id', memberId);

        if (BX24.callMethod) {
          BX24.callMethod('profile', {}, function(res){
            if (res && !res.error()) {
              var u = res.data() || {};
              if (u.ID) params.set('user_id', u.ID);
              if (u.WORK_POSITION) params.set('user_position', u.WORK_POSITION);
            }
            window.location.replace('install.php?' + params.toString());
          });
        } else {
          window.location.replace('install.php?' + params.toString());
        }
        return;
      }
    } catch(e) {}

    // Диагностика, если AUTH_ID нельзя получить даже через SDK.
    try {
      BX24.callMethod('profile', {}, function(res){
        var box = document.getElementById('box');
        if (res && !res.error()) {
          var u = res.data() || {};
          box.insertAdjacentHTML('beforeend', '<div class="ok">BX24 SDK работает. Текущий профиль: ID '+ String(u.ID || '') +'. Но AUTH_ID не был передан и BX24.getAuth() его не вернул.</div>');
        } else {
          box.insertAdjacentHTML('beforeend', '<div class="err">BX24 SDK доступен, но profile вернул ошибку.</div>');
        }
      });
    } catch(e) {}
  });
})();
</script>
</body>
</html>
<?php

function self_install($portal) {
    $cats = [
        ['Продажи','income'],['Услуги','income'],['Прочие поступления','income'],
        ['Зарплата','expense'],['Аренда','expense'],['Реклама','expense'],
        ['Хозяйственные расходы','expense'],['Прочие расходы','expense'],
    ];
    foreach ($cats as [$name,$type]) {
        try {
            $ex = DB::fetchOne("SELECT id FROM categories WHERE portal=? AND name=?",[$portal,$name]);
            if (!$ex) DB::insert("INSERT INTO categories (portal,name,type) VALUES (?,?,?)",[$portal,$name,$type]);
        } catch (Throwable $e) {}
    }
    try {
        $ex = DB::fetchOne("SELECT id FROM accounts WHERE portal=?",[$portal]);
        if (!$ex) DB::insert("INSERT INTO accounts (portal,name) VALUES (?,'Основная касса')",[$portal]);
    } catch (Throwable $e) {}

    try {
        $ex = DB::fetchOne("SELECT id FROM currencies WHERE portal=?",[$portal]);
        if (!$ex) {
            $curs = [['TJS','Таджикский сомони',1,1],['USD','Доллар США',10.9,0],['EUR','Евро',11.8,0]];
            foreach ($curs as [$code,$name,$rate,$base]) {
                DB::insert("INSERT IGNORE INTO currencies (portal,code,name,rate,is_base) VALUES (?,?,?,?,?)",
                    [$portal,$code,$name,$rate,$base]);
            }
        }
    } catch (Throwable $e) {}
}
