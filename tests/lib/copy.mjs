// Copy deck extraction (DEC-25): MESSAGES objects from both tools and the two PHP template files.
// Produces per-key text hashes (approvals/copy.json, SCH-16) and per-source aggregate hashes
// (approvals/<slug>.json copy_hashes, SCH-03).
import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';
import { TOOLS_DIR } from './paths.mjs';
import { sha256, canonical } from './hash.mjs';
import { messages } from './tool-page.mjs';

export const TEMPLATE_FILES = { protein: 'api/templates-protein.php', diabetes: 'api/templates.php' };

/** Flattened template texts: { "protein_standard.subject": "...", "protein_standard.body": "..." }. */
export function templates(slug, file = TEMPLATE_FILES[slug] && resolve(TOOLS_DIR, TEMPLATE_FILES[slug])) {
  if (!file) return {};   // the Healthy Weight Check sends no email, so it has no templates (D51)
  const r = spawnSync('php', ['-r', 'echo json_encode(require $argv[1]);', file], { encoding: 'utf8' });
  if (r.status !== 0) throw new Error('php failed: ' + r.stderr);
  const obj = JSON.parse(r.stdout);
  const out = {};
  for (const [k, v] of Object.entries(obj)) {
    if (v && typeof v === 'object') for (const [k2, text] of Object.entries(v)) out[`${k}.${k2}`] = String(text);
    else out[k] = String(v);
  }
  return out;
}

const hashEach = (flat, prefix) => Object.fromEntries(Object.entries(flat).map(([k, v]) => [`${prefix}.${k}`, sha256(v)]));

/** Everything the copy-hash job compares: per-key hashes grouped as SCH-16 expects, plus aggregates. */
export function extractCopy({ html = {}, templateFiles = {} } = {}) {
  const msg = { protein: messages('protein', html.protein), diabetes: messages('diabetes', html.diabetes), 'healthy-weight': messages('healthy-weight', html['healthy-weight']) };
  const tpl = { protein: templates('protein', templateFiles.protein), diabetes: templates('diabetes', templateFiles.diabetes), 'healthy-weight': {} };
  return {
    messages: { ...hashEach(msg.protein, 'protein'), ...hashEach(msg.diabetes, 'diabetes'), ...hashEach(msg['healthy-weight'], 'healthy-weight') },
    templates: { ...hashEach(tpl.protein, 'protein'), ...hashEach(tpl.diabetes, 'diabetes') },
    aggregate: {
      protein: { 'messages.protein': sha256(canonical(msg.protein)), 'templates.protein': sha256(canonical(tpl.protein)) },
      diabetes: { 'messages.diabetes': sha256(canonical(msg.diabetes)), 'templates.diabetes': sha256(canonical(tpl.diabetes)) },
      'healthy-weight': { 'messages.healthy-weight': sha256(canonical(msg['healthy-weight'])) },
    },
    texts: { messages: msg, templates: tpl },
  };
}
