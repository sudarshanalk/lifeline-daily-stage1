// TC-UI1-05 Clinically safe result layout — e2e + a11y + visual. Fixtures: FX-PR-REN-01, FX-PR-UNS-01, FX-DM-EMG-01,
// FX-DM-SYM-01, FX-DM-BAND-* (inputs pending ORC-01/OI-4 — FX-DM-QD-71 (low) and FX-DM-QD-73 (veryHigh) stand in for the
// band states, see PR), plus FX-PR-NRM-01 and FX-DM-QD-01 for the normal-result order and heading checks.
import { test, expect } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fillProteinForm, submitProtein } from './helpers/protein-form.mjs';
import { fillDiabetesForm, submitDiabetes } from './helpers/diabetes-form.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const fx = (dir, file, id) => JSON.parse(readFileSync(resolve(ROOT, 'fixtures', dir, file), 'utf8')).fixtures.find((f) => f.id === id);
const PR = { NRM01: fx('protein', 'nrm.json', 'FX-PR-NRM-01'), REN01: fx('protein', 'ren.json', 'FX-PR-REN-01'), UNS01: fx('protein', 'uns.json', 'FX-PR-UNS-01') };
const DM = { QD01: fx('diabetes', 'qd.json', 'FX-DM-QD-01'), EMG01: fx('diabetes', 'emg.json', 'FX-DM-EMG-01'), SYM01: fx('diabetes', 'sym.json', 'FX-DM-SYM-01'), LOW: fx('diabetes', 'qd.json', 'FX-DM-QD-71'), VHIGH: fx('diabetes', 'qd.json', 'FX-DM-QD-73') };
const ORDER = ['banners', 'headline', 'metrics', 'interp', 'advice', 'methodology', 'disclaimer', 'email-card'];
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer(); });
test.afterAll(async () => { await srv?.close(); });
test.use({ reducedMotion: 'reduce' }); // gauge/needle render their final state at once; CSS animations are disabled per screenshot

async function run(page, slug, fixture) {
  await page.goto(`${srv.url}/tools/${slug === 'protein' ? 'protein-requirement-tool.html' : 'diabetes-risk-tool.html'}`);
  if (slug === 'protein') { await fillProteinForm(page, fixture.inputs); await submitProtein(page); } else { await fillDiabetesForm(page, fixture.inputs); await submitDiabetes(page); }
  await expect(page.locator('#results')).toBeVisible();
  if (slug === 'diabetes' && !fixture.inputs.emergency) await expect(page.locator('#riskNum')).not.toHaveText('0.0%');
  // let the smooth scroll settle
  await page.waitForFunction(() => new Promise((r) => { let last = window.scrollY, n = 0; const t = setInterval(() => { if (window.scrollY === last) { if (++n >= 3) { clearInterval(t); r(true); } } else { last = window.scrollY; n = 0; } }, 60); }));
}
/** Section hooks inside #results in DOM order (only the eight canonical names). */
const sections = (page) => page.evaluate((order) => [...document.querySelectorAll('#results [data-test]')].map((el) => el.getAttribute('data-test')).filter((t) => order.includes(t)), ORDER);
const STATES = [['protein', PR.NRM01], ['protein', PR.REN01], ['diabetes', DM.QD01], ['diabetes', DM.EMG01]];

test('TC-UI1-05-AS-01', async ({ page }) => {
  for (const [slug, fixture] of STATES) {
    await run(page, slug, fixture);
    const seq = await sections(page);
    expect(seq, `${slug} ${fixture.id}: every section hook present once`).toHaveLength(ORDER.length);
    expect(seq, `${slug} ${fixture.id}: DOM order`).toEqual(ORDER);
  }
});

