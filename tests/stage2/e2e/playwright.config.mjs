import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { defineConfig, devices } from '@playwright/test';

const testDir = new URL('.', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');

function testSecret() {
  if (process.env.LLD_TEST_SECRET) return process.env.LLD_TEST_SECRET;
  if (!process.env.RUNNER_TEMP) return '';
  const path = `${process.env.RUNNER_TEMP}/lld-test-secret`;
  if (!existsSync(path)) writeFileSync(path, randomBytes(16).toString('hex'));
  return readFileSync(path, 'utf8').trim();
}

const secret = testSecret();

export default defineConfig({
  testDir,
  testMatch: /.*\.spec\.mjs/,
  timeout: 120000,
  workers: 1,
  fullyParallel: false,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: process.env.LLD_WP_URL || 'http://127.0.0.1:8090',
    extraHTTPHeaders: secret ? { 'X-LLD-Test-Secret': secret } : {},
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],
});
