const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');

const DEFAULT_SELECTOR = '#CybotCookiebotDialogBodyLevelButtonLevelOptinAllowAll';

/**
 * Builds an async factory that prepares a Cookiebot storageState file (once) and reuses it.
 * Returns { enabled, ensureCookiebotState }.
 */
function createCookiebotStateFactory({ defaults, viewports, scenarios, resolveUrl }) {
  const enabled = defaults?.cookiebot === true;
  const selector = defaults?.cookiebotSelector || DEFAULT_SELECTOR;
  const stateDir = path.resolve(__dirname, '.state');
  const statePath = path.join(stateDir, 'cookiebot.json');
  const consentBase = defaults?.testURL || defaults?.referenceURL || '';
  const consentScenario = scenarios?.[0];
  const consentUrl = consentScenario ? resolveUrl(consentScenario.url, consentBase) : consentBase || '/';

  const ensureCookiebotState = async () => {
    if (!enabled) return undefined;
    if (fs.existsSync(statePath)) return statePath;

    fs.mkdirSync(stateDir, { recursive: true });

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({
      viewport: {
        width: viewports?.[viewports.length - 1]?.width || 1280,
        height: viewports?.[viewports.length - 1]?.height || 800
      }
    });

    try {
      await page.goto(consentUrl, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector(selector, { timeout: 10000 });
      await page.click(selector);
      await page.waitForTimeout(500);
    } catch (_) {
      // Ignore banner failures; still save whatever state we have so tests can proceed.
    }

    await page.context().storageState({ path: statePath });
    await browser.close();

    return statePath;
  };

  return { enabled, ensureCookiebotState };
}

module.exports = { createCookiebotStateFactory };
