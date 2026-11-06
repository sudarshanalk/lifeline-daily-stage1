/*! lld-home-v2.js — behaviour for the v2 homepage (S2-L1): Day/Night colours, the "Your Health" menu, the calculator
 *  tabs with their quick checks (numbers from the signed-off engines in lld-home-calc.js) and the science accordion.
 *  No dependencies, no cookies, no analytics. The Day/Night choice is remembered in this browser only. */
(function () {
  'use strict';
  var doc = document, body = doc.body;
  var $ = function (s, r) { return (r || doc).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || doc).querySelectorAll(s)); };

  /* Day / Night */
  var DAY_ON = 'px-2.5 py-0.5 rounded-full bg-[#1D4E48] text-white text-[11px] font-medium transition-all shadow-sm';
  var DAY_OFF = 'px-2.5 py-0.5 rounded-full text-[#9E998F] hover:text-white text-[11px] font-medium transition-all';
  var NIGHT_ON = 'px-2.5 py-0.5 rounded-full bg-[#4FA89B] text-black font-semibold text-[11px] transition-all';
  var NIGHT_OFF = 'px-2.5 py-0.5 rounded-full text-[#5A5751] text-day-muted hover:text-black text-[11px] font-medium transition-all';
  function setCircadian(mode, keep) {
    var night = mode === 'night', d = $('#circadian-btn-day'), n = $('#circadian-btn-night');
    body.classList.toggle('mode-night', night);
    if (d && n) {
      d.className = night ? DAY_OFF : DAY_ON; n.className = night ? NIGHT_ON : NIGHT_OFF;
      d.setAttribute('aria-pressed', String(!night)); n.setAttribute('aria-pressed', String(night));
    }
    if (keep) { try { localStorage.setItem('lld_circadian', night ? 'night' : 'day'); } catch (e) { /* storage off */ } }
  }
  $$('[data-circadian]').forEach(function (b) { b.addEventListener('click', function () { setCircadian(b.getAttribute('data-circadian'), true); }); });
  try { if (localStorage.getItem('lld_circadian') === 'night') setCircadian('night', false); } catch (e) { /* storage off */ }

  /* "Your Health" menu (desktop button and phone menu button share the drawer) */
  var menu = $('#mega-menu'), arrow = $('#nav-arrow'), openers = $$('#nav-your-health-btn, #nav-mobile-btn');
  function setMenu(open) {
    if (!menu) return;
    menu.classList.toggle('hidden', !open);
    if (arrow) arrow.classList.toggle('rotate-180', open);
    openers.forEach(function (b) { b.setAttribute('aria-expanded', String(open)); });
  }
  openers.forEach(function (b) { b.addEventListener('click', function (e) { e.stopPropagation(); setMenu(menu.classList.contains('hidden')); }); });
  doc.addEventListener('click', function (e) {
    if (!menu || menu.classList.contains('hidden')) return;
    if (menu.contains(e.target) && !e.target.closest('a')) return;
    setMenu(false);
  });
  doc.addEventListener('keydown', function (e) { if (e.key === 'Escape') setMenu(false); });

  /* Calculator tabs */
  var TAB_ON = ['is-active', 'bg-[#1D4E48]', 'text-white', 'shadow-sm'];
  var TAB_OFF = ['text-[#5A5751]', 'text-day-muted', 'hover:text-black'];
  function setCalcTab(slug) {
    var tab = $('.calc-tab[data-calc-tab="' + slug + '"]');
    if (!tab) return;
    $$('.calc-tab').forEach(function (b) {
      var on = b === tab;
      TAB_ON.forEach(function (c) { b.classList.toggle(c, on); });
      TAB_OFF.forEach(function (c) { b.classList.toggle(c, !on); });
      b.setAttribute('aria-selected', String(on));
      b.tabIndex = on ? 0 : -1;
    });
    $$('[data-calc-panel]').forEach(function (p) {
      var on = p.getAttribute('data-calc-panel') === slug;
      p.hidden = !on;
    });
    $$('[data-calc-img]').forEach(function (i) { i.classList.toggle('hidden', i.getAttribute('data-calc-img') !== slug); });
    var t = $('#calc-image-title'), s = $('#calc-image-sub');
    if (t) t.textContent = tab.getAttribute('data-title') || '';
    if (s) s.textContent = tab.getAttribute('data-sub') || '';
  }
  $$('[data-calc-tab]').forEach(function (b) {
    b.addEventListener('click', function () { setCalcTab(b.getAttribute('data-calc-tab')); });
  });
  var tablist = $('[role="tablist"]');
  if (tablist) tablist.addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
    var tabs = $$('.calc-tab'), i = tabs.indexOf(doc.activeElement);
    if (i < 0) return;
    var next = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
    next.focus(); setCalcTab(next.getAttribute('data-calc-tab'));
  });

  /* Quick checks: the design's sliders, with every number from the signed-off engines (window.LLD_HOME_CALC). */
  var CALC = window.LLD_HOME_CALC;
  var ACT = [null, ['sedentary', 'Mostly sitting'], ['light', 'Lightly active'], ['moderate', 'Moderate'], ['vigorous', 'Very active']];
  var BADGE = {
    good: 'inline-block px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800',
    mid: 'inline-block px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800',
    bad: 'inline-block px-3 py-1 rounded-full text-xs font-semibold bg-rose-100 text-rose-800',
    info: 'inline-block px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-700'
  };
  var RISK = { low: ['Low risk', 'good'], moderate: ['Moderate risk', 'mid'], high: ['High risk', 'bad'], veryHigh: ['Very high risk', 'bad'] };
  var WAIST = { below: ['Below 0.40', 'info'], healthy: ['Healthy (under 0.50)', 'good'], increased: ['Increased (0.50 to 0.59)', 'mid'], high: ['High (0.60 or more)', 'bad'], notShown: ['See full check', 'info'] };

  function panelValues(p) {
    var v = {};
    $$('input[type="range"][data-q]', p).forEach(function (i) { v[i.getAttribute('data-q')] = parseInt(i.value, 10); });
    $$('.q-choice.is-active', p).forEach(function (b) { v[b.getAttribute('data-q')] = b.getAttribute('data-val'); });
    return v;
  }
  function out(p, key, text) { var o = $('[data-q-out="' + key + '"]', p); if (o) o.textContent = text; }
  function show(p, score, badge) {
    $('[data-q-score]', p).textContent = score;
    var b = $('[data-q-badge]', p); b.textContent = badge[0]; b.className = BADGE[badge[1]];
  }
  var RUN = {
    protein: function (p, v) {
      out(p, 'weight', v.weight + ' kg'); out(p, 'height', v.height + ' cm'); out(p, 'activity', ACT[v.activity][1]);
      var r = CALC.computeProtein({ heightCm: v.height, weightKg: v.weight, age: 30, activity: ACT[v.activity][0], resistance: 'no',
        goal: 'maintain', preg: 'no', kidney: 'no', liver: 'no', plan: 'no', meals: 3 });
      show(p, r.targetG + 'g / day', [String(r.gPerKg) + 'g / kg • ' + r.lowG + '–' + r.highG + 'g range', 'good']);
    },
    diabetes: function (p, v) {
      out(p, 'sex', ''); out(p, 'age', v.age + ' years'); out(p, 'bmi', String(v.bmi));
      var pct = CALC.QDR2018A({ sex: v.sex, age: v.age, bmi: v.bmi, ethn: 'WhiteNA', smoke: 'Non', tds: 0 });
      show(p, pct.toFixed(1) + '%', RISK[CALC.bandFor(pct)]);
    },
    'healthy-weight': function (p, v) {
      out(p, 'waist', v.waist + ' cm'); out(p, 'height', v.height + ' cm');
      var r2 = CALC.HW.roundHalfUp(v.waist / v.height, 2);
      show(p, r2.toFixed(2), WAIST[CALC.HW.waistBand(r2, 0)]);
    }
  };
  function recalc(p) { var k = p.getAttribute('data-calc-panel'); if (CALC && RUN[k]) RUN[k](p, panelValues(p)); }
  $$('[data-calc-panel]').forEach(function (p) {
    $$('input[type="range"][data-q]', p).forEach(function (i) { i.addEventListener('input', function () { recalc(p); }); });
    $$('.q-choice', p).forEach(function (b) {
      b.addEventListener('click', function () {
        $$('.q-choice[data-q="' + b.getAttribute('data-q') + '"]', p).forEach(function (o) {
          var on = o === b;
          ['is-active', 'bg-[#1D4E48]', 'text-white', 'border-[#1D4E48]'].forEach(function (c) { o.classList.toggle(c, on); });
          ['border-[#DDD5C7]', 'border-day-soft', 'text-[#5A5751]', 'text-day-muted', 'hover:text-black'].forEach(function (c) { o.classList.toggle(c, !on); });
          o.setAttribute('aria-checked', String(on));
        });
        recalc(p);
      });
    });
    recalc(p);
  });

  /* Science accordion */
  $$('[data-acc]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = b.getAttribute('data-acc'), el = doc.getElementById(id), icon = doc.getElementById('acc-icon-' + id.split('-')[1]);
      var open = el.classList.contains('hidden');
      el.classList.toggle('hidden', !open);
      b.setAttribute('aria-expanded', String(open));
      if (icon) icon.textContent = open ? '−' : '+';
    });
  });
})();
