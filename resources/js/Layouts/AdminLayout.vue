<template>
    <div
        data-admin
        class="relative isolate flex min-h-svh w-full bg-white max-lg:flex-col lg:bg-zinc-100 dark:bg-zinc-900 dark:lg:bg-zinc-950"
    >
        <!-- Боковая панель (фиксированная на больших экранах), сворачивается до иконок -->
        <aside
            class="fixed inset-y-0 left-0 z-20 transition-[width] duration-200 max-lg:hidden"
            :class="adminStore.isSidebarCollapsed ? 'w-[72px]' : 'w-64'"
        >
            <nav class="flex h-full min-h-0 flex-col" aria-label="Главное меню">
                <div
                    class="flex items-center p-4"
                    :class="adminStore.isSidebarCollapsed ? 'justify-center' : ''"
                >
                    <Link
                        :href="route('welcome')"
                        class="flex min-w-0 items-center gap-3 rounded-lg text-sm font-semibold text-zinc-950 dark:text-white"
                        title="Открыть сайт"
                    >
                        <img
                            v-if="adminStore.isSidebarCollapsed"
                            class="size-10 shrink-0 rounded-lg bg-white object-contain"
                            src="/images/admin/magexpert-icon.svg"
                            alt="MAG Expert"
                        />
                        <template v-else>
                            <span class="inline-grid shrink-0 rounded-lg bg-white p-1">
                                <img
                                    class="size-6 object-contain"
                                    src="/images/admin/magexpert-icon.svg"
                                    alt="MAG Expert"
                                />
                            </span>
                            <span class="truncate">MAG Expert</span>
                        </template>
                    </Link>
                </div>

                <div class="px-3">
                    <button
                        type="button"
                        class="flex w-full cursor-pointer items-center gap-3 rounded-lg px-2 py-2 text-sm text-zinc-500 transition-colors hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-800"
                        :class="adminStore.isSidebarCollapsed ? 'justify-center' : ''"
                        :title="adminStore.isSidebarCollapsed ? 'Быстрый поиск (Ctrl+K)' : undefined"
                        aria-label="Быстрый поиск"
                        @click="adminStore.openCommandPalette"
                    >
                        <MagnifyingGlassIcon class="size-5 shrink-0 fill-zinc-500 dark:fill-zinc-400" />
                        <template v-if="!adminStore.isSidebarCollapsed">
                            <span class="flex-1 text-left">Быстрый поиск</span>
                            <kbd class="rounded border border-zinc-300 bg-white px-1.5 py-0.5 text-[11px] text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900">⌘K</kbd>
                        </template>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-3 py-2">
                    <template v-for="group in menuGroups" :key="group.title || 'root'">
                        <p
                            v-if="group.title && !adminStore.isSidebarCollapsed"
                            class="px-2 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wide text-zinc-400"
                        >
                            {{ group.title }}
                        </p>
                        <div
                            v-else-if="group.title"
                            class="my-3 border-t border-zinc-950/10 dark:border-white/10"
                            aria-hidden="true"
                        ></div>
                        <ul class="space-y-0.5">
                            <li v-for="item in group.items" :key="item.routeName">
                                <Link
                                    :href="route(item.routeName)"
                                    class="flex items-center gap-3 rounded-lg px-2 py-2 text-sm font-medium transition-colors"
                                    :class="[
                                        adminStore.isSidebarCollapsed ? 'justify-center' : '',
                                        isActive(item)
                                            ? 'bg-zinc-950/5 text-zinc-950 dark:bg-white/10 dark:text-white'
                                            : 'text-zinc-700 hover:bg-zinc-200 dark:text-zinc-200 dark:hover:bg-zinc-800',
                                    ]"
                                    :title="adminStore.isSidebarCollapsed ? item.name : undefined"
                                    :aria-current="isActive(item) ? 'page' : undefined"
                                >
                                    <component
                                        :is="item.icon"
                                        class="size-5 shrink-0"
                                        :class="isActive(item) ? 'fill-zinc-950 dark:fill-white' : 'fill-zinc-500 dark:fill-zinc-400'"
                                    />
                                    <span v-if="!adminStore.isSidebarCollapsed" class="truncate">{{ item.name }}</span>
                                    <span v-else class="sr-only">{{ item.name }}</span>
                                </Link>
                            </li>
                        </ul>
                    </template>
                </div>

                <div class="border-t border-zinc-950/5 p-3 dark:border-white/5">
                    <button
                        type="button"
                        class="flex w-full cursor-pointer items-center gap-3 rounded-lg px-2 py-2 text-sm font-medium text-zinc-500 transition-colors hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-800"
                        :class="adminStore.isSidebarCollapsed ? 'justify-center' : ''"
                        :title="adminStore.isSidebarCollapsed ? 'Развернуть меню' : undefined"
                        :aria-label="adminStore.isSidebarCollapsed ? 'Развернуть меню' : 'Свернуть меню'"
                        :aria-expanded="!adminStore.isSidebarCollapsed"
                        @click="adminStore.toggleSidebar"
                    >
                        <ChevronDoubleRightIcon v-if="adminStore.isSidebarCollapsed" class="size-5 shrink-0" />
                        <template v-else>
                            <ChevronDoubleLeftIcon class="size-5 shrink-0" />
                            <span>Свернуть</span>
                        </template>
                    </button>
                </div>
            </nav>
        </aside>

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
                                    src="/images/admin/magexpert-icon.svg"
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
                        <template v-for="(item, index) in menuItems" :key="item.routeName">
                        <p
                            v-if="item.group && item.group !== menuItems[index - 1]?.group"
                            class="px-2 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wide text-zinc-400"
                        >
                            {{ item.group }}
                        </p>
                        <span class="relative block">
                            <span
                                v-if="isActive(item)"
                                class="absolute inset-y-2 -left-2 w-0.5 rounded-full bg-zinc-950 dark:bg-white"
                            ></span>
                            <Link
                                :href="route(item.routeName)"
                                class="flex items-center gap-3 rounded-lg px-2 py-2 text-sm font-medium"
                                :class="{
                                    'bg-zinc-950/5 text-zinc-950 dark:bg-white/5 dark:text-white':
                                        isActive(item),
                                    'text-zinc-950 hover:bg-zinc-200 dark:text-white dark:hover:bg-zinc-800':
                                        !isActive(item),
                                }"
                            >
                                <component
                                    :is="item.icon"
                                    class="size-5"
                                    :class="{
                                        'fill-zinc-950 dark:fill-white':
                                            isActive(item),
                                        'fill-zinc-500 dark:fill-zinc-400':
                                            !isActive(item),
                                    }"
                                />
                                <span>{{ item.name }}</span>
                            </Link>
                        </span>
                        </template>
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
            class="flex flex-1 flex-col pb-2 transition-[padding] duration-200 lg:min-w-0 lg:pr-2"
            :class="adminStore.isSidebarCollapsed ? 'lg:pl-[72px]' : 'lg:pl-64'"
        >
            <!-- Верхняя панель: где я, создать, уведомления, аккаунт -->
            <header class="hidden h-14 shrink-0 items-center gap-3 px-4 lg:flex">
                <nav aria-label="Где вы находитесь" class="min-w-0 truncate text-sm text-zinc-500 dark:text-zinc-400">
                    <template v-if="currentItem">
                        <span v-if="currentItem.group">{{ currentItem.group }}<span class="mx-1.5 text-zinc-300 dark:text-zinc-600">/</span></span>
                        <span class="font-medium text-zinc-950 dark:text-white">{{ currentItem.name }}</span>
                    </template>
                </nav>

                <div class="ml-auto flex items-center gap-2">
                    <Menu v-if="createActions.length" as="div" class="relative">
                        <MenuButton
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 focus-visible:ring-offset-2 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100"
                        >
                            <PlusIcon class="size-4" />
                            Создать
                            <ChevronDownIcon class="size-4 opacity-70" />
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
                                class="absolute right-0 z-30 mt-2 w-56 origin-top-right rounded-lg bg-white py-1 shadow-lg ring-1 ring-zinc-950/5 focus:outline-none dark:bg-zinc-800 dark:ring-white/10"
                            >
                                <MenuItem v-for="action in createActions" :key="action.routeName" v-slot="{ active }">
                                    <Link
                                        :href="route(action.routeName)"
                                        :class="[
                                            active ? 'bg-zinc-100 dark:bg-zinc-700' : '',
                                            'flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 dark:text-zinc-200',
                                        ]"
                                    >
                                        <component :is="action.icon" class="size-5 text-zinc-500" />
                                        {{ action.name }}
                                    </Link>
                                </MenuItem>
                            </MenuItems>
                        </transition>
                    </Menu>

                    <button
                        type="button"
                        class="relative flex size-9 cursor-pointer items-center justify-center rounded-lg text-zinc-600 transition-colors hover:bg-zinc-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        aria-label="Уведомления"
                        @click="adminStore.toggleNotificationDrawer"
                    >
                        <InboxIcon class="size-5" />
                        <span
                            v-if="adminStore.unreadNotificationsCount > 0"
                            class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-medium text-white"
                        >
                            {{ adminStore.unreadNotificationsCount > 9 ? "9+" : adminStore.unreadNotificationsCount }}
                        </span>
                    </button>

                    <Menu as="div" class="relative">
                        <MenuButton
                            class="flex cursor-pointer items-center rounded-lg p-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900"
                            aria-label="Меню аккаунта"
                        >
                            <UserAvatar
                                :name="$page.props.auth.user.full_name"
                                :src="$page.props.auth.user.avatar"
                                size="sm"
                                shape="square"
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
                                class="absolute right-0 z-30 mt-2 w-64 origin-top-right rounded-lg bg-white shadow-lg ring-1 ring-zinc-950/5 focus:outline-none dark:bg-zinc-800 dark:ring-white/10"
                            >
                                <div class="border-b border-zinc-100 px-4 py-3 dark:border-zinc-700">
                                    <p class="truncate text-sm font-medium text-zinc-950 dark:text-white">{{ $page.props.auth.user.full_name }}</p>
                                    <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $page.props.auth.user.email }}</p>
                                </div>
                                <div class="py-1">
                                    <MenuItem v-slot="{ active }">
                                        <Link
                                            :href="route('profile.edit')"
                                            :class="[active ? 'bg-zinc-100 dark:bg-zinc-700' : '', 'flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 dark:text-zinc-200']"
                                        >
                                            <UserCircleIcon class="size-5 text-zinc-500" />
                                            Профиль
                                        </Link>
                                    </MenuItem>
                                    <MenuItem v-slot="{ active }">
                                        <button
                                            type="button"
                                            :class="[active ? 'bg-zinc-100 dark:bg-zinc-700' : '', 'flex w-full cursor-pointer items-center gap-2 px-4 py-2 text-sm text-zinc-700 dark:text-zinc-200']"
                                            @click="adminStore.toggleDarkMode"
                                        >
                                            <SunIcon v-if="!adminStore.darkMode" class="size-5 text-amber-500" />
                                            <MoonIcon v-else class="size-5 text-blue-500" />
                                            {{ adminStore.darkMode ? "Тёмная тема" : "Светлая тема" }}
                                        </button>
                                    </MenuItem>
                                </div>
                                <div class="border-t border-zinc-100 py-1 dark:border-zinc-700">
                                    <MenuItem v-slot="{ active }">
                                        <button
                                            type="button"
                                            :class="[active ? 'bg-zinc-100 dark:bg-zinc-700' : '', 'flex w-full cursor-pointer items-center gap-2 px-4 py-2 text-sm text-red-600 dark:text-red-400']"
                                            @click="logout"
                                        >
                                            <ArrowLeftOnRectangleIcon class="size-5" />
                                            Выйти
                                        </button>
                                    </MenuItem>
                                </div>
                            </MenuItems>
                        </transition>
                    </Menu>
                </div>
            </header>

            <div
                class="grow p-6 lg:rounded-lg lg:bg-white lg:p-6 lg:ring-1 lg:shadow-xs lg:ring-zinc-950/5 dark:lg:bg-zinc-900 dark:lg:ring-white/10"
            >
                <div class="w-full">
                    <!-- Слот для заголовка -->
                    <div v-if="$slots.header" class="mb-6">
                        <slot name="header" />
                    </div>

                    <!-- Основной контент -->
                    <slot />
                </div>
            </div>
        </main>

        <!-- Быстрый поиск ⌘K -->
        <CommandPalette :pages="menuItems" :actions="createActions" />

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
import { computed, onMounted, onUnmounted, watch } from "vue";
import { useToast } from "vue-toastification";
import UserAvatar from "@/Components/UserAvatar.vue";
import NotificationDrawer from "@/Components/Admin/NotificationDrawer.vue";
import CommandPalette from "@/Components/Admin/CommandPalette.vue";
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
    BellAlertIcon,
    SparklesIcon,
    PlusIcon,
    ChevronDoubleLeftIcon,
    ChevronDoubleRightIcon,
} from "@heroicons/vue/24/solid";

