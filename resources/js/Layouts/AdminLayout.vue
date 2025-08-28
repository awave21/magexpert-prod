<template>
    <div
        class="relative isolate flex min-h-svh w-full bg-white max-lg:flex-col lg:bg-zinc-100 dark:bg-zinc-900 dark:lg:bg-zinc-950"
    >
        <!-- Боковая панель (фиксированная на больших экранах) -->
        <div class="fixed inset-y-0 left-0 w-64 max-lg:hidden">
            <nav class="flex h-full min-h-0 flex-col">
                <!-- Заголовок боковой панели -->
                <div
                    class="flex flex-col border-b border-zinc-950/5 p-4 dark:border-white/5 [&>[data-slot=section]+[data-slot=section]]:mt-2.5"
                >
                    <div data-slot="section" class="flex flex-col gap-0.5">
                        <span class="relative">
                            <Link
                                :href="route('welcome')"
                                type="button"
                                class="cursor-default flex w-full items-center gap-3 rounded-lg px-2 py-2.5 text-left text-base/6 font-medium text-zinc-950 sm:py-2 sm:text-sm/5 data-hover:bg-zinc-950/5 dark:text-white"
                            >
                                <span
                                    data-slot="avatar"
                                    class="inline-grid shrink-0 align-middle rounded-lg bg-white p-1"
                                >
                                    <img
                                        class="size-6 object-contain"
                                        src="/storage/admin/cropped-logo-mimi.png"
                                        alt="MAG Expert Logo"
                                    />
                                </span>
                                <span class="truncate">MAG Expert</span>
                            </Link>
                        </span>
                    </div>

                    <!-- Поиск и уведомления (скрыты на мобильных) -->
                    <div class="max-lg:hidden">
                        <div class="mt-1">
                            <button
                                @click="adminStore.toggleNotificationDrawer"
                                class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-sm font-medium text-zinc-950 hover:bg-zinc-200 dark:text-white dark:hover:bg-zinc-800 transition-colors duration-150 cursor-pointer"
                            >
                                <div class="relative">
                                    <InboxIcon class="size-5 fill-zinc-500" />
                                    <span
                                        v-if="
                                            adminStore.unreadNotificationsCount >
                                            0
                                        "
                                        class="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[10px] font-medium text-white"
                                    >
                                        {{
                                            adminStore.unreadNotificationsCount >
                                            9
                                                ? "9+"
                                                : adminStore.unreadNotificationsCount
                                        }}
                                    </span>
                                </div>
                                <span>Уведомления</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Основное меню боковой панели -->
                <div class="flex-1 overflow-y-auto p-4">
                    <div class="space-y-1">
                        <span
                            v-for="item in menuItems"
                            :key="item.routeName"
                            class="relative"
                        >
                            <span
                                v-if="route().current(item.activePattern)"
                                class="absolute inset-y-2 -left-4 w-0.5 rounded-full bg-zinc-950 dark:bg-white"
                            ></span>
                            <Link
                                :href="route(item.routeName)"
                                class="flex items-center gap-3 rounded-lg px-2 py-2 text-sm font-medium"
                                :class="{
                                    'bg-zinc-950/5 text-zinc-950 dark:bg-white/5 dark:text-white':
                                        route().current(item.activePattern),
                                    'text-zinc-950 hover:bg-zinc-200 dark:text-white dark:hover:bg-zinc-800':
                                        !route().current(item.activePattern),
                                }"
                            >
                                <component
                                    :is="item.icon"
                                    class="size-5"
                                    :class="{
                                        'fill-zinc-950 dark:fill-white':
                                            route().current(item.activePattern),
                                        'fill-zinc-500 dark:fill-zinc-400':
                                            !route().current(
                                                item.activePattern
                                            ),
                                    }"
                                />
                                <span>{{ item.name }}</span>
                            </Link>
                        </span>
                    </div>
                </div>

                <!-- Переключатель темы (выравнивание по нижней части, перед блоком пользователя) -->
                <div class="p-2">
                    <div class="space-y-1">
                        <button
                            @click="adminStore.toggleDarkMode"
                            class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-sm font-medium text-zinc-950 cursor-pointer hover:bg-zinc-200 dark:text-white dark:hover:bg-zinc-800 transition-all duration-300 ease-in-out"
                        >
                            <SunIcon
                                v-if="!adminStore.darkMode"
                                class="h-5 w-5 text-amber-500"
                            />
                            <MoonIcon v-else class="h-5 w-5 text-blue-500" />
                            <span>{{
                                adminStore.darkMode
                                    ? "Темная тема"
                                    : "Светлая тема"
                            }}</span>
                        </button>
                    </div>
                </div>

                <!-- Футер боковой панели (скрыт на мобильных) -->
                <div
                    class="border-t border-zinc-950/5 p-4 dark:border-white/5 max-lg:hidden"
                >
                    <Menu as="div" class="relative">
                        <MenuButton
                            class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-sm font-medium text-zinc-950 cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-800 transition-colors duration-150 data-hover:bg-zinc-950/5 dark:text-white"
                        >
                            <span
                                data-slot="avatar"
                                class="size-10 inline-grid shrink-0 align-middle rounded-lg"
                            >
                                <UserAvatar
                                    :name="$page.props.auth.user.full_name"
                                    :src="$page.props.auth.user.avatar"
                                    size="md"
                                    shape="square"
                                />
                            </span>
                            <span class="min-w-0 text-left">
                                <span
                                    class="block truncate text-sm/5 font-medium text-zinc-950 dark:text-white"
                                    >{{ $page.props.auth.user.full_name }}</span
                                >
                                <span
                                    class="block truncate text-xs/5 font-normal text-zinc-500 dark:text-zinc-400"
                                    >{{ $page.props.auth.user.email }}</span
                                >
                            </span>
                            <ChevronDownIcon
                                class="ml-auto size-4 fill-zinc-500"
                            />
                        </MenuButton>
                        <transition
                            enter-active-class="transition ease-out duration-100"
                            enter-from-class="transform opacity-0 scale-95"
                            enter-to-class="transform opacity-100 scale-100"
                            leave-active-class="transition ease-in duration-75"
                            leave-from-class="transform opacity-100 scale-100"
                            leave-to-class="transform opacity-0 scale-95"
                        >
                            <MenuItems
                                class="absolute right-0 bottom-full mb-2 w-56 origin-bottom-right rounded-lg bg-white py-1 shadow-lg ring-1 ring-zinc-950/5 focus:outline-none dark:bg-zinc-800 dark:ring-white/10"
                            >
                                <MenuItem v-slot="{ active }">
                                    <Link
                                        :href="route('profile.edit')"
                                        :class="[
                                            active
                                                ? 'bg-zinc-100 dark:bg-zinc-700'
                                                : '',
                                            'flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 dark:text-zinc-200',
                                        ]"
                                    >
                                        <UserCircleIcon
                                            class="size-5 text-zinc-500"
                                        />
                                        Профиль
                                    </Link>
                                </MenuItem>
                                <MenuItem v-slot="{ active }">
                                    <form
                                        @submit.prevent="logout"
                                        class="w-full"
                                    >
                                        <button
                                            type="submit"
                                            :class="[
                                                active
                                                    ? 'bg-zinc-100 dark:bg-zinc-700'
                                                    : '',
                                                'flex w-full items-center gap-2 px-4 py-2 text-sm text-red-600 dark:text-red-400',
                                            ]"
                                        >
                                            <ArrowLeftOnRectangleIcon
                                                class="size-5"
                                            />
                                            Выйти
                                        </button>
                                    </form>
                                </MenuItem>
                            </MenuItems>
                        </transition>
                    </Menu>
                </div>
            </nav>
        </div>

        <!-- Верхняя навигация (видима на мобильных) -->
        <header
            class="sticky top-0 z-10 flex h-14 items-center gap-4 border-b border-zinc-950/10 bg-white px-4 sm:px-6 lg:hidden dark:border-white/10 dark:bg-zinc-900"
        >
            <button
                type="button"
                class="flex size-8 items-center justify-center"
                @click="adminStore.toggleMobileMenu"
            >
                <Bars3Icon class="size-5 text-zinc-950 dark:text-white" />
            </button>

            <div class="min-w-0 flex-1">
                <nav class="flex flex-1 items-center gap-4 py-2.5">
                    <div aria-hidden="true" class="-ml-4 flex-1"></div>
                    <div class="flex items-center gap-3">
                        <button
                            @click="adminStore.toggleNotificationDrawer"
                            aria-label="Notifications"
                            class="relative flex min-w-0 items-center gap-3 rounded-lg p-2 text-zinc-950 dark:text-white"
                        >
                            <div class="relative">
                                <InboxIcon class="size-5 fill-zinc-500" />
                                <span
                                    v-if="
                                        adminStore.unreadNotificationsCount > 0
                                    "
                                    class="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[10px] font-medium text-white"
                                >
                                    {{
                                        adminStore.unreadNotificationsCount > 9
                                            ? "9+"
                                            : adminStore.unreadNotificationsCount
                                    }}
                                </span>
                            </div>
                        </button>
                        <Menu as="div" class="relative">
                            <MenuButton
                                class="relative flex min-w-0 items-center gap-3 rounded-lg p-2 text-zinc-950 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors duration-150 dark:text-white"
                            >
                                <span
                                    data-slot="avatar"
                                    class="inline-grid shrink-0 align-middle rounded-lg"
                                >
                                    <UserAvatar
                                        :name="$page.props.auth.user.full_name"
                                        :src="$page.props.auth.user.avatar"
                                        size="sm"
                                        shape="square"
                                    />
                                </span>
                            </MenuButton>
                            <transition
                                enter-active-class="transition ease-out duration-100"
                                enter-from-class="transform opacity-0 scale-95"
                                enter-to-class="transform opacity-100 scale-100"
                                leave-active-class="transition ease-in duration-75"
                                leave-from-class="transform opacity-100 scale-100"
                                leave-to-class="transform opacity-0 scale-95"
                            >
                                <MenuItems
                                    class="absolute right-0 top-full mt-2 w-48 origin-top-right rounded-lg bg-white py-1 shadow-lg ring-1 ring-zinc-950/5 focus:outline-none dark:bg-zinc-800 dark:ring-white/10"
                                >
                                    <MenuItem v-slot="{ active }">
                                        <Link
                                            :href="route('profile.edit')"
                                            :class="[
                                                active
                                                    ? 'bg-zinc-100 dark:bg-zinc-700'
                                                    : '',
                                                'flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 dark:text-zinc-200',
                                            ]"
                                        >
                                            <UserCircleIcon
                                                class="size-5 text-zinc-500"
                                            />
                                            Профиль
                                        </Link>
                                    </MenuItem>
                                    <MenuItem v-slot="{ active }">
                                        <form
                                            @submit.prevent="logout"
                                            class="w-full"
                                        >
                                            <button
                                                type="submit"
                                                :class="[
                                                    active
                                                        ? 'bg-zinc-100 dark:bg-zinc-700'
                                                        : '',
                                                    'flex w-full items-center gap-2 px-4 py-2 text-sm text-red-600 dark:text-red-400',
                                                ]"
                                            >
                                                <ArrowLeftOnRectangleIcon
                                                    class="size-5"
                                                />
                                                Выйти
                                            </button>
                                        </form>
                                    </MenuItem>
                                </MenuItems>
                            </transition>
                        </Menu>
                    </div>
                </nav>
            </div>
        </header>

        <!-- Мобильное меню (видимо только когда открыто) -->
        <div
            v-if="adminStore.isMobileMenuOpen"
            class="fixed inset-0 z-20 bg-zinc-950/50 lg:hidden"
            @click="adminStore.closeMobileMenu"
        ></div>
        <div
            v-if="adminStore.isMobileMenuOpen"
            class="fixed inset-y-0 inset-x-0 z-30 bg-white dark:bg-zinc-900 lg:hidden overflow-y-auto"
        >
            <!-- Мобильное меню содержит те же элементы, что и боковая панель -->
            <nav class="flex h-full min-h-0 flex-col">
                <!-- Заголовок и основное меню -->
                <div
                    class="flex flex-col border-b border-zinc-950/5 p-4 dark:border-white/5"
                >
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span
                                data-slot="avatar"
                                class="inline-grid shrink-0 align-middle rounded-lg bg-white p-1"
                            >
                                <img
                                    class="size-6 object-contain"
                                    src="/storage/admin/cropped-logo-mimi.png"
                                    alt="MAG Expert Logo"
                                />
                            </span>
                            <span class="text-xl font-medium dark:text-white"
                                >MAG Expert</span
                            >
                        </div>
                        <button @click="adminStore.closeMobileMenu" class="p-2">
                            <XMarkIcon
                                class="size-6 text-zinc-500 dark:text-zinc-400"
                            />
                        </button>
                    </div>

                    <!-- Поиск и уведомления в мобильном меню -->
                    <div class="mt-4 space-y-1">
                        <button
                            @click="
                                () => {
                                    adminStore.closeMobileMenu();
                                    adminStore.toggleNotificationDrawer();
                                }
                            "
                            class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-sm font-medium text-zinc-950 hover:bg-zinc-100 dark:text-white dark:hover:bg-zinc-800 transition-colors duration-150 cursor-pointer"
                        >
                            <div class="relative">
                                <InboxIcon class="size-5 fill-zinc-500" />
                                <span
                                    v-if="
                                        adminStore.unreadNotificationsCount > 0
                                    "
                                    class="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[10px] font-medium text-white"
                                >
                                    {{
                                        adminStore.unreadNotificationsCount > 9
                                            ? "9+"
                                            : adminStore.unreadNotificationsCount
                                    }}
                                </span>
                            </div>
                            <span>Уведомления</span>
                        </button>
                    </div>
                </div>

                <!-- Основное меню -->
                <div class="flex-1 overflow-y-auto p-2">
                    <div class="space-y-1">
                        <span
                            v-for="item in menuItems"
                            :key="item.routeName"
                            class="relative"
                        >
                            <span
                                v-if="route().current(item.activePattern)"
                                class="absolute inset-y-2 -left-2 w-0.5 rounded-full bg-zinc-950 dark:bg-white"
                            ></span>
                            <Link
                                :href="route(item.routeName)"
                                class="flex items-center gap-3 rounded-lg px-2 py-2 text-sm font-medium"
                                :class="{
                                    'bg-zinc-950/5 text-zinc-950 dark:bg-white/5 dark:text-white':
                                        route().current(item.activePattern),
                                    'text-zinc-950 hover:bg-zinc-200 dark:text-white dark:hover:bg-zinc-800':
                                        !route().current(item.activePattern),
                                }"
                            >
                                <component
                                    :is="item.icon"
                                    class="size-5"
                                    :class="{
                                        'fill-zinc-950 dark:fill-white':
                                            route().current(item.activePattern),
                                        'fill-zinc-500 dark:fill-zinc-400':
                                            !route().current(
                                                item.activePattern
                                            ),
                                    }"
                                />
                                <span>{{ item.name }}</span>
                            </Link>
                        </span>
                    </div>
                </div>

                <!-- Переключатель темы в мобильном меню -->
                <div class="p-2 border-t border-zinc-950/5 dark:border-white/5">
                    <div class="space-y-1">
                        <button
                            @click="adminStore.toggleDarkMode"
                            class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-sm font-medium text-zinc-950 hover:bg-zinc-100 dark:text-white dark:hover:bg-zinc-800 transition-all duration-300 ease-in-out"
                        >
                            <SunIcon
                                v-if="!adminStore.darkMode"
                                class="h-5 w-5 text-amber-500"
                            />
                            <MoonIcon v-else class="h-5 w-5 text-blue-500" />
                            <span>{{
                                adminStore.darkMode
                                    ? "Темная тема"
                                    : "Светлая тема"
                            }}</span>
                        </button>
                    </div>
                </div>

                <!-- Футер мобильного меню -->
                <div class="border-t border-zinc-950/5 p-4 dark:border-white/5">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span
                                data-slot="avatar"
                                class="size-10 inline-grid shrink-0 align-middle rounded-lg"
                            >
                                <UserAvatar
                                    :name="$page.props.auth.user.full_name"
                                    :src="$page.props.auth.user.avatar"
                                    size="md"
                                    shape="square"
                                />
                            </span>
                            <span class="min-w-0 text-left">
                                <span
                                    class="block truncate text-sm/5 font-medium text-zinc-950 dark:text-white"
                                    >{{ $page.props.auth.user.full_name }}</span
                                >
                                <span
                                    class="block truncate text-xs/5 font-normal text-zinc-500 dark:text-zinc-400"
                                    >{{ $page.props.auth.user.email }}</span
                                >
                            </span>
                        </div>
                        <div class="flex space-x-2">
                            <Link
                                :href="route('profile.edit')"
                                class="rounded-lg p-2 text-zinc-600 cursor-pointer hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-800 transition-colors duration-150"
                            >
                                <UserCircleIcon class="size-5" />
                            </Link>
                            <form @submit.prevent="logout">
                                <button
                                    type="submit"
                                    class="rounded-lg p-2 text-red-600 hover:bg-zinc-100 dark:text-red-400 dark:hover:bg-zinc-800 transition-colors duration-150"
                                >
                                    <ArrowLeftOnRectangleIcon class="size-5" />
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </nav>
        </div>

        <!-- Основное содержимое -->
        <main
            class="flex flex-1 flex-col pb-2 lg:min-w-0 lg:pt-2 lg:pr-2 lg:pl-64"
        >
            <div
                class="grow p-6 lg:rounded-lg lg:bg-white lg:p-10 lg:ring-1 lg:shadow-xs lg:ring-zinc-950/5 dark:lg:bg-zinc-900 dark:lg:ring-white/10"
            >
                <div class="mx-auto max-w-6xl">
                    <!-- Слот для заголовка -->
                    <div v-if="$slots.header" class="mb-6">
                        <slot name="header" />
                    </div>

                    <!-- Основной контент -->
                    <slot />
                </div>
            </div>
        </main>

        <!-- Панель уведомлений -->
        <NotificationDrawer
            :is-open="adminStore.isNotificationDrawerOpen"
            @close="adminStore.closeNotificationDrawer"
        />
    </div>
