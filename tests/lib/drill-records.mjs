// Drill records written by tools/release/drill.mjs (evidence/<REQ>/artefacts/*.json) and fixture lookup, for the
// release artefact tests (tests/artefact/TC-NFR1-08.test.mjs, TC-FR1-19.test.mjs) and the drill specs.
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { ROOT } from './paths.mjs';

export const fx = (dir, file, id) => JSON.parse(readFileSync(resolve(ROOT, 'fixtures', dir, file), 'utf8')).fixtures.find((f) => f.id === id);

/** newest drill records of a kind under evidence/<req>/artefacts/, newest first */
export function records(req, prefix) {
  const dir = join(ROOT, 'evidence', req, 'artefacts');
  if (!existsSync(dir)) return [];
  return readdirSync(dir).filter((f) => f.startsWith(prefix) && f.endsWith('.json')).sort().reverse()
    .map((f) => ({ file: `evidence/${req}/artefacts/${f}`, ...JSON.parse(readFileSync(join(dir, f), 'utf8')) }));
}

