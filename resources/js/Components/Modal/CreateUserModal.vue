<template>
  <SlideOverModal :show="show" @close="closeModal">
    <template #title>Создание нового пользователя</template>
    
    <form @submit.prevent="createUser" class="space-y-5">
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
        :error="form.errors.avatar"
      />
      
      <!-- Пароль -->
      <TextInput
        id="password"
        v-model="form.password"
        type="password"
        label="Пароль"
        required
        placeholder="Введите пароль"
        :error="form.errors.password"
      />
      
      <!-- Подтверждение пароля -->
      <TextInput
        id="password_confirmation"
        v-model="form.password_confirmation"
        type="password"
        label="Подтверждение пароля"
        required
        placeholder="Повторите пароль"
      />
      
      <!-- Роли пользователя (только для админов) -->
      <div v-if="isAdmin">
        <label class="block text-sm font-medium text-zinc-900 dark:text-white">Роли</label>
        <div class="mt-2 space-y-2">
          <div v-for="role in roles" :key="role.id" class="flex items-center">
            <CheckboxInput
              :id="`role-${role.id}`"
              v-model="form.roles"
              :value="role.name"
              :label="role.name"
            />
          </div>
        </div>
        <p v-if="form.errors.roles" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.roles }}</p>
      </div>
    </form>

    <template #footer>
      <div class="flex justify-end gap-3">
        <SecondaryButton @click="closeModal">
          Отмена
        </SecondaryButton>
        <PrimaryButton @click="createUser" :processing="form.processing">
          Создать пользователя
        </PrimaryButton>
      </div>
    </template>
  </SlideOverModal>
</template>

<script setup>
import { ref } from 'vue';
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
  roles: {
    type: Array,
    default: () => []
  },
  isAdmin: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['close', 'created']);
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
  password: '',
  password_confirmation: '',
  roles: []
});

const closeModal = () => {
  resetForm();
  emit('close');
};

const resetForm = () => {
  form.reset();
  form.clearErrors();
};

const createUser = () => {
  form.post(route('admin.users.store'), {
    preserveScroll: true,
    onSuccess: () => {
      toast.success('Пользователь успешно создан');
      closeModal();
      emit('created');
    },
    onError: (errors) => {
      toast.error('Ошибка при создании пользователя');
    }
  });
};
</script> 