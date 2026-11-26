(function () {
  var $ = function (id) { return document.getElementById(id); };
  var form = $('rentalForm'); if (!form) return;
  var money = function (n) { return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var today = new Date(); today.setMinutes(today.getMinutes() - today.getTimezoneOffset());
  var iso = function (d) { return d.toISOString().slice(0, 10); };
  $('startDate').min = iso(today);

  function calc() {
    var vSel = $('vehicle'), vOpt = vSel.options[vSel.selectedIndex], rate = parseFloat(vOpt.dataset.rate || 0);
    var s = $('startDate').value, e = $('endDate').value, days = 0;
    if (s) { var d = new Date(s); d.setDate(d.getDate() + 1); $('endDate').min = iso(d); }
    if (s && e) days = Math.round((new Date(e) - new Date(s)) / 86400000);
    if (days < 0) days = 0;
    var addons = 0;
    document.querySelectorAll('.addon-checkbox').forEach(function (c) {
      c.closest('.addon-option').classList.toggle('selected-addon', c.checked);
      if (c.checked) addons += parseFloat(c.dataset.rate) * (c.dataset.type === 'Per Day' ? days : 1);
    });
    document.querySelectorAll('.payment-option').forEach(function (o) { o.classList.toggle('selected-payment', o.querySelector('input').checked); });
    var base = rate * days, total = base + addons;
    $('summaryVehicle').textContent = vSel.value ? vOpt.dataset.name : '—';
    $('summaryCustomer').textContent = $('customer').value ? $('customer').options[$('customer').selectedIndex].text : '—';
    $('summaryDuration').textContent = days ? days + (days === 1 ? ' day' : ' days') : '—';
    $('summaryRate').textContent = money(rate);
    $('summaryBase').textContent = money(base);
    $('summaryAddons').textContent = money(addons);
    $('summaryTotal').textContent = money(total);
    var paid = parseFloat($('amountPaid').value || 0);
    $('summaryBalance').textContent = money(Math.max(total - paid, 0));
    $('amountPaid').max = total || '';
  }
  form.addEventListener('input', calc);
  form.addEventListener('change', calc);
  form.addEventListener('reset', function () { setTimeout(calc, 0); });
  calc();
})();
