// TC-FR1-22 Independent oracles — families ci + artefact. Fixtures: FX-PR-*, FX-DM-* (scope per registry oracles[]).
import { describe, expect, test } from 'vitest';
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, readFileSync, mkdtempSync, cpSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { ROOT } from '../lib/paths.mjs';
import { fixtureIds } from '../lib/fixtures.mjs';
import { validateFile } from '../lib/validate.mjs';
import registry from '../../registry/Stage1_Requirements_Registry_v1_2.json' with { type: 'json' };
import sessionLog from '../../oracles/orc-02-protein-ref/session-log.json' with { type: 'json' };
import primarySources from '../../oracles/orc-02-protein-ref/primary-sources.json' with { type: 'json' };
import hg01 from '../../approvals/hg-01.json' with { type: 'json' };

const py = () => ['python3', 'python'].find((c) => spawnSync(c, ['--version'], { encoding: 'utf8' }).status === 0);
const ORACLE_DIR = { 'ORC-01': 'orc-01-qdiabetes-r', 'ORC-02': 'orc-02-protein-ref', 'ORC-03': 'orc-03-idrs', 'ORC-04': 'orc-04-whtr' };
const resultsPath = (id) => resolve(ROOT, 'oracles', ORACLE_DIR[id], 'results.json');
// Long-running child (the ORC-01 container build takes about a minute): run it asynchronously so the vitest worker can
// keep answering its RPC calls; a synchronous spawn starves it ("Timeout calling onTaskUpdate").
const runAsync = (cmd, args, opts) => new Promise((done) => {
  const c = spawn(cmd, args, opts); let out = '';
  c.stdout.on('data', (d) => { out += d; }); c.stderr.on('data', (d) => { out += d; });
  c.on('close', (status) => done({ status, out }));
});
const dockerReady = () => spawnSync('docker', ['info'], { encoding: 'utf8', timeout: 20000 }).status === 0;

/** Expand a registry scope entry ("FX-DM-QD-*", "FX-PR-* except INV") to fixture ids. */
function scopeIds(scope) {
  const ids = new Set();
  for (const s of scope) {
    const [pat, except] = s.split(/\s+except\s+/);
    for (const id of fixtureIds(pat.trim())) if (!except || !id.includes(`-${except.trim()}-`)) ids.add(id);
  }
  return ids;
}

