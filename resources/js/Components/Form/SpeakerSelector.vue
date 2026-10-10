<template>
    <div ref="rootRef" class="space-y-3">
        <label v-if="label" :for="`${id}-search`" class="mb-1 block text-sm font-medium text-zinc-800 dark:text-zinc-200">
            {{ label }}<span v-if="required" class="text-red-600"> *</span>
        </label>

        <!-- Поиск: сразу печатаем фамилию, список остаётся открытым, чтобы добавить нескольких подряд -->
        <div class="relative">
            <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" aria-hidden="true" />
            <input
                :id="`${id}-search`"
                ref="inputRef"
                v-model="query"
                type="text"
                role="combobox"
                autocomplete="off"
                :aria-expanded="isOpen"
                :aria-controls="`${id}-list`"
                :aria-activedescendant="isOpen && options[activeIndex] ? `${id}-opt-${activeIndex}` : undefined"
                class="block w-full rounded-lg border border-zinc-300 bg-white py-2 pl-9 pr-3 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:focus:border-white dark:focus:ring-white"
                :placeholder="selected.length ? 'Добавить ещё спикера…' : 'Начните вводить фамилию спикера'"
                @focus="open"
                @input="open"
                @keydown="onKeydown"
            />

            <div
                v-if="isOpen"
                class="absolute z-30 mt-1 w-full overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800"
            >
                <ul :id="`${id}-list`" role="listbox" class="max-h-72 overflow-auto py-1">
                    <li
                        v-for="(speaker, index) in matches"
                        :id="`${id}-opt-${index}`"
                        :key="speaker.id"
                        role="option"
                        :aria-selected="index === activeIndex"
                        class="flex cursor-pointer items-center gap-3 px-3 py-2"
                        :class="index === activeIndex ? 'bg-zinc-100 dark:bg-zinc-700' : ''"
                        @mouseenter="activeIndex = index"
                        @mousedown.prevent="choose(speaker)"
                    >
                        <SpeakerPhoto :speaker="speaker" class="size-8" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">
                                {{ nameOf(speaker) }}
                                <span v-if="speaker.is_active === false" class="ml-1 rounded bg-zinc-100 px-1.5 py-0.5 text-[11px] font-normal text-zinc-500 dark:bg-zinc-700 dark:text-zinc-300">скрыт</span>
                            </span>
                            <span v-if="detailsOf(speaker)" class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ detailsOf(speaker) }}</span>
                        </span>
                    </li>
                    <li v-if="!matches.length" class="px-3 py-2 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ query.trim() ? "Такого спикера нет в списке" : "Все спикеры уже добавлены" }}
                    </li>
                    <li
                        :id="`${id}-opt-${matches.length}`"
                        role="option"
                        :aria-selected="activeIndex === matches.length"
                        class="flex cursor-pointer items-center gap-3 border-t border-zinc-100 px-3 py-2 text-sm font-medium text-blue-700 dark:border-zinc-700 dark:text-blue-300"
                        :class="activeIndex === matches.length ? 'bg-zinc-100 dark:bg-zinc-700' : ''"
                        @mouseenter="activeIndex = matches.length"
                        @mousedown.prevent="startCreate"
                    >
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-blue-50 dark:bg-blue-900/30"><PlusIcon class="size-4" /></span>
                        {{ query.trim() ? `Новый спикер: «${query.trim()}»` : "Новый спикер" }}
                    </li>
                </ul>
                <p class="border-t border-zinc-100 px-3 py-1.5 text-[11px] text-zinc-400 dark:border-zinc-700">↑ ↓ — выбрать, Enter — добавить, Esc — закрыть</p>
            </div>
        </div>

        <!-- Быстрое добавление спикера, которого ещё нет в базе -->
        <div v-if="creating" class="space-y-3 rounded-lg border border-blue-200 bg-blue-50/50 p-3 dark:border-blue-900 dark:bg-blue-950/30">
            <p class="text-sm font-medium text-zinc-900 dark:text-white">Новый спикер</p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                    <label :for="`${id}-new-last`" class="mb-1 block text-xs font-medium text-zinc-700 dark:text-zinc-300">Фамилия *</label>
                    <input :id="`${id}-new-last`" ref="newLastRef" v-model="draft.last_name" :class="smallInput" @keydown.enter.prevent="saveNew" />
                    <p v-if="draftErrors.last_name" class="mt-1 text-xs text-red-600">{{ draftErrors.last_name }}</p>
                </div>
                <div>
                    <label :for="`${id}-new-first`" class="mb-1 block text-xs font-medium text-zinc-700 dark:text-zinc-300">Имя *</label>
                    <input :id="`${id}-new-first`" v-model="draft.first_name" :class="smallInput" @keydown.enter.prevent="saveNew" />
                    <p v-if="draftErrors.first_name" class="mt-1 text-xs text-red-600">{{ draftErrors.first_name }}</p>
                </div>
                <div>
                    <label :for="`${id}-new-middle`" class="mb-1 block text-xs font-medium text-zinc-700 dark:text-zinc-300">Отчество</label>
                    <input :id="`${id}-new-middle`" v-model="draft.middle_name" :class="smallInput" @keydown.enter.prevent="saveNew" />
                </div>
            </div>
            <div>
                <label :for="`${id}-new-position`" class="mb-1 block text-xs font-medium text-zinc-700 dark:text-zinc-300">Должность</label>
                <input :id="`${id}-new-position`" v-model="draft.position" :class="smallInput" placeholder="Врач акушер-гинеколог, к.м.н." @keydown.enter.prevent="saveNew" />
            </div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Фото и регалии можно добавить позже в разделе «Спикеры».</p>
            <p v-if="draftErrors.general" class="text-xs text-red-600">{{ draftErrors.general }}</p>
            <div class="flex gap-2">
                <button type="button" class="rounded-lg bg-zinc-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-zinc-800 disabled:opacity-50 dark:bg-white dark:text-zinc-900" :disabled="savingNew" @click="saveNew">
                    {{ savingNew ? "Добавляем…" : "Добавить и выбрать" }}
                </button>
                <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800" @click="creating = false">Отмена</button>
            </div>
        </div>

        <!-- Выбранные спикеры в том порядке, в каком их покажет сайт. Порядок меняется перетаскиванием за ручку -->
        <TransitionGroup v-if="selected.length" ref="listRef" tag="ol" move-class="transition-transform duration-150 ease-out" class="relative space-y-2">
            <li
                v-for="(item, index) in selected"
                :key="item.id"
                :ref="(el) => (itemEls[index] = el)"
                class="relative rounded-lg border bg-white p-3 dark:bg-zinc-800"
                :class="dragIndex === index
                    ? 'z-10 !transition-none border-zinc-400 shadow-xl ring-2 ring-zinc-900/10 dark:border-zinc-500 dark:ring-white/10'
                    : 'border-zinc-200 dark:border-zinc-700'"
                :style="dragIndex === index ? { translate: `0 ${dragOffset}px` } : null"
            >
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="-ml-1 flex h-9 w-6 shrink-0 cursor-grab touch-none items-center justify-center rounded text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 active:cursor-grabbing dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                        :aria-label="`Перетащите, чтобы изменить порядок: ${nameOf(speakerOf(item))}. С клавиатуры — стрелки вверх и вниз`"
                        title="Перетащите, чтобы изменить порядок"
                        :data-handle="index"
                        @pointerdown="startDrag($event, index)"
                        @pointermove="onDrag"
                        @pointerup="endDrag"
                        @pointercancel="endDrag"
                        @keydown.up.prevent="moveByKey(index, -1)"
                        @keydown.down.prevent="moveByKey(index, 1)"
                    >
                        <svg viewBox="0 0 12 20" fill="currentColor" class="h-4 w-3" aria-hidden="true">
                            <circle cx="3" cy="4" r="1.4" /><circle cx="9" cy="4" r="1.4" />
                            <circle cx="3" cy="10" r="1.4" /><circle cx="9" cy="10" r="1.4" />
                            <circle cx="3" cy="16" r="1.4" /><circle cx="9" cy="16" r="1.4" />
                        </svg>
                    </button>
                    <span class="w-4 shrink-0 text-center text-xs font-semibold text-zinc-400">{{ index + 1 }}</span>
                    <SpeakerPhoto :speaker="speakerOf(item)" class="size-9" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ nameOf(speakerOf(item)) }}</span>
                        <span v-if="detailsOf(speakerOf(item))" class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ detailsOf(speakerOf(item)) }}</span>
                    </span>
                    <button type="button" :class="[iconButton, 'hover:!text-red-600']" title="Убрать" :aria-label="`Убрать ${nameOf(speakerOf(item))}`" @click="remove(index)"><XMarkIcon class="size-4" /></button>
                </div>
                <div class="mt-2 grid grid-cols-1 gap-2 pl-14 sm:grid-cols-[180px_1fr]">
                    <input v-model="item.role" :list="`${id}-roles`" :class="smallInput" placeholder="Роль, напр. ведущий" :aria-label="`Роль: ${nameOf(speakerOf(item))}`" />
                    <input v-model="item.topic" :class="smallInput" placeholder="Тема выступления — необязательно" :aria-label="`Тема выступления: ${nameOf(speakerOf(item))}`" />
                </div>
            </li>
        </TransitionGroup>
        <p v-else class="text-xs text-zinc-500 dark:text-zinc-400">Спикеров пока нет. Порядок, в котором вы их добавите, будет на странице мероприятия.</p>
        <p v-if="selected.length > 1" class="text-xs text-zinc-500 dark:text-zinc-400">Порядок на сайте — как здесь. Чтобы поменять, перетащите спикера за ⠿.</p>

        <datalist :id="`${id}-roles`">
            <option value="Ведущий" />
            <option value="Докладчик" />
            <option value="Модератор" />
            <option value="Эксперт" />
        </datalist>

        <p v-if="error" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </div>
