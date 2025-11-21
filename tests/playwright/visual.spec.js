import { test, expect } from '@playwright/test';
import config from './scenarios.json' assert { type: 'json' };

const { viewports, scenarios, defaults } = config;

const resolveUrl = (pathOrUrl, baseUrl) => {
  if (!pathOrUrl) return '';
  // If the scenario provides an absolute URL, use it as-is. Otherwise join with the chosen base.
  return /^https?:\/\//i.test(pathOrUrl)
    ? pathOrUrl
    : baseUrl
      ? new URL(pathOrUrl, baseUrl).toString()
      : pathOrUrl;
};

test.describe.configure({ mode: 'parallel' });

for (const scenario of scenarios) {
  for (const viewport of viewports) {
    test(`${scenario.label} [${viewport.label}]`, async ({ page }, testInfo) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });

      const referenceBase =
        scenario.referenceURL ||
        process.env.PLAYWRIGHT_REFERENCE_URL ||
        defaults?.referenceURL;
      const testBase =
        scenario.testURL ||
        process.env.PLAYWRIGHT_TEST_URL ||
        defaults?.testURL;
      const useReferenceBase =
        process.env.PLAYWRIGHT_REFERENCE === '1' ||
        process.env.PWTEST_REFERENCE === '1' ||
        testInfo?.config?.updateSnapshots === 'all';

      const targetUrl = resolveUrl(scenario.url, useReferenceBase ? referenceBase : testBase);
      const readySelector = scenario.readySelector || defaults?.readySelector;
      const readyText = scenario.readyText || defaults?.readyText;
      const waitTimeout = scenario.waitTimeout || defaults?.waitTimeout || 5000;
      const waitAfterLoad = scenario.wait ?? defaults?.wait ?? 0;
      const maxDiffPixelRatio = scenario.threshold ?? defaults?.threshold;
      const snapshotOptions = maxDiffPixelRatio != null ? { maxDiffPixelRatio } : undefined;

      await page.goto(targetUrl, { waitUntil: 'networkidle' });
      await page.evaluate(() => {
        const body = document.body || document.getElementsByTagName('body')[0];
        if (body) {
          body.style['-webkit-font-smoothing'] = 'none';
        }
      });
      // Let fonts and rendering settle to reduce flaky diffs.
      await page.waitForLoadState('networkidle');
      await page.waitForFunction(
        () => (document.fonts?.status === 'loaded') ? true : document.fonts?.ready,
        null,
        { timeout: waitTimeout }
      ).catch(() => {});
      if (waitAfterLoad > 0) {
        await page.waitForTimeout(waitAfterLoad);
      }
      await page.waitForTimeout(500);

      let isWSOD = false;

      try {
        if (readySelector) {
          await page.waitForSelector(readySelector, { timeout: waitTimeout });
        } else if (readyText) {
          await page.waitForFunction(
            text => document.body && document.body.innerText.includes(text),
            readyText,
            { timeout: waitTimeout }
          );
        }
      } catch {
        isWSOD = true;
      }

      if (isWSOD) {
        const wsodBuffer = Buffer.from("WSOD");
        expect(wsodBuffer).toMatchSnapshot(
          `${scenario.label.replace(/\s+/g, '_')}_${viewport.label}.wsod`
        );
      } else {
        const screenshot = await page.screenshot();
        expect(screenshot).toMatchSnapshot(
          `${scenario.label.replace(/\s+/g, '_')}_${viewport.label}.png`,
          snapshotOptions
        );
      }
    });
  }
}
