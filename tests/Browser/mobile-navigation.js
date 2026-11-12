async (page) => {
    const base = 'http://127.0.0.1:8000';
    const assert = (condition, message) => { if (!condition) throw new Error(message); };
    const bottom = () => page.getByRole('navigation', { name: 'Основная навигация' });
    const pending = () => bottom().locator('[aria-busy="true"]');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${base}/contacts`, { waitUntil: 'domcontentloaded' });
    await bottom().waitFor();
    const consent = page.getByRole('button', { name: 'Принять', exact: true });
    if (await consent.count()) await consent.click();

    let release;
    let requests = 0;
    const holdDocuments = async (route) => {
        if (!route.request().headers()['x-inertia']) return route.continue();
        requests++;
        await new Promise((resolve) => { release = resolve; });
        try {
            await route.continue();
        } catch (error) {
            if (!error.message.includes('Route is already handled')) throw error;
        }
    };
    await page.route('**/documents', holdDocuments);
    const library = bottom().getByRole('link', { name: 'Библиотека' });
    await library.click();
    await pending().waitFor();
    await page.waitForFunction(() => !!document.querySelector('#nprogress .bar'));
    assert(await pending().count() === 1, 'The clicked item must show loading');
    assert(await bottom().getByRole('link', { name: 'Контакты' }).evaluate((el) => el.classList.contains('text-blue-600')), 'Current page highlight must remain until navigation completes');
    await library.dispatchEvent('click');
    await page.waitForTimeout(100);
    assert(requests === 1, 'Duplicate tap must not create another request');
    const destination = page.waitForURL('**/documents');
    release();
    await destination;
    await bottom().waitFor();
    assert(await pending().count() === 0, 'Loading must clear after success');
    await page.unroute('**/documents', holdDocuments);

    await page.route('**/events', (route) => route.abort('failed'));
    await bottom().getByRole('link', { name: 'Мероприятия' }).click();
    await page.getByText('Не удалось открыть страницу. Проверьте соединение и попробуйте ещё раз.').waitFor();
    assert(await pending().count() === 0, 'Loading must clear after a network error');
    await page.unroute('**/events');
    await bottom().getByRole('link', { name: 'Мероприятия' }).click();
    await page.waitForURL('**/events');
    await bottom().waitFor();

    await page.route('**/documents', holdDocuments);
    await bottom().getByRole('link', { name: 'Библиотека' }).click();
    await pending().waitFor();
    await page.waitForTimeout(100);
    await Promise.all([
        page.waitForURL('**/contacts', { waitUntil: 'domcontentloaded' }),
        bottom().getByRole('link', { name: 'Контакты' }).click(),
    ]);
    release();
    await page.unroute('**/documents', holdDocuments);
    await bottom().waitFor();
    assert(await pending().count() === 0, 'Cancelled navigation must not leave a loading item');

    await page.getByRole('button', { name: 'Открыть меню' }).click();
    const upperLibrary = page.locator('.fixed.inset-y-0.right-0').getByRole('link', { name: 'Медицинская библиотека' });
    await upperLibrary.waitFor();
    await upperLibrary.click();
    await page.waitForURL('**/documents');
    await bottom().waitFor();
    await page.goBack();
    await page.waitForURL('**/contacts');
    await bottom().waitFor();
    assert(await pending().count() === 0, 'Back navigation must not restore stale loading');

    const viewports = [];
    for (const width of [320, 390, 767]) {
        await page.setViewportSize({ width, height: 844 });
        for (const link of await bottom().getByRole('link').all()) {
            const bounds = await link.boundingBox();
            assert(bounds && bounds.height >= 44 && bounds.x >= 0 && bounds.x + bounds.width <= width + 1, `Navigation tap target does not fit at ${width}px`);
        }
        viewports.push(width);
    }
    return { loading: true, duplicateTap: true, networkRetry: true, cancellation: true, upperMenu: true, history: true, viewports };
};
