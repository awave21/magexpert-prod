async (page) => {
    const base = 'https://magsender.ru';
    await page.route('**/api/sender/v1/admin/forgot-password', (route) => route.fulfill({
        json: { message: 'Если аккаунт существует, мы отправим ссылку для восстановления пароля.' },
    }));
    await page.route('**/api/sender/v1/admin/reset-password', (route) => route.fulfill({
        status: 422,
        json: { message: 'Ссылка недействительна', errors: { token: ['Ссылка недействительна или срок её действия истёк. Запросите новую.'] } },
    }));
    for (const width of [390, 1280]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto(base+'/login', { waitUntil: 'domcontentloaded' });
        await page.getByRole('link', { name: 'Забыли пароль?' }).click();
        await page.getByLabel('Email', { exact: true }).fill('test@example.com');
        await page.getByRole('button', { name: 'Отправить ссылку' }).click();
        await page.getByRole('status').waitFor();
        await page.goto(base+'/reset-password#token='+ 'x'.repeat(64)+'&email=test%40example.com', { waitUntil: 'domcontentloaded' });
        await page.getByLabel('Новый пароль', { exact: true }).fill('test-password');
        await page.getByLabel('Повторите пароль').fill('test-password');
        await page.getByRole('button', { name: 'Сохранить пароль' }).click();
        await page.getByRole('alert').waitFor();
        await page.getByRole('link', { name: 'Запросить новую ссылку' }).click();
        await page.getByRole('button', { name: 'Отправить ссылку' }).waitFor();
        if (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)) {
            throw new Error('Recovery page overflows at '+width+'px');
        }
    }
    await page.goto(base+'/reset-password', { waitUntil: 'domcontentloaded' });
    await page.getByRole('alert').waitFor();
    return { mobile: true, desktop: true, missingLink: true, expiredLink: true };
};
