<template>
    <SlideOverModal :show="isOpen" @close="emit('close')">
        <template #title>
            <div class="flex items-center gap-2">
                <BellIcon class="size-5 text-zinc-700 dark:text-zinc-300" />
                <span>Уведомления</span>
                <span v-if="adminStore.unreadNotificationsCount > 0" 
                    class="ml-2 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-xs font-medium text-white">
                    {{ adminStore.unreadNotificationsCount }}
                </span>
            </div>
        </template>

        <!-- Поиск и фильтры -->
        <div class="space-y-4">
            <!-- Фильтр типов -->
            <div class="flex items-center">
                <SelectList
                    v-model="selectedType"
                    :options="notificationTypeOptions"
                    placeholder="Фильтр по типу"
                    class="w-full"
                />
            </div>
        </div>

        <div class="my-4 h-px bg-zinc-200 dark:bg-zinc-700"></div>

        <!-- Список уведомлений -->
        <div class="overflow-y-auto -mx-6 px-6" style="max-height: calc(100vh - 250px);">
            <div v-if="loading" class="flex justify-center py-8">
                <div class="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-blue-500"></div>
            </div>
            
            <div v-else-if="filteredNotifications.length === 0" class="flex flex-col items-center justify-center py-8 text-center text-zinc-500 dark:text-zinc-400">
                <InboxIcon class="size-12 mb-3" />
                <p>Нет уведомлений</p>
            </div>
            
            <div 
                v-else
                class="space-y-2"
            >
                <div 
                    v-for="notification in filteredNotifications" 
                    :key="notification.id"
                    class="group relative flex items-start gap-4 rounded-lg p-3 transition-all duration-200 cursor-pointer" 
                    :class="[
                        notification.read 
                            ? 'bg-white hover:bg-zinc-50 dark:bg-zinc-900 dark:hover:bg-zinc-800/70' 
                            : 'bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:hover:bg-blue-900/30'
                    ]"
                >
                    <!-- Иконка уведомления -->
                    <div 
                        class="flex-shrink-0 flex items-center justify-center size-10 rounded-full" 
                        :class="getNotificationClass(notification.type)"
                    >
                        <component 
                            :is="getNotificationIcon(notification.type)" 
                            class="size-5 text-white"
                        />
                    </div>
                    
                    <!-- Контент уведомления -->
                    <div class="flex-grow min-w-0">
                        <div class="flex items-start justify-between">
                            <span class="font-medium text-zinc-900 dark:text-white" :class="{ 'font-semibold': !notification.read }">
                                {{ notification.title }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300" :class="{ 'font-medium': !notification.read }">
                            {{ notification.message }}
                        </p>
                        <div class="mt-2 flex items-center justify-between">
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ formatDate(notification.timestamp) }}
                            </span>
                            
                            <!-- Действия -->
                            <div class="flex space-x-2">
                                <!-- Кнопка Прочитано -->
                                <button 
                                    v-if="!notification.read"
                                    @click="markAsRead(notification.id)"
                                    title="Отметить как прочитанное"
                                    class="rounded-full p-1 text-zinc-400 hover:bg-zinc-200 hover:text-blue-600 dark:hover:bg-zinc-700 dark:hover:text-blue-400"
                                >
                                    <EnvelopeOpenIcon class="size-4" />
                                </button>
                                
                                <!-- Кнопка Удалить -->
                                <button 
                                    @click="deleteNotification(notification.id)"
                                    title="Удалить"
                                    class="rounded-full p-1 text-zinc-400 hover:bg-red-100 hover:text-red-600 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                >
                                    <TrashIcon class="size-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Футер -->
        <template #footer>
            <div class="flex flex-col sm:flex-row justify-between gap-3">
                <button 
                    @click="clearAllNotifications"
                    class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 rounded-md px-4 py-2 text-sm font-medium text-zinc-700 hover:text-red-600 hover:bg-red-50 dark:text-zinc-300 dark:hover:text-red-400 dark:hover:bg-red-900/20 focus:outline-none"
                >
                    <TrashIcon class="size-4" />
                    <span>Очистить все</span>
                </button>
                
                <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
                    <button 
                        @click="markAllAsRead"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 rounded-md px-4 py-2 text-sm font-medium text-zinc-700 hover:text-blue-600 hover:bg-blue-50 dark:text-zinc-300 dark:hover:text-blue-400 dark:hover:bg-blue-900/20 focus:outline-none"
                        :disabled="!hasUnreadNotifications"
                        :class="{ 'opacity-50 cursor-not-allowed': !hasUnreadNotifications }"
                    >
                        <CheckIcon class="size-4" />
                        <span>Прочитать все</span>
                    </button>
                    
                    <button 
                        @click="loadNotifications"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 rounded-md px-4 py-2 text-sm font-medium text-zinc-700 hover:text-zinc-900 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:text-white dark:hover:bg-zinc-800 focus:outline-none"
                    >
                        <ArrowPathIcon class="size-4" />
                        <span>Обновить</span>
                    </button>
                </div>
            </div>
        </template>
    </SlideOverModal>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, h } from 'vue';
