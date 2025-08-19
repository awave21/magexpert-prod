<template>
  <AdminLayout>
    <template #header>
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">Медицинская библиотека</h1>
      </div>
      
      <!-- Заголовок и кнопки действий -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <h2 class="text-xl font-semibold text-zinc-800 dark:text-white">
          Список материалов
        </h2>
        
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 sm:gap-4">
          <button
            @click="showCreateLibraryModal = true"
            class="w-full sm:w-auto inline-flex items-center justify-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100"
          >
            <PlusIcon class="mr-2 size-4" />
            Добавить материал
          </button>
          
          <div class="w-full sm:w-40">
            <SelectList v-model="languageFilter" :options="languageOptions" placeholder="Все языки" @change="applyFilters" />
          </div>

          <div class="relative w-full sm:w-64">
            <input v-model="searchQuery" type="text" placeholder="Поиск материалов..." class="w-full rounded-lg border border-zinc-300 bg-white pl-10 pr-4 py-2 text-sm text-zinc-900 placeholder-zinc-500 transition-colors duration-200 ease-in-out hover:border-zinc-400 focus:border-zinc-500 focus:outline-none focus:ring-2 focus:ring-zinc-500/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-400 dark:hover:border-zinc-600" @input="debouncedSearch" />
            <MagnifyingGlassIcon class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
          </div>
        </div>
      </div>
    </template>

    <!-- Скелетон загрузки -->
    <div v-if="loading" class="space-y-4">
      <div v-for="i in 5" :key="i" class="animate-pulse">
        <div class="h-20 rounded-lg bg-zinc-100 dark:bg-zinc-800"></div>
      </div>
    </div>
    
    <!-- Таблица материалов библиотеки -->
    <div v-else>
      <div v-if="library && library.data.length > 0" class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm transition-all duration-200 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
            <thead class="bg-zinc-50 dark:bg-zinc-800/50">
              <tr>
                <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Материал</th>
                <th scope="col" class="hidden lg:table-cell px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Дата публикации</th>
                <th scope="col" class="hidden xl:table-cell px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Язык</th>
                <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Действия</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
              <tr v-for="item in library.data" :key="`library-${item.id}`" class="group transition-colors duration-150 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                <td class="whitespace-nowrap px-4 sm:px-6 py-4">
                  <div class="flex items-center">
                    <div class="size-12 sm:size-16 flex-shrink-0 overflow-hidden rounded-lg">
                      <img v-if="item.image_url" :src="item.image_url" :alt="item.title" class="size-full object-cover" />
                      <div v-else class="size-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center text-zinc-500 dark:text-zinc-400">
                        <DocumentIcon class="size-8" />
                      </div>
                    </div>
                    <div class="ml-3 sm:ml-4">
                      <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ item.title }}</div>
                      <div class="text-xs text-zinc-500 dark:text-zinc-400 line-clamp-1">{{ item.description }}</div>
                    </div>
                  </div>
                </td>
                <td class="hidden lg:table-cell whitespace-nowrap px-4 sm:px-6 py-4">
                  <div class="text-sm text-zinc-900 dark:text-white">{{ formatDate(item.publication_date) }}</div>
                </td>
                <td class="hidden xl:table-cell whitespace-nowrap px-4 sm:px-6 py-4">
                  <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-zinc-100 text-zinc-800 dark:bg-zinc-900/30 dark:text-zinc-300">
                    {{ getLanguageName(item.language) }}
                  </span>
                </td>
                <td class="whitespace-nowrap px-4 sm:px-6 py-4 text-sm font-medium">
                  <div class="flex items-center gap-2">
                    <a v-if="item.file_url" :href="item.file_url" target="_blank" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-blue-600 transition-colors duration-150 hover:bg-blue-50 hover:text-blue-900 dark:text-blue-400 dark:hover:bg-blue-900/20 dark:hover:text-blue-300">
                      <ArrowDownTrayIcon class="size-4" />
                      <span class="hidden sm:inline">Скачать</span>
                    </a>
                    <button @click="editLibraryItem(item)" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-zinc-600 transition-colors duration-150 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white">
                      <PencilIcon class="size-4" />
                      <span class="hidden sm:inline">Редактировать</span>
                    </button>
                    <button @click="confirmDeleteLibraryItem(item)" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-red-600 transition-colors duration-150 hover:bg-red-50 hover:text-red-900 dark:text-red-400 dark:hover:bg-red-900/20 dark:hover:text-red-300">
                      <TrashIcon class="size-4" />
                      <span class="hidden sm:inline">Удалить</span>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div v-else class="mt-6 flex flex-col items-center justify-center rounded-lg border border-zinc-200 bg-white py-12 text-center dark:border-zinc-800 dark:bg-zinc-900">
        <DocumentTextIcon class="mb-4 size-16 text-zinc-300 dark:text-zinc-600" />
        <h3 class="text-xl font-medium text-zinc-900 dark:text-white">Материалы не найдены</h3>
        <p class="mt-2 text-zinc-600 dark:text-zinc-400">{{ getNoResultsMessage() }}</p>
        <button v-if="hasActiveFilters()" @click="resetFilters" class="mt-4 inline-flex items-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100">
          <XCircleIcon class="mr-2 size-4" />
          Сбросить фильтры
        </button>
      </div>
    </div>

    <!-- Пагинация -->
    <div v-if="library && library.data.length > 0" class="mt-6">
      <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex flex-col sm:flex-row items-center gap-3 sm:gap-4 w-full sm:w-auto">
          <div class="text-sm text-zinc-700 dark:text-zinc-300 text-center sm:text-left">
            <span v-if="library.total > 0">Показано {{ library.from }}-{{ library.to }} из {{ library.total }} материалов</span>
            <span v-else>Всего 0 материалов</span>
          </div>
          <div class="flex items-center text-sm text-zinc-700 dark:text-zinc-300">
            <span class="mr-2">Показывать:</span>
            <div class="w-20"><SelectList v-model="perPage" :options="perPageOptions" @change="changePerPage" /></div>
          </div>
        </div>
        <div class="w-full sm:w-auto"><Pagination :links="library.links" :show-first-last-buttons="true" :max-visible-pages="5" /></div>
      </div>
    </div>

    <!-- Модальные окна -->
    <CreateMedicalLibraryModal
      :show="showCreateLibraryModal"
      :library="selectedLibraryItem"
      @close="closeCreateLibraryModal"
      @created="handleLibraryItemCreated"
      @updated="handleLibraryItemUpdated"
    />
    
    <ConfirmModal 
      :show="showDeleteModal" 
      :title="deleteModalTitle" 
      :message="deleteModalMessage" 
      confirm-text="Удалить" 
      cancel-text="Отмена" 
      confirm-button-class="bg-red-600 hover:bg-red-700 text-white" 
      @confirm="deleteConfirmed" 
      @close="showDeleteModal = false" 
    />
  </AdminLayout>
