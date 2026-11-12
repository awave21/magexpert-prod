async (page) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const response = await page.goto('http://127.0.0.1:8000/events/podderzka-liuteinovoi-fazy', {
        waitUntil: 'domcontentloaded',
    });
    if (response.status() !== 200) {
        throw new Error(`Event details returned HTTP ${response.status()}`);
    }
    await page.getByRole('navigation', { name: 'Основная навигация' }).waitFor();
    return { status: response.status(), eventDetailsRendered: true };
};
