#!/usr/bin/env node
// fidelity-gate (docs/04 §1, DEC-08; FR1-10 AS-04). Compares the deployed diabetes engine (jsdom) with the ORC-01 results
// for every FX-DM-QD fixture: pass if abs(js − r) ≤ 0.05 percentage points on UNROUNDED values (DEC-02). On any
// disagreement for the deployed hash it (a) writes the STAGING status.json with diabetes `withdrawn` (message key
// `unavailable.diabetes`, since = now) — only to the path given by --status (staging over SSH in CI; a sandbox copy in
// tests), never production — (b) prints an ALERT line for ACT-04 and (c) exits 1 so CI opens the DEC row.
// Without an ORC-01 results file it reports the deferral (OI-4) and exits 0 without comparing.
// Usage: node tests/ci/fidelity-gate.mjs [--root <dir>] [--status <path to status.json to write>] [--orc <results.json>]
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ROOT, TOOL_FILES } from '../lib/paths.mjs';
import { diabetesRunner, closeAll } from '../lib/tool-page.mjs';
import { fixtures } from '../lib/fixtures.mjs';
import { sha256File } from '../lib/hash.mjs';

const TOLERANCE = 0.05;
const PRODUCTION_ENV = ['PROD_SSH_HOST', 'PROD_SSH_KEY', 'PROD_SSH_USER', 'PROD_DOCROOT'];

export function fidelityGate({ root = ROOT, orcPath = resolve(root, 'oracles', 'orc-01-qdiabetes-r', 'results.json'), statusPath = null, html } = {}) {
  const leaked = PRODUCTION_ENV.filter((k) => process.env[k]);
  if (leaked.length) return { ok: false, action: 'refused', problems: [`production credentials present in the environment (${leaked.join(', ')}); fidelity-gate must never run with them (DEC-08)`] };
  if (!existsSync(orcPath)) return { ok: true, action: 'deferred', problems: [], note: 'ORC-01 results not present (Docker/WSL 2 pending, OI-4); deferred to HG-02 per approvals/hg-01.json' };
  const orc = JSON.parse(readFileSync(orcPath, 'utf8'));
  const ref = Object.fromEntries(orc.results.filter((r) => r.expected && typeof r.expected.pct === 'number').map((r) => [r.fixture_id, r.expected.pct]));
  const run = diabetesRunner(html ?? readFileSync(resolve(root, 'site', 'public_html', 'tools', TOOL_FILES.diabetes), 'utf8'));
  const compared = []; const problems = [];
  for (const f of fixtures('FX-DM-QD-*')) {
    if (!(f.id in ref)) continue;
    const js = run(f.inputs).pct; const r = ref[f.id]; const diff = Math.abs(js - r);
    compared.push({ fixture: f.id, js, r, diff: +diff.toFixed(6), ok: diff <= TOLERANCE });
    if (diff > TOLERANCE) problems.push(`${f.id}: js ${js} vs ORC-01 ${r} (diff ${diff.toFixed(4)} pp > ${TOLERANCE})`);
  }
  closeAll();
  if (!compared.length) return { ok: true, action: 'deferred', problems: [], note: 'ORC-01 results contain no numeric QD rows yet' };
  if (!problems.length) return { ok: true, action: 'none', problems, compared };
  // disagreement on the deployed hash → withdraw on staging only (DEC-08 (a))
  let action = 'alert-only';
  if (statusPath) {
    const status = JSON.parse(readFileSync(statusPath, 'utf8'));
    const now = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
    status.tools.diabetes = { ...status.tools.diabetes, status: 'withdrawn', message: 'unavailable.diabetes', since: now };
    status.updated_at = now;
    writeFileSync(statusPath, JSON.stringify(status, null, 2) + '\n');
    action = `withdrawn:${statusPath}`;
  }
  const deployed = sha256File(resolve(root, 'site', 'public_html', 'tools', TOOL_FILES.diabetes));
  console.error(`ALERT fidelity-gate: ORC-01 disagreement on deployed diabetes hash ${deployed} — ${problems.length} fixture(s); staging withdrawn, production action by ACT-04 within 4 h (DEC-08)`);
  return { ok: false, action, problems, compared };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const args = process.argv.slice(2);
  const opt = (k) => { const i = args.indexOf(k); return i >= 0 ? args[i + 1] : undefined; };
  const r = fidelityGate({ root: opt('--root') ? resolve(opt('--root')) : ROOT, orcPath: opt('--orc') ? resolve(opt('--orc')) : undefined, statusPath: opt('--status') ? resolve(opt('--status')) : null });
  for (const p of r.problems) console.error('fidelity-gate: ' + p);
  console.log(`fidelity-gate: ${r.ok ? 'OK' : 'FAIL'} (${r.action}${r.note ? ' — ' + r.note : ''}${r.compared ? `; ${r.compared.length} fixtures compared` : ''})`);
  process.exit(r.ok ? 0 : 1);
}
