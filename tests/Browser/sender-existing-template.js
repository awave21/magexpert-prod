async (page, base = 'https://magsender.ru') => {
    await page.addInitScript(() => localStorage.setItem('sender.token', 'browser-test-token'));
    const originalHtml = '<p>Сохранённый текст письма</p>';
    const template = {
        id: 1, name: 'Регистрация на сайте', slug: 'welcome', subject: 'Добро пожаловать',
        folder_id: 1, sender_address_id: 1, reply_to: null, preheader: null,
        editor: 'blocks', body_html: originalHtml, body_text: 'Сохранённый текст письма',
        updated_at: new Date().toISOString(),
        design: { version: 1, settings: {}, blocks: [{ id: 'saved-text', type: 'text', html: originalHtml }] },
    };
    const writes = [];
    await page.route('**/api/sender/v1/admin/**', (route) => {
        const request = route.request();
        const path = new URL(request.url()).pathname.split('/admin')[1];
        if (request.method() !== 'GET') writes.push({ path, method: request.method(), body: request.postDataJSON() });
        let data = { data: [] };
        if (path === '/me') data = { data: { id: 1, name: 'Администратор', email: 'test@example.com', role: 'owner', organization: 'MagExpert' } };
        if (path === '/templates/1') data = { data: template };
        if (path === '/templates/2') data = { data: { ...template, id: 2, editor: 'html', design: null } };
        if (path === '/templates/3') data = { data: { ...template, id: 3, editor: 'html', design: null } };
        if (path === '/template-folders') data = { data: [{ id: 1, name: 'MagExpert' }] };
        if (path === '/variables') data = { base: [], custom: [] };
        if (path === '/sender-addresses') data = { data: [{ id: 1, email: 'noreply@example.com', name: 'MagExpert', confirmed: true, verified: true }] };
        if (path === '/domains') data = { data: [{ id: 1, domain: 'example.com', status: 'verified' }] };
        if (path === '/templates' && request.method() === 'POST') data = { data: { ...template, id: 3, editor: 'html', design: null } };
        return route.fulfill({ json: data });
    });

    for (const width of [390, 1280]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto(base+'/templates/1', { waitUntil: 'domcontentloaded' });
        const edit = page.getByRole('button', { name: 'Редактировать письмо', exact: true });
        await edit.waitFor();
        if (await page.locator('.tpl-ways, .tpl-steps').count()) throw new Error('Existing content still shows creation choices');
        await edit.click();
        await page.locator('.be-body').waitFor();
        await page.getByText('Сохранённый текст письма', { exact: true }).waitFor();
        if (await page.getByText('Собрать письмо из блоков', { exact: true }).count()) throw new Error('Existing content opens the builder start screen');
    }
    await page.goto(base+'/templates/2', { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Редактировать письмо', exact: true }).click();
    const source = page.getByLabel('HTML письма', { exact: true });
    await source.waitFor();
    if (await source.inputValue() !== originalHtml) throw new Error('Existing HTML was changed on opening');
    await page.getByRole('button', { name: 'Отмена', exact: true }).click();
    if (writes.length) throw new Error('Opening the editor unexpectedly writes content');

    await page.getByRole('button', { name: 'Редактировать письмо', exact: true }).click();
    await page.getByLabel('HTML письма', { exact: true }).fill('<p>Изменённый текст</p>');
    await page.getByRole('button', { name: 'Применить изменения', exact: true }).click();
    if (writes.length) throw new Error('Applying HTML unexpectedly saves before confirmation');
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await page.getByText('Письмо сохранено', { exact: true }).waitFor();
    if (writes.length !== 1 || writes[0].method !== 'PUT' || writes[0].path !== '/templates/2'
        || writes[0].body.body_html !== '<p>Изменённый текст</p>') throw new Error('HTML edit did not save to the existing template');
    writes.length = 0;

    await page.goto(base+'/templates/new', { waitUntil: 'domcontentloaded' });
    await page.locator('.tpl-ways').waitFor();
    if (!page.url().includes('setup=1')) throw new Error('New content does not enter setup');
    if (writes.length !== 1 || writes[0].method !== 'POST') throw new Error('New content creation made unexpected writes');
    return { mobile: true, desktop: true, savedBlocksPreserved: true, htmlPreserved: true, setupOnlyForNew: true };
};
