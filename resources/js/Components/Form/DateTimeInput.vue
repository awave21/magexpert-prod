<template>
    <div>
        <span v-if="label" class="mb-1 block text-sm font-medium text-zinc-900 dark:text-white">{{ label }}</span>
        <div class="grid grid-cols-[minmax(0,1fr)_8rem] gap-2">
            <DateInput :id="id" :model-value="date" :disabled="disabled" @update:model-value="setDate" />
            <TimeInput :model-value="time" :disabled="disabled" @update:model-value="setTime" />
        </div>
        <p v-if="error" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </div>
</template>

<script setup>
import { computed } from "vue";
import DateInput from "@/Components/Form/DateInput.vue";
import TimeInput from "@/Components/Form/TimeInput.vue";

// Дата и время одним значением «ГГГГ-ММ-ДДTЧЧ:ММ», как у datetime-local.
const props = defineProps({
    modelValue: { type: String, default: "" },
    id: { type: String, default: undefined },
    label: { type: String, default: "" },
    error: { type: String, default: "" },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(["update:modelValue"]);

const date = computed(() => (props.modelValue ?? "").slice(0, 10));
const time = computed(() => (props.modelValue ?? "").slice(11, 16));

function setDate(value) {
    if (!value) {
        emit("update:modelValue", "");
        return;
    }
    emit("update:modelValue", `${value}T${time.value || "00:00"}`);
}

function setTime(value) {
    if (!date.value) {
        return;
    }
    emit("update:modelValue", `${date.value}T${value || "00:00"}`);
}
</script>
