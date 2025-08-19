<template>
  <Modal :show="show" @close="$emit('close')" :max-width="maxWidth">
    <div class="p-6">
      <div class="text-lg font-medium text-zinc-900 dark:text-white">
        {{ title }}
      </div>

      <div class="mt-4 text-sm text-zinc-600 dark:text-zinc-400">
        {{ message }}
      </div>

      <div class="mt-6 flex justify-end gap-3">
        <button
          @click="$emit('close')"
          class="inline-flex items-center rounded-lg border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
        >
          {{ cancelText }}
        </button>
        <button
          @click="$emit('confirm')"
          :class="confirmButtonClass || 'inline-flex items-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100'"
          class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium transition-colors"
        >
          {{ confirmText }}
        </button>
      </div>
    </div>
  </Modal>
</template>

<script setup>
import Modal from '@/Components/Modal.vue';

defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  title: {
    type: String,
    default: 'Подтверждение',
  },
  message: {
    type: String,
    default: 'Вы уверены, что хотите выполнить это действие?',
  },
  confirmText: {
    type: String,
    default: 'Подтвердить',
  },
  cancelText: {
    type: String,
    default: 'Отмена',
  },
  maxWidth: {
    type: String,
    default: 'md',
  },
  confirmButtonClass: {
    type: String,
    default: '',
  },
});

defineEmits(['close', 'confirm']);
</script> 