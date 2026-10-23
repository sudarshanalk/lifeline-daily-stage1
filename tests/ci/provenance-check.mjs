#!/usr/bin/env node
// provenance-check (docs/04 §1, §7; FR1-06 AS-02).
// Compares the staged tool files with the methodology approval records (SCH-03) and the manifests (SCH-02):
//   * sha256(site/public_html/tools/<tool>.html) must equal approvals/<slug>.json.version_hash
//   * the same hash must be the is_live version in manifests/<slug>.json
//   * clinical_signoff.decision: `pending` or `approved` is accepted on staging/PR; `approved` is required
//     when LLD_ENV=production (release tags).
// Usage: node tests/ci/provenance-check.mjs [--root <dir>] [--env staging|production]
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { sha256File } from '../lib/hash.mjs';
import { ROOT, TOOL_FILES } from '../lib/paths.mjs';

export function provenanceCheck({ root = ROOT, env = process.env.LLD_ENV || 'staging' } = {}) {
  const problems = [];
  const accepted = env === 'production' ? ['approved'] : ['pending', 'approved'];
  for (const slug of ['protein', 'diabetes']) {
    let approval, manifest;
    try { approval = JSON.parse(readFileSync(resolve(root, 'approvals', `${slug}.json`), 'utf8')); }
    catch (e) { problems.push(`${slug}: approvals/${slug}.json unreadable (${e.message})`); continue; }
    try { manifest = JSON.parse(readFileSync(resolve(root, 'manifests', `${slug}.json`), 'utf8')); }
    catch (e) { problems.push(`${slug}: manifests/${slug}.json unreadable (${e.message})`); continue; }
    const staged = sha256File(resolve(root, 'site', 'public_html', 'tools', TOOL_FILES[slug]));
    if (staged !== approval.version_hash) problems.push(`${slug}: staged hash ${staged} != approved version_hash ${approval.version_hash}`);
    const live = (manifest.versions || []).find((v) => v.is_live);
    if (!live) problems.push(`${slug}: manifest has no is_live version`);
    else if (live.version_hash !== staged) problems.push(`${slug}: staged hash ${staged} != manifest live version ${live.version_hash}`);
    const decision = approval.clinical_signoff?.decision;
    if (!accepted.includes(decision)) problems.push(`${slug}: clinical_signoff.decision '${decision}' not accepted for ${env} (need ${accepted.join('|')})`);
  }
  return { ok: problems.length === 0, problems, env };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const args = process.argv.slice(2);
  const opt = (k) => { const i = args.indexOf(k); return i >= 0 ? args[i + 1] : undefined; };
  const r = provenanceCheck({ root: opt('--root') ? resolve(opt('--root')) : ROOT, env: opt('--env') });
  for (const p of r.problems) console.error('provenance-check: ' + p);
  console.log(r.ok ? `provenance-check: OK (${r.env})` : 'provenance-check: FAIL');
  process.exit(r.ok ? 0 : 1);
}
