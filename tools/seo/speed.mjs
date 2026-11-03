#!/usr/bin/env node
// tools/seo/speed.mjs — P3-82 / NFR3-11 (TC-NFR3-11-AS-01): a mobile-profile lab run of the home page and three
// article pages, judged against LCP < 2.5 s, INP < 200 ms and CLS < 0.1. LAB results, not real-visitor data.
//
// Same approach as tools/lead/lab-vitals.mjs (FR2-27): Playwright chromium emulating a mid-range phone on 4G
// (412x915, DPR 2.625, touch, mobile UA, CPU 4x slower, ~9 Mbps / 60 ms, cold cache per run). Measures
//   LCP  PerformanceObserver largest-contentful-paint
//   CLS  PerformanceObserver layout-shift, largest session window
//   TBT  sum of (long task - 50 ms) between first contentful paint and load + 2 s quiet: the lab proxy for INP
//   INP  Event Timing API on one scripted tap of the mobile menu toggle (when found); worst interaction
// Each page is run N times (default 3) and the median judged. The judgement (lib.mjs judgeVitals) uses INP when a tap
// was measured and TBT otherwise, and says which. "Saving a log entry in under 1 second" (TC-NFR3-11-AS-02) needs a
// signed-in member and belongs to P3-50's tests, not here.
//
// Usage: node tools/seo/speed.mjs <base-url> [--pages home,/slug-1/,/slug-2/,/slug-3/] [--runs 3] [--out report.json]
//        [--md report.md]
// Without --pages the home page and the first three articles of the post sitemap are used.
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { chromium } from 'playwright';
import { parseSitemap, isPostSitemap, judgeVitals, SPEED_BUDGETS, mdTable, joinUrl } from './lib.mjs';
import { getOnce } from './fetch.mjs';

const MOBILE_UA = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';
const NET = { offline: false, latency: 60, downloadThroughput: (9 * 1024 * 1024) / 8, uploadThroughput: (1.5 * 1024 * 1024) / 8 };
const CPU_RATE = 4;
const MENU = ['.tdb-mobile-menu-button', '.td-mobile-menu-button', '#td-top-mobile-toggle', 'button[aria-label*="menu" i]'];

function parseArgs(argv) {
  const a = { base: null, pages: null, runs: 3, out: null, md: null };
  for (let i = 0; i < argv.length; i++) {
    const v = argv[i];
    if (v === '--pages') a.pages = argv[++i].split(',').map((s) => s.trim()).filter(Boolean);
    else if (v === '--runs') a.runs = parseInt(argv[++i], 10) || 3;
    else if (v === '--out') a.out = argv[++i];
    else if (v === '--md') a.md = argv[++i];
    else if (v === '-h' || v === '--help') a.help = true;
    else if (!a.base) a.base = v.replace(/\/+$/, '');
  }
  return a;
}

