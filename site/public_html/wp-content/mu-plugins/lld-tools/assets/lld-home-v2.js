/*! lld-home-v2.js — behaviour for the v2 homepage (S2-L1): Day/Night colours, the "Your Health" menu, the calculator
 *  tabs (each tab shows the real tool's embed; a tab's frame loads the first time it is opened) and the science accordion.
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
      var f = on && $('iframe[data-src]', p);
      if (f) { f.src = f.getAttribute('data-src'); f.removeAttribute('data-src'); }
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
