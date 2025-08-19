<script setup>
import { Link } from '@inertiajs/vue3';
import { 
  CalendarDaysIcon, 
  MapPinIcon,
  ArrowLongRightIcon
} from '@heroicons/vue/24/outline';

const props = defineProps({
  event: Object,
});

// Варианты для отображения формата
const formatOptions = {
  'online': 'Онлайн',
  'offline': 'Офлайн',
  'hybrid': 'Гибридный',
};

// Определяет тип мероприятия для отображения в карточке
const getEventType = (event) => {
  const types = {
    'webinar': 'Вебинар',
    'conference': 'Конференция',
    'course': 'Курс',
    'workshop': 'Мастер-класс',
    'seminar': 'Семинар'
  };
  
  return types[event.event_type] || event.event_type;
};

// Форматирование дат
const formatDate = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleDateString('ru-RU', {
    day: 'numeric',
    month: 'long',
    year: 'numeric'
  });
};

// Функция для форматирования цены
const formatPrice = (price) => {
  if (price === null || price === 0) {
    return 'Бесплатно';
  }
  // Преобразуем в число, чтобы убрать лишние нули из decimal строк
  const numPrice = parseFloat(price);
  // Форматируем число с пробелами в качестве разделителей тысяч
  // и убираем дробную часть если она равна 0
  return numPrice.toLocaleString('ru-RU', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
    useGrouping: true
  }).replace(/,/g, ' ');
};

// Функция для получения отображаемой цены с учетом новой логики
const getDisplayPrice = (event) => {
  // Если мероприятие не платное - показываем "Бесплатно"
  if (!event.is_paid) {
    return 'Бесплатно';
  }
  
  // Если платное, но цену не нужно показывать - показываем "Платно"
  if (event.is_paid && !event.show_price) {
    return 'Платно';
  }
  
  // Если платное и нужно показать цену, но цена не указана - показываем "Платно"
  if (event.is_paid && event.show_price && (!event.price || event.price === 0)) {
    return 'Платно';
  }
  
  // Если все условия выполнены - показываем цену
  return formatPrice(event.price);
};

// Функция для определения нужно ли показывать знак рубля
const shouldShowCurrency = (event) => {
  return event.is_paid && event.show_price && event.price && event.price > 0;
};

// Функция для склонения слова "эксперт"
const declensionExperts = (count) => {
  const declension = ['эксперт', 'эксперта', 'экспертов'];
  if (count % 100 >= 11 && count % 100 <= 19) {
    return declension[2];
  }
  const lastDigit = count % 10;
  if (lastDigit === 1) {
    return declension[0];
  } else if (lastDigit >= 2 && lastDigit <= 4) {
    return declension[1];
  } else {
    return declension[2];
  }
};

// Функция для получения первой строки из регалий
const getFirstRegaliaLine = (regalia) => {
  if (!regalia) return '';
  return regalia.split('\n')[0].trim();
};
</script>

