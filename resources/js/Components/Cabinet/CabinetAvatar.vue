<script setup>
import { computed } from 'vue';

const props = defineProps({
    user: { type: Object, required: true },
    size: { type: Number, default: 44 },
});

const initials = computed(() => `${props.user.first_name?.[0] ?? ''}${props.user.last_name?.[0] ?? ''}`.toUpperCase() || '·');
</script>

<template>
    <span
        class="flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-brandblue-soft font-display font-medium text-brandblue-dark"
        :style="{ width: `${size}px`, height: `${size}px`, fontSize: `${Math.round(size * 0.38)}px` }"
    >
        <img v-if="user.avatar" :src="user.avatar" alt="" class="h-full w-full object-cover" />
        <template v-else>{{ initials }}</template>
    </span>
</template>
