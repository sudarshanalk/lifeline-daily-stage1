// Stage 2 key journeys (P3-11): NFR3-03, the key-journeys tests run in CI and in the smoke tests after each staging deploy.
import { expect, test } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '../..');
const read = (path) => readFileSync(resolve(root, path), 'utf8');

test('TC-NFR3-03-AS-02', () => {
  // CI runs the key journeys as three browser jobs, named like the Stage 2 ones: chromium, webkit 1 of 2, webkit 2 of 2.
  const workflow = read('.github/workflows/ci-guards.yml');
  const job = workflow.match(/^  stage3-e2e:\n([\s\S]*?)(?=^  [a-z0-9-]+:\n|(?![\s\S]))/m)?.[1] || '';
  expect(job, 'stage3-e2e job').not.toBe('');
  expect(job).toMatch(/name: "stage3-e2e \(\$\{\{ matrix\.browser \}\}\$\{\{ matrix\.parts > 1 && format\(' \{0\} of \{1\}', matrix\.part, matrix\.parts\) \|\| '' \}\}\)"/);
  const names = [...job.matchAll(/\{ browser: (\w+), part: (\d+), parts: (\d+) \}/g)]
    .map(([, browser, part, parts]) => `stage3-e2e (${browser}${Number(parts) > 1 ? ` ${part} of ${parts}` : ''})`);
  expect(names).toEqual(['stage3-e2e (chromium)', 'stage3-e2e (webkit 1 of 2)', 'stage3-e2e (webkit 2 of 2)']);
  expect(job).toMatch(/npx playwright test -c tests\/stage3\/e2e\/playwright\.config\.mjs/);
  // The smoke tests after each staging deploy run the signed-out key journeys.
  const deploy = read('deploy/deploy.sh');
  expect(deploy).toMatch(/^bash deploy\/key-journeys\.sh /m);
  expect(read('deploy/key-journeys.sh')).toMatch(/curl/);
});
