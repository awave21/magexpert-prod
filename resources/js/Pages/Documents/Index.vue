<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import DocumentFilters from '@/Components/Documents/DocumentFilters.vue';
import DocumentCard from '@/Components/Documents/DocumentCard.vue';
import { 
  MagnifyingGlassIcon, 
  AdjustmentsHorizontalIcon, 
  DocumentTextIcon,
  CalendarDaysIcon, 
  GlobeAltIcon,
  BookOpenIcon
} from '@heroicons/vue/24/outline';
import { 
  HomeIcon,
  ChevronRightIcon 
} from '@heroicons/vue/20/solid';

const props = defineProps({
  documents: Object,
  languages: Array,
  years: Array,
  filters: Object,
});

// Локальное состояние для хранения документов
const localDocuments = ref([...props.documents.data]);
const hasMorePages = ref(props.documents.next_page_url !== null);
const loadingMore = ref(false);
const loadMoreRef = ref(null);

// Состояние фильтров - объявляем ДО watchers
const searchQuery = ref(props.filters.search || '');
const showFilters = ref(false);
const initialSortValue = props.filters.sort && props.filters.direction 
  ? `${props.filters.sort}_${props.filters.direction}` 
  : 'publication_date_desc';
console.log('Initial sort value:', initialSortValue, 'from filters:', props.filters);
const sortOption = ref(initialSortValue);

// Перезагрузка списка при изменении пропсов (фильтров)
watch(() => props.documents, (newDocuments) => {
  // Сохраняем текущие документы и добавляем новые, если это загрузка дополнительной страницы
  if (newDocuments.current_page > 1) {
    localDocuments.value = [...localDocuments.value, ...newDocuments.data];
  } else {
    // Если это первая страница или смена фильтров, полностью заменяем список
    localDocuments.value = [...newDocuments.data];
  }
  hasMorePages.value = newDocuments.next_page_url !== null;
}, { deep: true });

// Наблюдение за изменением фильтров для обновления локального состояния
watch(() => props.filters, (newFilters) => {
  console.log('props.filters changed:', newFilters);
  
  // Обновляем локальное состояние в соответствии с фильтрами
  searchQuery.value = newFilters.search || '';
  
  // Обновляем состояние сортировки
  if (newFilters.sort && newFilters.direction) {
    const newSortValue = `${newFilters.sort}_${newFilters.direction}`;
    console.log('Updating sortOption from filters:', newSortValue, 'from raw values:', { sort: newFilters.sort, direction: newFilters.direction });
    sortOption.value = newSortValue;
  }
}, { deep: true, immediate: true });

// Отслеживаем изменения sortOption и автоматически применяем сортировку
watch(sortOption, (newValue, oldValue) => {
  console.log('sortOption changed from', oldValue, 'to', newValue);
  
  // Не применяем сортировку при первой инициализации
  if (oldValue === undefined) return;
  
  // Правильно разбиваем значение сортировки
  const parts = newValue.split('_');
  let sort, direction;
  
  if (parts.length >= 3) {
    // Для publication_date_asc - берем первые две части как sort, последнюю как direction
    direction = parts[parts.length - 1];
    sort = parts.slice(0, -1).join('_');
  } else {
    // Для простых значений типа title_asc
    [sort, direction] = parts;
  }
  
  console.log('Auto-applying sort:', { sort, direction, originalValue: newValue });
  
  // Применяем сортировку через query-параметры
  router.get(route('documents.index'), {
    search: searchQuery.value,
    sort: sort,
    direction: direction,
    language: props.filters.language,
    year: props.filters.year,
  }, {
    preserveState: true,
    preserveScroll: false,
    replace: true
  });
});

// Функция для загрузки следующей страницы
const loadMoreDocuments = () => {
  if (!hasMorePages.value || loadingMore.value) return;

  loadingMore.value = true;
  
  // Используем текущие параметры фильтров из props, а не из sortOption
  const sort = props.filters.sort || 'publication_date';
  const direction = props.filters.direction || 'desc';
  
  console.log('loadMoreDocuments with sort params:', { sort, direction });
  
  // Формируем параметры запроса на основе текущих фильтров
  const params = {
    search: searchQuery.value || props.filters.search,
    sort: sort,
    direction: direction,
    page: props.documents.current_page + 1
  };
  
  // Добавляем дополнительные параметры фильтрации, если они есть
  if (props.filters.language) {
    params.language = props.filters.language;
  }
  
  if (props.filters.year) {
    params.year = props.filters.year;
  }
  
  // Используем базовый URL без параметров пагинации
  router.get(route('documents.index'), params, {
    preserveState: true,
    preserveScroll: true,
    only: ['documents'],
    onSuccess: (page) => {
      // Добавляем новые данные к существующим
      localDocuments.value = [...localDocuments.value, ...page.props.documents.data];
      hasMorePages.value = page.props.documents.next_page_url !== null;
      loadingMore.value = false;
    },
    onError: () => {
      loadingMore.value = false;
    }
  });
};

