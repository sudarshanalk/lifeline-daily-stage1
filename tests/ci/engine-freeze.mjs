#!/usr/bin/env node
// engine-freeze (docs/04 §1, §7; CLAUDE.md hard rules; FR1-06 AS-04).
// Extracts every frozen engine object from the two tool pages (tests/lib/tool-page.mjs FROZEN list),
// hashes its source text and compares with manifests/<slug>.json → versions[is_live].engine_hashes["js:<name>"].
// A mismatch fails unless the PR references a DEC row that is `ratified` with `ratify_at: HG-02`
// (passed as --dec DEC-nn; the registry is the source of truth, DEC-38).
// Usage: node tests/ci/engine-freeze.mjs [--root <dir>] [--dec DEC-nn]
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ROOT, TOOL_FILES } from '../lib/paths.mjs';
import { frozenHashes } from '../lib/tool-page.mjs';

export function engineFreeze({ root = ROOT, dec = null } = {}) {
  const problems = [];
  const compared = {};
  for (const slug of ['protein', 'diabetes']) {
    const manifest = JSON.parse(readFileSync(resolve(root, 'manifests', `${slug}.json`), 'utf8'));
    const live = (manifest.versions || []).find((v) => v.is_live);
    const want = Object.fromEntries(Object.entries(live?.engine_hashes || {}).filter(([k]) => k.startsWith('js:')));
    if (!Object.keys(want).length) { problems.push(`${slug}: manifest records no js:* engine hashes`); continue; }
    const html = readFileSync(resolve(root, 'site', 'public_html', 'tools', TOOL_FILES[slug]), 'utf8');
    let got;
    try { got = frozenHashes(slug, html); } catch (e) { problems.push(`${slug}: extraction failed: ${e.message}`); continue; }
    compared[slug] = got;
    for (const [k, h] of Object.entries(want)) if (got[k] !== h) problems.push(`${slug}: ${k} changed (${got[k]} != manifest ${h})`);
    for (const k of Object.keys(got)) if (!(k in want)) problems.push(`${slug}: ${k} is frozen but not recorded in the manifest`);
  }
  let waived = false;
  if (problems.length && dec) {
    const reg = JSON.parse(readFileSync(resolve(root, 'registry', 'Stage1_Requirements_Registry_v1_2.json'), 'utf8'));
    const row = (reg.decisions || []).find((d) => d.id === dec);
    waived = !!row && row.status === 'ratified' && row.ratify_at === 'HG-02';
    if (!waived) problems.push(`${dec} does not waive the freeze (needs status ratified and ratify_at HG-02)`);
  }
  return { ok: problems.length === 0 || waived, problems, compared, waived };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const args = process.argv.slice(2);
  const opt = (k) => { const i = args.indexOf(k); return i >= 0 ? args[i + 1] : undefined; };
  const r = engineFreeze({ root: opt('--root') ? resolve(opt('--root')) : ROOT, dec: opt('--dec') || null });
  for (const p of r.problems) console.error('engine-freeze: ' + p);
  console.log(r.ok ? (r.waived ? 'engine-freeze: changed, waived by ratified DEC' : 'engine-freeze: OK') : 'engine-freeze: FAIL');
  process.exit(r.ok ? 0 : 1);
}
