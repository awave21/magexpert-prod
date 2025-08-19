<template>
  <SlideOverModal :show="show" @close="closeModal">
    <template #title>{{ isEdit ? 'Редактирование спикера' : 'Создание нового спикера' }}</template>
    
    <form @submit.prevent="submitForm" class="space-y-5">
      <TextInput
        id="first_name"
        label="Имя"
        v-model="form.first_name"
        :error="form.errors.first_name"
        required
        placeholder="Введите имя спикера"
      />
      
      <TextInput
        id="last_name"
        label="Фамилия"
        v-model="form.last_name"
        :error="form.errors.last_name"
        required
        placeholder="Введите фамилию спикера"
      />
      
      <TextInput
        id="middle_name"
        label="Отчество"
        v-model="form.middle_name"
        :error="form.errors.middle_name"
        placeholder="Введите отчество спикера (необязательно)"
      />
      
      <TextInput
        id="position"
        label="Должность"
        v-model="form.position"
        :error="form.errors.position"
        placeholder="Введите должность спикера"
      />
      
      <TextInput
        id="company"
        label="Компания"
        v-model="form.company"
        :error="form.errors.company"
        placeholder="Введите название компании"
      />
      
      <TextareaInput
        id="regalia"
        label="Регалии"
        v-model="form.regalia"
        :error="form.errors.regalia"
        rows="3"
        placeholder="Введите регалии и достижения спикера"
      />
      
      <TextareaInput
        id="description"
        label="Описание"
        v-model="form.description"
        :error="form.errors.description"
        rows="5"
        placeholder="Подробное описание спикера"
      />
      
      <ImageUpload
        label="Фото"
        v-model="form.photo"
        v-model:delete-photo="form.delete_photo"
        :error="form.errors.photo"
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
      
      <CheckboxInput
        id="is_active"
        label="Активный спикер"
        v-model="form.is_active"
      />
    </form>

    <template #footer>
      <div class="flex justify-end gap-3">
        <SecondaryButton @click="closeModal">
          Отмена
        </SecondaryButton>
        <PrimaryButton @click="submitForm" :processing="form.processing">
          {{ isEdit ? 'Сохранить' : 'Создать спикера' }}
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
import ImageUpload from '@/Components/Form/ImageUpload.vue';

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  speaker: {
    type: Object,
    default: null
  }
});

const emit = defineEmits(['close', 'created', 'updated']);
const toast = useToast();

const isEdit = computed(() => !!props.speaker);

const form = useForm({
  first_name: '',
  last_name: '',
  middle_name: '',
  position: '',
  company: '',
  regalia: '',
  description: '',
  photo: null,
  is_active: true,
  sort_order: 0,
  delete_photo: false,
  _method: 'POST'
});

watch(() => props.speaker, (newSpeaker) => {
  if (newSpeaker) {
    form.first_name = newSpeaker.first_name || '';
    form.last_name = newSpeaker.last_name || '';
    form.middle_name = newSpeaker.middle_name || '';
    form.position = newSpeaker.position || '';
    form.company = newSpeaker.company || '';
    form.regalia = newSpeaker.regalia || '';
    form.description = newSpeaker.description || '';
    form.is_active = newSpeaker.is_active ?? true;
    form.sort_order = newSpeaker.sort_order || 0;
    form.photo = newSpeaker.photo || null;
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
    ? route('admin.speakers.update', props.speaker.id)
    : route('admin.speakers.store');
  
  // Создаем копию данных формы для манипуляций
  const formData = new FormData();
  
  // Добавляем все поля кроме photo и delete_photo
  for (const key in form) {
    if (key !== 'photo' && key !== 'delete_photo' && key !== 'errors' && key !== 'processing' && key !== 'recentlySuccessful' && !key.startsWith('_')) {
      formData.append(key, form[key] === null ? '' : form[key]);
    }
  }
  
  // Добавляем метод запроса для редактирования
  if (isEdit.value) {
    formData.append('_method', 'PUT');
  }
  
  // Добавляем photo только если есть новое изображение
  if (form.photo instanceof File) {
    formData.append('photo', form.photo);
  } else if (form.photo === null && isEdit.value) {
    // Если редактируем и фото сброшено на null без флага удаления,
    // явно добавляем пустую строку для photo
    formData.append('photo', '');
  }
  
  // Добавляем флаг удаления фото, если он установлен
  if (form.delete_photo) {
    formData.append('delete_photo', '1');
  }
  
  // Отправляем запрос с использованием Inertia
  form.post(url, {
    preserveScroll: true,
    data: formData,
    forceFormData: true,
    onSuccess: () => {
      const message = isEdit.value ? 'Спикер успешно обновлен' : 'Спикер успешно создан';
      toast.success(message);
      closeModal();
      emit(isEdit.value ? 'updated' : 'created');
    },
    onError: () => {
      toast.error('Ошибка при сохранении спикера');
    }
  });
};
</script> 