import axios from 'axios';
import { useAdminStore } from '@/Stores/adminStore';
import { useToast } from 'vue-toastification';
import { usePage } from '@inertiajs/vue3';
import SlideOverModal from '@/Components/Modal/SlideOverModal.vue';
import { 
    BellIcon, 
    EnvelopeOpenIcon, 
    TrashIcon,
    ArrowPathIcon,
    ShoppingCartIcon,
    UserIcon,
    CreditCardIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    XCircleIcon,
    CheckIcon,
    InboxIcon
} from '@heroicons/vue/24/outline';
import SelectList from '@/Components/SelectList.vue';

const props = defineProps({
    isOpen: {
        type: Boolean,
        required: true
    }
});

const emit = defineEmits(['close']);
const adminStore = useAdminStore();
const toast = useToast();
const page = usePage();

// Состояние компонента
const selectedType = ref('all');
const loading = ref(false);

// Опции типов уведомлений для селекта
const notificationTypeOptions = computed(() => [
    { value: 'all', label: 'Все уведомления', avatar: getIconUrl('all') },
    { value: 'order', label: 'Заказы', avatar: getIconUrl('order') },
    { value: 'user', label: 'Пользователи', avatar: getIconUrl('user') },
    { value: 'payment', label: 'Платежи', avatar: getIconUrl('payment') },
    { value: 'system', label: 'Системные', avatar: getIconUrl('system') }
]);

// Вычисляемые свойства
const filteredNotifications = computed(() => {
    return adminStore.notifications.filter(notification => {
        // Фильтрация по типу
        return selectedType.value === 'all' || notification.type === selectedType.value;
    }).sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));
});

const hasUnreadNotifications = computed(() => {
    return adminStore.notifications.some(n => !n.read);
});

// Обработчики событий жизненного цикла
onMounted(() => {
    // Используем глобальный объект Echo для подписки на уведомления
    if (window.Echo && page.props.auth.user) {
        window.Echo.private(`admin.notifications.${page.props.auth.user.id}`)
            .listen('AdminNotification', (e) => {
                adminStore.addNotification(e.notification);
                showToast(e.notification);
            });
    }

    // Загружаем уведомления с сервера при монтировании
    loadNotifications();
});

onUnmounted(() => {
    // Отписываемся от канала при размонтировании компонента
    if (window.Echo && page.props.auth.user) {
        window.Echo.leave(`admin.notifications.${page.props.auth.user.id}`);
    }
});

// Методы
const loadNotifications = async () => {
    loading.value = true;
    try {
        const response = await axios.get('/admin/notifications');
        if (response.data) {
            adminStore.setNotifications(response.data);
        }
    } catch (error) {
        console.error('Ошибка загрузки уведомлений:', error);
        toast.error('Не удалось загрузить уведомления');
    } finally {
        loading.value = false;
    }
};

// Функция для отображения всплывающего уведомления
const showToast = (notification) => {
    const toastType = notification.type === 'success' ? 'success' :
                      notification.type === 'error' ? 'error' :
                      notification.type === 'warning' ? 'warning' : 'info';
                      
    toast(notification.message, {
        type: toastType,
        timeout: 5000
    });
};

// Функция для отметки уведомления как прочитанного
const markAsRead = async (notificationId) => {
    try {
        await axios.post(`/admin/notifications/${notificationId}/read`);
        adminStore.markNotificationAsRead(notificationId);
    } catch (error) {
        console.error('Ошибка при отметке уведомления как прочитанного:', error);
        toast.error('Не удалось отметить уведомление как прочитанное');
    }
};

