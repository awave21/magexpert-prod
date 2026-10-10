<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { DevicePhoneMobileIcon, EnvelopeIcon, MagnifyingGlassIcon, UserIcon } from '@heroicons/vue/24/outline';
import { PlayIcon } from '@heroicons/vue/24/solid';
import ProfileLayout from '@/Layouts/ProfileLayout.vue';
import EventCard from '@/Components/Cabinet/EventCard.vue';
import MiniCalendar from '@/Components/Cabinet/MiniCalendar.vue';

const props = defineProps({
    live: { type: Object, default: null },
    upcoming: { type: Array, default: () => [] },
    records: { type: Array, default: () => [] },
    calendar: { type: Array, default: () => [] },
    today: { type: String, required: true },
    library: { type: Object, default: () => ({ total: 0, latest: [] }) },
    setup: { type: Object, required: true },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
// подтверждать телефон предлагаем, только когда Яндекс действительно отдаёт номер
const phoneCheck = computed(() => (page.props.phoneProviders ?? []).includes('yandex'));
// итог последней попытки подтверждения (приходит один раз после возврата с Яндекса)
const phoneResult = computed(() => page.props.flash?.phone_result ?? null);

const greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 6) return 'Доброй ночи';
    if (hour < 12) return 'Доброе утро';
    if (hour < 18) return 'Добрый день';
    return 'Добрый вечер';
});

// Настройка аккаунта: показываем только то, что осталось сделать
const emailSent = ref(false);
const sending = ref(false);
const resend = () => {
    sending.value = true;
    router.post(route('email.confirm.resend'), {}, {
        preserveScroll: true,
        onSuccess: () => { emailSent.value = true; },
        onFinish: () => { sending.value = false; },
    });
};
const totalSteps = computed(() => (phoneCheck.value ? 4 : 3));
const doneCount = computed(() => 1 + Number(props.setup.email_verified) + Number(phoneCheck.value && props.setup.phone_verified) + Number(props.setup.profile_filled));
const setupDone = computed(() => doneCount.value === totalSteps.value);

const tab = ref(props.upcoming.length || !props.records.length ? 'upcoming' : 'records');
const tabs = [
    { key: 'upcoming', label: 'Предстоящие' },
    { key: 'records', label: 'Записи' },
];
const shown = computed(() => (tab.value === 'upcoming' ? props.upcoming : props.records));

const libQuery = ref('');
const searchLibrary = () => {
    router.get(route('documents.index'), libQuery.value ? { search: libQuery.value } : {});
};
const year = (date) => (date ? String(date).slice(0, 4) : '');
</script>

