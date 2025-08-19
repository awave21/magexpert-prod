<script setup>
import { ref, watch, computed } from 'vue';
import SlideOverModal from '@/Components/Modal/SlideOverModal.vue';
import SelectList from '@/Components/SelectList.vue';

const props = defineProps({
  show: Boolean,
  languages: Array,
  years: Array,
  filters: Object,
});

const emit = defineEmits(['close', 'apply']);

// Состояние фильтров
const languageFilter = ref(props.filters.language || '');
const yearFilter = ref(props.filters.year || '');

// Сброс фильтров
const resetFilters = () => {
  languageFilter.value = '';
  yearFilter.value = '';
};

const apply = () => {
  emit('apply', {
    language: languageFilter.value,
    year: yearFilter.value,
  });
};

watch(() => props.filters, (newFilters) => {
  languageFilter.value = newFilters.language || '';
  yearFilter.value = newFilters.year || '';
}, { deep: true });

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

// Опции для языков
const languageOptions = computed(() => [
  { value: '', name: 'Все языки' },
  ...props.languages.map(lang => ({
    value: lang,
    name: getLanguageLabel(lang)
  }))
]);

// Опции для годов
const yearOptions = computed(() => [
  { value: '', name: 'Все годы' },
  ...props.years.map(year => ({
    value: year,
    name: year.toString()
  }))
]);
</script>

<template>
  <SlideOverModal :show="show" @close="$emit('close')">
    <template #title>Фильтры документов</template>
    
    <div class="space-y-6">
      <!-- Язык документа -->
      <div>
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-3">Язык документа</h3>
        <SelectList
          v-model="languageFilter"
          :options="languageOptions"
          placeholder="Выберите язык"
        />
      </div>
      
      <!-- Год публикации -->
      <div>
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-3">Год публикации</h3>
        <SelectList
          v-model="yearFilter"
          :options="yearOptions"
          placeholder="Выберите год"
        />
      </div>
    </div>
    
    <template #footer>
      <div class="flex justify-between gap-3">
        <button
          @click="resetFilters"
          class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
        >
          Сбросить
        </button>
        <button
          @click="apply"
          class="inline-flex items-center rounded-lg bg-brandblue px-4 py-2 text-sm font-medium text-white hover:bg-brandblue/90 focus:outline-none"
        >
          Применить
        </button>
      </div>
    </template>
  </SlideOverModal>
</template> 