/**
 * Remove DOM elements matching any selector in the provided list.
 * Accepts an array of selectors. No-op on errors.
 */
async function removeBySelector(page, selectors = []) {
  if (!Array.isArray(selectors) || selectors.length === 0) return;
  await page
    .evaluate(selList => {
      selList.forEach(sel => {
        document.querySelectorAll(sel).forEach(el => el.remove());
      });
    }, selectors)
    .catch(() => {});
}

module.exports = { removeBySelector };
