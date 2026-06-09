<?php
// ВАЖНО: не вызываем Bitrix::currentUser здесь, чтобы не подхватить чужой/админский токен.
// $accounts уже отфильтрованы в index.php по правам текущего сотрудника.
$allowedAccs = $accounts;
?>

<div class="page-header">
  <h1>📥 Импорт из Excel</h1>
</div>

<?php if (isset($importResult)): ?>
  <?php if (isset($importResult['deleted'])): ?>
    <div class="alert alert-success">✅ Удалено <?=$importResult['deleted']?> импортированных платежей.</div>
  <?php elseif (isset($importResult['error'])): ?>
    <div class="alert alert-error">❌ <?=htmlspecialchars($importResult['error'])?></div>
  <?php else: ?>
    <div class="alert alert-success">
      ✅ Импортировано: <strong><?=$importResult['imported']?></strong> из <?=$importResult['total']?> строк.
      <?php if ($importResult['skipped']): ?>
        Пропущено: <strong><?=$importResult['skipped']?></strong>.
      <?php endif; ?>
    </div>
    <?php if (!empty($importResult['errors'])): ?>
    <div class="alert alert-warning">
      <strong>Предупреждения:</strong>
      <ul style="margin:6px 0 0 16px">
        <?php foreach ($importResult['errors'] as $e): ?>
        <li><?=htmlspecialchars($e)?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<div class="import-grid">

  <!-- Загрузка файла -->
  <div class="form-card">
    <h3>📂 Загрузить Excel файл (.xlsx)</h3>
    <p class="hint">Поддерживается формат файла кассы — лист <strong>TJS</strong>, данные с 3-й строки.</p>

    <form id="importForm" method="POST" enctype="multipart/form-data" action="?page=import&action=import_excel">

      <!-- Выбор кассы -->
      <div class="fg" style="margin-bottom:12px">
        <label style="font-size:12px;font-weight:600;color:var(--color-text-secondary);margin-bottom:5px;display:block">
          Загрузить в кассу <span style="color:#dc2626">*</span>
        </label>
        <select name="account_id" required style="width:100%;padding:8px 10px;border-radius:6px;border:1px solid var(--color-border-secondary);font-size:13px">
          <option value="">— выберите кассу —</option>
          <?php foreach ($allowedAccs as $a): ?>
          <option value="<?=$a['id']?>"><?=htmlspecialchars($a['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="upload-box" id="uploadBox">
        <input type="file" name="excel" id="excelFile" accept=".xlsx,.xls" required>
        <div class="upload-inner">
          <div style="font-size:40px;margin-bottom:8px">📊</div>
          <div style="font-size:14px;font-weight:500">Перетащите Excel файл сюда</div>
          <div style="font-size:12px;color:var(--color-text-secondary);margin-top:4px">или нажмите для выбора (.xlsx)</div>
          <div id="fileName" style="margin-top:8px;font-weight:600;color:#2563eb;font-size:13px"></div>
        </div>
      </div>

      <div class="import-progress" id="importProgress" style="display:none">
        <div class="ip-head"><span id="ipText">Подготовка...</span><span id="ipPct">0%</span></div>
        <div class="ip-bar"><span id="ipBar" style="width:0%"></span></div>
        <div class="ip-sub" id="ipSub">Загрузка файла ещё не началась</div>
      </div>
      <button type="submit" id="importBtn" class="btn btn-primary" style="width:100%;margin-top:12px;padding:11px;font-size:14px">
        ⬆ Загрузить и импортировать
      </button>
    </form>
  </div>

  <!-- Инструкция -->
  <div class="form-card">
    <h3>📋 Формат файла</h3>
    <p class="hint">Файл должен содержать лист <strong>TJS</strong>. Данные начинаются с <strong>3-й строки</strong>.</p>

    <div style="overflow-x:auto">
    <table class="data-table" style="font-size:11px;min-width:400px">
      <thead>
        <tr>
          <th>Колонка</th>
          <th>Поле</th>
          <th>Пример</th>
        </tr>
      </thead>
      <tbody>
        <tr><td><strong>B</strong></td><td>Дата ✅</td><td>05.02.2026</td></tr>
        <tr><td><strong>C</strong></td><td>Приход (Дебет)</td><td>50000</td></tr>
        <tr><td><strong>D</strong></td><td>Расход (Кредит)</td><td>12000</td></tr>
        <tr><td><strong>E</strong></td><td>Валюта</td><td>TJS</td></tr>
        <tr><td><strong>F</strong></td><td>Курс валюты</td><td>1</td></tr>
        <tr><td><strong>G</strong></td><td>Описание</td><td>КВ 40% по акту...</td></tr>
        <tr><td><strong>H</strong></td><td>Получатель/Плательщик</td><td>Усманов Умед</td></tr>
        <tr><td><strong>I</strong></td><td>Информация (ссылка)</td><td>2762/03/2026</td></tr>
        <tr><td><strong>J</strong></td><td>Код операции</td><td>7304</td></tr>
        <tr><td><strong>L</strong></td><td>Отдел (Канал)</td><td>ОПП</td></tr>
        <tr><td><strong>N</strong></td><td>Регион</td><td>0</td></tr>
        <tr><td><strong>O</strong></td><td>Подотдел</td><td>АХО</td></tr>
      </tbody>
    </table>
    </div>

    <div style="margin-top:14px;padding:10px 12px;background:#FFF8F0;border-radius:8px;border-left:3px solid #f59e0b">
      <div style="font-size:12px;font-weight:600;color:#a16207;margin-bottom:4px">⚠ Важно</div>
      <ul style="font-size:11px;color:#a16207;margin:0;padding-left:14px;line-height:1.8">
        <li>Строка 1 — итоговые формулы (пропускается)</li>
        <li>Строка 2 — заголовки (пропускается)</li>
        <li>Строки без даты пропускаются</li>
        <li>Приход = колонка C, Расход = колонка D</li>
        <li>Название статьи определяется по коду из колонки J</li>
      </ul>
    </div>
  </div>

</div>

<!-- История импортов -->
<?php
try {
    if ($isManager || !Payment::hasImportLogColumn()) {
        $logs = DB::fetchAll(
            "SELECT * FROM import_log WHERE portal=? ORDER BY imported_at DESC LIMIT 10",
            [$portal]
        );
    } else {
        $allowedRaw = array_map('intval', $allowedAccountIds ?? []);
        if (in_array(0, $allowedRaw, true)) {
            $logs = DB::fetchAll(
                "SELECT * FROM import_log WHERE portal=? ORDER BY imported_at DESC LIMIT 10",
                [$portal]
            );
        } else {
            $ids = array_values(array_filter($allowedRaw, fn($v)=>$v>0));
            if (empty($ids)) {
                $logs = [];
            } else {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $logs = DB::fetchAll(
                    "SELECT DISTINCT il.* FROM import_log il
                     INNER JOIN payments p ON p.import_log_id=il.id AND p.portal=il.portal
                     WHERE il.portal=? AND p.account_id IN ($ph)
                     ORDER BY il.imported_at DESC LIMIT 10",
                    array_merge([$portal], $ids)
                );
            }
        }
    }
} catch (Exception $e) { $logs = []; }
if (!empty($logs)):
?>
<div class="section-title" style="margin-top:20px">История импортов</div>
<div class="tbl-wrap">
<table class="data-table">
  <thead><tr><th>Дата импорта</th><th>Файл</th><th>Всего строк</th><th>Импортировано</th><th>Пропущено</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($logs as $l): ?>
  <tr>
    <td><?=date('d.m.Y H:i', strtotime($l['imported_at']))?></td>
    <td><?=htmlspecialchars($l['filename'])?></td>
    <td><?=$l['rows_total']?></td>
    <td class="text-income"><?=$l['rows_imported']?></td>
    <td class="text-expense"><?=$l['rows_skipped']?></td>
    <td style="text-align:right">
      <button type="button" class="btn-delete btn-delete-apple import-delete-btn" onclick="deleteImportFile(<?=$l['id']?>)">Удалить файл</button>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<style>
.import-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:700px){.import-grid{grid-template-columns:1fr}}
.form-card{background:var(--color-background-primary);border-radius:12px;padding:20px 24px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.form-card h3{font-size:15px;font-weight:600;margin-bottom:10px}
.hint{font-size:12px;color:var(--color-text-secondary);margin-bottom:12px;line-height:1.5}
.upload-box{border:2px dashed var(--color-border-secondary);border-radius:10px;padding:28px 20px;text-align:center;cursor:pointer;transition:.15s;position:relative}
.upload-box:hover,.upload-box.drag{border-color:#2563eb;background:#eff6ff}
.upload-box input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.upload-inner{pointer-events:none}
.fg{display:flex;flex-direction:column}.import-progress{margin-top:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px}.ip-head{display:flex;justify-content:space-between;font-size:12px;font-weight:600;color:#334155;margin-bottom:6px}.ip-bar{height:9px;background:#e2e8f0;border-radius:999px;overflow:hidden}.ip-bar span{display:block;height:100%;background:#2563eb;border-radius:999px;transition:width .2s}.ip-sub{margin-top:6px;font-size:11px;color:#64748b}
</style>

<script>
var excelFile = document.getElementById('excelFile');
var fileName  = document.getElementById('fileName');
var uploadBox = document.getElementById('uploadBox');
var importForm = document.getElementById('importForm');
var importBtn = document.getElementById('importBtn');
var importProgress = document.getElementById('importProgress');
var ipText = document.getElementById('ipText');
var ipPct = document.getElementById('ipPct');
var ipBar = document.getElementById('ipBar');
var ipSub = document.getElementById('ipSub');
function setImportProgress(pct, text, sub){
  importProgress.style.display='block';
  pct = Math.max(0, Math.min(100, Math.round(pct||0)));
  ipBar.style.width = pct + '%'; ipPct.textContent = pct + '%';
  if(text) ipText.textContent = text; if(sub) ipSub.textContent = sub;
}

excelFile.addEventListener('change', () => {
  fileName.textContent = excelFile.files[0]?.name ?? '';
});
uploadBox.addEventListener('dragover', e => { e.preventDefault(); uploadBox.classList.add('drag'); });
uploadBox.addEventListener('dragleave', ()  => uploadBox.classList.remove('drag'));

if (importForm) {
  importForm.addEventListener('submit', function(e){
    e.preventDefault();
    if (!excelFile.files || !excelFile.files[0]) { alert('Выберите Excel-файл'); return; }
    var fd = new FormData(importForm);
    var xhr = new XMLHttpRequest();
    var url = window.appUrl(importForm.getAttribute('action') || '?page=import&action=import_excel');
    importBtn.disabled = true;
    importBtn.textContent = '⏳ Импорт выполняется...';
    setImportProgress(1, 'Начинаем загрузку', 'Файл выбран: ' + excelFile.files[0].name);
    xhr.upload.onprogress = function(ev){
      if (ev.lengthComputable) {
        var pct = Math.min(85, Math.round((ev.loaded / ev.total) * 85));
        setImportProgress(pct, 'Загрузка файла', 'Передано ' + Math.round(ev.loaded/1024) + ' КБ из ' + Math.round(ev.total/1024) + ' КБ');
      }
    };
    xhr.onreadystatechange = function(){
      if (xhr.readyState === 2) setImportProgress(90, 'Файл загружен', 'Сервер обрабатывает строки Excel...');
      if (xhr.readyState === 4) {
        importBtn.disabled = false;
        importBtn.textContent = '⬆ Загрузить и импортировать';
        try {
          var d = JSON.parse(xhr.responseText || '{}');
          if (xhr.status >= 200 && xhr.status < 300 && d.ok) {
            setImportProgress(100, 'Импорт завершён', 'Импортировано: ' + (d.imported||0) + ', пропущено: ' + (d.skipped||0));
            setTimeout(function(){ location.reload(); }, 900);
          } else {
            setImportProgress(100, 'Ошибка импорта', d.error || 'Не удалось импортировать файл');
            alert('Ошибка: ' + (d.error || 'не удалось импортировать файл'));
          }
        } catch (err) {
          setImportProgress(100, 'Ошибка ответа сервера', 'Сервер вернул не JSON. Проверьте logs/app.log');
          alert('Ошибка ответа сервера: ' + err.message);
        }
      }
    };
    xhr.open('POST', url, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.send(fd);
  });
}

uploadBox.addEventListener('drop', e => {
  e.preventDefault(); uploadBox.classList.remove('drag');
  if (e.dataTransfer.files[0]) {
    var dt = new DataTransfer();
    dt.items.add(e.dataTransfer.files[0]);
    excelFile.files = dt.files;
    fileName.textContent = e.dataTransfer.files[0].name;
  }
});

async function deleteImportFile(id) {
  if (!confirm('Удалить платежи, загруженные этим файлом?')) return;
  var fd = new FormData();
  fd.set('import_id', id);
  try {
    var r = await fetch('?page=import&action=delete_import_file', {method:'POST', body:fd});
    var d = await r.json();
    if (d.ok) {
      alert('Удалено платежей: ' + (d.deleted || 0));
      location.reload();
    } else {
      alert('Ошибка: ' + (d.error || 'не удалось удалить файл'));
    }
  } catch (e) {
    alert('Ошибка удаления: ' + e.message);
  }
}
</script>
