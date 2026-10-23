// TC-NFR1-15 Embed security policy — http family (AS-01). AS-02 (= TC-FR1-21-AS-05) and AS-03 (report-only phase
// produces 0 reports across the e2e suite) are Playwright specs in tests/e2e/TC-FR1-21.spec.mjs and tests/e2e/TC-NFR1-15.spec.mjs.
// Runs against LLD_TOOLS_URL (+ LLD_WP_URL for the landing pages) when set, otherwise the .htaccess emulator,
// whose /__harness/landing.html stands in for the WordPress landing pages and carries the managed root block headers.
import { afterAll, beforeAll, describe, expect, test } from 'vitest';
import { resolve } from 'node:path';
import { ROOT, TOOL_FILES } from '../lib/paths.mjs';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';

const remoteTools = process.env.LLD_TOOLS_URL?.replace(/\/$/, '');
const remoteWp = process.env.LLD_WP_URL?.replace(/\/$/, '');
let srv, tools, landing, origin;
beforeAll(async () => {
  if (remoteTools) { tools = remoteTools; landing = remoteWp; origin = new URL(remoteTools).origin; }
  else { srv = await startHtaccessServer({ harnessDir: resolve(ROOT, 'tests', 'e2e', 'harness') }); tools = srv.url; landing = srv.url + '/__harness'; origin = srv.url; }
});
afterAll(async () => { if (srv) await srv.close(); });

/** Parse a CSP header into { directive: [sources] }. */
const parse = (h) => Object.fromEntries((h || '').split(';').map((d) => d.trim()).filter(Boolean).map((d) => { const [k, ...v] = d.split(/\s+/); return [k, v]; }));
const allCsp = (res) => (res.headers.get('content-security-policy') || '').split(/,\s*/).filter(Boolean); // the host adds its own CSP (upgrade-insecure-requests); both are enforced

describe('TC-NFR1-15 embed security policy', () => {
  test('TC-NFR1-15-AS-01', async () => {
    // tool pages: frame-ancestors limited to the configured origin plus the two production hosts (DEC-28, DEC-34)
    for (const slug of Object.keys(TOOL_FILES)) {
      const r = await fetch(`${tools}/tools/${TOOL_FILES[slug]}`);
      const policies = allCsp(r).map(parse);
      const fa = policies.find((p) => p['frame-ancestors']);
      expect(fa, `${slug}: frame-ancestors present`).toBeDefined();
      expect(fa['frame-ancestors']).toEqual(expect.arrayContaining([origin, 'https://lifelinedaily.com', 'https://www.lifelinedaily.com']));
      for (const src of fa['frame-ancestors']) expect(src, `${slug}: no wildcard/unknown ancestor`).toMatch(/^(https:\/\/(www\.)?lifelinedaily\.com|'self')$|^https?:\/\/[\w.-]+(:\d+)?$/);
      expect(fa['frame-ancestors']).not.toContain('*');
      // no placeholder leaks into the served header
      expect(r.headers.get('content-security-policy')).not.toContain('__LLD_ORIGIN__');
    }
    // landing pages: frame-src 'self' + the configured origin (apex on production)
    const pages = remoteWp ? ['/health-tools/protein/', '/health-tools/diabetes-risk-check/'] : ['/landing.html?tool=protein', '/landing.html?tool=diabetes'];
    for (const p of pages) {
      const r = await fetch(landing + p);
      expect(r.status, p).toBe(200);
      const policies = allCsp(r).map(parse);
      const fs = policies.find((q) => q['frame-src']);
      expect(fs, `${p}: frame-src present`).toBeDefined();
      expect(fs['frame-src']).toEqual(expect.arrayContaining(["'self'", origin]));
      expect(fs['frame-src']).toContain('https://lifelinedaily.com');
      expect(fs['frame-src']).not.toContain('*');
      // the enforced landing policy is mirrored as Report-Only so violations are observable (AS-03)
      expect(r.headers.get('content-security-policy-report-only') || '').toContain('frame-src');
    }
  });
});
