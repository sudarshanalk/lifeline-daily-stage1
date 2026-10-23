// Mandatory safety regression — diabetes (docs/03 §7). Runs on every PR.
// Replays fixtures through the deployed engine (jsdom) and requires the characterisation `expected` exactly.
// Proves "unchanged since the 23 Oct 2026 baseline", never clinical correctness (docs/04 §9).
import { beforeAll, describe, expect, test } from 'vitest';
import { existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { fixtures } from '../lib/fixtures.mjs';
import { diabetesRunner } from '../lib/tool-page.mjs';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';

let run;
beforeAll(() => { run = diabetesRunner(); });

function replay(patterns, extra = () => {}) {
  const fxs = patterns.flatMap((p) => fixtures(p));
  const recorded = fxs.filter((f) => f.oracle === 'characterisation' && f.expected);
  expect(recorded.length, 'fixtures with recorded characterisation values').toBeGreaterThan(0);
  for (const f of recorded) {
    const got = run(f.inputs);
    expect(got, f.id).toEqual(f.expected);
    extra(f, got);
  }
  return recorded;
}

describe('SAF diabetes', () => {
  test('SAF-DM-01', () => {
    // Emergency: FX-DM-EMG-01/02 — emergency flag set and the on-screen score suppressed
    replay(['FX-DM-EMG-*'], (f, got) => { expect(got.emergency, f.id).toBe(true); expect(got.scoreSuppressedOnScreen, f.id).toBe(true); });
  });
  test('SAF-DM-02', () => {
    // Classic symptoms: FX-DM-SYM-01…03 — symptomatic flag follows the classic-symptom rule
    replay(['FX-DM-SYM-*'], (f, got) => { expect(typeof got.symptomatic, f.id).toBe('boolean'); });
  });
  test('SAF-DM-03', () => {
    // Band boundaries: FX-DM-BAND-01…12 are clinical fixtures filled by ORC-01 (pending Docker, OI-4);
    // until then the boundary rule is checked on the recorded QD fixtures: band must follow the unrounded pct (DEC-04)
    const bands = fixtures('FX-DM-BAND-*');
    const filled = bands.filter((f) => f.expected);
    for (const f of filled) expect(run(f.inputs), f.id).toEqual(f.expected);
    replay(['FX-DM-QD-*', 'FX-DM-QD-CLAMP-*'], (f, got) => {
      const want = got.pct < 5.6 ? 'low' : got.pct < 10 ? 'moderate' : got.pct < 20 ? 'high' : 'veryHigh';
      expect(got.band, f.id).toBe(want);
    });
  });
  test('SAF-DM-04', () => {
    // Secondary indicators: IDRS, WHtR, CONC, NOWAIST
    replay(['FX-DM-IDRS-*', 'FX-DM-WHTR-*', 'FX-DM-CONC-*', 'FX-DM-NOWAIST-*'], (f, got) => {
      if (f.inputs.waistCm) { expect(got.idrs, f.id).toBeDefined(); expect(got.whtr, f.id).toBeDefined(); }
      else { expect(got.idrs, f.id).toBeUndefined(); expect(got.whtr, f.id).toBeUndefined(); }
    });
  });
  test('SAF-CROSS-01', async () => {
    // Shared change isolation (TC-NFR1-04-AS-03): after any change to the shared mail layer both tools' result e-mails
    // still go through — FX-MAIL-01 (protein) and FX-MAIL-02 (diabetes) each produce exactly one message via the real endpoints.
    const srv = await startHtaccessServer();
    try {
      const outboxDir = join(srv.docroot, 'tools', 'api', 'outbox');
      const outbox = () => existsSync(outboxDir) ? readdirSync(outboxDir).filter((f) => !f.startsWith('.')) : [];
      for (const [id, path] of [['FX-MAIL-01', '/tools/submit-protein.php'], ['FX-MAIL-02', '/tools/submit.php']]) {
        const f = fixtures(id)[0];
        const before = outbox().length;
        const r = await fetch(srv.url + path, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: 'https://lifelinedaily.com', 'X-Forwarded-For': '10.4.0.1' }, body: JSON.stringify(f.inputs) });
        expect(r.status, `${id} delivered`).toBe(f.expected.status);
        expect((await r.json()).status, id).toBe('ok');
        expect(outbox().length - before, `${id}: exactly one message`).toBe(f.expected.mails);
      }
    } finally { await srv.close(); }
  }, 60000);
  test.skip('SAF-WITHDRAW-01', () => {
    // Withdrawal (TC-FR1-15-AS-01/02) needs the status.json reader in tools and endpoints — wave 4 (FR1-15)
  });
});
