<template>
  <AdminLayout>
    <template #header>
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">Спикеры</h1>
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 sm:gap-4">
          <!-- Кнопка создания спикера -->
          <button
            v-if="canManageSpeakers"
            @click="showCreateModal = true"
            class="w-full sm:w-auto inline-flex items-center justify-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="mr-2 size-4">
              <path d="M6.25 6.375a4.125 4.125 0 1 1 8.25 0 4.125 4.125 0 0 1-8.25 0ZM3.25 19.125a7.125 7.125 0 0 1 14.25 0v.003l-.001.119a.766.766 0 0 1-.752.743H4.003a.766.766 0 0 1-.752-.743l-.001-.122Z" />
              <path d="M19.75 7.5a.75.75 0 0 0-1.5 0v2.25H16a.75.75 0 0 0 0 1.5h2.25v2.25a.75.75 0 0 0 1.5 0v-2.25H22a.75.75 0 0 0 0-1.5h-2.25V7.5Z" />
            </svg>
            Создать спикера
          </button>
          
          <!-- Фильтр по статусу -->
          <div class="w-full sm:w-40">
            <SelectList
              v-model="statusFilter"
              :options="statusOptions"
              placeholder="Все спикеры"
              @change="applyFilters"
            />
          </div>
          
          <!-- Поиск -->
          <div class="relative w-full sm:w-64">
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Поиск спикеров..."
              class="w-full rounded-lg border border-zinc-300 bg-white pl-10 pr-4 py-2 text-sm text-zinc-900 placeholder-zinc-500 transition-colors duration-200 ease-in-out hover:border-zinc-400 focus:border-zinc-500 focus:outline-none focus:ring-2 focus:ring-zinc-500/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-400 dark:hover:border-zinc-600"
              @input="debouncedSearch"
            />
            <svg
              class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400"
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 24 24"
              fill="currentColor"
            >
              <path fill-rule="evenodd" d="M10.5 3.75a6.75 6.75 0 1 0 0 13.5 6.75 6.75 0 0 0 0-13.5ZM2.25 10.5a8.25 8.25 0 1 1 14.59 5.28l4.69 4.69a.75.75 0 1 1-1.06 1.06l-4.69-4.69A8.25 8.25 0 0 1 2.25 10.5Z" clip-rule="evenodd" />
            </svg>
          </div>
        </div>
      </div>
    </template>

    <!-- Скелетон загрузки -->
    <div v-if="loading" class="space-y-4">
      <div v-for="i in 5" :key="i" class="animate-pulse">
        <div class="h-16 rounded-lg bg-zinc-100 dark:bg-zinc-800"></div>
      </div>
    </div>

    <!-- Таблица спикеров -->
    <div v-else-if="speakers.data.length > 0" class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm transition-all duration-200 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
          <thead class="bg-zinc-50 dark:bg-zinc-800/50">
            <tr>
              <th
                scope="col"
                class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400"
              >
                Спикер
              </th>
              <th
                scope="col"
                class="hidden lg:table-cell px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400"
              >
                Должность/Компания
              </th>
              <th
                scope="col"
                class="hidden xl:table-cell px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400"
              >
                Статус
              </th>
              <th
                scope="col"
                class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400"
              >
                Действия
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
            <tr v-for="speaker in speakers.data" :key="speaker.id" class="group transition-colors duration-150 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
              <td class="whitespace-nowrap px-4 sm:px-6 py-4">
                <div class="flex items-center">
                  <div class="size-8 sm:size-10 flex-shrink-0 overflow-hidden rounded-lg">
                    <img v-if="speaker.photo" :src="speaker.photo" :alt="speaker.full_name" class="size-full object-cover" />
                    <div v-else class="size-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center text-zinc-500 dark:text-zinc-400">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-6">
                        <path fill-rule="evenodd" d="M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 0 1-.437-.695Z" clip-rule="evenodd" />
                      </svg>
                    </div>
                  </div>
                  <div class="ml-3 sm:ml-4">
                    <div class="text-sm font-medium text-zinc-900 dark:text-white">
                      {{ speaker.full_name }}
                    </div>
                    <div class="lg:hidden text-xs text-zinc-500 dark:text-zinc-400">
                      <span v-if="speaker.position">{{ speaker.position }}</span>
                      <span v-if="speaker.position && speaker.company">, </span>
                      <span v-if="speaker.company">{{ speaker.company }}</span>
                    </div>
                  </div>
                </div>
              </td>
              <td class="hidden lg:table-cell whitespace-nowrap px-4 sm:px-6 py-4">
                <div class="text-sm text-zinc-900 dark:text-white">
                  <div v-if="speaker.position">{{ speaker.position }}</div>
                  <div v-if="speaker.company" class="text-xs text-zinc-500 dark:text-zinc-400">{{ speaker.company }}</div>
                </div>
              </td>
              <td class="hidden xl:table-cell whitespace-nowrap px-4 sm:px-6 py-4">
                <span
                  class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                  :class="{
                    'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300': speaker.is_active,
                    'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300': !speaker.is_active,
                  }"
                >
                  {{ speaker.is_active ? 'Активный' : 'Неактивный' }}
                </span>
              </td>
              <td class="whitespace-nowrap px-4 sm:px-6 py-4 text-sm font-medium">
                <div class="flex items-center gap-2">
                  <!-- Кнопка редактирования -->
                  <button
                    @click="editSpeaker(speaker)"
                    class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-zinc-600 transition-colors duration-150 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-4">
                      <path d="M21.731 2.269a2.625 2.625 0 0 0-3.712 0l-1.157 1.157 3.712 3.712 1.157-1.157a2.625 2.625 0 0 0 0-3.712ZM19.513 8.199l-3.712-3.712-8.4 8.4a5.25 5.25 0 0 0-1.32 2.214l-.8 2.685a.75.75 0 0 0 .933.933l2.685-.8a5.25 5.25 0 0 0 2.214-1.32l8.4-8.4Z" />
                      <path d="M5.25 5.25a3 3 0 0 0-3 3v10.5a3 3 0 0 0 3 3h10.5a3 3 0 0 0 3-3V13.5a.75.75 0 0 0-1.5 0v5.25a1.5 1.5 0 0 1-1.5 1.5H5.25a1.5 1.5 0 0 1-1.5-1.5V8.25a1.5 1.5 0 0 1 1.5-1.5h5.25a.75.75 0 0 0 0-1.5H5.25Z" />
                    </svg>
                    <span class="hidden sm:inline">Редактировать</span>
                  </button>
                  
                  <!-- Кнопка удаления -->
                  <button
                    @click="confirmDeleteSpeaker(speaker)"
                    class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-red-600 transition-colors duration-150 hover:bg-red-50 hover:text-red-900 dark:text-red-400 dark:hover:bg-red-900/20 dark:hover:text-red-300"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-4">
                      <path fill-rule="evenodd" d="M16.5 4.478v.227a48.816 48.816 0 0 1 3.878.512.75.75 0 1 1-.256 1.478l-.209-.035-1.005 13.07a3 3 0 0 1-2.991 2.77H8.084a3 3 0 0 1-2.991-2.77L4.087 6.66l-.209.035a.75.75 0 0 1-.256-1.478A48.567 48.567 0 0 1 7.5 4.705v-.227c0-1.564 1.213-2.9 2.816-2.951a52.662 52.662 0 0 1 3.369 0c1.603.051 2.815 1.387 2.815 2.951Zm-6.136-1.452a51.196 51.196 0 0 1 3.273 0C14.39 3.05 15 3.684 15 4.478v.113a49.488 49.488 0 0 0-6 0v-.113c0-.794.609-1.428 1.364-1.452Zm-.355 5.945a.75.75 0 1 0-1.5.058l.347 9a.75.75 0 1 0 1.499-.058l-.346-9Zm5.48.058a.75.75 0 1 0-1.498-.058l-.347 9a.75.75 0 0 0 1.5.058l.345-9Z" clip-rule="evenodd" />
                    </svg>
                    <span class="hidden sm:inline">Удалить</span>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Отсутствие результатов -->
    <div v-else class="mt-6 flex flex-col items-center justify-center rounded-lg border border-zinc-200 bg-white py-12 text-center dark:border-zinc-800 dark:bg-zinc-900">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="mb-4 size-16 text-zinc-300 dark:text-zinc-600">
        <path d="M4.5 6.375a4.125 4.125 0 1 1 8.25 0 4.125 4.125 0 0 1-8.25 0ZM12 8.625a3.375 3.375 0 1 1 6.75 0 3.375 3.375 0 0 1-6.75 0ZM1.5 19.125a7.125 7.125 0 0 1 14.25 0v.003l-.001.119a.75.75 0 0 1-.363.63 13.067 13.067 0 0 1-6.761 1.873c-2.472 0-4.786-.684-6.76-1.873a.75.75 0 0 1-.364-.63l-.001-.122ZM12.75 18.75a49.38 49.38 0 0 0-1.5 0 6.375 6.375 0 0 1 13.5 0v.003l-.001.119a.75.75 0 0 1-.363.63 12.94 12.94 0 0 1-5.043 1.612.75.75 0 0 1-.854-.749 6.375 6.375 0 0 0-5.739-6.364Z" />
      </svg>
      <h3 class="text-xl font-medium text-zinc-900 dark:text-white">Спикеры не найдены</h3>
      <p class="mt-2 text-zinc-600 dark:text-zinc-400">
        {{ getNoResultsMessage() }}
      </p>
      <button 
        v-if="hasActiveFilters()" 
        @click="resetFilters" 
        class="mt-4 inline-flex items-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100"
      >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="mr-2 size-4">
          <path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25Zm-1.72 6.97a.75.75 0 1 0-1.06 1.06L10.94 12l-1.72 1.72a.75.75 0 1 0 1.06 1.06L12 13.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L13.06 12l1.72-1.72a.75.75 0 1 0-1.06-1.06L12 10.94l-1.72-1.72Z" clip-rule="evenodd" />
        </svg>
        Сбросить фильтры
      </button>
    </div>

    <!-- Пагинация -->
    <div v-if="speakers.data.length > 0" class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4">
      <div class="flex flex-col sm:flex-row items-center gap-3 sm:gap-4 w-full sm:w-auto">
        <div class="text-sm text-zinc-700 dark:text-zinc-300 text-center sm:text-left">
          <span v-if="speakers.total > 0">
            Показано {{ speakers.from }}-{{ speakers.to }} из {{ speakers.total }} спикеров
          </span>
          <span v-else>Всего 0 спикеров</span>
        </div>
        
        <!-- Выбор количества на странице -->
        <div class="flex items-center text-sm text-zinc-700 dark:text-zinc-300">
          <span class="mr-2">Показывать:</span>
          <div class="w-20">
            <SelectList
              v-model="perPage"
              :options="perPageOptions"
              @change="changePerPage"
            />
          </div>
        </div>
      </div>
      
      <!-- Компонент пагинации -->
      <div class="w-full sm:w-auto">
        <Pagination 
          :links="speakers.links" 
          :show-first-last-buttons="true"
          :max-visible-pages="5"
        />
      </div>
    </div>

    <!-- Модальное окно создания/редактирования спикера -->
    <CreateSpeakerModal
      :show="showCreateModal"
      :speaker="selectedSpeaker"
      @close="closeCreateModal"
      @created="handleSpeakerCreated"
      @updated="handleSpeakerUpdated"
    />
    
    <!-- Модальное окно подтверждения удаления -->
    <ConfirmModal
      :show="showDeleteModal"
      title="Удаление спикера"
      :message="'Вы действительно хотите удалить спикера ' + (speakerToDelete?.full_name || '') + '?'"
      confirm-text="Удалить"
      cancel-text="Отмена"
      confirm-button-class="bg-red-600 hover:bg-red-700 text-white"
      @confirm="deleteSpeaker"
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
import CreateSpeakerModal from '@/Components/Modal/CreateSpeakerModal.vue';
import ConfirmModal from '@/Components/Modal/ConfirmModal.vue';
import debounce from 'lodash/debounce';

