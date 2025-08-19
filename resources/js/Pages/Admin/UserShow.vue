<template>
  <AdminLayout>
    <template #header>
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">Информация о пользователе</h1>
        <Link
          :href="route('admin.users')"
          class="inline-flex cursor-pointer items-center justify-center rounded-lg border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 transition-all duration-200 hover:bg-zinc-100 hover:scale-[1.02] hover:border-zinc-400 focus:outline-none focus:ring-2 focus:ring-zinc-500 focus:ring-offset-2 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700 dark:hover:border-zinc-500 dark:focus:ring-offset-zinc-800"
        >
          <ArrowLeftIcon class="mr-2 size-5" />
          Назад к списку
        </Link>
      </div>
    </template>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <!-- Основная информация -->
      <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-800/50">
          <h2 class="text-lg font-medium text-zinc-900 dark:text-white">Основная информация</h2>
        </div>
        <div class="px-6 py-5">
          <div class="flex items-center">
            <div class="size-20 flex-shrink-0">
              <UserAvatar :name="getUserName()" :src="user.avatar" size="xl" shape="square" />
            </div>
            <div class="ml-6">
              <h3 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ getUserName() }}</h3>
              <div class="mt-1 flex items-center">
                <EnvelopeIcon class="mr-1.5 size-4 text-zinc-500 dark:text-zinc-400" />
                <span class="text-zinc-600 dark:text-zinc-300">{{ user.email }}</span>
              </div>
              <div v-if="user.phone" class="mt-1 flex items-center">
                <PhoneIcon class="mr-1.5 size-4 text-zinc-500 dark:text-zinc-400" />
                <span class="text-zinc-600 dark:text-zinc-300">{{ user.phone }}</span>
              </div>
            </div>
          </div>
          
          <dl class="mt-6 grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
            <div>
              <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">ID пользователя</dt>
              <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ user.id }}</dd>
            </div>

            <div>
              <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Дата регистрации</dt>
              <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ formatDate(user.created_at) }}</dd>
            </div>

            <div>
              <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Последнее обновление</dt>
              <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ formatDate(user.updated_at) }}</dd>
            </div>

            <div>
              <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Email подтвержден</dt>
              <dd class="mt-1 text-sm text-zinc-900 dark:text-white">
                <span v-if="user.email_verified_at" class="inline-flex items-center">
                  <CheckCircleIcon class="mr-1.5 size-4 text-green-500" />
                  {{ formatDate(user.email_verified_at) }}
                </span>
                <span v-else class="inline-flex items-center">
                  <XCircleIcon class="mr-1.5 size-4 text-red-500" />
                  Не подтвержден
                </span>
              </dd>
            </div>
          </dl>
        </div>
      </div>

      <!-- Дополнительная информация -->
      <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-800/50">
          <h2 class="text-lg font-medium text-zinc-900 dark:text-white">Дополнительная информация</h2>
        </div>
        <div class="px-6 py-5">
          <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
            <div v-if="user.first_name">
              <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Имя</dt>
              <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ user.first_name }}</dd>
            </div>

            <div v-if="user.last_name">
              <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Фамилия</dt>
              <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ user.last_name }}</dd>
            </div>

            <div v-if="user.middle_name">
              <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Отчество</dt>
              <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ user.middle_name }}</dd>
            </div>

            <div v-if="user.company">
              <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Компания</dt>
              <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ user.company }}</dd>
            </div>

            <div v-if="user.position">
              <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Должность</dt>
              <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ user.position }}</dd>
            </div>

            <div v-if="user.city">
              <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Город</dt>
              <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ user.city }}</dd>
            </div>

            <div v-if="user.phone">
              <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Телефон</dt>
              <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ user.phone }}</dd>
            </div>
          </dl>
        </div>
      </div>

      <!-- Роли пользователя -->
      <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-800/50">
          <h2 class="text-lg font-medium text-zinc-900 dark:text-white">Роли пользователя</h2>
        </div>
        <div class="px-6 py-5">
          <div class="space-y-4">
            <div v-if="user.roles && user.roles.length > 0">
              <div class="flex flex-wrap gap-2">
                <div
                  v-for="role in user.roles"
                  :key="role.id"
                  class="flex flex-col rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800"
                >
                  <div class="mb-2 flex items-center">
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
                  </div>
                  <p v-if="role.description" class="text-sm text-zinc-600 dark:text-zinc-300">
                    {{ role.description }}
                  </p>
                  <p v-else class="text-sm italic text-zinc-500 dark:text-zinc-400">
                    Нет описания
                  </p>
                </div>
              </div>
            </div>
            <div v-else class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-center dark:border-zinc-700 dark:bg-zinc-800">
              <p class="text-sm text-zinc-600 dark:text-zinc-300">У этого пользователя нет назначенных ролей</p>
            </div>

            <!-- Кнопка управления ролями (только для админов) -->
            <div class="mt-4" v-if="canManageRoles">
              <PrimaryButton @click="showRolesModal = true">
                <PencilSquareIcon class="mr-2 size-5" />
                Управление ролями
              </PrimaryButton>
            </div>
          </div>
        </div>
      </div>

      <!-- Доступы к событиям -->
      <UserEventAccess
        :user="user"
        :userEvents="userEvents"
        :availableEvents="availableEvents"
        :canManageAccess="canManageAccess"
        @access-updated="handleAccessUpdated"
        class="lg:col-span-2"
      />

      <!-- Действия -->
      <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 lg:col-span-2">
        <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-800/50">
          <h2 class="text-lg font-medium text-zinc-900 dark:text-white">Действия</h2>
        </div>
        <div class="flex items-center gap-4 px-6 py-5">
          <!-- Кнопка редактирования -->
          <SecondaryButton
            v-if="canManageUsers && (isAdmin || !hasAdminRole(user))"
            @click="showEditModal = true"
          >
            <PencilIcon class="mr-2 size-5" />
            Редактировать данные
          </SecondaryButton>
          
          <!-- Кнопка удаления -->
          <button
            v-if="canManageUsers && (isAdmin || !hasAdminRole(user))"
            @click="showDeleteModal = true"
            class="inline-flex cursor-pointer items-center justify-center rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 transition-all duration-200 hover:bg-red-50 hover:border-red-400 hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300 dark:hover:bg-red-900/30"
          >
            <TrashIcon class="mr-2 size-5" />
            Удалить пользователя
          </button>
        </div>
      </div>
    </div>

    <!-- Уведомления -->
    <div
      v-if="notification.show"
      class="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg px-4 py-3 shadow-lg transition-all duration-300"
      :class="{
        'bg-green-50 text-green-800 ring-1 ring-green-500/10 dark:bg-green-900/30 dark:text-green-300': notification.type === 'success',
        'bg-red-50 text-red-800 ring-1 ring-red-500/10 dark:bg-red-900/30 dark:text-red-300': notification.type === 'error'
      }"
    >
      <div class="flex items-center justify-between">
        <p>{{ notification.message }}</p>
        <button 
          @click="notification.show = false"
          class="ml-4 text-current hover:opacity-75"
        >
          <XMarkIcon class="h-5 w-5" />
        </button>
      </div>
    </div>

    <!-- Модальные окна -->
    <EditUserModal
      :show="showEditModal"
      :user="user"
      @close="showEditModal = false"
      @update="handleUserUpdate"
    />
    <UserRolesModal
      :show="showRolesModal"
      :user="user"
      :roles="roles"
      @close="showRolesModal = false"
      @update="showNotification('Роли пользователя успешно обновлены')"
    />

    <!-- Модальное окно подтверждения удаления -->
    <SlideOverModal :show="showDeleteModal" @close="showDeleteModal = false">
      <template #title>Удаление пользователя</template>
      
      <div class="space-y-4">
        <div class="flex items-center gap-3 rounded-lg bg-red-50 p-4 dark:bg-red-900/20">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            class="size-6 text-red-600 dark:text-red-400"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          >
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
            <line x1="12" y1="9" x2="12" y2="13" />
            <line x1="12" y1="17" x2="12.01" y2="17" />
          </svg>
          <p class="text-sm text-red-800 dark:text-red-300">
            Вы уверены, что хотите удалить пользователя <strong>{{ user.name }}</strong>?
            <br>
            Это действие невозможно отменить.
          </p>
        </div>
      </div>
      
      <template #footer>
        <div class="flex justify-end gap-3">
          <SecondaryButton @click="showDeleteModal = false">
            Отмена
          </SecondaryButton>
          
          <button
            @click="confirmDelete"
            :disabled="form.processing"
            class="inline-flex cursor-pointer items-center justify-center rounded-lg border border-transparent bg-red-600 px-4 py-2 text-sm font-medium text-white transition-all duration-200 hover:bg-red-700 hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
          >
            <div v-if="form.processing" class="mr-2">
              <SpinnerIcon class="size-4 animate-spin" />
            </div>
            <TrashIcon v-else class="mr-2 size-5" />
            {{ form.processing ? 'Удаление...' : 'Удалить пользователя' }}
          </button>
        </div>
      </template>
    </SlideOverModal>
  </AdminLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import EditUserModal from '@/Components/Admin/EditUserModal.vue';
