<template>
  <div>
    <label :for="id" class="block text-sm font-medium text-zinc-900 dark:text-white">{{ label }}</label>
    <div class="mt-1">
      <textarea
        :id="id"
        :value="modelValue"
        @input="$emit('update:modelValue', $event.target.value)"
        :rows="rows"
        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 transition-colors hover:border-zinc-400 focus:border-brandblue focus:outline-none focus:ring-1 focus:ring-brandblue disabled:cursor-not-allowed disabled:bg-zinc-100 disabled:text-zinc-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:hover:border-zinc-500 dark:focus:border-brandblue dark:focus:ring-brandblue dark:disabled:bg-zinc-900"
        :placeholder="placeholder"
      ></textarea>
    </div>
    <p v-if="error" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
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
    type: String,
    default: '',
  },
  rows: {
    type: Number,
    default: 3,
  },
  placeholder: {
    type: String,
    default: '',
  },
  error: {
    type: String,
    default: '',
  },
});

defineEmits(['update:modelValue']);
</script> 