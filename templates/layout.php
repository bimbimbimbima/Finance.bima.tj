<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Финансовый учёт</title>
<link rel="stylesheet" href="assets/style.css?v=<?=filemtime(dirname(__DIR__).'/assets/style.css')?>">
<script src="https://api.bitrix24.com/api/v1/"></script>
</head>
<body>
<div class="app">
  <nav class="sidebar">
    <a class="logo logo-text" href="<?=app_link(['page'=>'dashboard'])?>" title="На дашборд"><span class="logo-title">Финансовая система</span><span class="logo-subtitle">СПО БИМА</span></a>
    <?php
      $visibleSections = $GLOBALS['visibleSections'] ?? [];
      $show = static fn($k) => !empty($visibleSections[$k]);
      // v104: счётчик согласований не блокирует первичную отрисовку меню.
      // Он обновляется асинхронно через JS после загрузки страницы.
      $reqMenuCount = 0;
    ?>
    <?php if ($show('dashboard')): ?>
    <a href="<?=app_link(['page'=>'dashboard'])?>" class="<?=$page==='dashboard'?'active':''?>">
      <span class="icon">📊</span> Дашборд
    </a>
    <?php endif; ?>
    <?php if ($show('payments')): ?>
    <a href="<?=app_link(['page'=>'payments'])?>" class="<?=$page==='payments'?'active':''?>">
      <span class="icon">📋</span> Платежи
    </a>
    <?php endif; ?>
    <?php if ($show('import')): ?>
    <a href="<?=app_link(['page'=>'import'])?>" class="<?=$page==='import'?'active':''?>">
      <span class="icon">📥</span> Импорт
    </a>
    <?php endif; ?>
    <?php if ($show('report')): ?>
    <a href="<?=app_link(['page'=>'report'])?>" class="<?=$page==='report'?'active':''?>">
      <span class="icon">📈</span> Отчёты
    </a>
    <?php endif; ?>

    <?php if ($show('settings')): ?>
    <a href="<?=app_link(['page'=>'settings'])?>" class="<?=$page==='settings'?'active':''?>">
      <span class="icon">⚙️</span> Настройки
    </a>
    <?php endif; ?>

    <?php if ($show('requests')): ?>
    <a href="<?=app_link(['page'=>'requests'])?>" class="<?=$page==='requests'?'active':''?>">
      <span class="icon">🧾</span> Согласование <?php if($reqMenuCount>0): ?><span class="nav-counter"><?=$reqMenuCount?></span><?php endif; ?>
    </a>
    <?php endif; ?>
    <div class="sidebar-footer">
      <small><?=htmlspecialchars($portal)?></small>
    </div>
  </nav>
  <main class="content" id="mainContent">
    <?php
    $tplDir = dirname(__DIR__) . '/templates/';
    try {
        switch ($page) {
            case 'dashboard': require $tplDir . 'dashboard.php';   break;
            case 'payments':  require $tplDir . 'payments.php';    break;
            case 'add':       require $tplDir . 'add_payment.php'; break;
            case 'import':    require $tplDir . 'import.php';      break;
            case 'report':
                if (!empty($_GET['sync_report'])) {
                    require $tplDir . 'report.php';
                } else {
                    echo '<div id="reportAsyncRoot" class="report-async-root report-empty-root"><div class="report-shell-skeleton"><div class="page-header"><h1>📈 Кассовые операции</h1><div class="skeleton-btn"></div></div><div class="filter-bar skeleton-filter"><div></div><div></div><div></div><div></div></div><div class="rep-summary skeleton-summary"><div></div><div></div><div></div><div></div></div><div class="report-table-wrap skeleton-table"><div></div><div></div><div></div><div></div><div></div></div></div></div>';
                }
                break;
            case 'requests':  require $tplDir . 'requests.php';    break;
            case 'settings':  require $tplDir . 'settings.php';    break;
            case 'denied':
                echo '<div class="empty-state" style="padding:70px 20px"><div style="font-size:48px;margin-bottom:16px">🔐</div><h3>Доступ запрещён</h3><p style="color:var(--color-text-secondary);margin-top:8px">'.htmlspecialchars($GLOBALS['accessDeniedMessage'] ?? 'У вас нет доступа к этому разделу.').'</p></div>';
                break;
            default:          require $tplDir . 'dashboard.php';
        }
    } catch (Throwable $e) {
        app_log('Template error: ' . $e->getMessage());
        echo '<div class="alert alert-error">Ошибка загрузки страницы: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    ?>
  </main>
</div>
<script>
window.APP_CTX_QUERY = <?=json_encode(app_context_query(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
window.APP_DEBUG = <?=APP_DEBUG ? 'true' : 'false'?>;
window.APP_USER_ID = <?=json_encode((int)($userId ?? 0))?>;
window.APP_USER_POSITION = <?=json_encode((string)($userPos ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
window.appUrl = function(url) {
  var q = window.APP_CTX_QUERY || '';
  if (!q || typeof url !== 'string') return url;
  if (url.indexOf('AUTH_ID=') !== -1 || url.indexOf('DOMAIN=') !== -1) return url;
  if (/^https?:\/\//i.test(url) && url.indexOf(location.origin) !== 0) return url;
  return url + (url.indexOf('?') === -1 ? '?' : '&') + q;
};

window.appLooksLikeHtmlChallenge = function(text) {
  text = String(text || '').toLowerCase();
  return text.indexOf('/aes.js') !== -1 ||
         text.indexOf('function tonumbers') !== -1 ||
         text.indexOf('<html') !== -1 ||
         text.indexOf('<body') !== -1 ||
         text.indexOf('<script') !== -1 ||
         text.indexOf('<!doctype') !== -1;
};
window.cleanAppUrlForPage = function(navUrl, forceSyncReport) {
  var source = navUrl || (location.search || '?page=dashboard');
  var qs = source.indexOf('?') >= 0 ? source.split('?').pop() : source;
  var p = new URLSearchParams(qs);
  if (!p.get('page')) p.set('page', new URLSearchParams(location.search).get('page') || 'dashboard');
  ['action','offset_ajax','_ajax','ajax'].forEach(function(k){ p.delete(k); });
  if (forceSyncReport || p.get('page') === 'report') p.set('sync_report','1');
  p.set('_r', String(Date.now()));
  return '?' + p.toString();
};
window.parseAppJsonResponse = function(response, fallbackUrl, options) {
  options = options || {};
  return response.text().then(function(text){
    try { return JSON.parse(text || '{}'); }
    catch(e) {
      if (window.appLooksLikeHtmlChallenge(text)) {
        var target = fallbackUrl || window.cleanAppUrlForPage(location.search || '?page=dashboard', false);
        if (options.silentReload !== false) {
          setTimeout(function(){ window.location.href = window.appUrl(target); }, 80);
          throw new Error('Серверная защита хостинга вернула HTML вместо JSON. Перезагружаю страницу...');
        }
        throw new Error('Серверная защита хостинга вернула HTML вместо JSON. Обновите страницу.');
      }
      throw new Error('Сервер вернул не JSON: ' + String(text || '').replace(/<[^>]*>/g,'').slice(0,160));
    }
  });
};

window.clearOneFilter = function(key) {
  var p = new URLSearchParams(window.location.search);
  p.delete(key);
  p.delete('offset');
  var target = '?' + p.toString();
  if ((p.get('page') || 'dashboard') === 'report' && window.loadReportFragment && document.getElementById('reportAsyncRoot')) {
    window.loadReportFragment(target);
  } else if (window.loadPageFragment && window.APP_ASYNC_NAVIGATION) {
    window.loadPageFragment(target, true);
  } else {
    window.location.href = window.appUrl(target);
  }
};

window.runInjectedScripts = function(scope) {
  if (!scope) return;
  scope.querySelectorAll('script').forEach(function(oldScript){
    var s = document.createElement('script');
    Array.from(oldScript.attributes).forEach(function(a){ s.setAttribute(a.name, a.value); });
    s.text = oldScript.text || oldScript.textContent || '';
    oldScript.parentNode.removeChild(oldScript);
    document.body.appendChild(s);
    if (!s.src) setTimeout(function(){ try { s.remove(); } catch(e){} }, 0);
  });
};
window.__reportFragmentAbort = null;
window.loadReportFragment = function(navUrl) {
  var root = document.getElementById('reportAsyncRoot');
  if (!root) return Promise.resolve();
  if (window.__reportFragmentAbort) { try { window.__reportFragmentAbort.abort(); } catch(e) {} }
  window.__reportFragmentAbort = (window.AbortController ? new AbortController() : null);
  var source = navUrl || (location.search || '?page=report');
  var qs = source.indexOf('?') >= 0 ? source.split('?').pop() : source;
  var p = new URLSearchParams(qs);
  p.set('page', 'report');
  var clean = new URLSearchParams(p);
  clean.delete('action');
  if (navUrl) history.pushState(null, '', '?' + clean.toString());
  p.set('action', 'report_fragment');
  root.classList.add('is-loading');
  if (!root.dataset.loaded) {
    root.innerHTML = '<div class="report-shell-skeleton"><div class="page-header"><h1>📈 Кассовые операции</h1><div class="skeleton-btn"></div></div><div class="filter-bar skeleton-filter"><div></div><div></div><div></div><div></div></div><div class="rep-summary skeleton-summary"><div></div><div></div><div></div><div></div></div><div class="report-table-wrap skeleton-table"><div></div><div></div><div></div><div></div><div></div></div></div>';
  }
  var fetchOpts = {headers:{'Accept':'application/json','X-Requested-With':'fetch','Cache-Control':'no-store'}, cache:'no-store'};
  if (window.__reportFragmentAbort) fetchOpts.signal = window.__reportFragmentAbort.signal;
  return fetch(window.appUrl('?' + p.toString()), fetchOpts)
    .then(function(r){ return window.parseAppJsonResponse(r, window.cleanAppUrlForPage('?' + clean.toString(), true)); })
    .then(function(j){
      if (!j || !j.ok) throw new Error((j && j.error) || 'Не удалось загрузить отчёт');
      root.dataset.loaded = '1';
      root.innerHTML = j.html || '';
      window.runInjectedScripts(root);
      root.classList.remove('is-loading');
      if (window.AppInitPage) window.AppInitPage(root);
      try { if (typeof BX24 !== 'undefined' && BX24.fitWindow) BX24.fitWindow(); } catch(e) {}
    })
    .catch(function(e){
      if (e && e.name === 'AbortError') return;
      root.classList.remove('is-loading');
      if (String(e.message || e).indexOf('Перезагружаю страницу') === -1) root.innerHTML = '<div class="alert alert-error">Ошибка загрузки отчёта: ' + String(e.message || e).replace(/[<>&]/g,'') + '</div>';
    });
};
window.addEventListener('popstate', function(){
  if ((new URLSearchParams(location.search).get('page') || 'dashboard') === 'report') window.loadReportFragment();
});
document.addEventListener('click', function(e){
  var a = e.target.closest && e.target.closest('#reportAsyncRoot a[href]');
  if (!a || !window.loadReportFragment) return;
  var u;
  try { u = new URL(a.getAttribute('href'), location.href); } catch(err) { return; }
  var p = new URLSearchParams(u.search);
  if ((p.get('page') || 'dashboard') !== 'report') return;
  if (p.get('action') === 'export_excel') return;
  e.preventDefault();
  window.loadReportFragment('?' + p.toString());
});
document.addEventListener('submit', function(e){
  var form = e.target.closest && e.target.closest('#reportAsyncRoot form#rf');
  if (!form || !window.loadReportFragment) return;
  e.preventDefault();
  var p = new URLSearchParams(new FormData(form));
  p.delete('offset');
  window.loadReportFragment('?' + p.toString());
});

document.addEventListener('click', function(e){
  var a = e.target.closest && e.target.closest('a[href]');
  if (!a || !window.isPlainPageLink || !window.loadPageFragment) return;
  if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
  if (a.closest('#reportAsyncRoot')) return;
  if (!window.isPlainPageLink(a)) return;
  e.preventDefault();
  var u = new URL(a.getAttribute('href'), location.href);
  window.loadPageFragment('?' + u.searchParams.toString(), true);
});
document.addEventListener('submit', function(e){
  var form = e.target;
  if (!window.APP_ASYNC_NAVIGATION || !form || (form.method || 'get').toLowerCase() !== 'get') return;
  if (form.closest('#reportAsyncRoot')) return;
  if (form.enctype && form.enctype.toLowerCase().indexOf('multipart') !== -1) return;
  var fd = new FormData(form);
  var p = new URLSearchParams(fd);
  var page = p.get('page') || (new URLSearchParams(location.search).get('page') || 'dashboard');
  p.set('page', page);
  e.preventDefault();
  window.loadPageFragment('?' + p.toString(), true);
});
window.addEventListener('popstate', function(){
  var page = new URLSearchParams(location.search).get('page') || 'dashboard';
  if (page !== 'report' && window.loadPageFragment) window.loadPageFragment(location.search || '?page=dashboard', false);
});

(function(){
  var nativeFetch = window.fetch ? window.fetch.bind(window) : null;
  if (nativeFetch) {
    window.fetch = function(input, init) {
      if (typeof input === 'string') {
        input = window.appUrl(input);
      }
      init = init || {};
      if (!('credentials' in init)) init.credentials = 'include';
      return nativeFetch(input, init);
    };
  }
})();

window.addEventListener('error', function(e){
  console.error('APP JS ERROR:', e.message, e.filename, e.lineno);
});
window.addEventListener('unhandledrejection', function(e){
  console.error('APP PROMISE ERROR:', e.reason);
});


window.APP_ASYNC_NAVIGATION = <?=defined('APP_ASYNC_NAVIGATION') && APP_ASYNC_NAVIGATION ? 'true' : 'false'?>;
window.updateSidebarActive = function(page) {
  page = page || (new URLSearchParams(location.search).get('page') || 'dashboard');
  document.querySelectorAll('.sidebar a:not(.logo)').forEach(function(link){
    var p = new URLSearchParams(link.search).get('page');
    if (p === page) link.classList.add('active'); else link.classList.remove('active');
  });
};
window.updateApprovalCounters = function(count) {
  count = parseInt(count || 0, 10);
  if (isNaN(count) || count < 0) count = 0;
  var reqLink = document.querySelector('.sidebar a[href*="page=requests"]');
  if (reqLink) {
    var badge = reqLink.querySelector('.nav-counter');
    if (count > 0) {
      if (!badge) { badge = document.createElement('span'); badge.className = 'nav-counter'; reqLink.appendChild(badge); }
      badge.textContent = String(count);
    } else if (badge) { badge.remove(); }
  }
  document.querySelectorAll('.request-tabs .req-counter').forEach(function(b){
    if (count > 0) b.textContent = '+' + count; else b.remove();
  });
};
window.refreshApprovalCountersAsync = function(){
  var reqLink = document.querySelector('.sidebar a[href*="page=requests"]');
  if (!reqLink) return Promise.resolve();
  var p = new URLSearchParams(window.location.search);
  p.set('action','approval_waiting_count');
  p.set('_r', String(Date.now()));
  return fetch(window.appUrl('?' + p.toString()), {headers:{'Accept':'application/json','X-Requested-With':'fetch'}, cache:'no-store'})
    .then(function(r){ return window.parseAppJsonResponse(r, null, {silentReload:false}); })
    .then(function(j){ if (j && j.ok) window.updateApprovalCounters(j.waiting_count || 0); })
    .catch(function(){ /* счётчик не должен тормозить интерфейс */ });
};
window.renderPageSkeleton = function(page){
  var title = page === 'requests' ? 'Согласование заявок' : page === 'settings' ? 'Настройки' : page === 'payments' ? 'Платежи' : page === 'import' ? 'Импорт' : page === 'report' ? 'Отчёты' : 'Раздел';
  return '<div class="page-shell-skeleton" aria-busy="true"><div class="page-header"><h1>'+title+'</h1></div><div class="skeleton-title"></div><div class="skeleton-cards"><div></div><div></div><div></div><div></div></div><div class="skeleton-list"></div></div>';
};

window.isPlainPageLink = function(anchor) {
  if (!anchor || !anchor.getAttribute) return false;
  if (anchor.target || anchor.hasAttribute('download')) return false;
  var raw = anchor.getAttribute('href') || '';
  if (!raw || raw.charAt(0) === '#') return false;
  var u;
  try { u = new URL(raw, location.href); } catch(e) { return false; }
  if (u.origin !== location.origin || u.pathname !== location.pathname) return false;
  var p = new URLSearchParams(u.search);
  var page = p.get('page') || '';
  if (!page) return false;
  var action = p.get('action') || '';
  if (action && action !== 'page_fragment') return false;
  return true;
};
window.__pageFragmentAbort = null;
window.loadPageFragment = function(navUrl, push) {
  if (!window.APP_ASYNC_NAVIGATION) { window.location.href = window.appUrl(navUrl); return Promise.resolve(); }
  var content = document.getElementById('mainContent');
  if (!content) return Promise.resolve();
  if (window.__pageFragmentAbort) { try { window.__pageFragmentAbort.abort(); } catch(e) {} }
  window.__pageFragmentAbort = (window.AbortController ? new AbortController() : null);
  var source = navUrl || (location.search || '?page=dashboard');
  var qs = source.indexOf('?') >= 0 ? source.split('?').pop() : source;
  var p = new URLSearchParams(qs);
  var page = p.get('page') || 'dashboard';
  var clean = new URLSearchParams(p);
  clean.delete('action');
  p.set('action', 'page_fragment');
  content.classList.add('is-pjax-loading');
  if (page !== 'report') {
    content.innerHTML = window.renderPageSkeleton ? window.renderPageSkeleton(page) : content.innerHTML;
  }
  var fetchOpts = {headers:{'Accept':'application/json','X-Requested-With':'fetch','Cache-Control':'no-store'}, cache:'no-store'};
  if (window.__pageFragmentAbort) fetchOpts.signal = window.__pageFragmentAbort.signal;
  return fetch(window.appUrl('?' + p.toString()), fetchOpts)
    .then(function(r){ return window.parseAppJsonResponse(r, window.cleanAppUrlForPage('?' + clean.toString(), page === 'report')); })
    .then(function(j){
      if (!j || !j.ok) throw new Error((j && j.error) || 'Не удалось загрузить страницу');
      content.innerHTML = j.html || '';
      content.classList.remove('is-pjax-loading');
      if (push !== false) history.pushState(null, '', '?' + clean.toString());
      window.updateSidebarActive(page);
      if (window.runInjectedScripts) window.runInjectedScripts(content);
      if (window.AppInitPage) window.AppInitPage(content);
      if (page === 'report' && document.getElementById('reportAsyncRoot')) window.loadReportFragment();
      if (window.refreshApprovalCountersAsync) window.refreshApprovalCountersAsync();
      try { if (typeof BX24 !== 'undefined' && BX24.fitWindow) setTimeout(function(){BX24.fitWindow();}, 60); } catch(e) {}
    })
    .catch(function(e){
      if (e && e.name === 'AbortError') return;
      content.classList.remove('is-pjax-loading');
      if (String(e.message || e).indexOf('Перезагружаю страницу') === -1) content.insertAdjacentHTML('afterbegin','<div class="alert alert-error">Ошибка загрузки страницы: '+String(e.message||e).replace(/[<>&]/g,'')+'</div>');
    });
};

document.addEventListener('DOMContentLoaded', function () {
  // Все внутренние ссылки/формы автоматически получают контекст Bitrix24.
  document.querySelectorAll('a[href^="?"]').forEach(function(a){ a.href = window.appUrl(a.getAttribute('href')); });
  document.querySelectorAll('form').forEach(function(f){
    var a = f.getAttribute('action');
    if (a && a.charAt(0) === '?') f.setAttribute('action', window.appUrl(a));
  });

  if (document.getElementById('reportAsyncRoot')) window.loadReportFragment();
  if (window.refreshApprovalCountersAsync) window.refreshApprovalCountersAsync();

  if (typeof BX24 !== 'undefined' && BX24.init) {
    BX24.init(function () {
      console.log('Bitrix24 app initialized');
      if (BX24.fitWindow) BX24.fitWindow();

      // Bitrix24 не всегда передаёт user_id серверу в iframe. Для корректных прав доступа
      // один раз получаем текущего пользователя через JS SDK и добавляем user_id в URL.
      try {
        var params = new URLSearchParams(window.location.search);
        if ((!window.APP_USER_ID || window.APP_USER_ID === 0) && !params.get('user_id') && BX24.callMethod) {
          BX24.callMethod('profile', {}, function(res) {
            if (res && !res.error()) {
              var u = res.data() || {};
              if (u.ID) {
                params.set('user_id', u.ID);
                if (u.WORK_POSITION) params.set('user_position', u.WORK_POSITION);
                window.location.replace(window.location.pathname + '?' + params.toString());
              }
            }
          });
        }
      } catch(e) { console.warn('profile check failed', e); }
    });
  } else {
    console.warn('BX24 SDK не загружен. Приложение открылось вне Bitrix24 или SDK заблокирован.');
  }
});
</script>
<script src="assets/app.js?v=<?=filemtime(dirname(__DIR__).'/assets/app.js')?>" defer></script>
</body>
</html>
