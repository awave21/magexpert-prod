<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { 
  DocumentTextIcon, 
  CalendarDaysIcon,
  GlobeAltIcon
} from '@heroicons/vue/24/outline';

const props = defineProps({
  document: Object,
});

// Получение года из даты публикации
const publicationYear = computed(() => {
  if (!props.document.publication_date) return null;
  return new Date(props.document.publication_date).getFullYear();
});

// Получение расширения файла
const fileExtension = computed(() => {
  if (!props.document.file_path) return null;
  const extension = props.document.file_path.split('.').pop()?.toUpperCase();
  return extension;
});

// Цвет для типа файла (бейдж) - используем brandblue
const getFileTypeColor = (extension) => {
  return 'text-brandblue bg-brandblue/10 border-brandblue/30';
};

// Цвет для иконки документа - используем brandblue
const getFileTypeIconColor = (extension) => {
  return 'bg-brandblue/10 text-brandblue group-hover:bg-brandblue group-hover:text-white';
};

// Перевод языков
const getLanguageLabel = (language) => {
  const languages = {
    'ru': 'Русский',
    'en': 'English',
    'kz': 'Қазақша',
    'uk': 'Українська',
    'be': 'Беларуская',
    'ky': 'Кыргызча',
    'uz': 'O\'zbekcha',
    'tg': 'Тоҷикӣ',
    'mn': 'Монгол',
    'ka': 'ქართული',
    'hy': 'Հայերեն',
    'az': 'Azərbaycan',
  };
  return languages[language] || language;
};
</script>

<template>
  <div class="group relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 hover:shadow-lg hover:ring-gray-300 transition-all duration-300 dark:bg-gray-800 dark:ring-gray-700 dark:hover:ring-gray-600">

    
    <!-- Заголовок с иконкой документа -->
    <div class="p-6 pb-4">
      <div class="flex items-start justify-between">
        <div class="flex-1">
          <!-- Тип файла бейдж -->
          <div class="mb-3">
            <span 
              v-if="fileExtension"
              :class="getFileTypeColor(fileExtension)"
              class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold border-2"
            >
              <DocumentTextIcon class="w-3 h-3 mr-1.5" />
              {{ fileExtension }}
            </span>
          </div>
          
          <!-- Название документа -->
          <h3 class="text-lg font-semibold text-gray-900 dark:text-white line-clamp-3 mb-3 leading-tight">
            {{ document.title }}
          </h3>
        </div>
        
        <!-- Иконка большого документа -->
        <div class="ml-4 flex-shrink-0">
          <div 
            :class="getFileTypeIconColor(fileExtension)"
            class="flex h-14 w-14 items-center justify-center rounded-xl transition-all duration-300 group-hover:scale-110"
          >
            <DocumentTextIcon class="h-7 w-7" />
          </div>
        </div>
      </div>
    </div>

    <!-- Описание -->
    <div class="px-6 pb-4">
      <p v-if="document.description" class="text-sm text-gray-600 dark:text-gray-300 line-clamp-3 leading-relaxed">
        {{ document.description }}
      </p>
    </div>

    <!-- Нижняя информация -->
    <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900/50 border-t border-gray-100 dark:border-gray-700">
      <!-- Метаинформация -->
      <div class="flex items-center space-x-4 text-xs text-gray-500 dark:text-gray-400 mb-3">
        <!-- Год публикации -->
        <div v-if="publicationYear" class="flex items-center">
          <CalendarDaysIcon class="h-4 w-4 mr-1" />
          <span>{{ publicationYear }}</span>
        </div>
        
        <!-- Язык документа -->
        <div v-if="document.language" class="flex items-center">
          <GlobeAltIcon class="h-4 w-4 mr-1" />
          <span>{{ getLanguageLabel(document.language) }}</span>
        </div>
      </div>
      
      <!-- Кнопка действия -->
      <div v-if="document.file_url">
        <!-- Кнопка просмотра статьи -->
        <a 
          :href="document.file_url"
          target="_blank"
          class="w-full inline-flex items-center justify-center px-4 py-2.5 text-sm font-medium rounded-lg cursor-pointer text-brandcoral transition-all duration-200 bg-brandcoral/10 hover:bg-brandcoral hover:text-white"
          @click.stop
        >
          <DocumentTextIcon class="h-4 w-4 mr-2" />
          Открыть статью
        </a>
      </div>
    </div>
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