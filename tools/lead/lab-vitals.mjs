#!/usr/bin/env node
// lab-vitals.mjs - FR2-27 / AS-02 lab test (LAB results, not real-visitor data).
//
// Emulates a mid-range phone on 4G with Playwright chromium:
//   viewport 412x915 (DPR 2.625, touch, mobile UA), CPU throttled 4x
//   (CDP Emulation.setCPUThrottlingRate), network ~9 Mbps down / 1.5 Mbps up /
//   60 ms latency (CDP Network.emulateNetworkConditions), cold cache per run.
// Measures LCP and CLS (PerformanceObserver), then performs scripted taps on
// the mobile main-menu toggle and on one in-page, non-navigating button, and
// measures interaction latency with the Event Timing API (type "event",
// durationThreshold 16). INP for a run = worst scripted interaction.
// Each URL is run N times (default 3); the median is reported.
//
// Usage: node tools/lead/lab-vitals.mjs --out <file> [--runs 3]
//        [--menu "<css>"] [--button "<css>"] <url>...

import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const MOBILE_UA =
  'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';
const NET = { offline: false, latency: 60, downloadThroughput: (9 * 1024 * 1024) / 8, uploadThroughput: (1.5 * 1024 * 1024) / 8 };
const CPU_RATE = 4;
const TARGETS = { lcp_ms: 2500, cls: 0.1, inp_ms: 200 };

// Newspaper (tagDiv) mobile menu toggle, then fallbacks.
const DEFAULT_MENU = ['.tdb-mobile-menu-button', '.td-mobile-menu-button', '#td-top-mobile-toggle', 'button[aria-label*="menu" i]'];
const DEFAULT_MENU_CLOSE = ['.td-mobile-close', '.tdb-mobile-menu-close'];
// In-page controls that do not navigate away (first visible wins):
// a tool/form button in content, the cookie banner "Customise" button
// (opens the preferences panel, grants nothing), the header search toggle.
const DEFAULT_BUTTON = [
  '.td-main-content-wrap button[type="button"]',
  '.td-main-content-wrap button:not([type="submit"])',
  '.cky-btn-customize',
  '.tdb-header-search-button-mob',
];

function parseArgs(argv) {
  const a = { out: null, runs: 3, menu: null, button: null, urls: [] };
  for (let i = 0; i < argv.length; i++) {
    const v = argv[i];
    if (v === '--out') a.out = argv[++i];
    else if (v === '--runs') a.runs = parseInt(argv[++i], 10);
    else if (v === '--menu') a.menu = argv[++i];
    else if (v === '--button') a.button = argv[++i];
    else if (v === '-h' || v === '--help') a.help = true;
    else a.urls.push(v);
  }
  return a;
}

