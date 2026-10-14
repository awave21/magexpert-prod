<script setup>
import MainLogo from '@/Components/main-logo.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { CheckIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';

// Итог перехода по ссылке подтверждения email: открывается без входа на сайт.
defineProps({
    confirmed: { type: Boolean, required: true },
});

const page = usePage();
</script>

<template>
    <Head :title="confirmed ? 'Почта подтверждена' : 'Ссылка не подошла'" />

    <div class="flex min-h-screen flex-col items-center justify-center gap-8 bg-cabinet px-4 py-10 font-sans dark:bg-gray-950">
        <Link :href="route('welcome')" aria-label="МедАльянсГрупп Expert — на главную">
            <MainLogo :width="196" :height="35" />
        </Link>

        <main class="cab-panel flex w-full max-w-md flex-col items-center gap-4 p-8 text-center">
            <span
                class="flex h-14 w-14 items-center justify-center rounded-full"
                :class="confirmed ? 'bg-brandblue-soft text-brandblue-dark' : 'bg-brandcoral-soft text-[#b4361c]'"
            >
                <CheckIcon v-if="confirmed" class="h-7 w-7" aria-hidden="true" />
                <ExclamationTriangleIcon v-else class="h-7 w-7" aria-hidden="true" />
            </span>

            <h1 class="text-xl font-bold text-gray-900 dark:text-white">
                {{ confirmed ? 'Почта подтверждена' : 'Ссылка устарела или неверна' }}
            </h1>

            <p class="text-sm leading-6 text-gray-600 dark:text-gray-400">
                <template v-if="confirmed">Спасибо! Адрес подтверждён, эту страницу можно закрыть.</template>
                <template v-else>Отправьте новую ссылку из личного кабинета: на главной кабинета, в блоке «Завершите настройку».</template>
            </p>

            <Link
                :href="page.props.auth?.user ? route('dashboard') : (confirmed ? route('welcome') : route('login'))"
                class="cab-btn mt-2"
            >
                {{ page.props.auth?.user ? 'В личный кабинет' : (confirmed ? 'На сайт' : 'Войти') }}
            </Link>
        </main>
    </div>
</template>
