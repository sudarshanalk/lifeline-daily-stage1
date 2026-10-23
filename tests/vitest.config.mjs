// Vitest covers every non-browser family. Playwright (tests/e2e/playwright.config.mjs) covers e2e.
// Run:  npx vitest run -c tests/vitest.config.mjs [tests/<family>/TC-<ID>.test.mjs]
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    root: new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'),
    include: ['tests/{unit,ci,http,static,artefact,saf,php}/**/*.test.mjs'],
    exclude: ['**/node_modules/**', 'tests/e2e/**'],
    testTimeout: 120000,
    hookTimeout: 120000,
    pool: 'forks',
    reporters: process.env.LLD_VITEST_JSON ? ['default', ['json', { outputFile: process.env.LLD_VITEST_JSON }]] : ['default'],
  },
});