// Runs in the page before any script.
const INIT = () => {
  const s = (window.__lv = { lcp: null, lcpEl: null, cls: 0, clsEntries: 0, events: [] });
  try {
    new PerformanceObserver((l) => {
      for (const e of l.getEntries()) {
        s.lcp = e.renderTime || e.loadTime || e.startTime;
        const el = e.element;
        s.lcpEl = el ? (el.tagName + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.') : '')).slice(0, 120) : (e.url || null);
        s.lcpUrl = e.url || null;
      }
    }).observe({ type: 'largest-contentful-paint', buffered: true });
  } catch (_) {}
  try {
    // CLS per Core Web Vitals: largest session window (1 s gap, 5 s max).
    let win = 0, winStart = 0, last = 0;
    new PerformanceObserver((l) => {
      for (const e of l.getEntries()) {
        if (e.hadRecentInput) continue;
        s.clsEntries++;
        if (win && e.startTime - last < 1000 && e.startTime - winStart < 5000) win += e.value;
        else { win = e.value; winStart = e.startTime; }
        last = e.startTime;
        if (win > s.cls) s.cls = win;
      }
    }).observe({ type: 'layout-shift', buffered: true });
  } catch (_) {}
  try {
    new PerformanceObserver((l) => {
      for (const e of l.getEntries()) {
        s.events.push({ name: e.name, id: e.interactionId || 0, start: e.startTime, duration: e.duration,
          inputDelay: e.processingStart - e.startTime, processing: e.processingEnd - e.processingStart,
          presentation: e.startTime + e.duration - e.processingEnd });
      }
    }).observe({ type: 'event', durationThreshold: 16, buffered: true });
    new PerformanceObserver(() => {}).observe({ type: 'first-input', buffered: true });
  } catch (_) {}
};

async function firstVisible(page, selectors) {
  for (const sel of selectors) {
    const loc = page.locator(sel);
    const n = await loc.count().catch(() => 0);
    for (let i = 0; i < Math.min(n, 10); i++) {
      const el = loc.nth(i);
      if (await el.isVisible().catch(() => false)) return { sel, el };
    }
  }
  return null;
}

async function tapAndMeasure(page, target, label) {
  const before = await page.evaluate(() => performance.now());
  const urlBefore = page.url();
  await target.el.scrollIntoViewIfNeeded().catch(() => {});
  await target.el.tap({ timeout: 5000 });
  await page.waitForTimeout(1200); // let the next paint and Event Timing entries land
  const evs = await page.evaluate((t0) => window.__lv.events.filter((e) => e.start >= t0 - 5), before);
  const inter = evs.filter((e) => e.id > 0);
  const worst = (inter.length ? inter : evs).reduce((m, e) => (e.duration > (m?.duration ?? -1) ? e : m), null);
  return {
    label, selector: target.sel, navigated: page.url() !== urlBefore,
    // Event Timing only reports events >= 16 ms; no entry means the response was under 16 ms.
    latency_ms: worst ? Math.round(worst.duration) : 16, below_threshold: !worst,
    breakdown: worst ? { event: worst.name, input_delay_ms: Math.round(worst.inputDelay), processing_ms: Math.round(worst.processing), presentation_ms: Math.round(worst.presentation) } : null,
  };
}

async function runOnce(browser, url, opts) {
  const ctx = await browser.newContext({
    viewport: { width: 412, height: 915 }, deviceScaleFactor: 2.625, isMobile: true, hasTouch: true,
    userAgent: MOBILE_UA, locale: 'en-GB', serviceWorkers: 'block',
  });
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
    res.cache_header = resp ? (resp.headers()['x-litespeed-cache'] || resp.headers()['x-hcdn-cache-status'] || null) : null;
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(2000);
    const nav = await page.evaluate(() => {
      const n = performance.getEntriesByType('navigation')[0];
      const fcp = performance.getEntriesByName('first-contentful-paint')[0];
      return { ttfb: n ? n.responseStart : null, fcp: fcp ? fcp.startTime : null, load: n ? n.loadEventEnd : null };
    });
    const pre = await page.evaluate(() => ({ lcp: window.__lv.lcp, lcpEl: window.__lv.lcpEl, lcpUrl: window.__lv.lcpUrl, cls: window.__lv.cls }));
    res.ttfb_ms = nav.ttfb != null ? Math.round(nav.ttfb) : null;
    res.fcp_ms = nav.fcp != null ? Math.round(nav.fcp) : null;
    res.load_ms = nav.load != null ? Math.round(nav.load) : null;
    res.lcp_ms = pre.lcp != null ? Math.round(pre.lcp) : null;
    res.lcp_element = pre.lcpEl;
    res.lcp_url = pre.lcpUrl;

    res.taps = [];
    const menu = await firstVisible(page, opts.menu ? [opts.menu] : DEFAULT_MENU);
    if (menu) {
      res.taps.push(await tapAndMeasure(page, menu, 'main-menu'));
      const close = await firstVisible(page, DEFAULT_MENU_CLOSE);
      if (close) { await close.el.tap({ timeout: 5000 }).catch(() => {}); await page.waitForTimeout(800); }
    } else res.taps.push({ label: 'main-menu', error: 'menu toggle not found' });
    const btn = await firstVisible(page, opts.button ? [opts.button] : DEFAULT_BUTTON);
    if (btn) res.taps.push(await tapAndMeasure(page, btn, 'in-page-button'));
    else res.taps.push({ label: 'in-page-button', error: 'no suitable in-page button found' });

    res.cls = Math.round((await page.evaluate(() => window.__lv.cls)) * 1000) / 1000;
    const lat = res.taps.filter((t) => typeof t.latency_ms === 'number').map((t) => t.latency_ms);
    res.inp_ms = lat.length ? Math.max(...lat) : null;
  } catch (e) {
    res.ok = false; res.error = String(e.message || e).slice(0, 300);
  }
  await ctx.close();
  return res;
}

