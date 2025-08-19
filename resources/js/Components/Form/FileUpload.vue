<template>
  <div>
    <label class="block text-sm font-medium text-zinc-900 dark:text-white">{{ label }}</label>
    <div class="mt-1">
      <div v-if="currentFilename" class="mb-3 flex items-center space-x-2">
        <DocumentIcon class="h-5 w-5 text-zinc-500 dark:text-zinc-400" />
        <span class="text-sm text-zinc-900 dark:text-white">{{ currentFilename }}</span>
      </div>
      
      <input
        :id="id"
        ref="fileInput"
        type="file"
        class="hidden"
        :accept="accept"
        @change="handleFileChange"
      />
      
      <div class="flex items-center gap-3">
        <button
          type="button"
          @click="$refs.fileInput.click()"
          class="inline-flex items-center px-4 py-2 border border-zinc-300 rounded-lg shadow-sm text-zinc-900 bg-white hover:bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-brandblue/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:hover:bg-zinc-700"
        >
          <ArrowUpTrayIcon class="mr-2 h-4 w-4" />
          {{ modelValue || currentFilename ? 'Изменить файл' : 'Выбрать файл' }}
        </button>
        
        <button
          v-if="modelValue || currentFilename"
          type="button"
          @click="removeExistingFile"
          class="inline-flex items-center px-4 py-2 border border-brandcoral rounded-lg shadow-sm text-brandcoral bg-white hover:bg-brandcoral/10 focus:outline-none focus:ring-2 focus:ring-brandcoral/20 dark:border-brandcoral dark:bg-zinc-800 dark:text-brandcoral dark:hover:bg-zinc-700"
        >
          <XMarkIcon class="mr-2 h-4 w-4" />
          Удалить файл
        </button>
      </div>
    </div>
    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
      PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX (до 20MB)
    </p>
    <p v-if="error" class="mt-1 text-sm text-brandcoral dark:text-brandcoral">{{ error }}</p>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { DocumentIcon, XMarkIcon, ArrowUpTrayIcon } from '@heroicons/vue/24/outline';
import { useToast } from 'vue-toastification';

const props = defineProps({
  modelValue: {
    type: [File, null],
    default: null
  },
  deleteFile: {
    type: Boolean,
    default: false
  },
  label: {
    type: String,
    required: true
  },
  id: {
    type: String,
    default: () => `file-upload-${Math.random().toString(36).substring(2, 9)}`
  },
  error: {
    type: String,
    default: ''
  },
  required: {
    type: Boolean,
    default: false
  },
  accept: {
    type: String,
    default: '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx'
  },
  currentFilename: {
    type: String,
    default: ''
  },
  maxSize: { // в МБ
    type: Number,
    default: 20,
  }
});

const emit = defineEmits(['update:modelValue', 'update:deleteFile']);
const toast = useToast();
const fileInput = ref(null);

const getDisplayFilename = computed(() => {
  if (!props.modelValue) return '';
  return props.modelValue.name;
});

const handleFileChange = (event) => {
  const file = event.target.files[0];
  if (!file) return;
  
  // Проверка размера файла
  if (file.size > props.maxSize * 1024 * 1024) {
    toast.error(`Размер файла превышает ${props.maxSize}MB`);
    if (fileInput.value) {
      fileInput.value.value = '';
    }
    return;
  }
  
  emit('update:modelValue', file);
  emit('update:deleteFile', false);
};

const removeExistingFile = () => {
  emit('update:modelValue', null);
  emit('update:deleteFile', true);
  if (fileInput.value) {
    fileInput.value.value = '';
  }
};
</script> 