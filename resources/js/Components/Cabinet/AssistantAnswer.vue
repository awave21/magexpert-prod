<script setup>
import { computed, ref } from 'vue';
import { ArrowTopRightOnSquareIcon, ChevronDownIcon, DocumentTextIcon } from '@heroicons/vue/24/outline';

// Ответ помощника: текст со сносками [1], [2] и список источников со ссылками на страницы PDF.
// Наведение на сноску подсвечивает её источник, наведение на источник — все его сноски в тексте.
const props = defineProps({
    text: { type: String, required: true },
    sources: { type: Array, default: () => [] },
});

const active = ref(null);
const sourcesOpen = ref(true);

const byNumber = computed(() => Object.fromEntries(props.sources.map((source) => [source.number, source])));

// Сноска не должна переноситься на новую строку одна: последнее слово перед ней уходит вместе со сноской (lead)
const paragraphs = computed(() => props.text.split(/\n{2,}/).map((paragraph) => {
    const parts = [];
    for (const piece of paragraph.split(/(\[\d+\])/).filter(Boolean)) {
        const match = piece.match(/^\[(\d+)\]$/);
        const source = match ? byNumber.value[Number(match[1])] : null;
        if (!source) {
            parts.push({ text: piece });
            continue;
        }
        const previous = parts.at(-1);
        const lead = previous?.text?.match(/(\S+)\s*$/);
        if (lead) {
            previous.text = previous.text.slice(0, lead.index);
        }
        parts.push({ cite: source, lead: lead ? lead[1] : '' });
    }

    return parts;
}));

const languages = { ru: 'Русский', en: 'English' };

const plural = (count) => {
    const mod10 = count % 10;
    const mod100 = count % 100;
    if (mod10 === 1 && mod100 !== 11) {
        return 'источник';
    }

    return mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14) ? 'источника' : 'источников';
};
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <div class="flex flex-col gap-3 text-[15px] leading-[26px] text-gray-800 dark:text-gray-100">
            <p v-for="(parts, index) in paragraphs" :key="index">
                <template v-for="(part, partIndex) in parts" :key="partIndex">
                    <span v-if="part.cite" class="whitespace-nowrap">{{ part.lead }}<a
                        :href="part.cite.url"
                        target="_blank"
                        rel="noopener"
                        class="cab-focus ml-1 mr-0.5 inline-flex h-[20px] min-w-[20px] -translate-y-px items-center justify-center rounded-full px-1 align-middle text-[11px] font-extrabold leading-none transition"
                        :class="active === part.cite.number
                            ? 'bg-brandblue text-white'
                            : 'bg-brandblue-soft text-brandblue-dark hover:bg-brandblue hover:text-white dark:bg-gray-700 dark:text-[#9db6d6]'"
                        :title="`${part.cite.title}, стр. ${part.cite.page}`"
                        :aria-label="`Источник ${part.cite.number}: ${part.cite.title}, страница ${part.cite.page}`"
                        @mouseenter="active = part.cite.number"
                        @mouseleave="active = null"
                        @focus="active = part.cite.number"
                        @blur="active = null"
                    >{{ part.cite.number }}</a></span>
                    <template v-else>{{ part.text }}</template>
                </template>
            </p>
        </div>

        <div v-if="sources.length" class="flex flex-col gap-2">
            <button
                type="button"
                class="cab-focus inline-flex items-center gap-2 self-start rounded-full py-1 pr-2 text-[13px] font-bold text-gray-500 transition hover:text-brandblue-dark dark:text-gray-400"
                :aria-expanded="sourcesOpen"
                @click="sourcesOpen = !sourcesOpen"
            >
                <span class="flex -space-x-1.5" aria-hidden="true">
                    <span
                        v-for="source in sources.slice(0, 4)"
                        :key="source.number"
                        class="flex h-5 w-5 items-center justify-center rounded-full bg-brandblue text-[10px] font-extrabold text-white ring-2 ring-white dark:ring-gray-800"
                    >{{ source.number }}</span>
                </span>
                {{ sources.length }} {{ plural(sources.length) }}
                <ChevronDownIcon class="h-4 w-4 transition-transform" :class="sourcesOpen && 'rotate-180'" aria-hidden="true" />
            </button>

            <ul v-show="sourcesOpen" class="grid gap-2 sm:grid-cols-2">
                <li v-for="source in sources" :key="source.number">
                    <a
                        :href="source.url"
                        target="_blank"
                        rel="noopener"
                        class="cab-focus group flex h-full items-start gap-3 rounded-2xl border p-3 transition"
                        :class="active === source.number
                            ? 'border-brandblue bg-brandblue-soft/60 shadow-[0_10px_22px_-14px_#6186b6bf] dark:bg-gray-700'
                            : 'border-gray-200 bg-white hover:border-brandblue dark:border-gray-700 dark:bg-gray-900'"
                        @mouseenter="active = source.number"
                        @mouseleave="active = null"
                    >
                        <span class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brandblue-soft text-brandblue-dark dark:bg-gray-800 dark:text-[#9db6d6]">
                            <DocumentTextIcon class="h-5 w-5" aria-hidden="true" />
                            <span class="absolute -right-1.5 -top-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-brandblue px-1 text-[10px] font-extrabold text-white ring-2 ring-white dark:ring-gray-900">{{ source.number }}</span>
                        </span>
                        <span class="flex min-w-0 flex-1 flex-col gap-1">
                            <span class="line-clamp-2 text-[13px] font-bold leading-[18px] text-gray-900 dark:text-white">{{ source.title }}</span>
                            <span class="flex flex-wrap items-center gap-x-2 text-[12px] font-semibold text-gray-500 dark:text-gray-400">
                                <span class="text-brandblue-dark dark:text-[#9db6d6]">Стр. {{ source.page }}</span>
                                <span v-if="languages[source.language]" aria-hidden="true">·</span>
                                <span v-if="languages[source.language]">{{ languages[source.language] }}</span>
                            </span>
                            <span v-if="source.excerpt" class="line-clamp-2 text-[12px] leading-[17px] text-gray-500 dark:text-gray-400">{{ source.excerpt }}</span>
                        </span>
                        <ArrowTopRightOnSquareIcon class="mt-0.5 h-4 w-4 shrink-0 text-gray-300 transition group-hover:text-brandblue-dark dark:text-gray-600" aria-hidden="true" />
                    </a>
                </li>
            </ul>
        </div>
    </div>
</template>
