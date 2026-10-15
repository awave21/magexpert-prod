<template>
    <div ref="rootRef" class="relative">
        <label v-if="label" :for="id" class="mb-1 block text-sm font-medium text-zinc-900 dark:text-white">{{ label }}</label>
        <div class="relative">
            <input
                :id="id"
                ref="inputRef"
                :value="text"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                placeholder="дд.мм.гггг"
                maxlength="10"
                :disabled="disabled"
                :class="[field, 'pr-10']"
                @input="onInput"
                @blur="commit"
                @keydown.enter.prevent="commit"
                @keydown.esc="close"
                @keydown.down.prevent="openCalendar"
            />
            <button
                type="button"
                class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-zinc-400 hover:text-zinc-700 disabled:cursor-not-allowed dark:hover:text-zinc-200"
                :disabled="disabled"
                aria-label="Открыть календарь"
                :aria-expanded="isOpen"
                @click="toggle"
            >
                <CalendarDaysIcon class="size-5" aria-hidden="true" />
            </button>
        </div>

        <div
            v-if="isOpen"
            class="absolute z-30 mt-1 w-72 rounded-lg bg-white p-3 shadow-lg ring-1 ring-zinc-950/5 dark:bg-zinc-800 dark:ring-white/10"
            role="dialog"
            aria-label="Календарь"
        >
            <div class="mb-2 flex items-center justify-between">
                <button type="button" class="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-700" aria-label="Предыдущий месяц" @click="shiftMonth(-1)">
                    <ChevronLeftIcon class="size-4" />
                </button>
                <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ monthNames[viewMonth] }} {{ viewYear }}</span>
                <button type="button" class="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-700" aria-label="Следующий месяц" @click="shiftMonth(1)">
                    <ChevronRightIcon class="size-4" />
                </button>
            </div>

            <div class="grid grid-cols-7 gap-0.5 text-center text-[11px] font-medium text-zinc-400">
                <span v-for="day in weekDays" :key="day" class="py-1">{{ day }}</span>
            </div>
            <div class="grid grid-cols-7 gap-0.5">
                <button
                    v-for="cell in cells"
                    :key="cell.iso"
                    type="button"
                    :disabled="cell.disabled"
                    class="flex size-9 items-center justify-center rounded-md text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-30"
                    :class="cell.selected
                        ? 'bg-brandblue-dark font-semibold text-white dark:bg-brandblue dark:text-white'
                        : cell.today
                            ? 'font-semibold text-zinc-900 ring-1 ring-zinc-300 hover:bg-zinc-100 dark:text-white dark:ring-zinc-600 dark:hover:bg-zinc-700'
                            : cell.outside
                                ? 'text-zinc-400 hover:bg-zinc-100 dark:text-zinc-500 dark:hover:bg-zinc-700'
                                : 'text-zinc-900 hover:bg-zinc-100 dark:text-white dark:hover:bg-zinc-700'"
                    @click="pick(cell.iso)"
                >
                    {{ cell.day }}
                </button>
            </div>

            <div class="mt-2 flex justify-between border-t border-zinc-100 pt-2 text-sm dark:border-zinc-700">
                <button type="button" class="font-medium text-zinc-700 hover:underline dark:text-zinc-200" @click="pick(todayIso)">Сегодня</button>
                <button type="button" class="text-zinc-500 hover:underline dark:text-zinc-400" @click="clear">Очистить</button>
            </div>
        </div>

        <p v-if="error" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { CalendarDaysIcon, ChevronLeftIcon, ChevronRightIcon } from "@heroicons/vue/20/solid";
import { input as field } from "@/Components/Admin/formClasses.js";

