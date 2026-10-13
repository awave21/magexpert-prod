<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useToast } from 'vue-toastification';
import { ArrowLeftIcon, BookOpenIcon, CalendarDaysIcon, ChevronLeftIcon, Cog6ToothIcon, EnvelopeIcon, KeyIcon, PaperAirplaneIcon, PhoneIcon, SignalIcon, SparklesIcon, Squares2X2Icon } from '@heroicons/vue/24/outline';
import MainLogo from '@/Components/main-logo.vue';
import MobileBottomNav from '@/Components/MobileBottomNav.vue';
import CookieConsent from '@/Components/CookieConsent.vue';
import UserMenu from '@/Components/Cabinet/UserMenu.vue';
import MessagesBell from '@/Components/Cabinet/MessagesBell.vue';

// Каркас личного кабинета: боковое меню на экран высотой, верхняя панель с колокольчиком и меню пользователя.
const page = usePage();

const nav = [
    { label: 'Главная', route: 'dashboard', active: ['dashboard'], icon: Squares2X2Icon },
    { label: 'Мои доступы', route: 'my-events', active: ['my-events', 'my-events.*'], icon: KeyIcon },
    { label: 'Библиотека', route: 'documents.index', active: ['documents.*'], icon: BookOpenIcon },
    { label: 'ИИ-помощник', route: 'cabinet.assistant', active: ['cabinet.assistant'], icon: SparklesIcon },
];

const crumbs = {
    dashboard: 'Главная',
    'my-events': 'Мои доступы',
    'my-events.view': 'Мои доступы',
    'profile.edit': 'Профиль',
    'cabinet.security': 'Вход и безопасность',
    'cabinet.notifications': 'Уведомления',
    'cabinet.payments': 'Платежи',
    'cabinet.assistant': 'ИИ-помощник',
    certificates: 'Сертификаты',
};

const profileMissing = computed(() => page.props.auth.user?.profile_missing ?? []);
const isStaff = computed(() => (page.props.auth.user?.roles ?? []).some((role) => ['admin', 'editor', 'manager'].includes(role.name)));

const isActive = (item) => item.active.some((name) => route().current(name));
const crumb = computed(() => {
    const name = Object.keys(crumbs).find((key) => route().current(key));
    return name ? crumbs[name] : 'Личный кабинет';
});

// Свёрнутое меню (только иконки) запоминается в браузере. Анимация включается после монтирования,
// чтобы при переходе между страницами меню не «схлопывалось» на глазах.
const COLLAPSED_KEY = 'cabinet.sidebar.collapsed';
const collapsed = ref(false);
const animated = ref(false);

onMounted(() => {
    try {
        collapsed.value = localStorage.getItem(COLLAPSED_KEY) === '1';
    } catch {}
    requestAnimationFrame(() => { animated.value = true; });
});

const toggleCollapsed = () => {
    collapsed.value = !collapsed.value;
    try {
        localStorage.setItem(COLLAPSED_KEY, collapsed.value ? '1' : '0');
    } catch {}
};

// Сегодняшняя дата в верхней панели: «Пн, 12 октября», на широком экране «Понедельник, 12 октября».
const now = new Date();
const capitalize = (text) => text.charAt(0).toUpperCase() + text.slice(1);
const todayIso = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
const todayDay = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long' }).format(now);
const todayLong = `${capitalize(new Intl.DateTimeFormat('ru-RU', { weekday: 'long' }).format(now))}, ${todayDay}`;
const todayShort = `${capitalize(new Intl.DateTimeFormat('ru-RU', { weekday: 'short' }).format(now))}, ${todayDay}`;

const toast = useToast();
watch(() => page.props.flash?.message, (message) => {
    if (message) {
        toast.success(message, { position: 'top-center', timeout: 6000 });
    }
}, { immediate: true });
watch(() => page.props.flash?.error, (error) => {
    if (error) {
        toast.error(error, { position: 'top-center', timeout: 8000 });
    }
}, { immediate: true });
</script>

