<template>
  <Head title="Админ-панель" />

  <AdminLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">
        Админ-панель
      </h2>
    </template>

    <div>
      <h1 class="text-2xl/8 font-semibold text-zinc-950 sm:text-xl/8 dark:text-white">Главная</h1>
      <hr role="presentation" class="mt-6 w-full border-t border-zinc-950/10 dark:border-white/10">
      
      <div class="mt-6">
        <div class="mb-6">
          <h3 class="text-lg font-medium mb-4">Добро пожаловать в админ-панель, {{ user.name }}!</h3>
          
          <div class="mb-6">
            <p class="mb-2">Ваши роли:</p>
            <div class="flex flex-wrap gap-2">
              <span 
                v-for="role in user.roles" 
                :key="role.id" 
                class="px-3 py-1 bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-100 rounded-full text-sm"
              >
                {{ role.name }}
              </span>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Статистика пользователей -->
            <div class="bg-gray-50 dark:bg-zinc-700/50 p-4 rounded-lg shadow-sm ring-1 ring-zinc-950/5 dark:ring-white/10">
              <h4 class="font-medium text-gray-700 dark:text-gray-200 mb-2">Пользователи</h4>
              <div class="text-3xl font-bold text-blue-600 dark:text-blue-400">{{ stats.users_count }}</div>
              <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">Всего пользователей</p>
            </div>

            <!-- Статистика ролей -->
            <div class="bg-gray-50 dark:bg-zinc-700/50 p-4 rounded-lg shadow-sm ring-1 ring-zinc-950/5 dark:ring-white/10">
              <h4 class="font-medium text-gray-700 dark:text-gray-200 mb-2">Роли</h4>
              <div class="text-3xl font-bold text-green-600 dark:text-green-400">{{ stats.roles_count }}</div>
              <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">Всего ролей</p>
            </div>

            <!-- Статистика администраторов -->
            <div class="bg-gray-50 dark:bg-zinc-700/50 p-4 rounded-lg shadow-sm ring-1 ring-zinc-950/5 dark:ring-white/10">
              <h4 class="font-medium text-gray-700 dark:text-gray-200 mb-2">Администраторы</h4>
              <div class="text-3xl font-bold text-purple-600 dark:text-purple-400">{{ stats.admin_count }}</div>
              <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">Пользователей с ролью администратора</p>
            </div>

            <!-- Статистика редакторов -->
            <div class="bg-gray-50 dark:bg-zinc-700/50 p-4 rounded-lg shadow-sm ring-1 ring-zinc-950/5 dark:ring-white/10">
              <h4 class="font-medium text-gray-700 dark:text-gray-200 mb-2">Редакторы</h4>
              <div class="text-3xl font-bold text-yellow-600 dark:text-yellow-400">{{ stats.editor_count }}</div>
              <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">Пользователей с ролью редактора</p>
            </div>

            <!-- Статистика менеджеров -->
            <div class="bg-gray-50 dark:bg-zinc-700/50 p-4 rounded-lg shadow-sm ring-1 ring-zinc-950/5 dark:ring-white/10">
              <h4 class="font-medium text-gray-700 dark:text-gray-200 mb-2">Менеджеры</h4>
              <div class="text-3xl font-bold text-red-600 dark:text-red-400">{{ stats.manager_count }}</div>
              <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">Пользователей с ролью менеджера</p>
            </div>
          </div>

          <!-- Тестирование уведомлений -->
          <div class="mt-8">
            <h3 class="text-lg font-medium mb-4">Тестирование уведомлений</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
              Уведомления будут отправлены всем пользователям с ролью "admin".
            </p>
            <div class="flex flex-wrap gap-4">
              <button 
                @click="testOrderNotification"
                class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition"
              >
                Тест уведомления о заказе
              </button>
              <button 
                @click="testUserNotification"
                class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 transition"
              >
                Тест уведомления о пользователе
              </button>
              <button 
                @click="testPaymentNotification"
                class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition"
              >
                Тест уведомления об оплате
              </button>
            </div>
            <div v-if="notificationMessage" class="mt-2 p-2 text-sm" :class="notificationMessageClass">
              {{ notificationMessage }}
            </div>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import axios from 'axios';
import { ref } from 'vue';

const props = defineProps({
  stats: Object,
  user: Object
});

// Сообщение о результате отправки уведомления
const notificationMessage = ref('');
const notificationMessageClass = ref('');

// Функция для отображения сообщения
const showNotificationMessage = (message, isSuccess = true) => {
  notificationMessage.value = message;
  notificationMessageClass.value = isSuccess 
    ? 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200'
    : 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200';
  
  // Скрываем сообщение через 5 секунд
  setTimeout(() => {
    notificationMessage.value = '';
  }, 5000);
};

// Функция для проверки наличия роли у пользователя
const hasRole = (roles) => {
  return props.user.roles.some(role => roles.includes(role.name));
};

// Функции для тестирования уведомлений
const testOrderNotification = async () => {
  try {
    const response = await axios.post('/api/test-notification', {
      id: Date.now(),
      type: 'order',
      title: 'Новый заказ',
      message: 'Получен новый заказ на сумму 1500 руб.',
      timestamp: new Date().toISOString(),
      read: false
    });
    
    showNotificationMessage(response.data.message || 'Уведомление отправлено успешно');
  } catch (error) {
    console.error('Ошибка при отправке тестового уведомления о заказе:', error);
    showNotificationMessage('Ошибка при отправке уведомления', false);
  }
};

const testUserNotification = async () => {
  try {
    const response = await axios.post('/api/test-notification', {
      id: Date.now(),
      type: 'user',
      title: 'Новый пользователь',
      message: 'Зарегистрирован новый пользователь: Иван Иванов',
      timestamp: new Date().toISOString(),
      read: false
    });
    
    showNotificationMessage(response.data.message || 'Уведомление отправлено успешно');
  } catch (error) {
    console.error('Ошибка при отправке тестового уведомления о пользователе:', error);
    showNotificationMessage('Ошибка при отправке уведомления', false);
  }
};

const testPaymentNotification = async () => {
  try {
    const response = await axios.post('/api/test-notification', {
      id: Date.now(),
      type: 'payment',
      title: 'Оплата получена',
      message: 'Получена оплата заказа #123 на сумму 1500 руб.',
      timestamp: new Date().toISOString(),
      read: false
    });
    
    showNotificationMessage(response.data.message || 'Уведомление отправлено успешно');
  } catch (error) {
    console.error('Ошибка при отправке тестового уведомления об оплате:', error);
    showNotificationMessage('Ошибка при отправке уведомления', false);
  }
};
</script> 