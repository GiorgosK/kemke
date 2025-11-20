import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  reporter: [['html', { outputFolder: 'playwright-report', open: 'never' }]],
  timeout: 30 * 1000,
  use: {
    headless: true,
    ignoreHTTPSErrors: true,
    screenshot: 'on',
    channel: 'chromium',
  },
  expect: {
    toMatchSnapshot: {
      threshold: 0.2
    }
  }
});
