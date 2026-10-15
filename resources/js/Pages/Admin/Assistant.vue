<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCircleIcon, MagnifyingGlassIcon, QuestionMarkCircleIcon, SparklesIcon } from '@heroicons/vue/24/outline';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Components/Pagination.vue';

// Раздел «ИИ-помощник»: что спрашивают пользователи, на что в библиотеке нет ответа и какие документы открывают
const props = defineProps({
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
    missing: { type: Array, required: true },
    documents: { type: Array, required: true },
    questions: { type: Object, required: true },
});

const periods = [
    { value: 7, label: '7 дней' },
    { value: 30, label: '30 дней' },
    { value: 90, label: '3 месяца' },
    { value: 365, label: 'Год' },
];
const statuses = [
    { value: 'all', label: 'Все' },
    { value: 'found', label: 'С ответом' },
    { value: 'missing', label: 'Без ответа' },
];

const period = ref(props.filters.period);
const status = ref(props.filters.status);
const search = ref(props.filters.search);

const apply = () => {
    router.get(route('admin.assistant'), { period: period.value, status: status.value, search: search.value || undefined }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

let timer = null;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(apply, 350);
});

const plural = (n, one, few, many) => {
    const mod10 = n % 10;
    const mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) {
        return one;
    }

    return mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14) ? few : many;
};

const foundShare = computed(() => (props.summary.questions ? Math.round((props.summary.found / props.summary.questions) * 100) : 0));
const cards = computed(() => [
    { label: 'Вопросов', value: props.summary.questions, hint: `${props.summary.users} ${plural(props.summary.users, 'пользователь', 'пользователя', 'пользователей')}` },
    { label: 'Нашёлся ответ', value: `${foundShare.value}%`, hint: `${props.summary.found} из ${props.summary.questions}` },
    { label: 'Открыто из ответов', value: props.summary.opens_assistant, hint: 'кликов по источникам' },
    { label: 'Открыто из библиотеки', value: props.summary.opens_library, hint: 'открытий PDF' },
    { label: 'Расход на ответы', value: `${props.summary.cost.toLocaleString('ru-RU')} ₽`, hint: 'по ценам ИИ-шлюза' },
]);

const chip = 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium';
const th = 'px-4 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400';
</script>

