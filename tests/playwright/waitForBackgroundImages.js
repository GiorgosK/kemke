async function waitForBackgroundImages(page, timeout) {
  const { urls, base } = await page.evaluate(() => {
    const collected = [];
    const regex = /url\(["']?([^"')]+)["']?\)/g;
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
}

module.exports = { waitForBackgroundImages };
