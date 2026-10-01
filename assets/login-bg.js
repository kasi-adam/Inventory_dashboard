// Login page only. Draws a very quiet "connected network" backdrop (nodes and links drifting slowly,
// with an occasional pulse travelling along one link) behind the sign-in panels.
// Purely decorative: aria-hidden, no pointer events, no data access, no libraries.
// It pauses when the tab is hidden, runs at ~30 fps, and draws a single still frame
// (no motion) when the visitor prefers reduced motion.
(function () {
  'use strict';
  var wrap = document.querySelector('.authwrap');
  if (!wrap || !window.requestAnimationFrame || !window.matchMedia) return;

  var root = document.documentElement;
  var mqMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  var mqDark = window.matchMedia('(prefers-color-scheme: dark)');
  var FRAME_MS = 1000 / 30;
  var nets = [], rafId = 0, last = 0;

  // Colours come from the existing theme tokens, so light and dark mode follow the app automatically.
  function token(name, fallback) {
    var v = (getComputedStyle(root).getPropertyValue(name) || '').trim();
    var m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(v);
    if (!m) return fallback;
    var h = m[1];
    if (h.length === 3) h = h.replace(/./g, '$&$&');
    return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
  }
  function rgba(c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }

  function makeNet(host, cfg) {
    if (!host) return;
    var canvas = document.createElement('canvas');
    canvas.className = 'authnet';
    canvas.setAttribute('aria-hidden', 'true');
    var ctx = canvas.getContext && canvas.getContext('2d');
    if (!ctx) return;
    host.insertBefore(canvas, host.firstChild);
    var net = { host: host, canvas: canvas, ctx: ctx, cfg: cfg, w: 0, h: 0, link: 140,
                nodes: [], pulse: null, nextPulse: 0, active: false, lineC: [255, 255, 255], dotC: [242, 176, 30] };
    nets.push(net);
    return net;
  }

  function colours(net) {
    net.lineC = token(net.cfg.lineToken, net.lineC);
    net.dotC = token(net.cfg.dotToken, net.dotC);
  }

  function seed(net) {
    var count = Math.max(8, Math.min(net.cfg.max, Math.round(net.w * net.h / net.cfg.area)));
    net.nodes = [];
    for (var i = 0; i < count; i++) {
      var ang = Math.random() * 6.2832, sp = 4 + Math.random() * 6;   // 4-10 px per second: slow drift
      net.nodes.push({ x: Math.random() * net.w, y: Math.random() * net.h, vx: Math.cos(ang) * sp, vy: Math.sin(ang) * sp });
    }
    net.pulse = null;
  }

  function size(net) {
    var w = net.canvas.clientWidth, h = net.canvas.clientHeight;
    net.active = w > 0 && h > 0;                       // the canvas is display:none on narrow screens
    if (!net.active) return;
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    net.w = w; net.h = h;
    net.canvas.width = Math.round(w * dpr);
    net.canvas.height = Math.round(h * dpr);
    net.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    net.link = Math.max(110, Math.min(170, Math.min(w, h) * 0.3));
    seed(net);
  }

  function step(net, dt) {
    var n = net.nodes;
    for (var i = 0; i < n.length; i++) {
      var p = n[i];
      p.x += p.vx * dt; p.y += p.vy * dt;
      if (p.x < 0) { p.x = 0; p.vx = -p.vx; } else if (p.x > net.w) { p.x = net.w; p.vx = -p.vx; }
      if (p.y < 0) { p.y = 0; p.vy = -p.vy; } else if (p.y > net.h) { p.y = net.h; p.vy = -p.vy; }
    }
  }

  function pickPulse(net, now) {
    var n = net.nodes, L2 = net.link * net.link;
    var a = Math.floor(Math.random() * n.length), best = -1, bd = L2;
    for (var j = 0; j < n.length; j++) {
      if (j === a) continue;
      var dx = n[a].x - n[j].x, dy = n[a].y - n[j].y, d2 = dx * dx + dy * dy;
      if (d2 < bd) { bd = d2; best = j; }
    }
    net.pulse = best < 0 ? null : { a: a, b: best, t0: now };
    net.nextPulse = now + 3000 + Math.random() * 3500;
  }

  function draw(net, now, animated) {
    var ctx = net.ctx, n = net.nodes, c = net.cfg, L = net.link, L2 = L * L, i, j;
    ctx.clearRect(0, 0, net.w, net.h);
    ctx.lineWidth = 1;
    for (i = 0; i < n.length; i++) {
      for (j = i + 1; j < n.length; j++) {
        var dx = n[i].x - n[j].x, dy = n[i].y - n[j].y, d2 = dx * dx + dy * dy;
        if (d2 >= L2) continue;
        ctx.strokeStyle = rgba(net.lineC, ((1 - Math.sqrt(d2) / L) * c.line).toFixed(3));
        ctx.beginPath(); ctx.moveTo(n[i].x, n[i].y); ctx.lineTo(n[j].x, n[j].y); ctx.stroke();
      }
    }
    ctx.fillStyle = rgba(net.dotC, c.dot);
    for (i = 0; i < n.length; i++) { ctx.beginPath(); ctx.arc(n[i].x, n[i].y, 1.8, 0, 6.2832); ctx.fill(); }

    if (!animated) return;
    if (!net.pulse && now >= net.nextPulse) pickPulse(net, now);
    if (net.pulse) {
      var t = (now - net.pulse.t0) / 1600, a = n[net.pulse.a], b = n[net.pulse.b];
      var ddx = a.x - b.x, ddy = a.y - b.y;
      if (t >= 1 || ddx * ddx + ddy * ddy >= L2) { net.pulse = null; return; }
      ctx.fillStyle = rgba(net.dotC, (Math.sin(Math.PI * t) * c.pulse).toFixed(3));
      ctx.beginPath(); ctx.arc(a.x + (b.x - a.x) * t, a.y + (b.y - a.y) * t, 2.6, 0, 6.2832); ctx.fill();
    }
  }

  function anyActive() { for (var i = 0; i < nets.length; i++) if (nets[i].active) return true; return false; }

  function stillFrame() { nets.forEach(function (net) { if (net.active) draw(net, 0, false); }); }

  function tick(now) {
    rafId = 0;
    if (document.hidden || mqMotion.matches) return;
    if (now - last >= FRAME_MS) {
      var dt = Math.min((now - last) / 1000, 0.1);
      last = now;
      nets.forEach(function (net) { if (net.active) { step(net, dt); draw(net, now, true); } });
    }
    start();
  }

  function start() {
    if (rafId || document.hidden || mqMotion.matches || !anyActive()) return;
    rafId = requestAnimationFrame(tick);
  }

  function stop() { if (rafId) { cancelAnimationFrame(rafId); rafId = 0; } }

  function relayout(net) {
    size(net);
    if (mqMotion.matches && net.active) draw(net, 0, false);
    last = performance.now();
    start();
  }

  var SIDE = { lineToken: '--brand-fg', dotToken: '--brandy', area: 21000, max: 34, line: 0.13, dot: 0.38, pulse: 0.55 };
  var MAIN = { lineToken: '--ink', dotToken: '--primary', area: 24000, max: 30, line: 0.075, dot: 0.28, pulse: 0.35 };
  makeNet(wrap.querySelector('.authside'), SIDE);
  makeNet(wrap.querySelector('.authmain'), MAIN);
  if (!nets.length) return;

  nets.forEach(function (net) {
    colours(net);
    size(net);
    if ('ResizeObserver' in window) {
      var pending = 0;
      new ResizeObserver(function () {
        clearTimeout(pending);
        pending = setTimeout(function () { relayout(net); }, 150);
      }).observe(net.host);
    }
  });
  if (!('ResizeObserver' in window)) {
    var t = 0;
    window.addEventListener('resize', function () {
      clearTimeout(t);
      t = setTimeout(function () { nets.forEach(relayout); }, 150);
    });
  }

  function onChange(mq, fn) { if (mq.addEventListener) mq.addEventListener('change', fn); else if (mq.addListener) mq.addListener(fn); }
  onChange(mqMotion, function () {
    if (mqMotion.matches) { stop(); stillFrame(); } else { last = performance.now(); start(); }
  });
  onChange(mqDark, function () {
    nets.forEach(colours);
    if (mqMotion.matches) stillFrame();
  });
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) stop(); else { last = performance.now(); start(); }
  });

  if (mqMotion.matches) stillFrame(); else { last = performance.now(); start(); }
})();
