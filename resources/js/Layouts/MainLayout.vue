<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDownIcon, EnvelopeIcon, PhoneIcon } from '@heroicons/vue/24/outline';
import MainLogo from '@/Components/main-logo.vue';
import YoutubeIcon from '@/Components/Social/YoutubeIcon.vue';
import TelegramIcon from '@/Components/Social/TelegramIcon.vue';
import VkIcon from '@/Components/Social/VkIcon.vue';
import SocialLink from '@/Components/Social/SocialLink.vue';
import SlideOverModal from '@/Components/Modal/SlideOverModal.vue';
import MobileMenu from '@/Components/Navigation/MobileMenu.vue';

// Константы для социальных сетей
const socialLinks = [
    {
        href: 'https://www.youtube.com/channel/UCGrXZfRQ5rUHr7aBYQG3-cg',
        label: 'YouTube',
        hoverColor: 'red',
        component: YoutubeIcon
    },
    {
        href: 'https://t.me/beautifulgynecology',
        label: 'Telegram',
        hoverColor: 'blue',
        component: TelegramIcon
    },
    {
        href: 'https://vk.com/medalliancegroup',
        label: 'ВКонтакте',
        hoverColor: 'vkblue',
        component: VkIcon
    }
];

// Элементы навигационного меню
const navigation = [
    { name: 'Главная', href: route('welcome') },
    { name: 'Мероприятия', href: route('events.index') },
    { name: 'Медицинская библиотека', href: route('documents.index') },
    { name: 'Виртуальная выставка', href: route('exhibition.index') },
    { name: 'Контакты', href: route('contacts.index') },
];

// Состояние мобильного меню
const isOpen = ref(false);

// Состояние выпадающего меню
const openDropdown = ref(null);
const hoverTimeout = ref(null);

// Состояние для отслеживания скролла
const isScrolled = ref(false);

// Утилиты для определения активного пути
const page = usePage();
const currentUrl = computed(() => page.url);

const toPath = (href) => {
    if (!href) return '/';
    try {
        if (typeof href === 'string' && /^(https?:)?\/\//i.test(href)) {
            return new URL(href).pathname || '/';
        }
        const base = typeof window !== 'undefined' ? window.location.origin : 'http://localhost';
        return new URL(href, base).pathname || '/';
    } catch (e) {
        return typeof href === 'string' && href.startsWith('/') ? href : '/';
    }
};

const isActive = (href) => {
    const path = toPath(href);
    if (path === '/') {
        return currentUrl.value === '/' || currentUrl.value.startsWith('/?');
    }
    return (
        currentUrl.value === path ||
        currentUrl.value.startsWith(path + '/') ||
        currentUrl.value.startsWith(path + '?')
    );
};

const isParentActive = (href) => {
    const path = toPath(href);
    if (path === '/') {
        return currentUrl.value === '/' || currentUrl.value.startsWith('/?');
    }
    return currentUrl.value.startsWith(path);
};

// Функция для открытия выпадающего меню с задержкой при наведении
const handleMouseEnter = (index) => {
    clearTimeout(hoverTimeout.value);
    hoverTimeout.value = setTimeout(() => {
        openDropdown.value = index;
    }, 100); // Небольшая задержка для предотвращения случайных срабатываний
};

// Функция для закрытия выпадающего меню с задержкой при уходе курсора
const handleMouseLeave = () => {
    clearTimeout(hoverTimeout.value);
    hoverTimeout.value = setTimeout(() => {
        openDropdown.value = null;
    }, 300); // Задержка перед закрытием, чтобы пользователь успел навести на меню
};

// Вычисляемое свойство для анимации выпадающего меню
const dropdownClasses = computed(() => index => ({
    'transform opacity-0 scale-95': openDropdown.value !== index,
    'transform opacity-100 scale-100': openDropdown.value === index,
}));

// Обработчик для закрытия выпадающего меню при клике вне его
const handleClickOutside = (event) => {
    if (!event.target.closest('.dropdown-container')) {
        openDropdown.value = null;
    }
};

