import { defineConfig } from '@playwright/test';
import config from './tests/playwright/config';

export default defineConfig({
  testDir: './tests/playwright',
  // Generous timeouts: the first requests served by the PHP built-in server
  // compile the TYPO3 codebase into OPcache, which is slow on CI runners.
  timeout: 90000,
  expect: { timeout: 25000 },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: [['list'], ['html', { outputFolder: './playwright-report', open: 'never' }]],
  outputDir: './test-results',

  use: {
    baseURL: config.baseUrl,
    ignoreHTTPSErrors: true,
    viewport: { width: 1600, height: 1200 },
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },

  projects: [
    {
      name: 'login setup',
      testMatch: /helper\/login\.setup\.ts/,
    },
    {
      name: 'e2e',
      testMatch: /e2e\/.*\.spec\.ts/,
      dependencies: ['login setup'],
      use: {
        storageState: './tests/playwright/.auth/login.json',
      },
    },
  ],
});
