import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { createPinia } from 'pinia';

// Импортируем Toast
import Toast, { useToast } from "vue-toastification";
import "vue-toastification/dist/index.css";

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

router.on('exception', (event) => {
    if (event.detail.exception?.code === 'ERR_NETWORK') {
        useToast().error('Не удалось открыть страницу. Проверьте соединение и попробуйте ещё раз.', { position: 'top-center' });
        event.preventDefault();
    }
});

// Опции для Toast
const toastOptions = {
    position: "bottom-right",
    timeout: 3000,
    closeOnClick: true,
    pauseOnFocusLoss: true,
    pauseOnHover: true,
    draggable: true,
    draggablePercent: 0.6,
    showCloseButtonOnHover: false,
    hideProgressBar: false,
    closeButton: "button",
    icon: true,
    rtl: false,
    transition: "Vue-Toastification__bounce",
    maxToasts: 20,
    newestOnTop: true
};

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const pinia = createPinia();
        
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(pinia)
            .use(Toast, toastOptions)
            .mount(el);
    },
    progress: {
        delay: 0,
        color: '#6186b6',
    },
});

// Регистрация сервис-воркера (в продакшене)
if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js').catch((error) => {
            console.error('Service worker registration failed:', error);
        });
    });
}
