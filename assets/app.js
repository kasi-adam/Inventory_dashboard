// Small enhancements; every page works without JavaScript.
(function () {
  var side = document.getElementById('side'), menu = document.getElementById('menu');
  if (menu && side) {
    function setOpen(o) { side.classList.toggle('open', o); menu.setAttribute('aria-expanded', o ? 'true' : 'false'); }
    menu.addEventListener('click', function () { setOpen(!side.classList.contains('open')); });
    document.addEventListener('click', function (e) {
      if (side.classList.contains('open') && !side.contains(e.target) && e.target !== menu) setOpen(false);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
  }

  // Collapse / expand the sidebar (desktop). Remembered for this browser session; expanded by default.
  var root = document.documentElement, colBtn = document.getElementById('collapse'), tip = document.getElementById('tip');
  function syncCollapse() {
    var c = root.classList.contains('side-collapsed');
    if (colBtn) {
      colBtn.setAttribute('aria-expanded', c ? 'false' : 'true');
      colBtn.setAttribute('aria-label', c ? 'Expand sidebar' : 'Collapse sidebar');
      colBtn.dataset.tip = c ? 'Expand sidebar' : 'Collapse sidebar';
    }
    if (tip) tip.hidden = true;
  }
  if (colBtn) {
    syncCollapse();
    colBtn.addEventListener('click', function () {
      var c = root.classList.toggle('side-collapsed');
      try { sessionStorage.setItem('nedco-side', c ? '1' : '0'); } catch (e) {}
      syncCollapse();
    });
  }
  // Tooltips for the icon-only sidebar (uses the existing #tip element).
  if (side && tip) {
    function showTip(el) {
      if (!root.classList.contains('side-collapsed') || !window.matchMedia('(min-width: 901px)').matches) return;
      tip.textContent = el.dataset.tip; tip.hidden = false;
      var r = el.getBoundingClientRect(), t = tip.getBoundingClientRect();
      tip.style.left = Math.round(r.right + 10) + 'px';
      tip.style.top = Math.round(r.top + (r.height - t.height) / 2) + 'px';
    }
    function hideTip() { tip.hidden = true; }
    side.querySelectorAll('[data-tip]').forEach(function (el) {
      el.addEventListener('mouseenter', function () { showTip(el); });
      el.addEventListener('mouseleave', hideTip);
      el.addEventListener('focus', function () { showTip(el); });
      el.addEventListener('blur', hideTip);
    });
    side.addEventListener('scroll', hideTip);
  }

  // Collapsible "Asset register" group; remembers the choice.
  var grp = document.getElementById('grp-assets');
  if (grp) {
    var tog = grp.querySelector('.tog');
    try { var saved = localStorage.getItem('nedco-nav-assets'); if (saved === '1' || saved === '0') { grp.classList.toggle('open', saved === '1'); tog.setAttribute('aria-expanded', saved === '1' ? 'true' : 'false'); } } catch (e) {}
    tog.addEventListener('click', function () {
      var o = !grp.classList.contains('open');
      grp.classList.toggle('open', o); tog.setAttribute('aria-expanded', o ? 'true' : 'false');
      try { localStorage.setItem('nedco-nav-assets', o ? '1' : '0'); } catch (e) {}
    });
  }

  // Theme: light / dark / system (system = follow the device). Saved in this browser.
  var themeBtns = document.querySelectorAll('[data-theme-set]');
  function markTheme() {
    var cur = document.documentElement.dataset.theme || 'system';
    themeBtns.forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.themeSet === cur ? 'true' : 'false'); });
  }
  themeBtns.forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.dataset.themeSet;
      if (t === 'system') delete document.documentElement.dataset.theme; else document.documentElement.dataset.theme = t;
      try { if (t === 'system') localStorage.removeItem('nedco-theme'); else localStorage.setItem('nedco-theme', t); } catch (e) {}
      markTheme();
    });
  });
  markTheme();

  // Show / hide button on every password field.
  document.querySelectorAll('input[type="password"]').forEach(function (inp) {
    var wrap = document.createElement('span'); wrap.className = 'pw';
    inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp);
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'pwt';
    btn.textContent = 'Show'; btn.setAttribute('aria-label', 'Show password'); btn.setAttribute('aria-pressed', 'false');
    btn.addEventListener('click', function () {
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Hide' : 'Show'; btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password'); btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
    wrap.appendChild(btn);
  });

  // Purchase chart: move the highlight, dashed guide and label to the hovered bar.
  document.querySelectorAll('svg.yc').forEach(function (svg) {
    var bars = svg.querySelectorAll('.bar'), guide = svg.querySelector('.guide'), flag = svg.querySelector('.flag');
    var fr = flag.querySelector('rect'), ft = flag.querySelector('text'), def = svg.querySelector('.bar.hi');
    var W = svg.viewBox.baseVal.width;
    function show(b) {
      bars.forEach(function (x) { x.classList.remove('hi'); });
      b.classList.add('hi');
      var y = +b.getAttribute('y'), x = +b.getAttribute('x') + (+b.getAttribute('width')) / 2;
      var lab = b.dataset.l + ' : ' + b.dataset.v, lw = lab.length * 6.6 + 14, lx = Math.min(Math.max(x - lw / 2, 8), W - 40 - lw);
      guide.setAttribute('y1', y); guide.setAttribute('y2', y);
      fr.setAttribute('x', lx); fr.setAttribute('y', y - 26); fr.setAttribute('width', lw);
      ft.setAttribute('x', lx + lw / 2); ft.setAttribute('y', y - 12); ft.textContent = lab;
    }
    bars.forEach(function (b) { b.addEventListener('mouseenter', function () { show(b); }); b.addEventListener('click', function () { show(b); }); });
    svg.addEventListener('mouseleave', function () { if (def) show(def); });
  });

  // Ask before risky user-management actions.
  document.querySelectorAll('button[data-confirm]').forEach(function (b) {
    b.addEventListener('click', function (e) { if (!window.confirm(b.dataset.confirm)) e.preventDefault(); });
  });

  // "I want to delete" checkbox arms the delete button.
  document.querySelectorAll('[data-arm]').forEach(function (c) {
    var btn = document.getElementById(c.dataset.arm);
    c.addEventListener('change', function () { btn.disabled = !c.checked; });
  });
})();
