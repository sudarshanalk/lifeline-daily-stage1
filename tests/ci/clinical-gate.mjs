#!/usr/bin/env node
// clinical-gate (docs/04 §1: tag check, "HG-02 artefacts (`decision: approved`)"; NFR1-02, TC-NFR1-02).
// Passes only when the HG-02 gate has been held and recorded:
//   * approvals/protein.json and approvals/diabetes.json: clinical_signoff.{name,date,decision} present, a named reviewer
//     (not the `pending …` placeholder), an ISO date, decision `approved`
//   * approvals/copy.json: reviewer, date, decision `approved` (DEC-25 copy deck)
//   * registry: no DEC row with ratify_at `HG-02` whose status is outside {ratified, overridden}
// The script never writes an artefact; it only reads the records the clinical reviewer signs.
// Usage: node tests/ci/clinical-gate.mjs [--root <dir>]   (exit 0 = gate satisfied, 1 = not satisfied)
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ROOT } from '../lib/paths.mjs';

const ISO = /^\d{4}-\d{2}-\d{2}$/;
/** A real calendar date in YYYY-MM-DD (2026-99-99 is refused). */
const isDate = (v) => { const s = String(v); if (!ISO.test(s)) return false; const d = new Date(`${s}T00:00:00Z`); return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === s; };
const named = (n) => typeof n === 'string' && n.trim().length > 0 && !/^pending\b/i.test(n.trim());
const read = (root, rel, problems) => { try { return JSON.parse(readFileSync(resolve(root, rel), 'utf8')); } catch (e) { problems.push(`${rel} unreadable (${e.message})`); return null; } };

/** AS-01: the methodology approval records carry a named, dated `approved` clinical sign-off. */
export function signoffProblems(root = ROOT) {
  const problems = [];
  for (const slug of ['protein', 'diabetes']) {
    const a = read(root, `approvals/${slug}.json`, problems); if (!a) continue;
    const s = a.clinical_signoff;
    if (!s || typeof s !== 'object') { problems.push(`${slug}: clinical_signoff missing`); continue; }
    for (const k of ['name', 'date', 'decision']) if (!(k in s)) problems.push(`${slug}: clinical_signoff.${k} missing`);
    if ('name' in s && !named(s.name)) problems.push(`${slug}: clinical_signoff.name is not a named reviewer ('${s.name}')`);
    if ('date' in s && !isDate(s.date)) problems.push(`${slug}: clinical_signoff.date '${s.date}' is not a YYYY-MM-DD calendar date`);
    if ('decision' in s && s.decision !== 'approved') problems.push(`${slug}: clinical_signoff.decision is '${s.decision}', not 'approved'`);
  }
  const c = read(root, 'approvals/copy.json', problems);
  if (c) {
    if (!named(c.reviewer)) problems.push(`copy: reviewer is not a named reviewer ('${c.reviewer}')`);
    if (!isDate(c.date)) problems.push(`copy: date '${c.date}' is not a YYYY-MM-DD calendar date`);
    if (c.decision !== 'approved') problems.push(`copy: decision is '${c.decision}', not 'approved'`);
  }
  return problems;
}

/** AS-02: every DEC row due at HG-02 has been ratified or overridden. */
export function ratificationProblems(root = ROOT) {
  const problems = [];
  const r = read(root, 'registry/Stage1_Requirements_Registry_v1_2.json', problems); if (!r) return problems;
  for (const d of r.decisions || []) if (d.ratify_at === 'HG-02' && !['ratified', 'overridden'].includes(d.status)) problems.push(`${d.id}: ratify_at HG-02, status '${d.status}'`);
  return problems;
}

/** The gate counts as held (the checks then apply strictly) as soon as ANY HG-02 artefact carries a decision other than
 *  `pending`, or any of them is missing, unreadable or has no decision: only the untouched all-pending state of the three
 *  records defers the assertions. */
export function gateHeld(root = ROOT) {
  const p = [];
  const decisions = [read(root, 'approvals/protein.json', p)?.clinical_signoff?.decision, read(root, 'approvals/diabetes.json', p)?.clinical_signoff?.decision, read(root, 'approvals/copy.json', p)?.decision];
  return p.length > 0 || decisions.some((d) => d !== 'pending');
}

export function clinicalGate({ root = ROOT } = {}) {
  const problems = [...signoffProblems(root), ...ratificationProblems(root)];
  return { ok: problems.length === 0, problems };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const i = process.argv.indexOf('--root');
  const res = clinicalGate({ root: i > 0 ? resolve(process.argv[i + 1]) : ROOT });
  if (res.ok) console.log('clinical-gate: HG-02 artefacts approved; all HG-02 DEC rows ratified or overridden');
  else { console.error(`clinical-gate: not satisfied (${res.problems.length})`); for (const p of res.problems) console.error(`  - ${p}`); }
  process.exit(res.ok ? 0 : 1);
}