<template>
    <div class="min-h-screen bg-cabinet font-sans text-gray-900 dark:bg-gray-950 dark:text-white">
        <!-- Мобильная шапка -->
        <header class="sticky top-0 z-40 flex items-center gap-2 border-b border-gray-200 bg-white px-3 py-2 lg:hidden dark:border-gray-800 dark:bg-gray-900">
            <Link :href="route('welcome')" class="flex items-center" aria-label="МедАльянсГрупп Expert — на главную">
                <MainLogo :width="146" :height="26" />
            </Link>
            <span class="flex-1"></span>
            <MessagesBell />
            <UserMenu compact />
        </header>

        <div class="mx-auto flex max-w-[1440px] items-start gap-8 px-4 pb-28 pt-4 sm:px-6 lg:px-8 lg:pb-20 lg:pt-6">
            <!-- Боковое меню: высотой в экран и остаётся на месте при прокрутке; сворачивается до иконок -->
            <div
                class="sticky top-6 hidden shrink-0 lg:block"
                :class="[collapsed ? 'w-20' : 'w-[272px]', animated && 'transition-[width] duration-300 ease-[cubic-bezier(.2,.8,.2,1)] motion-reduce:transition-none']"
            >
                <button
                    type="button"
                    class="cab-focus absolute -right-3 top-[30px] z-10 flex h-6 w-6 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-400 shadow-[0_2px_8px_-2px_rgb(15_23_42/.18)] transition hover:scale-110 hover:border-brandblue hover:text-brandblue-dark dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                    :aria-expanded="!collapsed"
                    :aria-label="collapsed ? 'Развернуть меню' : 'Свернуть меню'"
                    :title="collapsed ? 'Развернуть меню' : 'Свернуть меню'"
                    @click="toggleCollapsed"
                >
                    <ChevronLeftIcon class="h-3.5 w-3.5 stroke-[2.5] transition-transform duration-300 ease-[cubic-bezier(.2,.8,.2,1)]" :class="collapsed && 'rotate-180'" aria-hidden="true" />
                </button>
                <aside class="cab-panel flex h-[calc(100vh-48px)] max-h-[900px] w-full flex-col overflow-y-auto overflow-x-hidden px-3.5 pb-4 pt-6">
                    <Link
                        :href="route('welcome')"
                        class="mb-5 flex shrink-0 overflow-hidden"
                        :class="[collapsed ? 'w-[31px] translate-x-[11px]' : 'w-[196px] translate-x-3.5', animated && 'transition-all duration-300 ease-[cubic-bezier(.2,.8,.2,1)] motion-reduce:transition-none']"
                        aria-label="МедАльянсГрупп Expert — на главную"
                    >
                        <MainLogo :width="196" :height="35" class="shrink-0" />
                    </Link>
                    <nav aria-label="Личный кабинет" class="flex flex-col gap-1">
                        <Link
                            v-for="item in nav"
                            :key="item.route"
                            :href="route(item.route)"
                            class="cab-focus flex min-h-[46px] items-center gap-3 overflow-hidden whitespace-nowrap rounded-full px-4 text-[15px] font-bold transition"
                            :class="isActive(item)
                                ? 'bg-brandblue text-white shadow-[0_10px_22px_-10px_#6186b6bf]'
                                : 'text-gray-900 hover:bg-gray-100 dark:text-white dark:hover:bg-gray-800'"
                            :aria-current="isActive(item) ? 'page' : undefined"
                            :title="collapsed ? item.label : undefined"
                        >
                            <component :is="item.icon" class="h-5 w-5 shrink-0" aria-hidden="true" />
                            <span class="transition-opacity" :class="collapsed ? 'opacity-0 duration-100' : 'opacity-100 duration-200 delay-150'">{{ item.label }}</span>
                        </Link>
                        <!-- Прямой эфир: раздел готовится, пункт неактивен -->
                        <span
                            class="flex min-h-[46px] cursor-not-allowed select-none items-center gap-3 overflow-hidden whitespace-nowrap rounded-full px-4 text-[15px] font-bold text-gray-400 dark:text-gray-500"
                            role="link"
                            aria-disabled="true"
                            :title="collapsed ? 'Прямой эфир — скоро' : 'Скоро'"
                        >
                            <SignalIcon class="h-5 w-5 shrink-0" aria-hidden="true" />
                            <span class="flex items-center gap-2 transition-opacity" :class="collapsed ? 'opacity-0 duration-100' : 'opacity-100 duration-200 delay-150'">
                                Прямой эфир
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wider text-gray-400 dark:bg-gray-800 dark:text-gray-500">скоро</span>
                            </span>
                        </span>
                        <a
                            v-if="isStaff"
                            :href="route('admin.index')"
                            class="cab-focus mt-2 flex min-h-[46px] items-center gap-3 overflow-hidden whitespace-nowrap rounded-full border border-gray-200 px-[15px] text-[15px] font-bold text-gray-900 hover:border-brandblue hover:text-brandblue-dark dark:border-gray-700 dark:text-white"
                            :title="collapsed ? 'Админка' : undefined"
                        >
                            <Cog6ToothIcon class="h-5 w-5 shrink-0" aria-hidden="true" />
                            <span class="transition-opacity" :class="collapsed ? 'opacity-0 duration-100' : 'opacity-100 duration-200 delay-150'">Админка</span>
                        </a>
                    </nav>

                    <div class="min-h-6 flex-1"></div>

                    <!-- Карточка сообщества сжимается в круглую кнопку: ширина, высота и скругление анимируются,
                         текст (фиксированной ширины, чтобы не переносился) гаснет, в центре проявляется самолётик -->
                    <a
                        href="https://t.me/beautifulgynecology"
                        target="_blank"
                        rel="noopener"
                        class="cab-focus relative flex min-h-[46px] flex-col overflow-hidden bg-gradient-to-br from-brandblue to-brandblue-dark text-white"
                        :class="[
                            collapsed ? 'mx-[3px] w-[46px] rounded-[23px] p-0 shadow-[0_10px_22px_-10px_#6186b6bf]' : 'mx-1 w-[236px] rounded-[22px] p-[18px]',
                            animated && 'transition-all duration-300 ease-[cubic-bezier(.2,.8,.2,1)] motion-reduce:transition-none',
                        ]"
                        :title="collapsed ? 'Сообщество в Telegram «Красивая гинекология»' : undefined"
                        :aria-label="collapsed ? 'Сообщество в Telegram «Красивая гинекология»' : undefined"
                    >
                        <div class="grid" :class="[collapsed ? 'grid-rows-[0fr]' : 'grid-rows-[1fr]', animated && 'transition-[grid-template-rows] duration-300 ease-[cubic-bezier(.2,.8,.2,1)]']">
                            <div
                                class="flex w-[200px] min-h-0 flex-col gap-2.5 overflow-hidden"
                                :class="[collapsed ? '-translate-y-1 opacity-0 duration-100' : 'opacity-100 duration-200 delay-150', animated && 'transition']"
                            >
                                <span class="text-[11px] font-extrabold uppercase tracking-[.14em] text-[#e3ebf5]">Сообщество в Telegram</span>
                                <span class="font-display text-xl font-medium leading-6">«Красивая гинекология»</span>
                                <span class="text-[13px] text-[#e3ebf5]">Больше 1 000 врачей в сообществе</span>
                                <span class="inline-flex min-h-10 items-center self-start rounded-full bg-white px-4 text-[13px] font-extrabold text-brandblue-dark">Вступить в канал</span>
                            </div>
                        </div>
                        <span
                            class="pointer-events-none absolute inset-0 flex items-center justify-center"
                            :class="[collapsed ? 'scale-100 opacity-100 delay-150 duration-200' : 'scale-75 opacity-0 duration-100', animated && 'transition']"
                            aria-hidden="true"
                        >
                            <PaperAirplaneIcon class="h-5 w-5 -rotate-12" />
                        </span>
                    </a>

                    <!-- Контакты: в свёрнутом меню — иконки. Оба варианта в DOM, переключаются v-show -->
                    <div v-show="collapsed" class="flex flex-col items-center gap-1">
                        <span class="my-2 h-px w-8 bg-gray-100 dark:bg-gray-800" aria-hidden="true"></span>
                        <a href="tel:+79952220779" class="cab-focus flex h-[42px] w-[42px] items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-brandblue-dark dark:text-gray-400 dark:hover:bg-gray-800" title="+7 (995) 222-07-79" aria-label="Позвонить: +7 (995) 222-07-79">
                            <PhoneIcon class="h-5 w-5" aria-hidden="true" />
                        </a>
                        <a href="mailto:info@mag-expert.ru" class="cab-focus flex h-[42px] w-[42px] items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-brandblue-dark dark:text-gray-400 dark:hover:bg-gray-800" title="info@mag-expert.ru" aria-label="Написать: info@mag-expert.ru">
                            <EnvelopeIcon class="h-5 w-5" aria-hidden="true" />
                        </a>
                    </div>
                    <div v-show="!collapsed" class="mx-1 mt-3.5 flex min-w-[216px] flex-col gap-0.5 border-t border-gray-100 px-3.5 pt-3.5 dark:border-gray-800">
                        <span class="text-sm font-bold">Нужна помощь?</span>
                        <a href="tel:+79952220779" class="text-[13px] font-semibold leading-[22px] text-gray-500 hover:text-brandblue-dark dark:text-gray-400">+7 (995) 222-07-79</a>
                        <a href="mailto:info@mag-expert.ru" class="text-[13px] font-semibold leading-[22px] text-gray-500 hover:text-brandblue-dark dark:text-gray-400">info@mag-expert.ru</a>
                    </div>
                </aside>
            </div>

            <main class="flex min-w-0 flex-1 flex-col gap-8">
                <!-- Верхняя панель (десктоп): прилипает к верху экрана; вокруг прозрачно, сама панель — матовое стекло -->
                <div class="cab-panel cab-glass sticky top-6 z-30 hidden items-center gap-2.5 !rounded-full py-2 pl-3 pr-2 lg:flex">
                    <Link :href="route('welcome')" class="cab-btn-ghost !min-h-11 shrink-0 !pl-3 !pr-4">
                        <ArrowLeftIcon class="h-[18px] w-[18px]" aria-hidden="true" />
                        На сайт
                    </Link>
                    <nav aria-label="Вы здесь" class="flex min-w-0 items-center gap-2 whitespace-nowrap pl-2 text-sm font-semibold text-gray-500 dark:text-gray-400">
                        <Link :href="route('dashboard')" class="hover:text-brandblue-dark">Личный кабинет</Link>
                        <span aria-hidden="true">/</span>
                        <span class="truncate font-bold text-gray-900 dark:text-white">{{ crumb }}</span>
                    </nav>
                    <span class="min-w-0 flex-1"></span>
                    <!-- Дата: при развёрнутом меню места хватает только с 1280px -->
                    <span
                        class="shrink-0 items-center gap-2 whitespace-nowrap pr-1.5 text-sm font-semibold text-gray-500 dark:text-gray-400"
                        :class="collapsed ? 'inline-flex' : 'hidden xl:inline-flex'"
                    >
                        <CalendarDaysIcon class="h-[18px] w-[18px]" aria-hidden="true" />
                        <time :datetime="todayIso" :title="todayLong">
                            <span class="2xl:hidden">{{ todayShort }}</span>
                            <span class="hidden 2xl:inline">{{ todayLong }}</span>
                        </time>
                    </span>
                    <MessagesBell />
                    <UserMenu />
                </div>

                <!-- до полного доступа: что осталось сделать -->
                <div
                    v-if="profileMissing.length"
                    role="status"
                    class="flex flex-wrap items-center gap-3 rounded-3xl border border-amber-200 bg-amber-50 p-4 text-amber-900 dark:border-amber-900/50 dark:bg-amber-900/20 dark:text-amber-100"
                >
                    <span class="min-w-0 flex-[1_1_240px] text-[15px] leading-6">
                        <b>Откройте все разделы:</b> {{ profileMissing.join(', ') }}.
                    </span>
                    <Link :href="route('profile.edit')" class="cab-btn !min-h-10 !px-4 !text-[13px]">Заполнить профиль</Link>
                </div>

                <slot />
            </main>
        </div>

        <CookieConsent />
        <MobileBottomNav />
    </div>
</template>