/** Resolve "home", a path or a full URL against the base; with no list, home + the first three post-sitemap articles. */
export async function resolvePages(base, pages) {
  if (pages && pages.length) return pages.map((p) => (p === 'home' ? base + '/' : /^https?:\/\//i.test(p) ? p : joinUrl(base, p)));
  const index = await getOnce(joinUrl(base, '/sitemap_index.xml'));
  const maps = parseSitemap(index.body).locs.filter(isPostSitemap);
  const articles = [];
  for (const m of maps) {
    if (articles.length >= 3) break;
    const r = await getOnce(m);
    articles.push(...parseSitemap(r.body).locs);
  }
  return [base + '/', ...articles.slice(0, 3)];
}

// Runs in the page before any script (string-safe: no closures over Node values).
const INIT = () => {
  const s = (window.__lv = { lcp: null, lcpEl: null, cls: 0, longTasks: [], events: [] });
  try {
    new PerformanceObserver((l) => { for (const e of l.getEntries()) { s.lcp = e.renderTime || e.loadTime || e.startTime; const el = e.element; s.lcpEl = el ? (el.tagName + (el.id ? '#' + el.id : '') + (typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '')).slice(0, 100) : (e.url || null); } }).observe({ type: 'largest-contentful-paint', buffered: true });
  } catch (_) {}
  try {
    let win = 0, winStart = 0, last = 0;
    new PerformanceObserver((l) => { for (const e of l.getEntries()) { if (e.hadRecentInput) continue; if (win && e.startTime - last < 1000 && e.startTime - winStart < 5000) win += e.value; else { win = e.value; winStart = e.startTime; } last = e.startTime; if (win > s.cls) s.cls = win; } }).observe({ type: 'layout-shift', buffered: true });
  } catch (_) {}
  try {
    new PerformanceObserver((l) => { for (const e of l.getEntries()) s.longTasks.push({ start: e.startTime, duration: e.duration }); }).observe({ type: 'longtask', buffered: true });
  } catch (_) {}
  try {
    new PerformanceObserver((l) => { for (const e of l.getEntries()) s.events.push({ name: e.name, id: e.interactionId || 0, start: e.startTime, duration: e.duration }); }).observe({ type: 'event', durationThreshold: 16, buffered: true });
  } catch (_) {}
};

async function runOnce(browser, url) {
  const ctx = await browser.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2.625, isMobile: true, hasTouch: true, userAgent: MOBILE_UA, locale: 'en-GB', serviceWorkers: 'block' });
  const page = await ctx.newPage();
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
  await cdp.send('Network.emulateNetworkConditions', NET);
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: CPU_RATE });
  await page.addInitScript(INIT);
  const res = { ok: true };
  try {
    const resp = await page.goto(url, { waitUntil: 'load', timeout: 90000 });
    res.status = resp ? resp.status() : null;
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(2000);
    const m = await page.evaluate(() => {
      const n = performance.getEntriesByType('navigation')[0];
      const fcp = performance.getEntriesByName('first-contentful-paint')[0]?.startTime ?? 0;
      const end = (n ? n.loadEventEnd : 0) + 2000;
      let tbt = 0;
      for (const t of window.__lv.longTasks) if (t.start >= fcp && t.start <= end) tbt += Math.max(0, t.duration - 50);
      return { ttfb: n ? n.responseStart : null, fcp: fcp || null, lcp: window.__lv.lcp, lcpEl: window.__lv.lcpEl, tbt, longTasks: window.__lv.longTasks.length };
    });
    res.ttfb_ms = m.ttfb != null ? Math.round(m.ttfb) : null;
    res.fcp_ms = m.fcp != null ? Math.round(m.fcp) : null;
    res.lcp_ms = m.lcp != null ? Math.round(m.lcp) : null;
    res.lcp_element = m.lcpEl;
    res.tbt_ms = Math.round(m.tbt);
    res.long_tasks = m.longTasks;
    // One scripted tap on the mobile menu toggle, measured with the Event Timing API.
    res.inp_ms = null;
    for (const sel of MENU) {
      const el = page.locator(sel).first();
      if (!(await el.isVisible().catch(() => false))) continue;
      const t0 = await page.evaluate(() => performance.now());
      await el.tap({ timeout: 5000 });
      await page.waitForTimeout(1200);
      const evs = await page.evaluate((t) => window.__lv.events.filter((e) => e.start >= t - 5), t0);
      const inter = evs.filter((e) => e.id > 0);
      const worst = (inter.length ? inter : evs).reduce((w, e) => (e.duration > (w?.duration ?? -1) ? e : w), null);
      res.inp_ms = worst ? Math.round(worst.duration) : 16; // Event Timing reports >= 16 ms only
      res.inp_target = sel;
      break;
    }
    res.cls = Math.round((await page.evaluate(() => window.__lv.cls)) * 1000) / 1000;
  } catch (e) { res.ok = false; res.error = String(e.message || e).slice(0, 300); }
  await ctx.close();
  return res;
}

const median = (xs) => { const v = xs.filter((x) => typeof x === 'number').sort((a, b) => a - b); if (!v.length) return null; const m = Math.floor(v.length / 2); return v.length % 2 ? v[m] : (v[m - 1] + v[m]) / 2; };