</template>

<script setup>
import { ref, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useToast } from 'vue-toastification';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SelectList from '@/Components/SelectList.vue';
import Pagination from '@/Components/Pagination.vue';
import CreateMedicalLibraryModal from '@/Components/Modal/CreateMedicalLibraryModal.vue';
import ConfirmModal from '@/Components/Modal/ConfirmModal.vue';
import debounce from 'lodash/debounce';
import { 
  PlusIcon, 
  MagnifyingGlassIcon, 
  DocumentIcon,
  DocumentTextIcon,
  PencilIcon, 
  TrashIcon,
  XCircleIcon,
  ArrowDownTrayIcon
} from '@heroicons/vue/24/outline';

const toast = useToast();

const props = defineProps({
  library: Object,
  filters: Object,
  canManageLibrary: Boolean,
});

// Общее состояние
const loading = ref(false);
const showDeleteModal = ref(false);
const itemToDelete = ref(null);
const deleteModalTitle = ref('');
const deleteModalMessage = ref('');

// Состояние для библиотеки
const searchQuery = ref(props.filters?.search || '');
const languageFilter = ref(props.filters?.language || '');
const perPage = ref(props.filters?.per_page || 10);
const showCreateLibraryModal = ref(false);
const selectedLibraryItem = ref(null);

// Варианты для выпадающих списков
const perPageOptions = [
  { value: 5, label: '5' },
  { value: 10, label: '10' },
  { value: 25, label: '25' },
  { value: 50, label: '50' },
];

const languageOptions = [
  { value: '', label: 'Все языки' },
  { value: 'ru', label: 'Русский' },
  { value: 'en', label: 'Английский' },
];

// Получение названия языка по его коду
const getLanguageName = (code) => {
  const option = languageOptions.find(opt => opt.value === code);
  return option ? option.label : code;
};

// Форматирование даты
const formatDate = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleDateString('ru-RU', { 
    year: 'numeric', 
    month: 'long', 
    day: 'numeric' 
  });
};

// Фильтрация и поиск для библиотеки
const debouncedSearch = debounce(() => applyFilters(), 300);

const applyFilters = () => {
  loading.value = true;
  router.get(route('admin.medical-library'), { 
    search: searchQuery.value, 
    language: languageFilter.value, 
    per_page: perPage.value, 
    page: 1 
  }, { 
    preserveState: true, 
    preserveScroll: true, 
    onFinish: () => { loading.value = false; }
  });
};

const hasActiveFilters = () => languageFilter.value !== '' || searchQuery.value !== '';

const resetFilters = () => {
  searchQuery.value = ''; 
  languageFilter.value = '';
  applyFilters();
};

const getNoResultsMessage = () => hasActiveFilters() 
  ? 'По заданным фильтрам материалов не найдено.' 
  : 'Материалов пока нет.';

const changePerPage = () => applyFilters();

// CRUD для библиотеки
const closeCreateLibraryModal = () => { 
  showCreateLibraryModal.value = false; 
  selectedLibraryItem.value = null; 
};

const editLibraryItem = (item) => { 
  selectedLibraryItem.value = item; 
  showCreateLibraryModal.value = true; 
};

const handleLibraryItemCreated = () => { 
  router.reload({ only: ['library'] }); 
  toast.success('Материал успешно добавлен'); 
};

const handleLibraryItemUpdated = () => { 
  router.reload({ only: ['library'] }); 
  toast.success('Материал успешно обновлен'); 
};

const confirmDeleteLibraryItem = (item) => {
  itemToDelete.value = item.id;
  deleteModalTitle.value = 'Удаление материала';
  deleteModalMessage.value = `Вы действительно хотите удалить материал «${item.title}»?`;
  showDeleteModal.value = true;
};

// Удаление материала
const deleteConfirmed = () => {
  if (!itemToDelete.value) return;
  
  router.delete(route('admin.medical-library.destroy', itemToDelete.value), {
    onSuccess: () => {
      toast.success('Материал успешно удален');
      showDeleteModal.value = false;
      itemToDelete.value = null;
    },
    onError: (errors) => {
      const errorMsg = errors.error || 'Ошибка при удалении материала';
      toast.error(errorMsg);
      showDeleteModal.value = false;
    }
  });
};

// Отслеживание flash-сообщений
watch(() => props.library, () => {
  const flash = router.page.props.flash;
  if (flash?.success) toast.success(flash.success);
  if (flash?.error) toast.error(flash.error);
}, { deep: true, immediate: true });
</script> 