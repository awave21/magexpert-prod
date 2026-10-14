<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { PlayIcon } from '@heroicons/vue/24/solid';
import ProfileLayout from '@/Layouts/ProfileLayout.vue';
import EventCard from '@/Components/Cabinet/EventCard.vue';

const props = defineProps({
    events: { type: Array, default: () => [] },
});

const live = computed(() => props.events.find((event) => event.is_live));

// Всё сразу, без деления на «Доступно» и «Предстоящие»: идущий эфир, затем ближайшие, затем прошедшие (от новых к старым).
// Когда было или будет — видно по дате на карточке.
const startsAt = (event) => `${event.start_date ?? '9999-12-31'} ${event.start_time ?? ''}`;
const ordered = computed(() => [
    ...props.events.filter((event) => event.is_live),
    ...props.events.filter((event) => !event.is_past && !event.is_live).sort((a, b) => startsAt(a).localeCompare(startsAt(b))),
    ...props.events.filter((event) => event.is_past && !event.is_live).sort((a, b) => startsAt(b).localeCompare(startsAt(a))),
]);

const chips = [
    { key: 'all', label: 'Все' },
    { key: 'paid', label: 'Оплаченные' },
    { key: 'free', label: 'Бесплатные' },
];
const chip = ref('all');

// типы мероприятий (вебинары, мастер-классы, конференции…): фильтр показываем, только если у пользователя их больше одного
const PLURAL = { 'Вебинар': 'Вебинары', 'Конференция': 'Конференции', 'Курс': 'Курсы', 'Мастер-класс': 'Мастер-классы', 'Семинар': 'Семинары', 'Мероприятие': 'Другие' };
const types = computed(() => {
    const counts = new Map();
    props.events.forEach((event) => event.type && counts.set(event.type, (counts.get(event.type) ?? 0) + 1));
    return [...counts.entries()].sort((a, b) => b[1] - a[1]).map(([type, count]) => ({ key: type, label: PLURAL[type] ?? type, count }));
});
const type = ref('all');

const shown = computed(() => ordered.value.filter((event) => {
    if (type.value !== 'all' && event.type !== type.value) return false;
    if (chip.value === 'paid') return event.access === 'paid' || event.access === 'pending';
    if (chip.value === 'free') return event.access === 'free' || event.access === 'request';
    return true;
}));
</script>

<template>
    <Head title="Мои доступы" />

    <ProfileLayout>
        <header class="flex flex-col gap-2.5">
            <span class="cab-eyebrow">Личный кабинет</span>
            <h1 class="cab-h1">Мои доступы</h1>
            <p class="max-w-[640px] text-base text-gray-500 dark:text-gray-400">Всё, к чему у вас есть доступ: купленные и бесплатные мероприятия, трансляции и записи.</p>
        </header>

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

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div v-if="types.length > 1" role="group" aria-label="Тип мероприятия" class="flex flex-wrap gap-2">
                <button
                    v-for="t in [{ key: 'all', label: 'Все типы', count: events.length }, ...types]"
                    :key="t.key"
                    type="button"
                    :aria-pressed="type === t.key ? 'true' : 'false'"
                    class="cab-focus min-h-10 rounded-full border px-4 text-sm font-bold transition"
                    :class="type === t.key ? 'border-brandblue-dark bg-brandblue-soft text-brandblue-dark' : 'border-gray-200 bg-white text-gray-900 hover:border-brandblue dark:border-gray-700 dark:bg-gray-900 dark:text-white'"
                    @click="type = t.key"
                >{{ t.label }}<span class="ml-1.5 opacity-60">{{ t.count }}</span></button>
            </div>
            <div role="group" aria-label="Доступ" class="flex flex-wrap gap-2">
                <button
                    v-for="c in chips"
                    :key="c.key"
                    type="button"
                    :aria-pressed="chip === c.key ? 'true' : 'false'"
                    class="cab-focus min-h-10 rounded-full border px-4 text-sm font-bold transition"
                    :class="chip === c.key ? 'border-brandblue-dark bg-brandblue-soft text-brandblue-dark' : 'border-gray-200 bg-white text-gray-900 hover:border-brandblue dark:border-gray-700 dark:bg-gray-900 dark:text-white'"
                    @click="chip = c.key"
                >{{ c.label }}</button>
            </div>
        </div>

        <div v-if="shown.length" class="grid grid-cols-[repeat(auto-fill,minmax(260px,1fr))] gap-x-5 gap-y-6">
            <EventCard v-for="event in shown" :key="event.id" :event="event" />
        </div>
        <div v-else class="cab-panel flex flex-col items-center gap-3 p-9 text-center">
            <span class="font-display text-[22px] font-medium">Здесь пока ничего нет</span>
            <span class="text-sm text-gray-500">Выберите мероприятие в каталоге — после регистрации или оплаты оно появится здесь.</span>
            <Link :href="route('events.index', { filter: 'all' })" class="cab-btn">Открыть каталог</Link>
        </div>
    </ProfileLayout>
</template>
