<template>
  <Head title="Админ-панель" />

  <AdminLayout>
    <div class="space-y-8">
      <!-- Приветствие и быстрые действия -->
      <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
          <p class="text-sm font-medium text-brandblue-dark dark:text-brandblue">{{ today }}</p>
          <h1 class="font-display mt-1 text-2xl font-semibold text-zinc-950 sm:text-3xl dark:text-white">
            Здравствуйте, {{ user.first_name || user.name }}
          </h1>
          <div class="mt-3 flex flex-wrap gap-2">
            <span
              v-for="role in user.roles"
              :key="role.id"
              class="rounded-full bg-brandblue-soft px-3 py-1 text-xs font-medium text-brandblue-dark dark:bg-brandblue/20 dark:text-brandblue"
            >
              {{ roleLabel(role.name) }}
            </span>
          </div>
        </div>
        <div class="flex flex-wrap gap-2">
          <Link
            :href="route('admin.events.create')"
            class="inline-flex items-center gap-2 rounded-lg bg-brandblue-dark px-4 py-2.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-brandblue-deep focus:outline-none focus-visible:ring-2 focus-visible:ring-brandblue focus-visible:ring-offset-2 dark:bg-brandblue dark:hover:bg-brandblue/90 dark:focus-visible:ring-offset-zinc-900"
          >
            <PlusIcon class="size-4" />
            Новое мероприятие
          </Link>
          <Link
            :href="route('admin.speakers.create')"
            class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2.5 text-sm font-medium text-zinc-700 transition-colors hover:border-brandblue hover:text-brandblue-dark dark:border-zinc-600 dark:text-zinc-200 dark:hover:border-brandblue dark:hover:text-white"
          >
            Добавить спикера
          </Link>
        </div>
      </div>

      <!-- Показатели -->
      <section aria-label="Показатели за 30 дней">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
          <div v-for="card in cards" :key="card.label" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ card.label }}</p>
            <p class="font-display mt-2 text-3xl font-semibold text-zinc-950 dark:text-white">{{ card.value }}</p>
            <p class="mt-1 text-xs" :class="card.trendClass">{{ card.note }}</p>
          </div>
        </div>
        <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
          В базе: {{ overview.events_total }} мероприятий · {{ overview.speakers_total }} спикеров · {{ overview.library_total }} материалов библиотеки
        </p>
      </section>

      <div class="grid gap-8 xl:grid-cols-2">
        <!-- Ближайшие мероприятия -->
        <section aria-labelledby="upcoming-title" class="min-w-0">
          <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 id="upcoming-title" class="font-display text-lg font-semibold text-zinc-950 dark:text-white">Ближайшие мероприятия</h2>
            <Link :href="route('admin.events')" class="text-sm font-medium text-brandblue-dark hover:underline dark:text-brandblue">Все мероприятия</Link>
          </div>
          <ul v-if="upcoming.length" class="divide-y divide-zinc-100 dark:divide-zinc-800">
            <li v-for="event in upcoming" :key="event.id">
              <Link :href="route('admin.events.edit', event.id)" class="-mx-3 flex items-center gap-4 rounded-lg px-3 py-3 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                <div class="flex size-12 shrink-0 flex-col items-center justify-center rounded-lg bg-brandblue-soft text-brandblue-dark dark:bg-brandblue/15 dark:text-brandblue">
                  <span class="text-base font-semibold leading-none">{{ dayOf(event.start_date) }}</span>
                  <span class="mt-0.5 text-[11px] uppercase leading-none">{{ monthOf(event.start_date) }}</span>
                </div>
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-medium text-zinc-950 dark:text-white">{{ event.title }}</p>
                  <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ event.start_time ? event.start_time.slice(0, 5) + " · " : "" }}{{ seatsText(event) }}
                  </p>
                  <p v-if="problems(event).length" class="mt-1 text-xs text-red-700 dark:text-brandcoral">
                    Не хватает: {{ problems(event).join(", ") }}
                  </p>
                </div>
                <ChevronRightIcon class="size-4 shrink-0 text-zinc-400" aria-hidden="true" />
              </Link>
            </li>
          </ul>
          <div v-else class="rounded-xl border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-700">
            <p class="text-sm font-medium text-zinc-900 dark:text-white">Ближайших мероприятий нет</p>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Создайте мероприятие, и оно появится здесь.</p>
            <Link
              :href="route('admin.events.create')"
              class="mt-4 inline-flex items-center rounded-lg bg-brandblue-dark px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-brandblue-deep dark:bg-brandblue"
            >
              Создать мероприятие
            </Link>
          </div>
        </section>

        <!-- Последние доступы -->
        <section aria-labelledby="latest-title" class="min-w-0">
          <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 id="latest-title" class="font-display text-lg font-semibold text-zinc-950 dark:text-white">Последние регистрации</h2>
            <Link :href="route('admin.event-registrations')" class="text-sm font-medium text-brandblue-dark hover:underline dark:text-brandblue">Все регистрации</Link>
          </div>
          <ul v-if="latestRegistrations.length" class="divide-y divide-zinc-100 dark:divide-zinc-800">
            <li v-for="(item, index) in latestRegistrations" :key="index" class="flex items-center gap-4 py-3">
              <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brandblue-soft text-sm font-semibold text-brandblue-dark dark:bg-brandblue/15 dark:text-brandblue">
                {{ initials(item.name) }}
              </div>
              <div class="min-w-0 flex-1">
                <Link :href="route('admin.users.show', item.user_id)" class="block truncate text-sm font-medium text-zinc-950 hover:underline dark:text-white">{{ item.name || "Без имени" }}</Link>
                <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ item.event_title }}</p>
              </div>
              <div class="shrink-0 text-right">
                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="paymentClass(item)">{{ paymentLabel(item) }}</span>
                <p class="mt-1 text-xs text-zinc-400">{{ shortDate(item.granted_at) }}</p>
              </div>
            </li>
          </ul>
          <div v-else class="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            Пока никто не регистрировался.
          </div>
        </section>
      </div>

      <!-- Служебное: проверка уведомлений, на главной не мешает -->
      <details class="group rounded-xl border border-zinc-200 dark:border-zinc-800">
        <summary class="flex cursor-pointer select-none items-center justify-between px-4 py-3 text-sm font-medium text-zinc-700 dark:text-zinc-200">
          Служебное: проверка уведомлений
          <ChevronRightIcon class="size-4 text-zinc-400 transition-transform group-open:rotate-90" aria-hidden="true" />
        </summary>
        <div class="space-y-3 border-t border-zinc-100 px-4 py-4 dark:border-zinc-800">
          <p class="text-sm text-zinc-500 dark:text-zinc-400">
            Тестовое уведомление уйдёт всем пользователям с ролью «Администратор» и появится в колокольчике.
          </p>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="test in notificationTests"
              :key="test.type"
              type="button"
              class="cursor-pointer rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 transition-colors hover:border-brandblue hover:text-brandblue-dark dark:border-zinc-600 dark:text-zinc-200 dark:hover:border-brandblue dark:hover:text-white"
              @click="sendTest(test)"
            >
              {{ test.label }}
            </button>
          </div>
          <p
            v-if="notificationMessage"
            class="rounded-lg px-3 py-2 text-sm"
            :class="notificationOk ? 'bg-brandblue-soft text-brandblue-dark dark:bg-brandblue/15 dark:text-brandblue' : 'bg-brandcoral-soft text-red-700 dark:bg-red-900/30 dark:text-red-300'"
            role="status"
          >
            {{ notificationMessage }}
          </p>
        </div>
      </details>
    </div>
  </AdminLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { ChevronRightIcon, PlusIcon } from '@heroicons/vue/20/solid';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { roleLabel } from '@/Components/Admin/roleLabels.js';

