<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import PartnerCard from '@/Components/Exhibition/PartnerCard.vue';
import { 
  MagnifyingGlassIcon, 
  UserGroupIcon,
  BuildingOffice2Icon
} from '@heroicons/vue/24/outline';
import { 
  HomeIcon,
  ChevronRightIcon 
} from '@heroicons/vue/20/solid';

const props = defineProps({
  partners: Object,
  partnersCount: Number,
  filters: Object,
});

// Локальное состояние для хранения партнеров
const localPartners = ref([...props.partners.data]);
const hasMorePages = ref(props.partners.next_page_url !== null);
const loadingMore = ref(false);
const loadMoreRef = ref(null);

// Перезагрузка списка при изменении пропсов (фильтров)
watch(() => props.partners, (newPartners) => {
  // Сохраняем текущих партнеров и добавляем новых, если это загрузка дополнительной страницы
  if (newPartners.current_page > 1) {
    localPartners.value = [...localPartners.value, ...newPartners.data];
  } else {
    // Если это первая страница или смена фильтров, полностью заменяем список
    localPartners.value = [...newPartners.data];
  }
  hasMorePages.value = newPartners.next_page_url !== null;
}, { deep: true });

// Наблюдение за изменением фильтров для обновления локального состояния
watch(() => props.filters, (newFilters) => {
  // Обновляем локальное состояние в соответствии с фильтрами
  searchQuery.value = newFilters.search || '';
  
  // Обновляем состояние сортировки
  if (newFilters.sort && newFilters.direction) {
    sortOption.value = `${newFilters.sort}_${newFilters.direction}`;
  }
}, { deep: true, immediate: true });

