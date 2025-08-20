<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { 
  Dialog, 
  DialogPanel, 
  DialogTitle,
  TransitionRoot,
  TransitionChild
} from '@headlessui/vue';
import { 
  XMarkIcon,
  CheckCircleIcon,
  ExclamationCircleIcon,
  ArrowRightIcon,
  ArrowLeftIcon
} from '@heroicons/vue/24/outline';
import CityAutocomplete from './CityAutocomplete.vue';
import TextInput from './Form/TextInput.vue';
import PhoneInput from './Form/PhoneInput.vue';

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  event: {
    type: Object,
    required: true
  }
});

const emit = defineEmits(['close']);

// Шаги регистрации
const STEPS = {
  EMAIL_CHECK: 1,
  USER_FORM: 2,
  PAYMENT: 3,
  CONFIRMATION: 4
};

const currentStep = ref(STEPS.EMAIL_CHECK);
const userExists = ref(false);
const isLoading = ref(false);

// Форма для проверки email
const emailForm = useForm({
  email: ''
});

// Форма для регистрации пользователя
const registrationForm = useForm({
  first_name: '',
  last_name: '',
  middle_name: '',
  email: '',
  phone: '',
  city: '',
  specialization: '',
  event_id: props.event.id
});

// Сброс состояния при закрытии модального окна
const resetModal = () => {
  currentStep.value = STEPS.EMAIL_CHECK;
  userExists.value = false;
  emailForm.reset();
  registrationForm.reset();
  isLoading.value = false;
};

// Закрытие модального окна
const closeModal = () => {
  resetModal();
  emit('close');
};

// Проверка существования пользователя по email
const checkEmail = async () => {
  if (!emailForm.email) return;
  
  isLoading.value = true;
  
  try {
    const response = await fetch('/api/check-user-email', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
      },
      body: JSON.stringify({ email: emailForm.email })
    });
    
    const data = await response.json();
    
    if (data.exists) {
      userExists.value = true;
      registrationForm.email = emailForm.email;
      // Не получаем персональные данные для защиты конфиденциальности
    } else {
      userExists.value = false;
      registrationForm.email = emailForm.email;
    }
    
    currentStep.value = STEPS.USER_FORM;
  } catch (error) {
    console.error('Ошибка при проверке email:', error);
  } finally {
    isLoading.value = false;
  }
};

// Возврат к предыдущему шагу
const goBack = () => {
  if (currentStep.value === STEPS.USER_FORM) {
    currentStep.value = STEPS.EMAIL_CHECK;
  } else if (currentStep.value === STEPS.PAYMENT) {
    currentStep.value = STEPS.USER_FORM;
  }
};

// Переход к оплате
const proceedToPayment = () => {
  // Здесь будет логика перехода к оплате
  // Пока просто закрываем модальное окно
  closeModal();
};

// Отправка формы регистрации
const submitRegistration = () => {
  if (userExists.value) {
    // Для существующих пользователей - проверяем необходимость оплаты
    handleExistingUserRegistration();
  } else {
    // Для новых пользователей - обычная регистрация
    registrationForm.post(route('events.register', props.event.slug), {
      onSuccess: (response) => {
        if (props.event.is_paid) {
          // Для платных мероприятий Inertia автоматически перенаправит на оплату
          // Если мы дошли до этой точки, значит что-то пошло не так
          console.error('Не удалось перенаправить на оплату', response);
          alert('Произошла ошибка при создании платежной ссылки. Попробуйте еще раз.');
        } else {
          currentStep.value = STEPS.CONFIRMATION;
        }
      },
      onError: (errors) => {
        console.error('Ошибки регистрации:', errors);
      }
    });
  }
};

