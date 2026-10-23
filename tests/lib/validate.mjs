// JSON-schema validation through Python's jsonschema (the same validator CI uses in validate-artefacts).
import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';
import { ROOT } from './paths.mjs';

const PY = resolve(ROOT, 'tests', 'lib', 'validate.py');

function python() {
  for (const cmd of ['python3', 'python']) {
    const r = spawnSync(cmd, ['-I', '-c', 'import jsonschema'], { encoding: 'utf8' });
    if (r.status === 0) return cmd;
  }
  throw new Error('python with jsonschema is required (pip install jsonschema)');
}

/** Returns { ok: boolean, errors: string[] } for `file` against `schemas/<schemaName>`. */
export function validateFile(schemaName, file) {
  const r = spawnSync(python(), ['-I', PY, resolve(ROOT, 'schemas', schemaName), file], { encoding: 'utf8' });
  return { ok: r.status === 0, errors: (r.stdout + r.stderr).trim().split(/\r?\n/).filter(Boolean) };
}
