<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';

defineProps({ href: { type: String, required: true } });
const emit = defineEmits(['start']);
const loading = ref(false);

const start = (visit) => {
    loading.value = true;
    emit('start', visit);
};
</script>

<template>
    <Link
        :href="href"
        class="navigation-link"
        :aria-busy="loading || undefined"
        :aria-disabled="loading || undefined"
        :on-before="() => !loading"
        @start="start"
        @finish="loading = false"
    >
        <slot />
        <span v-if="loading" class="sr-only" role="status">Загрузка страницы</span>
    </Link>
</template>

<style scoped>
.navigation-link {
    position: relative;
    min-height: 44px;
    touch-action: manipulation;
}
.navigation-link:active {
    background-color: var(--color-brandblue-soft);
}
.navigation-link[aria-busy='true'] {
    background-color: var(--color-brandblue-soft);
    box-shadow: inset 0 2px 0 var(--color-brandblue);
}
.navigation-link[aria-busy='true']::after {
    content: '';
    position: absolute;
    top: 7px;
    right: 5px;
    width: 12px;
    height: 12px;
    border: 2px solid var(--color-brandblue);
    border-right-color: transparent;
    border-radius: 50%;
    animation: navigation-spin .7s linear infinite;
}
:global(.dark) .navigation-link:active,
:global(.dark) .navigation-link[aria-busy='true'] {
    background-color: #25354b;
}
@keyframes navigation-spin {
    to { transform: rotate(360deg); }
}
@media (prefers-reduced-motion: reduce) {
    .navigation-link[aria-busy='true']::after { animation: none; }
}
</style>
