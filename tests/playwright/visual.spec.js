const { test: base, expect } = require('@playwright/test');
const config = require('./scenarios.json');
const { createCookiebotStateFactory } = require('./cookiebot');
const { waitForImages } = require('./waitForImages');
const { waitForBackgroundImages } = require('./waitForBackgroundImages');

const resolveUrl = (pathOrUrl, baseUrl) => {
  if (!pathOrUrl) return '';
  // If the scenario provides an absolute URL, use it as-is. Otherwise join with the chosen base.
  return /^https?:\/\//i.test(pathOrUrl)
    ? pathOrUrl
    : baseUrl
      ? new URL(pathOrUrl, baseUrl).toString()
      : pathOrUrl;
};

const { viewports, scenarios, defaults } = config;
const { enabled: cookiebotEnabled, ensureCookiebotState } = createCookiebotStateFactory({
  defaults,
  viewports,
  scenarios,
  resolveUrl
});

const waitForImages = async (page, timeout) => {
  await page
    .waitForFunction(
      () =>
        Array.from(document.images || []).every(
          img => img.complete && Number.isFinite(img.naturalWidth) && img.naturalWidth > 0
        ),
      null,
      { timeout }
    )
    .catch(() => {});
};

const waitForBackgroundImages = async (page, timeout) => {
  const { urls, base } = await page.evaluate(() => {
    const collected = [];
    const regex = /url\\(["']?([^"')]+)["']?\\)/g;
    document.querySelectorAll('body *').forEach(el => {
      const bg = getComputedStyle(el).getPropertyValue('background-image');
      if (!bg || bg === 'none') return;
      let match;
      while ((match = regex.exec(bg)) !== null) {
        const url = match[1];
        if (url && url !== 'about:blank' && !url.startsWith('data:')) {
          collected.push(url);
        }
      }
    });
    return { urls: Array.from(new Set(collected)), base: location.href };
  });

  if (!urls.length) return;

  await page
    .evaluate(
      async ({ images, baseUrl, maxWait }) => {
        const resolveUrl = src => (src.startsWith('http') ? src : new URL(src, baseUrl).href);
        const loaders = images.map(
          src =>
            new Promise(resolve => {
              const img = new Image();
              img.onload = img.onerror = resolve;
              img.src = resolveUrl(src);
              if (img.complete) resolve();
            })
        );

        await Promise.race([
          Promise.all(loaders),
          new Promise(resolve => setTimeout(resolve, maxWait))
        ]);
      },
      { images: urls, baseUrl: base, maxWait: timeout }
    )
    .catch(() => {});
};

const test = base.extend({
  context: async ({ browser }, use) => {
    const storageState = cookiebotEnabled ? await ensureCookiebotState() : undefined;
    const context = await browser.newContext({ storageState });
    await use(context);
    await context.close();
  }
});

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
      const useWaitForImages = scenario.waitForImages ?? defaults?.waitForImages;
      const useWaitForBackgroundImages =
        scenario.waitForBackgroundImages ?? defaults?.waitForBackgroundImages;

      await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: waitTimeout });
      await page.addStyleTag({
        content: `
          html, body, * {
            -webkit-font-smoothing: antialiased !important;
            font-synthesis: none !important;
          }
        `
      });
      // Let fonts and rendering settle to reduce flaky diffs.
      await page.waitForLoadState('networkidle', { timeout: waitTimeout }).catch(() => {});
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
        if (useWaitForImages) {
          await waitForImages(page, waitTimeout);
        }
        if (useWaitForBackgroundImages) {
          await waitForBackgroundImages(page, waitTimeout);
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
        // Prefer full page captures so we don't miss content below the fold; fall back to viewport if it fails.
        let screenshot;
        try {
          screenshot = await page.screenshot({ fullPage: true });
        } catch {
          screenshot = await page.screenshot();
        }
        expect(screenshot).toMatchSnapshot(
          `${scenario.label.replace(/\s+/g, '_')}_${viewport.label}.png`,
          snapshotOptions
        );
      }
    });
  }
}
