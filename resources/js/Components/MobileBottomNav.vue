<template>
    <nav
        class="fixed bottom-0 left-0 w-full bg-white border-t border-gray-200 md:hidden z-50"
    >
        <div class="grid grid-cols-5 h-16">
            <!-- Библиотека -->
            <Link
                :href="route('documents.index')"
                class="flex flex-col items-center justify-center text-xs font-medium transition-colors"
                :class="
                    route().current('documents.*')
                        ? 'text-blue-600'
                        : 'text-gray-600 hover:text-blue-600'
                "
            >
                <BookOpenIcon class="w-6 h-6 mb-1" />
                Библиотека
            </Link>
            <!-- Главная -->
            <Link
                :href="route('welcome')"
                class="flex flex-col items-center justify-center text-xs font-medium transition-colors"
                :class="
                    route().current('welcome')
                        ? 'text-blue-600'
                        : 'text-gray-600 hover:text-blue-600'
                "
            >
                <HomeIcon class="w-6 h-6 mb-1" />
                Главная
            </Link>

            <!-- Мероприятия -->
            <Link
                :href="route('events.index')"
                class="flex flex-col items-center justify-center text-xs font-medium transition-colors"
                :class="
                    route().current('events.*')
                        ? 'text-blue-600'
                        : 'text-gray-600 hover:text-blue-600'
                "
            >
                <CalendarIcon class="w-6 h-6 mb-1" />
                Мероприятия
            </Link>

            <!-- Личный кабинет -->
            <Link
                :href="
                    $page.props.auth.user ? route('dashboard') : route('login')
                "
                class="flex flex-col items-center justify-center text-xs font-medium transition-colors"
                :class="
                    $page.props.auth.user &&
                    (route().current('dashboard') ||
                        route().current('profile.*') ||
                        route().current('my-events') ||
                        route().current('certificates'))
                        ? 'text-blue-600'
                        : 'text-gray-600 hover:text-blue-600'
                "
            >
                <UserIcon class="w-6 h-6 mb-1" />
                Кабинет
            </Link>

            <!-- Контакты -->
            <Link
                :href="route('contacts.index')"
                class="flex flex-col items-center justify-center text-xs font-medium transition-colors"
                :class="
                    route().current('contacts.*')
                        ? 'text-blue-600'
                        : 'text-gray-600 hover:text-blue-600'
                "
            >
                <PhoneIcon class="w-6 h-6 mb-1" />
                Контакты
            </Link>
        </div>
    </nav>
</template>

<script setup>
import { Link } from "@inertiajs/vue3";
import {
    HomeIcon,
    CalendarIcon,
    BookOpenIcon,
    UserIcon,
    PhoneIcon,
} from "@heroicons/vue/24/outline";
</script>

<style scoped>
/* Дополнительные стили, если нужно */
</style>
