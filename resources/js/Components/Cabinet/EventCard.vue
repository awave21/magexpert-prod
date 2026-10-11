<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { LockClosedIcon, PlayIcon } from '@heroicons/vue/24/solid';

// Карточка мероприятия в кабинете. Закрыто только платное мероприятие, которое ещё не оплачено.
const props = defineProps({
    event: { type: Object, required: true },
});

const locked = computed(() => props.event.access === 'pending');
const href = computed(() => (locked.value ? route('events.show', props.event.slug) : route('my-events.view', props.event.slug)));

const dateLabel = computed(() => {
    if (props.event.is_live) {
        return 'Сейчас в эфире';
    }
    if (props.event.is_on_demand) {
        return 'В любое время';
    }
    if (!props.event.start_date) {
        return 'Дата уточняется';
    }
    const date = new Date(`${props.event.start_date}T00:00:00`).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
    return props.event.is_past ? (props.event.has_recording ? `Запись · ${date}` : `Прошло · ${date}`) : date;
});

const meta = computed(() => [dateLabel.value, props.event.format].filter(Boolean).join(' · '));

const badge = computed(() => ({
    paid: { text: 'Оплачено', cls: 'cab-chip-ok' },
    free: { text: 'Бесплатно', cls: 'cab-chip-blue' },
    request: { text: 'По запросу', cls: 'cab-chip-blue' },
    pending: { text: props.event.price ? `Ожидает оплаты · ${props.event.price}` : 'Ожидает оплаты', cls: 'cab-chip-warn' },
}[props.event.access]));
</script>

<template>
    <Link :href="href" class="cab-focus group flex flex-col gap-3 rounded-[22px] text-gray-900 no-underline dark:text-white">
        <div class="cab-thumb aspect-[16/10]">
            <img v-if="event.image" :src="event.image" alt="" class="absolute inset-0 h-full w-full object-cover transition duration-700 group-hover:scale-105" />
            <span class="absolute inset-0 bg-gradient-to-b from-transparent from-50% to-black/45"></span>
            <span v-if="event.type" class="absolute left-3 top-3 rounded-full bg-white/95 px-3 py-1.5 text-xs font-extrabold text-brandblue-dark">
                <span v-if="event.is_live" class="cab-live mr-1.5 !h-2 !w-2 align-middle"></span>{{ event.type }}
            </span>
            <span class="absolute bottom-3.5 left-3.5 text-[12.5px] font-bold text-white">{{ meta }}</span>
            <span v-if="!locked" class="absolute bottom-3 right-3 flex h-11 w-11 items-center justify-center rounded-full bg-white/90 text-brandblue transition group-hover:scale-110">
                <PlayIcon class="ml-0.5 h-4 w-4" aria-hidden="true" />
            </span>
            <span v-else class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-gray-900/65 px-4 text-center text-[13px] font-bold text-white">
                <LockClosedIcon class="h-6 w-6" aria-hidden="true" />
                Доступ после оплаты
            </span>
        </div>
        <span class="line-clamp-2 min-h-[44px] text-[17px] font-bold leading-[22px] group-hover:text-brandblue-dark">{{ event.title }}</span>
        <span :class="badge.cls" class="self-start">{{ badge.text }}</span>
    </Link>
</template>
