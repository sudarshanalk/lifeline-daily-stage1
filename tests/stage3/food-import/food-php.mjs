// Runs one food-importer call in PHP CLI with no WordPress.
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const harness = resolve(dirname(fileURLToPath(import.meta.url)), 'harness.php');

export function food(payload) {
  const out = execFileSync('php', [harness], { input: JSON.stringify(payload), encoding: 'utf8' });
  return JSON.parse(out);
}
