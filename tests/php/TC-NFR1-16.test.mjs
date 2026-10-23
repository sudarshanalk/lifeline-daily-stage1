// TC-NFR1-16 Logging and retention — php + log + artefact families (DEC-20). Fixtures: FX-MAIL-01, FX-MAIL-02, FX-MAIL-NEG-03.
// AS-01 every endpoint log line of a run validates SCH-07 through the same jsonschema validator CI uses;
// AS-02 30-day retention artefact (logrotate or equivalent) — see the skip reason / DEC proposal;
// AS-03 the same client IP hashes differently on day N and day N+1 (daily salt).
import { afterAll, beforeAll, describe, expect, test } from 'vitest';
import { mkdtempSync, writeFileSync, rmSync, existsSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fixtures } from '../lib/fixtures.mjs';
import { validateFile } from '../lib/validate.mjs';
import { ROOT } from '../lib/paths.mjs';

const ENDPOINT = { protein: '/tools/submit-protein.php', 'diabetes-risk': '/tools/submit.php' };
const post = (s, inputs, ip) => fetch(s.url + ENDPOINT[inputs.tool], { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: 'https://lifelinedaily.com', 'X-Forwarded-For': ip }, body: JSON.stringify(inputs) });
const logLines = (s) => s.phpLog().map((l) => l.slice(l.indexOf('{'))).filter((l) => l.startsWith('{"ts"')).map((l) => JSON.parse(l));

describe('TC-NFR1-16 logging and retention', () => {
  let srv;
  beforeAll(async () => { srv = await startHtaccessServer(); });
  afterAll(async () => { await srv?.close(); });

  test('TC-NFR1-16-AS-01', async () => {
    for (const id of ['FX-MAIL-01', 'FX-MAIL-02', 'FX-MAIL-NEG-03']) expect((await post(srv, fixtures(id)[0].inputs, '10.16.0.1')).status).toBe(200);
    const lines = logLines(srv);
    expect(lines.length).toBeGreaterThanOrEqual(3);
    const dir = mkdtempSync(join(tmpdir(), 'lld-log-'));
    try {
      lines.forEach((l, i) => {
        const f = join(dir, `line-${i}.json`); writeFileSync(f, JSON.stringify(l));
        const r = validateFile('endpoint-log.schema.json', f);
        expect(r.ok, `line ${i} ${JSON.stringify(l)}: ${r.errors.join('; ')}`).toBe(true);
      });
    } finally { rmSync(dir, { recursive: true, force: true }); }
    expect(new Set(lines.map((l) => l.outcome))).toEqual(new Set(['sent', 'honeypot']));
  });

  test('TC-NFR1-16-AS-02', (ctx) => {
    // Retention: the endpoints write only through PHP's error_log, which on the shared host is the account error log; its
    // retention is a hosting setting, not a file in this repository. The repository records the 30-day rule and where it is
    // enforced in privacy/log-retention.json once DEC (proposed in the NFR1-16 PR) names the mechanism. Until then the
    // artefact check runs against that file when present and skips with the reason otherwise.
    const f = resolve(ROOT, 'privacy', 'log-retention.json');
    if (!existsSync(f)) { ctx.skip('retention artefact privacy/log-retention.json pending the DEC on the hosting mechanism (shared hosting has no logrotate; outside CMP-04/05)'); return; }
    const rec = JSON.parse(readFileSync(f, 'utf8'));
    expect(rec.retention_days).toBe(30);
    expect(rec.mechanism).toBeTruthy();
  });

  test('TC-NFR1-16-AS-03', async () => {
    // same IP, salt for day N vs N+1 → different ip_hash (and the same day → the same hash)
    const dayN = await startHtaccessServer({ phpConfig: { logDay: '2026-10-23' } });
    const dayN1 = await startHtaccessServer({ phpConfig: { logDay: '2026-10-24' } });
    try {
      const f = fixtures('FX-MAIL-01')[0].inputs;
      for (const s of [dayN, dayN1]) { expect((await post(s, f, '10.16.1.1')).status).toBe(200); expect((await post(s, f, '10.16.1.1')).status).toBe(200); }
      const [a, b] = logLines(dayN).map((l) => l.ip_hash); const [c] = logLines(dayN1).map((l) => l.ip_hash);
      expect(a).toMatch(/^[0-9a-f]{64}$/); expect(a, 'same day, same IP → same hash').toBe(b);
      expect(c, 'next day, same IP → different hash').not.toBe(a);
    } finally { await dayN.close(); await dayN1.close(); }
  }, 120000);
});
