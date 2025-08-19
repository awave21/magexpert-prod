<template>
  <a 
    :href="href" 
    target="_blank" 
    rel="noopener" 
    :class="[
      'text-gray-500 transition-colors', 
      hoverColorClass
    ]" 
    :aria-label="label"
  >
    <slot></slot>
  </a>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  href: {
    type: String,
    required: true
  },
  label: {
    type: String,
    required: true
  },
  hoverColor: {
    type: String,
    default: 'brandblue' // По умолчанию используем цвет бренда
  }
});

// Вычисляем класс для цвета при наведении
const hoverColorClass = computed(() => {
  const colors = {
    red: 'hover:text-red-600',
    blue: 'hover:text-blue-500',
    vkblue: 'hover:text-blue-600',
    green: 'hover:text-green-500',
    yellow: 'hover:text-yellow-500',
    brandblue: 'hover:text-brandblue',
    brandcoral: 'hover:text-brandcoral'
  };
  
  return colors[props.hoverColor] || 'hover:text-brandblue';
});
</script> 