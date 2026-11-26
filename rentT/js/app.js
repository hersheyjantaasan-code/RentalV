(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  function openModal(m) { m.classList.add('show'); document.body.style.overflow = 'hidden'; var f = $('input:not([type=hidden]):not([type=file]),select', m); if (f) setTimeout(function () { f.focus(); }, 30); }
  function closeModal(m) { m.classList.remove('show'); document.body.style.overflow = ''; }

  // Fill a modal form. data === null -> "add" mode, otherwise "edit" mode (or prefill when isEdit=false)
  function prepare(m, data, isEdit) {
    var form = $('form', m); if (!form) return;
    form.reset();
    $$('input[type=hidden][name=id]', form).forEach(function (i) { i.value = ''; });
    if (data) Object.keys(data).forEach(function (k) {
      var f = form.elements[k]; if (f && f.type !== 'file') f.value = data[k] == null ? '' : data[k];
    });
    var t = $('[data-title]', m); if (t) t.textContent = isEdit ? t.dataset.edit : t.dataset.add;
    $$('[data-req-add]', m).forEach(function (i) { i.required = !isEdit; });
    var img = $('[data-img]', m); if (img) img.src = (data && data.image_url) ? data.image_url : img.dataset.empty;
    document.dispatchEvent(new CustomEvent('modal:prepared', { detail: { modal: m, data: data } }));
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-add]');
    if (t) { var m = document.getElementById(t.dataset.add); prepare(m, t.dataset.json ? JSON.parse(t.dataset.json) : null, false); openModal(m); return; }
    t = e.target.closest('[data-edit]');
    if (t) { var m2 = document.getElementById(t.dataset.edit); prepare(m2, JSON.parse(t.dataset.json), true); openModal(m2); return; }
    t = e.target.closest('[data-view]');
    if (t) { var m3 = document.getElementById(t.dataset.view); document.dispatchEvent(new CustomEvent('modal:view', { detail: { modal: m3, data: JSON.parse(t.dataset.json) } })); openModal(m3); return; }
    if (e.target.closest('[data-close]')) { closeModal(e.target.closest('.modal-overlay')); return; }
    if (e.target.classList.contains('modal-overlay')) closeModal(e.target);
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') $$('.modal-overlay.show').forEach(closeModal); });

  // live filters: search box debounces, selects submit immediately
  $$('form[data-auto]').forEach(function (f) {
    var timer, q = $('input[type=search],input[type=text]', f);
    $$('select,input[type=date]', f).forEach(function (s) { s.addEventListener('change', function () { f.submit(); }); });
    if (q) {
      q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { f.submit(); }, 450); });
      if (q.value && /[?&]q=/.test(location.search)) { q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
    }
  });

  // confirm / prompt + double-submit protection
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.dataset.confirm && !confirm(f.dataset.confirm)) { e.preventDefault(); return; }
    if (f.dataset.prompt) { var r = prompt(f.dataset.prompt, ''); if (r === null) { e.preventDefault(); return; } var h = f.elements.reason; if (h) h.value = r; }
    var b = f.querySelector('button[type=submit]:not([data-keep])');
    if (b && f.method.toLowerCase() === 'post') setTimeout(function () { b.disabled = true; }, 0);
  });

  // image preview
  $$('input[type=file][data-preview]').forEach(function (i) {
    i.addEventListener('change', function () {
      var img = document.getElementById(i.dataset.preview);
      if (i.files[0] && img) img.src = URL.createObjectURL(i.files[0]);
    });
  });

  // auto-hide alerts
  $$('.alert').forEach(function (a) { setTimeout(function () { a.style.transition = 'opacity .4s'; a.style.opacity = 0; setTimeout(function () { a.remove(); }, 450); }, 6000); });
})();
