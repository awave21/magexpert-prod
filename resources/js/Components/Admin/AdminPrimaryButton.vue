<script setup>
defineProps({
    type: { type: String, default: 'submit' },
    disabled: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
});
</script>

<template>
    <!-- Основная кнопка админки: графитовая, как «Сохранить» и «Создать» на страницах -->
    <button
        :type="type"
        :disabled="disabled || processing"
        class="inline-flex cursor-pointer items-center justify-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 dark:focus-visible:ring-white dark:focus-visible:ring-offset-zinc-900"
        :class="{ 'cursor-wait': processing }"
    >
        <svg v-if="processing" class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <slot />
    </button>
</template>
