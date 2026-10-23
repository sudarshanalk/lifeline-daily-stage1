// TC-NFR1-10 Error handling and observability — log family (AS-03): after a full run of the e-mail fixtures through both
// endpoints (valid, invalid, honeypot, rate-limited, withdrawn), every endpoint log line validates SCH-07 line by line and
// no line carries an e-mail address, a numeric result or a symptom key. Fixtures: FX-MAIL-*.
import { afterAll, beforeAll, describe, expect, test } from 'vitest';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fixtures } from '../lib/fixtures.mjs';
import { validateFile } from '../lib/validate.mjs';
import { ROOT, TOOLS_DIR } from '../lib/paths.mjs';
import sentinel from '../../fixtures/sentinel-values.json' with { type: 'json' };

const ENDPOINT = { protein: '/tools/submit-protein.php', 'diabetes-risk': '/tools/submit.php' };
let srv, ipN = 0;
beforeAll(async () => { srv = await startHtaccessServer(); });
afterAll(async () => { await srv?.close(); });
const post = (inputs, ip) => { const { _contentType, _repeat, ...payload } = inputs; return fetch(srv.url + (ENDPOINT[payload.tool] ?? ENDPOINT.protein), { method: 'POST', headers: { 'Content-Type': _contentType || 'application/json', Origin: 'https://lifelinedaily.com', 'X-Forwarded-For': ip }, body: JSON.stringify(payload) }); };

describe('TC-NFR1-10 error handling and observability — logs', () => {
  test('TC-NFR1-10-AS-03', async () => {
    // the full fixture set: every outcome the endpoints can log
    for (const f of fixtures('FX-MAIL-*')) { const ip = `10.10.0.${++ipN}`; for (let i = 0; i < (f.inputs._repeat || 1); i++) await post(f.inputs, ip); }
    const withdrawn = JSON.parse(readFileSync(srv.statusPath, 'utf8')); withdrawn.tools.diabetes.status = 'withdrawn'; writeFileSync(srv.statusPath, JSON.stringify(withdrawn));
    expect((await post(fixtures('FX-MAIL-02')[0].inputs, '10.10.9.9')).status).toBe(503);
    const lines = srv.phpLog().filter((l) => l.includes('{') && l.includes('"ts"')).map((l) => l.slice(l.indexOf('{')));
    expect(lines.length).toBeGreaterThan(15);
    const dir = mkdtempSync(join(tmpdir(), 'lld-log-'));
    try {
      lines.forEach((raw, i) => { const f = join(dir, `l${i}.json`); writeFileSync(f, raw); const r = validateFile('endpoint-log.schema.json', f); expect(r.ok, `line ${i} ${raw}: ${r.errors.join('; ')}`).toBe(true); });
    } finally { rmSync(dir, { recursive: true, force: true }); }
    const outcomes = new Set(lines.map((l) => JSON.parse(l).outcome));
    for (const o of ['sent', 'rejected_validation', 'rejected_rate', 'honeypot', 'withdrawn']) expect(outcomes, `outcome ${o} exercised`).toContain(o);
    // nothing but SCH-07 fields: no address, result number or symptom key anywhere in the server log
    const symptomKeys = [...readFileSync(resolve(TOOLS_DIR, 'diabetes-risk-tool.html'), 'utf8').matchAll(/data-sym="([a-z]+)"/g)].map((m) => m[1]);
    const forbidden = ['@', sentinel.firstName, sentinel.email, 'targetG', 'riskPercent', '"105"', '"4.2"', ...symptomKeys];
    for (const line of srv.phpLog()) for (const bad of forbidden) expect(line, `log leaks "${bad}"`).not.toContain(bad);
  }, 120000);
});
