// TC-FR1-21 Standalone tool page hardening — static + unit + http families. Mutation: MUT-12.
// e2e assertions (AS-01 crawl, AS-03 sizing, AS-05 framing blocked) live in tests/e2e/TC-FR1-21.spec.mjs.
import { afterAll, beforeAll, describe, expect, test } from 'vitest';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import { resolve } from 'node:path';
import { toolPath, TOOL_FILES, ROOT } from '../lib/paths.mjs';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import manifestProtein from '../../manifests/protein.json' with { type: 'json' };
import manifestDiabetes from '../../manifests/diabetes.json' with { type: 'json' };

const require = createRequire(import.meta.url);
// The listener is a browser script (.js in a type:module repo), so load it as CommonJS text: `window` undefined, `module` provided.
const LISTENER = resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools', 'assets', 'lld-tool-frame.js');
const loadListener = () => { const mod = { exports: {} }; new Function('module', 'window', readFileSync(LISTENER, 'utf8'))(mod, undefined); return mod.exports; };
const { createController, MIN_HEIGHT } = loadListener();
const LANDING = { protein: '/health-tools/protein/', diabetes: '/health-tools/diabetes-risk-check/' };
const html = Object.fromEntries(Object.keys(TOOL_FILES).map((s) => [s, readFileSync(toolPath(s), 'utf8')]));

/** The script body of the tool page: the only place a postMessage may appear. */
const scripts = (src) => [...src.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].map((m) => m[1]).join('\n');

const remote = process.env.LLD_TOOLS_URL;
let srv, base;
beforeAll(async () => { if (remote) base = remote.replace(/\/$/, ''); else { srv = await startHtaccessServer(); base = srv.url; } });
afterAll(async () => { if (srv) await srv.close(); });

describe('TC-FR1-21 standalone tool page hardening', () => {
  test('TC-FR1-21-AS-02', () => {
    // static: both tools post exactly {type:'lld-tool-height', height} to the configured origin placeholder, never '*'
    for (const slug of Object.keys(TOOL_FILES)) {
      const js = scripts(html[slug]);
      const calls = [...js.matchAll(/postMessage\s*\(([\s\S]*?)\)\s*;/g)].map((m) => m[1]);
      expect(calls.length, `${slug}: one postMessage call`).toBe(1);
      expect(calls[0], `${slug}: schema`).toMatch(/\{\s*type\s*:\s*'lld-tool-height'\s*,\s*height\s*:\s*h\s*\}/);
      expect(calls[0], `${slug}: target origin`).toMatch(/,\s*'__LLD_ORIGIN__'\s*$/);
      expect(js, `${slug}: no wildcard target anywhere`).not.toMatch(/postMessage\s*\([^)]*,\s*['"]\*['"]\s*\)/);
      expect(js, `${slug}: legacy message shape removed`).not.toMatch(/lldToolHeight/);
    }
    // unit: the parent listener ignores a message from another origin and applies one from the configured origin
    const dom = new JSDOM('<iframe class="lld-tool-frame" src="about:blank"></iframe>', { url: 'https://lifelinedaily.com/health-tools/protein/' });
    const frame = dom.window.document.querySelector('iframe');
    const ctl = createController(dom.window.document, { iframe_origin: 'https://lifelinedaily.com' });
    ctl.applyFallback();
    expect(frame.style.height).toBe(`${MIN_HEIGHT}px`);
    expect(ctl.handle({ origin: 'https://evil.example', data: { type: 'lld-tool-height', height: 1234 }, source: frame.contentWindow })).toBe(false);
    expect(frame.style.height).toBe(`${MIN_HEIGHT}px`);
    expect(ctl.handle({ origin: 'https://lifelinedaily.com', data: { type: 'lld-tool-height', height: 1234 }, source: frame.contentWindow })).toBe(true);
    expect(frame.style.height).toBe('1234px');
    // wrong schema or garbage height from the right origin is ignored too
    expect(ctl.handle({ origin: 'https://lifelinedaily.com', data: { lldToolHeight: 999 }, source: frame.contentWindow })).toBe(false);
    expect(ctl.handle({ origin: 'https://lifelinedaily.com', data: { type: 'lld-tool-height', height: 'NaN' }, source: frame.contentWindow })).toBe(false);
    expect(frame.style.height).toBe('1234px');
    // no configured origin → nothing is ever applied (fail closed)
    const closed = createController(new JSDOM('<iframe class="lld-tool-frame"></iframe>').window.document, {});
    expect(closed.handle({ origin: 'https://lifelinedaily.com', data: { type: 'lld-tool-height', height: 800 } })).toBe(false);
  });

  test('TC-FR1-21-AS-04', async () => {
    for (const slug of Object.keys(TOOL_FILES)) {
      const r = await fetch(`${base}/tools/${TOOL_FILES[slug]}`);
      expect(r.status).toBe(200);
      // no noindex header or meta (DEC-14)
      expect((r.headers.get('x-robots-tag') || '').toLowerCase()).not.toContain('noindex');
      const body = await r.text();
      const doc = new JSDOM(body).window.document;
      for (const m of doc.querySelectorAll('meta[name="robots"], meta[name="googlebot"]')) expect(m.content.toLowerCase()).not.toContain('noindex');
      // canonical = the landing page on the configured origin
      const canon = doc.querySelectorAll('link[rel="canonical"]');
      expect(canon.length, `${slug}: exactly one canonical`).toBe(1);
      const expectedOrigin = remote ? new URL(base).origin : base;
      expect(canon[0].href).toBe(`${expectedOrigin}${LANDING[slug]}`);
      // the Git copy keeps the placeholder (environment-neutral, DEC-34)
      expect(html[slug]).toContain(`<link rel="canonical" href="__LLD_ORIGIN__${LANDING[slug]}">`);
      // HSTS present (DEC-35)
      expect(r.headers.get('strict-transport-security') || '').toMatch(/max-age=31536000/);
    }
  });

  test('TC-FR1-21-AS-06', () => {
    for (const slug of Object.keys(TOOL_FILES)) {
      const doc = new JSDOM(html[slug]).window.document;
      const ns = doc.querySelector('noscript');
      expect(ns, `${slug}: <noscript> present`).not.toBeNull();
      // a scripting UA keeps <noscript> content as text, a non-scripting one parses it; accept either
      const link = ns.querySelector('a[href]') || new JSDOM(ns.textContent).window.document.querySelector('a[href]');
      expect(link, `${slug}: link inside <noscript>`).not.toBeNull();
      expect(link.getAttribute('href')).toBe(`__LLD_ORIGIN__/tools/${TOOL_FILES[slug]}`);
    }
  });

  test('TC-FR1-21 shell change keeps the frozen engine and updates provenance in the same PR', () => {
    // supporting check (not a TC id): the manifests' live version is the current file and the js:* hashes are unchanged from the
    // baseline, or from the latest engine version approved at HG-02 (protein v2, DEC-86)
    const { createHash } = require('node:crypto');
    for (const [slug, manifest] of [['protein', manifestProtein], ['diabetes', manifestDiabetes]]) {
      const live = manifest.versions.find((v) => v.is_live);
      expect(live.version_hash).toBe(createHash('sha256').update(readFileSync(toolPath(slug))).digest('hex'));
      const baseline = manifest.versions.find((v) => v.notes && /Baseline|approved at HG-02/.test(v.notes));   // newest first
      for (const [k, h] of Object.entries(baseline.engine_hashes)) if (k.startsWith('js:')) expect(live.engine_hashes[k], `${slug} ${k}`).toBe(h);
    }
  });
});
