<script setup>
defineProps({
    type: {
        type: String,
        default: 'submit',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    class: {
        type: String,
        default: '',
    },
    processing: {
        type: Boolean,
        default: false,
    }
});
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || processing"
        class="inline-flex cursor-pointer items-center justify-center rounded-lg border border-transparent bg-brandblue px-4 py-2 text-sm font-medium text-white transition-all duration-200 hover:bg-brandblue/90 hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-brandblue/20 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-brandblue dark:hover:bg-brandblue/80 dark:focus:ring-offset-zinc-800"
        :class="{ 'cursor-wait opacity-80': processing }"
    >
        <div v-if="processing" class="mr-2">
            <svg class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        </div>
        <slot />
    </button>
</template>
