// Playwright configuration for the e2e family. Base URLs come from the environment so the same specs run
// against the local stack, CI containers and staging:
//   LLD_TOOLS_URL  origin serving /tools/* (default http://127.0.0.1:8089, tests/lib/htaccess-server.mjs)
//   LLD_WP_URL     WordPress origin for hub/landing pages (default http://localhost:8080, local stack)
// Run: npx playwright test -c tests/e2e/playwright.config.mjs [--grep TC-FR1-21]
import { defineConfig, devices, webkit, firefox } from '@playwright/test';
import { existsSync } from 'node:fs';
// the WordPress specs (FR1-04, FR1-15, UI1-02, …) share one local database and apply/restore page content: never in parallel
const WP_SNAPSHOT = process.env.LLD_WP_SNAPSHOT || 'C:/MyProjects/lifeline-daily/snapshot-production-2026-10-23/public_html';
const wpLocal = !!process.env.LLD_WP_URL || existsSync(WP_SNAPSHOT);
const installed = (b) => { try { return existsSync(b.executablePath()); } catch { return false; } };

export default defineConfig({
  testDir: new URL('.', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'),
  testMatch: /.*\.spec\.mjs/,
  timeout: 60000,
  retries: 0,
  workers: wpLocal ? 1 : undefined,
  reporter: process.env.LLD_PW_JSON ? [['list'], ['json', { outputFile: process.env.LLD_PW_JSON }]] : [['list']],
  use: { trace: 'retain-on-failure' },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    // NFR1-12 / DEC-18 device matrix: only the browser-support spec runs here, and only where the browser is installed
    // (CI installs chromium only; locally `npx playwright install webkit firefox`)
    ...(installed(webkit) ? [{ name: 'webkit', testMatch: /TC-NFR1-12\.spec\.mjs/, use: { ...devices['Desktop Safari'] } }] : []),
    ...(installed(firefox) ? [{ name: 'firefox', testMatch: /TC-NFR1-12\.spec\.mjs/, use: { ...devices['Desktop Firefox'] } }] : []),
    { name: 'pixel5', testMatch: /TC-NFR1-12\.spec\.mjs/, use: { ...devices['Pixel 5'] } },
    ...(installed(webkit) ? [{ name: 'iphone12', testMatch: /TC-NFR1-12\.spec\.mjs/, use: { ...devices['iPhone 12'] } }] : []),
  ],
});
