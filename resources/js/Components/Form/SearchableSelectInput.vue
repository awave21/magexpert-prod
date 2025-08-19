<template>
  <div ref="componentRef">
    <label v-if="label" class="block text-sm font-medium text-zinc-900 dark:text-white">{{ label }}</label>
    <div class="relative mt-1">
      <button
        type="button"
        @click="toggleDropdown"
        class="relative w-full cursor-default rounded-lg border border-zinc-300 bg-white py-2 pl-3 pr-10 text-left text-zinc-900 placeholder-zinc-500 focus:border-brandblue focus:outline-none focus:ring-2 focus:ring-brandblue/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-400"
        :class="{ 'border-red-300 focus:border-red-500 focus:ring-red-500/20': error }"
      >
        <span class="flex items-center">
          <img v-if="selectedOption?.image" :src="selectedOption.image" alt="" class="size-6 shrink-0 rounded-full" />
          <span class="block truncate" :class="{ 'ml-3': selectedOption?.image, 'ml-0': !selectedOption?.image }">
            {{ selectedOption?.text || placeholder || 'Выберите значение' }}
          </span>
        </span>
        <span class="pointer-events-none absolute inset-y-0 right-0 ml-3 flex items-center pr-2">
          <ChevronUpDownIcon class="size-5 text-gray-400" aria-hidden="true" />
        </span>
      </button>

      <transition 
        enter-active-class="transition ease-out duration-100" 
        enter-from-class="transform opacity-0 scale-95" 
        enter-to-class="transform opacity-100 scale-100" 
        leave-active-class="transition ease-in duration-75" 
        leave-from-class="transform opacity-100 scale-100" 
        leave-to-class="transform opacity-0 scale-95"
      >
        <div
          v-if="isOpen"
          class="absolute z-10 mt-1 w-full overflow-hidden rounded-md bg-white shadow-lg ring-1 ring-black/5 focus:outline-none dark:bg-zinc-800 dark:ring-white/10"
        >
          <!-- Поле поиска -->
          <div v-if="searchable" class="sticky top-0 bg-white dark:bg-zinc-800 p-2 border-b border-zinc-200 dark:border-zinc-700">
            <input
              ref="searchInput"
              v-model="searchQuery"
              type="text"
              :placeholder="searchPlaceholder"
              class="w-full rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-900 placeholder-zinc-500 focus:border-brandblue focus:outline-none focus:ring-1 focus:ring-brandblue dark:border-zinc-600 dark:bg-zinc-700 dark:text-white dark:placeholder-zinc-400"
              @click.stop
              @keydown.escape="closeDropdown"
              @keydown.enter.prevent="selectFirstOption"
              @keydown.arrow-down.prevent="navigateOptions(1)"
              @keydown.arrow-up.prevent="navigateOptions(-1)"
            />
          </div>

          <!-- Список опций -->
          <div class="max-h-60 overflow-y-auto py-1">
            <!-- Индикатор загрузки -->
            <div
              v-if="isLoading"
              class="relative cursor-default select-none py-2 px-3 text-zinc-500 dark:text-zinc-400 flex items-center"
            >
              <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-zinc-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ loadingText }}
            </div>
            <!-- Сообщение о том, что ничего не найдено -->
            <div
              v-else-if="filteredOptions.length === 0 && searchQuery"
              class="relative cursor-default select-none py-2 px-3 text-zinc-500 dark:text-zinc-400"
            >
              {{ noResultsText }}
            </div>
            <div
              v-for="(option, index) in filteredOptions"
              :key="option.value"
              @click="selectOption(option)"
              @mouseenter="highlightedIndex = index"
              class="relative cursor-pointer select-none py-2 pl-3 pr-9 text-zinc-900 dark:text-white"
              :class="{
                'bg-brandblue/10 dark:bg-brandblue/20': highlightedIndex === index,
                'hover:bg-zinc-100 dark:hover:bg-zinc-700': highlightedIndex !== index
              }"
            >
              <div class="flex items-center">
                <img v-if="option.image" :src="option.image" alt="" class="size-6 shrink-0 rounded-full" />
                <div 
                  class="flex-1 min-w-0" 
                  :class="{ 'ml-3': option.image, 'ml-0': !option.image }"
                >
                  <span 
                    class="block truncate" 
                    :class="[
                      selectedOption?.value === option.value ? 'font-semibold' : 'font-normal'
                    ]"
                  >
                    {{ option.text }}
                  </span>
                  <!-- Дополнительная информация для событий -->
                  <div v-if="option.meta" class="text-xs text-zinc-500 dark:text-zinc-400 truncate">
                    <span v-if="option.meta.date">{{ option.meta.date }}</span>
                    <span v-if="option.meta.date && option.meta.location"> • </span>
                    <span v-if="option.meta.location">{{ option.meta.location }}</span>
                  </div>
                </div>
              </div>

              <span v-if="selectedOption?.value === option.value" class="absolute inset-y-0 right-0 flex items-center pr-4 text-brandblue dark:text-brandblue">
                <CheckIcon class="h-5 w-5" aria-hidden="true" />
              </span>
            </div>
          </div>
        </div>
      </transition>
    </div>
    
    <p v-if="error" class="mt-1 text-sm text-brandcoral dark:text-brandcoral">{{ error }}</p>
    <p v-if="hint" class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ hint }}</p>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue';
