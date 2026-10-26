import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';
import { frozenHashes, frozenSources } from '../lib/tool-page.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => readFileSync(resolve(root, rel), 'utf8');

test('TC-FR2-23-AS-01', () => {
  for (const slug of ['protein', 'diabetes']) {
    const live = JSON.parse(read(`manifests/${slug}.json`)).versions.find((version) => version.is_live);
    const hashes = frozenHashes(slug);
    for (const [key, hash] of Object.entries(hashes)) {
      expect(live.engine_hashes[key], key).toBe(hash);
    }
    const sources = Object.values(frozenSources(slug)).join('\n');
    expect(sources).not.toContain('lld-members-link');
    const page = slug === 'protein' ? 'protein-requirement-tool.html' : 'diabetes-risk-tool.html';
    const html = read(`site/public_html/tools/${page}`);
    const includeAt = html.indexOf('<script src="lld-members-link.js"></script>');
    expect(includeAt).toBeGreaterThan(html.lastIndexOf('</script>', includeAt));
  }
  const patch = read('tests/mutations/MUT-10.patch');
  const removed = patch.split('\n').find((line) => line.startsWith('-') && line.includes('version_hash'));
  const added = patch.split('\n').find((line) => line.startsWith('+') && line.includes('version_hash'));
  const hash = (line) => line.match(/[0-9a-f]{64}/)[0];
  const before = hash(removed);
  const after = hash(added);
  expect(before.slice(1)).toBe(after.slice(1));
  expect(before[0]).not.toBe(after[0]);
});
