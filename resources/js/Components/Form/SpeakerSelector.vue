<template>
  <div class="space-y-2">
    <label v-if="label" :for="id" class="block text-sm font-medium text-zinc-900 dark:text-white mb-1">
      {{ label }}
      <span v-if="required" class="text-red-600">*</span>
    </label>
    
    <div class="relative">
      <div @click="toggleDropdown" class="relative w-full cursor-default rounded-lg border border-zinc-300 bg-white py-2 pl-3 pr-10 text-left shadow-sm text-zinc-900 focus:border-zinc-500 focus:outline-none focus:ring-2 focus:ring-zinc-500/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:hover:border-zinc-600 transition-colors duration-150 ease-in-out hover:border-zinc-400">
        <span v-if="selectedSpeakers.length" class="flex items-center gap-2">
          <span class="block truncate">{{ selectedSpeakersText }}</span>
        </span>
        <span v-else class="block truncate text-zinc-500 dark:text-zinc-400">{{ placeholder }}</span>
        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-5 text-zinc-400" aria-hidden="true">
            <path fill-rule="evenodd" d="M12.53 16.28a.75.75 0 0 1-1.06 0l-7.5-7.5a.75.75 0 0 1 1.06-1.06L12 14.69l6.97-6.97a.75.75 0 1 1 1.06 1.06l-7.5 7.5Z" clip-rule="evenodd" />
          </svg>
        </span>
      </div>

      <transition 
        leave-active-class="transition ease-in duration-100" 
        leave-from-class="opacity-100" 
        leave-to-class="opacity-0"
        enter-active-class="transition ease-out duration-100"
        enter-from-class="opacity-0 translate-y-1"
        enter-to-class="opacity-100 translate-y-0"
      >
        <div v-if="showDropdown" class="absolute z-10 mt-1 w-full bg-white dark:bg-zinc-800 shadow-lg rounded-lg border border-zinc-300 dark:border-zinc-700 overflow-hidden">
          <div class="p-2 border-b border-zinc-200 dark:border-zinc-700">
            <input
              type="text"
              :id="id + '-search'"
              v-model="searchQuery"
              class="w-full rounded-md border-zinc-300 shadow-sm focus:border-zinc-500 focus:ring-zinc-500/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
              placeholder="Поиск спикеров..."
              @click.stop
            />
          </div>
          
          <div class="max-h-60 overflow-auto py-1">
            <div 
              v-for="speaker in availableSpeakers" 
              :key="speaker.id" 
              class="relative cursor-default select-none py-2 pl-3 pr-9 hover:bg-zinc-100 dark:hover:bg-zinc-700"
              @click="selectSpeaker(speaker)"
            >
              <div class="flex items-center gap-2">
                <div class="flex-shrink-0 size-5 rounded-full overflow-hidden bg-zinc-200 dark:bg-zinc-700">
                  <img v-if="speaker.photo" :src="speaker.photo" :alt="speaker.full_name" class="w-full h-full object-cover" />
                  <div v-else class="w-full h-full flex items-center justify-center text-zinc-500">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                  </div>
                </div>
                <div>
                  <div class="font-medium text-zinc-900 dark:text-white">{{ speaker.full_name }}</div>
                  <div class="text-xs text-zinc-500 dark:text-zinc-400">
                    {{ [speaker.position, speaker.company].filter(Boolean).join(', ') }}
                  </div>
                </div>
              </div>
            </div>
            
            <div v-if="availableSpeakers.length === 0" class="py-2 px-3 text-zinc-500 dark:text-zinc-400 text-center">
              {{ searchQuery ? 'Ничего не найдено' : 'Все спикеры уже выбраны' }}
            </div>
          </div>
        </div>
      </transition>
    </div>
    
    <div v-if="selectedSpeakers.length" class="mt-3 space-y-3">
      <div v-for="(speaker, index) in selectedSpeakers" :key="speaker.id" class="rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 overflow-hidden">
        <div class="flex items-center justify-between p-3 bg-zinc-50 dark:bg-zinc-700/50 border-b border-zinc-200 dark:border-zinc-700">
          <div class="flex items-center gap-2">
            <div class="flex-shrink-0 size-8 rounded-full overflow-hidden bg-zinc-200 dark:bg-zinc-700">
              <img v-if="speaker.photo" :src="speaker.photo" :alt="speaker.full_name" class="w-full h-full object-cover" />
              <div v-else class="w-full h-full flex items-center justify-center text-zinc-500">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
              </div>
            </div>
            <div>
              <div class="font-medium text-zinc-900 dark:text-white">{{ speaker.full_name }}</div>
              <div class="text-xs text-zinc-500 dark:text-zinc-400">
                {{ [speaker.position, speaker.company].filter(Boolean).join(', ') }}
              </div>
            </div>
          </div>
          
          <button 
            type="button" 
            @click.stop="removeSpeaker(index)" 
            class="text-zinc-500 hover:text-red-500 dark:text-zinc-400 dark:hover:text-red-400 transition-colors"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
          </button>
        </div>
        
        <div class="p-3">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <TextInput
              :id="`speaker-${speaker.id}-role`"
              label="Роль"
              v-model="speaker.role"
              placeholder="Например: Ведущий, Докладчик"
            />
            <TextInput
              :id="`speaker-${speaker.id}-topic`"
              label="Тема выступления"
              v-model="speaker.topic"
              placeholder="Тема выступления спикера"
            />
          </div>
        </div>
      </div>
    </div>
    
    <p v-if="error" class="mt-1 text-sm text-red-600">{{ error }}</p>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import TextInput from '@/Components/Form/TextInput.vue';

