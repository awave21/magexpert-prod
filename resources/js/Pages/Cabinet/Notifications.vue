<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { BellIcon, EnvelopeIcon, ShieldCheckIcon } from '@heroicons/vue/24/outline';
import ProfileLayout from '@/Layouts/ProfileLayout.vue';

const props = defineProps({
    settings: { type: Object, required: true },
});

const page = usePage();
const email = computed(() => page.props.auth.user.email);

// Переключатель сохраняется сразу — отдельной кнопки «Сохранить» нет
const form = useForm({ ...props.settings });
const toggle = (key) => {
    form[key] = !form[key];
    form.patch(route('cabinet.notifications.update'), {
        preserveScroll: true,
        onError: () => { form[key] = !form[key]; },
    });
};

const rows = computed(() => [
    {
        key: 'newsletter_consent',
        icon: EnvelopeIcon,
        title: 'Письма на почту',
        text: 'Новости и анонсы мероприятий MAG Expert.',
        on: form.newsletter_consent,
        status: form.newsletter_consent ? 'Вы подписаны' : 'Вы отписаны',
    },
    {
        key: 'site_notifications',
        icon: BellIcon,
        title: 'Уведомления на сайте',
        text: 'Пока вы на сайте, новые сообщения от команды MAG Expert появляются в углу экрана. Если выключить, они будут только в колокольчике.',
        on: form.site_notifications,
        status: form.site_notifications ? 'Включены' : 'Выключены',
    },
]);
</script>

<template>
    <Head title="Уведомления" />

    <ProfileLayout>
        <header class="flex flex-col gap-2.5">
            <span class="cab-eyebrow">Личный кабинет</span>
            <h1 class="cab-h1">Уведомления</h1>
        </header>

        <div class="flex max-w-[760px] flex-col gap-5">
            <section v-for="row in rows" :key="row.key" class="cab-panel flex flex-wrap items-center gap-5 p-6 sm:p-7">
                <span class="hidden h-14 w-14 shrink-0 items-center justify-center rounded-full bg-brandblue-soft text-brandblue-dark sm:flex">
                    <component :is="row.icon" class="h-6 w-6" aria-hidden="true" />
                </span>
                <div class="flex min-w-0 flex-[1_1_260px] flex-col gap-1.5">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h2 :id="`n-${row.key}`" class="font-display text-[22px] font-medium leading-7">{{ row.title }}</h2>
                        <span :class="row.on ? 'cab-chip-ok' : 'cab-chip-blue !bg-gray-100 !text-gray-600'">{{ row.status }}</span>
                    </div>
                    <span class="text-sm leading-[21px] text-gray-500 dark:text-gray-400">
                        {{ row.text }}
                        <template v-if="row.key === 'newsletter_consent'"> Приходят на {{ email }} · <Link :href="route('profile.edit')" class="font-bold text-brandblue-dark hover:underline">изменить</Link></template>
                    </span>
                </div>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="row.on ? 'true' : 'false'"
                    :aria-labelledby="`n-${row.key}`"
                    class="cab-focus relative h-8 w-14 shrink-0 rounded-full transition"
                    :class="row.on ? 'bg-brandblue' : 'bg-gray-300 dark:bg-gray-600'"
                    :disabled="form.processing"
                    @click="toggle(row.key)"
                >
                    <span class="absolute top-[3px] h-[26px] w-[26px] rounded-full bg-white shadow transition-all" :class="row.on ? 'left-[27px]' : 'left-[3px]'"></span>
                </button>
            </section>

            <section class="cab-panel flex flex-wrap items-center gap-4 px-6 py-5">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brandblue-soft text-brandblue-dark"><ShieldCheckIcon class="h-5 w-5" aria-hidden="true" /></span>
                <div class="min-w-0 flex-[1_1_260px]">
                    <div class="text-[15px] font-bold">Служебные письма приходят всегда</div>
                    <div class="text-[13px] leading-[19px] text-gray-500 dark:text-gray-400">Подтверждение email, восстановление пароля, регистрация на мероприятие и чеки об оплате.</div>
                </div>
            </section>
        </div>
    </ProfileLayout>
</template>
