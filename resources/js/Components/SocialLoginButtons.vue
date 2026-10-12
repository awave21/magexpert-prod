<script setup>
// Вход через Яндекс ID и VK ID (через него же — Одноклассники и Mail.ru). Обычные ссылки, а не Link: браузер уходит на сайт провайдера.
// Провайдер без ключей в .env не показывается.
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const providers = computed(() => usePage().props.socialProviders ?? []);
const count = computed(() => (providers.value.includes('yandex') ? 1 : 0) + (providers.value.includes('vkid') ? 3 : 0));

const button =
    'inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-900 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brandblue/30 dark:border-gray-600 dark:bg-gray-800 dark:text-white dark:hover:bg-gray-700';

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
        <div class="grid grid-cols-1 gap-3" :class="{ 'sm:grid-cols-2': count > 1 }">
            <a v-if="providers.includes('yandex')" :href="route('social.redirect', 'yandex')" :class="button">
                <span class="grid h-5 w-5 place-items-center rounded-full bg-[#FC3F1D] text-xs font-bold text-white" aria-hidden="true">Я</span>
                Яндекс ID
            </a>
            <template v-if="providers.includes('vkid')">
                <a :href="route('social.redirect', { provider: 'vkid', via: 'vkid' })" :class="button">
                    <span class="grid h-5 w-5 place-items-center rounded-md bg-[#0077FF] text-[10px] font-bold text-white" aria-hidden="true">VK</span>
                    ВКонтакте
                </a>
                <a :href="route('social.redirect', { provider: 'vkid', via: 'ok_ru' })" :class="button">
                    <span class="grid h-5 w-5 place-items-center rounded-md bg-[#EE8208] text-[10px] font-bold text-white" aria-hidden="true">ОК</span>
                    Одноклассники
                </a>
                <a :href="route('social.redirect', { provider: 'vkid', via: 'mail_ru' })" :class="button">
                    <span class="grid h-5 w-5 place-items-center rounded-md bg-[#005FF9] text-xs font-bold text-white" aria-hidden="true">@</span>
                    Mail.ru
                </a>
            </template>
        </div>
    </div>
</template>
