<script setup>
import { Head, usePage, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileLayout from '@/Layouts/ProfileLayout.vue';
import { 
    CalendarIcon, 
    ClockIcon, 
    MapPinIcon,
    PlayIcon,
    EyeIcon,
    UsersIcon,
    FireIcon,
    ChevronLeftIcon,
    ChevronRightIcon
} from '@heroicons/vue/24/outline';

const page = usePage();
const user = computed(() => page.props.auth.user);

const props = defineProps({
    events: {
        type: Object,
        required: true
    },
    totalCount: {
        type: Number,
        default: 0
    },
    liveCount: {
        type: Number,
        default: 0
    }
});

// Функции форматирования
const formatDate = (date) => {
    if (!date) return '';
    const parsedDate = new Date(date);
    if (isNaN(parsedDate.getTime()) || parsedDate.getFullYear() < 2000) {
        return '';
    }
    return parsedDate.toLocaleDateString('ru-RU', {
        day: 'numeric',
        month: 'long',
        year: 'numeric'
    });
};

const formatTime = (time) => {
    return time ? time.slice(0, 5) : '';
};

const getEventFormat = (format) => {
    switch (format) {
        case 'online': return 'Онлайн';
        case 'offline': return 'Офлайн';
        case 'hybrid': return 'Гибридный';
        default: return format || '';
    }
};

const getEventType = (type) => {
    switch (type) {
        case 'webinar': return 'Вебинар';
        case 'conference': return 'Конференция';
        case 'workshop': return 'Мастер-класс';
        case 'other': return 'Другое';
        default: return type || '';
    }
};

const getFormatColor = (format) => {
    switch (format) {
        case 'online': return 'bg-brandcoral/10 text-brandcoral dark:bg-brandcoral/20 dark:text-brandcoral';
        case 'offline': return 'bg-brandblue/10 text-brandblue dark:bg-brandblue/20 dark:text-brandblue';
        case 'hybrid': return 'bg-gradient-to-r from-brandblue/10 to-brandcoral/10 text-brandblue dark:from-brandblue/20 dark:to-brandcoral/20 dark:text-brandblue';
        default: return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
    }
};

// Определение статуса мероприятия
const getEventStatus = (event) => {
    if (event.is_on_demand) {
        return 'on_demand';
    }
    
    if (!event.start_date) {
        return 'archive';
    }
    
    const now = new Date();
    const startDateTime = new Date(`${event.start_date} ${event.start_time || '00:00:00'}`);
    const endDateTime = event.end_date && event.end_time 
        ? new Date(`${event.end_date} ${event.end_time}`)
        : new Date(startDateTime.getTime() + 3 * 60 * 60 * 1000); // +3 часа если нет времени окончания
    
    if (event.start_time && startDateTime <= now && endDateTime >= now) {
        return 'live';
    } else if (startDateTime > now) {
        return 'upcoming';
    } else {
        return 'archive';
    }
};

// Получение основной категории для отображения
const getMainCategory = (event) => {
    if (event.categories && event.categories.length > 0) {
        return event.categories[0].name;
    }
    if (event.category) {
        return event.category.name;
    }
    return null;
};

// Статистика для ProfileLayout
const stats = computed(() => ({
    total: props.totalCount,
    availableEvents: props.totalCount,
    archive: props.totalCount - props.liveCount
}));
</script>

<template>
    <Head title="Мои мероприятия" />

    <ProfileLayout :stats="stats">
        <div class="p-3 sm:p-4 lg:p-6 space-y-4 sm:space-y-6">
            <!-- Заголовок -->
            <div class="flex flex-col gap-3 sm:gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold text-gray-800 dark:text-white">
                        Мои мероприятия
                    </h1>
                    <div class="mt-1 sm:mt-2 text-sm sm:text-base text-gray-600 dark:text-gray-300">
                        <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-0">
                            <span>Всего доступно: {{ totalCount }}</span>
                            <span v-if="liveCount > 0" class="text-red-600 dark:text-red-400 font-medium">
                                <span class="hidden sm:inline"> • </span>{{ liveCount }} в эфире
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Список мероприятий -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div v-if="events.data.length" class="divide-y divide-gray-200 dark:divide-gray-700">
                    <div 
                        v-for="event in events.data" 
                        :key="event.id"
                        class="p-3 sm:p-4 lg:p-6 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-all group"
                    >
                        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                            <div class="flex-1">
                                <!-- Статусы и метки -->
                                <div class="flex flex-wrap gap-2 mb-3">
                                    <!-- Live статус -->
                                    <span v-if="getEventStatus(event) === 'live'" class="bg-red-100 text-red-800 text-xs font-medium px-2.5 py-0.5 rounded-full dark:bg-red-900 dark:text-red-300 flex items-center">
                                        <div class="w-2 h-2 bg-red-500 rounded-full mr-1.5 animate-pulse"></div>
                                        LIVE
                                    </span>
                                    
                                    <!-- Формат -->
                                    <span 
                                        v-if="event.format" 
                                        :class="getFormatColor(event.format)"
                                        class="text-xs font-medium px-2.5 py-0.5 rounded-full"
                                    >
                                        {{ getEventFormat(event.format) }}
                                    </span>

                                    <!-- По запросу -->
                                    <span v-if="event.is_on_demand" class="bg-brandblue/10 text-brandblue text-xs font-medium px-2.5 py-0.5 rounded-full dark:bg-brandblue/20 dark:text-brandblue">
                                        По запросу
                                    </span>

                                    <!-- Категория -->
                                    <span v-if="getMainCategory(event)" class="bg-brandcoral/10 text-brandcoral text-xs font-medium px-2.5 py-0.5 rounded-full dark:bg-brandcoral/20 dark:text-brandcoral">
                                        {{ getMainCategory(event) }}
                                    </span>

                                    <!-- Тип мероприятия -->
                                    <span v-if="event.event_type" class="bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-0.5 rounded-full dark:bg-gray-700 dark:text-gray-300">
                                        {{ getEventType(event.event_type) }}
                                    </span>
                                </div>
                                
                                <!-- Название -->
                                <h3 class="text-base sm:text-lg font-semibold text-gray-800 dark:text-white mb-2 group-hover:text-brandblue transition-colors line-clamp-2 lg:line-clamp-none">
                                    {{ event.title }}
                                </h3>
                                
                                <!-- Краткое описание -->
                                <p v-if="event.short_description" class="text-sm sm:text-base text-gray-600 dark:text-gray-300 mb-3 line-clamp-2">
                                    {{ event.short_description }}
                                </p>
                                
                                <!-- Детали мероприятия -->
                                <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2 sm:gap-4 text-xs sm:text-sm text-gray-600 dark:text-gray-300 mb-3">
                                    <div v-if="formatDate(event.start_date)" class="flex items-center">
                                        <CalendarIcon class="w-3 h-3 sm:w-4 sm:h-4 mr-1 text-brandblue flex-shrink-0" />
                                        <span class="truncate">{{ formatDate(event.start_date) }}</span>
                                        <span v-if="event.end_date && event.end_date !== event.start_date && formatDate(event.end_date)" class="hidden sm:inline">
                                            - {{ formatDate(event.end_date) }}
                                        </span>
                                    </div>
                                    <div v-if="event.start_time" class="flex items-center">
                                        <ClockIcon class="w-3 h-3 sm:w-4 sm:h-4 mr-1 text-brandblue flex-shrink-0" />
                                        <span>{{ formatTime(event.start_time) }}</span>
                                        <span v-if="event.end_time"> - {{ formatTime(event.end_time) }}</span>
                                    </div>
                                    <div v-if="event.location" class="flex items-center">
                                        <MapPinIcon class="w-3 h-3 sm:w-4 sm:h-4 mr-1 text-brandblue flex-shrink-0" />
                                        <span class="truncate">{{ event.location }}</span>
                                    </div>
                                </div>
                                
                                <!-- Спикеры -->
                                <div v-if="event.speakers && event.speakers.length" class="flex items-center text-xs sm:text-sm text-gray-600 dark:text-gray-300">
                                    <UsersIcon class="w-3 h-3 sm:w-4 sm:h-4 mr-1 text-brandblue flex-shrink-0" />
                                    <span class="truncate">{{ event.speakers.slice(0, 1).map(s => `${s.first_name} ${s.last_name}`).join(', ') }}</span>
                                    <span v-if="event.speakers.length > 1" class="flex-shrink-0"> +{{ event.speakers.length - 1 }}</span>
                                </div>
                            </div>
                            
                            <!-- Кнопки действий -->
                            <div class="flex flex-row lg:flex-col gap-2 lg:ml-6">
                                <!-- Кнопка просмотра live -->
                                <Link 
                                    v-if="getEventStatus(event) === 'live'"
                                    :href="route('my-events.view', { event: event.slug })"
                                    class="inline-flex items-center justify-center px-3 sm:px-4 py-2 bg-brandcoral hover:bg-brandcoral/90 text-white text-xs sm:text-sm font-medium rounded-lg transition-colors flex-1 lg:flex-none"
                                >
                                    <PlayIcon class="w-3 h-3 sm:w-4 sm:h-4 mr-1 sm:mr-2" />
                                    <span class="hidden sm:inline">Смотреть </span>live
                                </Link>
                                
                                <!-- Кнопка просмотра записи/контента -->
                                <Link 
                                    v-else-if="(getEventStatus(event) === 'archive' || getEventStatus(event) === 'on_demand') && (event.kinescope_id || event.kinescope_playlist_id)"
                                    :href="route('my-events.view', { event: event.slug })"
                                    class="inline-flex items-center justify-center px-3 sm:px-4 py-2 bg-brandcoral hover:bg-brandcoral/90 text-white text-xs sm:text-sm font-medium rounded-lg transition-colors flex-1 lg:flex-none"
                                >
                                    <PlayIcon class="w-3 h-3 sm:w-4 sm:h-4 mr-1 sm:mr-2" />
                                    {{ getEventStatus(event) === 'on_demand' ? 'Смотреть' : 'Запись' }}
                                </Link>
                                
                                <!-- Кнопка подробнее (всегда доступна) -->
                                <Link 
                                    :href="route('events.show', { event: event.slug })"
                                    class="inline-flex items-center justify-center px-3 sm:px-4 py-2 bg-brandblue/10 text-brandblue hover:bg-brandblue hover:text-white text-xs sm:text-sm font-medium rounded-lg transition-colors flex-1 lg:flex-none"
                                >
                                    <EyeIcon class="w-3 h-3 sm:w-4 sm:h-4 mr-1 sm:mr-2" />
                                    Подробнее
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Пустой список -->
                <div v-else class="text-center py-12">
                    <CalendarIcon class="w-12 h-12 text-gray-400 mx-auto mb-4" />
                    <h3 class="text-lg font-medium text-gray-600 dark:text-gray-300 mb-2">
                        Нет доступных мероприятий
                    </h3>
                    <p class="text-gray-500 dark:text-gray-400 mb-4">
                        У вас пока нет доступа к мероприятиям. Приобретите доступ или обратитесь к администратору.
                    </p>
                    <Link 
                        :href="route('events.index')" 
                        class="inline-flex items-center px-4 py-2 bg-brandblue hover:bg-brandblue/90 text-white font-medium rounded-lg transition-colors"
                    >
                        Посмотреть все мероприятия
                    </Link>
                </div>
            </div>

            <!-- Пагинация -->
            <div v-if="events.data.length && events.last_page > 1" class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 sm:gap-0">
                <div class="flex items-center text-xs sm:text-sm text-gray-700 dark:text-gray-300 justify-center sm:justify-start">
                    Показано {{ events.from }}-{{ events.to }} из {{ events.total }}
                </div>
                
                <div class="flex items-center justify-center space-x-1 sm:space-x-2">
                    <!-- Предыдущая страница -->
                    <Link
                        v-if="events.prev_page_url"
                        :href="events.prev_page_url"
                        class="inline-flex items-center px-2 sm:px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-xs sm:text-sm font-medium text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                    >
                        <ChevronLeftIcon class="w-3 h-3 sm:w-4 sm:h-4 sm:mr-1" />
                        <span class="hidden sm:inline">Назад</span>
                    </Link>
                    
                    <!-- Номера страниц -->
                    <div class="flex items-center space-x-1">
                        <template v-for="page in events.links" :key="page.label">
                            <Link
                                v-if="page.url && !page.label.includes('Previous') && !page.label.includes('Next')"
                                :href="page.url"
                                :class="[
                                    'inline-flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 text-xs sm:text-sm font-medium rounded-lg transition-colors',
                                    page.active
                                        ? 'bg-brandblue text-white'
                                        : 'border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 hover:bg-brandblue/10 dark:hover:bg-brandblue/20'
                                ]"
                                v-html="page.label"
                            />
                            <span
                                v-else-if="!page.url && !page.label.includes('Previous') && !page.label.includes('Next')"
                                class="inline-flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400"
                                v-html="page.label"
                            />
                        </template>
                    </div>
                    
                    <!-- Следующая страница -->
                    <Link
                        v-if="events.next_page_url"
                        :href="events.next_page_url"
                        class="inline-flex items-center px-2 sm:px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-xs sm:text-sm font-medium text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                    >
                        <span class="hidden sm:inline">Вперед</span>
                        <ChevronRightIcon class="w-3 h-3 sm:w-4 sm:h-4 sm:ml-1" />
                    </Link>
                </div>
            </div>
        </div>
    </ProfileLayout>
</template>

<style scoped>
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* Адаптивное скрытие line-clamp на больших экранах */
@media (min-width: 1024px) {
  .lg\:line-clamp-none {
    display: block;
    -webkit-line-clamp: unset;
    line-clamp: unset;
    -webkit-box-orient: unset;
    overflow: visible;
  }
}
</style>