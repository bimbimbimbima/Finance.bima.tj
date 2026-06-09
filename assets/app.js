// Финансовое приложение — app.js
(function(){
  'use strict';

  window.AppInitPage = function(root) {
    root = root || document;

    // Автоматически скрывать flash-сообщения через 4 секунды
    root.querySelectorAll('.alert').forEach(function(el){
      if (el.dataset.autohideBound === '1') return;
      el.dataset.autohideBound = '1';
      setTimeout(function(){
        el.style.transition = 'opacity .5s';
        el.style.opacity = '0';
        setTimeout(function(){ try { el.remove(); } catch(e){} }, 500);
      }, 4000);
    });

    // Форматирование числовых полей при вводе
    root.querySelectorAll('input[name="amount"]').forEach(function(amountInput){
      if (amountInput.dataset.amountBound === '1') return;
      amountInput.dataset.amountBound = '1';
      amountInput.addEventListener('blur', function(){
        var val = parseFloat(String(amountInput.value || '').replace(/\s+/g,''));
        if (!isNaN(val)) amountInput.value = val.toFixed(2);
      });
    });

    // Подсветка активного пункта меню
    var currentPage = new URLSearchParams(window.location.search).get('page') || 'dashboard';
    if (window.updateSidebarActive) window.updateSidebarActive(currentPage);
    else document.querySelectorAll('.sidebar a:not(.logo)').forEach(function(link){
      var p = new URLSearchParams(link.search).get('page');
      if (p === currentPage) link.classList.add('active'); else link.classList.remove('active');
    });
  };

  document.addEventListener('DOMContentLoaded', function(){
    window.AppInitPage(document);
  });
})();