const median = (xs) => {
  const v = xs.filter((x) => typeof x === 'number').sort((a, b) => a - b);
  if (!v.length) return null;
  const m = Math.floor(v.length / 2);
  return v.length % 2 ? v[m] : (v[m - 1] + v[m]) / 2;
};

async function main() {
  const args = parseArgs(process.argv.slice(2));
  if (args.help || !args.out || !args.urls.length) {
    console.error('Usage: node tools/lead/lab-vitals.mjs --out <file> [--runs 3] [--menu css] [--button css] <url>...');
    process.exit(args.help ? 0 : 2);
  }
  const browser = await chromium.launch();
  const out = {
    kind: 'lab',
    label: 'LAB results (simulated mid-range phone on 4G) - not real-visitor data',
    generated_at: new Date().toISOString(),
    tool: 'tools/lead/lab-vitals.mjs (Playwright chromium ' + browser.version() + ')',
    profile: { device: 'mid-range phone emulation', viewport: '412x915', device_scale_factor: 2.625, user_agent: MOBILE_UA,
      cpu_throttling: CPU_RATE + 'x', network: { down_mbps: 9, up_mbps: 1.5, latency_ms: 60 }, cache: 'cold (disabled) each run' },
    method: { lcp: 'PerformanceObserver largest-contentful-paint, read before first tap', cls: 'PerformanceObserver layout-shift, largest session window, whole visit',
      inp: 'Event Timing API (type event, durationThreshold 16); worst of the scripted taps (main menu toggle + one in-page button); <16 ms reported as 16' },
    targets: TARGETS, runs_per_url: args.runs, results: [],
  };
  for (const url of args.urls) {
    const runs = [];
    for (let i = 0; i < args.runs; i++) {
      const r = await runOnce(browser, url, args);
      runs.push(r);
      console.error(`${url} run ${i + 1}: LCP ${r.lcp_ms} ms, CLS ${r.cls}, INP ${r.inp_ms} ms${r.error ? ' ERROR ' + r.error : ''}`);
    }
    const med = { lcp_ms: median(runs.map((r) => r.lcp_ms)), cls: median(runs.map((r) => r.cls)), inp_ms: median(runs.map((r) => r.inp_ms)),
      ttfb_ms: median(runs.map((r) => r.ttfb_ms)), fcp_ms: median(runs.map((r) => r.fcp_ms)) };
    out.results.push({
      url, median: med,
      pass: { lcp: med.lcp_ms != null && med.lcp_ms < TARGETS.lcp_ms, cls: med.cls != null && med.cls < TARGETS.cls, inp: med.inp_ms != null && med.inp_ms < TARGETS.inp_ms },
      runs,
    });
  }
  await browser.close();
  mkdirSync(dirname(args.out), { recursive: true });
  writeFileSync(args.out, JSON.stringify(out, null, 2) + '\n');
  console.error('wrote ' + args.out);
}

main().catch((e) => { console.error(e); process.exit(1); });