// Функция для отметки всех уведомлений как прочитанных
const markAllAsRead = async () => {
    if (!hasUnreadNotifications.value) return;
    
    try {
        await axios.post('/admin/notifications/read-all');
        adminStore.markAllNotificationsAsRead();
        toast.success('Все уведомления отмечены как прочитанные');
    } catch (error) {
        console.error('Ошибка при отметке всех уведомлений как прочитанных:', error);
        toast.error('Не удалось отметить все уведомления как прочитанные');
    }
};

// Функция для удаления уведомления
const deleteNotification = async (notificationId) => {
    try {
        await axios.delete(`/admin/notifications/${notificationId}`);
        adminStore.deleteNotification(notificationId);
    } catch (error) {
        console.error('Ошибка при удалении уведомления:', error);
        toast.error('Не удалось удалить уведомление');
    }
};

// Функция для очистки всех уведомлений
const clearAllNotifications = async () => {
    if (adminStore.notifications.length === 0) return;
    
    if (!confirm('Вы уверены, что хотите удалить все уведомления?')) return;
    
    try {
        await axios.delete('/admin/notifications');
        adminStore.clearNotifications();
        toast.success('Все уведомления удалены');
    } catch (error) {
        console.error('Ошибка при очистке всех уведомлений:', error);
        toast.error('Не удалось удалить все уведомления');
    }
};

// Форматирование даты для отображения
const formatDate = (timestamp) => {
    const date = new Date(timestamp);
    const now = new Date();
    const diff = now - date;
    
    // Менее часа назад - показываем "X минут назад"
    if (diff < 60 * 60 * 1000) {
        const minutes = Math.floor(diff / (60 * 1000));
        return minutes === 0 ? 'только что' : `${minutes} мин. назад`;
    }
    
    // Сегодня - показываем "Сегодня, ЧЧ:ММ"
    if (date.toDateString() === now.toDateString()) {
        return `Сегодня, ${date.toLocaleTimeString('ru-RU', {hour: '2-digit', minute: '2-digit'})}`;
    }
    
    // Вчера - показываем "Вчера, ЧЧ:ММ"
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    if (date.toDateString() === yesterday.toDateString()) {
        return `Вчера, ${date.toLocaleTimeString('ru-RU', {hour: '2-digit', minute: '2-digit'})}`;
    }
    
    // Иначе полная дата
    return date.toLocaleString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};

// Получение иконки в зависимости от типа уведомления
const getNotificationIcon = (type) => {
    switch (type) {
        case 'order':
            return ShoppingCartIcon;
        case 'user':
            return UserIcon;
        case 'payment':
            return CreditCardIcon;
        case 'success':
            return CheckCircleIcon;
        case 'warning':
            return ExclamationTriangleIcon;
        case 'error':
            return XCircleIcon;
        default:
            return BellIcon;
    }
};

// Получение класса для иконки уведомления
const getNotificationClass = (type) => {
    switch (type) {
        case 'order':
            return 'bg-blue-600';
        case 'user':
            return 'bg-green-600';
        case 'payment':
            return 'bg-purple-600';
        case 'success':
            return 'bg-green-600';
        case 'warning':
            return 'bg-yellow-600';
        case 'error':
            return 'bg-red-600';
        default:
            return 'bg-zinc-600';
    }
};

// Функция для получения URL иконки для аватарки в селекте
function getIconUrl(type) {
    // Получаем цвет в зависимости от типа
    let color;
    switch (type) {
        case 'order':
            color = '#2563eb'; // blue-600
            break;
        case 'user':
            color = '#16a34a'; // green-600
            break;
        case 'payment':
            color = '#9333ea'; // purple-600
            break;
        case 'system':
            color = '#71717a'; // zinc-500
            break;
        default:
            color = '#6b7280'; // gray-500
            break;
    }
    
    // Создаем Data URL для цветного круга
    const svg = `
    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
      <circle cx="12" cy="12" r="10" fill="${color}" />
    </svg>
    `;
    
    return `data:image/svg+xml;base64,${btoa(svg)}`;
}
</script> 