import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  reporter: [['html', { outputFolder: 'playwright-report', open: 'never' }]],
  timeout: 30 * 1000,
  use: {
    headless: true,
    ignoreHTTPSErrors: true,
    // Disable default per-test screenshots; we manually attach full-page captures in the spec.
    screenshot: 'off',
    channel: 'chromium',
  },
  expect: {
    toMatchSnapshot: {
      threshold: 0.2
    }
  }
});
