<template>
  <SlideOverModal :show="show" @close="closeModal">
    <template #title>{{ isEdit ? 'Редактирование категории' : 'Создание новой категории' }}</template>
    
    <form @submit.prevent="submitForm" class="space-y-5">
      <TextInput
        id="name"
        label="Название"
        v-model="form.name"
        :error="form.errors.name"
        required
        placeholder="Введите название категории"
      />
      
      <TextInput
        id="slug"
        label="URL-адрес"
        v-model="form.slug"
        :error="form.errors.slug"
        placeholder="Оставьте пустым для автоматической генерации"
      >
        <template #hint>
          Используется в URL. Если оставить пустым, будет сгенерирован автоматически.
        </template>
      </TextInput>
      
      <TextareaInput
        id="description"
        label="Описание"
        v-model="form.description"
        :error="form.errors.description"
        rows="4"
        placeholder="Введите описание категории"
      />
      
      <TextInput
        id="sort_order"
        label="Порядок сортировки"
        type="number"
        min="0"
        v-model="form.sort_order"
        :error="form.errors.sort_order"
        placeholder="0"
      />
      
      <CheckboxInput id="is_active" label="Активная категория" v-model="form.is_active" />
    </form>

    <template #footer>
      <div class="flex justify-end gap-3">
        <SecondaryButton @click="closeModal">
          Отмена
        </SecondaryButton>
        <PrimaryButton @click="submitForm" :processing="form.processing">
          {{ isEdit ? 'Сохранить' : 'Создать категорию' }}
        </PrimaryButton>
      </div>
    </template>
  </SlideOverModal>
</template>

<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useToast } from 'vue-toastification';
import SlideOverModal from '@/Components/Modal/SlideOverModal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import TextareaInput from '@/Components/Form/TextareaInput.vue';
import CheckboxInput from '@/Components/Form/CheckboxInput.vue';

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  category: {
    type: Object,
    default: null
  }
});

const emit = defineEmits(['close', 'created', 'updated']);
const toast = useToast();

const isEdit = computed(() => !!props.category);

const form = useForm({
  name: '',
  slug: '',
  description: '',
  is_active: true,
  sort_order: 0,
  _method: 'POST'
});

watch(() => props.category, (newCategory) => {
  if (newCategory) {
    form.name = newCategory.name || '';
    form.slug = newCategory.slug || '';
    form.description = newCategory.description || '';
    form.is_active = newCategory.is_active ?? true;
    form.sort_order = newCategory.sort_order || 0;
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
    ? route('admin.categories.update', props.category.id) 
    : route('admin.categories.store');
  
  form.post(url, {
    onSuccess: () => {
      closeModal();
      const message = isEdit.value ? 'Категория успешно обновлена' : 'Категория успешно создана';
      toast.success(message);
      emit(isEdit.value ? 'updated' : 'created');
    },
    onError: () => {
      toast.error('Пожалуйста, исправьте ошибки в форме');
    }
  });
};
</script> 