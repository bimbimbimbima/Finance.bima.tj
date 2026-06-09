(function(){
  'use strict';
  var noticeTimer = null;
  function showNotice(){
    var old = document.querySelector('.source-protection-toast');
    if(old) old.remove();
    var box = document.createElement('div');
    box.className = 'source-protection-toast';
    box.innerHTML = 'Доступ к исходному коду ограничен<small>Это корпоративное приложение. Для технического доступа используйте официальные права администратора.</small>';
    document.body.appendChild(box);
    clearTimeout(noticeTimer);
    noticeTimer = setTimeout(function(){ if(box && box.parentNode) box.remove(); }, 2600);
  }
  function block(e){
    var k = String(e.key || '').toLowerCase();
    var code = e.keyCode || e.which;
    var blocked = false;
    if (code === 123 || k === 'f12') blocked = true;
    if ((e.ctrlKey || e.metaKey) && e.shiftKey && ['i','j','c','k'].indexOf(k) !== -1) blocked = true;
    if ((e.ctrlKey || e.metaKey) && ['u','s'].indexOf(k) !== -1) blocked = true;
    if (blocked) {
      e.preventDefault();
      e.stopPropagation();
      showNotice();
      return false;
    }
  }
  document.addEventListener('keydown', block, true);
  document.addEventListener('contextmenu', function(e){ e.preventDefault(); showNotice(); return false; }, true);
  document.addEventListener('dragstart', function(e){ e.preventDefault(); }, true);
})();
