import axios from 'axios';

// Утилита: записать токен в meta
const setMetaCsrfToken = (token) => {
    try {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            meta.setAttribute('content', token);
        } else {
            const newMeta = document.createElement('meta');
            newMeta.setAttribute('name', 'csrf-token');
            newMeta.setAttribute('content', token);
            document.head.appendChild(newMeta);
        }
    } catch (e) {
        console.error('Не удалось записать CSRF meta:', e);
    }
};

// Функция для получения CSRF токена
const getCSRFToken = () => {
    try {
        // 1. Из meta тега
        const metaToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (metaToken) return metaToken;

        // 2. Из cookie
        const cookies = document.cookie.split(';');
        const xsrfCookie = cookies.find(cookie => cookie.trim().startsWith('XSRF-TOKEN='));
        if (xsrfCookie) {
            return decodeURIComponent(xsrfCookie.split('=')[1]);
        }

        console.warn('CSRF токен не найден!');
        return null;
    } catch (error) {
        console.error('Ошибка при получении CSRF токена:', error);
        return null;
    }
};

// Обновление CSRF-токена без перезагрузки
const refreshCsrfToken = async () => {
    try {
        const response = await axios.get('/csrf-token', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const newToken = response?.data?.csrf_token;
        if (newToken) {
            setMetaCsrfToken(newToken);
            axios.defaults.headers.common['X-CSRF-TOKEN'] = newToken;
            return newToken;
        }
    } catch (error) {
        console.error('Ошибка при обновлении CSRF-токена:', error);
    }
    return null;
};

// Глобальная настройка axios
window.axios = axios;
axios.defaults.baseURL = window.location.origin;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['Accept'] = 'application/json';

// Перехватчик для добавления CSRF-токена
axios.interceptors.request.use(config => {
    const token = getCSRFToken();
    if (token) {
        config.headers['X-CSRF-TOKEN'] = token;
    }
    return config;
});

// Обработка ошибок axios
axios.interceptors.response.use(
    response => response,
    error => {
        if (error.response?.status === 419) {
            // Пытаемся мягко обновить CSRF и повторить запрос один раз
            const originalRequest = error.config || {};
            if (!originalRequest._retry) {
                originalRequest._retry = true;
                return refreshCsrfToken().then((token) => {
                    if (token) {
                        // Повторяем исходный запрос с новым токеном
                        originalRequest.headers = {
                            ...(originalRequest.headers || {}),
                            'X-CSRF-TOKEN': token,
                        };
                        return axios(originalRequest);
                    }
                    // Если обновление не удалось — перезагружаем страницу
                    window.location.reload();
                    return Promise.reject(error);
                });
            }
        }
        if (error.response?.status === 401) {
            // Если пользователь не авторизован
            window.location.href = '/login';
        }
        return Promise.reject(error);
    }
);

if (import.meta.env.DEV) {
    window.addEventListener('unhandledrejection', event => {
        console.error('Необработанная ошибка Promise:', event.reason);
    });
}
