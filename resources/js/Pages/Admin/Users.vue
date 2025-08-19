<template>
  <AdminLayout>
    <template #header>
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">Пользователи</h1>
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 sm:gap-4">
          <!-- Добавляем кнопку создания пользователя -->
          <button
            v-if="canManageUsers"
            @click="showCreateModal = true"
            class="w-full sm:w-auto inline-flex items-center justify-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="mr-2 size-4">
              <path d="M6.25 6.375a4.125 4.125 0 1 1 8.25 0 4.125 4.125 0 0 1-8.25 0ZM3.25 19.125a7.125 7.125 0 0 1 14.25 0v.003l-.001.119a.766.766 0 0 1-.752.743H4.003a.766.766 0 0 1-.752-.743l-.001-.122Z" />
              <path d="M19.75 7.5a.75.75 0 0 0-1.5 0v2.25H16a.75.75 0 0 0 0 1.5h2.25v2.25a.75.75 0 0 0 1.5 0v-2.25H22a.75.75 0 0 0 0-1.5h-2.25V7.5Z" />
            </svg>
            Создать пользователя
          </button>
          
          <!-- Улучшенный фильтр по ролям -->
          <div class="w-full sm:w-40">
            <SelectList
              v-model="roleFilter"
              :options="selectRoles"
              placeholder="Все роли"
              @change="applyFilters"
            />
          </div>
          
          <!-- Улучшенный поиск -->
          <div class="relative w-full sm:w-64">
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Поиск пользователей..."
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

    <!-- Таблица пользователей -->
    <div v-else-if="users.data.length > 0" class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm transition-all duration-200 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
          <thead class="bg-zinc-50 dark:bg-zinc-800/50">
            <tr>
              <th
                scope="col"
                class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400"
              >
                Пользователь
              </th>
              <th
                scope="col"
                class="hidden md:table-cell px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400"
              >
                Email
              </th>
              <th
                scope="col"
                class="hidden lg:table-cell px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400"
              >
                Роли
              </th>
              <th
                scope="col"
                class="hidden xl:table-cell px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400"
              >
                Дата регистрации
              </th>
              <th
                scope="col"
                class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400"
              >
                Профиль
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
            <tr v-for="user in users.data" :key="user.id" class="group transition-colors duration-150 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
              <td class="whitespace-nowrap px-4 sm:px-6 py-4">
                <div class="flex items-center">
                  <div class="size-8 sm:size-10 flex-shrink-0">
                    <UserAvatar 
                      :name="user.full_name || 'Пользователь'" 
                      :src="user.avatar"
                      size="md" 
                      shape="square" 
                    />
                  </div>
                  <div class="ml-3 sm:ml-4">
                    <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ user.full_name || 'Пользователь' }}</div>
                    <div class="md:hidden text-xs text-zinc-500 dark:text-zinc-400">{{ user.email }}</div>
                  </div>
                </div>
              </td>
              <td class="hidden md:table-cell whitespace-nowrap px-4 sm:px-6 py-4">
                <div class="text-sm text-zinc-900 dark:text-white">{{ user.email }}</div>
              </td>
              <td class="hidden lg:table-cell whitespace-nowrap px-4 sm:px-6 py-4">
                <div class="flex flex-wrap gap-1.5">
                  <span
                    v-for="role in user.roles"
                    :key="role.id"
                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors duration-150"
                    :class="{
                      'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300': role.name === 'admin',
                      'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300': role.name === 'manager',
                      'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300': role.name === 'editor',
                      'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200': role.name === 'user'
                    }"
                  >
                    {{ role.name }}
                  </span>
                  <span
                    v-if="!user.roles.length"
                    class="inline-flex items-center rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-medium text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200"
                  >
                    Пользователь
                  </span>
                </div>
              </td>
              <td class="hidden xl:table-cell whitespace-nowrap px-4 sm:px-6 py-4 text-sm text-zinc-500 dark:text-zinc-400">
                {{ formatDate(user.created_at) }}
              </td>
              <td class="whitespace-nowrap px-4 sm:px-6 py-4 text-sm font-medium">
                <Link
                  :href="route('admin.users.show', user.id)"
                  class="inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-zinc-600 transition-colors duration-150 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-4">
                    <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
                    <path fill-rule="evenodd" d="M1.323 11.447C2.811 6.976 7.028 3.75 12.001 3.75c4.97 0 9.185 3.223 10.675 7.69.12.362.12.752 0 1.113-1.487 4.471-5.705 7.697-10.677 7.697-4.97 0-9.186-3.223-10.675-7.69a1.762 1.762 0 0 1 0-1.113ZM17.25 12a5.25 5.25 0 1 1-10.5 0 5.25 5.25 0 0 1 10.5 0Z" clip-rule="evenodd" />
                  </svg>
                  <span class="hidden sm:inline">Просмотр</span>
                </Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Отсутствие результатов -->
    <div v-else class="mt-6 flex flex-col items-center justify-center rounded-lg border border-zinc-200 bg-white py-12 text-center dark:border-zinc-800 dark:bg-zinc-900">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="mb-4 size-16 text-zinc-300 dark:text-zinc-600">
        <path d="M18 2a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h12Zm0 2H6v16h12V4Z" />
        <path d="M12 9a2 2 0 1 1 0 4 2 2 0 0 1 0-4Zm-4 8a5 5 0 0 1 8 0H8Z" />
      </svg>
      <h3 class="text-xl font-medium text-zinc-900 dark:text-white">Пользователи не найдены</h3>
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

    <!-- Улучшенная пагинация -->
    <div v-if="users.data.length > 0" class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4">
      <div class="flex flex-col sm:flex-row items-center gap-3 sm:gap-4 w-full sm:w-auto">
        <div class="text-sm text-zinc-700 dark:text-zinc-300 text-center sm:text-left">
          <span v-if="users.total > 0">
            Показано {{ users.from }}-{{ users.to }} из {{ users.total }} пользователей
          </span>
          <span v-else>Всего 0 пользователей</span>
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
      
      <!-- Новый компонент пагинации -->
      <div class="w-full sm:w-auto">
        <Pagination 
          :links="users.links" 
          :show-first-last-buttons="true"
          :max-visible-pages="5"
        />
      </div>
    </div>

    <!-- Модальное окно создания пользователя -->
    <CreateUserModal
      :show="showCreateModal"
      :roles="roles"
      :is-admin="isAdmin"
      @close="showCreateModal = false"
      @created="handleUserCreated"
    />
  </AdminLayout>
