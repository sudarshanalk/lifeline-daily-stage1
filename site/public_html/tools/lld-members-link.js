/* Sends the finished calculator headline to the landing page. It reads only the result already on screen, never the answers.
 * Tool identity is explicit: <html data-lld-tool="…"> (the Healthy Weight Check); the two Stage 1 pages are told apart by
 * their result markup, and a page that is neither sends nothing. Messages (FR2-23, FR2-39 AS-14 and AS-15):
 *   { type: 'lld-calculator-result', calculator, headline, unit, band, version, onward }
 *   { type: 'lld-calculator-cleared', calculator[, onward: false] }   when the result goes away (edit, stop, withdrawal)
 * onward is false when the landing page must not offer the related track or guide (FR2-39). */
(function () {
  var named = document.documentElement.getAttribute('data-lld-tool');
  var slug = named || (document.getElementById('headlineBlock') ? 'protein' : (document.getElementById('headlineZone') ? 'diabetes' : ''));
  if (!slug) return;
  var version = '';
  var last = '';
  var ready = false;

  function origin() {
    var link = document.querySelector('link[rel="canonical"]');
    if (!link) return '';
    try { return new URL(link.href).origin; } catch (e) { return ''; }
  }

  function result() {
    if (slug === 'healthy-weight') {
      var r = window.LLD_RESULT;
      if (!r || window.LLD_STATUS === 'withdrawn') return { none: true, onward: true };
      if (r.stop) return { none: true, onward: false };
      return { headline: String(r.headline), unit: String(r.unit), band: String(r.band), onward: r.onward !== false };
    }
    if (slug === 'protein') {
      var num = document.querySelector('#headlineBlock .num');
      var unit = document.querySelector('#headlineBlock .unit');
      var range = document.querySelector('#headlineBlock .range');
      if (!num || !num.textContent.trim()) return null;
      var unitText = unit ? unit.textContent.trim() : '';
      var rangeText = range ? range.textContent.trim() : '';
      return {
        headline: num.textContent.trim(),
        unit: unitText,
        // Pregnancy and breastfeeding show a single minimum with no range: the unit line is the band then.
        band: rangeText || unitText
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
    if (!found || found.none) {
      // The tool has withdrawn its number (kidney disease, the emergency box, a safety stop or an edit): the landing page
      // must not keep it.
      var onward = !found || found.onward !== false;
      var clearedKey = 'cleared|' + onward;
      if (last !== '' && last !== clearedKey) {
        last = clearedKey;
        post(onward ? { type: 'lld-calculator-cleared', calculator: slug } : { type: 'lld-calculator-cleared', calculator: slug, onward: false });
      } else if (last === '' && !onward) {
        last = clearedKey;
        post({ type: 'lld-calculator-cleared', calculator: slug, onward: false });
      }
      return;
    }
    var onwardOk = found.onward !== false;
    var key = found.headline + '|' + found.unit + '|' + found.band + '|' + version + '|' + onwardOk;
    if (key === last) return;
    last = key;
    post({
      type: 'lld-calculator-result',
      calculator: slug,
      headline: found.headline,
      unit: found.unit,
      band: found.band,
      version: version,
      onward: onwardOk
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

  document.addEventListener('lld-result-change', send);
  new MutationObserver(send).observe(document.body, { childList: true, subtree: true, characterData: true });
})();
