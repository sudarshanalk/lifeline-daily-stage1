// Mutation support (docs/03 §3). Patches live in tests/mutations/MUT-NN.patch (unified diff, repo-relative paths).
//   withMutation('MUT-04', (root) => …)  → copies the touched files into a temp tree, applies the patch
//                                           there, runs the callback with that root, then deletes the tree.
//   node tests/lib/mutate.mjs apply MUT-04 | revert MUT-04   → `make mutate` / `make unmutate` on the real tree.
import { spawnSync } from 'node:child_process';
import { cpSync, mkdtempSync, mkdirSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ROOT } from './paths.mjs';

export const patchPath = (id) => resolve(ROOT, 'tests', 'mutations', `${id}.patch`);

/** Repo-relative files a patch touches (from its `+++ b/<path>` headers). */
export function patchedFiles(id) {
  return [...readFileSync(patchPath(id), 'utf8').matchAll(/^\+\+\+ b\/(.+)$/gm)].map((m) => m[1].trim());
}

function gitApply(cwd, patch, reverse = false) {
  const args = ['apply', '--whitespace=nowarn', ...(reverse ? ['-R'] : []), patch];
  const r = spawnSync('git', args, { cwd, encoding: 'utf8' });
  if (r.status !== 0) throw new Error(`git apply failed: ${r.stderr || r.stdout}`);
}

/** Temp copy of the files + any extra paths you need (fixtures, manifests…), with the mutation applied. */
export function mutatedTree(id, extraPaths = []) {
  const root = mkdtempSync(resolve(tmpdir(), `lld-${id}-`));
  for (const rel of [...patchedFiles(id), ...extraPaths]) {
    mkdirSync(dirname(resolve(root, rel)), { recursive: true });
    cpSync(resolve(ROOT, rel), resolve(root, rel), { recursive: true });
  }
  gitApply(root, patchPath(id));
  return root;
}

export async function withMutation(id, fn, extraPaths = []) {
  const root = mutatedTree(id, extraPaths);
  try { return await fn(root); } finally { rmSync(root, { recursive: true, force: true }); }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const [cmd, id] = process.argv.slice(2);
  if (!['apply', 'revert'].includes(cmd) || !/^MUT-\d{2}$/.test(id || '')) {
    console.error('usage: node tests/lib/mutate.mjs apply|revert MUT-NN'); process.exit(2);
  }
  gitApply(ROOT, patchPath(id), cmd === 'revert');
  console.log(`${id} ${cmd === 'apply' ? 'applied' : 'reverted'}: ${patchedFiles(id).join(', ')}`);
}
