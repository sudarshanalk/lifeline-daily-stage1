#!/usr/bin/env node
// assertion-map (docs/04 §1, §7): every AS/NEG id on the requirement's TC page exists as a test title somewhere under
// tests/, and the test file imports/loads the fixtures the registry names for that requirement (by id pattern or file).
// Usage: node tests/ci/assertion-map.mjs --req FR1-21   (or --all for every requirement at status >= in_progress)
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ROOT } from '../lib/paths.mjs';

const TC_DOC = resolve(ROOT, 'docs', '03_Test_Criteria_Stage1_v1_2.md');
const ACTIVE = ['in_progress', 'review', 'verified', 'accepted', 'released'];

function walk(dir, out = []) {
  for (const f of readdirSync(dir)) {
    const p = join(dir, f);
    if (f === 'node_modules' || f === 'harness') continue;
    if (statSync(p).isDirectory()) walk(p, out); else if (/\.(test|spec)\.mjs$/.test(f)) out.push(p);
  }
  return out;
}

/** Assertion ids on the TC page for one requirement: TC-<ID>-AS-nn rows plus NEG-nn in that section. */
export function tcAssertions(req, doc = readFileSync(TC_DOC, 'utf8')) {
  const start = doc.indexOf(`### TC-${req} `);
  if (start < 0) return null;
  const rest = doc.slice(start + 4);
  const end = rest.search(/\n### |\n## /);
  const section = rest.slice(0, end < 0 ? undefined : end);
  const ids = new Set([...section.matchAll(new RegExp(`TC-${req}-AS-\\d{2}`, 'g'))].map((m) => m[0]));
  // NEG-nn counts only as a table row of its own (ID column); a prose mention such as "NEG-01/02 → 400" is a fixture reference
  for (const m of section.matchAll(/^\| (NEG-\d{2}) \|/gm)) ids.add(m[1]);
  return { ids: [...ids], section };
}

export function assertionMap({ root = ROOT, req }) {
  const registry = JSON.parse(readFileSync(resolve(root, 'registry', 'Stage1_Requirements_Registry_v1_2.json'), 'utf8'));
  const entry = registry.requirements.find((r) => r.id === req);
  if (!entry) return { ok: false, problems: [`unknown requirement ${req}`] };
  const tc = tcAssertions(req);
  if (!tc) return { ok: false, problems: [`no TC page for ${req} in docs/03`] };
  const files = walk(resolve(root, 'tests')).map((p) => ({ p, text: readFileSync(p, 'utf8') }));
  const problems = [];
  const titleRe = (id) => new RegExp(`test(?:\\.skip|\\.only|\\.todo)?\\(\\s*['"\`]${id.replace(/[-.]/g, '\\$&')}(?:['"\`\\s])`);
  const hits = {};
  for (const id of tc.ids) {
    const where = files.filter((f) => titleRe(id).test(f.text));
    if (!where.length) problems.push(`${id} has no test named by its id`);
    else hits[id] = where.map((f) => f.p);
  }
  // fixtures named by the registry must be referenced (by id pattern, by file, or via the fixtures helper) in those files
  const relevant = files.filter((f) => tc.ids.some((id) => (hits[id] || []).includes(f.p)));
  for (const pat of entry.fixtures || []) {
    const token = pat.replace(/\*$/, '');
    const ok = relevant.some((f) => f.text.includes(token) || f.text.includes(pat));
    if (!ok) problems.push(`fixture ${pat} is not referenced by any ${req} test file`);
  }
  // registry assertions must agree with the TC page
  for (const id of entry.assertions || []) if (!tc.ids.includes(id)) problems.push(`${id} is in the registry but not on the TC page`);
  return { ok: problems.length === 0, problems, ids: tc.ids, hits };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const args = process.argv.slice(2);
  const opt = (k) => { const i = args.indexOf(k); return i >= 0 ? args[i + 1] : undefined; };
  const registry = JSON.parse(readFileSync(resolve(ROOT, 'registry', 'Stage1_Requirements_Registry_v1_2.json'), 'utf8'));
  // FR1-01 is the P-A0 baseline (wave 0): its assertions are artefact/report checks accepted at HG-01, before the test harness existed
  const reqs = args.includes('--all') ? registry.requirements.filter((r) => ACTIVE.includes(r.status) && r.id !== 'FR1-01').map((r) => r.id) : [opt('--req')].filter(Boolean);
  if (!reqs.length) { console.error('usage: --req <ID> | --all'); process.exit(2); }
  let ok = true;
  for (const req of reqs) {
    const r = assertionMap({ req });
    for (const p of r.problems) console.error(`assertion-map ${req}: ${p}`);
    console.log(`assertion-map ${req}: ${r.ok ? 'OK' : 'FAIL'} (${(r.ids || []).length} ids)`);
    ok = ok && r.ok;
  }
  process.exit(ok ? 0 : 1);
}