// Intersection Observer для автоматической подгрузки
onMounted(() => {
  const observer = new IntersectionObserver(
    (entries) => {
      if (entries[0].isIntersecting) {
        loadMoreDocuments();
      }
    },
    { threshold: 0.1 }
  );

  if (loadMoreRef.value) {
    observer.observe(loadMoreRef.value);
  }

  onUnmounted(() => {
    if (loadMoreRef.value) {
      observer.unobserve(loadMoreRef.value);
    }
  });
});

// Вычисляемые свойства
const documentCount = computed(() => props.documents.total || 0);

// Функция для склонения слова "документ" в зависимости от числа
const declensionDocuments = (count) => {
  // Массив окончаний для разных форм
  const declension = ['документ', 'документа', 'документов'];
  
  // Особые случаи для чисел от 11 до 19
  if (count % 100 >= 11 && count % 100 <= 19) {
    return declension[2];
  }
  
  // Для остальных чисел смотрим на последнюю цифру
  const lastDigit = count % 10;
  if (lastDigit === 1) {
    return declension[0];
  } else if (lastDigit >= 2 && lastDigit <= 4) {
    return declension[1];
  } else {
    return declension[2];
  }
};

// Функция для применения фильтров
const applyFilters = (newFilters) => {
  router.get(route('documents.index'), {
    search: searchQuery.value,
    language: newFilters.language,
    year: newFilters.year,
    sort: props.filters.sort,
    direction: props.filters.direction,
  }, {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      showFilters.value = false;
    }
  });
};



// Функция поиска
const performSearch = () => {
  // Используем текущую выбранную сортировку из sortOption
  const parts = (sortOption.value || 'publication_date_desc').split('_');
  let sort, direction;
  if (parts.length >= 3) {
    direction = parts[parts.length - 1];
    sort = parts.slice(0, -1).join('_');
  } else {
    [sort, direction] = parts;
  }
  router.get(route('documents.index'), {
    search: searchQuery.value,
    language: props.filters.language,
    year: props.filters.year,
    sort: sort,
    direction: direction,
  }, {
    preserveState: true,
    preserveScroll: false, // Сбрасываем scroll при новом поиске
    replace: true
  });
};

// Автопоиск без Enter (дебаунс, от 3 символов; очистка — сброс результата)
let searchDebounce = null;
watch(searchQuery, (newValue, oldValue) => {
  const serverSearch = props.filters.search || '';
  if (newValue === serverSearch) return;
  const shouldSearch = newValue.length >= 3 || (oldValue && oldValue.length >= 3 && newValue.length === 0);
  if (!shouldSearch) return;
  if (searchDebounce) clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    performSearch();
  }, 500);
});

// Статистика по языкам и годам
const languageStats = computed(() => {
  const stats = {};
  props.languages.forEach(lang => {
    stats[lang] = (stats[lang] || 0) + 1;
  });
  return Object.entries(stats).slice(0, 5);
});

const yearStats = computed(() => {
  return props.years.slice(0, 5);
});
</script>

