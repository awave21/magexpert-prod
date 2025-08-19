<template>
  <Listbox as="div" :model-value="modelValue" @update:model-value="value => emit('update:modelValue', value)">
    <ListboxLabel v-if="label" class="block text-sm font-medium text-zinc-900 dark:text-white">{{ label }}</ListboxLabel>
    <div class="relative mt-1">
      <ListboxButton
        class="relative w-full cursor-default rounded-lg border border-zinc-300 bg-white py-2 pl-3 pr-10 text-left text-zinc-900 placeholder-zinc-500 focus:border-brandblue focus:outline-none focus:ring-2 focus:ring-brandblue/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-400"
      >
        <span class="flex items-center">
          <img v-if="selectedOption?.image" :src="selectedOption.image" alt="" class="size-6 shrink-0 rounded-full" />
          <span class="ml-3 block truncate" :class="{ 'ml-0': !selectedOption?.image }">
            {{ selectedOption?.text || placeholder || 'Выберите значение' }}
          </span>
        </span>
        <span class="pointer-events-none absolute inset-y-0 right-0 ml-3 flex items-center pr-2">
          <ChevronUpDownIcon class="size-5 text-gray-400" aria-hidden="true" />
        </span>
      </ListboxButton>

      <transition leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <ListboxOptions
          class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black/5 focus:outline-none dark:bg-zinc-800 dark:ring-white/10 sm:text-sm"
        >
          <ListboxOption as="template" v-for="option in options" :key="option.value" :value="option.value" v-slot="{ active, selected }">
            <li :class="[active ? 'bg-brandblue/10 dark:bg-brandblue/20' : '', 'relative cursor-default select-none py-2 pl-3 pr-9 text-zinc-900 dark:text-white']">
              <div class="flex items-center">
                <img v-if="option.image" :src="option.image" alt="" class="size-6 shrink-0 rounded-full" />
                <span :class="[selected ? 'font-semibold' : 'font-normal', 'ml-3 block truncate', {'ml-0': !option.image}]">{{ option.text }}</span>
              </div>

              <span v-if="selected" class="absolute inset-y-0 right-0 flex items-center pr-4 text-brandblue dark:text-brandblue">
                <CheckIcon class="h-5 w-5" aria-hidden="true" />
              </span>
            </li>
          </ListboxOption>
        </ListboxOptions>
      </transition>
    </div>
    <p v-if="error" class="mt-1 text-sm text-brandcoral dark:text-brandcoral">{{ error }}</p>
  </Listbox>
</template>

<script setup>
import { computed } from 'vue';
import { Listbox, ListboxButton, ListboxLabel, ListboxOption, ListboxOptions } from '@headlessui/vue';
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';

const props = defineProps({
  modelValue: {
    type: [String, Number, Boolean],
    default: '',
  },
  options: {
    type: Array,
    required: true,
    validator: (value) => value.every(opt => typeof opt === 'object' && 'value' in opt && 'text' in opt),
  },
  label: {
    type: String,
    default: '',
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

const emit = defineEmits(['update:modelValue']);

const selectedOption = computed(() => {
  return props.options.find(opt => opt.value === props.modelValue);
});
</script> 