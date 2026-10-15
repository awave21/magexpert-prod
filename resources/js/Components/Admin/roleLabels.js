// Названия ролей для интерфейса: в базе они хранятся по-английски
export const roleLabels = {
    admin: "Администратор",
    manager: "Менеджер",
    editor: "Редактор",
    user: "Пользователь",
};

export const roleLabel = (name) => roleLabels[name] ?? name;
