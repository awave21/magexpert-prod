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

const groups = computed(() => ({
    upcoming: props.events
        .filter((event) => !event.is_past)
        .sort((a, b) => `${a.start_date ?? '9999'} ${a.start_time ?? ''}`.localeCompare(`${b.start_date ?? '9999'} ${b.start_time ?? ''}`)),
    records: props.events.filter((event) => event.is_past && event.has_recording).sort((a, b) => (b.start_date ?? '').localeCompare(a.start_date ?? '')),
    archive: props.events.filter((event) => event.is_past && !event.has_recording).sort((a, b) => (b.start_date ?? '').localeCompare(a.start_date ?? '')),
}));

const tabs = [
    { key: 'upcoming', label: 'Предстоящие' },
    { key: 'records', label: 'Записи' },
    { key: 'archive', label: 'Архив' },
];
const tab = ref(groups.value.upcoming.length || !groups.value.records.length ? 'upcoming' : 'records');

const chips = [
    { key: 'all', label: 'Все' },
    { key: 'paid', label: 'Оплаченные' },
    { key: 'free', label: 'Бесплатные' },
];
const chip = ref('all');

const shown = computed(() => groups.value[tab.value].filter((event) => {
    if (chip.value === 'paid') return event.access === 'paid' || event.access === 'pending';
    if (chip.value === 'free') return event.access === 'free' || event.access === 'request';
    return true;
}));
</script>

<template>
    <Head title="Мои мероприятия" />

    <ProfileLayout>
        <header class="flex flex-col gap-2.5">
            <span class="cab-eyebrow">Личный кабинет</span>
            <h1 class="cab-h1">Мероприятия</h1>
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
            <div role="tablist" aria-label="Период" class="inline-flex max-w-full gap-1 overflow-x-auto rounded-full border border-gray-200 bg-white p-1.5 dark:border-gray-700 dark:bg-gray-900">
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    role="tab"
                    :aria-selected="tab === t.key ? 'true' : 'false'"
                    class="cab-focus min-h-11 shrink-0 rounded-full px-5 text-[15px] font-bold transition"
                    :class="tab === t.key ? 'bg-brandblue text-white' : 'text-gray-900 hover:bg-gray-100 dark:text-white dark:hover:bg-gray-800'"
                    @click="tab = t.key"
                >
                    {{ t.label }}<span class="ml-1.5 opacity-70">{{ groups[t.key].length }}</span>
                </button>
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
            <span class="text-sm text-gray-500">Выберите мероприятие в каталоге — после записи или оплаты оно появится здесь.</span>
            <Link :href="route('events.index')" class="cab-btn">Открыть каталог</Link>
        </div>
    </ProfileLayout>
</template>
