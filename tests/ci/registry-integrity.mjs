#!/usr/bin/env node
// registry-integrity (docs/04 §1, SCH-01, DEC-38): the Decision Register table and the registry `decisions[]` agree on
// id, status, ratify_at and decided_on; every requirement's decisions[] names existing rows; statuses are valid.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ROOT } from '../lib/paths.mjs';

export function registryIntegrity({ root = ROOT } = {}) {
  const registry = JSON.parse(readFileSync(resolve(root, 'registry', 'Stage1_Requirements_Registry_v1_2.json'), 'utf8'));
  const doc = readFileSync(resolve(root, 'docs', '05_Decision_Register_v1_2.md'), 'utf8');
  const problems = [];
  const rows = {};
  for (const line of doc.split('\n')) {
    const m = /^\| (DEC-\d{2}) \|(.*)\|\s*$/.exec(line);
    if (!m) continue;
    const cells = m[2].split('|').map((c) => c.trim());
    rows[m[1]] = { ratify_at: cells[4] || '' };
  }
  const hg01 = JSON.parse(readFileSync(resolve(root, 'approvals', 'hg-01.json'), 'utf8'));
  for (const d of registry.decisions) {
    const row = rows[d.id];
    if (!row) { problems.push(`${d.id} in registry but not in the register table`); continue; }
    const docGate = (row.ratify_at.match(/HG-0[123]/) || [null])[0];
    if ((d.ratify_at || null) !== docGate) problems.push(`${d.id} ratify_at ${d.ratify_at} != register ${docGate}`);
    if (!['default', 'ratified', 'overridden', 'open'].includes(d.status)) problems.push(`${d.id} status ${d.status} invalid`);
    if (d.ratify_at === 'HG-01' && hg01.decision === 'approved' && hg01.ratified_decisions.includes(d.id) && d.status !== 'ratified') problems.push(`${d.id} ratified at HG-01 but status ${d.status}`);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(d.decided_on || '')) problems.push(`${d.id} decided_on missing`);
  }
  for (const id of Object.keys(rows)) if (!registry.decisions.find((d) => d.id === id)) problems.push(`${id} in the register table but not in the registry`);
  for (const r of registry.requirements) {
    for (const id of r.decisions || []) if (!registry.decisions.find((d) => d.id === id)) problems.push(`${r.id} references unknown ${id}`);
    for (const dep of r.depends_on || []) if (dep !== '*MUST*' && !registry.requirements.find((x) => x.id === dep)) problems.push(`${r.id} depends on unknown ${dep}`);
  }
  return { ok: problems.length === 0, problems, decisions: registry.decisions.length };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const r = registryIntegrity();
  for (const p of r.problems) console.error('registry-integrity: ' + p);
  console.log(r.ok ? `registry-integrity: OK (${r.decisions} decisions)` : 'registry-integrity: FAIL');
  process.exit(r.ok ? 0 : 1);
}
