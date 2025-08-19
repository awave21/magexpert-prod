<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { MapPinIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
  id: {
    type: String,
    required: true,
  },
  label: {
    type: String,
    required: true,
  },
  modelValue: {
    type: String,
    default: ''
  },
  placeholder: {
    type: String,
    default: 'Введите город'
  },
  disabled: {
    type: Boolean,
    default: false
  },
  required: {
    type: Boolean,
    default: false
  },
  error: {
    type: String,
    default: ''
  }
});

const emit = defineEmits(['update:modelValue']);

const inputRef = ref(null);
const isOpen = ref(false);
const suggestions = ref([]);
const isLoading = ref(false);
const searchTimeout = ref(null);

// Поиск городов через внутренний API
const searchCities = async (query) => {
  if (!query || query.length < 2) {
    suggestions.value = [];
    return;
  }

  isLoading.value = true;

  try {
    const response = await fetch('/api/dadata/cities', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      },
      body: JSON.stringify({
        query: query
      })
    });

    if (response.ok) {
      const data = await response.json();
      
      if (data.success) {
        suggestions.value = data.suggestions.map(item => ({
          value: item.value,
          fullAddress: item.full_address,
          region: item.region,
          city: item.city,
          fiasId: item.fias_id
        }));
      } else {
        console.warn('Ошибка API:', data.error);
        suggestions.value = [];
      }
    } else {
      console.error('Ошибка HTTP:', response.status, response.statusText);
      suggestions.value = [];
    }
  } catch (error) {
    console.error('Ошибка при поиске городов:', error);
    suggestions.value = [];
  } finally {
    isLoading.value = false;
  }
};

// Обработка ввода в поле
const handleInput = (event) => {
  const value = event.target.value;
  emit('update:modelValue', value);
  
  // Очищаем предыдущий таймаут
  if (searchTimeout.value) {
    clearTimeout(searchTimeout.value);
  }
  
  // Устанавливаем новый таймаут для поиска
  searchTimeout.value = setTimeout(() => {
    searchCities(value);
    isOpen.value = true;
  }, 300);
};

// Выбор города из списка
const selectCity = (city) => {
  emit('update:modelValue', city.value);
  isOpen.value = false;
  suggestions.value = [];
  inputRef.value?.blur();
};

// Обработка клавиш
const handleKeydown = (event) => {
  if (event.key === 'Escape') {
    isOpen.value = false;
  }
};

// Закрытие списка при клике вне компонента
const handleClickOutside = (event) => {
  if (!inputRef.value?.contains(event.target)) {
    isOpen.value = false;
  }
};

// Фокус на поле ввода
const handleFocus = () => {
  if (props.modelValue && suggestions.value.length === 0) {
    searchCities(props.modelValue);
  }
  if (suggestions.value.length > 0) {
    isOpen.value = true;
  }
};

// Очистка таймаута при размонтировании компонента
onMounted(() => {
  document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
  if (searchTimeout.value) {
    clearTimeout(searchTimeout.value);
  }
});

// Вычисляемое свойство для отображения состояния загрузки
const showLoading = computed(() => isLoading.value && isOpen.value);
const showSuggestions = computed(() => isOpen.value && suggestions.value.length > 0 && !isLoading.value);
const showNoResults = computed(() => isOpen.value && !isLoading.value && suggestions.value.length === 0 && props.modelValue?.length >= 2);
</script>

<template>
  <div class="relative">
    <label v-if="label" :for="id" class="block text-sm font-medium text-zinc-900 dark:text-white">{{ label }}</label>
    <div class="relative mt-1">
      <input
        :id="id"
        ref="inputRef"
        :value="modelValue"
        type="text"
        :placeholder="placeholder"
        :disabled="disabled"
        :required="required"
        @input="handleInput"
        @focus="handleFocus"
        @keydown="handleKeydown"
        class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-2 pr-10 text-zinc-900 placeholder-zinc-500 focus:border-brandblue focus:outline-none focus:ring-2 focus:ring-brandblue/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-400 disabled:bg-zinc-100 dark:disabled:bg-zinc-600"
        :class="{ 'border-brandcoral focus:border-brandcoral focus:ring-brandcoral/20': error }"
        autocomplete="off"
      />
      
      <!-- Иконка -->
      <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
        <MapPinIcon v-if="!isLoading" class="h-5 w-5 text-zinc-400" />
        <div v-else class="animate-spin h-4 w-4 border-2 border-brandblue border-t-transparent rounded-full"></div>
      </div>
    </div>
    
    <!-- Сообщение об ошибке -->
    <p v-if="error" class="mt-1 text-sm text-brandcoral dark:text-brandcoral">{{ error }}</p>
    
    <!-- Выпадающий список -->
    <div
      v-if="showLoading || showSuggestions || showNoResults"
      class="absolute z-[9999] mt-1 w-full bg-white dark:bg-zinc-800 shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black/5 overflow-auto focus:outline-none sm:text-sm dark:ring-white/10"
    >
      <!-- Индикатор загрузки -->
      <div v-if="showLoading" class="px-4 py-2 text-sm text-zinc-500 dark:text-zinc-400 flex items-center">
        <div class="animate-spin mr-2 h-4 w-4 border-2 border-brandblue border-t-transparent rounded-full"></div>
        Поиск городов...
      </div>
      
      <!-- Список предложений -->
      <template v-else-if="showSuggestions">
        <button
          v-for="(city, index) in suggestions"
          :key="index"
          @click="selectCity(city)"
          class="w-full text-left px-4 py-2 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-700 focus:bg-brandblue/10 dark:focus:bg-brandblue/20 focus:outline-none"
        >
          <div class="flex items-center">
            <MapPinIcon class="h-4 w-4 text-zinc-400 mr-2 flex-shrink-0" />
            <div>
              <div class="font-medium text-zinc-900 dark:text-white">{{ city.city || city.value }}</div>
              <div v-if="city.region && city.region !== city.city" class="text-xs text-zinc-500 dark:text-zinc-400">
                {{ city.region }}
              </div>
            </div>
          </div>
        </button>
      </template>
      
      <!-- Сообщение об отсутствии результатов -->
      <div v-else-if="showNoResults" class="px-4 py-2 text-sm text-zinc-500 dark:text-zinc-400">
        Городов не найдено
      </div>
    </div>
  </div>
</template>