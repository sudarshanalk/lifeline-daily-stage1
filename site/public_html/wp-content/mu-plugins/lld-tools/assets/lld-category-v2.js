/*! lld-category-v2.js — behaviour for the v2 category page (S2-L1): the Day/Night switch (shared with the homepage, kept
 *  in this browser only) and the phone menu. No dependencies, no cookies, no analytics. */
(function () {
  'use strict';
  var body = document.body, btn = document.getElementById('themeToggle');
  var icon = document.getElementById('themeIcon'), label = document.getElementById('themeLabel');
  function apply(night, keep) {
    body.classList.toggle('mode-night', night);
    body.classList.toggle('mode-day', !night);
    if (icon) icon.textContent = night ? '🌙' : '☀️';
    if (label) label.textContent = night ? 'Night' : 'Day';
    if (btn) btn.setAttribute('aria-pressed', String(night));
    if (keep) { try { localStorage.setItem('lld_circadian', night ? 'night' : 'day'); } catch (e) { /* storage off */ } }
  }
  try { if (localStorage.getItem('lld_circadian') === 'night') apply(true, false); } catch (e) { /* storage off */ }
  if (btn) btn.addEventListener('click', function () { apply(!body.classList.contains('mode-night'), true); });

  var menuBtn = document.getElementById('navMenuBtn'), menu = document.getElementById('navMenu');
  if (menuBtn && menu) {
    menuBtn.addEventListener('click', function () {
      var open = menu.classList.contains('hidden');
      menu.classList.toggle('hidden', !open);
      menuBtn.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !menu.classList.contains('hidden')) { menu.classList.add('hidden'); menuBtn.setAttribute('aria-expanded', 'false'); menuBtn.focus(); }
    });
  }
})();
