(function(){
function qs(el, sel){ return el.querySelector(sel); }
function qsa(el, sel){ return Array.from(el.querySelectorAll(sel)); }
function addDays(d, n){ var x=new Date(d); x.setDate(x.getDate()+n); return x; }

function mondayOf(date){
  var d = new Date(date);
  var startDow = (typeof moresAjax !== 'undefined' && moresAjax.startOfWeek !== undefined)
                  ? parseInt(moresAjax.startOfWeek, 10) : 1;
  var day = d.getDay();
  var diff = (day - startDow + 7) % 7;
  d.setDate(d.getDate() - diff);
  d.setHours(0,0,0,0);
  return d;
}

function formatRangeLabel(monday){
  var from = new Date(monday);
  var to   = new Date(monday); to.setDate(to.getDate()+6);
  function f(d){ return d.getDate()+'.'+(d.getMonth()+1)+'.'; }
  return 'Týden ' + f(from) + ' – ' + f(to);
}

function fmtDate(d){
  return d.getFullYear() + '-' +
         ('0'+(d.getMonth()+1)).slice(-2) + '-' +
         ('0'+d.getDate()).slice(-2);
}

function czDow(n){ return ['','Po','Út','St','Čt','Pá','So','Ne'][n] || ''; }

function renderGrid(form, grid){
  var wrap = form.querySelector('.mores-grid-wrap');
  var table = wrap.querySelector('.mores-grid');
  if (!table) { table = document.createElement('table'); table.className = 'mores-grid'; wrap.appendChild(table); }
  table.innerHTML = '';
  
  var hideW   = (form.dataset.hideWeekends === '1' || form.dataset.hideWeekends === 'true'
              || (typeof moresAjax !== 'undefined' && !!moresAjax.hideWeekends));
  var today   = new Date(); today.setHours(0,0,0,0);
  var nowHour = (new Date()).getHours();

  var openH  = grid.openHour || 8;
  var closeH = grid.closeHour || 18;

  // head
  var stepMin = grid.step || 60;

  // head - sloupce dle granularity
  var thead = document.createElement('thead');
  var hr = document.createElement('tr');
  var th0 = document.createElement('th'); th0.textContent = 'Den / hod.'; hr.appendChild(th0);
  for (var m = openH * 60; m < closeH * 60; m += stepMin) {
    var th = document.createElement('th');
    var hh = Math.floor(m / 60), mm = m % 60;
    th.textContent = (hh<10?'0':'')+hh+':'+(mm<10?'0':'')+mm;
    hr.appendChild(th);
  }
  thead.appendChild(hr);
  table.appendChild(thead);

  var tbody = document.createElement('tbody');

  (grid.days || []).forEach(function(day){
      var dd = new Date(day.date + 'T00:00:00');
      var dow = dd.getDay();
      if (hideW && (dow === 0 || dow === 6)) return;

      var tr = document.createElement('tr');
      var tdDay = document.createElement('th');
      var dname = ['Ne','Po','Út','St','Čt','Pá','So'][dow];
      tdDay.textContent = dname + ' ' + dd.getDate()+'.'+(dd.getMonth()+1)+'.';

      var isHoliday = !!day.holiday;
      if (isHoliday) { tr.classList.add('holiday-day'); tdDay.title = 'Svátek / výluka'; }
      tr.appendChild(tdDay);

      var isPastDay = (dd < today);
      if (isPastDay) tr.classList.add('past-day');

      var busySet    = new Set((day.busy    || []).map(function(t){ return t; }));
      var partialSet = new Set((day.partial || []).map(function(t){ return t; }));

      var nowHour = (new Date()).getHours();
      var nowMin  = (new Date()).getMinutes();

      for (var m = openH * 60; m < closeH * 60; m += stepMin) {
        var hh = Math.floor(m / 60), mm = m % 60;
        var key = (hh<10?'0':'')+hh+':'+(mm<10?'0':'')+mm;
        var isBusy    = busySet.has(key);
        var isPartial = partialSet.has(key);
        var isPastCell = isPastDay || (dd.getTime() === today.getTime() && (hh < nowHour || (hh === nowHour && mm <= nowMin)));

        var td = document.createElement('td');
        td.dataset.time = key;

        if (isHoliday) {
          td.className = 'mores-cell holiday';
          td.title = 'Svátek / výluka';
        } else if (isBusy) {
          td.className = 'mores-cell busy';
          td.title = 'Obsazeno';
        } else if (isPastCell) {
          td.className = 'mores-cell past';
          td.title = 'Minulý čas';
        } else if (isPartial) {
          td.className = 'mores-cell partial';
          td.title = 'Částečně obsazeno (příprava)';
        } else {
          td.className = 'mores-cell free';
          td.title = 'Volné';
          (function(dateStr, timeStr, cell){
            cell.addEventListener('click', function(){
              qsa(form, '.mores-grid .mores-cell.selected').forEach(function(x){ x.classList.remove('selected'); });
              cell.classList.add('selected');
              form.querySelector('input[name="date"]').value = dateStr;
              form.querySelector('input[name="time"]').value = timeStr;
              var opt = form.querySelector('select[name="service_id"] option:checked');
              var sName = opt ? opt.textContent : '';
              var cash = opt ? opt.getAttribute('data-price-cash') : '';
              var now  = opt ? opt.getAttribute('data-price-now')  : '';
              var picked = qs(form, '.mores-picked');
              var prices = qs(form, '.mores-prices');
              if (picked) picked.textContent = sName + ' — ' + dateStr + ' ' + timeStr;
              if (prices) prices.textContent = 'Hotově: ' + (cash? (cash+' Kč'):'—') + ' | Platím teď: ' + (now? (now+' Kč'):'—');
              var recap = qs(form, '.mores-recap'); if (recap) recap.style.display = '';
            });
          })(day.date, key, td);
        }

        tr.appendChild(td);
      }

      tbody.appendChild(tr);
  });


  table.appendChild(tbody);
}

function ensureForwardOnly(form){
  var lbl = qs(form, '.mores-week-label');
  var prevBtn = qs(form, '.mores-week-prev');
  if (!prevBtn) return;

  // použij label.dataset.weekStart, případně fallback na form.dataset.weekStart
  var base = (lbl && lbl.dataset.weekStart) ? lbl.dataset.weekStart : (form.dataset.weekStart || '');
  if (!base) { prevBtn.disabled = true; return; }

  var ws = new Date(base + 'T00:00:00');
  var monday = mondayOf(new Date());
  prevBtn.disabled = (ws <= monday);
}

function loadWeek(form){
  //var cal = form.querySelector('select[name="calendar_id"]').value;
  var calEl = form.querySelector('select[name="calendar_id"]');
  var cal   = calEl ? calEl.value : (form.dataset.calendar || '1');
  var srv = form.querySelector('select[name="service_id"]').value;
  var week_start = form.dataset.weekStart; // očekáváme pondělí

  var data = new FormData();
  data.append('action', 'mores_get_week');
  data.append('nonce',  (typeof moresAjax!=='undefined' ? moresAjax.nonce : ''));
  data.append('calendar_id', cal);
  data.append('service_id', srv);
  data.append('week_start', week_start);
  
  var wrap = form.querySelector('.mores-grid-wrap');
  if (wrap) wrap.innerHTML = '<div class="mores-grid-loading">Načítám dostupnost\u2026</div>';
  var loader = wrap ? wrap.querySelector('.mores-grid-loading') : null;

  fetch(moresAjax.ajaxurl, { method:'POST', credentials:'same-origin', body:data })
    .then(function(r){ return r.json(); })
    .then(function(json){
      if (!json || !json.success) throw new Error((json && json.data && json.data.message) || 'Chyba serveru');

      // normalizace -> 'busy'
      var g = json.data.grid || {};
      g.days = (g.days || []).map(function(day){
        if (Array.isArray(day.cells) && !Array.isArray(day.busy)) {
          day.busy = day.cells
            .filter(function(c){ return c && (c.state === 'busy' || c.busy === true); })
            .map(function(c){ return c.time || (typeof c.hour==='number' ? ((c.hour<10?'0':'')+c.hour+':00') : ''); })
            .filter(Boolean);
        }
        if (!Array.isArray(day.busy)) day.busy = [];
        return day;
      });

      // sjednotit label + další požadavky na přesné pondělí z backendu
      var lblEl = document.querySelector('.mores-week-label');
      if (g.days && g.days.length){
        var first = new Date(g.days[0].date + 'T00:00:00');  // 1. den týdne z backendu
        var wsStr = fmtDate(first);
        if (lblEl) {
          lblEl.textContent = formatRangeLabel(first);
          lblEl.dataset.weekStart = wsStr;
        }
        form.dataset.weekStart = wsStr;
      }

      // případný zákaz výběru minulosti – podle tvé verze
      if (typeof ensureForwardOnly === 'function') ensureForwardOnly(form);

      renderGrid(form, g);
      if (loader) loader.style.display = 'none';
    })
    .catch(function(err){
      console.error('MORES get_week error', err);
      var wrap = form.querySelector('.mores-grid-wrap');
      if (wrap) wrap.innerHTML = '<div class="mores-error">Nepodařilo se načíst dostupnost.</div>';
      if (loader) loader.style.display = 'none';
    });
}


document.addEventListener('change', function(e){
  if(e.target.matches('.mores-form select[name="service_id"]')){
    var form = e.target.closest('form');
    var today = new Date();
    var monday = mondayOf(today);
    form.dataset.weekStart = fmtDate(monday);
    loadWeek(form);
  }
});

// delegovaný handler – funguje i když se form rendruje později
document.addEventListener('click', function(e){
  if (!e.target.matches('.mores-week-prev, .mores-week-next')) return;

  e.preventDefault();
  var form = e.target.closest('form.mores-form');
  if (!form) return;

  var lbl = form.querySelector('.mores-week-label');
  var base = lbl && lbl.dataset.weekStart ? new Date(lbl.dataset.weekStart+'T00:00:00')
                                          : new Date(form.dataset.weekStart+'T00:00:00');

  var ws = mondayOf(base);                 // držíme se pondělí
  var dir = e.target.classList.contains('mores-week-prev') ? -7 : 7;
  ws.setDate(ws.getDate() + dir);

  // nepustit před aktuální týden
  var nowMon = mondayOf(new Date());
  if (dir < 0 && ws < nowMon) ws = nowMon;

  form.dataset.weekStart = fmtDate(ws);
  loadWeek(form);
});

// init on DOM ready: auto-load first service
document.addEventListener('DOMContentLoaded', function(){
  var form = document.querySelector('form.mores-form');
  if (!form) return;

  // výchozí pondělí
  var ws0 = mondayOf(new Date());
  form.dataset.weekStart = fmtDate(ws0);

  loadWeek(form);
});


document.addEventListener('submit', function(e){
  if(e.target.matches('.mores-form')){
    e.preventDefault();
    var form = e.target;
    var data = new FormData(form);
    data.append('action', 'mores_make_booking');
    data.append('nonce', moresAjax.nonce);
    data.append('calendar_id', form.getAttribute('data-calendar'));
    fetch(moresAjax.ajaxurl, { method: 'POST', credentials:'same-origin', body: data })
      .then(function(r){ return r.json(); })
      .then(function(json){
  var res = form.nextElementSibling;
  if(json.success){
		if (json.data && json.data.redirect) {
		  window.location.assign(json.data.redirect);
		  return;
		}
		res.textContent = json.data && json.data.message ? json.data.message : '';
		form.reset();
		var recap = qs(form, '.mores-recap'); if (recap) recap.style.display = 'none';
		qs(form, '.mores-time-help').textContent='';
		var today = new Date(); var monday = mondayOf(today);
		form.dataset.weekStart = fmtDate(monday);
		loadWeek(form);
    } else {
		res.textContent = (json.data && json.data.message) ? json.data.message : 'Chyba při rezervaci.';
    }
    }).catch(function(err){
        var res = form.nextElementSibling; res.textContent = 'Chyba sítě při odeslání.';
      });
  }
});

/*
// WooCommerce pokladna: live update ceny při změně platební metody
document.addEventListener('change', function(e) {
    if (e.target && e.target.matches('input[name="payment_method"]')) {
        if (typeof jQuery !== 'undefined') {
            jQuery('body').trigger('update_checkout');
        }
    }
});
*/

// WooCommerce pokladna: live update ceny při změně platební metody (s debounce)
(function() {
    var debounceTimer = null;
    function triggerUpdate() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() {
            if (typeof jQuery !== 'undefined') {
                jQuery('body').trigger('update_checkout');
            }
        }, 400);
    }
    document.addEventListener('change', function(e) {
        if (e.target && e.target.matches('input[name="payment_method"]')) {
            triggerUpdate();
        }
    });
    if (typeof jQuery !== 'undefined') {
        jQuery(document.body).on('payment_method_selected', function() {
            triggerUpdate();
        });
    }
})();

})();
