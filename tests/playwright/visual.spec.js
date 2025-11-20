import { test, expect } from '@playwright/test';
import config from './scenarios.json' assert { type: 'json' };

const { viewports, scenarios, defaults } = config;

for (const scenario of scenarios) {
  for (const viewport of viewports) {
    test(`${scenario.label} [${viewport.label}]`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto(scenario.url, { waitUntil: 'networkidle' });

      const readySelector = scenario.readySelector || defaults?.readySelector;
      const readyText = scenario.readyText || defaults?.readyText;
      const waitTimeout = scenario.waitTimeout || defaults?.waitTimeout || 5000;
      const maxDiffPixelRatio = scenario.threshold ?? defaults?.threshold;
      const snapshotOptions = maxDiffPixelRatio != null ? { maxDiffPixelRatio } : undefined;

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
