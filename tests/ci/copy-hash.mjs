#!/usr/bin/env node
// copy-hash (docs/04 §1, §7; DEC-25): the extracted MESSAGES and template texts must hash to what approvals/copy.json
// records, and the per-tool aggregates must equal approvals/<slug>.json copy_hashes. Any difference means user-facing
// clinical copy changed without a new approval record (set copy_changed: true, refresh with tests/ci/refresh-approvals.mjs,
// and route the wording to HG-02).
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ROOT, GUARDED_TOOLS } from '../lib/paths.mjs';
import { extractCopy } from '../lib/copy.mjs';
import { closeAll } from '../lib/tool-page.mjs';

export function copyHash({ root = ROOT } = {}) {
  const problems = [];
  const record = JSON.parse(readFileSync(resolve(root, 'approvals', 'copy.json'), 'utf8'));
  const copy = extractCopy();
  for (const group of ['messages', 'templates']) {
    const want = record[group] || {}, got = copy[group];
    for (const k of new Set([...Object.keys(want), ...Object.keys(got)])) {
      if (!(k in want)) problems.push(`${group}.${k}: new copy key without approval record`);
      else if (!(k in got)) problems.push(`${group}.${k}: approved key no longer present in the source`);
      else if (want[k] !== got[k]) problems.push(`${group}.${k}: text changed (hash ${got[k].slice(0, 12)} != approved ${want[k].slice(0, 12)})`);
    }
  }
  for (const slug of GUARDED_TOOLS) {
    const approval = JSON.parse(readFileSync(resolve(root, 'approvals', `${slug}.json`), 'utf8'));
    for (const [k, v] of Object.entries(copy.aggregate[slug])) if (approval.copy_hashes[k] !== v) problems.push(`approvals/${slug}.json copy_hashes.${k} != extracted`);
  }
  closeAll();
  return { ok: problems.length === 0, problems };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const r = copyHash();
  for (const p of r.problems) console.error('copy-hash: ' + p);
  console.log(r.ok ? 'copy-hash: OK' : 'copy-hash: FAIL (copy changed without approval — DEC-25)');
  process.exit(r.ok ? 0 : 1);
}
