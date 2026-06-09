<?php
$currentUser = $GLOBALS['currentUser'] ?? [];
$curUserId   = (int)($GLOBALS['userId'] ?? $_SESSION['user_id'] ?? $_REQUEST['user_id'] ?? 0);
$curUserPos  = (string)($GLOBALS['userPos'] ?? $_SESSION['user_position'] ?? $_REQUEST['user_position'] ?? ($currentUser['WORK_POSITION'] ?? ''));
if ($curUserId && empty($currentUser['ID'])) $currentUser['ID'] = $curUserId;
if ($curUserPos && empty($currentUser['WORK_POSITION'])) $currentUser['WORK_POSITION'] = $curUserPos;
$identityVerified = !empty($GLOBALS['userVerified']);
$isManager   = $identityVerified ? AccessControl::isManager($portal,$curUserId,$curUserPos,$currentUser) : false;
$allowedAccs = $isManager ? $accounts : ($identityVerified ? AccessControl::getAllowedAccounts($portal,$curUserId,$curUserPos,$allAccounts ?? $accounts) : []);
$localAllowedIds = $isManager ? null : array_values(array_map('intval', array_column($allowedAccs, 'id')));
$balances   = Payment::accountBalances($portal, $localAllowedIds);
$balanceMap = [];
foreach ($balances as $b) $balanceMap[$b['id']] = (float)$b['balance'];

$currencies  = DB::fetchAll("SELECT * FROM currencies  WHERE portal=? ORDER BY is_base DESC,code", [$portal]);
$departments = DB::fetchAll("SELECT DISTINCT name FROM departments WHERE portal=? AND parent IS NULL ORDER BY name", [$portal]);
$subDepts    = DB::fetchAll("SELECT name,parent FROM departments WHERE portal=? AND parent IS NOT NULL ORDER BY parent,name", [$portal]);
if (empty($currencies)) $currencies = [['code'=>'TJS','name'=>'Таджикский сомони','rate'=>1,'is_base'=>1]];
?>
<div class="page-header"><h1>➕ Добавить операцию</h1></div>


<div class="op-tabs" style="margin-bottom:16px">
  <button type="button" class="op-tab inc-tab active" onclick="AP.setOp('income',this)">📈 Приход</button>
  <button type="button" class="op-tab exp-tab"        onclick="AP.setOp('expense',this)">📉 Расход</button>
  <button type="button" class="op-tab trn-tab"        onclick="AP.setOp('transfer',this)">🔄 Перевод</button>
</div>

