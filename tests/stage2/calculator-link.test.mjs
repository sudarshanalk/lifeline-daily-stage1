import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';
import { JSDOM } from 'jsdom';
import { frozenHashes, frozenSources } from '../lib/tool-page.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => readFileSync(resolve(root, rel), 'utf8');

test('TC-FR2-23-AS-01', () => {
  for (const slug of ['protein', 'diabetes']) {
    const live = JSON.parse(read(`manifests/${slug}.json`)).versions.find((version) => version.is_live);
    const hashes = frozenHashes(slug);
    for (const [key, hash] of Object.entries(hashes)) {
      expect(live.engine_hashes[key], key).toBe(hash);
    }
    const sources = Object.values(frozenSources(slug)).join('\n');
    expect(sources).not.toContain('lld-members-link');
    const page = slug === 'protein' ? 'protein-requirement-tool.html' : 'diabetes-risk-tool.html';
    const html = read(`site/public_html/tools/${page}`);
    const includeAt = html.indexOf('<script src="lld-members-link.js"></script>');
    expect(includeAt).toBeGreaterThan(html.lastIndexOf('</script>', includeAt));
  }
  const patch = read('tests/mutations/MUT-10.patch');
  const removed = patch.split('\n').find((line) => line.startsWith('-') && line.includes('version_hash'));
  const added = patch.split('\n').find((line) => line.startsWith('+') && line.includes('version_hash'));
  const hash = (line) => line.match(/[0-9a-f]{64}/)[0];
  const before = hash(removed);
  const after = hash(added);
  expect(before.slice(1)).toBe(after.slice(1));
  expect(before[0]).not.toBe(after[0]);
});

// FR2-23-AS-02: only the result on screen may be saved. When the tool withdraws its number (kidney disease, the
// diabetes emergency box), the link script says so and the landing page empties and hides the save form.
const flush = () => new Promise((done) => setTimeout(done, 20));

function toolFrame(slug) {
  const page = slug === 'protein' ? 'protein-requirement-tool.html' : 'diabetes-risk-tool.html';
  const body = slug === 'protein' ? '<div id="headlineBlock"></div>' : '<div id="headlineZone"></div>';
  const dom = new JSDOM(`<!doctype html><html><head><link rel="canonical" href="https://lld.test/tools/${page}"></head><body>${body}</body></html>`, {
    url: `https://lld.test/tools/${page}`,
    runScripts: 'outside-only',
  });
  const sent = [];
  Object.defineProperty(dom.window, 'parent', { configurable: true, value: { postMessage: (message, origin) => sent.push({ message, origin }) } });
  dom.window.fetch = () => Promise.resolve({ ok: true, json: () => Promise.resolve({ tools: { [slug]: { approved_version: 'a'.repeat(64) } } }) });
  dom.window.eval(read('site/public_html/tools/lld-members-link.js'));
  return { dom, sent };
}

test('TC-FR2-23-AS-02', async () => {
  const protein = toolFrame('protein');
  await flush();
  const block = protein.dom.window.document.getElementById('headlineBlock');
  block.innerHTML = '<div class="headline"><h2 class="num">120 g</h2><div class="unit">of protein per day</div><div class="range">Target range 100–140 g per day</div></div>';
  await flush();
  expect(protein.sent.at(-1).message).toMatchObject({ type: 'lld-calculator-result', calculator: 'protein', headline: '120 g' });
  expect(protein.sent.at(-1).origin).toBe('https://lld.test');
  block.innerHTML = '';
  await flush();
  expect(protein.sent.at(-1).message).toEqual({ type: 'lld-calculator-cleared', calculator: 'protein' });
  const count = protein.sent.length;
  block.innerHTML = ' ';
  await flush();
  expect(protein.sent.length).toBe(count);
  // Pregnancy and breastfeeding show one minimum and no range: the unit line is saved as the band.
  block.innerHTML = '<div class="headline"><h2 class="num">95 g</h2><div class="unit">of protein per day (minimum)</div></div>';
  await flush();
  expect(protein.sent.at(-1).message).toMatchObject({ type: 'lld-calculator-result', headline: '95 g', unit: 'of protein per day (minimum)', band: 'of protein per day (minimum)' });

  const diabetes = toolFrame('diabetes');
  await flush();
  const zone = diabetes.dom.window.document.getElementById('headlineZone');
  zone.innerHTML = '<div class="risk-num" id="riskNum">12.3%</div><span class="risk-band">High risk</span>';
  await flush();
  expect(diabetes.sent.at(-1).message).toMatchObject({ type: 'lld-calculator-result', calculator: 'diabetes', headline: '12.3%', band: 'High' });
  zone.innerHTML = '<h2 class="eyebrow">Risk estimate paused</h2>';
  await flush();
  expect(diabetes.sent.at(-1).message).toEqual({ type: 'lld-calculator-cleared', calculator: 'diabetes' });

  const landing = new JSDOM(`<!doctype html><html><body><main><form id="lld-save-result" data-calculator="protein">
    <input type="hidden" name="lld_headline" value="120 g"><input type="hidden" name="lld_unit" value="of protein per day">
    <input type="hidden" name="lld_band" value="Target range 100–140 g per day"><input type="hidden" name="lld_version" value="${'a'.repeat(64)}">
    <button type="submit">Save this result to my profile</button></form></main></body></html>`, { url: 'https://lld.test/health-tools/protein/', runScripts: 'outside-only' });
  landing.window.eval(read('site/public_html/wp-content/plugins/lld-members/assets/lld-members.js'));
  const form = landing.window.document.getElementById('lld-save-result');
  landing.window.dispatchEvent(new landing.window.MessageEvent('message', { origin: 'https://elsewhere.test', data: { type: 'lld-calculator-cleared', calculator: 'protein' } }));
  expect(form.hidden).toBe(false);
  landing.window.dispatchEvent(new landing.window.MessageEvent('message', { origin: 'https://lld.test', data: { type: 'lld-calculator-cleared', calculator: 'protein' } }));
  expect(form.hidden).toBe(true);
  for (const name of ['lld_headline', 'lld_unit', 'lld_band', 'lld_version']) {
    expect(form.querySelector(`[name=${name}]`).value, name).toBe('');
  }
});
