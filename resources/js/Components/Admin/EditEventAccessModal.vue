<template>
  <SlideOverModal :show="show" @close="$emit('close')">
    <template #title>Редактировать доступ к событию</template>
    
    <div v-if="event" class="mb-6 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
      <h3 class="font-medium text-zinc-900 dark:text-white">{{ event.title }}</h3>
      <p v-if="event.short_description" class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
        {{ event.short_description }}
      </p>
    </div>
    
    <form @submit.prevent="submit" class="space-y-6">
      <!-- Тип доступа -->
      <SelectInput
        v-model="form.access_type"
        :options="accessTypeOptions"
        label="Тип доступа *"
        placeholder="Выберите тип доступа"
        :error="form.errors.access_type"
      />

      <!-- Статус платежа -->
      <SelectInput
        v-model="form.payment_status"
        :options="paymentStatusOptions"
        label="Статус платежа *"
        placeholder="Выберите статус платежа"
        :error="form.errors.payment_status"
      />

      <!-- Сумма платежа -->
      <div v-if="form.access_type === 'paid' || form.payment_status === 'completed'">
        <TextInput
          id="payment_amount"
          v-model="form.payment_amount"
          type="number"
          label="Сумма платежа (₽)"
          placeholder="0.00"
          :min="0"
          :error="form.errors.payment_amount"
        />
      </div>

      <!-- ID платежа -->
      <div v-if="form.payment_status !== 'free'">
        <TextInput
          id="payment_id"
          v-model="form.payment_id"
          label="ID платежа"
          placeholder="Введите ID платежа"
          :error="form.errors.payment_id"
        />
      </div>

      <!-- Дата предоставления доступа -->
      <div>
        <TextInput
          id="access_granted_at"
          v-model="form.access_granted_at"
          type="datetime-local"
          label="Дата предоставления доступа"
          :error="form.errors.access_granted_at"
        />
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
          Оставьте пустым для использования текущего времени
        </p>
      </div>

      <!-- Дата истечения доступа -->
      <div>
        <TextInput
          id="access_expires_at"
          v-model="form.access_expires_at"
          type="datetime-local"
          label="Дата истечения доступа"
          :error="form.errors.access_expires_at"
        />
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
          Оставьте пустым для бессрочного доступа
        </p>
      </div>

      <!-- Активность доступа -->
      <CheckboxInput
        id="is_active"
        v-model="form.is_active"
        label="Доступ активен"
      />
    </form>
    
    <template #footer>
      <div class="flex justify-end gap-3">
        <SecondaryButton @click="$emit('close')">
          Отмена
        </SecondaryButton>
        
        <PrimaryButton 
          @click="submit"
          :disabled="form.processing || !form.access_type || !form.payment_status"
        >
          <div v-if="form.processing" class="mr-2">
            <SpinnerIcon class="size-4 animate-spin" />
          </div>
          <CheckIcon v-else class="mr-2 size-5" />
          {{ form.processing ? 'Сохранение...' : 'Сохранить изменения' }}
        </PrimaryButton>
      </div>
    </template>
  </SlideOverModal>
</template>

<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import SlideOverModal from '@/Components/Modal/SlideOverModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectInput from '@/Components/Form/SelectInput.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import CheckboxInput from '@/Components/Form/CheckboxInput.vue';
import { CheckIcon } from '@heroicons/vue/20/solid';
import { ArrowPathIcon as SpinnerIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
  show: Boolean,
  user: Object,
  event: Object,
});

const emit = defineEmits(['close', 'updated']);

const form = useForm({
  access_type: '',
  payment_amount: null,
  payment_id: '',
  payment_status: '',
  access_granted_at: '',
  access_expires_at: '',
  is_active: true,
});

// Опции для типа доступа
const accessTypeOptions = [
  { value: 'free', text: 'Бесплатный' },
  { value: 'paid', text: 'Платный' },
  { value: 'promotional', text: 'Промо' },
  { value: 'admin', text: 'Администраторский' },
];

// Опции для статуса платежа
const paymentStatusOptions = [
  { value: 'free', text: 'Бесплатно' },
  { value: 'pending', text: 'Ожидает оплаты' },
  { value: 'completed', text: 'Оплачено' },
  { value: 'failed', text: 'Ошибка оплаты' },
  { value: 'refunded', text: 'Возврат' },
];

// Заполняем форму данными при открытии модального окна
watch(() => props.show, (newValue) => {
  if (newValue && props.event) {
    const pivot = props.event.pivot;
    
    form.access_type = pivot.access_type || '';
    form.payment_amount = pivot.payment_amount || null;
    form.payment_id = pivot.payment_id || '';
    form.payment_status = pivot.payment_status || '';
    form.access_granted_at = pivot.access_granted_at ? formatDateForInput(pivot.access_granted_at) : '';
    form.access_expires_at = pivot.access_expires_at ? formatDateForInput(pivot.access_expires_at) : '';
    form.is_active = pivot.is_active !== undefined ? Boolean(pivot.is_active) : true;
    
    form.clearErrors();
  }
});

// Автоматически устанавливаем статус платежа на основе типа доступа
watch(() => form.access_type, (newValue) => {
  if (newValue === 'free' || newValue === 'promotional' || newValue === 'admin') {
    form.payment_status = 'free';
    form.payment_amount = null;
    form.payment_id = '';
  } else if (newValue === 'paid') {
    form.payment_status = 'completed';
  }
});

// Форматируем дату для input[type="datetime-local"]
const formatDateForInput = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toISOString().slice(0, 16);
};

const submit = () => {
  if (!props.event) return;
  
  form.put(route('admin.users.events.update', {
    user: props.user.id,
    event: props.event.id
  }), {
    preserveState: true,
    onSuccess: () => {
      emit('updated');
    },
    onError: (errors) => {
      console.error('Ошибки валидации:', errors);
    }
  });
};
</script>