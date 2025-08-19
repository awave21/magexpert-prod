<script setup>
import { ref, computed } from 'vue';
import { 
  ChevronDownIcon, 
  ChevronUpIcon,
  ArrowTopRightOnSquareIcon 
} from '@heroicons/vue/24/outline';

const props = defineProps({
  partner: {
    type: Object,
    required: true
  }
});

// Состояние для управления раскрытием описания
const isExpanded = ref(false);

// Ограничиваем количество символов для краткого описания
const maxDescriptionLength = 150;

// Вычисляемое свойство для краткого описания
const shortDescription = computed(() => {
  if (!props.partner.description) return '';
  
  if (props.partner.description.length <= maxDescriptionLength) {
    return props.partner.description;
  }
  
  return props.partner.description.slice(0, maxDescriptionLength) + '...';
});

// Проверяем, нужна ли кнопка "Показать больше"
const needsExpansion = computed(() => {
  return props.partner.description && props.partner.description.length > maxDescriptionLength;
});

// Функция для переключения состояния раскрытия
const toggleExpansion = () => {
  isExpanded.value = !isExpanded.value;
};

// Функция для открытия сайта партнера
const openWebsite = () => {
  if (props.partner.website_url) {
    window.open(props.partner.website_url, '_blank', 'noopener,noreferrer');
  }
};
</script>

<template>
  <div class="group relative bg-white dark:bg-gray-800 rounded-2xl transition-all duration-300 border border-gray-200 dark:border-gray-700 hover:border-brandblue/30 dark:hover:border-brandblue/50 overflow-hidden flex flex-col h-full">
    <!-- Логотип партнера -->
    <div class="h-48 bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-700 dark:to-gray-800 p-6 flex items-center justify-center flex-shrink-0">
      <div class="w-full h-full flex items-center justify-center">
        <img 
          v-if="partner.logo_url" 
          :src="partner.logo_url" 
          :alt="partner.name"
          class="max-w-full max-h-full object-contain"
        />
        <div 
          v-else 
          class="w-16 h-16 bg-brandblue/10 dark:bg-brandblue/20 rounded-full flex items-center justify-center text-brandblue text-2xl font-bold"
        >
          {{ partner.name.charAt(0) }}
        </div>
      </div>
    </div>
    
    <!-- Контент карточки -->
    <div class="p-6 flex flex-col h-full">
      <!-- Название партнера -->
      <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-3 line-clamp-2">
        {{ partner.name }}
      </h3>
      
      <!-- Описание -->
      <div class="flex-grow mb-4">
        <div v-if="partner.description">
          <!-- Краткое описание или полное, если раскрыто -->
          <div 
            class="text-gray-600 dark:text-gray-300 leading-relaxed whitespace-pre-line"
            :class="{ 'line-clamp-3': !isExpanded }"
            v-html="isExpanded ? partner.description.replace(/\n/g, '<br>') : shortDescription.replace(/\n/g, '<br>')"
          ></div>
          
          <!-- Кнопка для раскрытия/скрытия полного описания -->
          <button 
            v-if="needsExpansion"
            @click="toggleExpansion"
            class="mt-2 inline-flex items-center text-sm font-medium text-brandblue hover:text-brandblue/80 dark:text-brandblue dark:hover:text-brandblue/80 transition-colors"
          >
            <span>{{ isExpanded ? 'Скрыть' : 'Показать больше' }}</span>
            <ChevronDownIcon v-if="!isExpanded" class="ml-1 h-4 w-4" />
            <ChevronUpIcon v-else class="ml-1 h-4 w-4" />
          </button>
        </div>
        
        <!-- Отсутствие описания -->
        <div v-else>
          <p class="text-gray-400 dark:text-gray-500 italic">
            Описание отсутствует
          </p>
        </div>
      </div>
      
      <!-- Ссылка на сайт - всегда внизу -->
      <div class="mt-auto">
        <button
          v-if="partner.website_url"
          @click="openWebsite"
          class="w-full inline-flex items-center justify-center px-4 py-2.5 text-sm font-medium rounded-lg cursor-pointer text-brandcoral transition-all duration-200 bg-brandcoral/10 hover:bg-brandcoral hover:text-white"
        >
          <span>Посетить</span>
          <ArrowTopRightOnSquareIcon class="ml-2 h-4 w-4" />
        </button>
        
        <!-- Заглушка, если нет ссылки на сайт -->
        <div v-else class="w-full inline-flex items-center justify-center px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-400 dark:text-gray-500 text-sm font-medium rounded-full cursor-not-allowed">
          <span>Сайт недоступен</span>
        </div>
      </div>
    </div>
    
    <!-- Эффект hover для всей карточки -->
    <div class="absolute inset-0 bg-brandblue/5 opacity-0 group-hover:opacity-100 transition-opacity duration-300 pointer-events-none rounded-2xl"></div>
  </div>
</template>

<style scoped>
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.line-clamp-3 {
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style> 