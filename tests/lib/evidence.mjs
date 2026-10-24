#!/usr/bin/env node
// Build an evidence bundle (SCH-06e) for one requirement from vitest/playwright JSON reports.
// Usage:
//   node tests/lib/evidence.mjs --req FR1-06 --run 20261023-1500 [--vitest out.json] [--playwright out.json]
//        [--red-run local-20261023-1455|null] [--mutations MUT-10:true,MUT-04:true] [--started 2026-10-23T14:55:00Z]
//        [--note "AS-03:skipped:reason"] [--reviewer PASS|FAIL --review-report <path or url>]  → writes evidence/<REQ>/<run>.json
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { ROOT, toolPath } from './paths.mjs';
import { sha256File } from './hash.mjs';
import { fixtureIds } from './fixtures.mjs';

const args = process.argv.slice(2);
const opt = (k, d) => { const i = args.indexOf(k); return i >= 0 ? args[i + 1] : d; };
const req = opt('--req'); const run = opt('--run');
if (!req || !run) { console.error('usage: --req <ID> --run <YYYYMMDD-HHMM> …'); process.exit(2); }

const registry = JSON.parse(readFileSync(resolve(ROOT, 'registry', 'Stage1_Requirements_Registry_v1_2.json'), 'utf8'));
const entry = registry.requirements.find((r) => r.id === req);
if (!entry) { console.error(`unknown requirement ${req}`); process.exit(2); }

const results = new Map();
const idRe = /^(TC-(FR1|NFR1|UI1)-\d{2}-(AS|NEG)-\d{2}|SAF-[A-Z]+-\d{2}|NEG-\d{2})/;
// assertions that may pass but are not accepted until a human gate signs (tests/lib/deferred.json): a pass records as skipped
const DEFERRED = (() => { try { const d = JSON.parse(readFileSync(new URL('./deferred.json', import.meta.url), 'utf8')); delete d._note; return d; } catch { return {}; } })();
const put = (title, status, artefact) => {
  const m = idRe.exec(title.trim()); if (!m) return;
  let outcome = status === 'passed' ? 'pass' : (status === 'skipped' || status === 'pending' || status === 'todo') ? 'skipped' : 'fail';
  if (outcome === 'pass' && DEFERRED[m[1]]) { outcome = 'skipped'; artefact = `deferred: ${DEFERRED[m[1]]}`; }
  const prev = results.get(m[1]);
  // one assertion can report from several tests, families or Playwright projects: a failure anywhere wins, then a skip (a
  // deferred half stays visible, e.g. TC-FR1-22-AS-01's ORC-01 container), and only an all-pass assertion is a pass
  const rank = { fail: 2, skipped: 1, pass: 0 };
  if (!prev || rank[outcome] > rank[prev.outcome]) results.set(m[1], { assertion: m[1], outcome, artefact });
};
if (opt('--vitest')) {
  const v = JSON.parse(readFileSync(opt('--vitest'), 'utf8'));
  for (const f of v.testResults || []) for (const a of f.assertionResults || []) put(a.title, a.status, 'artefacts/vitest.json');
}
if (opt('--playwright')) {
  const walk = (suite) => { for (const s of suite.suites || []) walk(s); for (const sp of suite.specs || []) for (const t of sp.tests || []) put(sp.title, t.results?.at(-1)?.status === 'skipped' ? 'skipped' : (t.status === 'expected' ? 'passed' : 'failed'), 'artefacts/playwright.json'); };
  const p = JSON.parse(readFileSync(opt('--playwright'), 'utf8'));
  for (const s of p.suites || []) walk(s);
}
// characterisation vs clinical labelling (docs/04 §9): fixture-driven assertions are labelled by the fixtures' oracle field
const fx = []; for (const pat of entry.fixtures || []) { try { fx.push(...fixtureIds(pat)); } catch {} }
const oracle = entry.fixtures?.length ? 'characterisation' : 'none';
const ordered = [...(entry.assertions || []), ...[...results.keys()].filter((k) => !(entry.assertions || []).includes(k))];
const out = {
  ev_id: `EV-${req}-${run}`, req_id: req, tc_id: entry.test_case,
  commit: opt('--commit', 'HEAD'),
  tool_hashes: { protein: sha256File(toolPath('protein')), diabetes: sha256File(toolPath('diabetes')) },
  fixtures: [...new Set(fx)],
  results: ordered.map((id) => ({ ...(results.get(id) || { assertion: id, outcome: 'skipped', artefact: 'not run' }), oracle: id.startsWith('SAF-') ? 'characterisation' : oracle })),
  mutations: (opt('--mutations', '') || '').split(',').filter(Boolean).map((s) => { const [id, red] = s.split(':'); return { id, red: red === 'true', run: `local-${run}` }; }),
  red_run: opt('--red-run', 'null') === 'null' ? null : opt('--red-run'),
  green_run: `local-${run}`,
  ...(opt('--reviewer') ? { reviewer: { agent: 'P-C', verdict: opt('--reviewer'), report: opt('--review-report', 'PR review comment') } } : {}),
  human_gate: entry.human_gate ? { id: entry.human_gate, artefact: entry.human_gate === 'HG-02' ? 'approvals/protein.json, approvals/diabetes.json' : `approvals/${entry.human_gate.toLowerCase()}.json`, status: 'pending' } : null,
  started_at: opt('--started', new Date().toISOString().replace(/\.\d{3}Z$/, 'Z')),
  finished_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
};
for (const note of args.filter((_, i) => args[i - 1] === '--note')) {
  const [id, outcome, ...reason] = note.split(':');
  const r = out.results.find((x) => x.assertion === id);
  if (r) { r.outcome = outcome; r.artefact = reason.join(':'); }
}
// commit resolution
if (out.commit === 'HEAD') {
  const { execSync } = await import('node:child_process');
  out.commit = execSync('git rev-parse HEAD', { cwd: ROOT, encoding: 'utf8' }).trim();
}
const dir = resolve(ROOT, 'evidence', req); if (!existsSync(dir)) mkdirSync(dir, { recursive: true });
const file = resolve(dir, `${run}.json`);
writeFileSync(file, JSON.stringify(out, null, 2) + '\n');
console.log(`evidence/${req}/${run}.json`, out.results.map((r) => `${r.assertion}=${r.outcome}`).join(' '));