// Функция для загрузки следующей страницы
const loadMorePartners = () => {
  if (!hasMorePages.value || loadingMore.value) return;

  loadingMore.value = true;
  
  // Используем значение из реактивной переменной sortOption
  const [sort, direction] = sortOption.value.split('_');
  
  // Формируем параметры запроса на основе текущих фильтров
  const params = {
    search: searchQuery.value,
    sort: sort,
    direction: direction,
    page: props.partners.current_page + 1
  };
  
  // Используем базовый URL без параметров пагинации
  router.get(route('exhibition.index'), params, {
    preserveState: true,
    preserveScroll: true,
    only: ['partners'],
    onSuccess: (page) => {
      // Добавляем новые данные к существующим
      localPartners.value = [...localPartners.value, ...page.props.partners.data];
      hasMorePages.value = page.props.partners.next_page_url !== null;
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
        loadMorePartners();
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

// Состояние фильтров
const searchQuery = ref(props.filters.search || '');
const sortOption = ref(props.filters.sort && props.filters.direction 
  ? `${props.filters.sort}_${props.filters.direction}` 
  : 'name_asc');

// Вычисляемые свойства
const partnersTotal = computed(() => props.partners.total || 0);

// Функция для склонения слова "партнер" в зависимости от числа
const declensionPartners = (count) => {
  // Массив окончаний для разных форм
  const declension = ['партнер', 'партнера', 'партнеров'];
  
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

// Функция для поиска
const performSearch = () => {
  // Применяем поиск через query-параметры
  router.get(route('exhibition.index'), {
    search: searchQuery.value,
    sort: sortOption.value.split('_')[0],
    direction: sortOption.value.split('_')[1],
  }, {
    preserveState: true,
    preserveScroll: true
  });
};

// Функция для изменения сортировки
const changeSorting = (event) => {
  const value = event.target.value;
  sortOption.value = value;
  
  const [sort, direction] = value.split('_');
  
  // Применяем сортировку через query-параметры
  router.get(route('exhibition.index'), {
    search: searchQuery.value,
    sort: sort,
    direction: direction,
  }, {
    preserveState: true,
    preserveScroll: true
  });
};

// Обработчик нажатия Enter в поле поиска
const handleSearchKeyup = (event) => {
  if (event.key === 'Enter') {
    performSearch();
  }
};
</script>

<template>
  <Head title="Виртуальная выставка" />
  
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
                          <span class="ml-1 font-medium text-white" aria-current="page">Виртуальная выставка</span>
                        </div>
                      </li>
                    </ol>
                  </nav>
                  
                  <h1 class="mt-4 text-3xl font-bold tracking-tight text-white sm:text-4xl">Виртуальная выставка</h1>
                  <p class="mt-4 text-lg text-white/90">
                    Познакомьтесь с нашими партнерами - ведущими компаниями в области медицины и здравоохранения. Изучите их продукты и услуги.
                  </p>
                </div>
                
                <!-- Правая колонка с иконкой партнеров -->
                <div class="hidden md:flex items-center justify-center md:justify-end">
                  <div class="flex items-center">
                    <div class="flex items-center justify-center w-20 h-20 bg-white/10 rounded-full mr-4">
                      <BuildingOffice2Icon class="h-10 w-10 text-white" />
                    </div>
                    <!-- Количество партнеров
                      <div class="text-right">
                      <div class="text-3xl font-bold text-white">{{ partnersCount }}</div>
                      <div class="text-white/80">{{ declensionPartners(partnersCount) }}</div>
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
        <!-- Поиск и сортировка -->
        <div class="mb-8">
          <div class="flex flex-col space-y-4 md:flex-row md:items-center md:space-y-0 md:space-x-4">
            <!-- Поисковая строка -->
            <div class="relative flex-grow">
              <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                <MagnifyingGlassIcon class="h-5 w-5 text-gray-400" aria-hidden="true" />
              </div>
              <input 
                v-model="searchQuery" 
                @keyup="handleSearchKeyup"
                type="text" 
                class="block w-full rounded-full border-0 py-3 pl-10 pr-16 text-gray-900 bg-white ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-brandblue dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-brandblue h-12"
                placeholder="Поиск партнеров..."
              />
              <button
                @click="performSearch"
                class="absolute inset-y-0 right-0 flex items-center pr-3"
              >
                <span class="sr-only">Поиск</span>
                <MagnifyingGlassIcon class="h-5 w-5 text-gray-400 hover:text-gray-600 transition-colors" />
              </button>
            </div>
          </div>
        </div>
        
        <!-- Количество результатов и сортировка -->
        <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between">
          <p class="text-sm text-gray-600 dark:text-gray-400">
            Найдено <span class="font-medium text-gray-900 dark:text-white">{{ partnersTotal }}</span> {{ declensionPartners(partnersTotal) }}
          </p>
          <div class="mt-2 sm:mt-0">
            <select 
              v-model="sortOption"
              @change="changeSorting"
              class="rounded-md border-gray-300 py-2 pl-3 pr-10 text-sm text-gray-900 focus:border-brandblue focus:outline-none focus:ring-brandblue dark:border-gray-700 dark:bg-gray-800 dark:text-white"
            >
              <option value="name_asc">По названию (А-Я)</option>
              <option value="name_desc">По названию (Я-А)</option>
              <option value="created_at_desc">Новые сначала</option>
              <option value="created_at_asc">Старые сначала</option>
            </select>
          </div>
        </div>
        
        <!-- Сетка партнеров -->
        <div class="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-3">
          <PartnerCard v-for="partner in localPartners" :key="partner.id" :partner="partner" />
        </div>
        
        <!-- Сообщение если нет партнеров -->
        <div v-if="localPartners.length === 0 && !loadingMore" class="text-center py-12">
          <BuildingOffice2Icon class="mx-auto h-12 w-12 text-gray-400" />
          <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">Партнеры не найдены</h3>
          <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Попробуйте изменить критерии поиска.
          </p>
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
        <div v-if="!hasMorePages && !loadingMore && localPartners.length > 0" class="mt-8 text-center text-gray-500 dark:text-gray-400">
        </div>
      </div>
    </div>
  </MainLayout>
</template> 