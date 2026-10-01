// Asset search suggestions. Enhances the existing search boxes (inputs marked data-suggest); every page still
// works without JavaScript, and Enter / the form's own submit always runs the normal search.
//
// Data: one small request to suggest.php the first time a box is used (per location scope), then everything is
// filtered in the browser, so typing never causes a request. Values are shown with textContent only.
(function () {
  'use strict';
  var inputs = document.querySelectorAll('input[data-suggest]');
  if (!inputs.length || !window.fetch) return;

  var MIN_CHARS = 2, MAX_TOTAL = 8, MAX_GROUP = 4;
  // key = field in suggest.php's response; type = name used in data-suggest
  var GROUPS = [
    { k: 'u', type: 'user',     label: 'Users',          icon: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.8-3.5 3.4-5.5 7-5.5s6.2 2 7 5.5"/>' },
    { k: 'm', type: 'model',    label: 'Models',         icon: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>' },
    { k: 'b', type: 'brand',    label: 'Brands',         icon: '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1"/>' },
    { k: 'c', type: 'category', label: 'Categories',     icon: '<path d="M3 7l9-4 9 4-9 4z"/><path d="M3 7v10l9 4 9-4V7"/>' },
    { k: 'l', type: 'location', label: 'Locations',      icon: '<path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/>' },
    { k: 's', type: 'serial',   label: 'Serial numbers', icon: '<path d="M4 5v14M8 5v14M12 5v14M16 5v14M20 5v14"/>' }
  ];
  var SVGNS = 'http://www.w3.org/2000/svg';

  // One shared popup (listbox) and a hidden live region for screen readers.
  var pop = document.createElement('div');
  pop.id = 'sg-list'; pop.className = 'sg'; pop.setAttribute('role', 'listbox'); pop.setAttribute('aria-label', 'Search suggestions'); pop.hidden = true;
  var live = document.createElement('div');
  live.className = 'sg-sr'; live.setAttribute('role', 'status'); live.setAttribute('aria-live', 'polite');
  document.body.appendChild(pop); document.body.appendChild(live);

  var cur = null;        // input currently showing suggestions
  var opts = [];         // option elements, in display order
  var active = -1;       // index of the highlighted option (-1 = none; the typed text is what Enter submits)
  var cache = {};        // location scope -> Promise of the index (kept for this page view only)

  function fold(s) {
    s = String(s).toLowerCase();
    try { s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch (e) {}
    return s.replace(/\s+/g, ' ').trim();
  }
  function scopeOf(inp) {   // same location the page's own search is limited to (forms with a "loc" field)
    var f = inp.form, l = f && f.elements ? f.elements.loc : null;
    return l && l.value ? String(l.value) : '';
  }
  function getIndex(inp) {
    var sc = scopeOf(inp);
    if (!cache[sc]) {
      cache[sc] = fetch('suggest.php' + (sc ? '?loc=' + encodeURIComponent(sc) : ''), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) {
          if (!r.ok || (r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('no suggestions');
          return r.json();
        })
        .then(function (d) { return prepare(d); })
        .catch(function () { delete cache[sc]; return null; });   // fail quietly: the plain search still works
    }
    return cache[sc];
  }
  function prepare(d) {   // add folded text once, so filtering is fast
    var out = {};
    GROUPS.forEach(function (g) {
      out[g.k] = (d[g.k] || []).map(function (r) { return { v: r[0], f: fold(r[0]), x: r[1] }; });
    });
    return out;
  }

  // 0 exact · 1 starts with · 2 a word starts with · 3 contains · 4 every typed word appears · -1 no match
  function tier(t, q, words) {
    if (t === q) return 0;
    if (t.indexOf(q) === 0) return 1;
    if ((' ' + t).replace(/[-_/.]/g, ' ').indexOf(' ' + q) >= 0) return 2;
    if (t.indexOf(q) >= 0) return 3;
    if (words.length > 1 && words.every(function (w) { return t.indexOf(w) >= 0; })) return 4;
    return -1;
  }
  function search(index, q, types) {
    var words = q.split(' '), cand = [];
    GROUPS.forEach(function (g, gi) {
      if (types.indexOf(g.type) < 0) return;
      index[g.k].forEach(function (r) {
        var t = tier(r.f, q, words);
        if (t >= 0) cand.push({ g: g, gi: gi, r: r, t: t });
      });
    });
    cand.sort(function (a, b) {
      return a.t - b.t || a.gi - b.gi || (g2n(b) - g2n(a)) || (a.r.f < b.r.f ? -1 : a.r.f > b.r.f ? 1 : 0);
    });
    var pick = [], per = {};
    for (var i = 0; i < cand.length && pick.length < MAX_TOTAL; i++) {
      var k = cand[i].g.k;
      if ((per[k] || 0) >= MAX_GROUP) continue;
      per[k] = (per[k] || 0) + 1; pick.push(cand[i]);
    }
    return pick;
  }
  function g2n(c) { return typeof c.r.x === 'number' ? c.r.x : 0; }

  function icon(path) {
    var s = document.createElementNS(SVGNS, 'svg');
    s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('aria-hidden', 'true'); s.setAttribute('focusable', 'false');
    s.innerHTML = path;   // constant markup defined above, never data
    return s;
  }
  function label(text, q) {   // text with the typed part in bold, built from nodes (no HTML from data)
    var span = document.createElement('span'); span.className = 'sg-t';
    var low = text.toLowerCase(), i = low.length === text.length ? low.indexOf(q) : -1;
    if (i < 0) { span.textContent = text; return span; }
    span.appendChild(document.createTextNode(text.slice(0, i)));
    var m = document.createElement('mark'); m.textContent = text.slice(i, i + q.length); span.appendChild(m);
    span.appendChild(document.createTextNode(text.slice(i + q.length)));
    return span;
  }

  function render(pick, q) {
    pop.textContent = ''; opts = []; active = -1;
    var n = 0;
    GROUPS.forEach(function (g) {
      var rows = pick.filter(function (c) { return c.g === g; });
      if (!rows.length) return;   // never show an empty group
      var grp = document.createElement('div'); grp.setAttribute('role', 'group'); grp.setAttribute('aria-labelledby', 'sg-h-' + g.k);
      var h = document.createElement('div'); h.className = 'sg-h'; h.id = 'sg-h-' + g.k; h.textContent = g.label; grp.appendChild(h);
      rows.forEach(function (c) {
        var o = document.createElement('div');
        o.className = 'sg-o'; o.id = 'sg-o-' + (n++); o.setAttribute('role', 'option'); o.setAttribute('aria-selected', 'false'); o.dataset.value = c.r.v;
        o.appendChild(icon(g.icon)); o.appendChild(label(c.r.v, q));
        var meta = g.k === 's' ? c.r.x : (c.r.x > 1 ? c.r.x + ' assets' : '');
        if (meta) { var m = document.createElement('span'); m.className = 'sg-m'; m.textContent = meta; o.appendChild(m); }
        grp.appendChild(o); opts.push(o);
      });
      pop.appendChild(grp);
    });
  }

  function place() {
    if (!cur) return;
    var a = cur.closest('.search,.side-search') || cur, r = a.getBoundingClientRect();
    var vw = document.documentElement.clientWidth, vh = window.innerHeight;
    if (r.bottom < 0 || r.top > vh) { close(); return; }
    var w = Math.min(Math.max(r.width, 300), vw - 16), left = Math.max(8, Math.min(r.left, vw - w - 8));
    var below = vh - r.bottom - 12, above = r.top - 12, want = Math.min(pop.scrollHeight, 320);
    pop.style.width = w + 'px'; pop.style.left = left + 'px';
    if (below >= want || below >= above) { pop.style.top = (r.bottom + 4) + 'px'; pop.style.bottom = 'auto'; pop.style.maxHeight = Math.max(120, Math.min(320, below)) + 'px'; }
    else { pop.style.top = 'auto'; pop.style.bottom = (vh - r.top + 4) + 'px'; pop.style.maxHeight = Math.max(120, Math.min(320, above)) + 'px'; }
  }
  function open(inp) {
    cur = inp; pop.hidden = false; place();
    inp.setAttribute('aria-expanded', 'true');
  }
  function close() {
    if (cur) { cur.setAttribute('aria-expanded', 'false'); cur.removeAttribute('aria-activedescendant'); }
    pop.hidden = true; opts = []; active = -1; cur = null;
  }
  function setActive(i) {
    if (active >= 0 && opts[active]) opts[active].setAttribute('aria-selected', 'false');
    active = i;
    if (i >= 0 && opts[i]) {
      opts[i].setAttribute('aria-selected', 'true'); cur.setAttribute('aria-activedescendant', opts[i].id);
      opts[i].scrollIntoView({ block: 'nearest' });
    } else if (cur) cur.removeAttribute('aria-activedescendant');
  }
  function choose(o) {
    var inp = cur, v = o.dataset.value;
    close();
    inp.value = v;                       // 1. put the value in the existing field
    var f = inp.form;                    // 2. run the existing search: the same form, the same request as pressing Enter
    if (f) { if (f.requestSubmit) f.requestSubmit(); else f.submit(); }
  }

  var seq = 0;
  function update(inp) {
    var raw = inp.value, q = fold(raw), my = ++seq;
    if (q.length < MIN_CHARS) { close(); live.textContent = ''; return; }
    var types = (inp.getAttribute('data-suggest') || '').split(',').map(function (s) { return s.trim(); });
    getIndex(inp).then(function (index) {
      if (my !== seq || document.activeElement !== inp || !index) return;   // outdated, or focus moved on
      var pick = search(index, q, types);
      if (!pick.length) { close(); live.textContent = 'No suggestions'; return; }
      render(pick, q); open(inp);
      live.textContent = pick.length + (pick.length === 1 ? ' suggestion' : ' suggestions') + ' available. Use the up and down arrow keys to review.';
    });
  }

  inputs.forEach(function (inp) {
    inp.setAttribute('role', 'combobox'); inp.setAttribute('aria-autocomplete', 'list'); inp.setAttribute('aria-haspopup', 'listbox');
    inp.setAttribute('aria-controls', 'sg-list'); inp.setAttribute('aria-expanded', 'false');
    inp.addEventListener('focus', function () { getIndex(inp); if (inp.value.trim().length >= MIN_CHARS) update(inp); });
    inp.addEventListener('input', function () { update(inp); });
    inp.addEventListener('click', function () { if (pop.hidden && inp.value.trim().length >= MIN_CHARS) update(inp); });
    inp.addEventListener('blur', function () { seq++; if (cur === inp) close(); });
    inp.addEventListener('keydown', function (e) {
      if (e.isComposing) return;
      var isOpen = cur === inp && !pop.hidden;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!isOpen) { if (e.key === 'ArrowDown' && inp.value.trim().length >= MIN_CHARS) { e.preventDefault(); update(inp); } return; }
        e.preventDefault();
        var n = opts.length, i = active;
        if (e.key === 'ArrowDown') i = i < n - 1 ? i + 1 : 0; else i = i > 0 ? i - 1 : (i === 0 ? -1 : n - 1);
        setActive(i);
      } else if (e.key === 'Enter') {
        if (isOpen && active >= 0) { e.preventDefault(); choose(opts[active]); }
        else if (isOpen) close();            // nothing highlighted: normal search of what was typed
      } else if (e.key === 'Escape') {
        if (isOpen) { e.preventDefault(); close(); }   // first Escape closes the list; a second one clears the box (browser default)
      } else if (e.key === 'Tab') { if (isOpen) close(); }
    });
  });

  // Mouse: keep focus in the box while using the list; hover highlights; click chooses.
  pop.addEventListener('mousedown', function (e) { e.preventDefault(); });
  pop.addEventListener('mousemove', function (e) {
    var o = e.target.closest('.sg-o'); if (!o) return;
    var i = opts.indexOf(o); if (i >= 0 && i !== active) setActive(i);
  });
  pop.addEventListener('click', function (e) { var o = e.target.closest('.sg-o'); if (o) choose(o); });

  window.addEventListener('resize', place);
  window.addEventListener('scroll', function (e) { if (cur && !(e.target instanceof Node && pop.contains(e.target))) place(); }, true);
  window.addEventListener('pageshow', close);
})();