</template>

<script setup>
import { ref, watch, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useToast } from 'vue-toastification';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import SelectList from '@/Components/SelectList.vue';
import CreateUserModal from '@/Components/Modal/CreateUserModal.vue';
import Pagination from '@/Components/Pagination.vue';
import debounce from 'lodash/debounce';

const toast = useToast();

const props = defineProps({
  users: Object,
  filters: Object,
  roles: Array,
  canManageUsers: Boolean,
  canManageRoles: Boolean,
  isAdmin: Boolean,
});

// Состояние
const searchQuery = ref(props.filters.search || '');
const roleFilter = ref(props.filters.role || '');
const perPage = ref(props.filters.per_page || 10);
const loading = ref(false);
const showCreateModal = ref(false);

// Варианты для количества записей на странице
const perPageOptions = [
  { value: 5, label: '5' },
  { value: 10, label: '10' },
  { value: 25, label: '25' },
  { value: 50, label: '50' },
];

// Методы
const hasAdminRole = (user) => {
  return user.roles && user.roles.some(role => role.name === 'admin');
};

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
    route('admin.users'),
    { 
      search: searchQuery.value,
      role: roleFilter.value,
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

// Преобразование ролей для компонента SelectList
const selectRoles = computed(() => {
  // Добавляем пустой вариант в начало списка
  return [
    { value: '', label: 'Все роли' },
    ...props.roles.map(role => ({
      value: role.name,
      label: role.name,
    }))
  ];
});

// Отслеживание flash-сообщений
watch(() => props.users, () => {
  const flash = router.page.props.flash;
  if (flash.message) {
    toast.success(flash.message);
  }
  if (flash.error) {
    toast.error(flash.error);
  }
}, { immediate: true });

const hasActiveFilters = () => {
  return roleFilter.value !== '' || searchQuery.value !== '';
};

const resetFilters = () => {
  searchQuery.value = '';
  roleFilter.value = '';
  applyFilters();
};

const getNoResultsMessage = () => {
  if (searchQuery.value && roleFilter.value) {
    return `По запросу "${searchQuery.value}" с ролью "${roleFilter.value}" ничего не найдено`;
  } else if (searchQuery.value) {
    return `По запросу "${searchQuery.value}" ничего не найдено`;
  } else if (roleFilter.value) {
    return `Пользователей с ролью "${roleFilter.value}" не найдено`;
  } else {
    return 'В системе еще нет зарегистрированных пользователей';
  }
};

// Изменение количества записей на странице
const changePerPage = () => {
  applyFilters();
};

// Обработка создания пользователя
const handleUserCreated = () => {
  // Перезагружаем список пользователей
  router.reload({ only: ['users'] });
};
</script> 