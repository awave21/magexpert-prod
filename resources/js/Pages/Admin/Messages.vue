<script setup>
import { ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    events: { type: Array, default: () => [] },
    history: { type: Object, required: true },
});

const form = useForm({
    audience: 'all',
    event_id: '',
    email: '',
    title: '',
    message: '',
    url: '',
});

const audiences = [
    { value: 'all', label: 'Все пользователи' },
    { value: 'event', label: 'Участники мероприятия' },
    { value: 'user', label: 'Один пользователь' },
];

// Сколько человек получит сообщение — пересчитываем при смене аудитории
const count = ref(null);
let timer = null;
const recount = () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        try {
            const { data } = await axios.get(route('admin.messages.count'), {
                params: { audience: form.audience, event_id: form.event_id || undefined, email: form.email || undefined },
            });
            count.value = data.count;
        } catch (e) {
            count.value = null;
        }
    }, 300);
};
watch(() => [form.audience, form.event_id, form.email], recount, { immediate: true });

const send = () => {
    const who = count.value === 1 ? '1 получателю' : `${count.value ?? '?'} получателям`;
    if (!confirm(`Отправить сообщение ${who}? Отменить отправку будет нельзя.`)) {
        return;
    }
    form.post(route('admin.messages.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('title', 'message', 'url'),
    });
};

