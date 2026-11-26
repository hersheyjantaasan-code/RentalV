(function () {
  var p = document.getElementById('password'), b = document.getElementById('togglePassword');
  if (!p || !b) return;
  b.addEventListener('click', function () {
    var show = p.type === 'password';
    p.type = show ? 'text' : 'password';
    b.textContent = show ? '◎' : '◉';
    b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });
  var c = document.getElementById('contactAdmin');
  if (c) c.addEventListener('click', function (e) { e.preventDefault(); alert('Please contact your Vehicle Rental System administrator to request an account.'); });
})();