import { ChevronUpDownIcon, CheckIcon } from '@heroicons/vue/20/solid';
import axios from 'axios';

const props = defineProps({
  modelValue: {
    type: [String, Number, Boolean],
    default: '',
  },
  options: {
    type: Array,
    default: () => [],
    validator: (value) => value.every(opt => typeof opt === 'object' && 'value' in opt && 'text' in opt),
  },
  label: {
    type: String,
    default: '',
  },
  placeholder: {
    type: String,
    default: 'Выберите значение',
  },
  error: {
    type: String,
    default: '',
  },
  hint: {
    type: String,
    default: '',
  },
  searchable: {
    type: Boolean,
    default: true,
  },
  searchPlaceholder: {
    type: String,
    default: 'Поиск...',
  },
  noResultsText: {
    type: String,
    default: 'Ничего не найдено',
  },
  // Новые пропы для асинхронного поиска
  searchUrl: {
    type: String,
    default: '',
  },
  searchParam: {
    type: String,
    default: 'q',
  },
  minSearchLength: {
    type: Number,
    default: 2,
  },
  debounceDelay: {
    type: Number,
    default: 300,
  },
  loadingText: {
    type: String,
    default: 'Поиск...',
  },
});

const emit = defineEmits(['update:modelValue']);

const componentRef = ref(null);
const searchInput = ref(null);
const isOpen = ref(false);
const searchQuery = ref('');
const highlightedIndex = ref(-1);
const isLoading = ref(false);
const asyncOptions = ref([]);
const debounceTimer = ref(null);

const selectedOption = computed(() => {
  // Сначала ищем в локальных опциях
  let option = props.options.find(opt => opt.value === props.modelValue);
  
  // Если не найдено и есть асинхронные опции, ищем там
  if (!option && asyncOptions.value.length > 0) {
    option = asyncOptions.value.find(opt => opt.value === props.modelValue);
  }
  
  return option;
});

const filteredOptions = computed(() => {
  // Если используется асинхронный поиск, возвращаем асинхронные опции
  if (props.searchUrl && searchQuery.value.length >= props.minSearchLength) {
    return asyncOptions.value;
  }
  
  // Иначе используем локальную фильтрацию
  if (!props.searchable || !searchQuery.value) {
    return props.options;
  }
  
  const query = searchQuery.value.toLowerCase();
  return props.options.filter(option => 
    option.text.toLowerCase().includes(query)
  );
});

const selectOption = (option) => {
  emit('update:modelValue', option.value);
  closeDropdown();
};

const selectFirstOption = () => {
  if (filteredOptions.value.length > 0) {
    selectOption(filteredOptions.value[0]);
  }
};

const navigateOptions = (direction) => {
  const maxIndex = filteredOptions.value.length - 1;
  if (maxIndex < 0) return;
  
  if (highlightedIndex.value === -1) {
    highlightedIndex.value = direction > 0 ? 0 : maxIndex;
  } else {
    highlightedIndex.value += direction;
    if (highlightedIndex.value > maxIndex) {
      highlightedIndex.value = 0;
    } else if (highlightedIndex.value < 0) {
      highlightedIndex.value = maxIndex;
    }
  }
};

const closeDropdown = () => {
  isOpen.value = false;
  // Сохраняем выбранную опцию перед очисткой поиска
  const currentSelected = selectedOption.value;
  searchQuery.value = '';
  
  // Если есть выбранное значение и используется асинхронный поиск, сохраняем его
  if (props.searchUrl && props.modelValue && currentSelected) {
    asyncOptions.value = [currentSelected];
  }
  
  highlightedIndex.value = -1;
};

const toggleDropdown = async () => {
  isOpen.value = !isOpen.value;
  if (isOpen.value && props.searchable) {
    await nextTick();
    searchInput.value?.focus();
  }
};

const handleClickOutside = (event) => {
  if (componentRef.value && !componentRef.value.contains(event.target)) {
    closeDropdown();
  }
};

// Функция асинхронного поиска
const performAsyncSearch = async (query) => {
  if (!props.searchUrl || query.length < props.minSearchLength) {
    // Сохраняем выбранную опцию при очистке asyncOptions
    if (props.modelValue && selectedOption.value) {
      asyncOptions.value = [selectedOption.value];
    } else {
      asyncOptions.value = [];
    }
    return;
  }
  
  isLoading.value = true;
  
  try {
    const response = await axios.get(props.searchUrl, {
      params: {
        [props.searchParam]: query,
        limit: 20
      }
    });
    
    asyncOptions.value = response.data || [];
  } catch (error) {
    console.error('Ошибка поиска:', error);
    asyncOptions.value = [];
  } finally {
    isLoading.value = false;
  }
};

// Debounced поиск
const debouncedSearch = (query) => {
  // Очищаем предыдущий таймер
  if (debounceTimer.value) {
    clearTimeout(debounceTimer.value);
  }
  
  // Устанавливаем новый таймер
  debounceTimer.value = setTimeout(() => {
    performAsyncSearch(query);
  }, props.debounceDelay);
};

// Watcher для поискового запроса
watch(searchQuery, (newQuery) => {
  if (props.searchUrl) {
    debouncedSearch(newQuery);
  }
});

// Слушатель клика вне компонента
onMounted(() => {
  document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
  
  // Очищаем таймер при размонтировании
  if (debounceTimer.value) {
    clearTimeout(debounceTimer.value);
  }
});
</script>