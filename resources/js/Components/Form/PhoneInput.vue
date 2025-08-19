<template>
  <div>
    <label v-if="label" :for="id" class="block text-sm font-medium text-zinc-900 dark:text-white">{{ label }}</label>
    <div class="mt-1 relative">
      <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
        <span class="text-zinc-500 dark:text-zinc-400 text-sm">+7</span>
      </div>
      <input
        :id="id"
        type="tel"
        :value="displayValue"
        @input="handleInput"
        @paste="handlePaste"
        @blur="handleBlur"
        :required="required"
        maxlength="15"
        class="w-full rounded-lg border border-zinc-300 bg-white pl-9 pr-4 py-2 text-zinc-900 placeholder-zinc-500 focus:border-brandblue focus:outline-none focus:ring-2 focus:ring-brandblue/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-400"
        :placeholder="placeholder"
        v-bind="attrs"
      />
    </div>
    <p v-if="error" class="mt-1 text-sm text-brandcoral dark:text-brandcoral">{{ error }}</p>
  </div>
</template>

<script setup>
import { ref, watch, useAttrs } from 'vue';
defineOptions({ inheritAttrs: false });
import { useToast } from 'vue-toastification';

const props = defineProps({
  modelValue: {
    type: String,
    default: ''
  },
  id: {
    type: String,
    required: true
  },
  label: {
    type: String,
    default: ''
  },
  placeholder: {
    type: String,
    default: '(999) 123-45-67'
  },
  required: {
    type: Boolean,
    default: false
  },
  error: {
    type: String,
    default: ''
  }
});

const emit = defineEmits(['update:modelValue']);

const toast = useToast();
const displayValue = ref('');
const attrs = useAttrs();

// Очистка номера до цифр
const cleanPhoneNumber = (phone) => {
  if (!phone) return '';
  return phone.replace(/\D/g, '');
};

// Извлечение российского номера (нормализация к формату 7XXXXXXXXXX)
const extractRussianPhone = (input) => {
  if (!input) return '';
  const cleaned = cleanPhoneNumber(input);
  if (cleaned.startsWith('8') && cleaned.length === 11) {
    return '7' + cleaned.slice(1);
  }
  if (cleaned.startsWith('7') && cleaned.length === 11) {
    return cleaned;
  }
  if (cleaned.length === 10) {
    return '7' + cleaned;
  }
  return '';
};

// Форматирование для отображения: (XXX) XXX-XX-XX
const formatPhoneForDisplay = (numbersOnly) => {
  if (!numbersOnly) return '';
  const n = numbersOnly.slice(0, 10);
  if (n.length <= 3) return n;
  if (n.length <= 6) return `(${n.slice(0, 3)}) ${n.slice(3)}`;
  if (n.length <= 8) return `(${n.slice(0, 3)}) ${n.slice(3, 6)}-${n.slice(6)}`;
  return `(${n.slice(0, 3)}) ${n.slice(3, 6)}-${n.slice(6, 8)}-${n.slice(8, 10)}`;
};

// Инициализация из modelValue (поддержка частичного ввода "7" + цифры)
const initializeFromModelValue = (value) => {
  const cleaned = cleanPhoneNumber(value);
  let numbers = cleaned;
  if (numbers.startsWith('7')) {
    numbers = numbers.slice(1);
  } else if (numbers.startsWith('8')) {
    // Приходящие данные с 8 считаем российским номером и убираем 8 для отображения
    numbers = numbers.slice(1);
  }
  numbers = numbers.slice(0, 10);
  displayValue.value = formatPhoneForDisplay(numbers);
};

watch(() => props.modelValue, (newValue) => {
  if (newValue !== undefined && newValue !== null) {
    initializeFromModelValue(newValue);
  } else {
    displayValue.value = '';
  }
}, { immediate: true });

// Обработка ввода
const handleInput = (event) => {
  const inputValue = event.target.value || '';
  const numbersOnly = inputValue.replace(/\D/g, '');
  const limited = numbersOnly.slice(0, 10);
  displayValue.value = formatPhoneForDisplay(limited);
  const fullPhone = limited.length > 0 ? '7' + limited : '';
  emit('update:modelValue', fullPhone);
};

// Обработка вставки (в т.ч. ссылок WhatsApp)
const handlePaste = (event) => {
  event.preventDefault();
  const pastedText = (event.clipboardData || window.clipboardData).getData('text') || '';
  const waMatch = pastedText.match(/\+?(\d{10,})/);
  const candidate = waMatch ? waMatch[1] : pastedText;
  handleInput({ target: { value: candidate } });
};

// Валидация на blur
const handleBlur = () => {
  const clean = cleanPhoneNumber(displayValue.value);
  if (displayValue.value.trim() && clean.length !== 10) {
    toast.error('Неверный формат номера телефона. Введите полный российский номер');
  }
};
</script>


