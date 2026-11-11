async (page) => {
    const url = 'http://127.0.0.1:8000/login';
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await page.evaluate(() => localStorage.removeItem('cookie_consent'));
    const results = [];

    for (const width of [320, 390, 767, 768, 1280]) {
        await page.setViewportSize({ width, height: 844 });
        await page.reload({ waitUntil: 'domcontentloaded' });
        const button = page.getByRole('button', { name: 'Принять', exact: true });
        await button.waitFor({ state: 'visible' });
        const banner = button.locator('../..');
        const bounds = await banner.boundingBox();
        const menu = page.locator('nav.fixed.bottom-0');

        if (width < 768) {
            const menuBounds = await menu.boundingBox();
            if (!menuBounds || bounds.y + bounds.height > menuBounds.y + 1) {
                throw new Error(`Cookie banner overlaps mobile navigation at ${width}px`);
            }
            const links = menu.getByRole('link');
            if (await links.count() !== 5) {
                throw new Error(`Mobile navigation items are missing at ${width}px`);
            }
            for (const link of await links.all()) {
                if (!await link.isVisible()) {
                    throw new Error(`Mobile navigation item is hidden at ${width}px`);
                }
            }
        } else if (Math.abs(bounds.y + bounds.height - 844) > 1) {
            throw new Error(`Cookie banner does not sit at the viewport bottom at ${width}px`);
        }

        await button.click({ trial: true });
        results.push({ width, buttonAccessible: true });
    }

    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Принять', exact: true }).click();
    if (await page.getByRole('button', { name: 'Принять', exact: true }).count()) {
        throw new Error('Cookie banner remains visible after acceptance');
    }
    await page.reload({ waitUntil: 'domcontentloaded' });
    if (await page.getByRole('button', { name: 'Принять', exact: true }).count()) {
        throw new Error('Cookie acceptance does not persist after reload');
    }

    return { viewports: results, acceptancePersists: true };
};