const props = defineProps({
  stats: { type: Object, required: true },
  overview: { type: Object, required: true },
  upcoming: { type: Array, default: () => [] },
  latestRegistrations: { type: Array, default: () => [] },
  user: { type: Object, required: true },
});

const today = new Date().toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' });

const money = (value) => `${Math.round(value).toLocaleString('ru-RU')} ₽`;

// Сравнение с предыдущими 30 днями: процент и цвет подсказки
const trend = (current, previous) => {
  const muted = 'text-zinc-500 dark:text-zinc-400';
  if (!previous) {
    return { note: current ? 'в прошлые 30 дней не было' : 'за последние 30 дней', cls: muted };
  }
  const pct = Math.round(((current - previous) / previous) * 100);
  return {
    note: `${pct > 0 ? '+' : ''}${pct}% к прошлым 30 дням`,
    cls: pct >= 0 ? 'text-brandblue-dark dark:text-brandblue' : 'text-red-700 dark:text-brandcoral',
  };
};

const cards = computed(() => {
  const muted = 'text-zinc-500 dark:text-zinc-400';
  const reg = trend(props.overview.registrations.current, props.overview.registrations.previous);
  const paid = trend(props.overview.paid.current, props.overview.paid.previous);
  return [
    { label: 'Регистрации за 30 дней', value: props.overview.registrations.current, note: reg.note, trendClass: reg.cls },
    { label: 'Оплачено за 30 дней', value: money(props.overview.paid.current), note: paid.note, trendClass: paid.cls },
    { label: 'Ближайшие мероприятия', value: props.overview.events_upcoming, note: 'активные, ещё не прошли', trendClass: muted },
    { label: 'Пользователи', value: props.stats.users_count, note: 'зарегистрированы на сайте', trendClass: muted },
  ];
});