// Используем хранилище админа
const adminStore = useAdminStore();

// Получение данных пользователя из Inertia
const page = usePage();

// Сообщения в кабинет могут отправлять только админы и менеджеры
const canSendMessages = (page.props.auth.user?.roles ?? []).some((role) => ['admin', 'manager'].includes(role.slug ?? role.name));

// Сообщения в кабинет и ИИ-помощник доступны только админам и менеджерам
const canManageUsers = (page.props.auth.user?.roles ?? []).some((role) => ['admin', 'manager'].includes(role.slug ?? role.name));

// Меню навигации: group задаёт заголовок в сайдбаре и хлебные крошки
const menuItems = [
    {
        name: "Главная",
        routeName: "admin.index",
        activePattern: "admin.index",
        icon: HomeIcon,
        group: "",
    },
    {
        name: "Мероприятия",
        routeName: "admin.events",
        activePattern: ["admin.events*", "admin.categories*"],
        icon: CalendarDaysIcon,
        group: "Контент",
        keywords: "категории вебинар курс",
    },
    {
        name: "Спикеры",
        routeName: "admin.speakers",
        activePattern: "admin.speakers*",
        icon: MicrophoneIcon,
        group: "Контент",
    },
    {
        name: "Библиотека",
        routeName: "admin.medical-library",
        activePattern: "admin.medical-library*",
        icon: DocumentTextIcon,
        group: "Контент",
        keywords: "материалы документы",
    },
    {
        name: "Партнеры",
        routeName: "admin.partners",
        activePattern: "admin.partners*",
        icon: BuildingOfficeIcon,
        group: "Контент",
        keywords: "выставка",
    },
    {
        name: "Регистрации",
        routeName: "admin.event-registrations",
        activePattern: "admin.event-registrations*",
        icon: ClipboardDocumentListIcon,
        group: "Люди",
        keywords: "доступы участники",
    },
    {
        name: "Пользователи",
        routeName: "admin.users",
        activePattern: "admin.users*",
        icon: UserGroupIcon,
        group: "Люди",
        keywords: "роли",
    },
    ...(canSendMessages
        ? [{
            name: "Сообщения",
            routeName: "admin.messages",
            activePattern: "admin.messages*",
            icon: BellAlertIcon,
            group: "Люди",
            keywords: "рассылка кабинет",
        }, {
            name: "ИИ-помощник",
            routeName: "admin.assistant",
            activePattern: "admin.assistant*",
            icon: SparklesIcon,
            group: "Инструменты",
            keywords: "вопросы",
        }]
        : []),
];