// Дата в формате ГГГГ-ММ-ДД. Календарь и поле ввода рисуем сами,
// чтобы выглядело одинаково во всех браузерах и на телефоне.
const props = defineProps({
    modelValue: { type: String, default: "" },
    id: { type: String, default: undefined },
    label: { type: String, default: "" },
    min: { type: String, default: "" },
    error: { type: String, default: "" },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(["update:modelValue"]);

const monthNames = ["Январь", "Февраль", "Март", "Апрель", "Май", "Июнь", "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь"];
const weekDays = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"];

const pad = (n) => String(n).padStart(2, "0");
const toIso = (y, m, d) => `${y}-${pad(m + 1)}-${pad(d)}`;
const isValidIso = (iso) => /^\d{4}-\d{2}-\d{2}$/.test(iso ?? "") && toIso(...isoParts(iso)) === iso && !Number.isNaN(new Date(`${iso}T00:00:00`).getTime());
function isoParts(iso) {
    const [y, m, d] = iso.split("-").map(Number);
    return [y, m - 1, d];
}
const toDisplay = (iso) => {
    if (!isValidIso(iso)) {
        return "";
    }
    const [y, m, d] = isoParts(iso);
    return `${pad(d)}.${pad(m + 1)}.${y}`;
};

const rootRef = ref(null);
const inputRef = ref(null);
const isOpen = ref(false);
const text = ref(toDisplay(props.modelValue));

const today = new Date();
const todayIso = toIso(today.getFullYear(), today.getMonth(), today.getDate());

const viewYear = ref(today.getFullYear());
const viewMonth = ref(today.getMonth());

function syncView() {
    if (isValidIso(props.modelValue)) {
        const [y, m] = isoParts(props.modelValue);
        viewYear.value = y;
        viewMonth.value = m;
    }
}
syncView();

watch(() => props.modelValue, (value) => {
    text.value = toDisplay(value);
    syncView();
});

// Маска «дд.мм.гггг»: пользователь печатает только цифры
function onInput(event) {
    const digits = event.target.value.replace(/\D/g, "").slice(0, 8);
    let out = digits;
    if (digits.length > 4) {
        out = `${digits.slice(0, 2)}.${digits.slice(2, 4)}.${digits.slice(4)}`;
    } else if (digits.length > 2) {
        out = `${digits.slice(0, 2)}.${digits.slice(2)}`;
    }
    text.value = out;
    event.target.value = out;
    if (digits.length === 8) {
        commit();
    }
}

function commit() {
    if (text.value === "") {
        if (props.modelValue !== "") {
            emit("update:modelValue", "");
        }
        return;
    }
    const match = text.value.match(/^(\d{2})\.(\d{2})\.(\d{4})$/);
    if (match) {
        const iso = toIso(Number(match[3]), Number(match[2]) - 1, Number(match[1]));
        if (isValidIso(iso)) {
            if (iso !== props.modelValue) {
                emit("update:modelValue", iso);
            }
            return;
        }
    }
    // введено что-то неполное или несуществующее — возвращаем последнюю верную дату
    text.value = toDisplay(props.modelValue);
}

function shiftMonth(step) {
    const date = new Date(viewYear.value, viewMonth.value + step, 1);
    viewYear.value = date.getFullYear();
    viewMonth.value = date.getMonth();
}

const cells = computed(() => {
    const first = new Date(viewYear.value, viewMonth.value, 1);
    const offset = (first.getDay() + 6) % 7; // неделя с понедельника
    const start = new Date(viewYear.value, viewMonth.value, 1 - offset);
    return Array.from({ length: 42 }, (_, i) => {
        const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
        const iso = toIso(date.getFullYear(), date.getMonth(), date.getDate());
        return {
            iso,
            day: date.getDate(),
            outside: date.getMonth() !== viewMonth.value,
            selected: iso === props.modelValue,
            today: iso === todayIso,
            disabled: !!props.min && iso < props.min,
        };
    });
});

function openCalendar() {
    if (!props.disabled) {
        syncView();
        isOpen.value = true;
    }
}
function close() {
    isOpen.value = false;
}
function toggle() {
    isOpen.value ? close() : openCalendar();
}
function pick(iso) {
    emit("update:modelValue", iso);
    text.value = toDisplay(iso);
    close();
}
function clear() {
    emit("update:modelValue", "");
    text.value = "";
    close();
}

const onOutside = (event) => {
    if (rootRef.value && !rootRef.value.contains(event.target)) {
        close();
    }
};
onMounted(() => document.addEventListener("mousedown", onOutside));
onBeforeUnmount(() => document.removeEventListener("mousedown", onOutside));
</script>
