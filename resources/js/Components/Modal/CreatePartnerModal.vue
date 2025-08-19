<template>
  <SlideOverModal :show="show" @close="closeModal">
    <template #title>{{ isEdit ? 'Редактирование партнера' : 'Добавление нового партнера' }}</template>

    <form @submit.prevent="submitForm" class="mt-6 space-y-6">
      <TextInput
        id="name"
        label="Название"
        v-model="form.name"
        :error="form.errors.name"
        required
        autofocus
        placeholder="Введите название партнера"
      />

      <TextareaInput
        id="description"
        label="Описание"
        v-model="form.description"
        :error="form.errors.description"
        rows="3"
        placeholder="Введите описание партнера"
      />

      <TextInput
        id="website_url"
        label="URL сайта"
        type="url"
        v-model="form.website_url"
        :error="form.errors.website_url"
        placeholder="https://example.com"
      />

      <ImageUpload
        label="Логотип"
        v-model="form.logo"
        v-model:delete-photo="form.delete_logo"
        :error="form.errors.logo"
        :help-text="'Рекомендуемый размер: 300x300 пикселей. Максимальный размер: 2MB.'"
      />
    </form>

    <template #footer>
      <div class="flex items-center justify-end gap-3">
        <SecondaryButton type="button" @click="closeModal" :disabled="form.processing">
          Отмена
        </SecondaryButton>
        <PrimaryButton type="submit" @click="submitForm" :processing="form.processing">
          {{ isEdit ? 'Сохранить' : 'Создать' }}
        </PrimaryButton>
      </div>
    </template>
  </SlideOverModal>
</template>

<script setup>
import { computed, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import SlideOverModal from '@/Components/Modal/SlideOverModal.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import TextareaInput from '@/Components/Form/TextareaInput.vue';
import ImageUpload from '@/Components/Form/ImageUpload.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
  show: Boolean,
  partner: Object,
});

const emit = defineEmits(['close', 'created', 'updated']);

const isEdit = computed(() => !!props.partner);

const form = useForm({
  name: '',
  description: '',
  website_url: '',
  logo: null,
  delete_logo: false,
  _method: 'POST',
});

// Инициализация формы при открытии модального окна
watch(() => props.show, (value) => {
  if (value && props.partner) {
    form.name = props.partner.name || '';
    form.description = props.partner.description || '';
    form.website_url = props.partner.website_url || '';
    form.logo = props.partner.logo_url || null;
    form._method = 'PUT';
    form.delete_logo = false;
  } else if (value) {
    resetForm();
    form._method = 'POST';
  }
}, { immediate: true });

// Сброс формы
const resetForm = () => {
  form.reset();
  form.clearErrors();
};

// Отправка формы
const submitForm = () => {
  const url = isEdit.value
    ? route('admin.partners.update', props.partner.id)
    : route('admin.partners.store');

  const options = {
    preserveScroll: true,
    onSuccess: () => {
      closeModal();
      emit(isEdit.value ? 'updated' : 'created');
    },
    onError: (errors) => {
      form.errors = errors;
    },
  };

  if (isEdit.value) {
    // Используем router.post для корректной отправки multipart/form-data с методом PUT
    router.post(url, {
      ...form.data(),
      _method: 'PUT',
    }, options);
  } else {
    form.post(url, options);
  }
};

// Закрытие модального окна
const closeModal = () => {
  resetForm();
  emit('close');
};
</script> 