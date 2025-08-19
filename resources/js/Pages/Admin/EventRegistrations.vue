<script setup>
import { ref, watch, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { MagnifyingGlassIcon, DocumentTextIcon } from '@heroicons/vue/24/outline';
import SelectList from '@/Components/SelectList.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
  registrations: Object,
  events: Array,
  filters: Object,
});

const loading = ref(false);

const searchQuery = ref(props.filters.search || '');
const selectedEventId = ref(props.filters.event_id || '');
// сорт+направление кодируем через |, чтобы избежать неверного split по _
const sortOption = ref(`${props.filters.sort || 'access_granted_at'}|${props.filters.direction || 'desc'}`);
const perPage = ref(props.filters.per_page || 20);

const localItems = ref([...(props.registrations?.data || [])]);

watch(() => props.registrations, (val) => {
  localItems.value = [...(val?.data || [])];
}, { deep: true });

const totalCount = computed(() => props.registrations?.total || 0);

const sortOptions = [
  { value: 'access_granted_at|desc', label: 'По дате доступа (новые)' },
  { value: 'access_granted_at|asc', label: 'По дате доступа (старые)' },
  { value: 'user|asc', label: 'По пользователю (А-Я)' },
  { value: 'user|desc', label: 'По пользователю (Я-А)' },
  { value: 'email|asc', label: 'Email (А-Я)' },
  { value: 'email|desc', label: 'Email (Я-А)' },
  { value: 'event_start|asc', label: 'Дата события (раньше)' },
  { value: 'event_start|desc', label: 'Дата события (позже)' },
  { value: 'payment_status|asc', label: 'Статус оплаты (А-Я)' },
  { value: 'payment_status|desc', label: 'Статус оплаты (Я-А)' },
];

const perPageOptions = [
  { value: 10, label: '10' },
  { value: 20, label: '20' },
  { value: 50, label: '50' },
  { value: 100, label: '100' },
];

const eventOptions = computed(() => [
  { value: '', label: 'Все мероприятия' },
  ...props.events.map(e => ({ value: e.id, label: e.title }))
]);

const apply = (overrides = {}) => {
  loading.value = true;
  const [sort, direction] = (sortOption.value || 'access_granted_at|desc').toString().split('|');
  const params = {
    // при пустом значении оставляем пустую строку, чтобы бэкенд очистил фильтр
    search: searchQuery.value,
    event_id: selectedEventId.value,
    sort,
    direction,
    per_page: perPage.value,
    // по умолчанию сбрасываем на первую страницу
    page: overrides.page ?? 1,
  };
  router.get(route('admin.event-registrations'), params, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onFinish: () => { loading.value = false; },
  });
};

let searchDebounceId = null;
watch(searchQuery, () => {
  if (searchDebounceId) clearTimeout(searchDebounceId);
  searchDebounceId = setTimeout(() => apply(), 350);
});

const gotoPage = (url) => {
  if (!url) return;
  const urlObj = new URL(url, window.location.origin);
  const page = urlObj.searchParams.get('page');
  apply({ page });
};
</script>

