// Login page only. Adds a loading state and a double-submit guard.
// The form still posts normally to login.php; the server decides success or failure.
(function () {
  var form = document.getElementById('loginform');
  if (!form) return;
  var btn = form.querySelector('button[type="submit"]');
  var wrap = document.querySelector('.authwrap');
  var label = btn.textContent, busy = false, timer = null;

  function reset() {
    busy = false;
    clearTimeout(timer);
    btn.classList.remove('loading');
    btn.removeAttribute('aria-busy');
    btn.textContent = label;
    if (wrap) wrap.classList.remove('leaving');
  }

  form.addEventListener('submit', function (e) {
    if (busy) { e.preventDefault(); return; }     // ignore a second click or Enter
    busy = true;
    btn.classList.add('loading');
    btn.setAttribute('aria-busy', 'true');
    btn.textContent = 'Signing in\u2026';
    if (wrap) wrap.classList.add('leaving');       // brief fade while the browser navigates
    timer = setTimeout(reset, 15000);              // safety net if the server never answers
  });

  // Coming back with the Back button should not leave the button stuck.
  window.addEventListener('pageshow', function (e) { if (e.persisted) reset(); });
})();
