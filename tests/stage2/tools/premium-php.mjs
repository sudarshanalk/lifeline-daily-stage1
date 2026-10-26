// Runs one LLD_Members_Premium method in PHP CLI with the real safety matrix and stub WordPress
// (tests/stage2/tools/premium-stubs.php). Content, consent and the health profile come from the test.
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const STUBS = resolve(dirname(fileURLToPath(import.meta.url)), 'premium-stubs.php');

/**
 * @param {{items?: object, consent?: boolean, profile?: object|null}} world
 * @param {string} call Method name, public or private.
 * @param {...any} args Arguments.
 */
export function premium(world, call, ...args) {
  const input = JSON.stringify({ items: world.items || {}, consent: !!world.consent, profile: world.profile ?? null, defines: world.defines || {}, call, args });
  const out = execFileSync('php', [STUBS], { input, encoding: 'utf8' });
  return JSON.parse(out);
}

/** The same call, returning the raw bytes PHP printed (for binary output such as the PDF). */
export function premiumRaw(world, call, ...args) {
  const input = JSON.stringify({ items: world.items || {}, consent: !!world.consent, profile: world.profile ?? null, call, args, raw: true });
  const json = execFileSync('php', [STUBS], { input });
  return Buffer.from(JSON.parse(json.toString('utf8')), 'base64');
}