const toast = useToast();

const props = defineProps({
  speakers: Object,
  filters: Object,
  canManageSpeakers: Boolean,
});

// Состояние
const searchQuery = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');
const perPage = ref(props.filters.per_page || 10);
const loading = ref(false);
const showCreateModal = ref(false);
const showDeleteModal = ref(false);
const selectedSpeaker = ref(null);
const speakerToDelete = ref(null);

// Варианты для выпадающих списков
const perPageOptions = [
  { value: 5, label: '5' },
  { value: 10, label: '10' },
  { value: 25, label: '25' },
  { value: 50, label: '50' },
];

const statusOptions = [
  { value: '', label: 'Все спикеры' },
  { value: 'active', label: 'Активные' },
  { value: 'inactive', label: 'Неактивные' },
];

// Методы
const formatDate = (date) => {
  return new Date(date).toLocaleDateString('ru-RU', {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  });
};

const debouncedSearch = debounce(() => {
  applyFilters();
}, 300);

const applyFilters = () => {
  loading.value = true;
  router.get(
    route('admin.speakers'),
    { 
      search: searchQuery.value,
      status: statusFilter.value,
      per_page: perPage.value,
      page: 1
    },
    {
      preserveState: true,
      preserveScroll: true,
      onFinish: () => {
        loading.value = false;
      }
    }
  );
};

