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
    TvIcon,
    ChartBarIcon,
    UsersIcon,
    FireIcon
} from '@heroicons/vue/24/outline';

const page = usePage();
const user = computed(() => page.props.auth.user);

const props = defineProps({
    liveEvents: {
        type: Array,
        default: () => []
    },
    upcomingEvents: {
        type: Array,
        default: () => []
    },
    stats: {
        type: Object,
        default: () => ({})
    }
});

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
        default: return format;
    }
};

const getFormatColor = (format) => {
    switch (format) {
        case 'online': return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300';
        case 'offline': return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300';
        case 'hybrid': return 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300';
        default: return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
    }
};
</script>

<template>
    <Head title="Личный кабинет" />

    <ProfileLayout :stats="stats">
        <div class="p-3 sm:p-6 space-y-6 sm:space-y-8">
            <div>
                <h1 class="mb-4 sm:mb-6 text-2xl sm:text-3xl font-bold text-gray-800 dark:text-white">
                    Добро пожаловать, {{ user.first_name }}!
                </h1>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6 mb-6 sm:mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="p-2 sm:p-3 bg-red-100 dark:bg-red-900/30 rounded-lg">
                                <TvIcon class="w-5 h-5 sm:w-6 sm:h-6 text-red-600 dark:text-red-400" />
                            </div>
                            <div class="ml-3 sm:ml-4">
                                <p class="text-xs sm:text-sm font-medium text-gray-600 dark:text-gray-400">Live трансляции</p>
                                <p class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-white">{{ user.stats?.liveEvents || stats.liveEvents || 0 }}</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="p-2 sm:p-3 bg-blue-100 dark:bg-blue-900/30 rounded-lg">
                                <CalendarIcon class="w-5 h-5 sm:w-6 sm:h-6 text-blue-600 dark:text-blue-400" />
                            </div>
                            <div class="ml-3 sm:ml-4">
                                <p class="text-xs sm:text-sm font-medium text-gray-600 dark:text-gray-400">Предстоящие</p>
                                <p class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-white">{{ user.stats?.upcomingEvents || stats.upcomingEvents || 0 }}</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 border border-gray-200 dark:border-gray-700 sm:col-span-2 lg:col-span-1">
                        <div class="flex items-center">
                            <div class="p-2 sm:p-3 bg-green-100 dark:bg-green-900/30 rounded-lg">
                                <ChartBarIcon class="w-5 h-5 sm:w-6 sm:h-6 text-green-600 dark:text-green-400" />
                            </div>
                            <div class="ml-3 sm:ml-4">
                                <p class="text-xs sm:text-sm font-medium text-gray-600 dark:text-gray-400">Доступно всего</p>
                                <p class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-white">{{ user.stats?.availableEvents || 0 }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="liveEvents.length" class="bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 rounded-xl p-4 sm:p-6 border-2 border-red-200 dark:border-red-800">
                <div class="flex items-center mb-4 sm:mb-6">
                    <div class="flex items-center">
                        <div class="w-2 h-2 sm:w-3 sm:h-3 bg-red-500 rounded-full mr-2 sm:mr-3 animate-pulse"></div>
                        <FireIcon class="w-5 h-5 sm:w-6 sm:h-6 text-red-600 dark:text-red-400 mr-1 sm:mr-2" />
                        <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-white">В эфире сейчас</h2>
                    </div>
                </div>
                
                <div class="grid gap-3 sm:gap-4">
                    <div 
                        v-for="event in liveEvents" 
                        :key="event.id"
                        class="bg-white dark:bg-gray-800 rounded-xl p-3 sm:p-4 border border-red-200 dark:border-red-700 hover:shadow-lg transition-all"
                    >
                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 sm:gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2 mb-2">
                                    <span class="bg-red-100 text-red-800 text-xs font-medium px-2 py-1 rounded-full dark:bg-red-900 dark:text-red-300">
                                        LIVE
                                    </span>
                                    <span 
                                        v-if="event.format" 
                                        :class="getFormatColor(event.format)"
                                        class="text-xs font-medium px-2 py-1 rounded-full"
                                    >
                                        {{ getEventFormat(event.format) }}
                                    </span>
                                </div>
                                
                                <h3 class="font-semibold text-gray-800 dark:text-white mb-2 text-sm sm:text-base line-clamp-2">
                                    {{ event.title }}
                                </h3>
                                
                                <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2 sm:gap-4 text-xs sm:text-sm text-gray-600 dark:text-gray-300 mb-2 sm:mb-3">
                                    <div v-if="formatDate(event.start_date)" class="flex items-center">
                                        <CalendarIcon class="w-3 h-3 sm:w-4 sm:h-4 mr-1 text-brandblue flex-shrink-0" />
                                        <span class="truncate">{{ formatDate(event.start_date) }}</span>
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
                                
                                <div v-if="event.speakers && event.speakers.length" class="flex items-center text-xs sm:text-sm text-gray-600 dark:text-gray-300 mb-2 sm:mb-0">
                                    <UsersIcon class="w-3 h-3 sm:w-4 sm:h-4 mr-1 text-brandblue flex-shrink-0" />
                                    <span class="truncate">{{ event.speakers.map(s => `${s.first_name} ${s.last_name}`).join(', ') }}</span>
                                </div>
                            </div>
                            
                            <div class="flex-shrink-0">
                                <Link 
                                    :href="route('my-events.view', { event: event.slug })"
                                    class="inline-flex items-center justify-center w-full sm:w-auto px-4 py-2.5 sm:py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-colors min-h-[44px] sm:min-h-0"
                                >
                                    <PlayIcon class="w-4 h-4 mr-2" />
                                    Смотреть
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 border border-gray-200 dark:border-gray-700">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 sm:gap-0 mb-4 sm:mb-6">
                    <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-white">Ближайшие мероприятия</h2>
                    <Link 
                        :href="route('events.index')" 
                        class="text-brandblue hover:text-blue-700 text-sm font-medium transition-colors text-right sm:text-left"
                    >
                        Все мероприятия →
                    </Link>
                </div>
                
                <div v-if="upcomingEvents.length" class="grid gap-3 sm:gap-4">
                    <div 
                        v-for="event in upcomingEvents" 
                        :key="event.id"
                        class="border border-gray-200 dark:border-gray-700 rounded-xl p-3 sm:p-4 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-all group"
                    >
                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 sm:gap-4">
                            <div class="flex-1 min-w-0">
                                <div v-if="event.format" class="flex items-center mb-2">
                                    <span 
                                        :class="getFormatColor(event.format)"
                                        class="text-xs font-medium px-2 py-1 rounded-full"
                                    >
                                        {{ getEventFormat(event.format) }}
                                    </span>
                                </div>
                                
                                <h3 class="font-semibold text-gray-800 dark:text-white mb-2 text-sm sm:text-base line-clamp-2 group-hover:text-brandblue transition-colors">
                                    {{ event.title }}
                                </h3>
                                
                                <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2 sm:gap-4 text-xs sm:text-sm text-gray-600 dark:text-gray-300 mb-2">
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
                                
                                <div v-if="event.speakers && event.speakers.length" class="flex items-center text-xs sm:text-sm text-gray-600 dark:text-gray-300 mb-2 sm:mb-0">
                                    <UsersIcon class="w-3 h-3 sm:w-4 sm:h-4 mr-1 text-brandblue flex-shrink-0" />
                                    <span class="truncate">{{ event.speakers.slice(0, 2).map(s => `${s.first_name} ${s.last_name}`).join(', ') }}</span>
                                    <span v-if="event.speakers.length > 2" class="hidden sm:inline"> и еще {{ event.speakers.length - 2 }}</span>
                                </div>
                            </div>
                            
                            <div class="flex-shrink-0 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity">
                                <Link 
                                    :href="route('events.show', { event: event.slug })"
                                    class="inline-flex items-center justify-center w-full sm:w-auto px-3 py-2.5 sm:py-2 bg-brandblue hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors min-h-[44px] sm:min-h-0"
                                >
                                    <EyeIcon class="w-4 h-4 mr-1" />
                                    Подробнее
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div v-else class="text-center py-8 sm:py-12">
                    <CalendarIcon class="w-10 h-10 sm:w-12 sm:h-12 text-gray-400 mx-auto mb-3 sm:mb-4" />
                    <h3 class="text-base sm:text-lg font-medium text-gray-600 dark:text-gray-300 mb-2">
                        Нет доступных мероприятий
                    </h3>
                    <p class="text-sm sm:text-base text-gray-500 dark:text-gray-400 mb-4 px-4">
                        У вас пока нет доступа к мероприятиям. Приобретите доступ или обратитесь к администратору.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-3 justify-center px-4">
                        <Link 
                            :href="route('events.index')" 
                            class="inline-flex items-center justify-center px-4 py-2.5 sm:py-2 bg-brandblue hover:bg-blue-700 text-white text-sm sm:text-base font-medium rounded-lg transition-colors min-h-[44px] sm:min-h-0"
                        >
                            Просмотреть все мероприятия
                        </Link>
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

@media (max-width: 640px) {
  .truncate {
    max-width: 200px;
  }
}

@media (max-width: 480px) {
  .line-clamp-2 {
    -webkit-line-clamp: 1;
    line-clamp: 1;
  }
  
  .truncate {
    max-width: 150px;
  }
}
</style>