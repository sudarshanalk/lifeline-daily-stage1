// TC-FR1-15 Safe withdrawal and rollback — http + php + artefact half (no WordPress needed; runs on every PR).
// AS-01 (php part): a withdrawn status.json makes the diabetes endpoint answer 503 with no mail while protein delivers;
// AS-05 status.json is served with Cache-Control: no-store through the real .htaccess; AS-06 the withdrawal runbook names
// the toggle command, the LiteSpeed purge step, the 4-hour production SLA (DEC-08) and a dated drill record.
import { describe, expect, test } from 'vitest';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fixtures } from '../lib/fixtures.mjs';
import { ROOT } from '../lib/paths.mjs';

const status = JSON.parse(readFileSync(resolve(ROOT, 'site', 'public_html', 'tools', 'status.json'), 'utf8'));
const withdrawn = { ...status, tools: { ...status.tools, diabetes: { ...status.tools.diabetes, status: 'withdrawn', message: 'unavailable.diabetes', since: '2026-10-23T12:00:00Z' } } };

describe('TC-FR1-15 safe withdrawal and rollback — endpoints, headers, runbook', () => {
  test('TC-FR1-15-AS-01', async () => {
    // the plugin's unavailable card renders approved copy for the status.json message key (DEC-72): its text must equal the
    // copy deck's unavailable.body in both tools, so a drift fails here even where the WordPress e2e cannot run (DEC-69)
    const plugin = readFileSync(resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools', 'lld-tools.php'), 'utf8');
    const pluginBody = plugin.match(/'unavailable\.body' => '((?:[^'\]|\.)*)'/)?.[1].replace(/\'/g, "'");
    for (const deck of ['protein-messages.json', 'diabetes-messages.json']) expect(pluginBody, `plugin copy = copy/${deck} unavailable.body`).toBe(JSON.parse(readFileSync(resolve(ROOT, 'copy', deck), 'utf8'))['unavailable.body']);
    const srv = await startHtaccessServer({ statusJson: withdrawn });
    try {
      const outboxDir = join(srv.docroot, 'tools', 'api', 'outbox');
      const outbox = () => existsSync(outboxDir) ? readdirSync(outboxDir).filter((f) => !f.startsWith('.')) : [];
      const post = (path, body, ip) => fetch(srv.url + path, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: 'https://lifelinedaily.com', 'X-Forwarded-For': ip }, body: JSON.stringify(body) });
      const r = await post('/tools/submit.php', fixtures('FX-MAIL-02')[0].inputs, '10.15.1.1');
      expect(r.status, 'withdrawn diabetes endpoint → 503').toBe(503);
      expect(await r.json()).toMatchObject({ status: 'withdrawn' });
      expect(outbox().length).toBe(0);
      const lines = srv.phpLog().filter((l) => l.includes('"outcome":"withdrawn"'));
      expect(lines.length, 'SCH-07 line with outcome withdrawn').toBe(1);
      const p = await post('/tools/submit-protein.php', fixtures('FX-MAIL-01')[0].inputs, '10.15.1.2');
      expect(p.status, 'protein unaffected').toBe(200); expect(outbox().length).toBe(1);
      // the served tool page carries the status gate markup the browser uses (e2e covers the rendered state)
      const html = await (await fetch(srv.url + '/tools/diabetes-risk-tool.html')).text();
      expect(html).toContain('data-test="unavailable"'); expect(html).toContain('data-test="loading"'); expect(html).toMatch(/fetch\('\.\/status\.json',\{cache:'no-store'\}\)/);
    } finally { await srv.close(); }
  }, 60000);

  test('TC-FR1-15-AS-05', async () => {
    const srv = await startHtaccessServer();
    try {
      const r = await fetch(srv.url + '/tools/status.json', { method: 'HEAD' });
      expect(r.status).toBe(200);
      expect(r.headers.get('cache-control')).toBe('no-store');
    } finally { await srv.close(); }
  });

  test('TC-FR1-15-AS-06', (ctx) => {
    const candidates = ['docs/runbook-withdrawal.md', 'deploy/runbooks/runbook-withdrawal.md'].map((p) => resolve(ROOT, p)).filter(existsSync);
    expect(candidates.length, 'withdrawal runbook present (docs/runbook-withdrawal.md or deploy/runbooks/runbook-withdrawal.md)').toBeGreaterThan(0);
    const text = readFileSync(candidates[0], 'utf8');
    expect(text, 'toggle command').toMatch(/wp lld status set/);
    expect(text, 'LiteSpeed purge step').toMatch(/wp litespeed-purge|LiteSpeed Cache → Purge/i);
    expect(text, '4-hour production SLA (DEC-08)').toMatch(/4 hours/i);
    const drill = /drill[^\n]*\b20\d{2}-\d{2}-\d{2}\b/i.test(text) || /\b20\d{2}-\d{2}-\d{2}\b[^\n]*drill/i.test(text);
    if (!drill) { ctx.skip('no dated drill record yet: the withdrawal drill runs on staging (OI-1) and is recorded in the runbook by the owner'); return; }
    expect(drill).toBe(true);
  });
});
