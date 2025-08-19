<script setup>
import { ref, computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { 
    HomeIcon, 
    BookOpenIcon, 
    CalendarIcon, 
    DocumentTextIcon, 
    UserIcon,
    ArrowRightOnRectangleIcon 
} from '@heroicons/vue/24/outline';
import axios from 'axios';
import { useToast } from 'vue-toastification';

// Получаем данные авторизованного пользователя
const page = usePage();
const user = computed(() => page.props.auth.user);

// Пропсы для статистики (если переданы)
const props = defineProps({
    stats: {
        type: Object,
        default: () => null
    }
});

// Используем статистику из глобальных данных пользователя или переданную статистику
const displayStats = computed(() => {
    // Приоритет: глобальная статистика из user.stats, затем переданная
    const globalStats = user.value?.stats;
    const localStats = props.stats;
    
    if (globalStats) {
        return {
            availableEvents: globalStats.availableEvents || globalStats.total || 0,
            completedEvents: globalStats.archive || 0
        };
    }
    
    // Fallback на локальную статистику только если нет глобальной
    if (localStats) {
        return {
            availableEvents: localStats.availableEvents || localStats.total || 0,
            completedEvents: localStats.archive || 0
        };
    }
    
    return {
        availableEvents: 0,
        completedEvents: 0
    };
});

// Пункты меню с правильными маршрутами
const menuItems = [
    { name: 'Сводка', href: route('dashboard'), route: 'Dashboard', icon: HomeIcon },
    { name: 'Профиль', href: route('profile.edit'), route: 'Profile/Edit', icon: UserIcon },
    { name: 'Мероприятия', href: route('my-events'), route: 'MyEvents', icon: CalendarIcon },
    { name: 'Сертификаты', href: route('certificates'), route: 'Certificates', icon: DocumentTextIcon },
];

// Получаем текущий компонент и его имя
const currentComponent = computed(() => usePage().component);
const isMobileMenuOpen = ref(false);

// Проверяем, активен ли пункт меню
const isActive = (itemRoute) => {
    // Проверяем точное совпадение или начало строки для вложенных маршрутов
    return currentComponent.value === itemRoute || 
           (itemRoute && currentComponent.value.startsWith(itemRoute));
};

// Получаем аватар пользователя или используем компонент иконки
const userAvatar = computed(() => {
    return user.value.avatar || null;
});

// Используем иконку профиля как заглушку, если аватар отсутствует
const hasAvatar = computed(() => Boolean(user.value.avatar));

// Состояние и логика выхода с обходом 419
const isLoggingOut = ref(false);
const toast = useToast();

const refreshCsrfToken = async () => {
    try {
        const response = await axios.get('/csrf-token');
        const token = response?.data?.csrf_token;
        if (token) {
            document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', token);
            return true;
        }
    } catch (e) {
        console.error('Ошибка при обновлении CSRF-токена:', e);
    }
    return false;
};

const logout = async () => {
    if (isLoggingOut.value) return;
    isLoggingOut.value = true;
    const doRequest = async () => {
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            // Используем axios для явного контроля заголовков и статусов
            await axios.post(route('logout'), {}, {
                headers: { 'X-CSRF-TOKEN': csrfToken || '' },
            });
            // После успешного выхода переходим на страницу входа
            router.visit(route('login'));
        } catch (error) {
            const status = error?.response?.status;
            if (status === 419) {
                const refreshed = await refreshCsrfToken();
                if (refreshed) {
                    toast.info('Сессия обновлена, выходим...');
                    return doRequest();
                }
                toast.error('Ошибка сессии. Пожалуйста, обновите страницу.');
            } else {
                toast.error('Ошибка при выходе из системы');
            }
        } finally {
            isLoggingOut.value = false;
        }
    };
    doRequest();
};
</script>