// Обработка регистрации существующего пользователя
const handleExistingUserRegistration = () => {
  isLoading.value = true;
  
  // Отправляем только email для регистрации существующего пользователя
  const existingUserForm = useForm({
    email: registrationForm.email,
    event_id: props.event.id,
    existing_user: true 
  });
  
  existingUserForm.post(route('events.register', props.event.slug), {
    onSuccess: (response) => {
      if (props.event.is_paid) {
        // Для платных мероприятий Inertia автоматически перенаправит на оплату
        // Если мы дошли до этой точки, значит что-то пошло не так
        console.error('Не удалось перенаправить на оплату (существующий пользователь)', response);
        alert('Произошла ошибка при создании платежной ссылки. Попробуйте еще раз.');
      } else {
        currentStep.value = STEPS.CONFIRMATION;
      }
    },
    onError: (errors) => {
      console.error('Ошибки регистрации:', errors);
    },
    onFinish: () => {
      isLoading.value = false;
    }
  });
};

// Маска телефона реализована в компоненте PhoneInput

// Заголовки для шагов
const stepTitles = computed(() => ({
  [STEPS.EMAIL_CHECK]: 'Регистрация на мероприятие',
  [STEPS.USER_FORM]: userExists.value ? 'Подтверждение регистрации' : 'Заполните данные',
  [STEPS.PAYMENT]: 'Оплата мероприятия',
  [STEPS.CONFIRMATION]: 'Регистрация завершена'
}));

// Очистка состояния при изменении видимости
watch(() => props.show, (newValue) => {
  if (!newValue) {
    resetModal();
  }
});
</script>

