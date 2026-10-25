import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, test } from 'vitest';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const workflow = readFileSync(resolve(root, '.github/workflows/ci-guards.yml'), 'utf8');

function jobBlock(name) {
  const start = workflow.indexOf(`\n  ${name}:`);
  if (start < 0) return '';
  const next = workflow.slice(start + 1).search(/\n  [a-z0-9-]+:/);
  return next < 0 ? workflow.slice(start) : workflow.slice(start, start + 1 + next);
}

describe('TC-NFR2-10-AS-01', () => {
  test('TC-NFR2-10-AS-01', () => {
    for (const name of ['engine-freeze-objects', 'copy-hash', 'saf', 'provenance-check', 'oracle-guard-expected']) {
      const block = jobBlock(name);
      expect(block, name).not.toBe('');
      expect(block, name).not.toMatch(/S2-/);
    }
    const e2e = jobBlock('stage2-e2e');
    const unit = jobBlock('stage2-tests');
    expect(e2e).not.toBe('');
    expect(unit).not.toBe('');
    expect(e2e).not.toMatch(/startsWith\(github\.event\.pull_request\.title[^)]*'S2-/);
    expect(unit).not.toMatch(/startsWith\(github\.event\.pull_request\.title[^)]*'S2-/);
    const setup = readFileSync(resolve(root, 'tests/ci/stage2-wp.sh'), 'utf8');
    expect(setup).toMatch(/paid-memberships-pro/);
    expect(setup).toMatch(/wp lld-members db-update/);
  });
});
