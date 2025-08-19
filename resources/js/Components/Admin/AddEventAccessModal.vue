<template>
  <SlideOverModal :show="show" @close="$emit('close')">
    <template #title>Добавить доступ к событию</template>
    
    <form @submit.prevent="submit" class="space-y-6">
      <!-- Выбор события -->
      <SearchableSelectInput
        v-model="form.event_id"
        :options="eventOptions"
        label="Событие *"
        placeholder="Выберите событие"
        search-placeholder="Введите название события для поиска..."
        no-results-text="События не найдены"
        loading-text="Поиск событий..."
        :search-url="route('admin.events.search')"
        :min-search-length="2"
        :debounce-delay="300"
        :error="form.errors.event_id"
      />

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
        label="Активировать доступ сразу"
      />
    </form>
    
    <template #footer>
      <div class="flex justify-end gap-3">
        <SecondaryButton @click="$emit('close')">
          Отмена
        </SecondaryButton>
        
        <PrimaryButton 
          @click="submit"
          :disabled="form.processing || !form.event_id || !form.access_type || !form.payment_status"
        >
          <div v-if="form.processing" class="mr-2">
            <SpinnerIcon class="size-4 animate-spin" />
          </div>
          <PlusIcon v-else class="mr-2 size-5" />
          {{ form.processing ? 'Добавление...' : 'Добавить доступ' }}
        </PrimaryButton>
      </div>
    </template>
  </SlideOverModal>
</template>

<script setup>
import { ref, watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import SlideOverModal from '@/Components/Modal/SlideOverModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SearchableSelectInput from '@/Components/Form/SearchableSelectInput.vue';
import SelectInput from '@/Components/Form/SelectInput.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import CheckboxInput from '@/Components/Form/CheckboxInput.vue';
import { PlusIcon } from '@heroicons/vue/20/solid';
import { ArrowPathIcon as SpinnerIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
  show: Boolean,
  user: Object,
  events: Array,
});

const emit = defineEmits(['close', 'added']);

const form = useForm({
  event_id: '',
  access_type: '',
  payment_amount: null,
  payment_id: '',
  payment_status: '',
  access_granted_at: '',
  access_expires_at: '',
  is_active: true,
});

// Преобразуем события в формат для SearchableSelectInput
const eventOptions = computed(() => {
  return props.events.map(event => ({
    value: event.id,
    text: event.title,
  }));
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

// Сбрасываем форму при открытии модального окна
watch(() => props.show, (newValue) => {
  if (newValue) {
    form.reset();
    form.clearErrors();
    form.is_active = true;
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

const submit = () => {
  form.post(route('admin.users.events.store', props.user.id), {
    preserveState: true,
    onSuccess: () => {
      emit('added');
    },
    onError: (errors) => {
      console.error('Ошибки валидации:', errors);
    }
  });
};
</script>