const hasActiveFilters = () => {
  return statusFilter.value !== '' || searchQuery.value !== '';
};

const resetFilters = () => {
  searchQuery.value = '';
  statusFilter.value = '';
  applyFilters();
};

const getNoResultsMessage = () => {
  if (searchQuery.value && statusFilter.value) {
    const status = statusFilter.value === 'active' ? 'активных' : 'неактивных';
    return `По запросу "${searchQuery.value}" среди ${status} спикеров ничего не найдено`;
  } else if (searchQuery.value) {
    return `По запросу "${searchQuery.value}" ничего не найдено`;
  } else if (statusFilter.value) {
    const status = statusFilter.value === 'active' ? 'активных' : 'неактивных';
    return `${status.charAt(0).toUpperCase() + status.slice(1)} спикеров не найдено`;
  } else {
    return 'В системе еще нет зарегистрированных спикеров';
  }
};

// Изменение количества записей на странице
const changePerPage = () => {
  applyFilters();
};

// Обработка создания и редактирования спикеров
const closeCreateModal = () => {
  showCreateModal.value = false;
  selectedSpeaker.value = null;
};

const editSpeaker = (speaker) => {
  selectedSpeaker.value = speaker;
  showCreateModal.value = true;
};

const handleSpeakerCreated = () => {
  router.reload({ only: ['speakers'] });
};

const handleSpeakerUpdated = () => {
  router.reload({ only: ['speakers'] });
};

// Удаление спикера
const confirmDeleteSpeaker = (speaker) => {
  speakerToDelete.value = speaker;
  showDeleteModal.value = true;
};

const deleteSpeaker = () => {
  router.delete(route('admin.speakers.destroy', speakerToDelete.value.id), {
    onSuccess: () => {
      toast.success('Спикер успешно удален');
      showDeleteModal.value = false;
      speakerToDelete.value = null;
    },
    onError: () => {
      toast.error('Ошибка при удалении спикера');
    }
  });
};

// Отслеживание flash-сообщений
watch(() => props.speakers, () => {
  const flash = router.page.props.flash;
  if (flash.message) {
    toast.success(flash.message);
  }
  if (flash.error) {
    toast.error(flash.error);
  }
}, { immediate: true });
</script> 