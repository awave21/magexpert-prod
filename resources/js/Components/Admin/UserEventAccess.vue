<template>
  <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-800/50">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-zinc-900 dark:text-white">Доступы к событиям</h2>
        <PrimaryButton @click="showAddAccessModal = true" v-if="canManageAccess">
          <PlusIcon class="mr-2 size-5" />
          Добавить доступ
        </PrimaryButton>
      </div>
    </div>
    
    <div class="px-6 py-5">
      <div v-if="userEvents && userEvents.length > 0" class="space-y-4">
        <div
          v-for="event in userEvents"
          :key="event.id"
          class="flex items-start justify-between rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800"
        >
          <div class="flex-1">
            <div class="flex items-start justify-between">
              <div class="flex-1">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                  {{ event.title }}
                </h3>
                <p v-if="event.short_description" class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                  {{ event.short_description }}
                </p>
                
                <!-- Информация о доступе -->
                <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <div>
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Тип доступа</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">
                      <span
                        class="inline-flex rounded-full px-2 py-1 text-xs font-semibold"
                        :class="getAccessTypeClass(event.pivot.access_type)"
                      >
                        {{ getAccessTypeLabel(event.pivot.access_type) }}
                      </span>
                    </dd>
                  </div>
                  
                  <div>
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Статус платежа</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">
                      <span
                        class="inline-flex rounded-full px-2 py-1 text-xs font-semibold"
                        :class="getPaymentStatusClass(event.pivot.payment_status)"
                      >
                        {{ getPaymentStatusLabel(event.pivot.payment_status) }}
                      </span>
                    </dd>
                  </div>
                  
                  <div v-if="event.pivot.payment_amount">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Сумма платежа</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">
                      {{ formatPrice(event.pivot.payment_amount) }}
                    </dd>
                  </div>
                  
                  <div>
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Статус доступа</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">
                      <span
                        class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold"
                        :class="event.pivot.is_active 
                          ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' 
                          : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300'"
                      >
                        <span
                          class="mr-1 size-2 rounded-full"
                          :class="event.pivot.is_active ? 'bg-green-500' : 'bg-red-500'"
                        ></span>
                        {{ event.pivot.is_active ? 'Активен' : 'Неактивен' }}
                      </span>
                    </dd>
                  </div>
                  
                  <div v-if="event.pivot.access_granted_at">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Доступ предоставлен</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">
                      {{ formatDate(event.pivot.access_granted_at) }}
                    </dd>
                  </div>
                  
                  <div v-if="event.pivot.access_expires_at">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Доступ истекает</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">
                      {{ formatDate(event.pivot.access_expires_at) }}
                    </dd>
                  </div>
                </div>
              </div>
              
              <!-- Действия -->
              <div v-if="canManageAccess" class="ml-4 flex flex-col gap-2">
                <button
                  @click="editAccess(event)"
                  class="inline-flex items-center rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 shadow-sm hover:bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-zinc-500 focus:ring-offset-2 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-600"
                >
                  <PencilIcon class="mr-1 size-3" />
                  Изменить
                </button>
                <button
                  @click="toggleAccess(event)"
                  class="inline-flex items-center rounded-md border px-3 py-1.5 text-xs font-medium shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2"
                  :class="event.pivot.is_active 
                    ? 'border-red-300 bg-white text-red-700 hover:bg-red-50 focus:ring-red-500 dark:border-red-600 dark:bg-red-900/20 dark:text-red-300 dark:hover:bg-red-900/30' 
                    : 'border-green-300 bg-white text-green-700 hover:bg-green-50 focus:ring-green-500 dark:border-green-600 dark:bg-green-900/20 dark:text-green-300 dark:hover:bg-green-900/30'"
                >
                  <component 
                    :is="event.pivot.is_active ? XMarkIcon : CheckIcon" 
                    class="mr-1 size-3" 
                  />
                  {{ event.pivot.is_active ? 'Отключить' : 'Включить' }}
                </button>
                <button
                  @click="removeAccess(event)"
                  class="inline-flex items-center rounded-md border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-700 shadow-sm hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:border-red-600 dark:bg-red-900/20 dark:text-red-300 dark:hover:bg-red-900/30"
                >
                  <TrashIcon class="mr-1 size-3" />
                  Удалить
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Пустое состояние -->
      <div v-else class="rounded-lg border border-zinc-200 bg-zinc-50 p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
        <CalendarIcon class="mx-auto size-12 text-zinc-400 dark:text-zinc-500" />
        <h3 class="mt-2 text-sm font-medium text-zinc-900 dark:text-white">Нет доступов к событиям</h3>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
          У этого пользователя пока нет доступов к каким-либо событиям.
        </p>
        <div v-if="canManageAccess" class="mt-6">
          <PrimaryButton @click="showAddAccessModal = true">
            <PlusIcon class="mr-2 size-5" />
            Добавить первый доступ
          </PrimaryButton>
        </div>
      </div>
    </div>

    <!-- Модальное окно добавления доступа -->
    <AddEventAccessModal
      :show="showAddAccessModal"
      :user="user"
      :events="availableEvents"
      @close="showAddAccessModal = false"
      @added="handleAccessAdded"
    />
    
    <!-- Модальное окно редактирования доступа -->
    <EditEventAccessModal
      :show="showEditAccessModal"
      :user="user"
      :event="selectedEvent"
      @close="closeEditModal"
      @updated="handleAccessUpdated"
    />
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AddEventAccessModal from '@/Components/Admin/AddEventAccessModal.vue';
import EditEventAccessModal from '@/Components/Admin/EditEventAccessModal.vue';
import {
  PlusIcon,
  PencilIcon,
  TrashIcon,
  CalendarIcon,
  XMarkIcon,
  CheckIcon
} from '@heroicons/vue/20/solid';

