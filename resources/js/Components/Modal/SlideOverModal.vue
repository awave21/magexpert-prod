<template>
  <Teleport to="body">
    <Transition
      enter-active-class="duration-300 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="duration-200 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div 
        v-if="show" 
        class="fixed inset-0 z-40 bg-zinc-950/50 backdrop-blur-sm"
      >
        <Transition
          enter-active-class="transform transition duration-300 ease-in-out"
          enter-from-class="translate-x-full"
          enter-to-class="translate-x-0"
          leave-active-class="transform transition duration-200 ease-in-out"
          leave-from-class="translate-x-0"
          leave-to-class="translate-x-full"
        >
          <div 
            v-if="show"
            class="fixed inset-y-0 right-0 w-full max-w-4xl overflow-hidden bg-white shadow-xl dark:bg-zinc-900"
            @click.stop
          >
            <div class="flex h-full flex-col">
              <!-- Заголовок -->
              <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-4 dark:border-zinc-800">
                <h3 class="text-xl font-medium text-zinc-900 dark:text-white">
                  <slot name="title">Заголовок</slot>
                </h3>
                <button 
                  @click="closeModal" 
                  class="cursor-pointer rounded-lg p-1.5 text-zinc-400 transition-all duration-200 hover:bg-zinc-200 hover:text-zinc-900 hover:scale-110 dark:text-zinc-500 dark:hover:bg-zinc-700 dark:hover:text-white"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-5">
                    <path fill-rule="evenodd" d="M5.47 5.47a.75.75 0 0 1 1.06 0L12 10.94l5.47-5.47a.75.75 0 1 1 1.06 1.06L13.06 12l5.47 5.47a.75.75 0 1 1-1.06 1.06L12 13.06l-5.47 5.47a.75.75 0 0 1-1.06-1.06L10.94 12 5.47 6.53a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                  </svg>
                </button>
              </div>

              <!-- Содержимое -->
              <div class="flex-1 overflow-y-auto px-6 py-6">
                <slot></slot>
              </div>

              <!-- Футер с кнопками -->
              <div class="border-t border-zinc-200 px-6 py-4 dark:border-zinc-800">
                <slot name="footer"></slot>
              </div>
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { Transition, Teleport, nextTick } from 'vue';

defineProps({
  show: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['close']);

const closeModal = () => {
  emit('close');
};
</script> 