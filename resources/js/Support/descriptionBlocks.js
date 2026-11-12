export const blockLabels = { heading: 'Заголовок', text: 'Текст', image: 'Изображение', button: 'Кнопка', divider: 'Разделитель', spacer: 'Отступ', html: 'Свой HTML' };
export const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
export function safeUrl(value) {
    const url = String(value || '').trim();
    return /^(https?:\/\/|mailto:|tel:|\/(?!\/)|#)/i.test(url) ? url : '';
}
export function createBlock(type) {
    return { id: crypto.randomUUID(), type, original: null, html: '<p>Введите текст</p>', text: type === 'button' ? 'Зарегистрироваться' : 'Заголовок', href: '', src: '', alt: '', align: 'left', color: '#f27b63', height: 24, spacing: 16 };
}
export function renderBlock(block) {
    if (block.original !== null) return block.original;
    const align = ['left', 'center', 'right'].includes(block.align) ? block.align : 'left';
    const spacing = Math.max(0, Math.min(120, Number(block.spacing) || 0));
    const style = `margin:0 0 ${spacing}px;text-align:${align};`;
    let content = '';
    switch (block.type) {
        case 'heading': content = `<h2 style="${style}font-size:22px;font-weight:700;line-height:1.35">${escapeHtml(block.text)}</h2>`; break;
        case 'text': content = `<div style="${style}line-height:1.75">${block.html}</div>`; break;
        case 'button': {
            const color = /^#[0-9a-f]{6}$/i.test(block.color) ? block.color : '#f27b63';
            content = `<p style="${style}"><a href="${escapeHtml(safeUrl(block.href))}" target="_blank" rel="noopener noreferrer" style="display:inline-block;max-width:100%;box-sizing:border-box;padding:12px 20px;border-radius:8px;background-color:${color};color:#ffffff;font-weight:600;line-height:1.5;text-decoration:none;overflow-wrap:anywhere">${escapeHtml(block.text)}</a></p>`;
            break;
        }
        case 'image': content = `<p style="${style}"><img src="${escapeHtml(safeUrl(block.src))}" alt="${escapeHtml(block.alt)}" style="max-width:100%;height:auto;border-radius:8px"></p>`; break;
        case 'divider': content = `<hr style="border:0;border-top:1px solid #d4d4d8;margin:0 0 ${spacing}px">`; break;
        case 'spacer': content = `<div style="height:${Math.max(0, Math.min(240, Number(block.height) || 0))}px" aria-hidden="true"></div>`; break;
        case 'html': content = block.html; break;
    }
    return `<section data-description-block="${block.type}">${content}</section>`;
}
export function parseBlocks(html, documentParser) {
    const document = documentParser.parseFromString(html || '', 'text/html');
    const blocks = [];
    for (const node of document.body.childNodes) {
        if (node.nodeType === 3 && !node.textContent.trim()) continue;
        if (node.nodeType === 8) continue;
        const element = node.nodeType === 1 ? node : null;
        const root = element?.hasAttribute('data-description-block') ? element.firstElementChild : element;
        const tag = root?.tagName?.toLowerCase();
        const link = root?.matches('a') ? root : root?.querySelector('a');
        const image = root?.matches('img') ? root : root?.querySelector('img');
        const onlyLink = link && root.textContent.trim() === link.textContent.trim() && !image;
        let type = element?.getAttribute('data-description-block');
        if (!blockLabels[type]) type = /^h[1-6]$/.test(tag) ? 'heading' : onlyLink && /background|padding|inline-flex|inline-block/.test(link.getAttribute('style') || '') ? 'button' : image && !root.textContent.trim() ? 'image' : tag === 'hr' ? 'divider' : ['p', 'ul', 'ol', 'blockquote'].includes(tag) ? 'text' : 'html';
        const block = createBlock(type);
        block.original = element ? element.outerHTML : escapeHtml(node.textContent);
        block.html = type === 'html' ? block.original : element?.hasAttribute('data-description-block') ? root?.innerHTML || '' : block.original;
        block.text = type === 'button' ? link?.textContent || '' : root?.textContent || node.textContent;
        block.href = link?.getAttribute('href') || '';
        block.src = image?.getAttribute('src') || '';
        block.alt = image?.getAttribute('alt') || '';
        block.align = root?.style.textAlign || 'left';
        block.spacing = Number.parseInt(root?.style.marginBottom) || 16;
        block.height = Number.parseInt(root?.style.height) || 24;
        const bg = link?.style.backgroundColor;
        if (bg) {
            const rgb = bg.match(/\d+/g);
            block.color = bg.startsWith('#') ? bg : rgb?.length >= 3 ? '#' + rgb.slice(0, 3).map(n => Number(n).toString(16).padStart(2, '0')).join('') : block.color;
        }
        blocks.push(block);
    }
    return blocks;
}
