<template>
  <Head title="Админ-панель" />

  <AdminLayout>
    <div class="space-y-10">
      <!-- Приветствие, период и быстрые действия -->
      <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div class="min-w-0">
          <p class="text-sm font-medium text-brandblue-dark dark:text-brandblue">{{ today }}</p>
          <h1 class="font-display mt-1 text-2xl font-semibold text-zinc-950 sm:text-3xl dark:text-white">
            Здравствуйте, {{ user.first_name || user.name }}
          </h1>
          <div class="mt-3 flex flex-wrap items-center gap-2">
            <span
              v-for="role in user.roles"
              :key="role.id"
              class="rounded-full bg-brandblue-soft px-3 py-1 text-xs font-medium text-brandblue-dark dark:bg-brandblue/20 dark:text-brandblue"
            >
              {{ roleLabel(role.name) }}
            </span>
            <span v-if="analytics.online" class="inline-flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
              <span class="size-2 rounded-full bg-brandblue"></span>
              Сейчас на сайте: {{ analytics.online }}
            </span>
          </div>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <div class="inline-flex rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800" role="tablist" aria-label="Период">
            <button
              v-for="option in periods"
              :key="option.value"
              type="button"
              role="tab"
              :aria-selected="analytics.period === option.value"
              class="cursor-pointer rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
              :class="analytics.period === option.value ? 'bg-white text-zinc-950 shadow-sm dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
              @click="setPeriod(option.value)"
            >
              {{ option.label }}
            </button>
          </div>
          <Link
            :href="route('admin.events.create')"
            class="inline-flex items-center gap-2 rounded-lg bg-brandblue-dark px-4 py-2.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-brandblue-deep focus:outline-none focus-visible:ring-2 focus-visible:ring-brandblue focus-visible:ring-offset-2 dark:bg-brandblue dark:hover:bg-brandblue/90 dark:focus-visible:ring-offset-zinc-900"
          >
            <PlusIcon class="size-4" />
            Новое мероприятие
          </Link>
        </div>
      </div>

      <!-- Показатели -->
      <section aria-label="Главные показатели" :class="{ 'opacity-60 transition-opacity': loading }">
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-6">
          <div v-for="card in cards" :key="card.label" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ card.label }}</p>
            <p class="font-display mt-2 text-2xl font-semibold text-zinc-950 sm:text-3xl dark:text-white">{{ card.value }}</p>
            <p class="mt-1 text-xs" :class="card.trend.cls">{{ card.trend.text }}</p>
          </div>
        </div>
      </section>

      <!-- Активность -->
      <section aria-labelledby="activity-title" :class="{ 'opacity-60 transition-opacity': loading }">
        <SectionTitle id="activity-title" title="Активность" hint="Сколько людей что-то делали на сайте по дням" />
        <div class="grid gap-6 xl:grid-cols-3">
          <div class="rounded-xl border border-zinc-200 p-4 xl:col-span-2 dark:border-zinc-800">
            <TrendChart :labels="dayLabels" :series="activitySeries" :height="280" />
          </div>
          <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
            <h3 class="text-sm font-semibold text-zinc-950 dark:text-white">Когда активны</h3>
            <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">По дням недели и часам за период</p>
            <Heatmap :grid="analytics.heatmap" />
          </div>
        </div>
        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
          Активность считается по действиям: просмотр записи, вопрос помощнику, открытие документа, регистрация. Журнала заходов на страницы сайт не ведёт.
        </p>
      </section>

      <!-- Регистрации -->
      <section aria-labelledby="reg-title" :class="{ 'opacity-60 transition-opacity': loading }">
        <SectionTitle id="reg-title" title="Регистрации и оплаты" hint="Кто и как получает доступ к мероприятиям">
          <Link :href="route('admin.event-registrations')" class="text-sm font-medium text-brandblue-dark hover:underline dark:text-brandblue">Все регистрации</Link>
        </SectionTitle>
        <div class="grid gap-6 xl:grid-cols-3">
          <div class="rounded-xl border border-zinc-200 p-4 xl:col-span-2 dark:border-zinc-800">
            <h3 class="text-sm font-semibold text-zinc-950 dark:text-white">Регистрации по дням</h3>
            <TrendChart class="mt-3" type="bar" :labels="dayLabels" :series="[{ label: 'Регистрации', data: analytics.activity.registrations }]" :height="220" />
          </div>
          <div class="space-y-6">
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
              <h3 class="mb-3 text-sm font-semibold text-zinc-950 dark:text-white">Тип доступа</h3>
              <DonutChart v-if="analytics.registrations.total" :items="analytics.registrations.by_type" caption="доступов" :size="130" />
              <p v-else class="text-sm text-zinc-500 dark:text-zinc-400">Регистраций за период нет.</p>
            </div>
            <div v-if="analytics.registrations.by_status.length" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
              <h3 class="mb-3 text-sm font-semibold text-zinc-950 dark:text-white">Оплата</h3>
              <ul class="space-y-2">
                <li v-for="item in analytics.registrations.by_status" :key="item.key" class="flex items-center gap-3 text-sm">
                  <span class="w-28 shrink-0 truncate text-zinc-600 dark:text-zinc-300">{{ item.label }}</span>
                  <span class="h-2 flex-1 rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <span class="block h-2 rounded-full bg-brandblue" :style="{ width: `${share(item.count, analytics.registrations.total)}%` }"></span>
                  </span>
                  <span class="w-8 text-right tabular-nums text-zinc-950 dark:text-white">{{ item.count }}</span>
                </li>
              </ul>
            </div>
          </div>
        </div>

        <div v-if="analytics.registrations.top_events.length" class="mt-6 rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
          <h3 class="mb-3 text-sm font-semibold text-zinc-950 dark:text-white">Мероприятия по регистрациям</h3>
          <ul class="space-y-3">
            <li v-for="event in analytics.registrations.top_events" :key="event.id" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)_auto]">
              <Link :href="route('admin.events.edit', event.id)" class="truncate text-sm font-medium text-zinc-950 hover:underline dark:text-white">{{ event.title }}</Link>
              <span class="order-3 col-span-2 h-2 rounded-full bg-zinc-100 sm:order-none sm:col-span-1 dark:bg-zinc-800">
                <span class="block h-2 rounded-full bg-brandblue-dark dark:bg-brandblue" :style="{ width: `${share(event.registrations, topRegistrations)}%` }"></span>
              </span>
              <span class="text-right text-sm tabular-nums text-zinc-600 dark:text-zinc-300">
                {{ event.registrations }}<span v-if="event.revenue" class="text-zinc-400"> · {{ money(event.revenue) }}</span>
              </span>
            </li>
          </ul>
        </div>
      </section>

      <!-- Записи и глубина просмотра -->
      <section aria-labelledby="video-title" :class="{ 'opacity-60 transition-opacity': loading }">
        <SectionTitle id="video-title" title="Записи и глубина просмотра" hint="Кто смотрит записи и как далеко досматривает" />
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
          <div v-for="item in videoCards" :key="item.label" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ item.label }}</p>
            <p class="font-display mt-2 text-2xl font-semibold text-zinc-950 dark:text-white">{{ item.value }}</p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ item.note }}</p>
          </div>
        </div>

        <div v-if="analytics.video.summary.views" class="mt-6 grid gap-6 xl:grid-cols-2">
          <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
            <h3 class="text-sm font-semibold text-zinc-950 dark:text-white">Сколько зрителей дошло до отметки</h3>
            <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">Доля зрителей, досмотревших хотя бы до этого места ролика</p>
            <TrendChart :labels="analytics.video.retention.map((p) => p.label)" :series="[{ label: 'Зрителей', data: analytics.video.retention.map((p) => p.share) }]" suffix="%" :max="100" :height="220" />
          </div>
          <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
            <h3 class="text-sm font-semibold text-zinc-950 dark:text-white">Глубина просмотра</h3>
            <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">Сколько просмотров остановились в каждой части ролика</p>
            <TrendChart type="bar" :labels="analytics.video.buckets.map((b) => b.label)" :series="[{ label: 'Просмотров', data: analytics.video.buckets.map((b) => b.count) }]" :height="220" />
          </div>
        </div>

        <div v-if="analytics.video.top.length" class="mt-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-800">
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead>
                <tr class="border-b border-zinc-200 text-left text-xs font-medium text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                  <th class="px-4 py-2.5">Запись</th>
                  <th class="px-4 py-2.5 text-right">Зрителей</th>
                  <th class="px-4 py-2.5">Средняя глубина</th>
                  <th class="px-4 py-2.5 text-right">Досмотрели</th>
                  <th class="px-4 py-2.5 text-right">Время просмотра</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/70">
                <tr v-for="row in analytics.video.top" :key="`${row.event_id}-${row.video_key}`">
                  <td class="max-w-xs px-4 py-3">
                    <Link :href="route('admin.events.edit', row.event_id)" class="block truncate font-medium text-zinc-950 hover:underline dark:text-white">{{ row.title }}</Link>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">ролик {{ row.duration_minutes }} мин · {{ String(row.video_key).slice(0, 8) }}</span>
                  </td>
                  <td class="px-4 py-3 text-right tabular-nums">{{ row.viewers }}</td>
                  <td class="px-4 py-3">
                    <div class="flex items-center gap-3">
                      <span class="h-2 w-28 shrink-0 rounded-full bg-zinc-100 dark:bg-zinc-800">
                        <span class="block h-2 rounded-full" :class="row.avg_depth >= 60 ? 'bg-brandblue-dark dark:bg-brandblue' : 'bg-brandcoral'" :style="{ width: `${row.avg_depth}%` }"></span>
                      </span>
                      <span class="tabular-nums text-zinc-700 dark:text-zinc-300">{{ row.avg_depth }}%</span>
                    </div>
                  </td>
                  <td class="px-4 py-3 text-right tabular-nums">{{ row.completed_rate }}%</td>
                  <td class="px-4 py-3 text-right tabular-nums text-zinc-600 dark:text-zinc-300">{{ minutesText(row.minutes) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <EmptyNote v-else-if="!analytics.video.summary.views">За период никто не смотрел записи. Данные появятся, когда плеер начнёт сохранять позиции просмотра.</EmptyNote>
      </section>

      <!-- ИИ-помощник -->
      <section aria-labelledby="assistant-title" :class="{ 'opacity-60 transition-opacity': loading }">
        <SectionTitle id="assistant-title" title="ИИ-помощник и библиотека" hint="Что спрашивают и какие документы открывают">
          <Link :href="route('admin.assistant')" class="text-sm font-medium text-brandblue-dark hover:underline dark:text-brandblue">Подробнее</Link>
        </SectionTitle>
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
          <div v-for="item in assistantCards" :key="item.label" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ item.label }}</p>
            <p class="font-display mt-2 text-2xl font-semibold text-zinc-950 dark:text-white">{{ item.value }}</p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ item.note }}</p>
          </div>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-3">
          <div class="rounded-xl border border-zinc-200 p-4 xl:col-span-2 dark:border-zinc-800">
            <h3 class="mb-3 text-sm font-semibold text-zinc-950 dark:text-white">Вопросы по дням</h3>
            <TrendChart
              type="bar"
              stacked
              :labels="dayLabels"
              :series="[
                { label: 'Нашёлся ответ', data: analytics.assistant.by_day.found },
                { label: 'Нет в библиотеке', data: notFoundByDay, color: '#f27b63' },
              ]"
              :height="220"
            />
          </div>
          <div class="space-y-6">
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
              <h3 class="mb-3 text-sm font-semibold text-zinc-950 dark:text-white">Чего не хватает в библиотеке</h3>
              <ul v-if="analytics.assistant.missing.length" class="space-y-2 text-sm">
                <li v-for="(item, index) in analytics.assistant.missing" :key="index" class="flex items-start gap-2">
                  <span class="mt-0.5 rounded bg-brandcoral-soft px-1.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ item.times }}×</span>
                  <span class="text-zinc-700 dark:text-zinc-300">{{ item.question }}</span>
                </li>
              </ul>
              <p v-else class="text-sm text-zinc-500 dark:text-zinc-400">Все вопросы за период нашли ответ.</p>
            </div>
          </div>
        </div>

        <div class="mt-6 rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
          <h3 class="mb-3 text-sm font-semibold text-zinc-950 dark:text-white">Какие документы открывают</h3>
          <ul v-if="analytics.assistant.documents.length" class="space-y-3">
            <li v-for="doc in analytics.assistant.documents" :key="doc.id" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)_auto]">
              <Link :href="route('admin.medical-library.edit', doc.id)" class="truncate text-sm font-medium text-zinc-950 hover:underline dark:text-white">{{ doc.title }}</Link>
              <span class="order-3 col-span-2 h-2 rounded-full bg-zinc-100 sm:order-none sm:col-span-1 dark:bg-zinc-800">
                <span class="block h-2 rounded-full bg-brandblue-dark dark:bg-brandblue" :style="{ width: `${share(doc.opens, topOpens)}%` }"></span>
              </span>
              <span class="text-right text-sm tabular-nums text-zinc-600 dark:text-zinc-300">
                {{ doc.opens }}<span class="text-zinc-400"> · из помощника {{ doc.from_assistant }}</span>
              </span>
            </li>
          </ul>
          <p v-else class="text-sm text-zinc-500 dark:text-zinc-400">
            За период документы не открывали. Откуда открытия: из ответов помощника {{ analytics.assistant.summary.opens_assistant }}, прямо из библиотеки {{ analytics.assistant.summary.opens_library }}.
          </p>
        </div>
      </section>

      <!-- Ближайшие мероприятия и последние регистрации -->
      <div class="grid gap-8 xl:grid-cols-2">
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
                  <p v-if="problems(event).length" class="mt-1 text-xs text-red-700 dark:text-brandcoral">Не хватает: {{ problems(event).join(", ") }}</p>
                </div>
                <ChevronRightIcon class="size-4 shrink-0 text-zinc-400" aria-hidden="true" />
              </Link>
            </li>
          </ul>
          <EmptyNote v-else>Ближайших мероприятий нет. Создайте мероприятие, и оно появится здесь.</EmptyNote>
        </section>

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
          <EmptyNote v-else>Пока никто не регистрировался.</EmptyNote>
        </section>
      </div>

      <p class="text-xs text-zinc-400">
        {{ stats.users_count }} пользователей · {{ overview.events_total }} мероприятий · {{ overview.speakers_total }} спикеров · {{ overview.library_total }} материалов. Данные обновляются раз в 5 минут, расчёт в {{ analytics.generated_at }}.
      </p>

      <!-- Служебное: проверка уведомлений -->
      <details class="group rounded-xl border border-zinc-200 dark:border-zinc-800">
        <summary class="flex cursor-pointer select-none items-center justify-between px-4 py-3 text-sm font-medium text-zinc-700 dark:text-zinc-200">
          Служебное: проверка уведомлений
          <ChevronRightIcon class="size-4 text-zinc-400 transition-transform group-open:rotate-90" aria-hidden="true" />
        </summary>
        <div class="space-y-3 border-t border-zinc-100 px-4 py-4 dark:border-zinc-800">
          <p class="text-sm text-zinc-500 dark:text-zinc-400">Тестовое уведомление уйдёт всем пользователям с ролью «Администратор» и появится в колокольчике.</p>
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
import { computed, defineComponent, h, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { ChevronRightIcon, PlusIcon } from '@heroicons/vue/20/solid';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TrendChart from '@/Components/Admin/Analytics/TrendChart.vue';
import DonutChart from '@/Components/Admin/Analytics/DonutChart.vue';
import Heatmap from '@/Components/Admin/Analytics/Heatmap.vue';
import { seriesColors, shortDate as chartDate } from '@/Components/Admin/Analytics/chartSetup.js';
import { roleLabel } from '@/Components/Admin/roleLabels.js';

const props = defineProps({
  stats: { type: Object, required: true },
  overview: { type: Object, required: true },
  analytics: { type: Object, required: true },
  upcoming: { type: Array, default: () => [] },
  latestRegistrations: { type: Array, default: () => [] },
  user: { type: Object, required: true },
});

// Мелкие компоненты страницы
const SectionTitle = defineComponent({
  props: { id: String, title: String, hint: String },
  setup(componentProps, { slots }) {
    return () => h('div', { class: 'mb-4 flex flex-wrap items-end justify-between gap-x-6 gap-y-1' }, [
      h('div', [
        h('h2', { id: componentProps.id, class: 'font-display text-xl font-semibold text-zinc-950 dark:text-white' }, componentProps.title),
        h('p', { class: 'text-sm text-zinc-500 dark:text-zinc-400' }, componentProps.hint),
      ]),
      slots.default?.(),
    ]);
  },
});
const EmptyNote = defineComponent({
  setup(_, { slots }) {
    return () => h('div', { class: 'rounded-xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400' }, slots.default?.());
  },
});

const today = new Date().toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' });
const periods = [
  { value: 7, label: '7 дней' },
  { value: 30, label: '30 дней' },
  { value: 90, label: '3 месяца' },
  { value: 365, label: 'Год' },
];

const loading = ref(false);
const setPeriod = (value) => {
  if (value === props.analytics.period) return;
  router.get(route('admin.index'), { period: value }, {
    only: ['analytics'],
    preserveScroll: true,
    preserveState: true,
    replace: true,
    onStart: () => { loading.value = true; },
    onFinish: () => { loading.value = false; },
  });
};

const money = (value) => `${Math.round(value).toLocaleString('ru-RU')} ₽`;
const share = (value, max) => (max ? Math.max(2, Math.round((value / max) * 100)) : 0);
const minutesText = (minutes) => (minutes >= 90 ? `${(minutes / 60).toFixed(1)} ч` : `${minutes} мин`);

// Сравнение с предыдущим периодом такой же длины
const trendOf = (pair) => {
  const muted = 'text-zinc-500 dark:text-zinc-400';
  if (!pair.previous) {
    return { text: pair.current ? 'в прошлом периоде не было' : 'нет данных за период', cls: muted };
  }
  const pct = Math.round(((pair.current - pair.previous) / pair.previous) * 100);
  return {
    text: `${pct > 0 ? '+' : ''}${pct}% к прошлому периоду`,
    cls: pct >= 0 ? 'text-brandblue-dark dark:text-brandblue' : 'text-red-700 dark:text-brandcoral',
  };
};

const cards = computed(() => {
  const k = props.analytics.kpis;
  return [
    { label: 'Активные пользователи', value: k.active_users.current, trend: trendOf(k.active_users) },
    { label: 'Новые пользователи', value: k.new_users.current, trend: trendOf(k.new_users) },
    { label: 'Регистрации', value: k.registrations.current, trend: trendOf(k.registrations) },
    { label: 'Оплачено', value: money(k.paid.current), trend: trendOf(k.paid) },
    { label: 'Зрители записей', value: k.video_viewers.current, trend: trendOf(k.video_viewers) },
    { label: 'Вопросы помощнику', value: k.questions.current, trend: trendOf(k.questions) },
  ];
});

const dayLabels = computed(() => props.analytics.activity.labels.map(chartDate));
const activitySeries = computed(() => {
  const a = props.analytics.activity;
  return [
    { label: 'Активные пользователи', data: a.active_users, color: seriesColors[0] },
    { label: 'Регистрации', data: a.registrations, color: seriesColors[1] },
    { label: 'Зрители записей', data: a.video_viewers, color: '#a9bfdc', dash: [6, 4] },
    { label: 'Вопросы помощнику', data: a.questions, color: seriesColors[3] },
  ];
});

const topRegistrations = computed(() => Math.max(0, ...props.analytics.registrations.top_events.map((e) => e.registrations)));
const topOpens = computed(() => Math.max(0, ...props.analytics.assistant.documents.map((d) => d.opens)));

const videoCards = computed(() => {
  const v = props.analytics.video.summary;
  return [
    { label: 'Зрителей записей', value: v.viewers, note: `${v.views} просмотров` },
    { label: 'Средняя глубина', value: `${v.avg_depth}%`, note: 'какая часть ролика просматривается' },
    { label: 'Досмотрели до конца', value: `${v.completed_rate}%`, note: 'доля просмотров, где дошли до конца' },
    { label: 'Время просмотра', value: `${v.hours} ч`, note: 'суммарно за период' },
  ];
});

const assistantCards = computed(() => {
  const s = props.analytics.assistant.summary;
  return [
    { label: 'Вопросов помощнику', value: s.questions, note: `от ${s.users} пользователей` },
    { label: 'Нашёлся ответ', value: `${s.found_rate}%`, note: `${s.found} из ${s.questions}` },
    { label: 'Открыто документов', value: s.opens_assistant + s.opens_library, note: `из помощника ${s.opens_assistant}, из библиотеки ${s.opens_library}` },
    { label: 'Время ответа', value: `${s.seconds} с`, note: 'в среднем' },
  ];
});
const notFoundByDay = computed(() => props.analytics.assistant.by_day.total.map((total, i) => total - props.analytics.assistant.by_day.found[i]));

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
