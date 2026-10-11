<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline';

// Компактный календарь ваших мероприятий: точка под числом — в этот день есть мероприятие.
const props = defineProps({
    events: { type: Array, default: () => [] },
    today: { type: String, required: true },
});

const pad = (n) => String(n).padStart(2, '0');
const [ty, tm] = props.today.split('-').map(Number);
const year = ref(ty);
const month = ref(tm);
const selected = ref(props.today);

const monthTitle = computed(() => {
    const label = new Date(year.value, month.value - 1, 1).toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }).replace(' г.', '');
    return label.charAt(0).toUpperCase() + label.slice(1);
});

const byDate = computed(() => props.events.reduce((acc, event) => {
    (acc[event.date] ||= []).push(event);
    return acc;
}, {}));

const days = computed(() => {
    const first = new Date(year.value, month.value - 1, 1);
    const offset = (first.getDay() + 6) % 7;
    const count = new Date(year.value, month.value, 0).getDate();
    const cells = Array.from({ length: offset }, (_, i) => ({ key: `e${i}`, empty: true }));
    for (let d = 1; d <= count; d++) {
        const date = `${year.value}-${pad(month.value)}-${pad(d)}`;
        const list = byDate.value[date] || [];
        cells.push({ key: date, date, day: d, list, live: list.some((e) => e.is_live), past: date < props.today });
    }
    return cells;
});

const shift = (step) => {
    const d = new Date(year.value, month.value - 1 + step, 1);
    year.value = d.getFullYear();
    month.value = d.getMonth() + 1;
};

const selectedEvents = computed(() => byDate.value[selected.value] || []);
const selectedTitle = computed(() => {
    const label = new Date(`${selected.value}T00:00:00`).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
    return selected.value === props.today ? `Сегодня, ${label}` : label;
});
const weekdays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
</script>

<template>
    <section class="cab-panel flex flex-col gap-2.5 p-[18px] pb-3" aria-label="Календарь мероприятий">
        <div class="flex items-center gap-2">
            <h2 class="flex-1 font-display text-xl font-medium">{{ monthTitle }}</h2>
            <button type="button" class="cab-focus flex h-11 w-11 items-center justify-center rounded-full border border-gray-200 bg-white hover:border-brandblue dark:border-gray-700 dark:bg-gray-900" aria-label="Предыдущий месяц" @click="shift(-1)">
                <ChevronLeftIcon class="h-4 w-4" aria-hidden="true" />
            </button>
            <button type="button" class="cab-focus flex h-11 w-11 items-center justify-center rounded-full border border-gray-200 bg-white hover:border-brandblue dark:border-gray-700 dark:bg-gray-900" aria-label="Следующий месяц" @click="shift(1)">
                <ChevronRightIcon class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>
        <div class="grid grid-cols-7 gap-0.5 text-center">
            <span v-for="w in weekdays" :key="w" class="pb-1.5 text-[11px] font-semibold text-gray-500">{{ w }}</span>
            <template v-for="cell in days" :key="cell.key">
                <span v-if="cell.empty" class="h-11"></span>
                <button
                    v-else
                    type="button"
                    class="cab-focus flex h-11 flex-col items-center justify-center gap-[3px] rounded-xl border text-[13px] leading-none"
                    :class="[
                        cell.date === selected ? 'bg-brandblue-soft font-extrabold' : 'font-medium hover:bg-gray-100 dark:hover:bg-gray-800',
                        cell.date === today ? 'border-brandblue font-extrabold' : 'border-transparent',
                        cell.past && cell.date !== selected ? 'text-gray-400' : '',
                    ]"
                    :aria-label="`${cell.day} число${cell.list.length ? ', есть мероприятие' : ''}`"
                    :aria-pressed="cell.date === selected ? 'true' : 'false'"
                    @click="selected = cell.date"
                >
                    <span>{{ cell.day }}</span>
                    <span class="h-1 w-1 rounded-full" :class="cell.list.length ? (cell.live ? 'bg-brandcoral' : 'bg-brandblue') : 'bg-transparent'"></span>
                </button>
            </template>
        </div>
        <div class="border-t border-gray-100 pt-1 dark:border-gray-800">
            <span class="block pb-0.5 pt-2 text-xs font-semibold text-gray-500">{{ selectedTitle }}</span>
            <Link
                v-for="event in selectedEvents"
                :key="event.slug"
                :href="route('my-events.view', event.slug)"
                class="flex gap-2.5 py-2 text-gray-900 hover:text-brandblue-dark dark:text-white"
            >
                <span class="w-[3px] shrink-0 rounded" :class="event.is_live ? 'bg-brandcoral' : 'bg-brandblue'"></span>
                <span class="flex min-w-0 flex-col">
                    <span class="text-[13px] font-bold leading-[18px]">{{ event.title }}</span>
                    <span class="text-[13px] text-gray-500">{{ [event.is_live ? 'Сейчас в эфире' : event.time, event.format].filter(Boolean).join(' · ') }}</span>
                </span>
            </Link>
            <span v-if="!selectedEvents.length" class="block py-1.5 text-sm text-gray-500">В этот день мероприятий нет</span>
        </div>
    </section>
</template>