<template>
  <div class="group relative flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white transition-all duration-200 hover:shadow-lg dark:border-gray-700 dark:bg-gray-800">
    <!-- Изображение мероприятия -->
    <div class="relative aspect-video w-full overflow-hidden">
      <img 
        v-if="event.image"
        :src="event.image" 
        :alt="event.title" 
        class="h-full w-full object-cover object-center transition-transform duration-300 group-hover:scale-105"
      />
      <div v-else class="flex h-full w-full items-center justify-center bg-gray-200 text-gray-500 dark:bg-gray-700 dark:text-gray-400">
        <CalendarDaysIcon class="h-16 w-16" />
      </div>
      
      <!-- Бейджи на изображении -->
      <div class="absolute right-2 top-2 flex flex-col items-end space-y-2">
        <span v-if="event.format" class="inline-flex items-center rounded-full bg-brandblue/70 px-2.5 py-1 text-xs font-medium text-white shadow backdrop-blur-sm">
          {{ formatOptions[event.format] || event.format }}
        </span>
        <span class="inline-flex items-center rounded-full bg-white/90 px-2.5 py-1 text-xs font-medium text-brandblue shadow backdrop-blur-sm dark:bg-black/70 dark:text-white">
          {{ getEventType(event) }}
        </span>
        <span v-if="event.is_archived" class="inline-flex items-center rounded-full bg-brandcoral/90 px-2.5 py-1 text-xs font-medium text-white shadow backdrop-blur-sm">
          Архив
        </span>
        <span v-if="event.is_on_demand" class="inline-flex items-center rounded-full bg-brandblue/90 px-2.5 py-1 text-xs font-medium text-white shadow backdrop-blur-sm">
          По запросу
        </span>
      </div>
    </div>
    
    <!-- Информация о мероприятии -->
    <div class="flex flex-1 flex-col p-5">
      <!-- Категории мероприятия -->
      <div v-if="(event.categories && event.categories.length > 0) || event.category" class="mb-2">
        <!-- Новая система множественных категорий -->
        <div v-if="event.categories && event.categories.length > 0" class="flex flex-wrap gap-1">
          <Link 
            v-for="(category, index) in event.categories.slice(0, 2)" 
            :key="category.id"
            :href="route('events.index', { category: category.id })" 
            class="relative z-10 inline-flex items-center rounded px-2 py-1 text-xs font-medium text-brandblue bg-brandblue/10 hover:bg-brandblue/20 transition-colors"
          >
            {{ category.name }}
          </Link>
          <span v-if="event.categories.length > 2" class="inline-flex items-center rounded px-2 py-1 text-xs font-medium text-gray-500 bg-gray-100 dark:bg-gray-700 dark:text-gray-400">
            +{{ event.categories.length - 2 }}
          </span>
        </div>
        
        <!-- Обратная совместимость со старой системой -->
        <div v-else-if="event.category" class="flex flex-wrap gap-1">
          <Link 
            :href="route('events.index', { category: event.category.id })" 
            class="relative z-10 inline-flex items-center rounded px-2 py-1 text-xs font-medium text-brandblue bg-brandblue/10 hover:bg-brandblue/20 transition-colors"
          >
            {{ event.category.name }}
          </Link>
        </div>
      </div>
      
      <h3 class="mb-2 text-xl font-bold text-gray-900 group-hover:text-brandblue dark:text-white dark:group-hover:text-brandblue">
        <Link :href="route('events.show', event.slug)" class="after:absolute after:inset-0">
          {{ event.title }}
        </Link>
      </h3>
      
      <p class="mb-4 flex-1 text-sm text-gray-600 line-clamp-2 dark:text-gray-400">
        {{ event.short_description }}
      </p>
      
      <!-- Блок с датой и местом проведения -->
      <div v-if="event.start_date || event.location" class="mb-4 pt-1">
        <!-- Тонкая разделительная линия сверху -->
        <div class="mb-3 border-t border-gray-100 dark:border-gray-700"></div>
        
        <div class="space-y-2">
          <div v-if="event.start_date && !event.is_on_demand" class="flex items-center text-sm text-gray-500 dark:text-gray-400">
            <CalendarDaysIcon class="mr-1.5 h-4 w-4 text-brandblue dark:text-brandblue/80" />
            <time>{{ formatDate(event.start_date) }}</time>
          </div>
          
          <div v-if="event.location" class="flex items-center text-sm text-gray-500 dark:text-gray-400">
            <MapPinIcon class="mr-1.5 h-4 w-4 text-brandblue dark:text-brandcoral/80" />
            <span>{{ event.location }}</span>
          </div>
        </div>
      </div>
      
      <!-- Спикеры мероприятия до 3-х -->
      <div v-if="event.speakers && event.speakers.length > 0" class="mt-auto pt-4">
        <div class="space-y-2">
          <div v-for="(speaker, index) in event.speakers.slice(0, 3)" :key="speaker.id" class="flex items-center space-x-2">
            <!-- Аватар спикера -->
            <div class="h-8 w-8 rounded-full overflow-hidden flex-shrink-0 border border-gray-200 dark:border-gray-600">
              <img v-if="speaker.photo" :src="speaker.photo" :alt="speaker.full_name" class="h-full w-full object-cover" />
              <div v-else class="h-full w-full flex items-center justify-center bg-brandblue/10 text-brandblue text-xs">
                {{ speaker.first_name[0] }}{{ speaker.last_name[0] }}
              </div>
            </div>
            <!-- Информация о спикере -->
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                {{ speaker.full_name || `${speaker.first_name} ${speaker.last_name}` }}
              </p>
              <div class="text-xs text-gray-500 dark:text-gray-400 truncate">
                <!-- Показываем роль и тему, если они есть -->
                <template v-if="speaker.pivot && (speaker.pivot.role || speaker.pivot.topic)">
                  <span v-if="speaker.pivot.role">{{ speaker.pivot.role }}</span>
                  <span v-if="speaker.pivot.role && speaker.pivot.topic"> — </span>
                  <span v-if="speaker.pivot.topic">{{ speaker.pivot.topic }}</span>
                </template>
                <!-- Иначе показываем первую строку из регалий -->
                <template v-else-if="getFirstRegaliaLine(speaker.regalia)">
                  {{ getFirstRegaliaLine(speaker.regalia) }}
                </template>
              </div>
            </div>
          </div>
          <p v-if="event.speakers.length > 3" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            + ещё {{ event.speakers.length - 3 }} {{ declensionExperts(event.speakers.length - 3) }}
          </p>
        </div>
      </div>
      
      <!-- Разделительная линия -->
      <div class="my-4 border-t border-gray-100 dark:border-gray-700"></div>
      
      <!-- Нижняя часть карточки с ценой и кнопкой -->
      <div class="flex items-center justify-between">
        <!-- Стоимость мероприятия слева -->
        <div class="text-base font-bold text-gray-900 dark:text-white">
          {{ getDisplayPrice(event) }}<span v-if="shouldShowCurrency(event)"> ₽</span>
        </div>
        
        <!-- Кнопка справа -->
        <Link 
          :href="route('events.show', event.slug)" 
          class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-brandcoral transition-all duration-200 bg-brandcoral/10 hover:bg-brandcoral hover:text-white"
        >
          <span v-if="event.is_archived">
            {{ event.kinescope_id ? 'Смотреть' : 'Подробнее' }}
          </span>
          <span v-else-if="event.is_on_demand">Запросить</span>
          <span v-else-if="event.is_paid">
            {{ event.show_price && event.price ? 'Купить' : 'Записаться' }}
          </span>
          <span v-else>Записаться</span>
          <ArrowLongRightIcon class="ml-1 h-5 w-5" />
        </Link>
      </div>
    </div>
  </div>
</template> 