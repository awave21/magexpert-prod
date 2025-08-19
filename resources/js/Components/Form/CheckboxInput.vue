<template>
  <div class="flex items-center">
    <input
      :id="id"
      :checked="modelValue"
      @change="$emit('update:modelValue', $event.target.checked)"
      type="checkbox"
      class="size-4 rounded border-zinc-300 bg-white text-brandblue shadow-sm focus:border-brandblue focus:ring-2 focus:ring-brandblue/20 focus:ring-offset-0 dark:border-zinc-700 dark:bg-zinc-800 dark:text-brandblue dark:focus:ring-brandblue/50"
    />
    <label :for="id" class="ml-2 block text-sm text-zinc-900 dark:text-white">
      {{ label }}
    </label>
  </div>
</template>

<script setup>
import { defineProps, defineEmits } from 'vue';

const props = defineProps({
  id: {
    type: String,
    required: true,
  },
  label: {
    type: String,
    required: true,
  },
  modelValue: {
    type: Boolean,
    default: false,
  },
});

defineEmits(['update:modelValue']);
</script> 