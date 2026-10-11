<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileLayout from '@/Layouts/ProfileLayout.vue';
import KinescopePlayer from '@/Components/Events/KinescopePlayer.vue';
import {
    ArrowLeftIcon,
    CalendarDaysIcon,
    ClockIcon,
    DocumentTextIcon,
    MapPinIcon,
    PlayIcon,
    VideoCameraIcon,
} from '@heroicons/vue/24/outline';
import DOMPurify from 'dompurify';

const props = defineProps({
    event: {
        type: Object,
        required: true
    },
    user: {
        type: Object,
        default: null
    },
    progress: {
        type: Object,
        default: () => ({ last: null, items: {} })
    },
    progressUrl: {
        type: String,
        default: null
    }
});

// Вспомогательная функция для парсинга дат (может быть ISO формат или обычная строка)
const parseEventDateTime = (date, time) => {
    if (!date || !time) return null;
    
    if (date.includes('T')) {
        // ISO формат - берем только дату и добавляем время
        const dateOnly = date.split('T')[0];
        return new Date(`${dateOnly} ${time}`);
    } else {
        return new Date(`${date} ${time}`);
    }
};

// Проверяем, идет ли мероприятие live с учетом флага is_live
const isLive = computed(() => {
    // Если флаг is_live установлен явно в false - не live
    if (props.event.is_live === false) {
        return false;
    }
    
    // Если нет времени начала/окончания, используем только флаг
    if (!props.event.start_date || !props.event.start_time) {
        return props.event.is_live === true;
    }
    
    const now = new Date();
    const startDateTime = parseEventDateTime(props.event.start_date, props.event.start_time);
    
    if (!startDateTime) {
        return props.event.is_live === true;
    }
    
    let endDateTime;
    if (props.event.end_date && props.event.end_time) {
        endDateTime = parseEventDateTime(props.event.end_date, props.event.end_time);
    } else {
        // Если нет времени окончания, считаем что мероприятие идет 3 часа
        endDateTime = new Date(startDateTime.getTime() + 3 * 60 * 60 * 1000);
    }
    
    // Добавляем 15 минут буферного времени после окончания для чата
    const endDateTimeWithBuffer = new Date(endDateTime.getTime() + 15 * 60 * 1000);
    
    const isInTimeFrame = now >= startDateTime && now <= endDateTimeWithBuffer;
    
    // Если флаг is_live установлен в true, проверяем временные рамки
    if (props.event.is_live === true) {
        return isInTimeFrame;
    }
    
    // Если флаг is_live не установлен (null), определяем по времени события
    return isInTimeFrame;
});

// Получаем URL для встраивания Кинескопа
const embedUrl = computed(() => {
    if (!props.event.kinescope_id && !props.event.kinescope_playlist_id) {
        return null;
    }
    
    if (props.event.kinescope_type === 'playlist' && props.event.kinescope_playlist_id) {
        return `https://kinescope.io/embed/pl/${props.event.kinescope_playlist_id}`;
    }
    
    if (props.event.kinescope_type === 'video' && props.event.kinescope_id) {
        return `https://kinescope.io/embed/${props.event.kinescope_id}`;
    }
    
    return null;
});

// Проверяем, показывать ли чат (только во время проведения мероприятия, без буферного времени)
const shouldShowChat = computed(() => {
    // Если флаг is_live установлен явно в false - не показываем чат
    if (props.event.is_live === false) {
        return false;
    }
    
    // Если нет времени начала/окончания, используем только флаг
    if (!props.event.start_date || !props.event.start_time) {
        return props.event.is_live === true;
    }
    
    const now = new Date();
    const startDateTime = parseEventDateTime(props.event.start_date, props.event.start_time);
    
    if (!startDateTime) {
        return props.event.is_live === true;
    }
    
    let endDateTime;
    if (props.event.end_date && props.event.end_time) {
        endDateTime = parseEventDateTime(props.event.end_date, props.event.end_time);
    } else {
        // Если нет времени окончания, считаем что мероприятие идет 3 часа
        endDateTime = new Date(startDateTime.getTime() + 3 * 60 * 60 * 1000);
    }
    
    // Добавляем 15 минут буферного времени после окончания для чата
    const endDateTimeWithChatBuffer = new Date(endDateTime.getTime() + 15 * 60 * 1000);
    
    const isInChatTimeFrame = now >= startDateTime && now <= endDateTimeWithChatBuffer;
    

    
    // Если флаг is_live установлен в true, показываем чат независимо от времени
    if (props.event.is_live === true) {
        return true;
    }
    
    // Если флаг is_live не установлен (null), определяем по времени события с буфером для чата
    return isInChatTimeFrame;
});

