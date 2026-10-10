<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { CheckCircleIcon, ExclamationCircleIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    social: { type: Array, default: () => [] },
    phoneVerified: { type: Boolean, default: false },
});

const phone = computed(() => usePage().props.auth.user?.phone);
const available = computed(() => props.social.filter((s) => s.available));

const unlink = (item) => {
    if (confirm(`Отвязать ${item.name}? Входить можно будет по email и паролю.`)) {
        router.delete(route('social.unlink', item.key), { preserveScroll: true });
    }
};
</script>

<template>
    <section class="space-y-6">
        <div>
            <h3 class="text-sm font-semibold text-gray-800 dark:text-white">Телефон</h3>
            <div class="mt-2 flex flex-col gap-2 rounded-lg bg-gray-50 p-4 text-sm sm:flex-row sm:items-center sm:justify-between dark:bg-gray-900/40">
                <div class="flex items-center gap-2">
                    <span class="font-medium text-gray-900 dark:text-white">{{ phone || 'не указан' }}</span>
                    <span v-if="phoneVerified" class="inline-flex items-center gap-1 text-green-700 dark:text-green-400"><CheckCircleIcon class="h-4 w-4" />подтверждён</span>
                    <span v-else class="inline-flex items-center gap-1 text-amber-700 dark:text-amber-400"><ExclamationCircleIcon class="h-4 w-4" />не подтверждён</span>
                </div>
                <div v-if="!phoneVerified && available.length" class="flex flex-wrap gap-2">
                    <a v-for="item in available" :key="item.key" :href="route('social.link', item.key)"
                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-medium text-gray-800 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                        Подтвердить через {{ item.name }}
                    </a>
                </div>
            </div>
            <p v-if="!phoneVerified" class="mt-2 text-xs text-gray-500">Номер возьмём из вашего профиля в Яндексе или ВКонтакте: там он уже проверен, код вводить не нужно.</p>
        </div>

        <div>
            <h3 class="text-sm font-semibold text-gray-800 dark:text-white">Вход через соцсети</h3>
            <p class="mt-1 text-xs text-gray-500">Привяжите аккаунт, чтобы входить в один и тот же профиль без пароля.</p>
            <ul class="mt-3 divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-700 dark:border-gray-700">
                <li v-for="item in social" :key="item.key" class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                    <div class="flex items-center gap-3">
                        <span v-if="item.key === 'yandex'" class="grid h-6 w-6 place-items-center rounded-full bg-[#FC3F1D] text-xs font-bold text-white">Я</span>
                        <span v-else class="grid h-6 w-6 place-items-center rounded-md bg-[#0077FF] text-[10px] font-bold text-white">VK</span>
                        <span class="font-medium text-gray-900 dark:text-white">{{ item.name }}</span>
                        <span v-if="item.linked" class="text-green-700 dark:text-green-400">привязан</span>
                    </div>
                    <button v-if="item.linked" type="button" class="text-gray-500 hover:text-red-600" @click="unlink(item)">Отвязать</button>
                    <a v-else-if="item.available" :href="route('social.link', item.key)" class="font-medium text-brandblue hover:underline">Привязать</a>
                    <span v-else class="text-xs text-gray-400">скоро</span>
                </li>
            </ul>
        </div>
    </section>
</template>