describe('TC-FR1-22 independent oracles', () => {
  test('TC-FR1-22-AS-01', () => {
    // `make oracle ORC=02|03|04` (oracles/run.py) succeeds from a clean checkout and regenerates results.json byte-for-byte
    // apart from run_at; ORC-01 needs the Docker daemon (known HG-01 deferral, OI-4) — see the separate ORC-01 step below.
    const clean = mkdtempSync(join(tmpdir(), 'lld-oracles-'));
    try {
      for (const d of ['oracles', 'fixtures', 'schemas']) cpSync(resolve(ROOT, d), join(clean, d), { recursive: true });
      for (const n of ['02', '03', '04']) {
        const before = JSON.parse(readFileSync(join(clean, 'oracles', ORACLE_DIR[`ORC-${n}`], 'results.json'), 'utf8'));
        const r = spawnSync(py(), ['-I', join(clean, 'oracles', 'run.py'), n], { cwd: clean, encoding: 'utf8' });
        expect(r.status, `ORC-${n}: ${r.stdout}${r.stderr}`).toBe(0);
        const after = JSON.parse(readFileSync(join(clean, 'oracles', ORACLE_DIR[`ORC-${n}`], 'results.json'), 'utf8'));
        expect(after.results, `ORC-${n}: results reproduced`).toEqual(before.results);
        expect(after.source_hash, `ORC-${n}: source hash reproduced`).toBe(before.source_hash);
      }
    } finally { rmSync(clean, { recursive: true, force: true }); }
  });

  test('TC-FR1-22-AS-01 ORC-01 container', async (ctx) => {
    if (!dockerReady()) {
      // the dispatcher must say so explicitly (exit 3), and the deferral must be the one HG-01 signed
      const r = spawnSync(py(), ['-I', resolve(ROOT, 'oracles', 'run.py'), '01'], { cwd: ROOT, encoding: 'utf8' });
      expect(r.status).toBe(3);
      expect(hg01.deferred_items.some((d) => /ORC-01/.test(d))).toBe(true);
      return ctx.skip('Docker daemon unavailable on this machine (OI-4); ORC-01 scaffold only — deferred to HG-02 in approvals/hg-01.json');
    }
    const pins = JSON.parse(readFileSync(resolve(ROOT, 'oracles', 'orc-01-qdiabetes-r', 'pins.json'), 'utf8'));
    if (!pins.tarball_sha256) return ctx.skip('Docker available but ORC-01 pins not yet recorded (first-run checklist in oracles/orc-01-qdiabetes-r/README.md)');
    const r = await runAsync('bash', [resolve(ROOT, 'oracles', 'orc-01-qdiabetes-r', 'run.sh'), ROOT], { cwd: ROOT });
    expect(r.status, r.out).toBe(0);
    expect(existsSync(resultsPath('ORC-01'))).toBe(true);
  }, 900000);

  test('TC-FR1-22-AS-02', () => {
    // the sealed ORC-02 session log lists every file read; none may be *.html or *.js
    expect(Array.isArray(sessionLog.files_read)).toBe(true);
    expect(sessionLog.files_read.length).toBeGreaterThan(0);
    for (const f of [...sessionLog.files_read, ...(sessionLog.files_written || [])]) expect(f, 'sealed session touched tool source').not.toMatch(/\.(html?|js|mjs|php)$/i);
    expect(sessionLog.sealing_rule).toMatch(/\.html/);
    expect(sessionLog.fixture_expected_values_ignored).toBe(true);
  });

  test('TC-FR1-22-AS-03', () => {
    // each results.json validates SCH-06 and covers exactly the fixture ids in its registry scope
    for (const o of registry.oracles) {
      if (o.id === 'ORC-01' && !existsSync(resultsPath('ORC-01'))) continue; // pending Docker (OI-4), asserted in AS-01 ORC-01 step
      const v = validateFile('oracle-result.schema.json', resultsPath(o.id));
      expect(v.errors, `${o.id} SCH-06`).toEqual([]);
      const res = JSON.parse(readFileSync(resultsPath(o.id), 'utf8'));
      const got = new Set(res.results.map((r) => r.fixture_id));
      const want = scopeIds(o.scope);
      // set equality on the in-scope ids; the sealed ORC-02 also echoes the out-of-scope INV fixtures (oracle: none) as
      // {error:true} rows, tolerated only because the registry marks them oracle:none and they are known fixture ids
      const outOfScopeAllowed = new Set(fixtureIds(o.id === 'ORC-02' ? 'FX-PR-*' : 'FX-DM-*').filter((id) => !want.has(id)));
      const inScopeGot = [...got].filter((id) => !outOfScopeAllowed.has(id));
      expect(inScopeGot.sort(), `${o.id} covers its scope exactly`).toEqual([...want].sort());
      for (const extra of [...got].filter((id) => outOfScopeAllowed.has(id))) expect(fixtureIds(extra).length, `${o.id} extra row ${extra} is a known fixture`).toBe(1);
      for (const r of res.results) expect(r.expected, `${o.id} ${r.fixture_id} expected`).toBeTypeOf('object');
    }
    expect(existsSync(resultsPath('ORC-01')) || hg01.deferred_items.some((d) => /ORC-01/.test(d))).toBe(true);
  });

  test('TC-FR1-22-AS-04', () => {
    // versions and source hashes recorded; ORC-01 pins; ORC-02 ships primary-sources.json for TC-FR1-07-AS-08
    for (const id of ['ORC-02', 'ORC-03', 'ORC-04']) {
      const res = JSON.parse(readFileSync(resultsPath(id), 'utf8'));
      expect(res.versions.python, `${id} python version`).toMatch(/^\d+\.\d+/);
      expect(res.source_hash, `${id} source hash`).toMatch(/^[0-9a-f]{64}$/);
    }
    const pins = JSON.parse(readFileSync(resolve(ROOT, 'oracles', 'orc-01-qdiabetes-r', 'pins.json'), 'utf8'));
    expect(pins.version).toBe('1.0-2');
    expect(pins.source).toMatch(/cran\.r-project\.org\/src\/contrib\/Archive\/QDiabetes\/QDiabetes_1\.0-2\.tar\.gz$/);
    expect(pins).toHaveProperty('tarball_sha256'); // null until the first run; the R run records it in results.versions
    if (existsSync(resultsPath('ORC-01'))) {
      const r1 = JSON.parse(readFileSync(resultsPath('ORC-01'), 'utf8'));
      expect(r1.versions.R).toMatch(/^4\./); expect(r1.versions.QDiabetes).toBe('1.0-2'); expect(r1.versions.tarball_sha256).toMatch(/^[0-9a-f]{64}$/);
    }
    expect(primarySources.sources.length).toBeGreaterThanOrEqual(6);
    expect(primarySources.sources.map((s) => s.guideline).join(' ')).toMatch(/IOM/);
    expect(primarySources.sources.map((s) => s.guideline).join(' ')).toMatch(/ISSN/);
    expect(primarySources.ceiling_g_per_kg).toBe(2.0);
  });
});
