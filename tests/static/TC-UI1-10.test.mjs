// TC-UI1-10 Iframe embed behaviour — static + http + unit half. AS-03 the tool pages' <noscript> link resolves 200;
// AS-04 the parent listener ignores a height message from another origin (unit, lld-tool-frame.js createController).
// AS-01's static reading (the documented embed markup in the lld-tools README) is checked here too; the rendered embed is AS-01 in the e2e spec.
import { afterAll, beforeAll, describe, expect, test } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { JSDOM } from 'jsdom';
import { ROOT, TOOL_FILES, toolPath } from '../lib/paths.mjs';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';

const PLUGIN = resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools');
let srv;
beforeAll(async () => { srv = await startHtaccessServer(); });
afterAll(async () => { await srv?.close(); });

describe('TC-UI1-10 iframe embed behaviour', () => {
  test('TC-UI1-10-AS-01', () => {
    // the embed contract the shortcode renders (README): title, loading="eager" (never lazy), no allow attribute
    const readme = readFileSync(resolve(PLUGIN, 'README.md'), 'utf8');
    const html = /```html\n([\s\S]*?)```/.exec(readme)?.[1] || '';
    const iframe = new JSDOM(html).window.document.querySelector('iframe.lld-tool-frame');
    expect(iframe, 'README documents the iframe markup').toBeTruthy();
    expect(iframe.getAttribute('title')?.trim().length).toBeGreaterThan(3);
    expect(iframe.getAttribute('loading')).not.toBe('lazy');
    expect(iframe.getAttribute('allow') || '').toBe('');
    // the landing-page double renders the same contract
    const dbl = readFileSync(resolve(ROOT, 'tests', 'e2e', 'harness', 'landing.html'), 'utf8');
    expect(dbl).toMatch(/f\.loading = 'eager'/); expect(dbl).toMatch(/f\.title = '/); expect(dbl).not.toMatch(/\.allow\s*=/);
  });

  test('TC-UI1-10-AS-03', async () => {
    for (const slug of Object.keys(TOOL_FILES)) {
      const doc = new JSDOM(readFileSync(toolPath(slug), 'utf8')).window.document;
      const a = doc.querySelector('noscript a[href]');
      expect(a, `${slug}: <noscript> link present`).toBeTruthy();
      const href = a.getAttribute('href').replace('__LLD_ORIGIN__', srv.url);
      const url = href.startsWith('http') ? href : srv.url + href;
      const r = await fetch(url.replace(/\/health-tools\/[^/]+\/$/, (m) => m) , { redirect: 'manual' });
      // WordPress landing pages are not emulated: the harness answers the tool page itself and the landing double; a
      // landing URL is checked against the real origin when LLD_WP_URL is set
      if (/\/health-tools\//.test(href) && !process.env.LLD_WP_URL) {
        expect(href, `${slug}: noscript links to the landing page`).toMatch(/\/health-tools\/(protein|diabetes-risk-check)\/$/);
        continue;
      }
      expect(r.status, `${slug}: ${url} → 200`).toBe(200);
    }
    if (process.env.LLD_WP_URL) for (const p of ['/health-tools/protein/', '/health-tools/diabetes-risk-check/']) expect((await fetch(process.env.LLD_WP_URL.replace(/\/$/, '') + p)).status, p).toBe(200);
  });

  test('TC-UI1-10-AS-04', () => {
    // the listener is a browser script with a CommonJS tail; load it the way TC-FR1-21 does (the package is ESM, so require() cannot)
    const mod = { exports: {} }; new Function('module', 'window', readFileSync(resolve(PLUGIN, 'assets', 'lld-tool-frame.js'), 'utf8'))(mod, undefined);
    const { createController, MIN_HEIGHT } = mod.exports;
    const dom = new JSDOM('<iframe class="lld-tool-frame" src="https://lifelinedaily.com/tools/protein-requirement-tool.html"></iframe>');
    const doc = dom.window.document; const frame = doc.querySelector('iframe');
    const ctl = createController(doc, { iframe_origin: 'https://lifelinedaily.com' });
    ctl.applyFallback();
    expect(frame.style.height).toBe(`${MIN_HEIGHT}px`);
    // wrong origin: ignored, height unchanged
    expect(ctl.handle({ origin: 'https://evil.example', data: { type: 'lld-tool-height', height: 1234 }, source: frame.contentWindow })).toBe(false);
    expect(frame.style.height).toBe(`${MIN_HEIGHT}px`);
    expect(ctl.handle({ origin: 'https://www.lifelinedaily.com', data: { type: 'lld-tool-height', height: 1234 }, source: frame.contentWindow })).toBe(false);
    expect(frame.style.height).toBe(`${MIN_HEIGHT}px`);
    // right origin: applied
    expect(ctl.handle({ origin: 'https://lifelinedaily.com', data: { type: 'lld-tool-height', height: 1234 }, source: frame.contentWindow })).toBe(true);
    expect(frame.style.height).toBe('1234px');
  });
});
