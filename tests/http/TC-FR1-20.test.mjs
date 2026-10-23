// TC-FR1-20 Cache control and purge — family http (+ e2e through the local full stack).
// Runs against LLD_TOOLS_URL when set (staging), otherwise against the .htaccess emulator serving a temp copy of
// site/public_html (tests/lib/htaccess-server.mjs). Assertions that need to write files (toggle status, edit a page)
// run only on the emulator; on a remote origin they are reported as skipped with the reason.
import { afterAll, beforeAll, describe, expect, test } from 'vitest';
import { readFileSync, writeFileSync, appendFileSync, utimesSync } from 'node:fs';
import { join } from 'node:path';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import statusTemplate from '../../site/public_html/tools/status.json' with { type: 'json' };

const remote = process.env.LLD_TOOLS_URL;
let srv, base;
beforeAll(async () => { if (remote) base = remote.replace(/\/$/, ''); else { srv = await startHtaccessServer(); base = srv.url; } });
afterAll(async () => { if (srv) await srv.close(); });

const get = (path, init) => fetch(base + path, { redirect: 'manual', ...init });
const cc = (res) => (res.headers.get('cache-control') || '').toLowerCase();

describe('TC-FR1-20 cache control', () => {
  test('TC-FR1-20-AS-01', async () => {
    // status.json: no-store
    const sj = await get('/tools/status.json');
    expect(sj.status).toBe(200);
    expect(cc(sj)).toContain('no-store');
    expect(JSON.parse(await sj.text()).tools.protein.status).toMatch(/^(active|withdrawn)$/);
    // tool HTML: no-cache, must-revalidate + ETag; a conditional GET with that ETag is a 304 (the validator works)
    for (const f of ['protein-requirement-tool.html', 'diabetes-risk-tool.html']) {
      const r = await get(`/tools/${f}`);
      expect(r.status, f).toBe(200);
      expect(cc(r), f).toContain('no-cache');
      expect(cc(r), f).toContain('must-revalidate');
      expect(cc(r), f).not.toContain('no-store');
      const etag = r.headers.get('etag');
      expect(etag, `${f} ETag`).toMatch(/^(W\/)?".+"$/);
      const again = await get(`/tools/${f}`, { headers: { 'If-None-Match': etag } });
      expect(again.status, `${f} conditional GET`).toBe(304);
    }
    // endpoints: no-store on every response, including an invalid body (400) — `Header always`
    for (const f of ['submit-protein.php', 'submit.php']) {
      const r = await get(`/tools/${f}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: 'not json' });
      expect([400, 405, 422, 429, 503], `${f} status`).toContain(r.status);
      expect(cc(r), f).toContain('no-store');
    }
  });

  test('TC-FR1-20-AS-02', async (ctx) => {
    // X-LiteSpeed-Cache must be absent or `miss` on /tools/* and status.json (DEC-31 exclusion)
    for (const p of ['/tools/status.json', '/tools/protein-requirement-tool.html', '/tools/diabetes-risk-tool.html']) {
      const r = await get(p);
      const ls = (r.headers.get('x-litespeed-cache') || '').toLowerCase();
      expect(ls === '' || ls === 'miss', `${p} X-LiteSpeed-Cache=${ls}`).toBe(true);
      expect((r.headers.get('x-litespeed-cache-control') || '').toLowerCase(), `${p} cache-control for LiteSpeed`).toContain('no-cache');
    }
    if (remote) return ctx.skip('toggling status.json on a remote origin needs `wp lld status set` over SSH (OI-1); header part verified');
    // toggle status → the very next request (well within 60 s) reflects the new state through the full local stack
    const file = join(srv.docroot, 'tools', 'status.json');
    const before = JSON.parse(await (await get('/tools/status.json')).text());
    expect(before.tools.diabetes.status).toBe('active');
    const toggled = structuredClone(before);
    toggled.tools.diabetes.status = 'withdrawn'; toggled.tools.diabetes.since = new Date().toISOString(); toggled.updated_at = toggled.tools.diabetes.since;
    writeFileSync(file, JSON.stringify(toggled, null, 2));
    const t0 = Date.now();
    const after = await get('/tools/status.json');
    expect(Date.now() - t0).toBeLessThan(60000);
    expect(JSON.parse(await after.text()).tools.diabetes.status).toBe('withdrawn');
    expect(cc(after)).toContain('no-store');
    writeFileSync(file, JSON.stringify(before, null, 2));
    expect(JSON.parse(await (await get('/tools/status.json')).text()).tools.diabetes.status).toBe('active');
  });

  test('TC-FR1-20-AS-03', async (ctx) => {
    if (remote) return ctx.skip('editing the deployed tool page needs a staging deploy (OI-1)');
    // modify a comment in the tool HTML → the next request carries a different ETag
    const f = 'protein-requirement-tool.html';
    const path = join(srv.docroot, 'tools', f);
    const first = await get(`/tools/${f}`);
    const etag1 = first.headers.get('etag');
    const original = readFileSync(path);
    appendFileSync(path, '\n<!-- TC-FR1-20-AS-03 shell comment -->\n');
    const later = new Date(Date.now() + 2000); utimesSync(path, later, later);
    const second = await get(`/tools/${f}`);
    expect(second.status).toBe(200);
    expect(second.headers.get('etag')).not.toBe(etag1);
    expect(cc(second)).toContain('must-revalidate');
    // and the stale validator no longer yields 304
    expect((await get(`/tools/${f}`, { headers: { 'If-None-Match': etag1 } })).status).toBe(200);
    writeFileSync(path, original);
  });

  test('TC-FR1-20 status.json template validates SCH-08 and names the live versions', () => {
    // supporting check (not a TC id): the Git template is the one installed by hand on a fresh server
    expect(Object.keys(statusTemplate.tools).sort()).toEqual(['diabetes', 'protein']);
    for (const slug of ['protein', 'diabetes']) {
      const manifest = JSON.parse(readFileSync(new URL(`../../manifests/${slug}.json`, import.meta.url), 'utf8'));
      expect(statusTemplate.tools[slug].status).toBe('active');
      expect(statusTemplate.tools[slug].approved_version).toBe(manifest.versions.find((v) => v.is_live).version_hash);
    }
    expect(statusTemplate.labels.map((l) => l.status)).toEqual(['label_only']);
  });
});
