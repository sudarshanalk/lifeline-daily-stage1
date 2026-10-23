#!/usr/bin/env node
// Refresh the machine-derived fields of the provenance artefacts from the current tree (FR1-06, DEC-25):
//   approvals/<slug>.json  → version_hash (sha256 of the tool page), copy_hashes (aggregate per source)
//   approvals/copy.json    → per-key hashes of MESSAGES and templates (SCH-16); inline/pages untouched
//   copy/inventory.json    → enumeration of every copy key with its source locator
//   manifests/<slug>.json  → js:* frozen-object hashes on the is_live version (file hashes untouched); a changed
//                            tool page gets a new is_live version entry (LLD_VERSION_NOTE sets its notes)
//   site/public_html/tools/status.json → approved_version per tool (the Git template of the switch)
// Prose fields (intended_use, limitations, sign-off…) are never changed here. Run after any shell change
// to a tool page; the diff is the "version_hash updated in the same PR" evidence the SRS asks for.
// Usage: node tests/ci/refresh-approvals.mjs [--check]   (--check: exit 1 if anything would change)
import { readFileSync, writeFileSync, statSync } from 'node:fs';
import { resolve } from 'node:path';
import { ROOT, TOOL_FILES, toolPath } from '../lib/paths.mjs';
import { sha256, sha256File } from '../lib/hash.mjs';
import { frozenHashes, closeAll } from '../lib/tool-page.mjs';
import { extractCopy, TEMPLATE_FILES } from '../lib/copy.mjs';

const check = process.argv.includes('--check');
const read = (rel) => JSON.parse(readFileSync(resolve(ROOT, rel), 'utf8'));
let changed = [];
function write(rel, obj) {
  const cur = (() => { try { return readFileSync(resolve(ROOT, rel), 'utf8'); } catch { return null; } })();
  // keep the file's existing line-ending convention (manifests were recorded with CRLF; .gitattributes is `-text`)
  const next = cur && cur.includes('\r\n') ? JSON.stringify(obj, null, 2).replace(/\n/g, '\r\n') : JSON.stringify(obj, null, 2) + '\n';
  if (cur === next) return;
  changed.push(rel);
  if (!check) writeFileSync(resolve(ROOT, rel), next);
}

const copy = extractCopy();
for (const slug of ['protein', 'diabetes']) {
  const approval = read(`approvals/${slug}.json`);
  approval.version_hash = sha256File(toolPath(slug));
  // aggregate per source + the per-banner hashes the TC pages compare against (e.g. TC-FR1-09-AS-05 copy_hashes.pregnancy)
  const BANNERS = { protein: ['pregnancy', 'renalStop', 'renalUnsure', 'clinical', 'underweight'], diabetes: ['emergency', 'symptomatic'] };
  const texts = copy.texts.messages[slug];
  approval.copy_hashes = { ...copy.aggregate[slug], ...Object.fromEntries(BANNERS[slug].filter((k) => texts[`${k}.title`] !== undefined).map((k) => [k, sha256(`${texts[`${k}.title`]}\n${texts[`${k}.body`]}`)])) };
  write(`approvals/${slug}.json`, approval);

  const manifest = read(`manifests/${slug}.json`);
  let live = manifest.versions.find((v) => v.is_live);
  const fileHashes = Object.fromEntries(Object.entries(live.engine_hashes).filter(([k]) => !k.startsWith('js:')));
  if (live.version_hash !== approval.version_hash) {
    // shell change: record a new live version (SCH-02) at index 0; the previous one stays as history (is_live false)
    live.is_live = false;
    live = { version_hash: approval.version_hash, captured_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'), is_live: true,
      size_bytes: statSync(toolPath(slug)).size, engine_hashes: {}, notes: process.env.LLD_VERSION_NOTE || 'Shell change; frozen engine objects unchanged (engine-freeze)' };
    manifest.versions.unshift(live);
  }
  live.engine_hashes = { ...fileHashes, ...frozenHashes(slug) };
  write(`manifests/${slug}.json`, manifest);

  // the Git template of the withdrawal switch names the approved version (SCH-08; installed once by hand, DEC-10)
  const statusPath = 'site/public_html/tools/status.json';
  const status = read(statusPath);
  status.tools[slug].approved_version = approval.version_hash;
  write(statusPath, status);
}

// MUT-10 (docs/03 §3): one hex digit of approvals/protein.json version_hash altered — regenerated so it always applies
{
  const cur = readFileSync(resolve(ROOT, 'approvals', 'protein.json'), 'utf8');
  const h = JSON.parse(cur).version_hash; const mutated = (h[0] === '0' ? '1' : '0') + h.slice(1);
  const lines = cur.split('\n'); const idx = lines.findIndex((l) => l.includes(`"version_hash": "${h}"`));
  const ctxB = lines.slice(Math.max(0, idx - 3), idx), ctxA = lines.slice(idx + 1, idx + 4);
  const start = Math.max(0, idx - 3) + 1; const count = ctxB.length + 1 + ctxA.length;
  const patch = ['diff --git a/approvals/protein.json b/approvals/protein.json', '--- a/approvals/protein.json', '+++ b/approvals/protein.json',
    `@@ -${start},${count} +${start},${count} @@`, ...ctxB.map((l) => ' ' + l), '-' + lines[idx], '+' + lines[idx].replace(h, mutated), ...ctxA.map((l) => ' ' + l), ''].join('\n');
  const pp = resolve(ROOT, 'tests', 'mutations', 'MUT-10.patch');
  const curPatch = (() => { try { return readFileSync(pp, 'utf8'); } catch { return null; } })();
  if (curPatch !== patch) { changed.push('tests/mutations/MUT-10.patch'); if (!check) writeFileSync(pp, patch); }
}

const copyRecord = (() => { try { return read('approvals/copy.json'); } catch { return { inline: {}, pages: {}, reviewer: 'pending (OI-8)', date: '2026-10-23', decision: 'pending' }; } })();
copyRecord.messages = copy.messages;
copyRecord.templates = copy.templates;
write('approvals/copy.json', copyRecord);

const inventory = { description: 'DEC-25 copy inventory: every user-facing clinical copy key, where it lives, and its text hash (approvals/copy.json). Generated by tests/ci/refresh-approvals.mjs.', entries: [] };
for (const slug of ['protein', 'diabetes']) {
  for (const key of Object.keys(copy.texts.messages[slug])) inventory.entries.push({ key: `messages.${slug}.${key}`, source: `site/public_html/tools/${TOOL_FILES[slug]}`, locator: `MESSAGES.${key}` });
  for (const key of Object.keys(copy.texts.templates[slug])) inventory.entries.push({ key: `templates.${slug}.${key}`, source: `site/public_html/tools/${TEMPLATE_FILES[slug]}`, locator: key });
}
write('copy/inventory.json', inventory);
closeAll();

if (changed.length) console.log((check ? 'would change: ' : 'updated: ') + changed.join(', '));
else console.log('provenance artefacts are current');
process.exit(check && changed.length ? 1 : 0);
