<template>
    <TransitionRoot :show="adminStore.isCommandPaletteOpen" as="template" appear @after-leave="reset">
        <Dialog class="relative z-50" @close="adminStore.closeCommandPalette">
            <TransitionChild
                as="template"
                enter="ease-out duration-150"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="ease-in duration-100"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="fixed inset-0 bg-zinc-950/40" aria-hidden="true" />
            </TransitionChild>

            <div class="fixed inset-0 overflow-y-auto p-4 sm:p-6 md:p-20">
                <TransitionChild
                    as="template"
                    enter="ease-out duration-150"
                    enter-from="opacity-0 scale-95"
                    enter-to="opacity-100 scale-100"
                    leave="ease-in duration-100"
                    leave-from="opacity-100 scale-100"
                    leave-to="opacity-0 scale-95"
                >
                    <DialogPanel class="mx-auto max-w-xl overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-zinc-950/5 dark:bg-zinc-900 dark:ring-white/10">
                        <div class="flex items-center gap-3 border-b border-zinc-200 px-4 dark:border-zinc-800">
                            <MagnifyingGlassIcon class="size-5 shrink-0 text-zinc-400" aria-hidden="true" />
                            <input
                                ref="inputRef"
                                v-model="query"
                                type="text"
                                role="combobox"
                                autocomplete="off"
                                :aria-expanded="true"
                                aria-controls="command-palette-list"
                                :aria-activedescendant="results[activeIndex] ? `cmd-opt-${activeIndex}` : undefined"
                                class="h-12 w-full border-0 bg-transparent text-sm text-zinc-900 placeholder-zinc-400 focus:outline-none focus:ring-0 dark:text-white"
                                placeholder="Найти мероприятие, раздел или действие…"
                                @keydown="onKeydown"
                            />
                            <kbd class="rounded border border-zinc-300 px-1.5 py-0.5 text-[11px] text-zinc-500 dark:border-zinc-700">Esc</kbd>
                        </div>

                        <ul v-if="results.length" id="command-palette-list" role="listbox" class="max-h-96 overflow-y-auto p-2">
                            <template v-for="(item, index) in results" :key="item.key">
                                <li
                                    v-if="index === 0 || results[index - 1].group !== item.group"
                                    role="presentation"
                                    class="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wide text-zinc-400"
                                >
                                    {{ item.group }}
                                </li>
                                <li
                                    :id="`cmd-opt-${index}`"
                                    role="option"
                                    :aria-selected="index === activeIndex"
                                    class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm"
                                    :class="index === activeIndex ? 'bg-zinc-100 text-zinc-950 dark:bg-zinc-800 dark:text-white' : 'text-zinc-700 dark:text-zinc-300'"
                                    @mouseenter="activeIndex = index"
                                    @mousedown.prevent="open(item)"
                                >
                                    <component :is="item.icon" class="size-5 shrink-0 text-zinc-400" aria-hidden="true" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-medium">{{ item.name }}</span>
                                        <span v-if="item.hint" class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ item.hint }}</span>
                                    </span>
                                    <span v-if="index === activeIndex" class="text-[11px] text-zinc-400">Enter</span>
                                </li>
                            </template>
                        </ul>
                        <p v-else class="px-4 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">
                            {{ searching ? "Ищем…" : "Ничего не найдено" }}
                        </p>

                        <div class="flex gap-4 border-t border-zinc-100 px-4 py-2 text-[11px] text-zinc-400 dark:border-zinc-800">
                            <span>↑ ↓ выбрать</span><span>Enter открыть</span><span>Esc закрыть</span>
                        </div>
                    </DialogPanel>
                </TransitionChild>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import axios from "axios";
import { Dialog, DialogPanel, TransitionChild, TransitionRoot } from "@headlessui/vue";
import { MagnifyingGlassIcon } from "@heroicons/vue/24/outline";
import { CalendarDaysIcon } from "@heroicons/vue/24/solid";
import { useAdminStore } from "@/Stores/adminStore";

// Быстрый поиск по админке: разделы, действия «создать» и активные мероприятия.
// Разделы и действия приходят из AdminLayout, чтобы список был один на всё меню.
const props = defineProps({
    pages: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
});

const adminStore = useAdminStore();

const query = ref("");
const activeIndex = ref(0);
const inputRef = ref(null);
const events = ref([]);
const searching = ref(false);

const normalize = (text) => (text ?? "").toString().toLowerCase().replaceAll("ё", "е");

const staticItems = computed(() => [
    ...props.actions.map((item) => ({ ...item, group: "Создать", key: `action-${item.routeName}` })),
    ...props.pages.map((item) => ({ ...item, group: "Разделы", key: `page-${item.routeName}` })),
]);

const results = computed(() => {
    const words = normalize(query.value).split(/\s+/).filter(Boolean);
    const matched = staticItems.value.filter((item) =>
        words.every((word) => normalize(`${item.name} ${item.keywords ?? ""}`).includes(word))
    );
    return [...matched, ...events.value];
});

watch(results, () => {
    activeIndex.value = 0;
});

// Мероприятия ищем на сервере с небольшой задержкой, от двух символов
let timer = null;
let requestId = 0;
watch(query, (value) => {
    clearTimeout(timer);
    const text = value.trim();
    if (text.length < 2) {
        events.value = [];
        searching.value = false;
        return;
    }
    searching.value = true;
    timer = setTimeout(async () => {
        const current = ++requestId;
        try {
            const { data } = await axios.get(route("admin.events.search"), { params: { q: text, limit: 6 } });
            if (current !== requestId) {
                return;
            }
            const list = Array.isArray(data) ? data : data.data ?? [];
            events.value = list.map((event) => ({
                key: `event-${event.value}`,
                group: "Мероприятия",
                name: event.text,
                hint: [event.meta?.date, event.meta?.location].filter(Boolean).join(" · "),
                icon: CalendarDaysIcon,
                href: route("admin.events.edit", event.value),
            }));
        } catch {
            events.value = [];
        } finally {
            if (current === requestId) {
                searching.value = false;
            }
        }
    }, 250);
});

function reset() {
    query.value = "";
    events.value = [];
    activeIndex.value = 0;
}

function open(item) {
    adminStore.closeCommandPalette();
    router.visit(item.href ?? route(item.routeName));
}

function onKeydown(event) {
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        const last = results.value.length - 1;
        activeIndex.value = event.key === "ArrowDown" ? Math.min(activeIndex.value + 1, last) : Math.max(activeIndex.value - 1, 0);
        nextTick(() => document.getElementById(`cmd-opt-${activeIndex.value}`)?.scrollIntoView({ block: "nearest" }));
        return;
    }
    if (event.key === "Enter") {
        event.preventDefault();
        const item = results.value[activeIndex.value];
        if (item) {
            open(item);
        }
    }
}

watch(
    () => adminStore.isCommandPaletteOpen,
    (isOpen) => {
        if (isOpen) {
            nextTick(() => inputRef.value?.focus());
        }
    }
);

// ⌘K / Ctrl+K открывает палитру из любого места админки
function onGlobalKeydown(event) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        adminStore.isCommandPaletteOpen ? adminStore.closeCommandPalette() : adminStore.openCommandPalette();
    }
}
onMounted(() => window.addEventListener("keydown", onGlobalKeydown));
onBeforeUnmount(() => {
    window.removeEventListener("keydown", onGlobalKeydown);
    clearTimeout(timer);
});
</script>