import UserRolesModal from '@/Components/Admin/UserRolesModal.vue';
import UserEventAccess from '@/Components/Admin/UserEventAccess.vue';
import SlideOverModal from '@/Components/Modal/SlideOverModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { 
  ArrowLeftIcon, 
  EnvelopeIcon, 
  PhoneIcon, 
  CheckCircleIcon, 
  XCircleIcon,
  PencilIcon,
  TrashIcon,
  PencilSquareIcon,
  XMarkIcon
} from '@heroicons/vue/20/solid';

// Импортируем спиннер отдельно
import { ArrowPathIcon as SpinnerIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
  user: Object,
  roles: Array,
  userEvents: Array,
  availableEvents: Array,
  canManageUsers: Boolean,
  canManageRoles: Boolean,
  canManageAccess: Boolean,
  isAdmin: Boolean,
});

// Модальные окна
const showEditModal = ref(false);
const showRolesModal = ref(false);
const showDeleteModal = ref(false);

// Форма для отслеживания состояния удаления
const form = useForm({});

// Уведомления
const notification = ref({
  show: false,
  message: '',
  type: 'success',
  timeout: null
});

const handleUserUpdate = () => {
  showNotification('Данные пользователя успешно обновлены');
  router.reload({ only: ['user'], preserveState: true });
};