<template>
    <Head title="ИИ-помощник" />

    <AdminLayout>
        <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex flex-col gap-1">
                    <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">ИИ-помощник</h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Что спрашивают пользователи и какие документы библиотеки открывают. Записи хранятся 12 месяцев.</p>
                </div>
                <div class="flex flex-wrap gap-1 rounded-lg border border-zinc-200 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-900" role="group" aria-label="Период">
                    <button
                        v-for="item in periods"
                        :key="item.value"
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                        :class="period === item.value ? 'bg-brandblue-dark text-white dark:bg-brandblue dark:text-white' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800'"
                        :aria-pressed="period === item.value"
                        @click="period = item.value; apply()"
                    >{{ item.label }}</button>
                </div>
            </div>
        </template>

        <div class="flex flex-col gap-6">
            <!-- Сводка за период -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div v-for="card in cards" :key="card.label" class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ card.label }}</div>
                    <div class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white">{{ card.value }}</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ card.hint }}</div>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Без ответа -->
                <section class="min-w-0 rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <header class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Не нашлось в библиотеке</h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Подсказка, каких документов не хватает. Одинаковые вопросы склеены.</p>
                    </header>
                    <ul v-if="missing.length" class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <li v-for="item in missing" :key="item.question" class="flex items-start gap-3 px-5 py-3">
                            <QuestionMarkCircleIcon class="mt-0.5 size-5 shrink-0 text-amber-500" aria-hidden="true" />
                            <span class="min-w-0 flex-1 text-sm text-zinc-900 dark:text-white">{{ item.question }}</span>
                            <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ item.times > 1 ? `${item.times} раза · ` : '' }}{{ item.last_asked }}</span>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">За этот период на все вопросы нашёлся ответ.</p>
                </section>

                <!-- Популярные документы -->
                <section class="min-w-0 rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <header class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Популярные документы</h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Сколько раз документ был в ответах помощника и сколько раз его открыли.</p>
                    </header>
                    <div v-if="documents.length" class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-zinc-100 dark:divide-zinc-800">
                            <thead>
                                <tr>
                                    <th :class="th">Документ</th>
                                    <th :class="th" class="text-right">В ответах</th>
                                    <th :class="th" class="text-right">Из ответов</th>
                                    <th :class="th" class="text-right">Из библиотеки</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                <tr v-for="doc in documents" :key="doc.id">
                                    <td class="px-4 py-3 text-sm">
                                        <a :href="route('admin.medical-library.edit', doc.id)" class="text-brandblue hover:underline">{{ doc.title }}</a>
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm tabular-nums text-zinc-900 dark:text-white">{{ doc.cited }}</td>
                                    <td class="px-4 py-3 text-right text-sm tabular-nums text-zinc-900 dark:text-white">{{ doc.opens_assistant }}</td>
                                    <td class="px-4 py-3 text-right text-sm tabular-nums text-zinc-900 dark:text-white">{{ doc.opens_library }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="px-5 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Документы за этот период не открывали.</p>
                </section>
            </div>

            <!-- Все вопросы -->
            <section class="min-w-0 rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <header class="flex flex-col gap-3 border-b border-zinc-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Вопросы <span class="font-normal text-zinc-500">· {{ questions.total }}</span></h2>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <div class="flex gap-1 rounded-lg border border-zinc-200 p-1 dark:border-zinc-700" role="group" aria-label="Ответ">
                            <button
                                v-for="item in statuses"
                                :key="item.value"
                                type="button"
                                class="rounded-md px-3 py-1 text-sm font-medium transition"
                                :class="status === item.value ? 'bg-brandblue-dark text-white dark:bg-brandblue dark:text-white' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800'"
                                :aria-pressed="status === item.value"
                                @click="status = item.value; apply()"
                            >{{ item.label }}</button>
                        </div>
                        <div class="relative w-full sm:w-64">
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Поиск по тексту вопроса"
                                class="w-full rounded-lg border border-zinc-300 bg-white py-2 pl-9 pr-3 text-sm text-zinc-900 placeholder-zinc-500 focus:border-zinc-500 focus:outline-none focus:ring-2 focus:ring-zinc-500/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            />
                            <MagnifyingGlassIcon class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" aria-hidden="true" />
                        </div>
                    </div>
                </header>

                <ul v-if="questions.data.length" class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <li v-for="item in questions.data" :key="item.id" class="flex flex-col gap-2 px-5 py-4">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                            <span>{{ item.asked_at }}</span>
                            <Link v-if="item.user" :href="route('admin.users.show', item.user.id)" class="font-medium text-brandblue hover:underline">{{ item.user.name || item.user.email }}</Link>
                            <span v-else>Пользователь удалён</span>
                            <span>{{ item.duration }} с</span>
                            <span v-if="item.found" :class="[chip, 'bg-brandblue-soft text-brandblue-dark dark:bg-brandblue/20 dark:text-brandblue']">Есть ответ</span>
                            <span v-else :class="[chip, 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200']">Нет в библиотеке</span>
                        </div>
                        <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ item.question }}</p>
                        <ul v-if="item.sources.length" class="flex flex-wrap gap-2">
                            <li
                                v-for="source in item.sources"
                                :key="`${source.document_id}-${source.page}`"
                                class="inline-flex max-w-full items-center gap-1.5 rounded-lg border px-2 py-1 text-xs"
                                :class="source.opened ? 'border-brandblue/40 bg-brandblue-soft text-brandblue-dark dark:border-brandblue/50 dark:bg-brandblue/15 dark:text-brandblue' : 'border-zinc-200 text-zinc-700 dark:border-zinc-700 dark:text-zinc-300'"
                                :title="source.opened ? 'Пользователь открыл этот источник' : 'Источник был в ответе, но его не открывали'"
                            >
                                <CheckCircleIcon v-if="source.opened" class="size-3.5 shrink-0" aria-hidden="true" />
                                <span class="truncate">{{ source.title }}</span>
                                <span class="shrink-0 text-zinc-500">стр. {{ source.page }}</span>
                            </li>
                        </ul>
                    </li>
                </ul>
                <div v-else class="flex flex-col items-center gap-2 px-5 py-12 text-center">
                    <SparklesIcon class="size-10 text-zinc-300 dark:text-zinc-600" aria-hidden="true" />
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Вопросов за этот период нет.</p>
                </div>

                <Pagination v-if="questions.last_page > 1" :links="questions.links" class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-800" />
            </section>
        </div>
    </AdminLayout>
</template>
