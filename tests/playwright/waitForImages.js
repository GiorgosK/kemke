async function waitForImages(page, timeout) {
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
}

module.exports = { waitForImages };