<template>
    <MainLayout>
        <div class="min-h-screen bg-gradient-to-br from-brandblue/[0.03] to-white/95 dark:from-brandblue/10 dark:to-gray-900">
            <!-- Шапка профиля -->
            <div class="border-b border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <div class="mx-auto max-w-[1440px] px-3 py-4 sm:px-6 sm:py-6 lg:px-8">
                    <div class="flex flex-col items-start justify-between gap-3 sm:gap-4 md:flex-row md:items-center">
                        <!-- Информация о пользователе -->
                        <div class="flex items-center">
                            <div class="mr-3 sm:mr-4 h-12 w-12 sm:h-16 sm:w-16 flex-shrink-0 overflow-hidden rounded-full border-2 border-brandblue/20">
                                <img v-if="hasAvatar" :src="userAvatar" alt="Аватар пользователя" class="h-full w-full object-cover" />
                                <div v-else class="flex h-full w-full items-center justify-center bg-gray-100 dark:bg-gray-700">
                                    <UserIcon class="h-6 w-6 sm:h-10 sm:w-10 text-gray-500 dark:text-gray-400" />
                                </div>
                            </div>
                            <div>
                                <h1 class="text-lg sm:text-xl font-bold text-gray-900 dark:text-white">{{ user.full_name }}</h1>
                                <p v-if="user.position" class="text-xs sm:text-sm text-gray-600 dark:text-gray-300">{{ user.position }}</p>
                                <p v-if="user.company" class="text-xs sm:text-sm text-gray-500 dark:text-gray-400">{{ user.company }}</p>
                            </div>
                        </div>
                        
                        <!-- Статистика -->
                        <div class="flex w-full flex-col sm:flex-row flex-wrap gap-2 sm:gap-4 md:w-auto">
                            <div class="flex items-center rounded-full bg-brandblue/10 px-3 sm:px-4 py-2 dark:bg-brandblue/20">
                                <CalendarIcon class="mr-2 h-4 w-4 sm:h-5 sm:w-5 text-brandblue flex-shrink-0" />
                                <span class="text-xs sm:text-sm font-medium text-gray-900 dark:text-white">{{ displayStats.availableEvents }} доступных мероприятий</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mx-auto max-w-[1440px] px-4 sm:px-6 lg:px-8">
                <div class="py-6">
                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
                        <!-- Мобильное меню -->
                        <div class="lg:hidden">
                            <button 
                                @click="isMobileMenuOpen = !isMobileMenuOpen" 
                                class="flex w-full items-center justify-between rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                            >
                                <span class="text-gray-900 dark:text-white">Меню</span>
                                <svg 
                                    xmlns="http://www.w3.org/2000/svg" 
                                    class="h-5 w-5 text-gray-500 dark:text-gray-400" 
                                    :class="{'transform rotate-180': isMobileMenuOpen}"
                                    fill="none" 
                                    viewBox="0 0 24 24" 
                                    stroke="currentColor"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                            
                            <div v-if="isMobileMenuOpen" class="mt-2">
                                <nav class="rounded-xl border border-gray-200 bg-white p-2 dark:border-gray-700 dark:bg-gray-800">
                                    <Link 
                                        v-for="item in menuItems" 
                                        :key="item.name" 
                                        :href="item.href"
                                        class="mb-1 flex items-center rounded-xl px-4 py-3 text-gray-600 transition-colors dark:text-gray-300"
                                        :class="{'bg-brandblue/10 text-brandblue dark:bg-brandblue/20 dark:text-brandblue': isActive(item.route)}"
                                    >
                                        <component 
                                            :is="item.icon" 
                                            class="mr-3 h-5 w-5" 
                                            :class="{'text-brandblue': isActive(item.route)}"
                                        />
                                        {{ item.name }}
                                    </Link>
                                    
                                    <button 
                                        type="button"
                                        @click="logout"
                                        :disabled="isLoggingOut"
                                        class="flex w-full items-center rounded-xl px-4 py-3 text-gray-600 transition-colors hover:bg-gray-50 disabled:opacity-50 dark:text-gray-300 dark:hover:bg-gray-700/50"
                                    >
                                        <ArrowRightOnRectangleIcon class="mr-3 h-5 w-5 text-gray-500" />
                                        Выйти
                                    </button>
                                </nav>
                            </div>
                        </div>
                        
                        <!-- Боковая панель (десктоп) -->
                        <div class="hidden lg:block lg:col-span-1">
                            <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                                <div class="p-4">
                                    <nav class="space-y-1">
                                        <Link 
                                            v-for="item in menuItems" 
                                            :key="item.name" 
                                            :href="item.href"
                                            class="flex items-center rounded-xl px-4 py-3 text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700/50"
                                            :class="{'bg-brandblue/10 text-brandblue dark:bg-brandblue/20 dark:text-brandblue': isActive(item.route)}"
                                        >
                                            <component 
                                                :is="item.icon" 
                                                class="mr-3 h-5 w-5" 
                                                :class="{'text-brandblue': isActive(item.route)}"
                                            />
                                            {{ item.name }}
                                        </Link>
                                    </nav>
                                </div>
                                <div class="border-t border-gray-200 p-4 dark:border-gray-700">
                                    <button 
                                        type="button"
                                        @click="logout"
                                        :disabled="isLoggingOut"
                                        class="flex w-full items-center rounded-xl px-4 py-3 text-gray-600 transition-colors hover:bg-gray-50 disabled:opacity-50 dark:text-gray-300 dark:hover:bg-gray-700/50"
                                    >
                                        <ArrowRightOnRectangleIcon class="mr-3 h-5 w-5 text-gray-500" />
                                        Выйти
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Основное содержимое -->
                        <div class="lg:col-span-3">
                            <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                                <slot></slot>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>
</template> 