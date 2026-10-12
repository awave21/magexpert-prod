<script setup>
import { computed, nextTick, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import {
    ArrowPathIcon,
    ArrowUpIcon,
    BeakerIcon,
    ClipboardDocumentListIcon,
    ExclamationTriangleIcon,
    GlobeAltIcon,
    LockClosedIcon,
    ShieldExclamationIcon,
    SparklesIcon,
} from '@heroicons/vue/24/outline';
import ProfileLayout from '@/Layouts/ProfileLayout.vue';
import AssistantAnswer from '@/Components/Cabinet/AssistantAnswer.vue';

const props = defineProps({
    hasAccess: { type: Boolean, required: true },
    available: { type: Boolean, required: true },
});

const suggestions = [
    { icon: BeakerIcon, text: 'Какие противопоказания указаны в инструкции к препарату?' },
    { icon: ClipboardDocumentListIcon, text: 'Кратко перескажи последние клинические рекомендации' },
    { icon: ShieldExclamationIcon, text: 'Какие осложнения описаны и как их избежать?' },
    { icon: GlobeAltIcon, text: 'Что пишут об этом в англоязычных исследованиях?' },
];

const MAX_LENGTH = 1000;
const messages = ref([]);
const question = ref('');
const pending = ref(false);
const scroller = ref(null);
const input = ref(null);

const canAsk = computed(() => props.hasAccess && props.available);
const trimmed = computed(() => question.value.trim());
const canSend = computed(() => canAsk.value && !pending.value && trimmed.value.length >= 3 && trimmed.value.length <= MAX_LENGTH);

const scrollDown = async () => {
    await nextTick();
    scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: 'smooth' });
};

