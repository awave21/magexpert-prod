<script setup>
// Вход через Яндекс ID и VK ID. Обычные ссылки, а не Link: браузер уходит на сайт провайдера.
// Провайдер без ключей в .env не показывается.
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const providers = computed(() => usePage().props.socialProviders ?? []);

defineProps({
    title: { type: String, default: 'или войдите через' },
});
</script>

<template>
    <div v-if="providers.length">
        <div class="my-6 flex items-center gap-3 text-sm text-gray-500 dark:text-gray-400">
            <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></span>
            {{ title }}
            <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></span>
        </div>
        <div class="grid grid-cols-1 gap-3" :class="{ 'sm:grid-cols-2': providers.length > 1 }">
            <a
                v-if="providers.includes('yandex')"
                :href="route('social.redirect', 'yandex')"
                class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-900 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brandblue/30 dark:border-gray-600 dark:bg-gray-800 dark:text-white dark:hover:bg-gray-700"
            >
                <span class="grid h-5 w-5 place-items-center rounded-full bg-[#FC3F1D] text-xs font-bold text-white" aria-hidden="true">Я</span>
                Яндекс ID
            </a>
            <a
                v-if="providers.includes('vkid')"
                :href="route('social.redirect', 'vkid')"
                class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-900 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brandblue/30 dark:border-gray-600 dark:bg-gray-800 dark:text-white dark:hover:bg-gray-700"
            >
                <span class="grid h-5 w-5 place-items-center rounded-md bg-[#0077FF] text-[10px] font-bold text-white" aria-hidden="true">VK</span>
                ВКонтакте
            </a>
        </div>
    </div>
</template>