<!-- ФОРМА-ТАБЛИЦА Приход/Расход -->
<div id="ieBlock">
<form id="addForm" onsubmit="return false">
<input type="hidden" id="opType" name="operation_type" value="income">
<input type="hidden" id="fOpName" name="operation_type_name" value="">
<input type="hidden" name="status" value="approved">
<div style="overflow-x:auto;border-radius:10px;border:1px solid #dde3ea" id="tableWrap">
<table style="width:max-content;border-collapse:collapse;font-size:12px;table-layout:fixed" id="addTable">
  <colgroup id="colGroup">
    <col style="width:110px"> <!-- Дата -->
    <col style="width:110px"> <!-- Дебет/Кредит -->
    <col style="width:70px">  <!-- Валюта -->
    <col style="width:85px">  <!-- Курс -->
    <col style="width:95px">  <!-- Сумма TJS -->
    <col style="width:200px"> <!-- Описание -->
    <col style="width:170px"> <!-- Получатель -->
    <col style="width:140px"> <!-- Ссылка -->
    <col style="width:80px">  <!-- Код -->
    <col style="width:190px"> <!-- Статья -->
    <col style="width:90px">  <!-- Отдел -->
    <col style="width:110px"> <!-- Касса -->
    <col style="width:70px">  <!-- Регион -->
    <col style="width:100px"> <!-- Подотдел -->
  </colgroup>
  <thead>
    <tr>
      <th class="h-dark">Дата *</th>
      <th class="h-amt" id="thAmt">Дебет</th>
      <th class="h-dark">Валюта</th>
      <th class="h-dark">Курс</th>
      <th class="h-dark">Сумма TJS</th>
      <th class="h-mid">Описание</th>
      <th class="h-mid">Получатель</th>
      <th class="h-mid">Ссылка</th>
      <th class="h-mid">Код</th>
      <th class="h-mid">Статья / Тип операции</th>
      <th class="h-dark">Отдел</th>
      <th class="h-dark">Банк/Касса *</th>
      <th class="h-dark">Регион</th>
      <th class="h-dark">Подотдел</th>
    </tr>
  </thead>
  <tbody>
  <tr>
    <td class="tc-d"><input type="date" name="date" value="<?=date('Y-m-d')?>" required class="tc-in"></td>
    <td class="tc-amt" id="tdAmt"><input type="number" name="amount" id="fAmt" step="0.01" placeholder="0.00" required class="tc-in ta-r" oninput="AP.calcTJS()"></td>
    <td class="tc-d">
      <select name="currency" id="fCur" class="tc-in" onchange="AP.onCur()">
        <?php foreach ($currencies as $c): ?>
        <option value="<?=$c['code']?>" data-rate="<?=$c['rate']?>" <?=$c['is_base']?'selected':''?>><?=$c['code']?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td class="tc-d"><input type="number" name="exchange_rate" id="fRate" step="0.0001" value="1.0000" class="tc-in ta-r" oninput="AP.calcTJS()"></td>
    <td class="tc-d"><input type="number" name="amount_tjs" id="fTJS" step="0.01" readonly class="tc-in ta-r ro"></td>
    <td class="tc-m"><input type="text" name="description" placeholder="Описание..." class="tc-in"></td>
    <td class="tc-m"><input type="text" name="company" placeholder="Получатель..." class="tc-in"></td>
    <td class="tc-m">
      <input type="text" name="transaction_link" placeholder="Ссылка..." class="tc-in">
      <input type="text" name="comment" placeholder="Комментарий..." class="tc-in mt2">
    </td>
    <td class="tc-m" style="text-align:center"><input type="number" name="operation_code" id="fCode" placeholder="Код" class="tc-in ta-c" oninput="AP.onCode()"></td>
    <td class="tc-m">
      <select name="category_id" id="fCat" class="tc-in" onchange="AP.onCat()">
        <option value="">— не выбрано —</option>
        <?php foreach ($categories as $c): ?>
        <?php
          $catCode = '';
          $catClean = $c['name'];
          if (preg_match('/^\s*(\d{2,6})\s*[-–—]\s*(.+)$/u', (string)$c['name'], $m)) { $catCode = $m[1]; $catClean = $m[2]; }
        ?>
        <option value="<?=$c['id']?>" data-t="<?=$c['type']?>" data-code="<?=htmlspecialchars($catCode)?>" data-clean="<?=htmlspecialchars($catClean)?>"><?=htmlspecialchars($c['name'])?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td class="tc-d">
      <select name="department" id="fDept" class="tc-in" onchange="AP.filterSub()">
        <option value="">—</option>
        <?php foreach ($departments as $d): ?>
        <option value="<?=htmlspecialchars($d['name'])?>"><?=htmlspecialchars($d['name'])?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td class="tc-d">
      <select name="account_id" id="fAcc" class="tc-in" onchange="AP.showBal()">
        <option value="">— выберите —</option>
        <?php foreach ($allowedAccs as $a): ?>
        <option value="<?=$a['id']?>" data-bal="<?=$balanceMap[$a['id']]??0?>">
          <?=htmlspecialchars($a['name'])?>
        </option>
        <?php endforeach; ?>
      </select>
      <div id="balTip" style="font-size:10px;margin-top:2px;font-weight:600;display:none;white-space:nowrap"></div>
    </td>
    <td class="tc-d" style="text-align:center">
      <select name="region" class="tc-in">
        <option value="0">0</option>
        <option value="1">1</option>
        <option value="2">2</option>
      </select>
    </td>
    <td class="tc-d">
      <select name="sub_department" id="fSub" class="tc-in">
        <option value="">—</option>
        <?php foreach ($subDepts as $sd): ?>
        <option value="<?=htmlspecialchars($sd['name'])?>" data-p="<?=htmlspecialchars($sd['parent'])?>">
          <?=htmlspecialchars($sd['name'])?>
        </option>
        <?php endforeach; ?>
      </select>
    </td>
  </tr>
  </tbody>