test('TC-UI1-05-AS-02', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 667 });
  for (const [slug, fixture, hook] of [['protein', PR.REN01, 'renal-stop'], ['diabetes', DM.EMG01, 'banner-emergency']]) {
    await run(page, slug, fixture);
    const banner = page.locator(`#results [data-test=${hook}]`);
    await expect(banner, `${fixture.id}: banner visible`).toBeVisible();
    await expect(banner).toHaveAttribute('role', 'alert');
    const facts = await banner.evaluate((el) => {
      // first in the result: the first text-bearing element reached from #results is inside the banner
      const results = document.getElementById('results');
      const walker = document.createTreeWalker(results, NodeFilter.SHOW_TEXT, { acceptNode: (n) => n.textContent.trim() && n.parentElement.offsetParent !== null ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP });
      const firstText = walker.nextNode();
      const first = !!firstText && el.contains(firstText);
      // contrast of the banner text against its effective background
      const parse = (c) => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const [r, g, b, a = '1'] = m[1].split(',').map((x) => parseFloat(x)); return { r, g, b, a }; };
      const bg = (node) => { for (let e = node; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c && c.a > 0) return c; } return { r: 255, g: 255, b: 255, a: 1 }; };
      const lum = ({ r, g, b }) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
      const ratio = (a, b) => { const [l1, l2] = [lum(a), lum(b)].sort((x, y) => y - x); return (l1 + 0.05) / (l2 + 0.05); };
      const background = bg(el);
      const texts = [el, ...el.querySelectorAll('h3, p, strong, em, a')].filter((t) => t.textContent.trim());
      const contrast = Math.min(...texts.map((t) => ratio(parse(getComputedStyle(t).color), background)));
      const rect = el.getBoundingClientRect();
      return { first, contrast, top: rect.top, bottom: rect.bottom, innerHeight: window.innerHeight, scrollY: window.scrollY };
    });
    expect(facts.first, `${fixture.id}: banner is the first content in the result`).toBe(true);
    expect(facts.contrast, `${fixture.id}: contrast ≥ 4.5:1`).toBeGreaterThanOrEqual(4.5);
    expect(facts.top, `${fixture.id}: banner top within viewport at 375`).toBeGreaterThanOrEqual(0);
    expect(facts.bottom, `${fixture.id}: banner bottom within viewport at 375 (${facts.innerHeight})`).toBeLessThanOrEqual(facts.innerHeight);
  }
});

test('TC-UI1-05-AS-03', async ({ page }, testInfo) => {
  const SHOTS = [['protein', PR.REN01, 'renal'], ['protein', PR.UNS01, 'unsure'], ['diabetes', DM.EMG01, 'emergency'], ['diabetes', DM.SYM01, 'symptomatic'], ['diabetes', DM.LOW, 'band-low'], ['diabetes', DM.VHIGH, 'band-veryhigh']];
  const missing = SHOTS.flatMap(([, , name]) => [375, 1440].map((w) => `${name}-${w}.png`)).filter((n) => !existsSync(testInfo.snapshotPath(n)));
  // baselines are platform-specific (fonts); a runner without recorded baselines skips with the reason instead of writing a fresh set
  test.skip(missing.length > 0 && ['missing', 'none'].includes(testInfo.config.updateSnapshots) && process.env.LLD_RECORD_BASELINES !== '1', `no ${process.platform} baselines recorded for: ${missing.join(', ')} (record with --update-snapshots on the reference runner)`);
  for (const [slug, fixture, name] of SHOTS) {
    for (const width of [375, 1440]) {
      await page.setViewportSize({ width, height: width === 375 ? 667 : 900 });
      await run(page, slug, fixture);
      await page.addStyleTag({ content: '.progress{visibility:hidden}' }); // the sticky form progress bar floats over the result at any scroll position
      await page.mouse.move(0, 0);   // no hover state in the baseline (the pointer would otherwise rest on whatever the click left under it)
      await expect(page.locator('#results')).toHaveScreenshot(`${name}-${width}.png`, { maxDiffPixelRatio: 0.005, animations: 'disabled', caret: 'hide' });
    }
  }
});

test('TC-UI1-05-AS-04', async ({ page }) => {
  for (const [slug, fixture] of STATES) {
    await run(page, slug, fixture);
    const headings = await page.evaluate(() => [...document.querySelectorAll('#results h1, #results h2, #results h3, #results h4, #results h5, #results h6')].filter((h) => h.offsetParent !== null).map((h) => ({ tag: h.tagName, inHeadline: !!h.closest('[data-test=headline]'), text: h.textContent.trim().slice(0, 40) })));
    const headline = headings.filter((h) => h.inHeadline);
    const headlineShown = await page.locator('#results [data-test=headline] *').count();
    if (headlineShown > 0) { expect(headline.map((h) => h.tag), `${fixture.id}: result headline is h2`).toEqual(['H2']); }
    for (const h of headings.filter((h) => !h.inHeadline)) expect(h.tag, `${fixture.id}: section heading "${h.text}" is h3`).toBe('H3');
  }
});
