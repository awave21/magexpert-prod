<template>
  <SlideOverModal :show="show" @close="closeModal">
    <template #title>Редактирование пользователя</template>
    
    <form @submit.prevent="submit" class="space-y-5">
      <!-- Фамилия пользователя -->
      <TextInput
        id="last_name"
        v-model="form.last_name"
        label="Фамилия"
        required
        placeholder="Введите фамилию"
        :error="form.errors.last_name"
      />
      
      <!-- Имя пользователя -->
      <TextInput
        id="first_name"
        v-model="form.first_name"
        label="Имя"
        required
        placeholder="Введите имя"
        :error="form.errors.first_name"
      />
      
      <!-- Отчество пользователя -->
      <TextInput
        id="middle_name"
        v-model="form.middle_name"
        label="Отчество"
        placeholder="Введите отчество (при наличии)"
        :error="form.errors.middle_name"
      />
      
      <!-- Email пользователя -->
      <TextInput
        id="email"
        v-model="form.email"
        type="email"
        label="Email"
        required
        placeholder="user@example.com"
        :error="form.errors.email"
      />
      
      <!-- Организация -->
      <TextInput
        id="company"
        v-model="form.company"
        label="Организация"
        placeholder="Название организации"
        :error="form.errors.company"
      />
      
      <!-- Должность -->
      <TextInput
        id="position"
        v-model="form.position"
        label="Должность"
        placeholder="Должность в организации"
        :error="form.errors.position"
      />
      
      <!-- Город -->
      <TextInput
        id="city"
        v-model="form.city"
        label="Город"
        placeholder="Город проживания"
        :error="form.errors.city"
      />
      
      <!-- Телефон -->
      <PhoneInput
        id="phone"
        v-model="form.phone"
        label="Телефон"
        placeholder="(999) 123-45-67"
        :error="form.errors.phone"
      />
      
      <!-- Аватар пользователя -->
      <ImageUpload
        label="Фотография профиля"
        v-model="form.avatar"
        v-model:deletePhoto="form.delete_avatar"
        :error="form.errors.avatar"
      />
      
      <!-- Роли пользователя (больше не отображаются в этом окне) -->
    </form>

    <template #footer>
      <div class="flex justify-end gap-3">
        <SecondaryButton @click="closeModal">
          Отмена
        </SecondaryButton>
        <PrimaryButton @click="submit" :processing="form.processing">
          Сохранить изменения
        </PrimaryButton>
      </div>
    </template>
  </SlideOverModal>
</template>

<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useToast } from 'vue-toastification';
import SlideOverModal from '@/Components/Modal/SlideOverModal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import PhoneInput from '@/Components/Form/PhoneInput.vue';
import CheckboxInput from '@/Components/Form/CheckboxInput.vue';
import ImageUpload from '@/Components/Form/ImageUpload.vue';

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  user: Object
});

const emit = defineEmits(['close', 'update']);
const toast = useToast();

const form = useForm({
  first_name: '',
  last_name: '',
  middle_name: '',
  email: '',
  company: '',
  position: '',
  city: '',
  phone: '',
  avatar: null,
  delete_avatar: false,
});

// Обновляем данные формы при изменении пользователя
watch(() => props.user, (newUser) => {
  if (newUser) {
    form.first_name = newUser.first_name || '';
    form.last_name = newUser.last_name || '';
    form.middle_name = newUser.middle_name || '';
    form.email = newUser.email || '';
    form.company = newUser.company || '';
    form.position = newUser.position || '';
    form.city = newUser.city || '';
    form.phone = newUser.phone || '';
    form.avatar = newUser.avatar || null;
    form.delete_avatar = false;
    
    // Роли больше не редактируются в этом окне
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

const submit = () => {
  // Проверяем данные перед отправкой
  console.log('Данные формы перед отправкой:', {
    first_name: form.first_name,
    last_name: form.last_name,
    email: form.email,
    // другие поля
  });

  // Используем FormData для отправки файлов
  form.transform((data) => {
    const formData = new FormData();
    
    // Добавляем метод PUT для Laravel (важно для правильной обработки FormData)
    formData.append('_method', 'PUT');
    
    // Добавляем все текстовые поля
    Object.keys(data).forEach(key => {
      if (key !== 'avatar') {
        formData.append(key, data[key] === null ? '' : data[key]);
      }
    });
    
    // Добавляем файл аватара, если он есть
    if (data.avatar instanceof File) {
      formData.append('avatar', data.avatar);
    }
    
    // Выводим содержимое FormData для отладки
    console.log('FormData entries:');
    for (let pair of formData.entries()) {
      console.log(pair[0] + ': ' + pair[1]);
    }
    
    return formData;
  });
  
  // Используем post вместо put при отправке FormData
  form.post(route('admin.users.update', props.user.id), {
    preserveScroll: true,
    onSuccess: () => {
      toast.success('Пользователь успешно обновлен');
      emit('update');
      closeModal();
    },
    onError: (errors) => {
      console.error('Ошибки валидации:', errors);
      
      // Показываем подробные сообщения об ошибках
      if (errors.first_name) toast.error(`Имя: ${errors.first_name}`);
      if (errors.last_name) toast.error(`Фамилия: ${errors.last_name}`);
      if (errors.email) toast.error(`Email: ${errors.email}`);
      
      // Общее сообщение об ошибке
      toast.error('Ошибка при обновлении пользователя. Проверьте форму на наличие ошибок.');
    }
  });
};
</script> 