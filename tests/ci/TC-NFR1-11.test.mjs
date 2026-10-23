// TC-NFR1-11 Graceful clinical feature disable — ci family. Each assertion is, by the TC page, another requirement's assertion
// re-run under this ID: AS-01 = TC-FR1-10-AS-04 (fidelity-gate withdraws the diabetes tool in a sandbox when the engine disagrees
// with ORC-01, no production credential), AS-02 = SAF-WITHDRAW-01 / TC-FR1-15-AS-01 (a withdrawn switch → 503 and no mail;
// protein unaffected; restore → 200), AS-03 = TC-FR1-15-AS-03 (`wp lld status set … active` refuses an unapproved or missing
// version and leaves status.json byte-identical; local WordPress stack, skipped with reason elsewhere — DEC-08/DEC-10;
// local-only WordPress e2e is the DEC-69 proposal). Fixtures: FX-DM-QD-*, FX-MAIL-01/02.
import { describe, expect, test } from 'vitest';
import { cpSync, existsSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { ROOT } from '../lib/paths.mjs';
import { fixtures } from '../lib/fixtures.mjs';
import { diabetesRunner } from '../lib/tool-page.mjs';
import { fidelityGate } from '../ci/fidelity-gate.mjs';
import { mutatedTree } from '../lib/mutate.mjs';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { available, startWpStack } from '../lib/wp-stack.mjs';

const STATUS = resolve(ROOT, 'site', 'public_html', 'tools', 'status.json');
const wp = available();

describe('TC-NFR1-11 graceful clinical feature disable', () => {
  test('TC-NFR1-11-AS-01', async () => {
    for (const k of ['PROD_SSH_HOST', 'PROD_SSH_KEY', 'PROD_SSH_USER', 'PROD_DOCROOT']) expect(process.env[k], `${k} must not be set`).toBeUndefined();
    const run = await diabetesRunner();
    const sandbox = mkdtempSync(join(tmpdir(), 'lld-nfr111-'));
    try {
      const qd = fixtures('FX-DM-QD-*').filter((f) => !f.id.includes('CLAMP'));
      const orcPath = join(sandbox, 'results.json');
      writeFileSync(orcPath, JSON.stringify({ oracle_id: 'ORC-01', implementation: 'sandbox stand-in (engine output, unmutated)', versions: { R: 'n/a' }, run_at: new Date().toISOString(), results: qd.map((f) => ({ fixture_id: f.id, expected: { pct: run(f.inputs).pct } })) }));
      const statusPath = join(sandbox, 'status.json'); cpSync(STATUS, statusPath);
      const clean = fidelityGate({ root: ROOT, orcPath, statusPath });
      expect(clean.ok, 'unmutated engine: ok').toBe(true); expect(clean.action, 'unmutated engine: no action').toBe('none');
      expect(JSON.parse(readFileSync(statusPath, 'utf8')).tools.diabetes.status, 'status untouched by a clean run').toBe('active');
      const mutRoot = mutatedTree('MUT-04', ['fixtures/diabetes', 'manifests/diabetes.json']);
      try {
        const errors = []; const orig = console.error; console.error = (m) => errors.push(String(m));
        let r; try { r = fidelityGate({ root: mutRoot, orcPath, statusPath }); } finally { console.error = orig; }
        expect(r.ok).toBe(false); expect(r.action).toBe(`withdrawn:${statusPath}`); expect(r.problems.length, 'disagreements listed').toBeGreaterThan(0);
        expect(errors.join('\n')).toMatch(/ALERT fidelity-gate/);
        const st = JSON.parse(readFileSync(statusPath, 'utf8'));
        expect(st.tools.diabetes.status, 'diabetes withdrawn, not deleted').toBe('withdrawn');
        expect(st.tools.diabetes.message).toBe('unavailable.diabetes');
        expect(st.tools.diabetes.since, 'since stamped (ISO)').toMatch(/^\d{4}-\d{2}-\d{2}T/);
        expect(st.tools.protein.status, 'protein untouched').toBe('active');
      } finally { rmSync(mutRoot, { recursive: true, force: true }); }
      // the repository's status.json template is untouched
      expect(JSON.parse(readFileSync(STATUS, 'utf8')).tools.diabetes.status).toBe('active');
    } finally { rmSync(sandbox, { recursive: true, force: true }); }
    // a production credential in the environment makes the gate refuse to run at all
    process.env.PROD_SSH_HOST = 'x'; try { expect(fidelityGate({ root: ROOT }).action).toBe('refused'); } finally { delete process.env.PROD_SSH_HOST; }
  }, 120000);

  test('TC-NFR1-11-AS-02', async () => {
    const status = JSON.parse(readFileSync(STATUS, 'utf8'));
    const withdrawn = { ...status, tools: { ...status.tools, diabetes: { ...status.tools.diabetes, status: 'withdrawn', message: 'unavailable.diabetes', since: '2026-10-23T12:00:00Z' } } };
    const srv = await startHtaccessServer({ statusJson: withdrawn });
    try {
      const outboxDir = join(srv.docroot, 'tools', 'api', 'outbox');
      const outbox = () => (existsSync(outboxDir) ? readdirSync(outboxDir).filter((f) => !f.startsWith('.')) : []);
      const post = (path, body, ip) => fetch(srv.url + path, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: 'https://lifelinedaily.com', 'X-Forwarded-For': ip }, body: JSON.stringify(body) });
      const before = outbox().length;
      const r = await post('/tools/submit.php', fixtures('FX-MAIL-02')[0].inputs, '10.6.0.1');
      expect(r.status, 'withdrawn diabetes endpoint').toBe(503); expect((await r.json()).status).toBe('withdrawn'); expect(outbox().length - before, 'no mail sent').toBe(0);
      const page = await (await fetch(`${srv.url}/tools/diabetes-risk-tool.html`)).text();
      expect(page, 'the tool page carries the status gate').toContain('data-test="unavailable"');
      const p = await post('/tools/submit-protein.php', fixtures('FX-MAIL-01')[0].inputs, '10.6.0.2');
      expect(p.status, 'protein unaffected').toBe(200); expect(outbox().length - before).toBe(1);
      writeFileSync(srv.statusPath, JSON.stringify(status));
      const r2 = await post('/tools/submit.php', fixtures('FX-MAIL-02')[0].inputs, '10.6.0.3');
      expect(r2.status, 'restored diabetes endpoint').toBe(200); expect(outbox().length - before).toBe(2);
    } finally { await srv.close(); }
  }, 60000);

  test('TC-NFR1-11-AS-03', async (ctx) => {
    if (!wp.ok) { ctx.skip(wp.reason); return; }
    const stack = await startWpStack();
    try {
      const before = readFileSync(stack.statusPath);
      const r = stack.wp('lld', 'status', 'set', 'diabetes', 'active', '--version=deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef');
      expect(r.status, 'unapproved version is refused').not.toBe(0);
      expect(r.stdout + r.stderr).toMatch(/not an approved version/);
      expect(Buffer.compare(readFileSync(stack.statusPath), before), 'status.json byte-identical').toBe(0);
      const bad = stack.wp('lld', 'status', 'set', 'diabetes', 'active');
      expect(bad.status, 'active without --version is refused').not.toBe(0);
      expect(Buffer.compare(readFileSync(stack.statusPath), before)).toBe(0);
    } finally { await stack.close(); }
  }, 300000);
});
