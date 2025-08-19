<template>
  <div>
    <label :for="id" class="block text-sm font-medium text-zinc-900 dark:text-white">{{ label }}</label>
    <div class="mt-1">
      <textarea
        :id="id"
        :value="modelValue"
        @input="$emit('update:modelValue', $event.target.value)"
        :rows="rows"
        class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-2 text-zinc-900 placeholder-zinc-500 focus:border-brandblue focus:outline-none focus:ring-2 focus:ring-brandblue/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-400"
        :placeholder="placeholder"
      ></textarea>
    </div>
    <p v-if="error" class="mt-1 text-sm text-brandcoral dark:text-brandcoral">{{ error }}</p>
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