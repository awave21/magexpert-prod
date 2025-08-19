<template>
  <SlideOverModal :show="show" @close="closeModal">
    <template #title>{{ isEdit ? 'Редактирование материала' : 'Добавление материала в библиотеку' }}</template>
    
    <form @submit.prevent="submitForm" class="space-y-5">
      <TextInput
        id="title"
        label="Название"
        v-model="form.title"
        :error="form.errors.title"
        required
        placeholder="Введите название материала"
      />
      
      <TextareaInput
        id="description"
        label="Описание"
        v-model="form.description"
        :error="form.errors.description"
        rows="4"
        placeholder="Введите описание материала"
      />
      
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <TextInput 
          id="publication_date" 
          label="Дата публикации" 
          v-model="form.publication_date" 
          type="date" 
          :error="form.errors.publication_date" 
          required 
        />
        
        <SelectInput
          id="language"
          label="Язык материала"
          v-model="form.language"
          :options="languageOptions"
          :error="form.errors.language"
          placeholder="Выберите язык"
          required
        />
      </div>
      
      <FileUpload
        label="Файл материала"
        v-model="form.file"
        v-model:delete-file="form.delete_file"
        :error="form.errors.file"
        :current-filename="currentFilename"
        accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx"
      />
      
      <ImageUpload
        label="Изображение обложки"
        v-model="form.image"
        v-model:delete-photo="form.delete_image"
        :error="form.errors.image"
      />
    </form>

    <template #footer>
      <div class="flex justify-end gap-3">
        <SecondaryButton @click="closeModal">
          Отмена
        </SecondaryButton>
        <PrimaryButton @click="submitForm" :processing="form.processing">
          {{ isEdit ? 'Сохранить' : 'Добавить материал' }}
        </PrimaryButton>
      </div>
    </template>
  </SlideOverModal>
</template>

<script setup>
import { computed, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { useToast } from 'vue-toastification';
import SlideOverModal from '@/Components/Modal/SlideOverModal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import TextareaInput from '@/Components/Form/TextareaInput.vue';
import SelectInput from '@/Components/Form/SelectInput.vue';
import ImageUpload from '@/Components/Form/ImageUpload.vue';
import FileUpload from '@/Components/Form/FileUpload.vue';

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  library: {
    type: Object,
    default: null
  }
});

const emit = defineEmits(['close', 'created', 'updated']);
const toast = useToast();

const isEdit = computed(() => !!props.library);

const form = useForm({
  title: '',
  description: '',
  publication_date: '',
  language: 'ru',
  file: null,
  image: null,
  delete_file: false,
  delete_image: false,
  _method: 'POST'
});

const languageOptions = [
  { value: 'ru', text: 'Русский' },
  { value: 'en', text: 'Английский' },
];

const currentFilename = computed(() => {
  if (!props.library || !props.library.file_path) return '';
  return props.library.file_path.split('/').pop();
});

watch(() => props.library, (newLibrary) => {
  if (newLibrary) {
    form.title = newLibrary.title || '';
    form.description = newLibrary.description || '';
    form.publication_date = newLibrary.publication_date ? new Date(newLibrary.publication_date).toISOString().split('T')[0] : '';
    form.language = newLibrary.language || 'ru';
    form.image = newLibrary.image_url || null;
    form._method = 'PUT';
  } else {
    resetForm();
    form._method = 'POST';
  }
}, { immediate: true });

const closeModal = () => {
  resetForm();
  emit('close');
};

const resetForm = () => {
  form.reset();
  form.clearErrors();
};

const submitForm = () => {
  const url = isEdit.value 
    ? route('admin.medical-library.update', props.library.id) 
    : route('admin.medical-library.store');
  
  const data = {
    ...form.data(),
  };

  if (isEdit.value) {
    data.delete_file = form.delete_file ? 1 : 0;
    data.delete_image = form.delete_image ? 1 : 0;
  }
  
  router.post(url, data, {
    forceFormData: true,
    onSuccess: () => {
      closeModal();
      const message = isEdit.value ? 'Материал успешно обновлен' : 'Материал успешно добавлен';
      toast.success(message);
      emit(isEdit.value ? 'updated' : 'created');
    },
    onError: (errors) => {
      form.errors = errors;
      toast.error('Пожалуйста, исправьте ошибки в форме');
    }
  });
};
</script> 