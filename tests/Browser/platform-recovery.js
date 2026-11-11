async (page) => {
    const url = 'http://127.0.0.1:8000/';
    const results = [];

    for (const width of [320, 390, 1280]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 20000 });
        await page.getByRole('heading', { level: 1 }).waitFor({ state: 'visible' });
        if (await page.getByRole('heading', { name: 'Восстановление платформы', exact: true }).count()) {
            throw new Error('The outdated recovery notice is still visible');
        }
        if (width < 768) {
            const menu = page.locator('nav.fixed.bottom-0');
            if (!await menu.isVisible() || await menu.getByRole('link').count() !== 5) {
                throw new Error('Mobile navigation is missing');
            }
        }
        results.push({ width, noticeRemoved: true, menuPresent: true });
    }

    return results;
};