const dayOf = (iso) => (iso ? Number(iso.slice(8, 10)) : '—');
const monthOf = (iso) => (iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('ru-RU', { month: 'short' }).replace('.', '') : '');
const shortDate = (value) => (value ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }) : '');
const initials = (name) => (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();

const seatsText = (event) => (event.max_quantity ? `${event.registrations} из ${event.max_quantity} мест` : `${event.registrations} регистраций`);
const problems = (event) => {
  const list = [];
  if (!event.speakers) list.push('спикеров');
  if (!event.has_image) list.push('обложки');
  return list;
};

const paymentLabel = (item) => {
  if (item.payment_status === 'completed') return 'Оплачено';
  if (item.payment_status === 'pending') return 'Ждёт оплаты';
  if (item.payment_status === 'refunded') return 'Возврат';
  if (item.payment_status === 'failed') return 'Ошибка оплаты';
  return item.access_type === 'free' ? 'Бесплатно' : '—';
};
const paymentClass = (item) => {
  if (item.payment_status === 'completed') return 'bg-brandblue-soft text-brandblue-dark dark:bg-brandblue/20 dark:text-brandblue';
  if (item.payment_status === 'pending') return 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300';
  if (item.payment_status === 'failed') return 'bg-brandcoral-soft text-red-700 dark:bg-red-900/30 dark:text-red-300';
  return 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200';
};

// Проверка уведомлений
const notificationMessage = ref('');
const notificationOk = ref(true);
const notificationTests = [
  { type: 'order', label: 'О заказе', title: 'Новый заказ', message: 'Получен новый заказ на сумму 1500 руб.' },
  { type: 'user', label: 'О пользователе', title: 'Новый пользователь', message: 'Зарегистрирован новый пользователь: Иван Иванов' },
  { type: 'payment', label: 'Об оплате', title: 'Оплата получена', message: 'Получена оплата заказа #123 на сумму 1500 руб.' },
];

const sendTest = async (test) => {
  try {
    const response = await axios.post('/api/test-notification', {
      id: Date.now(),
      type: test.type,
      title: test.title,
      message: test.message,
      timestamp: new Date().toISOString(),
      read: false,
    });
    notificationOk.value = true;
    notificationMessage.value = response.data.message || 'Уведомление отправлено';
  } catch (error) {
    console.error('Ошибка при отправке тестового уведомления:', error);
    notificationOk.value = false;
    notificationMessage.value = 'Не удалось отправить уведомление';
  }
  setTimeout(() => {
    notificationMessage.value = '';
  }, 5000);
};
</script>
