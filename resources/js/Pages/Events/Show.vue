<script setup>
import { ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useToast } from 'vue-toastification';
import MainLayout from '@/Layouts/MainLayout.vue';
import { CalendarDaysIcon, MapPinIcon, UserGroupIcon, BanknotesIcon, ClockIcon } from '@heroicons/vue/24/outline';
import { ArrowLeftIcon, HomeIcon, ChevronRightIcon } from '@heroicons/vue/20/solid';
import EventRegistrationModal from '@/Components/EventRegistrationModal.vue';

const props = defineProps({
  event: Object,
});

const showRegistrationModal = ref(false);
const isSubmitting = ref(false);

const page = usePage();
const toast = useToast();

const handleRegistrationClick = () => {
  // уже есть доступ
  if (props.event.user_has_access) {

  router.get(route('events.index'), { filter: tab }, {
  preserveState: true,
  preserveScroll: true,
});

    return;
  }

  // регистрация недоступна
  if (!isRegistrationAvailable(props.event) || isSubmitting.value) return;

  const user = page.props.auth?.user;

  // ==== По запросу: ведём как бесплатное ====
  if (props.event.is_on_demand) {
    if (user) {
      // авторизованный → мгновенная регистрация (fast)
      isSubmitting.value = true;
      router.post(route('events.register', props.event.slug), { fast: true }, {
        preserveScroll: true,
        onSuccess: () => {
          toast.success('Вы успешно зарегистрированы на мероприятие');
        },
        onError: () => {
          toast.error('Не удалось выполнить регистрацию');
        },
        onFinish: () => {
          isSubmitting.value = false;
        },
      });
      return;
    }
    // гость → модалка
    showRegistrationModal.value = true;
    return;
  }
  // ==== конец on_demand ====

  // ==== Обычный сценарий ====
  if (user) {
    isSubmitting.value = true;
    router.post(route('events.register', props.event.slug), { fast: true }, {
      preserveScroll: true,
      onSuccess: () => {
        toast.success('Вы успешно зарегистрированы на мероприятие');
      },
      onError: () => {
        toast.error('Не удалось выполнить регистрацию');
      },
      onFinish: () => {
        isSubmitting.value = false;
      },
    });
    return;
  }
  // гость
  showRegistrationModal.value = true;
};

const closeRegistrationModal = () => {
  showRegistrationModal.value = false;
};

const formatDate = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
};

const formatPrice = (price) => {
  if (price === null || price === 0) return 'Бесплатно';
  const numPrice = parseFloat(price);
  return numPrice.toLocaleString('ru-RU', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
    useGrouping: true
  }).replace(/,/g, ' ');
};

const getDisplayPrice = (event) => {
  if (!event.is_paid) return 'Бесплатно';
  if (event.is_paid && !event.show_price) return 'Платно';
  if (event.is_paid && event.show_price && (!event.price || event.price === 0)) return 'Платно';
  return formatPrice(event.price);
};

const shouldShowCurrency = (event) => {
  return event.is_paid && event.show_price && event.price && event.price > 0;
};

const getButtonText = (event) => {
  if (event.is_archived) {
    if (event.is_paid) {
      return event.show_price && event.price ? 'Купить доступ к записи' : 'Получить доступ к записи';
    } else {
      return event.has_recording ? 'Получить доступ к записи' : 'Подробнее';
    }
  } else if (event.is_on_demand) {
    return 'Зарегистрироваться'; // по запросу = как бесплатное
  } else if (event.is_paid) {
    return event.show_price && event.price ? 'Принять участие' : 'Записаться';
  } else {
    return 'Зарегистрироваться';
  }
};

const isRegistrationAvailable = (event) => {
  if (!event.registration_enabled) return false;
  if (event.is_archived) {
    return event.has_recording;
  }
  if (event.format === 'offline' && event.start_date && new Date(event.start_date) < new Date() && !event.is_archived) {
    return false;
  }
  return true;
};

const getEventType = (event) => {
  const types = {
    'webinar': 'Вебинар',
    'conference': 'Конференция',
    'course': 'Курс',
    'workshop': 'Мастер-класс',
    'seminar': 'Семинар'
  };
  return types[event.event_type] || event.event_type;
};

const formatOptions = {
  'online': 'Онлайн',
  'offline': 'Офлайн',
  'hybrid': 'Гибридный',
};

