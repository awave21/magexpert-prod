<template>
  <AdminLayout>
    <template #header>
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">Библиотека</h1>
          <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Статьи и материалы раздела «Библиотека» на сайте</p>
        </div>
        
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 sm:gap-4">
          <Link
            v-if="canManageLibrary"
            :href="route('admin.medical-library.create')"
            class="w-full sm:w-auto inline-flex items-center justify-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100"
          >
            <PlusIcon class="mr-2 size-4" />
            Добавить материал
          </Link>
          
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
                <th scope="col" class="px-4 sm:px-6 py-3"><span class="sr-only">Действия</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
              <tr v-for="item in library.data" :key="`library-${item.id}`" class="group cursor-pointer transition-colors duration-150 hover:bg-zinc-50 dark:hover:bg-zinc-800/50" @click="editLibraryItem(item)">
                <td class="px-4 sm:px-6 py-4">
                  <div class="flex items-center">
                    <div class="size-12 sm:size-16 flex-shrink-0 overflow-hidden rounded-lg">
                      <img v-if="item.image_url" :src="item.image_url" :alt="item.title" class="size-full object-cover" />
                      <div v-else class="size-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center text-zinc-500 dark:text-zinc-400">
                        <DocumentIcon class="size-8" />
                      </div>
                    </div>
                    <div class="ml-3 sm:ml-4">
                      <Link :href="route('admin.medical-library.edit', item.id)" class="block text-sm font-semibold text-zinc-900 group-hover:underline dark:text-white" @click.stop>{{ item.title }}</Link>
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
                  <div class="flex items-center justify-end gap-2">
                    <a v-if="item.file_url" :href="item.file_url" target="_blank" rel="noopener" :class="iconAction" title="Открыть файл" aria-label="Открыть файл" @click.stop>
                      <ArrowDownTrayIcon class="size-4" />
                    </a>
                    <button type="button" :class="iconDanger" title="Удалить" aria-label="Удалить материал" @click.stop="confirmDeleteLibraryItem(item)">
                      <TrashIcon class="size-4" />
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
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useToast } from 'vue-toastification';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SelectList from '@/Components/SelectList.vue';
import Pagination from '@/Components/Pagination.vue';
import { iconAction, iconDanger } from '@/Components/Admin/formClasses.js';
import ConfirmModal from '@/Components/Modal/ConfirmModal.vue';
import debounce from 'lodash/debounce';
import { 
  PlusIcon, 
  MagnifyingGlassIcon, 
  DocumentIcon,
  DocumentTextIcon,
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

// Открыть материал
const editLibraryItem = (item) => {
  router.visit(route('admin.medical-library.edit', item.id));
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
    preserveScroll: true,
    onSuccess: () => {
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

</script> 