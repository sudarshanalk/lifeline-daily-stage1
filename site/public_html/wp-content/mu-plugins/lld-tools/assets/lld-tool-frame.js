/*! lld-tool-frame.js — parent-side half of the Lifeline Daily tool embed (FR1-21, DEC-15, DEC-34).
 *  Listens for {type:'lld-tool-height', height} messages from the tool iframe and sets the iframe height,
 *  accepting messages only from the configured origin. Shipped by the lld-tools plugin; the landing pages load it
 *  next to the iframe (see README). No dependencies, no cookies, no storage, no analytics.
 *
 *  Configuration (one of):
 *    window.lld_tools_settings = { iframe_origin: 'https://lifelinedaily.com' }   // set per environment by WordPress
 *    <script src="…/lld-tool-frame.js" data-iframe-origin="https://lifelinedaily.com">
 *  Iframe: <iframe class="lld-tool-frame" src="<origin>/tools/…html" title="…" loading="eager"></iframe>
 */
(function (global) {
  'use strict';
  var MIN_HEIGHT = 600; // px fallback until the first message arrives (SRS FR1-21)
  var MAX_HEIGHT = 20000;

  function createController(doc, settings) {
    var origin = (settings && settings.iframe_origin) || '';
    function frames() { return Array.prototype.slice.call(doc.querySelectorAll('iframe.lld-tool-frame, iframe#lld-tool-frame')); }
    function applyFallback() {
      frames().forEach(function (f) {
        f.style.width = '100%'; f.style.border = '0'; f.style.display = 'block';
        if (!f.getAttribute('data-lld-sized')) { f.style.height = MIN_HEIGHT + 'px'; f.style.minHeight = ''; }
        if (f.getAttribute('scrolling') !== 'no') f.setAttribute('scrolling', 'no');
      });
    }
    /** Returns true when the message was applied, false when ignored. Pure apart from the DOM write. */
    function handle(event) {
      if (!origin || event.origin !== origin) return false;              // DEC-34: configured origin only
      var d = event.data;
      if (!d || typeof d !== 'object' || d.type !== 'lld-tool-height') return false;
      var h = d.height;
      if (typeof h !== 'number' || !isFinite(h) || h <= 0) return false;   // numbers only, no coercion
      h = Math.min(MAX_HEIGHT, Math.max(MIN_HEIGHT, Math.ceil(h)));
      var applied = false;
      frames().forEach(function (f) {
        if (event.source && f.contentWindow && f.contentWindow !== event.source) return; // only the frame that spoke
        f.style.height = h + 'px'; f.style.minHeight = ''; f.setAttribute('data-lld-sized', String(h)); applied = true;
      });
      return applied;
    }
    return { handle: handle, applyFallback: applyFallback, origin: origin, MIN_HEIGHT: MIN_HEIGHT };
  }

  // Browser wiring (skipped when loaded as a CommonJS/ESM module in tests)
  if (global && global.document && global.addEventListener) {
    var script = global.document.currentScript;
    var settings = global.lld_tools_settings || (script && script.getAttribute('data-iframe-origin') ? { iframe_origin: script.getAttribute('data-iframe-origin') } : null);
    var ctl = createController(global.document, settings);
    global.addEventListener('message', ctl.handle, false);
    if (global.document.readyState === 'loading') global.document.addEventListener('DOMContentLoaded', ctl.applyFallback); else ctl.applyFallback();
    global.lldToolFrame = ctl;
  }
  if (typeof module !== 'undefined' && module.exports) module.exports = { createController: createController, MIN_HEIGHT: MIN_HEIGHT };
})(typeof window !== 'undefined' ? window : null);
