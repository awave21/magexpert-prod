export function uploadError(file, accept, maxSizeMb) {
    const extension = '.' + file.name.split('.').pop().toLowerCase();
    const accepted = accept.split(',').map(type => type.trim().toLowerCase());
    const matches = accepted.some(type => type.startsWith('.') ? extension === type : type.endsWith('/*') ? file.type.startsWith(type.slice(0, -1)) : file.type === type);
    if (!matches) return 'Недопустимый формат файла.';
    if (file.size > maxSizeMb * 1024 * 1024) return `Размер файла превышает ${maxSizeMb} МБ.`;
    return null;
}