<template>
  <Head title="Регистрации на мероприятия" />

  <AdminLayout>
    <template #header>
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">Регистрации на мероприятия</h1>
      </div>

      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <h2 class="text-xl font-semibold text-zinc-800 dark:text-white">Список регистраций</h2>

        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 sm:gap-4 w-full sm:w-auto">
          <div class="relative w-full sm:w-64">
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Поиск по имени или email"
              class="w-full rounded-lg border border-zinc-300 bg-white pl-10 pr-4 py-2 text-sm text-zinc-900 placeholder-zinc-500 transition-colors duration-200 ease-in-out hover:border-zinc-400 focus:border-zinc-500 focus:outline-none focus:ring-2 focus:ring-zinc-500/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-400 dark:hover:border-zinc-600"
            />
            <MagnifyingGlassIcon class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
          </div>

          <div class="w-full sm:w-60">
            <SelectList v-model="selectedEventId" :options="eventOptions" placeholder="Все мероприятия" @change="apply" />
          </div>

          <div class="w-full sm:w-64">
            <SelectList v-model="sortOption" :options="sortOptions" @change="apply" />
          </div>

          <div class="w-full sm:w-28">
            <SelectList v-model="perPage" :options="perPageOptions" @change="apply" />
          </div>
        </div>
      </div>
    </template>

    <div v-if="loading" class="space-y-4">
      <div v-for="i in 5" :key="i" class="animate-pulse">
        <div class="h-20 rounded-lg bg-zinc-100 dark:bg-zinc-800"></div>
      </div>
    </div>

    <div v-else>
      <div v-if="props.registrations && props.registrations.data.length > 0" class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm transition-all duration-200 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
            <thead class="bg-zinc-50 dark:bg-zinc-800/50">
              <tr>
                <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Пользователь</th>
                <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Email</th>
                <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Мероприятие</th>
                <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Доступ</th>
                <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Оплата</th>
                <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Выдан</th>
                <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Истекает</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
              <tr v-for="row in localItems" :key="row.key" class="group transition-colors duration-150 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                <td class="whitespace-nowrap px-4 sm:px-6 py-4 text-sm text-zinc-900 dark:text-white">
                  <Link :href="route('admin.users.show', row.user.id)" class="font-medium text-brandblue hover:underline">
                    {{ row.user.full_name || '—' }}
                  </Link>
                  <div class="text-zinc-500 text-xs">{{ row.user.company || row.user.position ? `${row.user.company || ''}${row.user.company && row.user.position ? ' · ' : ''}${row.user.position || ''}` : '' }}</div>
                </td>
                <td class="whitespace-nowrap px-4 sm:px-6 py-4 text-sm text-zinc-700 dark:text-zinc-300">{{ row.user.email }}</td>
                <td class="whitespace-nowrap px-4 sm:px-6 py-4 text-sm text-zinc-900 dark:text-white">
                  <Link :href="route('events.show', row.event.slug)" class="text-brandblue hover:underline">{{ row.event.title }}</Link>
                  <div class="text-zinc-500 text-xs" v-if="row.event.start_date">{{ row.event.start_date }} <span v-if="row.event.start_time">{{ row.event.start_time }}</span></div>
                </td>
                <td class="whitespace-nowrap px-4 sm:px-6 py-4 text-sm">
                  <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :class="{
                          'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200': row.access.type === 'free',
                          'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200': row.access.type === 'paid',
                          'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-200': row.access.type === 'promotional',
                          'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-200': row.access.type === 'admin',
                        }">
                    {{ row.access.type_label }}
                  </span>
                  <div class="mt-1 text-xs" :class="row.access.is_active ? 'text-green-600 dark:text-green-400' : 'text-zinc-500'">
                    {{ row.access.is_active ? 'Активен' : 'Отключен' }}
                  </div>
                </td>
                <td class="whitespace-nowrap px-4 sm:px-6 py-4 text-sm">
                  <div class="text-zinc-900 dark:text-white">{{ row.access.payment_status_label }}</div>
                  <div class="text-zinc-500 text-xs" v-if="row.access.payment_amount">{{ row.access.payment_amount }} ₽</div>
                </td>
                <td class="whitespace-nowrap px-4 sm:px-6 py-4 text-sm text-zinc-700 dark:text-zinc-300">{{ row.access.granted_at || '—' }}</td>
                <td class="whitespace-nowrap px-4 sm:px-6 py-4 text-sm text-zinc-700 dark:text-zinc-300">{{ row.access.expires_at || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-else class="mt-6 flex flex-col items-center justify-center rounded-lg border border-zinc-200 bg-white py-12 text-center dark:border-zinc-800 dark:bg-zinc-900">
        <DocumentTextIcon class="mb-4 size-16 text-zinc-300 dark:text-zinc-600" />
        <h3 class="text-xl font-medium text-zinc-900 dark:text-white">Ничего не найдено</h3>
        <p class="mt-2 text-zinc-600 dark:text-zinc-400">Попробуйте изменить условия поиска или фильтры.</p>
      </div>
    </div>

    <div v-if="props.registrations && props.registrations.data.length > 0" class="mt-6">
      <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex flex-col sm:flex-row items-center gap-3 sm:gap-4 w-full sm:w-auto">
          <div class="text-sm text-zinc-700 dark:text-zinc-300 text-center sm:text-left">
            <span v-if="props.registrations.total > 0">Показано {{ props.registrations.from }}-{{ props.registrations.to }} из {{ props.registrations.total }} записей</span>
            <span v-else>Всего 0 записей</span>
          </div>
          <div class="flex items-center text-sm text-zinc-700 dark:text-zinc-300">
            <span class="mr-2">Показывать:</span>
            <div class="w-20"><SelectList v-model="perPage" :options="perPageOptions" @change="apply" /></div>
          </div>
        </div>
        <div class="w-full sm:w-auto"><Pagination :links="props.registrations.links" :show-first-last-buttons="true" :max-visible-pages="5" /></div>
      </div>
    </div>
  </AdminLayout>
</template>