const props = defineProps({
  user: Object,
  userEvents: Array,
  availableEvents: Array,
  canManageAccess: Boolean,
});

const emit = defineEmits(['access-updated']);

// Состояние модальных окон
const showAddAccessModal = ref(false);
const showEditAccessModal = ref(false);
const selectedEvent = ref(null);

// Методы для работы с типами доступа
const getAccessTypeLabel = (type) => {
  const labels = {
    'free': 'Бесплатный',
    'paid': 'Платный',
    'promotional': 'Промо',
    'admin': 'Администраторский'
  };
  return labels[type] || type;
};

const getAccessTypeClass = (type) => {
  const classes = {
    'free': 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
    'paid': 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    'promotional': 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
    'admin': 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300'
  };
  return classes[type] || 'bg-zinc-100 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-200';
};

// Методы для работы со статусами платежей
const getPaymentStatusLabel = (status) => {
  const labels = {
    'pending': 'Ожидает оплаты',
    'completed': 'Оплачено',
    'failed': 'Ошибка оплаты',
    'refunded': 'Возврат',
    'free': 'Бесплатно'
  };
  return labels[status] || status;
};

const getPaymentStatusClass = (status) => {
  const classes = {
    'pending': 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
    'completed': 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
    'failed': 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
    'refunded': 'bg-zinc-100 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-200',
    'free': 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300'
  };
  return classes[status] || 'bg-zinc-100 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-200';
};

// Форматирование даты
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

// Форматирование цены
const formatPrice = (amount) => {
  if (!amount) return '0 ₽';
  return new Intl.NumberFormat('ru-RU').format(amount) + ' ₽';
};

// Действия с доступами
const editAccess = (event) => {
  selectedEvent.value = event;
  showEditAccessModal.value = true;
};

const closeEditModal = () => {
  showEditAccessModal.value = false;
  selectedEvent.value = null;
};

const toggleAccess = (event) => {
  router.put(route('admin.users.events.toggle', {
    user: props.user.id,
    event: event.id
  }), {}, {
    preserveState: true,
    onSuccess: () => {
      emit('access-updated');
    }
  });
};

const removeAccess = (event) => {
  if (confirm('Вы уверены, что хотите удалить доступ к этому событию?')) {
    router.delete(route('admin.users.events.destroy', {
      user: props.user.id,
      event: event.id
    }), {
      preserveState: true,
      onSuccess: () => {
        emit('access-updated');
      }
    });
  }
};

const handleAccessAdded = () => {
  showAddAccessModal.value = false;
  emit('access-updated');
};

const handleAccessUpdated = () => {
  closeEditModal();
  emit('access-updated');
};
</script>