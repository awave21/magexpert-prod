<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileLayout from '@/Layouts/ProfileLayout.vue';
import { 
    PlayIcon,
    ArrowLeftIcon,
    ClockIcon,
    CalendarIcon,
    MapPinIcon,
    UsersIcon
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
</script>

<template>
    <Head :title="`Просмотр: ${event.title}`" />

    <ProfileLayout>
        <div class="p-3 sm:p-4 lg:p-6">
            <!-- Заголовок и статус -->
            <div class="mb-4 sm:mb-6">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
                    <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold text-gray-800 dark:text-white line-clamp-2 sm:line-clamp-none">
                        {{ event.title }}
                    </h1>
                    
                    <!-- Live индикатор -->
                    <div v-if="isLive" class="flex items-center bg-red-100 text-red-800 px-2 sm:px-3 py-1 rounded-full text-xs sm:text-sm font-medium self-start">
                        <div class="w-2 h-2 bg-red-500 rounded-full mr-1.5 sm:mr-2 animate-pulse"></div>
                        В эфире
                    </div>
                </div>

                <!-- Информация о мероприятии -->
                <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2 sm:gap-4 text-xs sm:text-sm text-gray-600 dark:text-gray-300">
                    <div class="flex items-center">
                        <CalendarIcon class="w-3 h-3 sm:w-4 sm:h-4 mr-1 text-brandblue flex-shrink-0" />
                        <span class="truncate">{{ formatDate(event.start_date) }}</span>
                        <span v-if="event.end_date && event.end_date !== event.start_date" class="hidden sm:inline">
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
                    
                    <div v-if="event.format" class="flex items-center">
                        <UsersIcon class="w-3 h-3 sm:w-4 sm:h-4 mr-1 text-brandblue flex-shrink-0" />
                        <span>{{ event.format === 'online' ? 'Онлайн' : event.format === 'offline' ? 'Офлайн' : 'Гибрид' }}</span>
                    </div>
                </div>
            </div>

            <!-- Основной контент -->
            <div class="bg-white dark:bg-gray-800 rounded-xl overflow-hidden">

                
                <!-- Видео и чат для live мероприятий -->
                <div v-if="shouldShowChat && chatUrl && embedUrl" class="flex flex-col">
                    <!-- Плеер -->
                    <div class="aspect-video bg-gray-900 relative">
                        <div class="relative w-full h-full">
                            <iframe 
                                :src="embedUrl"
                                class="absolute inset-0 w-full h-full"
                                frameborder="0"
                                allowfullscreen
                                allow="autoplay; fullscreen; picture-in-picture; encrypted-media; gyroscope; accelerometer; clipboard-write; screen-wake-lock;"
                            ></iframe>
                        </div>
                    </div>
                    
                    <!-- Чат -->
                    <div class="bg-gray-50 dark:bg-gray-700 border-t border-gray-200 dark:border-gray-600">
                        <div class="h-80 sm:h-96 lg:h-[500px]">
                            <iframe 
                                :src="chatUrl"
                                class="w-full h-full border-0"
                                frameborder="0"
                                allowfullscreen
                                allow="fullscreen"
                            ></iframe>
                        </div>
                    </div>
                </div>
                
                <!-- Обычный плеер для не-live мероприятий или когда нет чата -->
                <div v-else class="aspect-video bg-gray-900 relative">
                    <template v-if="embedUrl">
                        <!-- Встроенный плеер Кинескопа -->
                        <div class="relative w-full h-full">
                            <iframe 
                                :src="embedUrl"
                                class="absolute inset-0 w-full h-full"
                                frameborder="0"
                                allowfullscreen
                                allow="autoplay; fullscreen; picture-in-picture; encrypted-media; gyroscope; accelerometer; clipboard-write; screen-wake-lock;"
                            ></iframe>
                        </div>
                    </template>
                    
                    <template v-else>
                        <!-- Заглушка, если нет видео -->
                        <div class="flex items-center justify-center h-full text-white">
                            <div class="text-center">
                                <PlayIcon class="w-16 h-16 mx-auto mb-4 opacity-50" />
                                <h3 class="text-xl font-medium mb-2">Видео скоро будет доступно</h3>
                                <p class="text-gray-300">
                                    {{ isLive ? 'Мероприятие в процессе проведения' : 'Запись будет опубликована после окончания мероприятия' }}
                                </p>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Описание мероприятия -->
                <div class="p-3 sm:p-4 lg:p-6">
                    <div v-if="event.short_description" class="mb-4">
                        <h3 class="text-base sm:text-lg font-semibold text-gray-800 dark:text-white mb-2">
                            Краткое описание
                        </h3>
                        <p class="text-sm sm:text-base text-gray-600 dark:text-gray-300 leading-relaxed">
                            {{ event.short_description }}
                        </p>
                    </div>

                    <div v-if="event.full_description" class="mb-4">
                        <h3 class="text-base sm:text-lg font-semibold text-gray-800 dark:text-white mb-2">
                            Полное описание
                        </h3>
                        <div class="text-sm sm:text-base text-gray-600 dark:text-gray-300 prose max-w-none leading-relaxed" v-html="sanitizedFullDescription"></div>
                    </div>

                    <!-- Спикеры -->
                    <div v-if="event.speakers && event.speakers.length" class="mb-4">
                        <h3 class="text-base sm:text-lg font-semibold text-gray-800 dark:text-white mb-3">
                            Спикеры
                        </h3>
                        <div class="grid gap-4 sm:gap-6 grid-cols-1 lg:grid-cols-2">
                            <div 
                                v-for="speaker in event.speakers" 
                                :key="speaker.id"
                                class="flex items-start space-x-3 sm:space-x-4 p-3 sm:p-4 bg-gray-50 dark:bg-gray-700 rounded-lg"
                            >
                                <img 
                                    v-if="speaker.photo" 
                                    :src="speaker.photo" 
                                    :alt="`${speaker.first_name} ${speaker.last_name}`"
                                    class="w-12 h-12 sm:w-16 sm:h-16 rounded-full object-cover flex-shrink-0"
                                />
                                <div v-else class="w-12 h-12 sm:w-16 sm:h-16 rounded-full bg-gray-300 dark:bg-gray-600 flex items-center justify-center flex-shrink-0">
                                    <span class="text-gray-600 dark:text-gray-300 font-medium text-sm sm:text-lg">
                                        {{ speaker.first_name.charAt(0) }}{{ speaker.last_name.charAt(0) }}
                                    </span>
                                </div>
                                
                                <div class="flex-1 min-w-0">
                                    <!-- Имя спикера -->
                                    <h4 class="font-semibold text-gray-800 dark:text-white text-sm sm:text-base">
                                        {{ speaker.first_name }} {{ speaker.last_name }}
                                    </h4>
                                    
                                    <!-- Роль и тема выступления -->
                                    <div v-if="speaker.pivot && (speaker.pivot.role || speaker.pivot.topic)" class="mt-2 p-2 bg-brandblue/10 rounded border-l-2 border-brandblue">
                                        <div class="text-xs sm:text-sm">
                                            <span v-if="speaker.pivot.role" class="font-medium text-brandblue">{{ speaker.pivot.role }}</span>
                                            <span v-if="speaker.pivot.role && speaker.pivot.topic" class="text-gray-500 dark:text-gray-400"> • </span>
                                            <span v-if="speaker.pivot.topic" class="italic text-gray-700 dark:text-gray-300">{{ speaker.pivot.topic }}</span>
                                        </div>
                                    </div>
                                    
                                    <!-- Должность и компания -->
                                    <p v-if="speaker.position || speaker.company" class="text-xs sm:text-sm text-gray-600 dark:text-gray-400 mt-2">
                                        {{ [speaker.position, speaker.company].filter(Boolean).join(', ') }}
                                    </p>
                                    
                                    <!-- Регалии -->
                                    <div v-if="speaker.regalia" class="mt-2">
                                        <p class="text-xs text-gray-500 dark:text-gray-400 font-medium mb-1">Регалии и достижения:</p>
                                        <p class="text-xs sm:text-sm text-gray-600 dark:text-gray-300 leading-relaxed line-clamp-3 lg:line-clamp-none">
                                            {{ speaker.regalia }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Категории -->
                    <div v-if="event.categories && event.categories.length" class="mb-4">
                        <h3 class="text-base sm:text-lg font-semibold text-gray-800 dark:text-white mb-2">
                            Категории
                        </h3>
                        <div class="flex flex-wrap gap-2">
                            <span 
                                v-for="category in event.categories" 
                                :key="category.id"
                                class="px-2 sm:px-3 py-1 bg-brandblue/10 text-brandblue text-xs sm:text-sm rounded-full"
                            >
                                {{ category.name }}
                            </span>
                        </div>
                    </div>
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

.line-clamp-3 {
  display: -webkit-box;
  -webkit-line-clamp: 3;
  line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

@media (min-width: 640px) {
  .sm\:line-clamp-none {
    display: block;
    -webkit-line-clamp: unset;
    line-clamp: unset;
    -webkit-box-orient: unset;
    overflow: visible;
  }
}

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