const resize = () => {
    const el = input.value;
    if (!el) {
        return;
    }
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 180)}px`;
};

const errorText = (error) => {
    const status = error.response?.status;
    if (status === 422) {
        return error.response.data.errors?.question?.[0] ?? 'Проверьте вопрос.';
    }
    if (status === 429) {
        return 'Слишком много вопросов подряд. Подождите минуту и спросите снова.';
    }
    if (status === 503) {
        return 'ИИ-помощник скоро заработает.';
    }

    return 'Не удалось получить ответ. Проверьте интернет и попробуйте ещё раз.';
};

const ask = async (text) => {
    const value = (text ?? trimmed.value).trim();
    if (!canAsk.value || pending.value || value.length < 3) {
        return;
    }

    messages.value.push({ role: 'user', text: value });
    const reply = { role: 'assistant', status: 'loading', question: value };
    messages.value.push(reply);
    question.value = '';
    pending.value = true;
    await nextTick();
    resize();
    scrollDown();

    try {
        const { data } = await axios.post(route('cabinet.assistant.ask'), { question: value });
        Object.assign(messages.value[messages.value.length - 1], { status: data.found ? 'done' : 'empty', text: data.answer, sources: data.sources });
    } catch (error) {
        Object.assign(messages.value[messages.value.length - 1], { status: 'error', text: errorText(error) });
    } finally {
        pending.value = false;
        scrollDown();
        input.value?.focus();
    }
};

const retry = (index) => {
    const { question: text } = messages.value[index];
    messages.value.splice(index - 1, 2);
    ask(text);
};

const reset = () => {
    messages.value = [];
    question.value = '';
    nextTick(() => {
        resize();
        input.value?.focus();
    });
};

// Enter отправляет, Shift+Enter — новая строка; во время набора через IME Enter не отправляет
const onKeydown = (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        if (canSend.value) {
            ask();
        }
    }
};
</script>

<template>
    <Head title="ИИ-помощник" />

    <ProfileLayout>
        <header class="flex flex-wrap items-end gap-4">
            <div class="flex min-w-0 flex-[1_1_420px] flex-col gap-2.5">
                <span class="cab-eyebrow">Личный кабинет</span>
                <h1 class="cab-h1 flex flex-wrap items-center gap-3">
                    ИИ-помощник
                    <span v-if="hasAccess && !available" class="cab-chip-warn">Скоро</span>
                </h1>
                <p class="max-w-[620px] text-[15px] leading-6 text-gray-500 dark:text-gray-400">
                    Отвечает по документам библиотеки MagExpert и показывает, на какой странице какого документа это написано.
                </p>
            </div>
            <button v-if="messages.length" type="button" class="cab-btn-ghost shrink-0" :disabled="pending" @click="reset">
                <ArrowPathIcon class="h-[18px] w-[18px]" aria-hidden="true" />
                Новый диалог
            </button>
        </header>

        <!-- Нет оплаченного или выданного доступа -->
        <section v-if="!hasAccess" class="cab-panel flex flex-col items-center gap-5 px-6 py-14 text-center sm:px-10">
            <span class="flex h-16 w-16 items-center justify-center rounded-full bg-brandblue-soft text-brandblue-dark dark:bg-gray-800 dark:text-[#9db6d6]">
                <LockClosedIcon class="h-7 w-7" aria-hidden="true" />
            </span>
            <div class="flex max-w-[520px] flex-col gap-2">
                <h2 class="cab-h2">Помощник доступен участникам программ</h2>
                <p class="text-[15px] leading-6 text-gray-500 dark:text-gray-400">
                    Он открывается, когда у вас есть оплаченный или выданный доступ к мероприятию MagExpert. Доступы появляются в разделе «Мои доступы».
                </p>
            </div>
            <div class="flex flex-wrap justify-center gap-3">
                <Link :href="route('events.index')" class="cab-btn">Выбрать мероприятие</Link>
                <Link :href="route('my-events')" class="cab-btn-ghost">Мои доступы</Link>
            </div>
        </section>

        <!-- Чат -->
        <section v-else class="cab-panel flex h-[calc(100dvh-140px)] min-h-[560px] flex-col overflow-hidden lg:h-[calc(100vh-250px)]">
            <div ref="scroller" class="flex-1 overflow-y-auto overscroll-contain px-4 py-6 sm:px-8">
                <!-- Пустое состояние -->
                <div v-if="!messages.length" class="mx-auto flex min-h-full max-w-[720px] flex-col items-center justify-center gap-7 py-6 text-center">
                    <span class="relative flex h-16 w-16 items-center justify-center rounded-[22px] bg-gradient-to-br from-brandblue to-brandblue-dark text-white shadow-[0_14px_30px_-14px_#3f6596]">
                        <SparklesIcon class="h-8 w-8" aria-hidden="true" />
                    </span>
                    <div class="flex flex-col gap-2">
                        <h2 class="cab-h2">Спросите библиотеку</h2>
                        <p class="mx-auto max-w-[520px] text-[15px] leading-6 text-gray-500 dark:text-gray-400">
                            Задайте вопрос на русском или английском. Помощник найдёт ответ в клинических рекомендациях, инструкциях и статьях и приложит ссылки на страницы.
                        </p>
                    </div>
                    <div v-if="available" class="grid w-full gap-2.5 sm:grid-cols-2">
                        <button
                            v-for="item in suggestions"
                            :key="item.text"
                            type="button"
                            class="cab-focus group flex items-start gap-3 rounded-2xl border border-gray-200 bg-white p-4 text-left transition hover:-translate-y-0.5 hover:border-brandblue hover:shadow-[0_12px_24px_-16px_#6186b6bf] motion-reduce:hover:translate-y-0 dark:border-gray-700 dark:bg-gray-900"
                            @click="ask(item.text)"
                        >
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brandblue-soft text-brandblue-dark transition group-hover:bg-brandblue group-hover:text-white dark:bg-gray-800 dark:text-[#9db6d6]">
                                <component :is="item.icon" class="h-[18px] w-[18px]" aria-hidden="true" />
                            </span>
                            <span class="pt-1.5 text-sm font-semibold leading-5 text-gray-800 dark:text-gray-100">{{ item.text }}</span>
                        </button>
                    </div>
                    <p v-else class="cab-chip-blue">Подключаем поиск по документам. Совсем скоро здесь можно будет задавать вопросы.</p>
                </div>

                <!-- Сообщения -->
                <ol v-else class="mx-auto flex max-w-[760px] flex-col gap-7" aria-live="polite">
                    <li v-for="(message, index) in messages" :key="index" class="flex" :class="message.role === 'user' ? 'justify-end' : 'gap-3'">
                        <div
                            v-if="message.role === 'user'"
                            class="max-w-[85%] whitespace-pre-line rounded-[22px] rounded-br-md bg-brandblue px-4 py-2.5 text-[15px] leading-6 text-white shadow-[0_10px_22px_-14px_#6186b6bf]"
                        >{{ message.text }}</div>

                        <template v-else>
                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brandblue-soft text-brandblue-dark dark:bg-gray-800 dark:text-[#9db6d6]" aria-hidden="true">
                                <SparklesIcon class="h-4 w-4" />
                            </span>
                            <div class="min-w-0 flex-1 pt-1">
                                <div v-if="message.status === 'loading'" class="flex flex-col gap-3" role="status">
                                    <span class="assistant-shimmer text-sm font-bold">Ищу в документах библиотеки…</span>
                                    <span class="h-3 w-11/12 rounded-full bg-gray-100 motion-safe:animate-pulse dark:bg-gray-700"></span>
                                    <span class="h-3 w-4/5 rounded-full bg-gray-100 motion-safe:animate-pulse dark:bg-gray-700"></span>
                                    <span class="h-3 w-2/3 rounded-full bg-gray-100 motion-safe:animate-pulse dark:bg-gray-700"></span>
                                </div>

                                <AssistantAnswer v-else-if="message.status === 'done'" :text="message.text" :sources="message.sources" />

                                <div v-else-if="message.status === 'empty'" class="flex flex-col items-start gap-2.5">
                                    <span class="cab-chip-warn">Нет в библиотеке</span>
                                    <p class="text-[15px] leading-[26px] text-gray-800 dark:text-gray-100">{{ message.text }}</p>
                                </div>

                                <div v-else class="flex flex-wrap items-center gap-3 rounded-2xl border border-[#f5c9bd] bg-[#fdf1ed] px-4 py-3 text-sm font-semibold text-[#9a3b25] dark:border-[#7a3424] dark:bg-[#3a1d16] dark:text-[#f5b4a3]">
                                    <ExclamationTriangleIcon class="h-5 w-5 shrink-0" aria-hidden="true" />
                                    <span class="min-w-0 flex-1">{{ message.text }}</span>
                                    <button
                                        v-if="index === messages.length - 1"
                                        type="button"
                                        class="cab-focus rounded-full px-2 py-1 font-bold underline-offset-2 hover:underline"
                                        @click="retry(index)"
                                    >Повторить</button>
                                </div>
                            </div>
                        </template>
                    </li>
                </ol>
            </div>

            <!-- Поле вопроса -->
            <div class="border-t border-gray-100 bg-white/80 px-4 pb-4 pt-3 sm:px-8 dark:border-gray-700 dark:bg-gray-800/80">
                <form class="mx-auto flex max-w-[760px] flex-col gap-2" @submit.prevent="ask()">
                    <label for="assistant-question" class="sr-only">Ваш вопрос</label>
                    <div
                        class="flex items-end gap-2 rounded-[26px] border border-gray-200 bg-white py-2 pl-5 pr-2 transition focus-within:border-brandblue focus-within:shadow-[0_0_0_4px_#6186b62e] dark:border-gray-600 dark:bg-gray-900"
                        :class="!canAsk && 'opacity-60'"
                    >
                        <textarea
                            id="assistant-question"
                            ref="input"
                            v-model="question"
                            rows="1"
                            :maxlength="MAX_LENGTH"
                            :disabled="!canAsk"
                            class="max-h-[180px] min-h-[28px] flex-1 resize-none border-0 bg-transparent p-0 py-2 text-[15px] leading-6 text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-0 dark:text-white"
                            :placeholder="available ? 'Спросите о препарате, дозировке, методике…' : 'Помощник скоро заработает'"
                            @input="resize"
                            @keydown="onKeydown"
                        ></textarea>
                        <button
                            type="submit"
                            class="cab-focus flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brandblue text-white shadow-[0_10px_22px_-10px_#6186b6bf] transition hover:bg-brandblue-deep disabled:bg-gray-200 disabled:text-gray-400 disabled:shadow-none dark:disabled:bg-gray-700 dark:disabled:text-gray-500"
                            :disabled="!canSend"
                            aria-label="Отправить вопрос"
                        >
                            <ArrowPathIcon v-if="pending" class="h-5 w-5 motion-safe:animate-spin" aria-hidden="true" />
                            <ArrowUpIcon v-else class="h-5 w-5 stroke-[2.5]" aria-hidden="true" />
                        </button>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-2 text-[12px] leading-[17px] text-gray-400 dark:text-gray-500">
                        <span>Ответы составлены по документам библиотеки. Дозировки сверяйте с первоисточником.</span>
                        <span class="hidden sm:inline">
                            <template v-if="question.length > MAX_LENGTH - 100">{{ question.length }} / {{ MAX_LENGTH }} · </template>Enter — отправить, Shift + Enter — новая строка
                        </span>
                    </div>
                </form>
            </div>
        </section>
    </ProfileLayout>
</template>

<style scoped>
/* Мягкий перелив текста «Ищу в документах…», без анимации при reduced motion */
.assistant-shimmer {
    background: linear-gradient(90deg, #6b7280 0%, #6b7280 40%, #6186b6 50%, #6b7280 60%, #6b7280 100%);
    background-size: 200% 100%;
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    animation: assistant-shimmer 1.8s linear infinite;
}

@keyframes assistant-shimmer {
    from { background-position: 100% 0; }
    to { background-position: -100% 0; }
}

@media (prefers-reduced-motion: reduce) {
    .assistant-shimmer { animation: none; color: #6b7280; background: none; }
}
</style>
