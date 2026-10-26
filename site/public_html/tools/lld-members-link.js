/* Sends the finished calculator headline to the landing page. It reads only the result already on screen. */
(function () {
  var protein = document.getElementById('headlineBlock');
  var slug = protein ? 'protein' : 'diabetes';
  var version = '';
  var last = '';
  var ready = false;

  function origin() {
    var link = document.querySelector('link[rel="canonical"]');
    if (!link) return '';
    try { return new URL(link.href).origin; } catch (e) { return ''; }
  }

  function result() {
    if (slug === 'protein') {
      var num = document.querySelector('#headlineBlock .num');
      var unit = document.querySelector('#headlineBlock .unit');
      var range = document.querySelector('#headlineBlock .range');
      if (!num || !num.textContent.trim()) return null;
      return {
        headline: num.textContent.trim(),
        unit: unit ? unit.textContent.trim() : '',
        band: range ? range.textContent.trim() : ''
      };
    }
    var shown = document.getElementById('riskNum');
    var band = document.querySelector('#headlineZone .risk-band');
    if (!shown || !band) return null;
    var headline = shown.textContent.trim();
    if (!headline || headline === '0.0%' || headline === '0%') return null;
    return {
      headline: headline,
      unit: '% 10-year risk',
      band: band.textContent.replace(/\s*risk\s*$/i, '').trim()
    };
  }

  function post(message) {
    try { window.parent.postMessage(message, origin()); } catch (e) {}
  }

  function send() {
    var found = result();
    var parentOrigin = origin();
    if (!ready || !parentOrigin || window.parent === window) return;
    if (!found) {
      // The tool has withdrawn its number (for example kidney disease or the emergency box): the landing page must not keep it.
      if (last !== '') {
        last = '';
        post({ type: 'lld-calculator-cleared', calculator: slug });
      }
      return;
    }
    var key = found.headline + '|' + found.unit + '|' + found.band + '|' + version;
    if (key === last) return;
    last = key;
    post({
      type: 'lld-calculator-result',
      calculator: slug,
      headline: found.headline,
      unit: found.unit,
      band: found.band,
      version: version
    });
  }

  fetch('./status.json', { cache: 'no-store' }).then(function (res) {
    if (!res.ok) throw new Error('status');
    return res.json();
  }).then(function (status) {
    var tool = status && status.tools && status.tools[slug];
    version = tool && tool.approved_version ? String(tool.approved_version).slice(0, 64) : '';
    ready = true;
    send();
  }).catch(function () { ready = true; send(); });

  new MutationObserver(send).observe(document.body, { childList: true, subtree: true, characterData: true });
})();
