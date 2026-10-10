<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import {
    ArrowRightOnRectangleIcon,
    BellIcon,
    ChevronDownIcon,
    Cog6ToothIcon,
    CreditCardIcon,
    ShieldCheckIcon,
    UserIcon,
} from '@heroicons/vue/24/outline';
import CabinetAvatar from '@/Components/Cabinet/CabinetAvatar.vue';

const props = defineProps({
    compact: { type: Boolean, default: false },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
// Сотрудникам — быстрый переход в админку
const isStaff = computed(() => (user.value.roles ?? []).some((role) => ['admin', 'editor', 'manager'].includes(role.name)));
const needsAttention = computed(() => !user.value.email_verified || !user.value.phone_verified);

const open = ref(false);
const root = ref(null);
const close = (event) => {
    if (open.value && root.value && !root.value.contains(event.target)) {
        open.value = false;
    }
};
const onKey = (event) => {
    if (event.key === 'Escape') {
        open.value = false;
    }
};
onMounted(() => {
    document.addEventListener('click', close);
    document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', close);
    document.removeEventListener('keydown', onKey);
});

const items = [
    { label: 'Профиль', route: 'profile.edit', icon: UserIcon, attention: true },
    { label: 'Вход и безопасность', route: 'cabinet.security', icon: ShieldCheckIcon, attention: true },
    { label: 'Уведомления', route: 'cabinet.notifications', icon: BellIcon },
    { label: 'Платежи', route: 'cabinet.payments', icon: CreditCardIcon },
];

// Выход с повтором при устаревшем CSRF-токене (419): так было и в старом кабинете
const loggingOut = ref(false);
const logout = async () => {
    if (loggingOut.value) {
        return;
    }
    loggingOut.value = true;
    const send = () => axios.post(route('logout'), {}, {
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '' },
    });
    try {
        await send();
    } catch (error) {
        if (error?.response?.status === 419) {
            const { data } = await axios.get('/csrf-token');
            document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', data?.csrf_token || '');
            await send().catch(() => {});
        }
    } finally {
        router.visit(route('login'));
    }
};
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="cab-focus flex min-h-[52px] items-center gap-3 rounded-full p-1 pr-2 text-left transition hover:bg-gray-100 dark:hover:bg-gray-800 sm:pr-4"
            :aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="menu"
            :aria-label="compact ? `Меню аккаунта: ${user.full_name}` : undefined"
            @click="open = !open"
        >
            <CabinetAvatar :user="user" :size="44" />
            <span v-if="!compact" class="hidden min-w-0 flex-col sm:flex">
                <span class="truncate text-sm font-bold text-gray-900 dark:text-white">{{ user.full_name }}</span>
                <span class="truncate text-xs text-gray-500 dark:text-gray-400">{{ user.email }}</span>
            </span>
            <ChevronDownIcon v-if="!compact" class="hidden h-4 w-4 text-gray-500 sm:block" aria-hidden="true" />
        </button>

        <transition
            enter-active-class="transition duration-100 ease-out"
            enter-from-class="opacity-0 -translate-y-1"
            leave-active-class="transition duration-75 ease-in"
            leave-to-class="opacity-0 -translate-y-1"
        >
            <div
                v-if="open"
                role="menu"
                class="absolute right-0 top-[calc(100%+12px)] z-50 w-[min(300px,calc(100vw-24px))] rounded-[20px] border border-gray-200 bg-white p-2 shadow-xl dark:border-gray-700 dark:bg-gray-800"
            >
                <div class="mb-1 border-b border-gray-100 px-3 pb-3 pt-2 dark:border-gray-700">
                    <div class="text-sm font-bold text-gray-900 dark:text-white">{{ user.full_name }}</div>
                    <div class="truncate text-[13px] text-gray-500 dark:text-gray-400">{{ user.email }}</div>
                </div>
                <a
                    v-if="isStaff"
                    role="menuitem"
                    :href="route('admin.index')"
                    class="cab-focus mb-1 flex min-h-[44px] items-center gap-3 rounded-xl bg-gray-100 px-3 text-sm font-bold text-gray-900 hover:bg-gray-200 dark:bg-gray-700 dark:text-white"
                >
                    <Cog6ToothIcon class="h-[18px] w-[18px]" aria-hidden="true" />
                    Админка
                </a>
                <Link
                    v-for="item in items"
                    :key="item.route"
                    role="menuitem"
                    :href="route(item.route)"
                    class="cab-focus flex min-h-[44px] items-center gap-3 rounded-xl px-3 text-sm font-semibold text-gray-900 hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700"
                    @click="open = false"
                >
                    <component :is="item.icon" class="h-[18px] w-[18px] text-gray-500" aria-hidden="true" />
                    {{ item.label }}
                    <span v-if="item.attention && needsAttention" class="ml-auto h-2 w-2 rounded-full bg-brandcoral" title="Нужно подтвердить данные"></span>
                </Link>
                <div class="mx-1 my-1.5 h-px bg-gray-100 dark:bg-gray-700"></div>
                <button
                    type="button"
                    role="menuitem"
                    class="cab-focus flex min-h-[44px] w-full items-center gap-3 rounded-xl px-3 text-sm font-semibold text-[#b4442c] hover:bg-gray-100 disabled:opacity-60 dark:hover:bg-gray-700"
                    :disabled="loggingOut"
                    @click="logout"
                >
                    <ArrowRightOnRectangleIcon class="h-[18px] w-[18px]" aria-hidden="true" />
                    {{ loggingOut ? 'Выходим…' : 'Выйти' }}
                </button>
            </div>
        </transition>
    </div>
</template>
