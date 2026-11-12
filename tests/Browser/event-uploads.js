async (page) => {
    await page.goto('http://127.0.0.1:5178/@vite/client');
    await page.evaluate(async () => {
        const { createApp, h, ref } = await import('/node_modules/.vite/deps/vue.js');
        const { default: ImageUpload } = await import('/resources/js/Components/Form/ImageUpload.vue');
        const { default: FileDropzone } = await import('/resources/js/Components/Form/FileDropzone.vue');
        const { default: Toast } = await import('/node_modules/.vite/deps/vue-toastification.js');
        document.body.innerHTML = '<div id="test"></div>';
        const image = ref(null), program = ref(null);
        window.uploadState = { image: null, program: null };
        createApp({ render: () => h('main', [
            h(ImageUpload, { label: 'Обложка', uploadLabel: 'Загрузить обложку', accept: 'image/jpeg,image/png,image/gif,image/webp', modelValue: image.value, 'onUpdate:modelValue': file => { image.value = file; window.uploadState.image = file?.name || null; } }),
            h(FileDropzone, { label: 'Перетащите программу сюда', accept: '.pdf,.docx,.jpg,.jpeg,.png', maxSizeMb: 10, onFile: file => { program.value = file; window.uploadState.program = file.name; } }, () => program.value?.name || 'Загрузить программу'),
        ]) }).use(Toast).mount('#test');
    });
    const drop = async (index, names, type, size = 20) => {
        const data = await page.evaluateHandle(({ names, type, size }) => {
            const transfer = new DataTransfer();
            names.forEach(name => transfer.items.add(new File([new Uint8Array(size)], name, { type })));
            return transfer;
        }, { names, type, size });
        const zone = page.locator('.file-dropzone').nth(index);
        await zone.dispatchEvent('dragenter', { dataTransfer: data });
        if (!(await zone.getAttribute('class')).includes('is-dragging')) throw new Error('Drop highlight missing');
        await zone.dispatchEvent('drop', { dataTransfer: data });
        if ((await zone.getAttribute('class')).includes('is-dragging')) throw new Error('Drop highlight stuck');
        await data.dispose();
    };
    await drop(0, ['cover.png'], 'image/png');
    await page.waitForFunction(() => window.uploadState.image === 'cover.png');
    await drop(0, ['replacement.webp'], 'image/webp');
    await page.waitForFunction(() => window.uploadState.image === 'replacement.webp');
    await drop(0, ['wrong.pdf'], 'application/pdf');
    await drop(0, ['large.png'], 'image/png', 21 * 1024 * 1024);
    await drop(0, ['first.png', 'second.png'], 'image/png');
    if (await page.evaluate(() => window.uploadState.image) !== 'replacement.webp') throw new Error('Invalid image replaced the valid selection');
    await drop(1, ['program.pdf'], 'application/pdf');
    await page.waitForFunction(() => window.uploadState.program === 'program.pdf');
    await drop(1, ['replacement.docx'], 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    await drop(1, ['wrong.exe'], 'application/octet-stream');
    await drop(1, ['large.pdf'], 'application/pdf', 11 * 1024 * 1024);
    if (await page.evaluate(() => window.uploadState.program) !== 'replacement.docx') throw new Error('Invalid program replaced the valid selection');
    await page.getByRole('button', { name: 'Изменить фото', exact: true }).waitFor();
    await page.locator('input[type=file]').setInputFiles({ name: 'chosen.png', mimeType: 'image/png', buffer: Buffer.from('image') });
    await page.waitForFunction(() => window.uploadState.image === 'chosen.png');
    return { coverDrop: true, programDrop: true, replacement: true, invalidFilesRejected: true, buttonSelection: true };
};