</template>

<script setup>
import { computed, defineComponent, h, nextTick, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import axios from "axios";
import { MagnifyingGlassIcon, PlusIcon, UserIcon, XMarkIcon } from "@heroicons/vue/20/solid";

const props = defineProps({
    id: { type: String, required: true },
    label: { type: String, default: "Спикеры" },
    modelValue: { type: Array, default: () => [] },
    speakers: { type: Array, default: () => [] },
    error: { type: String, default: "" },
    required: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue"]);

const smallInput = "block w-full rounded-md border border-zinc-300 bg-white px-2.5 py-1.5 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white";
const iconButton = "inline-flex size-8 items-center justify-center rounded-md text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 disabled:pointer-events-none disabled:opacity-30 dark:text-zinc-400 dark:hover:bg-zinc-700 dark:hover:text-white";

// Круглое фото спикера или значок, если фото нет
const SpeakerPhoto = defineComponent({
    props: { speaker: { type: Object, default: null } },
    setup(photoProps, { attrs }) {
        return () => h(
            "span",
            { class: ["inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-zinc-200 text-zinc-500 dark:bg-zinc-700", attrs.class] },
            photoProps.speaker?.photo
                ? h("img", { src: photoProps.speaker.photo, alt: "", class: "size-full object-cover" })
                : h(UserIcon, { class: "size-1/2" }),
        );
    },
});

// Спикеры, добавленные прямо из формы, пока страница не перезагружена
const created = ref([]);
const allSpeakers = computed(() => [...props.speakers, ...created.value]);
const byId = computed(() => new Map(allSpeakers.value.map((speaker) => [speaker.id, speaker])));

const nameOf = (speaker) => speaker?.full_name || [speaker?.last_name, speaker?.first_name, speaker?.middle_name].filter(Boolean).join(" ") || "Спикер без имени";
const detailsOf = (speaker) => [speaker?.position, speaker?.company].filter(Boolean).join(", ");

// ---------- Выбранные ----------
const selected = ref([]);
const speakerOf = (item) => byId.value.get(item.id) ?? item;

function syncFromModel(value) {
    selected.value = (value ?? []).map((item) => ({ id: item.id, role: item.role ?? "", topic: item.topic ?? "", full_name: item.full_name }));
}
const asModel = () => selected.value.map((item, index) => ({ id: item.id, role: item.role, topic: item.topic, sort_order: index }));

syncFromModel(props.modelValue);
watch(() => props.modelValue, (value) => {
    if (JSON.stringify(value ?? []) !== JSON.stringify(asModel())) {
        syncFromModel(value);
    }
}, { deep: true });
watch(selected, () => emit("update:modelValue", asModel()), { deep: true });

// ---------- Перетаскивание ----------
const listRef = ref(null);
const itemEls = [];
const dragIndex = ref(null);
const dragOffset = ref(0);
let grabOffset = 0;

const listTop = () => (listRef.value?.$el ?? listRef.value)?.getBoundingClientRect().top ?? 0;

function startDrag(event, index) {
    if (event.pointerType === "mouse" && event.button !== 0) {
        return;
    }
    event.preventDefault();
    try {
        // курсор может уйти с ручки — события всё равно приходят ей
        event.currentTarget.setPointerCapture(event.pointerId);
    } catch {
        // без захвата перетаскивание тоже работает, пока курсор над ручкой
    }
    dragIndex.value = index;
    // offsetTop не зависит от анимаций, поэтому место под курсором считаем по нему
    grabOffset = event.clientY - listTop() - itemEls[index].offsetTop;
    dragOffset.value = 0;
}

function onDrag(event) {
    if (dragIndex.value === null) {
        return;
    }
    const pointer = event.clientY - listTop();
    let target = dragIndex.value;
    selected.value.forEach((_, i) => {
        if (i === dragIndex.value || !itemEls[i]) {
            return;
        }
        const middle = itemEls[i].offsetTop + itemEls[i].offsetHeight / 2;
        if (i < dragIndex.value && pointer - grabOffset < middle && target > i) {
            target = i;
        }
        if (i > dragIndex.value && pointer - grabOffset + itemEls[dragIndex.value].offsetHeight > middle) {
            target = i;
        }
    });
    if (target !== dragIndex.value) {
        const [moved] = selected.value.splice(dragIndex.value, 1);
        selected.value.splice(target, 0, moved);
        dragIndex.value = target;
    }
    nextTick(() => {
        const el = itemEls[dragIndex.value];
        if (el) {
            dragOffset.value = pointer - grabOffset - el.offsetTop;
        }
    });
}

function endDrag() {
    dragIndex.value = null;
    dragOffset.value = 0;
}

// С клавиатуры: фокус на ручке и стрелки вверх/вниз
function moveByKey(index, step) {
    const target = index + step;
    if (target < 0 || target >= selected.value.length) {
        return;
    }
    const [moved] = selected.value.splice(index, 1);
    selected.value.splice(target, 0, moved);
    nextTick(() => rootRef.value?.querySelector(`[data-handle="${target}"]`)?.focus());
}
function remove(index) {
    selected.value.splice(index, 1);
}

// ---------- Поиск ----------
const query = ref("");
const isOpen = ref(false);
const activeIndex = ref(0);
const inputRef = ref(null);
const rootRef = ref(null);

const normalize = (text) => (text ?? "").toString().toLowerCase().replaceAll("ё", "е");
const matches = computed(() => {
    const words = normalize(query.value).split(/\s+/).filter(Boolean);
    const chosen = new Set(selected.value.map((item) => item.id));
    return allSpeakers.value
        .filter((speaker) => !chosen.has(speaker.id))
        .filter((speaker) => {
            const haystack = normalize([speaker.last_name, speaker.first_name, speaker.middle_name, speaker.full_name, speaker.position, speaker.company].join(" "));
            // «Анна Петрова» и «Петрова Анна» находят одного и того же человека
            return words.every((word) => haystack.includes(word));
        })
        .slice(0, 50);
});
// в списке есть ещё пункт «Новый спикер» — он последний
const options = computed(() => [...matches.value, null]);

watch(query, () => { activeIndex.value = 0; });

function open() {
    isOpen.value = true;
}
function close() {
    isOpen.value = false;
}

function choose(speaker) {
    selected.value.push({ id: speaker.id, role: "", topic: "" });
    query.value = "";
    activeIndex.value = 0;
    nextTick(() => inputRef.value?.focus());
}

function onKeydown(event) {
    if (event.key === "Escape") {
        close();
        return;
    }
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        open();
        const last = options.value.length - 1;
        activeIndex.value = event.key === "ArrowDown"
            ? Math.min(activeIndex.value + 1, last)
            : Math.max(activeIndex.value - 1, 0);
        nextTick(() => document.getElementById(`${props.id}-opt-${activeIndex.value}`)?.scrollIntoView({ block: "nearest" }));
        return;
    }
    if (event.key === "Enter") {
        // Enter в поиске не должен отправлять всю форму мероприятия
        event.preventDefault();
        if (!isOpen.value) {
            open();
            return;
        }
        const option = options.value[activeIndex.value];
        option ? choose(option) : startCreate();
    }
}

const handleClickOutside = (event) => {
    if (rootRef.value && !rootRef.value.contains(event.target)) {
        close();
    }
};
onMounted(() => document.addEventListener("mousedown", handleClickOutside));
onUnmounted(() => document.removeEventListener("mousedown", handleClickOutside));

// ---------- Новый спикер ----------
const creating = ref(false);
const savingNew = ref(false);
const newLastRef = ref(null);
const draft = reactive({ last_name: "", first_name: "", middle_name: "", position: "" });
const draftErrors = reactive({});

function startCreate() {
    // «Петрова Анна Сергеевна» из поиска сразу раскладываем по полям
    const [lastName = "", firstName = "", middleName = ""] = query.value.trim().split(/\s+/);
    Object.assign(draft, { last_name: lastName, first_name: firstName, middle_name: middleName, position: "" });
    Object.keys(draftErrors).forEach((key) => delete draftErrors[key]);
    creating.value = true;
    close();
    nextTick(() => newLastRef.value?.focus());
}

async function saveNew() {
    if (savingNew.value) {
        return;
    }
    Object.keys(draftErrors).forEach((key) => delete draftErrors[key]);
    savingNew.value = true;
    try {
        const { data } = await axios.post(route("admin.speakers.quick-store"), { ...draft });
        created.value.push(data);
        selected.value.push({ id: data.id, role: "", topic: "" });
        creating.value = false;
        query.value = "";
    } catch (e) {
        const errors = e.response?.data?.errors;
        if (errors) {
            Object.entries(errors).forEach(([key, messages]) => { draftErrors[key] = messages[0]; });
        } else {
            draftErrors.general = "Не получилось добавить спикера. Попробуйте ещё раз.";
        }
    } finally {
        savingNew.value = false;
    }
}
</script>