<template>
  <Head title="Медицинская библиотека" />
  
  <MainLayout>
    <!-- Градиентный фон для всей страницы -->
    <div class="min-h-screen bg-gradient-to-br from-brandblue/[0.03] to-white/95 dark:from-brandblue/10 dark:to-gray-900">
      <!-- Отступ перед шапкой -->
      <div class="pt-8">
        <!-- Шапка страницы с фоном -->
        <div class="mx-auto max-w-[1440px] px-4 sm:px-6 lg:px-8">
          <div class="bg-brandblue py-12 text-white rounded-2xl">
            <div class="mx-auto px-6 sm:px-8 lg:px-10">
              <div class="grid grid-cols-1 gap-8 md:grid-cols-2">
                <!-- Левая колонка с заголовком и хлебными крошками -->
                <div>
                  <!-- Хлебные крошки -->
                  <nav class="flex text-sm text-white/80" aria-label="Хлебные крошки">
                    <ol class="flex items-center space-x-1">
                      <li>
                        <div class="flex items-center">
                          <Link href="/" class="hover:text-white">
                            <HomeIcon class="h-4 w-4 flex-shrink-0" aria-hidden="true" />
                            <span class="sr-only">Главная</span>
                          </Link>
                        </div>
                      </li>
                      <li>
                        <div class="flex items-center">
                          <ChevronRightIcon class="h-5 w-5 flex-shrink-0 text-white/60" aria-hidden="true" />
                          <span class="ml-1 font-medium text-white" aria-current="page">Медицинская библиотека</span>
                        </div>
                      </li>
                    </ol>
                  </nav>
                  
                  <h1 class="mt-4 text-3xl font-bold tracking-tight text-white sm:text-4xl">Медицинская библиотека</h1>
                  <p class="mt-4 text-lg text-white/90">
                    Коллекция медицинских документов, исследований, руководств и справочных материалов для специалистов здравоохранения.
                  </p>
                </div>
                
                <!-- Правая колонка со статистикой -->
                <div class="flex items-center justify-center md:justify-end">
                  <div class="flex items-center space-x-8">
                    <!-- Статистика по документам -->
                    <div class="text-center">
                      <div class="flex items-center justify-center w-16 h-16 bg-white/10 rounded-full mb-2">
                        <DocumentTextIcon class="w-8 h-8 text-white" />
                      </div>
                      <div class="text-2xl font-bold text-white">{{ documentCount }}</div>
                      <div class="text-sm text-white/80">{{ declensionDocuments(documentCount) }}</div>
                    </div>
                    
                    <!-- Статистика по языкам -->
                    <div class="text-center" v-if="languages.length > 0">
                      <div class="flex items-center justify-center w-16 h-16 bg-white/10 rounded-full mb-2">
                        <GlobeAltIcon class="w-8 h-8 text-white" />
                      </div>
                      <div class="text-2xl font-bold text-white">{{ languages.length }}</div>
                      <div class="text-sm text-white/80">{{ languages.length === 1 ? 'язык' : 'языка' }}</div>
                    </div>
                    
                    <!-- Статистика по годам 
                    <div class="text-center" v-if="years.length > 0">
                      <div class="flex items-center justify-center w-16 h-16 bg-white/10 rounded-full mb-2">
                        <CalendarDaysIcon class="w-8 h-8 text-white" />
                      </div>
                      <div class="text-2xl font-bold text-white">{{ years.length }}</div>
                      <div class="text-sm text-white/80">{{ years.length === 1 ? 'год' : 'лет' }}</div>
                    </div>
                  -->
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Основное содержимое -->
      <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-6 lg:px-8">
        <!-- Фильтры и поиск -->
        <div class="mb-8">
          <div class="flex flex-col space-y-4 md:flex-row md:items-center md:space-y-0 md:space-x-4">
            <!-- Поисковая строка -->
            <div class="relative flex-grow">
              <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                <MagnifyingGlassIcon class="h-5 w-5 text-gray-400" aria-hidden="true" />
              </div>
              <input 
                v-model="searchQuery" 
                type="text" 
                class="block w-full rounded-full border-0 py-3 pl-10 pr-4 text-gray-900 bg-white ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-brandblue dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-brandblue h-12"
                placeholder="Поиск документов..."
              />
            </div>
            
            <!-- Кнопка фильтров -->
            <div class="flex items-center space-x-2 flex-shrink-0">
              <button 
                @click="showFilters = !showFilters" 
                class="inline-flex items-center rounded-full bg-white px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 ring-1 ring-inset ring-gray-300 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 dark:ring-gray-700 h-12"
              >
                <AdjustmentsHorizontalIcon class="mr-1.5 h-5 w-5 text-gray-500 dark:text-gray-400" />
                Фильтры
              </button>
            </div>
          </div>
        </div>
        
        <!-- Количество результатов и сортировка -->
        <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between">
          <p class="text-sm text-gray-600 dark:text-gray-400">
            Найдено <span class="font-medium text-gray-900 dark:text-white">{{ documentCount }}</span> {{ declensionDocuments(documentCount) }}
          </p>
          <div class="mt-2 sm:mt-0">
            <select 
              v-model="sortOption"
              class="rounded-md border-gray-300 py-2 pl-3 pr-10 text-sm text-gray-900 focus:border-brandblue focus:outline-none focus:ring-brandblue dark:border-gray-700 dark:bg-gray-800 dark:text-white"
            >
              <option value="publication_date_desc">По дате (сначала новые)</option>
              <option value="publication_date_asc">По дате (сначала старые)</option>
              <option value="title_asc">По названию (А-Я)</option>
              <option value="title_desc">По названию (Я-А)</option>
            </select>
          </div>
        </div>
        
        <!-- Сетка документов -->
        <div class="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-3">
          <DocumentCard v-for="document in localDocuments" :key="document.id" :document="document" />
        </div>
        
        <!-- Элемент для отслеживания прокрутки -->
        <div ref="loadMoreRef" class="h-10"></div>
        
        <!-- Индикатор загрузки -->
        <div v-if="loadingMore" class="mt-8 flex justify-center">
            <svg class="animate-spin h-8 w-8 text-brandblue" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        </div>

        <!-- Сообщение, когда все загружено -->
        <div v-if="!hasMorePages && !loadingMore && localDocuments.length > 0" class="mt-8 text-center text-gray-500 dark:text-gray-400">
          Вы просмотрели все документы.
        </div>

        <!-- Сообщение, когда нет результатов -->
        <div v-if="localDocuments.length === 0 && !loadingMore" class="mt-12 text-center">
          <BookOpenIcon class="mx-auto h-12 w-12 text-gray-400" />
          <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">Документы не найдены</h3>
          <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Попробуйте изменить параметры поиска или фильтрации.</p>
        </div>
      </div>

      <!-- Панель фильтров справа -->
      <DocumentFilters
        :show="showFilters"
        :languages="languages"
        :years="years"
        :filters="filters"
        @close="showFilters = false"
        @apply="applyFilters"
      />
    </div>
  </MainLayout>
</template> 