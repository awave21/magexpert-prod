<script setup>
import { computed, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useToast } from 'vue-toastification';
import { ArrowLeftIcon, BookOpenIcon, CalendarDaysIcon, Cog6ToothIcon, Squares2X2Icon } from '@heroicons/vue/24/outline';
import MainLogo from '@/Components/main-logo.vue';
import MobileBottomNav from '@/Components/MobileBottomNav.vue';
import CookieConsent from '@/Components/CookieConsent.vue';
import UserMenu from '@/Components/Cabinet/UserMenu.vue';
import MessagesBell from '@/Components/Cabinet/MessagesBell.vue';

// Каркас личного кабинета: боковое меню на экран высотой, верхняя панель с колокольчиком и меню пользователя.
const page = usePage();

const nav = [
    { label: 'Сводка', route: 'dashboard', active: ['dashboard'], icon: Squares2X2Icon },
    { label: 'Мероприятия', route: 'my-events', active: ['my-events', 'my-events.*'], icon: CalendarDaysIcon },
    { label: 'Библиотека', route: 'documents.index', active: ['documents.*'], icon: BookOpenIcon },
];

const crumbs = {
    dashboard: 'Сводка',
    'my-events': 'Мероприятия',
    'my-events.view': 'Мероприятия',
    'profile.edit': 'Профиль',
    'cabinet.security': 'Вход и безопасность',
    'cabinet.notifications': 'Уведомления',
    'cabinet.payments': 'Платежи',
    certificates: 'Сертификаты',
};

const isStaff = computed(() => (page.props.auth.user?.roles ?? []).some((role) => ['admin', 'editor', 'manager'].includes(role.name)));

const isActive = (item) => item.active.some((name) => route().current(name));
const crumb = computed(() => {
    const name = Object.keys(crumbs).find((key) => route().current(key));
    return name ? crumbs[name] : 'Личный кабинет';
});

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
            <!-- Боковое меню: высотой в экран и остаётся на месте при прокрутке -->
            <aside class="cab-panel sticky top-6 hidden h-[calc(100vh-48px)] max-h-[900px] w-[272px] shrink-0 flex-col overflow-y-auto px-3.5 pb-4 pt-6 lg:flex">
                <Link :href="route('welcome')" class="mb-5 flex px-3.5" aria-label="МедАльянсГрупп Expert — на главную">
                    <MainLogo :width="196" :height="35" />
                </Link>
                <nav aria-label="Личный кабинет" class="flex flex-col gap-1">
                    <Link
                        v-for="item in nav"
                        :key="item.route"
                        :href="route(item.route)"
                        class="cab-focus flex min-h-[46px] items-center gap-3 rounded-full px-4 text-[15px] font-bold transition"
                        :class="isActive(item)
                            ? 'bg-brandblue text-white shadow-[0_10px_22px_-10px_#6186b6bf]'
                            : 'text-gray-900 hover:bg-gray-100 dark:text-white dark:hover:bg-gray-800'"
                        :aria-current="isActive(item) ? 'page' : undefined"
                    >
                        <component :is="item.icon" class="h-5 w-5 shrink-0" aria-hidden="true" />
                        {{ item.label }}
                    </Link>
                    <a
                        v-if="isStaff"
                        :href="route('admin.index')"
                        class="cab-focus mt-2 flex min-h-[46px] items-center gap-3 rounded-full border border-gray-200 px-4 text-[15px] font-bold text-gray-900 hover:border-brandblue hover:text-brandblue-dark dark:border-gray-700 dark:text-white"
                    >
                        <Cog6ToothIcon class="h-5 w-5 shrink-0" aria-hidden="true" />
                        Админка
                    </a>
                </nav>

                <div class="min-h-6 flex-1"></div>

                <a
                    href="https://t.me/beautifulgynecology"
                    target="_blank"
                    rel="noopener"
                    class="cab-focus mx-1 flex flex-col gap-2.5 rounded-[22px] bg-gradient-to-br from-brandblue to-brandblue-dark p-[18px] text-white"
                >
                    <span class="text-[11px] font-extrabold uppercase tracking-[.14em] text-[#e3ebf5]">Сообщество в Telegram</span>
                    <span class="font-display text-xl font-medium leading-6">«Красивая гинекология»</span>
                    <span class="text-[13px] text-[#e3ebf5]">Больше 1 000 врачей в сообществе</span>
                    <span class="inline-flex min-h-10 items-center self-start rounded-full bg-white px-4 text-[13px] font-extrabold text-brandblue-dark">Вступить в канал</span>
                </a>
                <div class="mx-1 mt-3.5 flex flex-col gap-0.5 border-t border-gray-100 px-3.5 pt-3.5 dark:border-gray-800">
                    <span class="text-sm font-bold">Нужна помощь?</span>
                    <a href="tel:+79952220779" class="text-[13px] font-semibold leading-[22px] text-gray-500 hover:text-brandblue-dark dark:text-gray-400">+7 (995) 222-07-79</a>
                    <a href="mailto:info@mag-expert.ru" class="text-[13px] font-semibold leading-[22px] text-gray-500 hover:text-brandblue-dark dark:text-gray-400">info@mag-expert.ru</a>
                </div>
            </aside>

            <main class="flex min-w-0 flex-1 flex-col gap-8">
                <!-- Верхняя панель (десктоп): прилипает к верху экрана, фон-подложка прячет содержимое, уезжающее под неё -->
                <div class="sticky top-0 z-30 -mt-6 hidden bg-cabinet pt-6 lg:block dark:bg-gray-950">
                <div class="cab-panel relative flex flex-wrap items-center gap-2.5 !rounded-full py-2 pl-3 pr-2">
                    <Link :href="route('welcome')" class="cab-btn-ghost !min-h-11 !pl-3 !pr-4">
                        <ArrowLeftIcon class="h-[18px] w-[18px]" aria-hidden="true" />
                        На сайт
                    </Link>
                    <nav aria-label="Вы здесь" class="flex items-center gap-2 pl-2 text-sm font-semibold text-gray-500 dark:text-gray-400">
                        <Link :href="route('dashboard')" class="hover:text-brandblue-dark">Личный кабинет</Link>
                        <span aria-hidden="true">/</span>
                        <span class="font-bold text-gray-900 dark:text-white">{{ crumb }}</span>
                    </nav>
                    <span class="flex-1"></span>
                    <MessagesBell />
                    <UserMenu />
                </div>
                </div>

                <slot />
            </main>
        </div>

        <CookieConsent />
        <MobileBottomNav />
    </div>
</template>
