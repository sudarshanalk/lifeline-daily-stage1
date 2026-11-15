import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { defineConfig, devices } from '@playwright/test';

const testDir = new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');
const outputDir = new URL('../../../test-results/stage3', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');
function secret() {
  if (process.env.LLD_TEST_SECRET) return process.env.LLD_TEST_SECRET;
  if (!process.env.RUNNER_TEMP) return '';
  const path = `${process.env.RUNNER_TEMP}/lld-test-secret`;
  if (!existsSync(path)) writeFileSync(path, randomBytes(16).toString('hex'));
  return readFileSync(path, 'utf8').trim();
}
const testSecret = secret();
export default defineConfig({
  testDir, outputDir, testMatch: ['key-journeys/**/*.spec.mjs', 'e2e/screens/**/*.spec.mjs', 'e2e/analytics/**/*.spec.mjs', 'e2e/admin/**/*.spec.mjs'], timeout: 120000, workers: 1, fullyParallel: false, retries: 0, reporter: 'list',
  use: { baseURL: process.env.LLD_WP_URL || 'http://127.0.0.1:8090', extraHTTPHeaders: testSecret ? { 'X-LLD-Test-Secret': testSecret } : {}, trace: 'retain-on-failure' },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }, { name: 'webkit', use: { ...devices['Desktop Safari'] } }],
});
