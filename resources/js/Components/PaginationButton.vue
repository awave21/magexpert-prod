<template>
  <Link 
    v-if="href"
    :href="href"
    :class="buttonClasses"
    :title="title"
  >
    <slot></slot>
  </Link>
  <span 
    v-else
    :class="buttonClasses"
    :title="title"
  >
    <slot></slot>
  </span>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
  href: {
    type: String,
    default: null
  },
  active: {
    type: Boolean,
    default: false
  },
  disabled: {
    type: Boolean,
    default: false
  },
  iconOnly: {
    type: Boolean,
    default: false
  },
  title: {
    type: String,
    default: ''
  }
});

const buttonClasses = computed(() => {
  const classes = [
    'flex',
    'h-8',
    'items-center',
    'justify-center',
    'rounded-lg',
    'font-medium',
    'text-sm',
    'transition-colors',
    'duration-150',
    'focus:outline-none',
    'focus:ring-2',
    'focus:ring-zinc-500/20'
  ];

  if (props.iconOnly) {
    classes.push('min-w-8', 'px-1.5');
  } else {
    classes.push('px-3');
  }

  if (props.active) {
    classes.push(
      'bg-zinc-900',
      'text-white',
      'dark:bg-white',
      'dark:text-zinc-900',
      'shadow-sm'
    );
  } else if (props.disabled) {
    classes.push(
      'cursor-not-allowed',
      'bg-zinc-100',
      'text-zinc-400',
      'dark:bg-zinc-800/50',
      'dark:text-zinc-600'
    );
  } else {
    classes.push(
      'text-zinc-700',
      'hover:bg-zinc-100',
      'hover:text-zinc-900',
      'dark:text-zinc-300',
      'dark:hover:bg-zinc-800',
      'dark:hover:text-white'
    );
  }

  return classes;
});
</script> 