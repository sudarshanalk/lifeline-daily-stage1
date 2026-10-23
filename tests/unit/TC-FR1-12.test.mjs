// TC-FR1-12 Diabetes interpretation and secondary indicators — unit + static families. Fixtures: FX-DM-BAND-01…12,
// IDRS-01…06, WHTR-01…04, CONC-01/02, NOWAIST-01. Mutations: MUT-06, MUT-08, MUT-09. e2e parts (AS-04, AS-05, AS-06, AS-08)
// are in tests/e2e/TC-FR1-12.spec.mjs. AS-01 also has an e2e part there.
import { beforeAll, describe, expect, test } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { ROOT, toolPath } from '../lib/paths.mjs';
import { fixtures } from '../lib/fixtures.mjs';
import { diabetesRunner, loadPage } from '../lib/tool-page.mjs';
import orc03 from '../../oracles/orc-03-idrs/results.json' with { type: 'json' };
import orc04 from '../../oracles/orc-04-whtr/results.json' with { type: 'json' };
import hg01 from '../../approvals/hg-01.json' with { type: 'json' };

const o3 = Object.fromEntries(orc03.results.map((r) => [r.fixture_id, r.expected.idrs]));
const o4 = Object.fromEntries(orc04.results.map((r) => [r.fixture_id, r.expected.whtr]));
let run, bandFor;
beforeAll(() => { run = diabetesRunner(); bandFor = loadPage('diabetes').eval('bandFor'); });

describe('TC-FR1-12 interpretation and secondary indicators', () => {
  test('TC-FR1-12-AS-01', (ctx) => {
    // BAND-01…12 map to the band of the ORC-01 unrounded percentage (DEC-04); inputs are engineered by the ORC-01 runner
    const bands = fixtures('FX-DM-BAND-*');
    expect(bands).toHaveLength(12);
    const filled = bands.filter((f) => typeof f.inputs.weightKg === 'number' && f.expected);
    for (const f of filled) {
      const got = run(f.inputs);
      expect(got.band, f.id).toBe(f.expected.band);
      expect(bandFor(got.pct), `${f.id} classified on the unrounded pct`).toBe(f.expected.band);
    }
    if (filled.length < 12) {
      expect(hg01.deferred_items.some((d) => /BAND/.test(d))).toBe(true);
      return ctx.skip(`BAND inputs pending the ORC-01 weight search (OI-4; ${filled.length}/12 filled) — deferred to HG-02 in approvals/hg-01.json`);
    }
  });

  test('TC-FR1-12-AS-02', () => {
    // IDRS-01…06 → scores 20/30/50/60/0/70; tiers low/moderate/moderate/high/low/high per ORC-03 (activity item is characterisation, DEC-05)
    const want = { 'FX-DM-IDRS-01': [20, 'low'], 'FX-DM-IDRS-02': [30, 'moderate'], 'FX-DM-IDRS-03': [50, 'moderate'], 'FX-DM-IDRS-04': [60, 'high'], 'FX-DM-IDRS-05': [0, 'low'], 'FX-DM-IDRS-06': [70, 'high'] };
    for (const f of fixtures('FX-DM-IDRS-*')) {
      const got = run(f.inputs);
      expect([got.idrs.score, got.idrs.tier], f.id).toEqual(want[f.id]);
      expect(got.idrs, `${f.id} vs ORC-03`).toEqual(o3[f.id]);
      expect(got, `${f.id} characterisation`).toEqual(f.expected);
    }
  });

  test('TC-FR1-12-AS-03', () => {
    // WHTR-01…04 (0.49/0.50/0.59/0.60) → healthy/raised/raised/high per ORC-04
    const want = { 'FX-DM-WHTR-01': 'healthy', 'FX-DM-WHTR-02': 'raised', 'FX-DM-WHTR-03': 'raised', 'FX-DM-WHTR-04': 'high' };
    for (const f of fixtures('FX-DM-WHTR-*')) {
      const got = run(f.inputs);
      expect(got.whtr.band, f.id).toBe(want[f.id]);
      expect(got.whtr, `${f.id} vs ORC-04`).toEqual(o4[f.id]);
      expect(got, `${f.id} characterisation`).toEqual(f.expected);
    }
  });

  test('TC-FR1-12-AS-07', () => {
    // no "waist-hip" / "waist hip" / WHR anywhere in CMP-03 or the landing-page double (the WordPress pages are crawled with LLD_WP_URL)
    const files = [toolPath('diabetes'), resolve(ROOT, 'tests', 'e2e', 'harness', 'landing.html'), resolve(ROOT, 'site', 'public_html', 'tools', 'health-tools-hub.html')];
    for (const f of files) {
      const text = readFileSync(f, 'utf8');
      expect(text, f).not.toMatch(/waist[- ]hip/i);
      expect(text.replace(/WHtR/g, ''), `${f}: WHR (waist-hip ratio) must not appear`).not.toMatch(/\bWHR\b/);
    }
    for (const f of readdirSync(resolve(ROOT, 'site', 'public_html', 'tools', 'api')).filter((x) => x.endsWith('.php'))) {
      expect(readFileSync(resolve(ROOT, 'site', 'public_html', 'tools', 'api', f), 'utf8'), f).not.toMatch(/waist[- ]hip/i);
    }
  });

  test('TC-FR1-12-AS-09', () => {
    // bandFor on unrounded values at the boundaries (DEC-04); MUT-06 detector
    expect(bandFor(5.59)).toBe('low');
    expect(bandFor(5.6)).toBe('moderate');
    expect(bandFor(9.99)).toBe('moderate');
    expect(bandFor(10.0)).toBe('high');
    expect(bandFor(19.99)).toBe('high');
    expect(bandFor(20.0)).toBe('veryHigh');
    // a displayed "5.6%" can still be Low when the raw value is 5.55–5.599 (DEC-04, for the clinician at HG-02)
    expect(bandFor(5.55)).toBe('low'); expect((5.55).toFixed(1)).toBe('5.5'); expect(bandFor(5.5999)).toBe('low'); expect((5.5999).toFixed(1)).toBe('5.6');
  });
});