</table>
</div>

<div class="form-acts" style="margin-top:14px">
  <button type="button" id="saveBtn" class="btn btn-primary btn-lg" onclick="AP.save()">💾 Сохранить</button>
  <a href="<?=app_link(['page'=>'payments'])?>" class="btn btn-light">Отмена</a>
  <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:var(--color-text-secondary)">
    <input type="checkbox" id="addMore"> Добавить ещё
  </label>
</div>
</form>
</div>

<!-- ПЕРЕВОД -->
<div id="trnBlock" style="display:none">
<div class="card-form">
<form id="trnForm" onsubmit="return false">
<input type="hidden" name="operation_type" value="transfer">
<div class="fg-grid">
  <div class="fg"><label>Дата *</label>
    <input type="date" name="date" value="<?=date('Y-m-d')?>" required></div>
  <div class="fg"><label>Сумма *</label>
    <input type="number" name="amount" step="0.01" placeholder="0.00" required></div>
  <div class="fg"><label>Из кассы *</label>
    <select name="account_id" id="fAccFrom">
      <option value="">— выберите —</option>
      <?php foreach ($allowedAccs as $a): ?>
      <option value="<?=$a['id']?>" data-bal="<?=$balanceMap[$a['id']]??0?>">
        <?=htmlspecialchars($a['name'])?> (<?=number_format($balanceMap[$a['id']]??0,0,'.',' ')?> TJS)
      </option>
      <?php endforeach; ?>
    </select></div>
  <div class="fg"><label>В кассу *</label>
    <select name="transfer_to">
      <option value="">— выберите —</option>
      <?php foreach ($allowedAccs as $a): ?>
      <option value="<?=$a['id']?>"><?=htmlspecialchars($a['name'])?></option>
      <?php endforeach; ?>
    </select></div>
  <div class="fg fg-span2"><label>Описание</label>
    <input type="text" name="description" placeholder="Причина перевода..."></div>
</div>
<div class="form-acts">
  <button type="button" class="btn btn-primary btn-lg" onclick="AP.saveTrn()">💾 Сохранить перевод</button>
  <a href="<?=app_link(['page'=>'payments'])?>" class="btn btn-light">Отмена</a>
</div>
</form>
</div>
</div>

<!-- Остатки -->
<?php if (!empty($allowedAccs)): ?>
<div class="section-title" style="margin-top:16px">Текущие остатки</div>
<div class="tbl-wrap"><table class="data-table">
  <thead><tr><th>Касса / Банк</th><th>Остаток (TJS)</th></tr></thead>
  <tbody>
  <?php foreach ($balances as $b):
    if (!$isManager && !in_array($b['id'],array_column($allowedAccs,'id'))) continue;
  ?>
  <tr>
    <td><?=htmlspecialchars($b['name'])?></td>
    <td class="<?=$b['balance']>=0?'text-income':'text-expense'?>">
      <?=number_format($b['balance'],2,'.',' ')?> TJS</td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<style>