const props = defineProps({
  id: {
    type: String,
    required: true
  },
  label: {
    type: String,
    default: 'Спикеры'
  },
  modelValue: {
    type: Array,
    default: () => []
  },
  speakers: {
    type: Array,
    default: () => []
  },
  error: {
    type: String,
    default: ''
  },
  required: {
    type: Boolean,
    default: false
  },
  placeholder: {
    type: String,
    default: 'Выберите спикеров'
  }
});

const emit = defineEmits(['update:modelValue']);

const searchQuery = ref('');
const showDropdown = ref(false);
const selectedSpeakers = ref([]);

// Текст для отображения выбранных спикеров
const selectedSpeakersText = computed(() => {
  if (selectedSpeakers.value.length === 0) return '';
  if (selectedSpeakers.value.length === 1) return selectedSpeakers.value[0].full_name;
  return `Выбрано спикеров: ${selectedSpeakers.value.length}`;
});

// Доступные спикеры (не выбранные)
const availableSpeakers = computed(() => {
  const result = props.speakers.filter(speaker => {
    return !selectedSpeakers.value.some(s => s.id === speaker.id);
  });
  
  if (!searchQuery.value) return result;
  
  const query = searchQuery.value.toLowerCase();
  return result.filter(speaker => {
    return speaker.full_name.toLowerCase().includes(query) || 
           (speaker.position && speaker.position.toLowerCase().includes(query)) ||
           (speaker.company && speaker.company.toLowerCase().includes(query));
  });
});

// Инициализация выбранных спикеров
onMounted(() => {
  initializeSelectedSpeakers();
  
  // Добавляем обработчик клика вне компонента для скрытия выпадающего списка
  document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
});

// Инициализация выбранных спикеров из значения модели
const initializeSelectedSpeakers = () => {
  if (!props.modelValue || !props.modelValue.length) {
    selectedSpeakers.value = [];
    return;
  }
  
  selectedSpeakers.value = props.modelValue.map(item => {
    // Находим спикера по ID
    const speaker = props.speakers.find(s => s.id === item.id);
    if (speaker) {
      // Возвращаем объект спикера с дополнительными полями из модели
      return {
        ...speaker,
        role: item.role || '',
        topic: item.topic || '',
        sort_order: item.sort_order || 0
      };
    }
    return item;
  });
};

const handleClickOutside = (event) => {
  showDropdown.value = false;
};

// Переключение видимости выпадающего списка
const toggleDropdown = () => {
  showDropdown.value = !showDropdown.value;
};

// Выбор спикера из списка
const selectSpeaker = (speaker) => {
  // Добавляем спикера с дополнительными полями
  selectedSpeakers.value.push({
    ...speaker,
    role: '',
    topic: '',
    sort_order: selectedSpeakers.value.length
  });
  
  // Обновляем модель
  updateModel();
  
  // Очищаем поисковый запрос и скрываем выпадающий список
  searchQuery.value = '';
  showDropdown.value = false;
};

// Удаление спикера из выбранных
const removeSpeaker = (index) => {
  selectedSpeakers.value.splice(index, 1);
  
  // Обновляем порядок сортировки
  selectedSpeakers.value.forEach((speaker, idx) => {
    speaker.sort_order = idx;
  });
  
  // Обновляем модель
  updateModel();
};

// Обновление модели при изменении выбранных спикеров
const updateModel = () => {
  emit('update:modelValue', selectedSpeakers.value.map(speaker => ({
    id: speaker.id,
    role: speaker.role,
    topic: speaker.topic,
    sort_order: speaker.sort_order
  })));
};

// Обновляем выбранных спикеров при изменении значения модели извне
watch(() => props.modelValue, (newValue) => {
  if (JSON.stringify(newValue) !== JSON.stringify(selectedSpeakers.value.map(s => ({
    id: s.id,
    role: s.role,
    topic: s.topic,
    sort_order: s.sort_order
  })))) {
    initializeSelectedSpeakers();
  }
}, { deep: true });

// Обновляем модель при изменении ролей или тем выступлений
watch(selectedSpeakers, () => {
  updateModel();
}, { deep: true });
</script> 