const audienceLabel = (item) => ({
    all: 'Все пользователи',
    event: item.event ? `Участники: ${item.event}` : 'Участники мероприятия',
    user: 'Один пользователь',
}[item.audience] ?? item.audience);
const formatDate = (value) => (value ? new Date(value).toLocaleString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');
const eventLabel = (event) => (event.date ? `${new Date(`${event.date}T00:00:00`).toLocaleDateString('ru-RU')} — ${event.title}` : event.title);
</script>

<template>
    <Head title="Сообщения в кабинет" />

    <AdminLayout>
        <template #header>
            <div class="mb-2 flex flex-col gap-1">
                <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">Сообщения в кабинет</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Сообщение появится в колокольчике личного кабинета. Если человек на сайте и не выключил уведомления, оно всплывёт при следующем открытии страницы.</p>
            </div>
        </template>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
            <form class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900" @submit.prevent="send">
                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium text-zinc-900 dark:text-white">Кому</legend>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="a in audiences"
                            :key="a.value"
                            class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                            :class="form.audience === a.value ? 'border-zinc-900 bg-zinc-50 dark:border-white dark:bg-zinc-800' : 'border-zinc-200 dark:border-zinc-700'"
                        >
                            <input v-model="form.audience" type="radio" name="audience" :value="a.value" class="text-zinc-900" />
                            <span class="text-zinc-900 dark:text-white">{{ a.label }}</span>
                        </label>
                    </div>
                    <p v-if="form.errors.audience" class="text-sm text-red-600">{{ form.errors.audience }}</p>
                </fieldset>

                <div v-if="form.audience === 'event'" class="flex flex-col gap-1.5">
                    <label for="event_id" class="text-sm font-medium text-zinc-900 dark:text-white">Мероприятие</label>
                    <select id="event_id" v-model="form.event_id" class="rounded-lg border-zinc-300 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                        <option value="">Выберите мероприятие</option>
                        <option v-for="event in events" :key="event.id" :value="event.id">{{ eventLabel(event) }}</option>
                    </select>
                    <p v-if="form.errors.event_id" class="text-sm text-red-600">{{ form.errors.event_id }}</p>
                </div>

                <div v-if="form.audience === 'user'" class="flex flex-col gap-1.5">
                    <label for="email" class="text-sm font-medium text-zinc-900 dark:text-white">Email пользователя</label>
                    <input id="email" v-model="form.email" type="email" class="rounded-lg border-zinc-300 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" placeholder="doctor@mail.ru" />
                    <p v-if="form.errors.email" class="text-sm text-red-600">{{ form.errors.email }}</p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label for="title" class="text-sm font-medium text-zinc-900 dark:text-white">Заголовок</label>
                    <input id="title" v-model="form.title" maxlength="120" class="rounded-lg border-zinc-300 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" placeholder="Мастер-класс 24 октября" />
                    <p v-if="form.errors.title" class="text-sm text-red-600">{{ form.errors.title }}</p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label for="message" class="text-sm font-medium text-zinc-900 dark:text-white">Текст</label>
                    <textarea id="message" v-model="form.message" maxlength="500" rows="4" class="rounded-lg border-zinc-300 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" placeholder="Начало в 10:00 МСК, ссылка на трансляцию — в разделе «Мероприятия»"></textarea>
                    <div class="flex justify-between text-xs text-zinc-500"><span>Коротко и по делу: сообщение показывается в маленьком окне.</span><span>{{ form.message.length }}/500</span></div>
                    <p v-if="form.errors.message" class="text-sm text-red-600">{{ form.errors.message }}</p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label for="url" class="text-sm font-medium text-zinc-900 dark:text-white">Ссылка <span class="font-normal text-zinc-500">— необязательно</span></label>
                    <input id="url" v-model="form.url" class="rounded-lg border-zinc-300 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" placeholder="/my-events" />
                    <p class="text-xs text-zinc-500">Страница сайта, куда перейдёт человек по нажатию. Только адреса этого сайта, начиная с «/».</p>
                    <p v-if="form.errors.url" class="text-sm text-red-600">{{ form.errors.url }}</p>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                    <span class="text-sm text-zinc-600 dark:text-zinc-300">
                        Получателей: <b class="text-zinc-900 dark:text-white">{{ count ?? '…' }}</b>
                    </span>
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-lg bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-zinc-800 disabled:opacity-50 dark:bg-white dark:text-zinc-900"
                        :disabled="form.processing || !count"
                    >{{ form.processing ? 'Отправляем…' : 'Отправить' }}</button>
                </div>
            </form>

            <aside class="flex flex-col gap-3">
                <span class="text-sm font-medium text-zinc-900 dark:text-white">Как увидит пользователь</span>
                <div class="rounded-2xl border border-zinc-200 bg-white px-5 pb-3 pt-2 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="py-3 text-lg font-medium text-zinc-900 dark:text-white">Сообщения от MAG Expert</div>
                    <div class="flex gap-3 border-t border-zinc-100 py-3 dark:border-zinc-800">
                        <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-[#6186b6]"></span>
                        <span class="min-w-0">
                            <span class="block break-words text-sm font-bold text-zinc-900 dark:text-white">{{ form.title || 'Заголовок сообщения' }}</span>
                            <span class="block whitespace-pre-line break-words text-[13px] text-zinc-500">{{ form.message || 'Текст сообщения' }}</span>
                            <span class="block text-xs text-zinc-400">сейчас<template v-if="form.url"> · откроет {{ form.url }}</template></span>
                        </span>
                    </div>
                </div>
            </aside>
        </div>

        <section class="mt-10">
            <h2 class="mb-4 text-xl font-semibold text-zinc-800 dark:text-white">История</h2>
            <div v-if="history.data.length" class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <table class="w-full min-w-[720px] text-sm">
                    <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 font-medium">Когда</th>
                            <th class="px-4 py-3 font-medium">Сообщение</th>
                            <th class="px-4 py-3 font-medium">Кому</th>
                            <th class="px-4 py-3 text-right font-medium">Получили</th>
                            <th class="px-4 py-3 font-medium">Отправил</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in history.data" :key="item.id" class="border-t border-zinc-100 align-top dark:border-zinc-800">
                            <td class="whitespace-nowrap px-4 py-3 text-zinc-500">{{ formatDate(item.created_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ item.title }}</div>
                                <div class="line-clamp-2 text-zinc-500">{{ item.message }}</div>
                                <div v-if="item.url" class="text-xs text-zinc-400">{{ item.url }}</div>
                            </td>
                            <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">{{ audienceLabel(item) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-zinc-900 dark:text-white">{{ item.recipients_count }}</td>
                            <td class="px-4 py-3 text-zinc-500">{{ item.sender || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700">Сообщений ещё не отправляли.</p>
            <Pagination v-if="history.last_page > 1" :links="history.links" class="mt-4" />
        </section>
    </AdminLayout>
</template>
