<template>
    <div ref="rootRef" class="relative">
        <label v-if="label" :for="id" class="mb-1 block text-sm font-medium text-zinc-900 dark:text-white">{{ label }}</label>
        <div class="relative">
            <input
                :id="id"
                :value="text"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                placeholder="чч:мм"
                maxlength="5"
                :disabled="disabled"
                :class="[field, 'pr-10']"
                @input="onInput"
                @blur="commit"
                @keydown.enter.prevent="commit"
                @keydown.esc="close"
                @keydown.down.prevent="openList"
            />
            <button
                type="button"
                class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-zinc-400 hover:text-zinc-700 disabled:cursor-not-allowed dark:hover:text-zinc-200"
                :disabled="disabled"
                aria-label="Выбрать время"
                :aria-expanded="isOpen"
                @click="toggle"
            >
                <ClockIcon class="size-5" aria-hidden="true" />
            </button>
        </div>

        <div
            v-if="isOpen"
            class="absolute z-30 mt-1 flex w-40 rounded-lg bg-white p-2 shadow-lg ring-1 ring-zinc-950/5 dark:bg-zinc-800 dark:ring-white/10"
            role="dialog"
            aria-label="Время"
        >
            <ul ref="hoursRef" class="max-h-56 flex-1 space-y-0.5 overflow-y-auto pr-1" aria-label="Часы">
                <li v-for="h in hours" :key="h">
                    <button
                        type="button"
                        :data-active="h === current.hour"
                        class="w-full rounded-md px-2 py-1.5 text-sm transition-colors"
                        :class="h === current.hour ? 'bg-zinc-900 font-semibold text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-900 hover:bg-zinc-100 dark:text-white dark:hover:bg-zinc-700'"
                        @click="setHour(h)"
                    >
                        {{ h }}
                    </button>
                </li>
            </ul>
            <ul ref="minutesRef" class="max-h-56 flex-1 space-y-0.5 overflow-y-auto border-l border-zinc-100 pl-1 dark:border-zinc-700" aria-label="Минуты">
                <li v-for="m in minutes" :key="m">
                    <button
                        type="button"
                        :data-active="m === current.minute"
                        class="w-full rounded-md px-2 py-1.5 text-sm transition-colors"
                        :class="m === current.minute ? 'bg-zinc-900 font-semibold text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-900 hover:bg-zinc-100 dark:text-white dark:hover:bg-zinc-700'"
                        @click="setMinute(m)"
                    >
                        {{ m }}
                    </button>
                </li>
            </ul>
        </div>

        <p v-if="error" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { ClockIcon } from "@heroicons/vue/20/solid";
import { input as field } from "@/Components/Admin/formClasses.js";

// Время «чч:мм». Список часов и минут (шаг 5) рисуем сами, как и календарь.
const props = defineProps({
    modelValue: { type: String, default: "" },
    id: { type: String, default: undefined },
    label: { type: String, default: "" },
    error: { type: String, default: "" },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(["update:modelValue"]);

const pad = (n) => String(n).padStart(2, "0");
const hours = Array.from({ length: 24 }, (_, i) => pad(i));
const minutes = Array.from({ length: 12 }, (_, i) => pad(i * 5));

const normalize = (value) => {
    const match = String(value ?? "").match(/^(\d{1,2}):(\d{2})/);
    if (!match) {
        return "";
    }
    const h = Number(match[1]);
    const m = Number(match[2]);
    return h < 24 && m < 60 ? `${pad(h)}:${pad(m)}` : "";
};

const rootRef = ref(null);
const hoursRef = ref(null);
const minutesRef = ref(null);
const isOpen = ref(false);
const text = ref(normalize(props.modelValue));

watch(() => props.modelValue, (value) => {
    text.value = normalize(value);
});

const current = computed(() => {
    const [hour = "", minute = ""] = normalize(props.modelValue).split(":");
    return { hour, minute };
});

function onInput(event) {
    const digits = event.target.value.replace(/\D/g, "").slice(0, 4);
    const out = digits.length > 2 ? `${digits.slice(0, 2)}:${digits.slice(2)}` : digits;
    text.value = out;
    event.target.value = out;
    if (digits.length === 4) {
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
    const value = normalize(text.value);
    if (value) {
        if (value !== props.modelValue) {
            emit("update:modelValue", value);
        }
        text.value = value;
        return;
    }
    text.value = normalize(props.modelValue);
}

function setHour(hour) {
    const value = `${hour}:${current.value.minute || "00"}`;
    emit("update:modelValue", value);
    text.value = value;
}
function setMinute(minute) {
    const value = `${current.value.hour || "00"}:${minute}`;
    emit("update:modelValue", value);
    text.value = value;
    close();
}

function scrollToActive() {
    nextTick(() => {
        [hoursRef.value, minutesRef.value].forEach((list) => {
            list?.querySelector('[data-active="true"]')?.scrollIntoView({ block: "center" });
        });
    });
}
function openList() {
    if (!props.disabled) {
        isOpen.value = true;
        scrollToActive();
    }
}
function close() {
    isOpen.value = false;
}
function toggle() {
    isOpen.value ? close() : openList();
}

const onOutside = (event) => {
    if (rootRef.value && !rootRef.value.contains(event.target)) {
        close();
    }
};
onMounted(() => document.addEventListener("mousedown", onOutside));
onBeforeUnmount(() => document.removeEventListener("mousedown", onOutside));
</script>