export async function run(args) {
  const pages = await resolvePages(args.base, args.pages);
  const browser = await chromium.launch();
  const report = {
    kind: 'lab', package: 'P3-82', requirement: 'NFR3-11', label: 'LAB results (simulated mid-range phone on 4G), not real-visitor data', base: args.base,
    generated_at: new Date().toISOString(), tool: 'tools/seo/speed.mjs (Playwright chromium ' + browser.version() + ')',
    profile: { viewport: '412x915', device_scale_factor: 2.625, user_agent: MOBILE_UA, cpu_throttling: CPU_RATE + 'x', network: { down_mbps: 9, up_mbps: 1.5, latency_ms: 60 }, cache: 'cold each run' },
    method: { lcp: 'PerformanceObserver largest-contentful-paint', cls: 'PerformanceObserver layout-shift, largest session window', tbt: 'sum of (long task - 50 ms) from first contentful paint to load + 2 s; lab proxy for INP', inp: 'Event Timing API on one scripted tap of the mobile menu toggle; null when no toggle is found' },
    budgets: SPEED_BUDGETS, runs_per_page: args.runs, out_of_scope: 'TC-NFR3-11-AS-02 (save a log entry under 1 s) needs a signed-in member: P3-50',
    results: [],
  };
  for (const url of pages) {
    const runs = [];
    for (let i = 0; i < args.runs; i++) {
      const r = await runOnce(browser, url);
      runs.push(r);
      console.error(`${url} run ${i + 1}: LCP ${r.lcp_ms} ms, CLS ${r.cls}, TBT ${r.tbt_ms} ms, INP ${r.inp_ms ?? '—'} ms${r.error ? ' ERROR ' + r.error : ''}`);
    }
    const med = { lcp_ms: median(runs.map((r) => r.lcp_ms)), cls: median(runs.map((r) => r.cls)), tbt_ms: median(runs.map((r) => r.tbt_ms)), inp_ms: median(runs.map((r) => r.inp_ms)), ttfb_ms: median(runs.map((r) => r.ttfb_ms)), fcp_ms: median(runs.map((r) => r.fcp_ms)) };
    report.results.push({ url, median: med, judgement: judgeVitals(med), runs });
  }
  await browser.close();
  report.summary = { pages: report.results.length, failing: report.results.filter((r) => !r.judgement.ok).length, all_ok: report.results.every((r) => r.judgement.ok) };
  return { report, markdown: toMarkdown(report) };
}

function toMarkdown(report) {
  const L = [`# NFR3-11 lab speed (TC-NFR3-11-AS-01) — ${report.base}`, '', `${report.label}. Generated ${report.generated_at} by ${report.tool}; ${report.runs_per_page} runs per page, medians shown.`, '',
    `Budgets: LCP < ${report.budgets.lcp_ms} ms, INP < ${report.budgets.inp_ms} ms (TBT < ${report.budgets.tbt_ms} ms stands in when no tap was measured), CLS < ${report.budgets.cls}.`, '',
    mdTable(['Page', 'LCP ms', 'CLS', 'TBT ms', 'INP ms (menu tap)', 'Judged on', 'Pass'], report.results.map((r) => [r.url, r.median.lcp_ms ?? '—', r.median.cls ?? '—', r.median.tbt_ms ?? '—', r.median.inp_ms ?? '—', r.judgement.responsiveness_metric.split(' ')[0], r.judgement.ok ? 'yes' : `no (${Object.entries(r.judgement.pass).filter(([, v]) => !v).map(([k]) => k).join(', ')})`])),
    '', `Overall: ${report.summary.all_ok ? 'all pages within budget' : `${report.summary.failing} of ${report.summary.pages} pages over budget`}.`, '', `Not in this run: ${report.out_of_scope}.`];
  return L.join('\n') + '\n';
}

if (process.argv[1] && /speed\.mjs$/.test(process.argv[1].replace(/\\/g, '/'))) {
  const args = parseArgs(process.argv.slice(2));
  if (args.help || !args.base) { console.error('Usage: node tools/seo/speed.mjs <base-url> [--pages home,/a/,/b/,/c/] [--runs 3] [--out report.json] [--md report.md]'); process.exit(args.help ? 0 : 2); }
  const { report, markdown } = await run(args);
  if (args.out) { mkdirSync(dirname(args.out), { recursive: true }); writeFileSync(args.out, JSON.stringify(report, null, 2) + '\n'); console.error('wrote ' + args.out); }
  const md = args.md || (args.out ? args.out.replace(/\.json$/i, '') + '.md' : null);
  if (md) { writeFileSync(md, markdown); console.error('wrote ' + md); } else process.stdout.write(markdown);
  process.exit(report.summary.all_ok ? 0 : 1);
}