</template>

<script setup>
import { Link, usePage, router } from "@inertiajs/vue3";
import { Menu, MenuButton, MenuItem, MenuItems } from "@headlessui/vue";
import { useAdminStore } from "@/Stores/adminStore";
import { onMounted, onUnmounted } from "vue";
import UserAvatar from "@/Components/UserAvatar.vue";
import NotificationDrawer from "@/Components/Admin/NotificationDrawer.vue";
import {
    HomeIcon,
    UserGroupIcon,
    CpuChipIcon,
    Cog6ToothIcon,
    MagnifyingGlassIcon,
    InboxIcon,
    ChevronDownIcon,
    QuestionMarkCircleIcon,
    UserCircleIcon,
    ArrowLeftOnRectangleIcon,
    Bars3Icon,
    XMarkIcon,
    SunIcon,
    MoonIcon,
    CalendarDaysIcon,
    MicrophoneIcon,
    DocumentTextIcon,
    BuildingOfficeIcon,
    ClipboardDocumentListIcon,
} from "@heroicons/vue/24/solid";

// Используем хранилище админа
const adminStore = useAdminStore();

// Получение данных пользователя из Inertia
const page = usePage();

// Меню навигации
const menuItems = [
    {
        name: "Главная",
        routeName: "admin.index",
        activePattern: "admin.index",
        icon: HomeIcon,
    },
    {
        name: "Пользователи",
        routeName: "admin.users",
        activePattern: "admin.users*",
        icon: UserGroupIcon,
    },
    {
        name: "Спикеры",
        routeName: "admin.speakers",
        activePattern: "admin.speakers*",
        icon: MicrophoneIcon,
    },
    {
        name: "Мероприятия",
        routeName: "admin.events",
        activePattern: "admin.events*",
        icon: CalendarDaysIcon,
    },
    {
        name: "Регистрации",
        routeName: "admin.event-registrations",
        activePattern: "admin.event-registrations*",
        icon: ClipboardDocumentListIcon,
    },
    {
        name: "Библиотека",
        routeName: "admin.medical-library",
        activePattern: "admin.medical-library*",
        icon: DocumentTextIcon,
    },
    {
        name: "Партнеры",
        routeName: "admin.partners",
        activePattern: "admin.partners*",
        icon: BuildingOfficeIcon,
    },
];

// Функция для выхода из системы
const logout = () => {
    router.post(route("logout"));
};

// Инициализация темной темы при монтировании
onMounted(() => {
    adminStore.initDarkMode();

    // Инициализируем подписку на уведомления
    if (window.Echo && page.props.auth.user) {
        window.Echo.private(
            `admin.notifications.${page.props.auth.user.id}`
        ).listen("AdminNotification", (data) => {
            adminStore.addNotification(data.notification);
        });
    }
});

// Отписываемся от канала при размонтировании компонента
onUnmounted(() => {
    if (window.Echo && page.props.auth.user) {
        window.Echo.leave(`admin.notifications.${page.props.auth.user.id}`);
    }
});
</script>
