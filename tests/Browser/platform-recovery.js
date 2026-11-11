async (page) => {
    const url = 'http://127.0.0.1:8000/';
    const message = 'После технического сбоя часть данных и функций платформы недоступна. Ведём восстановительные работы и проверяем целостность данных. Приносим извинения за неудобства.';
    const results = [];

    for (const width of [320, 390, 1280]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 20000 });
        const notice = page.getByRole('status').filter({ has: page.getByRole('heading', { name: 'Восстановление платформы', exact: true }) });
        await notice.waitFor({ state: 'visible' });
        const paragraph = (await notice.locator('p').innerText()).replace(/\s+/g, ' ').trim();
        if (paragraph !== message) {
            throw new Error('Recovery message differs from the approved text');
        }
        const bounds = await notice.boundingBox();
        if (bounds.x < 0 || bounds.x + bounds.width > width + 1 || bounds.y > 400) {
            throw new Error(`Recovery notice is clipped or not near the top at ${width}px`);
        }
        if (width < 768) {
            const menu = page.locator('nav.fixed.bottom-0');
            if (!await menu.isVisible() || await menu.getByRole('link').count() !== 5) {
                throw new Error('Mobile navigation is missing');
            }
        }
        results.push({ width, noticeVisible: true, menuPresent: true });
    }

    return results;
};
