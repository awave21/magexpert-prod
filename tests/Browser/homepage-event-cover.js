async (page) => {
    await page.route('**/storage/**', async route => {
        const url = new URL(route.request().url());
        await route.fulfill({ response: await route.fetch({ url: 'https://mag-expert.ru' + url.pathname }) });
    });
    const measurements = [];
    for (const width of [390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        const response = await page.goto('http://127.0.0.1:8000/', { waitUntil: 'domcontentloaded' });
        if (response.status() !== 200) throw new Error(`Homepage returned ${response.status()}`);
        await page.getByText('Ближайшее мероприятие', { exact: true }).waitFor();
        const result = await page.evaluate(async () => {
            const event = JSON.parse(document.querySelector('[data-page]').dataset.page).props.upcomingEvent;
            const image = [...document.images].find(image => image.alt === event.title);
            if (!image) throw new Error('Upcoming event cover missing');
            await image.decode();
            const box = image.getBoundingClientRect();
            const expected = box.width * image.naturalHeight / image.naturalWidth;
            if (Math.abs(box.height - expected) > 1) throw new Error('Cover aspect ratio changed');
            if (image.parentElement.clientWidth < box.width - 1) throw new Error('Cover exceeds card width');
            if (document.documentElement.scrollWidth > innerWidth) throw new Error('Horizontal page overflow');
            return { width: box.width, height: box.height, naturalWidth: image.naturalWidth, naturalHeight: image.naturalHeight };
        });
        measurements.push({ viewport: width, ...result });
    }
    return measurements;
};