const declensionExperts = (count) => {
  const declension = ['эксперт', 'эксперта', 'экспертов'];
  if (count % 100 >= 11 && count % 100 <= 19) return declension[2];
  const lastDigit = count % 10;
  if (lastDigit === 1) return declension[0];
  if (lastDigit >= 2 && lastDigit <= 4) return declension[1];
  return declension[2];
};

const getFirstRegaliaLine = (regalia) => {
  if (!regalia) return '';
  return regalia.split('\n')[0].trim();
};
</script>

<template>
  <Head :title="event.title">
    <meta name="dadata-token" content="YOUR_DADATA_TOKEN_HERE" />
  </Head>

  <MainLayout>
    <div class="min-h-screen bg-gradient-to-br from-brandblue/[0.03] to-white/95 dark:from-brandblue/10 dark:to-gray-900">
      <div class="pt-8">
        <div class="mx-auto max-w-[1440px] px-4 sm:px-6 lg:px-8">
          <div class="bg-brandblue py-8 sm:py-12 text-white rounded-2xl">
            <div class="mx-auto px-4 sm:px-6 lg:px-8">
              <nav class="flex text-sm text-white/80 overflow-hidden mb-6" aria-label="Хлебные крошки">
                <ol class="flex items-center space-x-1 flex-nowrap min-w-0">
                  <li class="flex-shrink-0">
                    <div class="flex items-center">
                      <Link href="/" class="hover:text-white">
                        <HomeIcon class="h-4 w-4 flex-shrink-0" aria-hidden="true" />
                        <span class="sr-only">Главная</span>
                      </Link>
                    </div>
                  </li>
                  <li class="flex-shrink-0">
                    <div class="flex items-center">
                      <ChevronRightIcon class="h-5 w-5 flex-shrink-0 text-white/60" aria-hidden="true" />
                      <Link :href="route('events.index')" class="ml-1 hover:text-white whitespace-nowrap">
                        Мероприятия
                      </Link>
                    </div>
                  </li>
                  <li class="flex-shrink-1 min-w-0">
                    <div class="flex items-center min-w-0">
                      <ChevronRightIcon class="h-5 w-5 flex-shrink-0 text-white/60" aria-hidden="true" />
                      <span class="ml-1 font-medium text-white truncate" aria-current="page">{{ event.title }}</span>
                    </div>
                  </li>
                </ol>
              </nav>

              <div class="flex flex-wrap gap-2 mb-4">
                <span class="inline-flex items-center rounded-full bg-white/20 px-2.5 py-1 text-xs font-medium text-white">
                  {{ getEventType(event) }}
                </span>
                <span v-if="event.is_archived" class="inline-flex items-center rounded-full bg-brandcoral/90 px-2.5 py-1 text-xs font-medium text-white">
                  Архив
                </span>
                <span v-if="event.is_on_demand" class="inline-flex items-center rounded-full bg-brandblue/90 px-2.5 py-1 text-xs font-medium text-white">
                  По запросу
                </span>
                <span v-if="event.format" class="inline-flex items-center rounded-full bg-brandblue/70 px-2.5 py-1 text-xs font-medium text-white">
                  {{ formatOptions[event.format] || event.format }}
                </span>

                <template v-if="event.categories && event.categories.length > 0">
                  <span
                    v-for="(category, index) in event.categories.slice(0, 2)"
                    :key="category.id"
                    class="inline-flex items-center rounded-full bg-white/10 px-2.5 py-1 text-xs font-medium text-white/90"
                  >
                    {{ category.name }}
                  </span>
                  <span v-if="event.categories.length > 2" class="inline-flex items-center rounded-full bg-white/10 px-2.5 py-1 text-xs font-medium text-white/70">
                    +{{ event.categories.length - 2 }}
                  </span>
                </template>

                <span v-else-if="event.category" class="inline-flex items-center rounded-full bg-white/10 px-2.5 py-1 text-xs font-medium text-white/90">
                  {{ event.category.name }}
                </span>
              </div>

              <h1 class="text-xl leading-tight font-bold tracking-tight text-white sm:text-2xl md:text-3xl lg:text-4xl">
                {{ event.title }}
              </h1>
            </div>
          </div>
        </div>
      </div>

      <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-6 lg:px-8">
        <div class="mb-6">
          <Link :href="route('events.index')" class="inline-flex items-center text-sm font-medium text-gray-700 hover:text-brandblue dark:text-gray-300 dark:hover:text-brandblue">
            <ArrowLeftIcon class="mr-1.5 h-4 w-4" />
            Назад к мероприятиям
          </Link>
        </div>

        <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
          <div class="lg:col-span-2 space-y-8">
            <div v-if="event.image" class="overflow-hidden rounded-xl">
              <img :src="event.image" :alt="event.title" class="w-full h-auto object-cover" />
            </div>

            <div class="bg-white rounded-xl p-6 border border-gray-200 dark:border-gray-700 dark:bg-gray-800">
              <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-4">О мероприятии</h2>
              <div class="prose prose-brandblue max-w-none dark:prose-invert" v-html="event.full_description"></div>
            </div>

            <div v-if="event.speakers && event.speakers.length > 0" class="bg-white rounded-xl p-6 border border-gray-200 dark:border-gray-700 dark:bg-gray-800">
              <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-4">
                Спикеры ({{ event.speakers.length }} {{ declensionExperts(event.speakers.length) }})
              </h2>

              <div class="space-y-6">
                <div v-for="speaker in event.speakers" :key="speaker.id" class="flex space-x-4">
                  <div class="h-16 w-16 rounded-full overflow-hidden flex-shrink-0 border border-gray-200 dark:border-gray-600">
                    <img v-if="speaker.photo" :src="speaker.photo" :alt="speaker.full_name" class="h-full w-full object-cover" />
                    <div v-else class="h-full w-full flex items-center justify-center bg-brandblue/10 text-brandblue text-lg">
                      {{ speaker.first_name[0] }}{{ speaker.last_name[0] }}
                    </div>
                  </div>

                  <div class="flex-1">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">
                      {{ speaker.full_name || `${speaker.first_name} ${speaker.last_name}` }}
                    </h3>

                    <p v-if="speaker.position || speaker.company" class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                      {{ [speaker.position, speaker.company].filter(Boolean).join(', ') }}
                    </p>

                    <div v-if="speaker.pivot && (speaker.pivot.role || speaker.pivot.topic)" class="mt-2 p-3 bg-brandblue/5 rounded-lg border-l-4 border-brandblue dark:bg-brandblue/10">
                      <div class="text-sm">
                        <span v-if="speaker.pivot.role" class="font-semibold text-brandblue dark:text-brandblue">{{ speaker.pivot.role }}</span>
                        <span v-if="speaker.pivot.role && speaker.pivot.topic" class="text-gray-500 dark:text-gray-400"> • </span>
                        <span v-if="speaker.pivot.topic" class="italic text-gray-700 dark:text-gray-300">{{ speaker.pivot.topic }}</span>
                      </div>
                    </div>

                    <div v-if="speaker.regalia" class="mt-3">
                      <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-1">Регалии:</h4>
                      <div class="text-sm text-gray-600 dark:text-gray-400 whitespace-pre-line leading-relaxed">
                        {{ speaker.regalia }}
                      </div>
                    </div>

                    <div v-else-if="getFirstRegaliaLine(speaker.regalia)" class="mt-2 text-sm text-gray-600 dark:text-gray-400 italic">
                      {{ getFirstRegaliaLine(speaker.regalia) }}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="space-y-6">
            <div class="bg-white rounded-xl p-6 border border-gray-200 dark:border-gray-700 dark:bg-gray-800">
              <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Регистрация</h2>

              <div class="space-y-4">
                <div v-if="event.start_date && !event.is_on_demand" class="flex items-start">
                  <CalendarDaysIcon class="mr-2 h-5 w-5 text-brandblue flex-shrink-0" />
                  <div>
                    <div class="font-medium">Дата проведения</div>
                    <div class="text-gray-600 dark:text-gray-400">{{ formatDate(event.start_date) }}</div>
                    <div v-if="event.start_time" class="flex items-center mt-1 text-gray-600 dark:text-gray-400">
                      <ClockIcon class="mr-1 h-4 w-4" />
                      <span>{{ event.start_time }}</span>
                    </div>
                  </div>
                </div>

                <div v-if="event.location" class="flex items-start">
                  <MapPinIcon class="mr-2 h-5 w-5 text-brandcoral flex-shrink-0" />
                  <div>
                    <div class="font-medium">Место проведения</div>
                    <div class="text-gray-600 dark:text-gray-400">{{ event.location }}</div>
                  </div>
                </div>
                <div v-if="event.max_quantity" class="flex items-start">
                <div class="mr-2 h-5 w-5 text-brandcoral flex-shrink-0"></div>
                <div>
                  <div class="font-medium">Количество мест</div>
                  <div class="text-gray-600 dark:text-gray-400">
                    {{ event.max_quantity }} <span v-if="event.available_spots !== null"> (осталось: {{ event.available_spots }})</span>
                  </div>
                </div>
                </div>


                <div class="flex items-start">
                  <BanknotesIcon class="mr-2 h-5 w-5 text-brandblue flex-shrink-0" />
                  <div class="w-full">
                    <div class="font-medium">Стоимость</div>
                    <div class="text-xl font-bold text-gray-900 dark:text-white">
                      {{ getDisplayPrice(event) }} <span v-if="shouldShowCurrency(event)"> ₽</span>
                    </div>
                    <div v-if="event.is_paid && !event.show_price" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                      Стоимость уточняется при регистрации
                    </div>
                    <div v-else-if="event.is_paid && event.show_price && (!event.price || event.price === 0)" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                      Цена будет объявлена позже
                    </div>
                  </div>
                </div>

                <button
                v-if="!(event.is_on_demand && event.user_has_access)"
                  @click="handleRegistrationClick"

                  class="w-full mt-4 inline-flex justify-center items-center rounded-lg px-4 py-3 text-base font-medium text-white transition-all duration-200"
                  :class="[
                    event.user_has_access ? 'bg-brandblue hover:bg-brandblue/90' : 'bg-brandcoral hover:bg-brandcoral/90',
                    { 'opacity-50 cursor-not-allowed': !isRegistrationAvailable(event) || isSubmitting }
                  ]"
                  :disabled="!isRegistrationAvailable(event) || isSubmitting"
                >
                  <span>{{ event.user_has_access ? 'Перейти к контенту мероприятия' : getButtonText(event) }}</span>
                </button>
                <div v-if="event.user_has_access && event.is_on_demand" class="mt-3 p-3 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-800">
                  <div class="flex items-center text-sm text-green-800 dark:text-green-200">
                    <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    Вы отправили запрос на это мероприятие. Менеджер свяжется с вами.
                  </div>
                </div>
                <div v-if="event.user_has_access && !event.is_on_demand" class="mt-3 p-3 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-800">
                  <div class="flex items-center text-sm text-green-800 dark:text-green-200">
                    <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    Вы зарегистрированы на это мероприятие. Контент доступен для просмотра.
                  </div>
                </div>

                <div v-else-if="!isRegistrationAvailable(event)" class="text-sm text-center text-gray-500 dark:text-gray-400">
                  <p v-if="event.is_archived && !event.has_recording">Запись мероприятия пока недоступна</p>
                  <p v-else-if="!event.registration_enabled">Регистрация временно недоступна</p>
                  <p v-else-if="event.format === 'offline' && event.start_date && new Date(event.start_date) < new Date() && !event.is_archived">Мероприятие уже прошло</p>
                </div>
              </div>
            </div>

            <div v-if="event.topic" class="bg-white rounded-xl p-6 border border-gray-200 dark:border-gray-700 dark:bg-gray-800">
              <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Тема мероприятия</h2>
              <p class="text-gray-600 dark:text-gray-400">{{ event.topic }}</p>
            </div>

            <div v-if="(event.categories && event.categories.length > 0) || event.category" class="bg-white rounded-xl p-6 border border-gray-200 dark:border-gray-700 dark:bg-gray-800">
              <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-4">
                {{ (event.categories && event.categories.length > 1) ? 'Категории' : 'Категория' }}
              </h2>

              <div v-if="event.categories && event.categories.length > 0" class="flex flex-wrap gap-2">
                <Link
                  v-for="category in event.categories"
                  :key="category.id"
                  :href="route('events.index', { category: category.id })"
                  class="inline-flex items-center rounded-md bg-brandblue/10 px-3 py-1 text-sm font-medium text-brandblue hover:bg-brandblue/20 transition-colors"
                >
                  {{ category.name }}
                </Link>
              </div>

              <div v-else-if="event.category" class="flex flex-wrap gap-2">
                <Link
                  :href="route('events.index', { category: event.category.id })"
                  class="inline-flex items-center rounded-md bg-brandblue/10 px-3 py-1 text-sm font-medium text-brandblue hover:bg-brandblue/20 transition-colors"
                >
                  {{ event.category.name }}
                </Link>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <EventRegistrationModal
      :show="showRegistrationModal"
      :event="event"
      @close="closeRegistrationModal"
    />
  </MainLayout>
</template>