// Методы
// Получить имя пользователя для отображения
const getUserName = () => {
  if (props.user.full_name && props.user.full_name.trim() !== '') {
    return props.user.full_name;
  }
  
  if (props.user.first_name && props.user.last_name) {
    return `${props.user.first_name} ${props.user.last_name}`;
  }
  
  if (props.user.first_name) {
    return props.user.first_name;
  }
  
  if (props.user.last_name) {
    return props.user.last_name;
  }
  
  return props.user.email.split('@')[0];
};

// Проверяет, имеет ли пользователь роль администратора
const hasAdminRole = (user) => {
  return user.roles && user.roles.some(role => role.name === 'admin');
};

const formatDate = (date) => {
  if (!date) return 'Не указана';
  
  return new Date(date).toLocaleDateString('ru-RU', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
};

const showNotification = (message, type = 'success') => {
  // Очистить предыдущий таймаут, если есть
  if (notification.value.timeout) {
    clearTimeout(notification.value.timeout);
  }
  
  notification.value = {
    show: true,
    message,
    type,
    timeout: setTimeout(() => {
      notification.value.show = false;
    }, 3000)
  };
};

const deleteUser = () => {
  form.delete(route('admin.users.destroy', props.user.id), {
    onSuccess: () => {
      showNotification('Пользователь успешно удален');
      setTimeout(() => {
        router.visit(route('admin.users'));
      }, 1500); // Небольшая задержка, чтобы пользователь увидел уведомление
    },
    onError: () => {
      showNotification('Ошибка при удалении пользователя', 'error');
    }
  });
};

const confirmDelete = () => {
  deleteUser();
  showDeleteModal.value = false;
};

const handleAccessUpdated = () => {
  showNotification('Доступ к событию успешно обновлен');
  router.reload({ only: ['user', 'userEvents', 'availableEvents'], preserveState: true });
};
</script> 