<template>
  <div ref="componentRef">
    <label v-if="label" class="block text-sm font-medium text-zinc-900 dark:text-white">{{ label }}</label>
    <div class="relative mt-1">
      <button
        type="button"
        @click="isOpen = !isOpen"
        class="relative w-full cursor-default rounded-lg border border-zinc-300 bg-white py-2 pl-3 pr-10 text-left text-zinc-900 placeholder-zinc-500 focus:border-brandblue focus:outline-none focus:ring-2 focus:ring-brandblue/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-400"
      >
        <span class="flex items-center">
          <span class="block truncate">
            {{ selectedText }}
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
          class="absolute z-10 mt-1 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black/5 focus:outline-none dark:bg-zinc-800 dark:ring-white/10 sm:text-sm"
          :class="{ 'max-h-60': !searchable, 'max-h-80': searchable }"
        >
          <!-- Поиск -->
          <div v-if="searchable" class="sticky top-0 bg-white dark:bg-zinc-800 p-2 border-b border-zinc-200 dark:border-zinc-700">
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Поиск категорий..."
              class="w-full rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-900 placeholder-zinc-500 focus:border-brandblue focus:outline-none focus:ring-1 focus:ring-brandblue dark:border-zinc-600 dark:bg-zinc-700 dark:text-white dark:placeholder-zinc-400"
              @click.stop
            />
          </div>

          <!-- Опции -->
          <div class="max-h-48 overflow-y-auto">
            <div
              v-for="option in filteredOptions"
              :key="option.value"
              @click="toggleOption(option.value)"
              class="relative cursor-pointer select-none py-2 pl-3 pr-9 text-zinc-900 hover:bg-brandblue/10 dark:text-white dark:hover:bg-brandblue/20"
            >
              <div class="flex items-center">
                <input
                  type="checkbox"
                  :checked="isSelected(option.value)"
                  class="mr-3 rounded border-zinc-300 text-brandblue focus:ring-brandblue dark:border-zinc-600 dark:bg-zinc-700"
                  @click.stop
                />
                <img v-if="option.image" :src="option.image" alt="" class="size-6 shrink-0 rounded-full mr-3" />
                <span class="block truncate font-normal">{{ option.text }}</span>
              </div>
            </div>

            <!-- Пустое состояние -->
            <div v-if="filteredOptions.length === 0" class="py-4 px-3 text-center text-zinc-500 dark:text-zinc-400">
              <span v-if="searchQuery">Категории не найдены</span>
              <span v-else>Нет доступных категорий</span>
            </div>
          </div>

          <!-- Действия -->
          <div v-if="modelValue.length > 0" class="sticky bottom-0 bg-white dark:bg-zinc-800 border-t border-zinc-200 dark:border-zinc-700 p-2">
            <button
              type="button"
              @click="clearAll"
              class="w-full text-sm text-brandcoral hover:text-brandcoral/80 dark:text-brandcoral dark:hover:text-brandcoral/80"
            >
              Очистить все ({{ modelValue.length }})
            </button>
          </div>
        </div>
      </transition>
    </div>

    <!-- Выбранные элементы -->
    <div v-if="modelValue.length > 0 && showSelected" class="mt-2 flex flex-wrap gap-1">
      <span
        v-for="selectedId in modelValue"
        :key="selectedId"
        class="inline-flex items-center gap-x-1 rounded-md bg-brandblue/10 px-2 py-1 text-xs font-medium text-brandblue dark:bg-brandblue/20 dark:text-brandblue"
      >
        {{ getOptionText(selectedId) }}
        <button
          type="button"
          @click="removeOption(selectedId)"
          class="group relative -mr-1 h-3.5 w-3.5 rounded-sm hover:bg-brandblue/20 dark:hover:bg-brandblue/30"
        >
          <span class="sr-only">Удалить</span>
          <svg class="h-3.5 w-3.5" viewBox="0 0 14 14">
            <path d="m4 4 6 6m0-6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
          </svg>
        </button>
      </span>
    </div>

    <!-- Подсказка -->
    <div class="mt-1">
      <slot name="hint">
        <p v-if="hint" class="text-sm text-zinc-500 dark:text-zinc-400">{{ hint }}</p>
      </slot>
    </div>

    <!-- Ошибка -->
    <p v-if="error" class="mt-1 text-sm text-brandcoral dark:text-brandcoral">{{ error }}</p>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { ChevronUpDownIcon } from '@heroicons/vue/20/solid';

const props = defineProps({
  modelValue: {
    type: Array,
    default: () => [],
  },
  options: {
    type: Array,
    required: true,
    validator: (value) => value.every(opt => typeof opt === 'object' && 'value' in opt && 'text' in opt),
  },
  label: {
    type: String,
    default: '',
  },
  placeholder: {
    type: String,
    default: 'Выберите категории',
  },
  hint: {
    type: String,
    default: '',
  },
  error: {
    type: String,
    default: '',
  },
  searchable: {
    type: Boolean,
    default: true,
  },
  showSelected: {
    type: Boolean,
    default: true,
  },
  maxSelections: {
    type: Number,
    default: null,
  },
});

const emit = defineEmits(['update:modelValue']);

const isOpen = ref(false);
const searchQuery = ref('');

const selectedText = computed(() => {
  if (props.modelValue.length === 0) {
    return props.placeholder;
  }
  if (props.modelValue.length === 1) {
    return getOptionText(props.modelValue[0]);
  }
  return `Выбрано: ${props.modelValue.length}`;
});

const filteredOptions = computed(() => {
  if (!props.searchable || !searchQuery.value) {
    return props.options;
  }
  
  const query = searchQuery.value.toLowerCase();
  return props.options.filter(option => 
    option.text.toLowerCase().includes(query)
  );
});

const isSelected = (value) => {
  return props.modelValue.includes(value);
};

const getOptionText = (value) => {
  const option = props.options.find(opt => opt.value === value);
  return option ? option.text : '';
};

const toggleOption = (value) => {
  const currentValues = [...props.modelValue];
  const index = currentValues.indexOf(value);
  
  if (index > -1) {
    // Убираем из выбранных
    currentValues.splice(index, 1);
  } else {
    // Добавляем в выбранные (если не превышен лимит)
    if (!props.maxSelections || currentValues.length < props.maxSelections) {
      currentValues.push(value);
    }
  }
  
  emit('update:modelValue', currentValues);
};

const removeOption = (value) => {
  const currentValues = props.modelValue.filter(v => v !== value);
  emit('update:modelValue', currentValues);
};

const clearAll = () => {
  emit('update:modelValue', []);
  searchQuery.value = '';
};

// Ссылка на корневой элемент компонента
const componentRef = ref(null);

// Закрытие по клику вне компонента
const closeOnOutsideClick = (event) => {
  if (componentRef.value && !componentRef.value.contains(event.target)) {
    isOpen.value = false;
  }
};

onMounted(() => {
  document.addEventListener('click', closeOnOutsideClick);
});

onUnmounted(() => {
  document.removeEventListener('click', closeOnOutsideClick);
});
</script>