<template>
    <Head title="Личный кабинет" />

    <ProfileLayout>
        <div class="flex flex-wrap items-start gap-7">
            <div class="flex min-w-0 flex-[999_1_560px] flex-col gap-10">
                <!-- Приветствие -->
                <section class="flex flex-wrap items-center gap-6">
                    <div class="hidden h-[116px] w-24 shrink-0 items-end sm:flex justify-center overflow-hidden rounded-[999px_999px_18px_18px] bg-gradient-to-br from-[#f3f7fb] via-[#d5e1ef] to-[#9fb7d6] pb-[18px] font-display text-[34px] font-medium text-brandblue-dark">
                        <img v-if="user.avatar" :src="user.avatar" alt="" class="h-full w-full object-cover" />
                        <template v-else>{{ (user.first_name?.[0] ?? '') + (user.last_name?.[0] ?? '') }}</template>
                    </div>
                    <div class="flex min-w-0 flex-[1_1_320px] flex-col gap-3.5">
                        <span class="cab-eyebrow">Личный кабинет</span>
                        <h1 class="cab-h1">{{ greeting }}, {{ user.first_name }}</h1>
                        <div v-if="user.specialization || user.city" class="flex flex-wrap gap-2">
                            <span v-if="user.specialization" class="rounded-full bg-brandcoral-soft px-2.5 py-1 text-xs font-extrabold text-[#b4442c]">{{ user.specialization }}</span>
                            <span v-if="user.city" class="rounded-full bg-brandcoral-soft px-2.5 py-1 text-xs font-extrabold text-[#b4442c]">{{ user.city }}</span>
                        </div>
                    </div>
                </section>

                <!-- Сейчас в эфире -->
                <Link v-if="live" :href="route('my-events.view', live.slug)" class="cab-panel group flex flex-wrap items-center gap-6 p-3.5 pr-7 text-gray-900 dark:text-white">
                    <div class="cab-thumb h-[136px] w-full shrink-0 sm:w-60">
                        <img v-if="live.image" :src="live.image" alt="" class="absolute inset-0 h-full w-full object-cover" />
                        <span class="absolute bottom-3 right-3 flex h-11 w-11 items-center justify-center rounded-full bg-white/90 text-brandblue"><PlayIcon class="ml-0.5 h-4 w-4" aria-hidden="true" /></span>
                    </div>
                    <div class="flex min-w-0 flex-[1_1_280px] flex-col gap-1.5">
                        <span class="flex items-center gap-2.5 text-xs font-extrabold uppercase tracking-[.14em] text-brandblue-dark"><span class="cab-live"></span>Сейчас в эфире</span>
                        <span class="text-xl font-bold leading-[26px] group-hover:text-brandblue-dark">{{ live.title }}</span>
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ [live.type, live.format].filter(Boolean).join(' · ') }}</span>
                    </div>
                    <span class="cab-btn cab-btn--coral">Смотреть трансляцию</span>
                </Link>

                <!-- Завершите настройку -->
                <section v-if="!setupDone" aria-labelledby="setup-title" class="flex flex-col gap-6">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div class="flex flex-col gap-3.5">
                            <span class="cab-eyebrow">Профиль</span>
                            <h2 id="setup-title" class="font-display text-[30px] font-medium leading-9">Завершите настройку — {{ doneCount }} из {{ totalSteps }}</h2>
                        </div>
                        <p class="max-w-[420px] text-[15px] leading-[23px] text-gray-500 dark:text-gray-400">Подтвердите email и телефон — так вы не потеряете доступ к аккаунту.</p>
                    </div>
                    <div class="grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] gap-4">
                        <div v-if="!setup.email_verified" class="cab-panel flex flex-wrap items-center gap-3.5 !rounded-3xl p-4">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brandblue-soft text-brandblue-dark"><EnvelopeIcon class="h-6 w-6" aria-hidden="true" /></span>
                            <span class="flex min-w-0 flex-[1_1_160px] flex-col">
                                <span class="font-bold">Подтвердите email</span>
                                <span class="text-[13px] text-gray-500">{{ emailSent ? 'Новая ссылка отправлена, проверьте «Спам»' : 'Ссылка в письме «Добро пожаловать»' }}</span>
                            </span>
                            <span v-if="emailSent" class="cab-chip-ok">Отправлено</span>
                            <button v-else type="button" class="cab-btn !min-h-10 !px-4 !text-[13px]" :disabled="sending" @click="resend">{{ sending ? 'Отправляем…' : 'Отправить ещё раз' }}</button>
                        </div>
                        <div v-if="phoneCheck && !setup.phone_verified" class="cab-panel flex flex-wrap items-center gap-3.5 !rounded-3xl p-4">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brandblue-soft text-brandblue-dark"><DevicePhoneMobileIcon class="h-6 w-6" aria-hidden="true" /></span>
                            <span class="flex min-w-0 flex-[1_1_160px] flex-col">
                                <span class="font-bold">Подтвердите телефон</span>
                                <span v-if="phoneResult && !phoneResult.ok" class="text-[13px] font-semibold text-[#b4361c]" role="alert">{{ phoneResult.text }}</span>
                                <span v-else class="text-[13px] text-gray-500">Номером из Яндекс ID, без СМС. Откроется Яндекс и сразу вернёт сюда</span>
                            </span>
                            <a :href="route('social.link', 'yandex')" class="cab-btn !min-h-10 !px-4 !text-[13px]">Подтвердить</a>
                        </div>
                        <div v-if="!setup.profile_filled" class="cab-panel flex flex-wrap items-center gap-3.5 !rounded-3xl p-4">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brandblue-soft text-brandblue-dark"><UserIcon class="h-6 w-6" aria-hidden="true" /></span>
                            <span class="flex min-w-0 flex-[1_1_160px] flex-col">
                                <span class="font-bold">Укажите специализацию и город</span>
                                <span class="text-[13px] text-gray-500">По ним подбираем мероприятия</span>
                            </span>
                            <Link :href="route('profile.edit')" class="cab-btn !min-h-10 !px-4 !text-[13px]">Заполнить</Link>
                        </div>
                    </div>
                </section>

                <!-- Мероприятия -->
                <section aria-labelledby="events-title" class="flex flex-col gap-6">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div class="flex flex-col gap-3.5">
                            <span class="cab-eyebrow">Мероприятия</span>
                            <h2 id="events-title" class="font-display text-[30px] font-medium leading-9">Ваши мероприятия</h2>
                        </div>
                        <div role="tablist" aria-label="Мероприятия" class="inline-flex gap-1 rounded-full border border-gray-200 bg-white p-1.5 dark:border-gray-700 dark:bg-gray-900">
                            <button
                                v-for="t in tabs"
                                :key="t.key"
                                type="button"
                                role="tab"
                                :aria-selected="tab === t.key ? 'true' : 'false'"
                                class="cab-focus min-h-11 rounded-full px-5 text-[15px] font-bold transition"
                                :class="tab === t.key ? 'bg-brandblue text-white' : 'text-gray-900 hover:bg-gray-100 dark:text-white dark:hover:bg-gray-800'"
                                @click="tab = t.key"
                            >{{ t.label }}</button>
                        </div>
                    </div>
                    <div v-if="shown.length" class="grid grid-cols-[repeat(auto-fill,minmax(240px,1fr))] gap-x-5 gap-y-6">
                        <EventCard v-for="event in shown" :key="event.id" :event="event" />
                    </div>
                    <div v-else class="cab-panel flex flex-col items-center gap-3 p-8 text-center">
                        <span class="font-display text-[22px] font-medium">{{ tab === 'upcoming' ? 'Предстоящих мероприятий нет' : 'Записей пока нет' }}</span>
                        <span class="text-sm text-gray-500">Выберите мероприятие в каталоге — после записи оно появится здесь.</span>
                        <Link :href="route('events.index')" class="cab-btn">Открыть каталог</Link>
                    </div>
                    <Link :href="route('my-events')" class="self-start text-[15px] font-bold text-brandblue-dark hover:underline">Все мои мероприятия →</Link>
                </section>

                <!-- Библиотека -->
                <section aria-labelledby="lib-title" class="cab-panel flex flex-col gap-5 !rounded-[32px] bg-gradient-to-br from-white to-[#f3f4f6] p-6 sm:p-9 dark:from-gray-800 dark:to-gray-900">
                    <div class="flex flex-col gap-2.5">
                        <span class="cab-eyebrow">Библиотека</span>
                        <h2 id="lib-title" class="font-display text-[26px] font-medium leading-8">Клинические руководства и исследования</h2>
                    </div>
                    <form class="flex h-[60px] items-center gap-3 rounded-full border border-gray-200 bg-white pl-5 pr-2 dark:border-gray-700 dark:bg-gray-900" role="search" @submit.prevent="searchLibrary">
                        <MagnifyingGlassIcon class="h-[18px] w-[18px] shrink-0 text-gray-500" aria-hidden="true" />
                        <label for="libq" class="sr-only">Поиск по библиотеке</label>
                        <input id="libq" v-model="libQuery" type="search" placeholder="рак шейки матки" class="min-w-0 flex-1 border-0 bg-transparent p-0 text-base font-semibold focus:ring-0" />
                        <button type="submit" class="cab-btn !min-h-11">Найти</button>
                    </form>
                    <div v-if="library.latest.length">
                        <Link
                            v-for="doc in library.latest"
                            :key="doc.id"
                            :href="route('documents.show', doc.id)"
                            class="flex h-[50px] items-center gap-3.5 rounded-xl border-b border-gray-100 px-3 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800"
                        >
                            <span class="rounded-lg bg-brandblue-soft px-2 py-1 text-[11px] font-extrabold text-brandblue-dark">PDF</span>
                            <span class="min-w-0 flex-1 truncate text-[15px] font-bold">{{ doc.title }}</span>
                            <span class="shrink-0 text-[13px] text-gray-500">{{ [year(doc.publication_date), doc.language?.toUpperCase()].filter(Boolean).join(' · ') }}</span>
                        </Link>
                    </div>
                    <Link :href="route('documents.index')" class="self-start text-[15px] font-bold text-brandblue-dark hover:underline">
                        Открыть библиотеку{{ library.total ? ` · ${library.total}` : '' }} →
                    </Link>
                </section>
            </div>

            <!-- Правая колонка -->
            <aside class="flex min-w-0 flex-[1_1_330px] flex-col gap-5" aria-label="Календарь">
                <MiniCalendar :events="calendar" :today="today" />
            </aside>
        </div>
    </ProfileLayout>
</template>