// Получаем URL для чата Кинескопа (только для live мероприятий в правильное время)
const chatUrl = computed(() => {
    if (!shouldShowChat.value || !props.user) {
        return null;
    }
    
    // Определяем ID для чата (используем kinescope_id если это видео, или kinescope_playlist_id если плейлист)
    let chatId = null;
    if (props.event.kinescope_type === 'video' && props.event.kinescope_id) {
        chatId = props.event.kinescope_id;
    } else if (props.event.kinescope_type === 'playlist' && props.event.kinescope_playlist_id) {
        chatId = props.event.kinescope_playlist_id;
    }
    
    if (!chatId) {
        return null;
    }
    
    // Формируем имя пользователя из данных пользователя
    const username = props.user.full_name || `${props.user.first_name || ''} ${props.user.last_name || ''}`.trim() || 'Пользователь';
    
    // Используем ID пользователя как member_id
    const memberId = props.user.id;
    
    // Создаем URL для чата Кинескопа
    const chatUrlResult = `https://kinescope.io/chat/${chatId}?username=${encodeURIComponent(username)}&id=${memberId}`;
    

    
    return chatUrlResult;
});

// Форматируем дату
const formatDate = (date) => {
    if (!date) return '';
    const parsedDate = new Date(date);
    // Проверяем что дата валидна и не является 1970 годом
    if (isNaN(parsedDate.getTime()) || parsedDate.getFullYear() < 2000) {
        return '';
    }
    return parsedDate.toLocaleDateString('ru-RU', {
        day: 'numeric',
        month: 'long',
        year: 'numeric'
    });
};

// Форматируем время
const formatTime = (time) => {
    return time ? time.slice(0, 5) : '';
};

// Безопасный HTML для полного описания
const sanitizedFullDescription = computed(() => {
    if (!props.event.full_description) return '';
    return DOMPurify.sanitize(props.event.full_description);
});

const formatLabel = computed(() => ({ online: 'Онлайн', offline: 'Офлайн', hybrid: 'Гибрид' }[props.event.format] ?? null));

// Файл .ics для календаря телефона или компьютера — без обращения к серверу
const calendarHref = computed(() => {
    const start = parseEventDateTime(props.event.start_date, props.event.start_time);
    if (!start || isNaN(start.getTime())) {
        return null;
    }
    const end = parseEventDateTime(props.event.end_date || props.event.start_date, props.event.end_time) || new Date(start.getTime() + 2 * 60 * 60 * 1000);
    const stamp = (d) => `${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}T${String(d.getHours()).padStart(2, '0')}${String(d.getMinutes()).padStart(2, '0')}00`;
    const escape = (value) => String(value || '').replace(/[,;\\]/g, (m) => `\\${m}`).replace(/\n/g, ' ');
    const ics = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//MAG Expert//RU', 'BEGIN:VEVENT',
        `UID:event-${props.event.id}@mag-expert.ru`,
        `DTSTART;TZID=Europe/Moscow:${stamp(start)}`,
        `DTEND;TZID=Europe/Moscow:${stamp(end)}`,
        `SUMMARY:${escape(props.event.title)}`,
        `URL:${window.location.href}`,
        props.event.location ? `LOCATION:${escape(props.event.location)}` : null,
        'END:VEVENT', 'END:VCALENDAR',
    ].filter(Boolean).join('\r\n');
    return `data:text/calendar;charset=utf-8,${encodeURIComponent(ics)}`;
});
</script>

