<template>
  <div>
    <label class="block text-sm font-medium text-zinc-900 dark:text-white">{{ label }}</label>
    <div class="mt-1">
      <div v-if="preview" class="mb-3">
        <img :src="preview" alt="Превью фото" class="h-32 w-32 rounded-lg object-cover" />
      </div>
      
      <input
        ref="photoInput"
        type="file"
        class="hidden"
        @change="onFileChange"
        :accept="accept"
      />
      
      <div class="flex items-center gap-3">
        <button
          type="button"
          @click="$refs.photoInput.click()"
          class="inline-flex items-center px-4 py-2 border border-zinc-300 rounded-lg shadow-sm text-zinc-900 bg-white hover:bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-brandblue/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:hover:bg-zinc-700"
        >
          {{ preview ? 'Изменить фото' : 'Выбрать фото' }}
        </button>
        
        <button
          v-if="preview"
          type="button"
          @click="removePhoto"
          class="inline-flex items-center px-4 py-2 border border-brandcoral rounded-lg shadow-sm text-brandcoral bg-white hover:bg-brandcoral/10 focus:outline-none focus:ring-2 focus:ring-brandcoral/20 dark:border-brandcoral dark:bg-zinc-800 dark:text-brandcoral dark:hover:bg-zinc-700"
        >
          Удалить фото
        </button>
      </div>
    </div>
    <p v-if="hint" class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ hint }}</p>
    <p v-if="error" class="mt-1 text-sm text-brandcoral dark:text-brandcoral">{{ error }}</p>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, defineProps, defineEmits } from 'vue';
import { useToast } from 'vue-toastification';

const props = defineProps({
  label: {
    type: String,
    required: true,
  },
  modelValue: {
    type: [File, String, null],
    default: null,
  },
  deletePhoto: {
    type: Boolean,
    default: false,
  },
  error: {
    type: String,
    default: '',
  },
  hint: {
    type: String,
    default: 'Разрешенные форматы: jpg, png, gif, webp. Макс. размер: 20MB',
  },
  accept: {
    type: String,
    default: 'image/*',
  },
  maxSize: { // in MB
    type: Number,
    default: 20,
  }
});

const emit = defineEmits(['update:modelValue', 'update:deletePhoto']);
const toast = useToast();
const photoInput = ref(null);
const preview = ref(null);

// Инициализация превью при монтировании компонента
onMounted(() => {
  if (props.modelValue) {
    if (typeof props.modelValue === 'string') {
      preview.value = props.modelValue;
    } else if (props.modelValue instanceof File) {
      const reader = new FileReader();
      reader.onload = (e) => {
        preview.value = e.target.result;
      };
      reader.readAsDataURL(props.modelValue);
    }
  }
});

watch(() => props.modelValue, (newValue) => {
  if (newValue instanceof File) {
    const reader = new FileReader();
    reader.onload = (e) => {
      preview.value = e.target.result;
    };
    reader.readAsDataURL(newValue);
  } else if (typeof newValue === 'string' && newValue) {
    preview.value = newValue;
  } else {
    preview.value = null;
  }
}, { immediate: true });

const onFileChange = (e) => {
  const file = e.target.files[0];
  if (!file) return;

  const acceptedTypes = props.accept.split(',').map(t => t.trim());
  if (props.accept !== 'image/*' && !acceptedTypes.includes(file.type)) {
    toast.error('Недопустимый формат файла.');
    return;
  }
  
  if (file.size > props.maxSize * 1024 * 1024) {
    toast.error(`Размер файла превышает ${props.maxSize}MB`);
    return;
  }

  emit('update:modelValue', file);
  emit('update:deletePhoto', false);
};

const removePhoto = () => {
  emit('update:modelValue', null);
  emit('update:deletePhoto', true);
  preview.value = null;
  if (photoInput.value) {
    photoInput.value.value = '';
  }
};
</script> 