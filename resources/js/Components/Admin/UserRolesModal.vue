<template>
  <SlideOverModal :show="show" @close="$emit('close')">
    <template #title>Управление ролями пользователя</template>
    
    <form @submit.prevent="submit" class="space-y-6">
      <div class="space-y-4">
        <div v-for="role in roles" :key="role.id" class="flex items-start gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800/60 transition-colors">
          <div class="flex h-5 items-center">
            <Checkbox
              :id="'role-' + role.id"
              :checked="selectedRoles.includes(role.name)"
              @update:checked="toggleRole(role.name)"
              class="h-4 w-4 rounded border-zinc-300 text-zinc-600 focus:ring-zinc-500 dark:border-zinc-600 dark:bg-zinc-800 dark:focus:ring-zinc-500"
            />
          </div>
          <div class="flex flex-col">
            <InputLabel :for="'role-' + role.id">
              <span 
                class="inline-flex rounded-full px-3 py-1 text-sm font-semibold"
                :class="{
                  'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300': role.name === 'admin',
                  'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300': role.name === 'manager',
                  'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300': role.name === 'editor',
                  'bg-zinc-100 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-200': role.name === 'user'
                }"
              >
                {{ role.name }}
              </span>
            </InputLabel>
            <p v-if="role.description" class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
              {{ role.description }}
            </p>
            <p v-else class="mt-1 text-sm italic text-zinc-500 dark:text-zinc-500">
              Нет описания
            </p>
          </div>
        </div>
        
        <div v-if="roles.length === 0" class="rounded-lg bg-zinc-50 p-4 text-center dark:bg-zinc-800">
          <p class="text-sm text-zinc-600 dark:text-zinc-400">Роли не найдены</p>
        </div>
      </div>
    </form>

    <template #footer>
      <div class="flex justify-end gap-3">
        <SecondaryButton @click="$emit('close')">
          Отмена
        </SecondaryButton>
        <PrimaryButton 
          @click="submit"
          :processing="form.processing"
        >
          Сохранить роли
        </PrimaryButton>
      </div>
    </template>
  </SlideOverModal>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import InputLabel from '@/Components/InputLabel.vue';
import Checkbox from '@/Components/Checkbox.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SlideOverModal from '@/Components/Modal/SlideOverModal.vue';

const props = defineProps({
  show: Boolean,
  user: Object,
  roles: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['close', 'update']);

const selectedRoles = ref([]);
const form = useForm({
  roles: []
});

watch(() => props.user, (newUser) => {
  if (newUser && newUser.roles) {
    // Получаем имена ролей из пропса user
    const roleNames = newUser.roles.map(role => role.name);
    
    // Обновляем состояние выбранных ролей
    selectedRoles.value = [...roleNames];
    
    // Явное присваивание массива ролей в форму
    form.roles = [...roleNames];
    
    console.log('Инициализированы роли пользователя:', selectedRoles.value);
    console.log('Форма после инициализации:', form.roles);
  } else {
    selectedRoles.value = [];
    form.roles = [];
  }
}, { immediate: true });

// Обновляем данные формы при изменении выбранных ролей
watch(selectedRoles, (newRoles) => {
  // Всегда создаем новый массив для обновления формы
  form.roles = [...newRoles];
  console.log('Форма обновлена:', form.roles);
}, { deep: true });

// Переключение роли
const toggleRole = (roleName) => {
  const index = selectedRoles.value.indexOf(roleName);
  if (index !== -1) {
    selectedRoles.value.splice(index, 1);
  } else {
    selectedRoles.value.push(roleName);
  }
  console.log('Текущие выбранные роли:', selectedRoles.value);
};

const submit = () => {
  console.log('Отправляемые роли:', form.roles);
  
  // Проверяем, что форма содержит валидные данные перед отправкой
  if (!form.roles || !Array.isArray(form.roles)) {
    console.error('Некорректные данные формы:', form.roles);
    form.roles = [...selectedRoles.value]; // Пытаемся восстановить данные
  }
  
  form.put(route('admin.users.roles.update', props.user.id), {
    preserveScroll: true,
    onSuccess: () => {
      console.log('Роли успешно обновлены');
      emit('update');
      emit('close');
    },
    onError: (errors) => {
      console.error('Ошибка при обновлении ролей:', errors);
    }
  });
};
</script> 