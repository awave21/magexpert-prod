# Sender UI

Админ-интерфейс сервиса рассылки Sender: React 19, TypeScript, Vite, React Router, TanStack Query.

## Запуск

Нужен запущенный Laravel (`php artisan serve` на порту 8000). Запросы к `/api/` Vite проксирует туда.

```bash
npm install
npm run dev        # http://localhost:5174/sender
```

Другой адрес API: `SENDER_API_URL=https://mail.mag-expert.ru npm run dev`.

Вход: пользователь из таблицы `sender_users` (создаётся командой `php artisan sender:user-create`).

## Устройство

- `src/api.ts`: клиент админ-API (`/api/sender/v1/admin`), типы, токен `mxu_…` в localStorage.
- `src/styles.css`: токены дизайн-системы (индиго, нейтральные, статусы) и компоненты.
- `src/components/Layout.tsx`: боковое меню и верхняя панель.
- `src/pages/*`: экраны по утверждённому дизайну.

## Сборка и выкладка

`npm run build` собирает интерфейс в `../public/sender-static` (папка в .gitignore, собирается на сервере).
Laravel отдаёт его по адресу `/sender`: маршрут `sender.ui` в `app/Sender/routes.php`.
API на том же домене, поэтому CORS не нужен.