// Обработчик скролла для добавления тени к шапке
const handleScroll = () => {
    isScrolled.value = window.scrollY > 10;
};

// Добавляем обработчики событий
onMounted(() => {
    document.addEventListener('click', handleClickOutside);
    window.addEventListener('scroll', handleScroll);
    // Проверяем начальное положение скролла
    handleScroll();
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    window.removeEventListener('scroll', handleScroll);
});

// Функция для переключения выпадающего меню по клику
const toggleDropdown = (index) => {
    openDropdown.value = openDropdown.value === index ? null : index;
};

// Вычисляемые классы для шапки
const headerClasses = computed(() => [
    'sticky top-0 z-30 border-b border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800 transition-all duration-300',
    isScrolled.value ? 'border-gray-300 dark:border-gray-600' : ''
]);

// Текущий год для футера
const currentYear = new Date().getFullYear();
</script>

<template>
    <div class="flex min-h-screen flex-col">
        <!-- Топ бар с контактами и соц. сетями -->
        <div class="hidden border-b border-gray-100 bg-gray-50 py-3 dark:border-gray-800 dark:bg-gray-900 min-[1150px]:block">
            <div class="mx-auto flex max-w-[1440px] items-center justify-between px-4 text-sm text-gray-500 dark:text-gray-400 sm:px-6 lg:px-8">
                <div class="flex items-center space-x-6">
                    <a href="mailto:info@gin-kongress.ru" class="hover:text-brandblue">
                        <span class="flex items-center">
                            <EnvelopeIcon class="mr-1.5 h-4 w-4" />
                            info@gin-kongress.ru
                        </span>
                    </a>
                    <a href="tel:+74956646753" class="hover:text-brandblue">
                        <span class="flex items-center">
                            <PhoneIcon class="mr-1.5 h-4 w-4" />
                            +7 (495) 664-67-53
                        </span>
                    </a>
                    <a href="tel:+79952220779" class="hover:text-brandblue">
                        <span class="flex items-center">
                            <PhoneIcon class="mr-1.5 h-4 w-4" />
                            +7 (995) 222-07-79
                        </span>
                    </a>
                </div>
                
                <div class="flex items-center space-x-4">
                    <SocialLink 
                        v-for="social in socialLinks"
                        :key="social.label"
                        :href="social.href"
                        :label="social.label"
                        :hover-color="social.hoverColor"
                    >
                        <component :is="social.component" size="16" classes="h-4 w-4" />
                    </SocialLink>
                </div>
            </div>
        </div>
        
        <!-- Шапка сайта (фиксированная при скролле) -->
        <header :class="headerClasses">
            <div class="mx-auto max-w-[1440px] px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between min-[1150px]:h-20">
                    <!-- Логотип -->
                    <div class="flex flex-shrink-0 items-center">
                        <Link href="/">
                            <MainLogo :width="180" :height="32" />
                        </Link>
                    </div>
                    
                    <!-- Навигация для десктопа -->
                    <div class="hidden min-[1150px]:block">
                        <div class="ml-10 inline-flex items-center rounded-full bg-gray-100 p-1.5 dark:bg-gray-700">
                            <template v-for="(item, index) in navigation" :key="item.name">
                                <div v-if="item.hasDropdown" class="relative dropdown-container"
                                    @mouseenter="handleMouseEnter(index)"
                                    @mouseleave="handleMouseLeave"
                                >
                                    <button 
                                        @click.stop="toggleDropdown(index)" 
                                         class="group flex items-center rounded-full px-5 py-2 text-sm font-medium text-gray-700 transition-all hover:bg-white hover:shadow-sm dark:text-gray-200 dark:hover:bg-gray-800"
                                         :class="[
                                         isParentActive(item.href)
                                            ? 'bg-white text-brandblue shadow-sm dark:bg-gray-800 dark:text-brandblue'
                                            : ''
                                         ]"
                                    >
                                        {{ item.name }}
                                        <ChevronDownIcon class="ml-1.5 h-4 w-4 transition-transform duration-200" :class="{'rotate-180': openDropdown === index}" />
                                    </button>
                                    <div 
                                        v-show="openDropdown === index"
                                        class="absolute left-0 mt-2 w-64 origin-top-left rounded-2xl bg-gray-100 p-1.5 shadow-lg transition-all duration-200 ease-out dark:bg-gray-700"
                                        :class="dropdownClasses(index)"
                                         @mouseenter="clearTimeout(hoverTimeout.value)"
                                        @mouseleave="handleMouseLeave"
                                    >
                                        <Link 
                                            v-for="dropItem in item.dropdownItems" 
                                            :key="dropItem.name" 
                                            :href="dropItem.href"
                                             class="block rounded-xl px-4 py-2.5 text-sm text-gray-700 transition-all hover:bg-white hover:shadow-sm dark:text-gray-200 dark:hover:bg-gray-800"
                                             :class="[
                                                isActive(dropItem.href)
                                                  ? 'bg-white text-brandblue shadow-sm dark:bg-gray-800 dark:text-brandblue'
                                                  : ''
                                             ]"
                                            @click="openDropdown = null"
                                        >
                                            {{ dropItem.name }}
                                        </Link>
                                    </div>
                                </div>
                                <Link
                                    v-else
                                    :href="item.href"
                                     class="rounded-full px-4 py-2 text-sm font-medium text-gray-700 transition-all hover:bg-white hover:shadow-sm dark:text-gray-200 dark:hover:bg-gray-800"
                                     :class="[
                                        isActive(item.href)
                                        ? 'bg-white text-brandblue shadow-sm dark:bg-gray-800 dark:text-brandblue'
                                        : ''
                                     ]"
                                >
                                    {{ item.name }}
                                </Link>
                            </template>
                        </div>
                    </div>
                    
                    <!-- Кнопки авторизации -->
                    <div class="hidden min-[1150px]:block">
                        <div class="flex items-center space-x-3">
                            <Link 
                                :href="route('login')" 
                                class="rounded-full px-5 py-2 text-sm font-medium text-gray-700 transition-all hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
                                v-if="!$page.props.auth.user"
                            >
                                Войти
                            </Link>
                            
                            <Link 
                                :href="route('register')" 
                                class="rounded-full bg-brandblue px-5 py-2 text-sm font-medium text-white transition-colors hover:bg-brandblue/90 dark:bg-brandblue dark:hover:bg-brandblue/90"
                                v-if="!$page.props.auth.user"
                            >
                                Регистрация
                            </Link>
                            
                            <Link 
                                :href="route('dashboard')" 
                                class="rounded-full bg-brandcoral/10 px-5 py-3 text-sm font-medium text-brandcoral transition-colors hover:bg-brandcoral/90 hover:text-white dark:bg-brandcoral dark:hover:bg-brandcoral/90"
                                v-if="$page.props.auth.user"
                            >
                                Личный кабинет
                            </Link>
                        </div>
                    </div>
                    
                    <!-- Мобильное меню (кнопка) -->
                    <div class="flex min-[1150px]:hidden">
                        <button
                            @click="isOpen = !isOpen"
                            class="inline-flex items-center justify-center rounded-md p-2 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                            aria-expanded="false"
                        >
                            <span class="sr-only">Открыть меню</span>
                            <!-- Иконка меню -->
                            <svg
                                v-if="!isOpen"
                                class="h-6 w-6"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                aria-hidden="true"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                            <!-- Иконка закрытия -->
                            <svg
                                v-else
                                class="h-6 w-6"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                aria-hidden="true"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Мобильное меню в слайдовой модалке -->
            <SlideOverModal :show="isOpen" @close="isOpen = false">
                <template #title>
                    Меню
                </template>

                <MobileMenu
                    :navigation="navigation"
                    :current-url="$page.url"
                    :is-auth="$page.props.auth.user !== null && $page.props.auth.user !== undefined"
                    @close="isOpen = false"
                />

                <template #footer>
                    <div class="space-y-3">
                        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                            <a href="mailto:info@gin-kongress.ru" class="flex items-center gap-2 hover:text-brandblue">
                                <EnvelopeIcon class="h-4 w-4" />
                                info@gin-kongress.ru
                            </a>
                            <a href="tel:+74956646753" class="flex items-center gap-2 hover:text-brandblue">
                                <PhoneIcon class="h-4 w-4" />
                                +7 (495) 664-67-53
                            </a>
                            <a href="tel:+79952220779" class="flex items-center gap-2 hover:text-brandblue">
                                <PhoneIcon class="h-4 w-4" />
                                +7 (995) 222-07-79
                            </a>
                        </div>
                        <div class="flex items-center gap-3">
                            <SocialLink 
                                v-for="social in socialLinks"
                                :key="social.label"
                                :href="social.href"
                                :label="social.label"
                                :hover-color="social.hoverColor"
                            >
                                <component :is="social.component" size="20" classes="h-5 w-5" />
                            </SocialLink>
                        </div>
                    </div>
                </template>
            </SlideOverModal>
        </header>
        
        <!-- Основное содержимое -->
        <main class="flex-1">
            <slot></slot>
        </main>
        
        <!-- Подвал сайта -->
        <footer class="border-t border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-6 lg:px-8">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="mb-6 md:mb-0">
                        <MainLogo class="h-12 w-auto" />
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                            Платформа для профессионалов
                        </p>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-8 sm:grid-cols-3">
                        <div>
                            <h3 class="mb-4 text-sm font-semibold uppercase text-gray-500 dark:text-gray-400">Навигация</h3>
                            <ul class="space-y-2 text-sm">
                                <li v-for="item in navigation" :key="item.name">
                                    <Link :href="item.href" class="text-gray-600 hover:text-brandblue hover:underline dark:text-gray-300 dark:hover:text-brandblue">{{ item.name }}</Link>
                                </li>
                            </ul>
                        </div>
                        
                        <div>
                            <h3 class="mb-4 text-sm font-semibold uppercase text-gray-500 dark:text-gray-400">Связаться</h3>
                            <ul class="space-y-2 text-sm">
                                <li>
                                    <Link :href="route('contacts.index')" class="text-gray-600 hover:text-brandblue hover:underline dark:text-gray-300 dark:hover:text-brandblue">Контакты</Link>
                                </li>
                                <li>
                                    <a href="mailto:info@gin-kongress.ru" class="text-gray-600 hover:text-brandblue hover:underline dark:text-gray-300 dark:hover:text-brandblue">info@gin-kongress.ru</a>
                                </li>
                                <li>
                                    <a href="tel:+74956646753" class="text-gray-600 hover:text-brandblue hover:underline dark:text-gray-300 dark:hover:text-brandblue">+7 (495) 664-67-53</a>
                                </li>
                                <li>
                                    <a href="tel:+79952220779" class="text-gray-600 hover:text-brandblue hover:underline dark:text-gray-300 dark:hover:text-brandblue">+7 (995) 222-07-79</a>
                                </li>
                            </ul>
                        </div>
                        
                        <div>
                            <h3 class="mb-4 text-sm font-semibold uppercase text-gray-500 dark:text-gray-400">Социальные сети</h3>
                            <div class="flex space-x-4">
                                <SocialLink 
                                    v-for="social in socialLinks"
                                    :key="social.label"
                                    :href="social.href"
                                    :label="social.label"
                                    :hover-color="social.hoverColor"
                                >
                                    <component :is="social.component" size="20" classes="h-5 w-5" />
                                </SocialLink>
                            </div>
                        </div>
                    </div>
                </div>
                
                <hr class="my-6 border-gray-200 dark:border-gray-700 sm:mx-auto lg:my-8" />
                
                <div class="flex flex-col items-center justify-between sm:flex-row">
                    <span class="mb-4 text-sm text-gray-500 dark:text-gray-400 sm:mb-0">
                        © {{ currentYear }} МедАльянсГрупп Expert. Все права защищены.
                    </span>
                </div>
            </div>
        </footer>
    </div>
</template> 