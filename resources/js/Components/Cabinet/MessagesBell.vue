<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { BellIcon, XMarkIcon } from '@heroicons/vue/24/outline';

// Сообщения команды MAG Expert. Их отправляют вручную из админки, сайт сам ничего не создаёт.
const page = usePage();
const user = computed(() => page.props.auth.user);

const open = ref(false);
const loading = ref(false);
const items = ref([]);
const unread = ref(user.value?.unread_messages ?? 0);
const root = ref(null);
const popup = ref(false);

const load = async () => {
    loading.value = true;
    try {
        const { data } = await axios.get(route('cabinet.messages'));
        items.value = data.data;
        unread.value = data.unread;
    } finally {
        loading.value = false;
    }
};

const toggle = async () => {
    open.value = !open.value;
    popup.value = false;
    if (open.value) {
        await load();
    }
};

const readAll = async () => {
    await axios.post(route('cabinet.messages.read-all'));
    items.value = items.value.map((item) => ({ ...item, read: true }));
    unread.value = 0;
};

const openItem = async (item) => {
    if (!item.read) {
        await axios.post(route('cabinet.messages.read', item.id));
        item.read = true;
        unread.value = Math.max(0, unread.value - 1);
    }
    if (item.url) {
        open.value = false;
        router.visit(item.url);
    }
};

const formatDate = (value) => new Date(value).toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });

const close = (event) => {
    if (open.value && root.value && !root.value.contains(event.target)) {
        open.value = false;
    }
};

onMounted(() => {
    document.addEventListener('click', close);
    // Всплывающее напоминание о непрочитанных — один раз за визит и только если пользователь не выключил его
    try {
        if (user.value?.site_notifications && unread.value > 0 && !sessionStorage.getItem('cabinet-messages-popup')) {
            popup.value = true;
            sessionStorage.setItem('cabinet-messages-popup', '1');
        }
    } catch (e) {
        // sessionStorage может быть недоступен — тогда просто не показываем подсказку
    }
});
onBeforeUnmount(() => document.removeEventListener('click', close));

const unreadLabel = computed(() => (unread.value > 9 ? '9+' : String(unread.value)));
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="cab-focus relative flex h-11 w-11 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-900 transition hover:border-brandblue hover:text-brandblue-dark dark:border-gray-700 dark:bg-gray-900 dark:text-white"
            :aria-label="unread ? `Сообщения: ${unread} новых` : 'Сообщения'"
            :aria-expanded="open ? 'true' : 'false'"
            @click="toggle"
        >
            <BellIcon class="h-5 w-5" aria-hidden="true" />
            <span
                v-if="unread"
                class="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full border-2 border-white bg-brandcoral px-1 text-[11px] font-extrabold text-white"
            >{{ unreadLabel }}</span>
        </button>

        <div
            v-if="popup && !open"
            role="status"
            class="absolute right-0 top-[calc(100%+12px)] z-50 flex w-[min(320px,calc(100vw-24px))] items-start gap-3 rounded-[20px] border border-gray-200 bg-white p-4 shadow-xl dark:border-gray-700 dark:bg-gray-800"
        >
            <span class="cab-live mt-1.5"></span>
            <button type="button" class="flex-1 text-left" @click="toggle">
                <span class="block text-sm font-bold text-gray-900 dark:text-white">Новые сообщения от MAG Expert</span>
                <span class="block text-[13px] text-gray-500 dark:text-gray-400">Непрочитанных: {{ unread }}. Нажмите, чтобы открыть.</span>
            </button>
            <button type="button" class="cab-focus -m-1 flex h-8 w-8 items-center justify-center rounded-full text-gray-400 hover:text-gray-700" aria-label="Скрыть" @click.stop="popup = false">
                <XMarkIcon class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>

        <div
            v-if="open"
            class="absolute right-0 top-[calc(100%+12px)] z-50 w-[min(380px,calc(100vw-24px))] rounded-[24px] border border-gray-200 bg-white px-5 pb-3 pt-2 shadow-xl dark:border-gray-700 dark:bg-gray-800"
        >
            <div class="flex items-center justify-between py-3">
                <span class="font-display text-xl font-medium text-gray-900 dark:text-white">Сообщения от MAG Expert</span>
                <button v-if="unread" type="button" class="cab-focus rounded text-[13px] font-bold text-brandblue-dark hover:underline" @click="readAll">Прочитать все</button>
            </div>
            <div v-if="loading && !items.length" class="py-6 text-center text-sm text-gray-500">Загружаем…</div>
            <div v-else-if="!items.length" class="py-6 text-center text-sm text-gray-500">Сообщений пока нет</div>
            <ul v-else class="max-h-[60vh] overflow-y-auto">
                <li v-for="item in items" :key="item.id" class="border-t border-gray-100 dark:border-gray-700">
                    <button type="button" class="flex w-full gap-3 py-3 text-left" @click="openItem(item)">
                        <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="item.read ? 'bg-gray-200 dark:bg-gray-600' : 'bg-brandblue'"></span>
                        <span class="min-w-0">
                            <span class="block text-sm font-bold" :class="item.read ? 'text-gray-500 dark:text-gray-400' : 'text-gray-900 dark:text-white'">{{ item.title }}</span>
                            <span class="block text-[13px] text-gray-500 dark:text-gray-400">{{ item.message }}</span>
                            <span class="block text-xs text-gray-400">{{ formatDate(item.created_at) }}</span>
                        </span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