// Что можно создать из верхней панели и из ⌘K
const createActions = [
    { name: "Новое мероприятие", routeName: "admin.events.create", icon: CalendarDaysIcon, keywords: "создать добавить" },
    { name: "Новый спикер", routeName: "admin.speakers.create", icon: MicrophoneIcon, keywords: "создать добавить" },
    { name: "Новый материал", routeName: "admin.medical-library.create", icon: DocumentTextIcon, keywords: "создать добавить библиотека" },
    { name: "Новый партнёр", routeName: "admin.partners.create", icon: BuildingOfficeIcon, keywords: "создать добавить" },
    ...(canManageUsers
        ? [{ name: "Новый пользователь", routeName: "admin.users.create", icon: UserGroupIcon, keywords: "создать добавить" }]
        : []),
];

// Пункты по группам в порядке следования меню
const menuGroups = computed(() => {
    const groups = [];
    menuItems.forEach((item) => {
        let group = groups.find((entry) => entry.title === item.group);
        if (!group) {
            group = { title: item.group, items: [] };
            groups.push(group);
        }
        group.items.push(item);
    });
    return groups;
});

// Сообщения после сохранения/удаления показываем в одном месте для всех страниц админки
const toast = useToast();
watch(
    () => page.props.flash,
    (flash) => {
        const success = flash?.success || flash?.message;
        if (success) {
            toast.success(success);
        }
        if (flash?.error) {
            toast.error(flash.error);
        }
    },
    { immediate: true }
);

// Пункт меню подсвечен, если открыт любой из его разделов
const isActive = (item) => [].concat(item.activePattern).some((pattern) => route().current(pattern));
const currentItem = computed(() => menuItems.find((item) => isActive(item)) ?? null);

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
