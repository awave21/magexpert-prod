<script setup>
defineProps({
    value: {
        type: String,
    },
    required: {
        type: Boolean,
        default: false,
    },
    class: {
        type: String,
        default: '',
    }
});
</script>

<template>
    <label class="block text-sm font-medium text-zinc-800 dark:text-zinc-200">
        <span v-if="value">
            {{ value }}
            <span v-if="required" class="ml-1 text-brandcoral">*</span>
        </span>
        <span v-else><slot /></span>
    </label>
</template>