<template>
    <Head :title="`Просмотр: ${event.title}`" />

    <ProfileLayout>
        <Link :href="route('my-events')" class="-mb-3 inline-flex min-h-11 items-center gap-2 self-start text-sm font-bold text-brandblue-dark hover:underline">
            <ArrowLeftIcon class="h-[18px] w-[18px]" aria-hidden="true" />
            Мои мероприятия
        </Link>

        <div class="flex flex-wrap items-start gap-7">
            <div class="flex min-w-0 flex-[999_1_560px] flex-col gap-6">
                <header class="flex flex-col gap-3">
                    <div v-if="event.categories?.length || isLive" class="flex flex-wrap gap-2">
                        <span v-if="isLive" class="inline-flex items-center gap-2 rounded-full bg-brandcoral-soft px-3 py-1 text-xs font-extrabold text-[#b4442c]"><span class="cab-live !h-2 !w-2"></span>В эфире</span>
                        <span v-for="category in event.categories" :key="category.id" class="rounded-full bg-brandcoral-soft px-2.5 py-1 text-xs font-extrabold text-[#b4442c]">{{ category.name }}</span>
                    </div>
                    <h1 class="cab-h1">{{ event.title }}</h1>
                    <div class="flex flex-wrap gap-x-5 gap-y-2 text-[15px] font-semibold text-gray-500 dark:text-gray-400">
                        <span v-if="formatDate(event.start_date)" class="inline-flex items-center gap-2">
                            <CalendarDaysIcon class="h-[18px] w-[18px]" aria-hidden="true" />
                            {{ formatDate(event.start_date) }}<template v-if="event.end_date && event.end_date !== event.start_date"> — {{ formatDate(event.end_date) }}</template>
                        </span>
                        <span v-if="event.start_time" class="inline-flex items-center gap-2">
                            <ClockIcon class="h-[18px] w-[18px]" aria-hidden="true" />
                            {{ formatTime(event.start_time) }}<template v-if="event.end_time">–{{ formatTime(event.end_time) }}</template> МСК
                        </span>
                        <span v-if="formatLabel" class="inline-flex items-center gap-2"><VideoCameraIcon class="h-[18px] w-[18px]" aria-hidden="true" />{{ formatLabel }}</span>
                        <span v-if="event.location" class="inline-flex items-center gap-2"><MapPinIcon class="h-[18px] w-[18px]" aria-hidden="true" />{{ event.location }}</span>
                    </div>
                </header>

                <!-- Плеер и чат Кинескопа -->
                <div class="overflow-hidden rounded-[26px] bg-gray-900 shadow-[0_4px_10px_#0f172a0d,0_40px_80px_-40px_#0f172a4d]">
                    <KinescopePlayer
                        v-if="embedUrl"
                        :video-id="event.kinescope_type === 'video' ? event.kinescope_id : null"
                        :playlist-id="event.kinescope_type === 'playlist' ? event.kinescope_playlist_id : null"
                        :embed-url="embedUrl"
                        :progress="progress"
                        :save-url="progressUrl"
                        :track="!isLive"
                    />
                    <div v-else class="relative aspect-video">
                        <div class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-gradient-to-br from-[#1f2a3a] via-brandblue-dark to-brandblue px-6 text-center text-white">
                            <span class="flex h-16 w-16 items-center justify-center rounded-full bg-white/90 text-brandblue-dark"><PlayIcon class="ml-1 h-7 w-7" aria-hidden="true" /></span>
                            <span class="font-display text-2xl font-medium">Видео скоро будет доступно</span>
                            <span class="text-sm text-white/80">{{ isLive ? 'Мероприятие в процессе проведения' : 'Запись будет опубликована после окончания мероприятия' }}</span>
                        </div>
                    </div>
                    <div v-if="shouldShowChat && chatUrl && embedUrl" class="h-80 border-t border-gray-700 bg-gray-50 sm:h-96 lg:h-[500px]">
                        <iframe :src="chatUrl" class="h-full w-full border-0" frameborder="0" allowfullscreen allow="fullscreen" title="Чат трансляции"></iframe>
                    </div>
                </div>

                <section v-if="event.short_description || event.full_description" class="cab-panel flex flex-col gap-3 p-6 sm:p-7">
                    <h2 class="cab-h2">О мероприятии</h2>
                    <p v-if="event.short_description" class="text-base leading-[26px]">{{ event.short_description }}</p>
                    <div v-if="event.full_description" class="prose max-w-none text-[15px] leading-6 text-gray-600 dark:prose-invert dark:text-gray-300" v-html="sanitizedFullDescription"></div>
                </section>
            </div>

            <aside class="flex min-w-0 flex-[1_1_320px] flex-col gap-5">
                <section class="cab-panel flex flex-col gap-2.5 p-5">
                    <span class="text-xs font-extrabold uppercase tracking-[.12em] text-gray-500">Ваш доступ</span>
                    <span class="cab-chip-ok self-start !text-[13px]">Доступ открыт</span>
                    <span class="text-[13px] leading-[19px] text-gray-500 dark:text-gray-400">Трансляция и запись доступны вам в этом разделе.</span>
                    <a v-if="calendarHref" :href="calendarHref" :download="`${event.slug}.ics`" class="cab-btn-ghost mt-1">
                        <CalendarDaysIcon class="h-[18px] w-[18px]" aria-hidden="true" />
                        Добавить в календарь
                    </a>
                    <a v-if="event.file_path" :href="event.file_path" target="_blank" rel="noopener" class="cab-btn-ghost">
                        <DocumentTextIcon class="h-[18px] w-[18px]" aria-hidden="true" />
                        Программа мероприятия
                    </a>
                </section>

                <section v-if="event.speakers?.length" class="cab-panel px-5 pb-2 pt-[18px]">
                    <h2 class="pb-2 font-display text-xl font-medium">Спикеры</h2>
                    <div v-for="speaker in event.speakers" :key="speaker.id" class="flex gap-3 border-t border-gray-100 py-3 dark:border-gray-800">
                        <img v-if="speaker.photo" :src="speaker.photo" :alt="`${speaker.first_name} ${speaker.last_name}`" class="h-[60px] w-[52px] shrink-0 rounded-[999px_999px_12px_12px] object-cover" />
                        <span v-else class="flex h-[60px] w-[52px] shrink-0 items-end justify-center rounded-[999px_999px_12px_12px] bg-brandblue-soft pb-2 font-display text-base text-brandblue-dark">{{ speaker.first_name?.[0] }}{{ speaker.last_name?.[0] }}</span>
                        <div class="min-w-0">
                            <div class="text-sm font-bold">{{ speaker.last_name }} {{ speaker.first_name }} {{ speaker.middle_name }}</div>
                            <div v-if="speaker.pivot?.topic" class="text-[13px] italic text-brandblue-dark">{{ speaker.pivot.topic }}</div>
                            <div v-if="speaker.position || speaker.company" class="text-[13px] text-gray-500">{{ [speaker.position, speaker.company].filter(Boolean).join(', ') }}</div>
                            <div v-if="speaker.regalia" class="line-clamp-3 text-xs text-gray-500">{{ speaker.regalia }}</div>
                        </div>
                    </div>
                </section>

                <section class="cab-panel flex flex-col gap-1 px-5 py-[18px]">
                    <span class="text-sm font-bold">Не работает трансляция?</span>
                    <span class="text-[13px] leading-[19px] text-gray-500 dark:text-gray-400">Обновите страницу или откройте её в другом браузере. Если не помогло — позвоните.</span>
                    <a href="tel:+79952220779" class="pt-1 text-sm font-bold text-brandblue-dark">+7 (995) 222-07-79</a>
                </section>
            </aside>
        </div>
    </ProfileLayout>
</template>