.op-tabs{display:flex;border-radius:8px;overflow:hidden;border:1px solid var(--color-border-secondary)}
.op-tab{flex:1;padding:11px 8px;border:none;background:var(--color-background-primary);color:var(--color-text-secondary);font-size:13px;font-weight:600;cursor:pointer;transition:.15s;font-family:inherit}
.op-tab.active.inc-tab{background:#16a34a;color:#fff}
.op-tab.active.exp-tab{background:#dc2626;color:#fff}
.op-tab.active.trn-tab{background:#7c3aed;color:#fff}
.h-dark{background:#1F4E79;color:#fff;padding:8px 6px;font-weight:700;font-size:11px;text-align:center;border:1px solid rgba(255,255,255,.1);white-space:nowrap}
.h-mid {background:#2E75B6;color:#fff;padding:8px 6px;font-weight:700;font-size:11px;text-align:center;border:1px solid rgba(255,255,255,.1);white-space:nowrap}
.h-amt {padding:8px 6px;font-weight:700;font-size:11px;text-align:center;border:1px solid rgba(255,255,255,.1);white-space:nowrap}
.tc-d  {background:#f0f4f8;padding:4px;border:1px solid #dde3ea;vertical-align:top}
.tc-m  {background:#FFF8F0;padding:4px;border:1px solid #dde3ea;vertical-align:top}
.tc-amt{padding:4px;border:1px solid #dde3ea;vertical-align:top}
.tc-in {width:100%;padding:8px 8px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px;font-family:inherit;background:#fff;box-sizing:border-box;min-height:36px}
.tc-in:focus{border-color:#2563eb;outline:none;box-shadow:0 0 0 2px rgba(37,99,235,.15)}
.ta-r{text-align:right}.ta-c{text-align:center}
.ro{background:#f5f7fa!important;color:#64748b!important}
.mt2{margin-top:3px}
.card-form{background:var(--color-background-primary);border-radius:12px;padding:20px 24px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.fg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:14px;align-items:start}
.fg{display:flex;flex-direction:column;gap:4px}
.fg label{font-size:12px;font-weight:600;color:var(--color-text-secondary)}
.fg input,.fg select{padding:8px 10px;border-radius:6px;border:1px solid var(--color-border-secondary);font-size:13px;font-family:inherit;background:var(--color-background-primary);color:var(--color-text-primary);width:100%}
.fg-span2{grid-column:span 2}
.form-acts{display:flex;align-items:center;gap:10px}
</style>

<script>
var AP = {
  BALS:  <?=json_encode($balanceMap)?>,
  RATES: <?=json_encode(array_column($currencies,'rate','code'))?>,
  curOp: 'income',

  setOp: function(op, btn) {
    AP.curOp = op;
    document.querySelectorAll('.op-tab').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');
    document.getElementById('opType').value = op;
    document.getElementById('ieBlock').style.display  = op==='transfer'?'none':'block';
    document.getElementById('trnBlock').style.display = op==='transfer'?'block':'none';
    // Меняем цвет и заголовок колонки суммы
    var th  = document.getElementById('thAmt');
    var td  = document.getElementById('tdAmt');
    if (op==='income') {
      th.style.background='#16a34a'; th.style.color='#fff'; th.textContent='Дебет';
      td.style.background='#E8F5E9';
    } else {
      th.style.background='#dc2626'; th.style.color='#fff'; th.textContent='Кредит';
      td.style.background='#FFEBEE';
    }
    AP.filterCat(op);
  },

  calcTJS: function() {
    var a = parseFloat(document.getElementById('fAmt').value)||0;
    var r = parseFloat(document.getElementById('fRate').value)||1;
    // Для кредитовой корректировки минус должен сохраняться: -213 остаётся в кредите,
    // но увеличивает остаток кассы через отрицательный расход.
    document.getElementById('fTJS').value = (a*r).toFixed(2);
  },

  onCur: function() {
    var v = document.getElementById('fCur').value;
    document.getElementById('fRate').value = AP.RATES[v]||1;
    AP.calcTJS();
  },

  showBal: function() {
    var tip = document.getElementById('balTip');
    var sel = document.getElementById('fAcc');
    if (!sel||!sel.value){if(tip)tip.style.display='none';return;}
    var bal = parseFloat((sel.options[sel.selectedIndex]||{}).dataset.bal||'0');
    tip.style.display='';
    tip.style.color = bal>=0?'#16a34a':'#dc2626';
    tip.textContent = 'Баланс: '+bal.toLocaleString('ru')+' TJS';
  },

  filterCat: function(op) {
    var t = op==='income'?'income':'expense';
    var sel = document.getElementById('fCat');
    if(!sel) return;
    Array.from(sel.options).forEach(function(o){
      if(!o.value) return;
      o.hidden = o.dataset.t && o.dataset.t!==t;
      if(o.hidden&&o.selected) o.selected=false;
    });
    AP.syncOpName();
  },

  syncOpName: function() {
    var sel = document.getElementById('fCat');
    var opName = document.getElementById('fOpName');
    if(!sel || !opName) return;
    var o = sel.options[sel.selectedIndex];
    opName.value = o && o.value ? (o.dataset.clean || o.textContent || '') : '';
  },

  onCat: function() {
    var sel = document.getElementById('fCat');
    var codeInput = document.getElementById('fCode');
    if(!sel || !codeInput) return;
    var o = sel.options[sel.selectedIndex];
    if(o && o.value && o.dataset.code) {
      // При выборе статьи код проставляется автоматически.
      codeInput.value = o.dataset.code;
    }
    AP.syncOpName();
  },

  onCode: function() {
    var codeInput = document.getElementById('fCode');
    var sel = document.getElementById('fCat');
    if(!codeInput || !sel) return;
    var code = (codeInput.value || '').trim();
    if(!code) return; // ручной пустой код не сбрасывает выбранную статью
    var visibleMatch = null;
    Array.from(sel.options).forEach(function(o){
      if(!o.value || o.hidden) return;
      if((o.dataset.code || '') === code) visibleMatch = o;
    });
    if(visibleMatch) {
      sel.value = visibleMatch.value;
      AP.syncOpName();
    }
    // Если кода нет в справочнике, оставляем ручной код как есть и не мешаем выбрать статью вручную.
  },

  filterSub: function() {
    var d=(document.getElementById('fDept')||{}).value||'';
    var s=document.getElementById('fSub');
    if(!s) return;
    Array.from(s.options).forEach(function(o){
      if(!o.value) return;
      o.hidden = d ? o.dataset.p!==d : false;
      if(o.hidden&&o.selected) o.selected=false;
    });
    s.options[0].text = d?'— подотдел —':'—';
  },

  save: async function() {
    var btn=document.getElementById('saveBtn');
    var msgOk=AP.getMsg('msgOk','alert-success');
    var msgErr=AP.getMsg('msgErr','alert-error');
    msgOk.style.display=msgErr.style.display='none';

    AP.syncOpName();
    var fd = new FormData(document.getElementById('addForm'));
    fd.set('operation_type', AP.curOp);

    // Проверка только обязательных полей — БЕЗ валидации баланса
    var amt = parseFloat(document.getElementById('fAmt').value||'0');
    if(!isFinite(amt) || amt===0){AP.err('Введите сумму');return;}
    fd.set('amount', amt);

    btn.disabled=true; btn.textContent='⏳ Сохранение...';
    try {
      var resp = await fetch('?action=add_payment',{method:'POST',body:fd});
      var txt  = await resp.text();
      var d; try{d=JSON.parse(txt);}catch(e){throw new Error(txt.substring(0,300));}
      if(d&&d.ok){
        msgOk.style.display=''; msgOk.textContent='✅ Операция сохранена!';
        if(document.getElementById('addMore').checked){
          document.getElementById('addForm').reset();
          document.getElementById('opType').value=AP.curOp;
          AP.calcTJS(); AP.filterCat(AP.curOp);
          var bt=document.getElementById('balTip');if(bt)bt.style.display='none';
          setTimeout(function(){msgOk.style.display='none';},3000);
        } else {
          setTimeout(function(){ if(window.loadPageFragment) window.loadPageFragment('?page=payments', true); else window.location.href=window.appUrl('?page=payments'); },400);
        }
      } else { AP.err(d?(d.error||'Ошибка сервера'):'Неверный ответ: '+txt.substring(0,100)); }
    } catch(e){ AP.err(e.message); }
    finally{ btn.disabled=false; btn.textContent='💾 Сохранить'; }
  },

  saveTrn: async function() {
    var msgOk=AP.getMsg('msgOk','alert-success'),msgErr=AP.getMsg('msgErr','alert-error');
    msgOk.style.display=msgErr.style.display='none';
    var fd=new FormData(document.getElementById('trnForm'));
    var from=fd.get('account_id')||'',to=fd.get('transfer_to')||'',amt=parseFloat(fd.get('amount')||'0');
    if(!from){AP.err('Выберите кассу-источник');return;}
    if(!to)  {AP.err('Выберите кассу-получатель');return;}
    if(amt<=0){AP.err('Введите сумму');return;}
    if(from===to){AP.err('Нельзя переводить в ту же кассу');return;}
    // БЕЗ проверки баланса — пропускаем даже если минус
    fd.set('amount_tjs',amt);fd.set('currency','TJS');fd.set('exchange_rate','1');fd.set('status','approved');
    try{
      var r=await fetch('?action=add_payment',{method:'POST',body:fd});
      var t=await r.text();var d;try{d=JSON.parse(t);}catch(e){throw new Error(t.substring(0,200));}
      if(d&&d.ok){msgOk.style.display='';msgOk.textContent='✅ Перевод выполнен!';
        setTimeout(function(){ if(window.loadPageFragment) window.loadPageFragment('?page=payments', true); else window.location.href=window.appUrl('?page=payments'); },500);
      } else{AP.err(d?(d.error||'Ошибка'):'Ошибка');}
    }catch(e){AP.err(e.message);}
  },

  getMsg: function(id, cls) {
    var el = document.getElementById(id);
    if (!el) {
      el = document.createElement('div');
      el.id = id;
      el.className = 'alert ' + cls;
      el.style.marginBottom = '10px';
      var form = document.getElementById('addForm') || document.getElementById('trnForm') || document.body;
      form.parentNode.insertBefore(el, form);
    }
    return el;
  },
  err: function(msg){
    var ok = AP.getMsg('msgOk','alert-success');
    ok.style.display='none';
    var el = AP.getMsg('msgErr','alert-error');
    el.style.display=''; el.textContent='❌ '+msg;
    el.scrollIntoView({behavior:'smooth',block:'nearest'});
  }
};
AP.calcTJS();
AP.filterCat('income');

// Создаём контейнеры сообщений если их нет
if (!document.getElementById('msgOk')) {
  var ok = document.createElement('div');
  ok.id='msgOk'; ok.className='alert alert-success';
  ok.style.cssText='display:none;margin-bottom:10px';
  var wrap = document.querySelector('.card-form') || document.querySelector('.op-tabs') || document.body;
  wrap.parentNode.insertBefore(ok, wrap);
}
if (!document.getElementById('msgErr')) {
  var er = document.createElement('div');
  er.id='msgErr'; er.className='alert alert-error';
  er.style.cssText='display:none;margin-bottom:10px';
  var ok2 = document.getElementById('msgOk');
  ok2.parentNode.insertBefore(er, ok2.nextSibling);
}

// ---- Изменение размера столбцов ----
(function() {
  var table = document.getElementById('addTable');
  if (!table) return;
  var ths = table.querySelectorAll('thead tr:first-child th');
  var cols = document.getElementById('colGroup').children;

  ths.forEach(function(th, i) {
    th.style.position = 'relative';
    th.style.userSelect = 'none';
    var handle = document.createElement('div');
    handle.style.cssText = 'position:absolute;right:0;top:0;bottom:0;width:6px;cursor:col-resize;background:rgba(255,255,255,.15);z-index:1';
    handle.title = 'Тяните для изменения ширины';
    th.appendChild(handle);

    var startX, startW;
    handle.addEventListener('mousedown', function(e) {
      e.preventDefault();
      startX = e.pageX;
      startW = cols[i] ? cols[i].offsetWidth : th.offsetWidth;
      document.body.style.cursor = 'col-resize';

      function onMove(e) {
        var w = Math.max(50, startW + (e.pageX - startX));
        if (cols[i]) cols[i].style.width = w + 'px';
      }
      function onUp() {
        document.body.style.cursor = '';
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
      }
      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
    });
  });
})();
</script>
