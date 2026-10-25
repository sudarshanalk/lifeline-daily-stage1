import { defineConfig, devices } from '@playwright/test';

const testDir = new URL('.', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');

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
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],
});
