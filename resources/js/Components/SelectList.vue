<template>
  <Listbox as="div" v-model="selectedValue" :disabled="disabled">
    <ListboxLabel v-if="label" class="block text-sm font-medium text-zinc-900 dark:text-white mb-1">{{ label }}</ListboxLabel>
    <div class="relative">
      <ListboxButton class="relative w-full cursor-default rounded-lg border border-zinc-300 bg-white py-2 pl-3 pr-10 text-left shadow-sm text-zinc-900 focus:border-zinc-500 focus:outline-none focus:ring-2 focus:ring-zinc-500/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:hover:border-zinc-600 transition-colors duration-150 ease-in-out hover:border-zinc-400">
        <span v-if="selectedOption" class="flex items-center gap-2">
          <img v-if="selectedOption.avatar" :src="selectedOption.avatar" alt="" class="size-5 shrink-0 rounded-full" />
          <span class="block truncate">{{ selectedOption.label || selectedOption.name }}</span>
        </span>
        <span v-else class="block truncate text-zinc-500 dark:text-zinc-400">{{ placeholder }}</span>
        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-5 text-zinc-400" aria-hidden="true">
            <path fill-rule="evenodd" d="M12.53 16.28a.75.75 0 0 1-1.06 0l-7.5-7.5a.75.75 0 0 1 1.06-1.06L12 14.69l6.97-6.97a.75.75 0 1 1 1.06 1.06l-7.5 7.5Z" clip-rule="evenodd" />
          </svg>
        </span>
      </ListboxButton>

      <transition 
        leave-active-class="transition ease-in duration-100" 
        leave-from-class="opacity-100" 
        leave-to-class="opacity-0"
        enter-active-class="transition ease-out duration-100"
        enter-from-class="opacity-0 translate-y-1"
        enter-to-class="opacity-100 translate-y-0"
      >
        <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg bg-white py-1 text-base shadow-lg ring-1 ring-black/5 focus:outline-none dark:bg-zinc-800 dark:ring-white/10 text-sm">
          <ListboxOption 
            v-slot="{ active, selected }" 
            v-for="option in options" 
            :key="getOptionKey(option)" 
            :value="option.value !== undefined ? option.value : option"
            as="template"
          >
            <li 
              :class="[
                active ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-700 dark:text-white' : 'text-zinc-900 dark:text-white',
                'relative cursor-default select-none py-2 pl-3 pr-9'
              ]"
            >
              <div class="flex items-center gap-2">
                <img v-if="option.avatar" :src="option.avatar" alt="" class="size-5 shrink-0 rounded-full" />
                <span :class="[selected ? 'font-medium' : 'font-normal', 'block truncate']">
                  {{ option.label || option.name }}
                </span>
              </div>

              <span 
                v-if="selected" 
                :class="[
                  'absolute inset-y-0 right-0 flex items-center pr-3',
                  active ? 'text-zinc-900 dark:text-white' : 'text-zinc-600 dark:text-zinc-400'
                ]"
              >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-5" aria-hidden="true">
                  <path fill-rule="evenodd" d="M19.916 4.626a.75.75 0 0 1 .208 1.04l-9 13.5a.75.75 0 0 1-1.154.114l-6-6a.75.75 0 0 1 1.06-1.06l5.353 5.353 8.493-12.74a.75.75 0 0 1 1.04-.207Z" clip-rule="evenodd" />
                </svg>
              </span>
            </li>
          </ListboxOption>
        </ListboxOptions>
      </transition>
    </div>
  </Listbox>
</template>

<script setup>
import { computed } from 'vue';
import { Listbox, ListboxButton, ListboxLabel, ListboxOption, ListboxOptions } from '@headlessui/vue';

const props = defineProps({
  modelValue: {
    type: [String, Number, Object],
    default: null
  },
  options: {
    type: Array,
    required: true
  },
  label: {
    type: String,
    default: ''
  },
  placeholder: {
    type: String,
    default: 'Выберите опцию'
  },
  disabled: {
    type: Boolean,
    default: false
  },
  keyField: {
    type: String,
    default: 'id'
  }
});

const emit = defineEmits(['update:modelValue', 'change']);

const selectedValue = computed({
  get: () => props.modelValue,
  set: (value) => {
    emit('update:modelValue', value);
    emit('change', value);
  }
});

const selectedOption = computed(() => {
  if (props.modelValue === null || props.modelValue === undefined) return null;
  
  return props.options.find(option => {
    const optionValue = option.value !== undefined ? option.value : option;
    return optionValue === props.modelValue || 
           (typeof optionValue === 'object' && optionValue[props.keyField] === props.modelValue) ||
           (typeof props.modelValue === 'object' && props.modelValue[props.keyField] === optionValue[props.keyField]);
  });
});

const getOptionKey = (option) => {
  if (option.id !== undefined) return option.id;
  if (option.value !== undefined) return option.value;
  return option;
};
</script> 