<template>
  <TransitionRoot appear :show="show" as="template">
    <Dialog as="div" @close="closeModal" class="relative z-50">
      <TransitionChild
        as="template"
        enter="duration-300 ease-out"
        enter-from="opacity-0"
        enter-to="opacity-100"
        leave="duration-200 ease-in"
        leave-from="opacity-100"
        leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-black/25 backdrop-blur-sm" />
      </TransitionChild>

      <div class="fixed inset-0 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4 text-center">
          <TransitionChild
            as="template"
            enter="duration-300 ease-out"
            enter-from="opacity-0 scale-95"
            enter-to="opacity-100 scale-100"
            leave="duration-200 ease-in"
            leave-from="opacity-100 scale-100"
            leave-to="opacity-0 scale-95"
          >
            <DialogPanel class="w-full max-w-md transform overflow-visible rounded-2xl bg-white dark:bg-gray-800 p-6 text-left align-middle shadow-xl transition-all">
              <!-- Заголовок с кнопкой закрытия -->
              <div class="flex items-center justify-between mb-6">
                <DialogTitle as="h3" class="text-lg font-medium leading-6 text-gray-900 dark:text-white">
                  {{ stepTitles[currentStep] }}
                </DialogTitle>
                <button
                  @click="closeModal"
                  class="rounded-md p-2 text-gray-400 hover:text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-brandblue"
                >
                  <XMarkIcon class="h-5 w-5" />
                </button>
              </div>

              <!-- Индикатор прогресса -->
              <div class="mb-6" v-if="currentStep < STEPS.CONFIRMATION">
                <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400 mb-2">
                  <span>Шаг {{ currentStep }} из {{ userExists && props.event.is_paid ? 3 : (props.event.is_paid ? 4 : 3) }}</span>
                  <span>{{ Math.round((currentStep / (userExists && props.event.is_paid ? 3 : (props.event.is_paid ? 4 : 3))) * 100) }}%</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                  <div 
                    class="bg-brandblue h-2 rounded-full transition-all duration-300"
                    :style="{ width: `${(currentStep / (userExists && props.event.is_paid ? 3 : (props.event.is_paid ? 4 : 3))) * 100}%` }"
                  ></div>
                </div>
              </div>

              <!-- Шаг 1: Проверка email -->
              <div v-if="currentStep === STEPS.EMAIL_CHECK" class="space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                  Введите ваш email адрес для начала регистрации
                </p>
                
                <form @submit.prevent="checkEmail" class="space-y-4">
                  <TextInput
                    id="email"
                    label="Email адрес *"
                    v-model="emailForm.email"
                    type="email"
                    :required="true"
                    placeholder="example@email.com"
                    :error="emailForm.errors.email"
                  />
                  
                  <button
                    type="submit"
                    :disabled="isLoading || !emailForm.email"
                    class="w-full flex justify-center items-center rounded-lg bg-brandblue px-4 py-2 text-sm font-medium text-white hover:bg-brandblue/90 focus:outline-none focus:ring-2 focus:ring-brandblue focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    <span v-if="isLoading">Проверяем...</span>
                    <span v-else class="flex items-center">
                      Продолжить
                      <ArrowRightIcon class="ml-2 h-4 w-4" />
                    </span>
                  </button>
                </form>
              </div>

              <!-- Шаг 2: Форма пользователя -->
              <div v-else-if="currentStep === STEPS.USER_FORM" class="space-y-4">
                <!-- Уведомление о найденном пользователе -->
                <div v-if="userExists" class="rounded-lg bg-green-50 dark:bg-green-900/20 p-4">
                  <div class="flex">
                    <CheckCircleIcon class="h-5 w-5 text-green-400 flex-shrink-0" />
                    <div class="ml-3">
                      <p class="text-sm font-medium text-green-800 dark:text-green-200">
                        Пользователь найден!
                      </p>
                      <p class="mt-1 text-sm text-green-700 dark:text-green-300">
                        Мы нашли ваш аккаунт в системе. {{ props.event.is_paid ? 'Нажмите "Продолжить" для перехода к оплате.' : 'Нажмите "Зарегистрироваться" для завершения.' }}
                      </p>
                    </div>
                  </div>
                </div>

                <!-- Уведомление о новом пользователе -->
                <div v-else class="rounded-lg bg-blue-50 dark:bg-blue-900/20 p-4">
                  <div class="flex">
                    <ExclamationCircleIcon class="h-5 w-5 text-blue-400 flex-shrink-0" />
                    <div class="ml-3">
                      <p class="text-sm font-medium text-blue-800 dark:text-blue-200">
                        Новый пользователь
                      </p>
                      <p class="mt-1 text-sm text-blue-700 dark:text-blue-300">
                        Заполните форму для завершения регистрации.
                      </p>
                    </div>
                  </div>
                </div>

                <!-- Форма для существующих пользователей -->
                <div v-if="userExists" class="space-y-4">
                  <!-- Email (только для отображения) -->
                  <TextInput
                    id="display_email"
                    label="Email *"
                    :modelValue="registrationForm.email"
                    type="email"
                    :disabled="true"
                    :error="''"
                  />

                  <!-- Кнопки для существующего пользователя -->
                  <div class="flex space-x-3 pt-4">
                    <button
                      type="button"
                      @click="goBack"
                      class="flex-1 flex justify-center items-center rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-brandblue focus:ring-offset-2"
                    >
                      <ArrowLeftIcon class="mr-2 h-4 w-4" />
                      Назад
                    </button>
                    <button
                      type="button"
                      @click="submitRegistration"
                      :disabled="isLoading"
                      class="flex-1 flex justify-center items-center rounded-lg bg-brandcoral px-4 py-2 text-sm font-medium text-white hover:bg-brandcoral/90 focus:outline-none focus:ring-2 focus:ring-brandcoral focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                      <span v-if="isLoading">{{ props.event.is_paid ? 'Переходим к оплате...' : 'Регистрируем...' }}</span>
                      <span v-else>{{ props.event.is_paid ? 'Перейти к оплате' : 'Зарегистрироваться' }}</span>
                    </button>
                  </div>
                </div>

                <!-- Форма для новых пользователей -->
                <form v-else @submit.prevent="submitRegistration" class="space-y-4">
                  <!-- Имя и Фамилия -->
                  <div class="grid grid-cols-2 gap-3">
                    <TextInput
                      id="first_name"
                      label="Имя *"
                      v-model="registrationForm.first_name"
                      type="text"
                      :required="true"
                      placeholder="Введите имя"
                      :error="registrationForm.errors.first_name"
                    />
                    <TextInput
                      id="last_name"
                      label="Фамилия *"
                      v-model="registrationForm.last_name"
                      type="text"
                      :required="true"
                      placeholder="Введите фамилию"
                      :error="registrationForm.errors.last_name"
                    />
                  </div>

                  <!-- Отчество -->
                  <TextInput
                    id="middle_name"
                    label="Отчество"
                    v-model="registrationForm.middle_name"
                    type="text"
                    placeholder="Введите отчество"
                    :error="registrationForm.errors.middle_name"
                  />

                  <!-- Email (только для отображения) -->
                  <TextInput
                    id="display_email"
                    label="Email *"
                    :modelValue="registrationForm.email"
                    type="email"
                    :disabled="true"
                    :error="''"
                  />

                  <!-- Телефон -->
                  <PhoneInput
                    id="phone"
                    label="Телефон *"
                    v-model="registrationForm.phone"
                    :required="true"
                    :error="registrationForm.errors.phone"
                    placeholder="(999) 123-45-67"
                  />

                  <!-- Город -->
                  <CityAutocomplete
                    id="city"
                    label="Город *"
                    v-model="registrationForm.city"
                    :required="true"
                    :error="registrationForm.errors.city"
                    placeholder="Введите город"
                  />

                  <!-- Направление деятельности / специализация -->
                  <TextInput
                    id="specialization"
                    label="Направление деятельности / специализация"
                    v-model="registrationForm.specialization"
                    type="text"
                    :error="registrationForm.errors.specialization"
                    placeholder="Введите специализацию (необязательно)"
                  />

                  <!-- Кнопки -->
                  <div class="flex space-x-3 pt-4">
                    <button
                      type="button"
                      @click="goBack"
                      class="flex-1 flex justify-center items-center rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-brandblue focus:ring-offset-2"
                    >
                      <ArrowLeftIcon class="mr-2 h-4 w-4" />
                      Назад
                    </button>
                    <button
                      type="submit"
                      :disabled="registrationForm.processing"
                      class="flex-1 flex justify-center items-center rounded-lg bg-brandcoral px-4 py-2 text-sm font-medium text-white hover:bg-brandcoral/90 focus:outline-none focus:ring-2 focus:ring-brandcoral focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                      <span v-if="registrationForm.processing">Регистрируем...</span>
                      <span v-else>Зарегистрироваться</span>
                    </button>
                  </div>
                </form>
              </div>

              <!-- Шаг 3: Оплата -->
              <div v-else-if="currentStep === STEPS.PAYMENT" class="text-center space-y-4">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-yellow-100 dark:bg-yellow-900/20">
                  <ExclamationCircleIcon class="h-6 w-6 text-yellow-600 dark:text-yellow-400" />
                </div>
                
                <div>
                  <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                    Переход к оплате
                  </h3>
                  <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    Вы будете перенаправлены на страницу оплаты для завершения регистрации на мероприятие "{{ event.title }}".
                  </p>
                </div>
                
                <div class="flex space-x-3">
                  <button
                    @click="goBack"
                    class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-brandblue focus:ring-offset-2"
                  >
                    Назад
                  </button>
                  <button
                    @click="proceedToPayment"
                    class="flex-1 rounded-lg bg-brandcoral px-4 py-2 text-sm font-medium text-white hover:bg-brandcoral/90 focus:outline-none focus:ring-2 focus:ring-brandcoral focus:ring-offset-2"
                  >
                    Перейти к оплате
                  </button>
                </div>
              </div>

              <!-- Шаг 4: Подтверждение -->
              <div v-else-if="currentStep === STEPS.CONFIRMATION" class="text-center space-y-4">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/20">
                  <CheckCircleIcon class="h-6 w-6 text-green-600 dark:text-green-400" />
                </div>
                
                <div>
                  <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                    Регистрация успешна!
                  </h3>
                  <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    Вы успешно зарегистрированы на мероприятие "{{ event.title }}". 
                    Подробности будут отправлены на ваш email.
                  </p>
                </div>
                
                <button
                  @click="closeModal"
                  class="w-full rounded-lg bg-brandblue px-4 py-2 text-sm font-medium text-white hover:bg-brandblue/90 focus:outline-none focus:ring-2 focus:ring-brandblue focus:ring-offset-2"
                >
                  Закрыть
                </button>
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>