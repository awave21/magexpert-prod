<script setup>
import { ref, watch } from 'vue';
import SlideOverModal from '@/Components/Modal/SlideOverModal.vue';

const props = defineProps({
  show: Boolean,
  categories: Array,
  filters: Object,
  eventTypeOptions: Object,
  formatOptions: Object,
});

const emit = defineEmits(['close', 'apply']);

// Состояние фильтров
const categoryFilter = ref(props.filters.category || '');
const eventTypeFilter = ref(props.filters.type || '');
const formatFilter = ref(props.filters.format || '');

// Сброс фильтров
const resetFilters = () => {
  categoryFilter.value = '';
  eventTypeFilter.value = '';
  formatFilter.value = '';
};

const apply = () => {
  emit('apply', {
    category: categoryFilter.value,
    type: eventTypeFilter.value,
    format: formatFilter.value,
  });
};

watch(() => props.filters, (newFilters) => {
  categoryFilter.value = newFilters.category || '';
  eventTypeFilter.value = newFilters.type || '';
  formatFilter.value = newFilters.format || '';
}, { deep: true });
</script>

<template>
  <SlideOverModal :show="show" @close="$emit('close')">
    <template #title>Фильтры мероприятий</template>
    
    <div class="space-y-6">
      <!-- Категории -->
      <div>
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-3">Категория</h3>
        <div class="space-y-2">
          <div class="flex items-center" v-for="category in categories" :key="category.id">
            <input 
              :id="`category-${category.id}`" 
              type="radio"
              name="category" 
              :value="category.id" 
              v-model="categoryFilter" 
              class="h-4 w-4 text-brandblue focus:ring-brandblue"
            />
            <label :for="`category-${category.id}`" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
              {{ category.name }}
            </label>
          </div>
          <div class="flex items-center">
            <input 
              id="category-all" 
              type="radio" 
              name="category" 
              value="" 
              v-model="categoryFilter" 
              class="h-4 w-4 text-brandblue focus:ring-brandblue"
            />
            <label for="category-all" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
              Все категории
            </label>
          </div>
        </div>
      </div>
      
      <!-- Тип мероприятия -->
      <div>
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-3">Тип мероприятия</h3>
        <div class="space-y-2">
          <div class="flex items-center" v-for="(label, value) in eventTypeOptions" :key="value">
            <input 
              :id="`type-${value}`" 
              type="radio" 
              name="eventType" 
              :value="value" 
              v-model="eventTypeFilter" 
              class="h-4 w-4 text-brandblue focus:ring-brandblue"
            />
            <label :for="`type-${value}`" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
              {{ label }}
            </label>
          </div>
          <div class="flex items-center">
            <input 
              id="type-all" 
              type="radio" 
              name="eventType" 
              value="" 
              v-model="eventTypeFilter" 
              class="h-4 w-4 text-brandblue focus:ring-brandblue"
            />
            <label for="type-all" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
              Все типы
            </label>
          </div>
        </div>
      </div>
      
      <!-- Формат -->
      <div>
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-3">Формат</h3>
        <div class="space-y-2">
          <div class="flex items-center" v-for="(label, value) in formatOptions" :key="value">
            <input 
              :id="`format-${value}`" 
              type="radio" 
              name="format" 
              :value="value" 
              v-model="formatFilter" 
              class="h-4 w-4 text-brandblue focus:ring-brandblue"
            />
            <label :for="`format-${value}`" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
              {{ label }}
            </label>
          </div>
          <div class="flex items-center">
            <input 
              id="format-all" 
              type="radio" 
              name="format" 
              value="" 
              v-model="formatFilter" 
              class="h-4 w-4 text-brandblue focus:ring-brandblue"
            />
            <label for="format-all" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
              Все форматы
            </label>
          </div>
        </div>
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