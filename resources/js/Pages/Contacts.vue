<script setup>
import { ref, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { 
  PhoneIcon, 
  EnvelopeIcon, 
  MapPinIcon,
  BuildingOfficeIcon
} from '@heroicons/vue/24/outline';
import { 
  HomeIcon,
  ChevronRightIcon 
} from '@heroicons/vue/20/solid';

// Реактивная переменная для карты
const mapContainer = ref(null);
const mapLoaded = ref(false);
const mapError = ref(false);

// Данные контактов
const contactInfo = {
  address: {
    title: 'Адрес',
    value: 'Москва, ул. Рабочая д.93 стр. 1, офис 250',
    icon: MapPinIcon
  },
  cooperation: {
    title: 'По вопросам сотрудничества',
    phones: ['+7(495) 664-67-53', '+7 (995) 222-07-79'],
    icon: PhoneIcon
  },
  email: {
    title: 'E-mail',
    value: 'info@gin-kongress.ru',
    icon: EnvelopeIcon
  },
  support: {
    title: 'Техническая поддержка',
    value: 'info@gin-kongress.ru',
    icon: BuildingOfficeIcon
  }
};

// Инициализация Яндекс карты
onMounted(() => {
  loadYandexMaps();
});

const loadYandexMaps = () => {
  // Проверяем, загружен ли API Яндекс карт
  if (typeof window.ymaps !== 'undefined') {
    console.log('Яндекс карты уже загружены');
    window.ymaps.ready(initYandexMap);
  } else {
    console.log('Загружаем API Яндекс карт...');
    // Загружаем API Яндекс карт
    const script = document.createElement('script');
    script.src = 'https://api-maps.yandex.ru/2.1/?apikey=75d9f0b5-7a64-40e8-b3d0-4546dc5c521b&lang=ru_RU';
    
    script.onload = () => {
      console.log('API Яндекс карт загружен');
      window.ymaps.ready(() => {
        console.log('ymaps готов к использованию');
        initYandexMap();
      });
    };
    
    script.onerror = (error) => {
      console.error('Ошибка загрузки API Яндекс карт:', error);
      mapError.value = true;
    };
    
    document.head.appendChild(script);
  }
};

const initYandexMap = () => {
  console.log('Инициализация карты...');
  
  if (!mapContainer.value) {
    console.error('Контейнер карты не найден');
    return;
  }

  try {
    const coords = [55.740487, 37.695923];
    
    const map = new window.ymaps.Map(mapContainer.value, {
      center: coords,
      zoom: 16,
      controls: ['zoomControl', 'fullscreenControl', 'geolocationControl']
    });

    const placemark = new window.ymaps.Placemark(coords, {
      balloonContent: '<strong>Наш офис</strong><br>Москва, ул. Рабочая д.93 стр. 1, офис 250',
      hintContent: 'Наш офис'
    }, {
      preset: 'islands#redDotIcon'
    });

    map.geoObjects.add(placemark);
    mapLoaded.value = true;
    
  } catch (error) {
    console.error('Ошибка инициализации карты:', error);
    mapError.value = true;
  }
};
</script>

<template>
  <Head title="Контакты" />
  
  <MainLayout>
    <!-- Градиентный фон для всей страницы -->
    <div class="min-h-screen bg-gradient-to-br from-brandblue/[0.03] to-white/95 dark:from-brandblue/10 dark:to-gray-900">
      <!-- Отступ перед шапкой -->
      <div class="pt-8">
        <!-- Шапка страницы с фоном -->
        <div class="mx-auto max-w-[1440px] px-4 sm:px-6 lg:px-8">
          <div class="bg-brandblue py-12 text-white rounded-2xl">
            <div class="mx-auto px-6 sm:px-8 lg:px-10">
              <div class="grid grid-cols-1 gap-8 md:grid-cols-2">
                <!-- Левая колонка с заголовком и хлебными крошками -->
                <div>
                  <!-- Хлебные крошки -->
                  <nav class="flex text-sm text-white/80" aria-label="Хлебные крошки">
                    <ol class="flex items-center space-x-1">
                      <li>
                        <div class="flex items-center">
                          <Link :href="route('welcome')" class="hover:text-white">
                            <HomeIcon class="h-4 w-4 flex-shrink-0" aria-hidden="true" />
                            <span class="sr-only">Главная</span>
                          </Link>
                        </div>
                      </li>
                      <li>
                        <div class="flex items-center">
                          <ChevronRightIcon class="h-5 w-5 flex-shrink-0 text-white/60" aria-hidden="true" />
                          <span class="ml-1 font-medium text-white" aria-current="page">Контакты</span>
                        </div>
                      </li>
                    </ol>
                  </nav>
                  
                  <h1 class="mt-4 text-3xl font-bold tracking-tight text-white sm:text-4xl">Контакты</h1>
                  <p class="mt-4 text-lg text-white/90">
                    Свяжитесь с нами для получения дополнительной информации или технической поддержки.
                  </p>
                </div>
                
                <!-- Правая колонка с иконкой контактов -->
                <div class="hidden md:flex items-center justify-center md:justify-end">
                  <div class="inline-flex h-32 w-32 items-center justify-center rounded-full bg-white/10 backdrop-blur-sm">
                    <EnvelopeIcon class="h-16 w-16 text-white" />
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Основное содержимое -->
      <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
          <!-- Левая колонка с контактной информацией -->
          <div class="space-y-6">
            <!-- Адрес -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700">
              <div class="flex items-start space-x-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-brandblue/10">
                  <component :is="contactInfo.address.icon" class="h-6 w-6 text-brandblue" />
                </div>
                <div class="flex-1">
                  <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ contactInfo.address.title }}</h3>
                  <p class="mt-1 text-gray-600 dark:text-gray-300">{{ contactInfo.address.value }}</p>
                </div>
              </div>
            </div>

            <!-- Телефоны для сотрудничества -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700">
              <div class="flex items-start space-x-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-brandcoral/10">
                  <component :is="contactInfo.cooperation.icon" class="h-6 w-6 text-brandcoral" />
                </div>
                <div class="flex-1">
                  <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ contactInfo.cooperation.title }}</h3>
                  <div class="mt-1 space-y-1">
                    <p v-for="phone in contactInfo.cooperation.phones" :key="phone" class="text-gray-600 dark:text-gray-300">
                      <a :href="`tel:${phone.replace(/[^+\d]/g, '')}`" class="hover:text-brandcoral transition-colors">
                        {{ phone }}
                      </a>
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Email -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700">
              <div class="flex items-start space-x-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/20">
                  <component :is="contactInfo.email.icon" class="h-6 w-6 text-green-600 dark:text-green-400" />
                </div>
                <div class="flex-1">
                  <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ contactInfo.email.title }}</h3>
                  <p class="mt-1 text-gray-600 dark:text-gray-300">
                    <a :href="`mailto:${contactInfo.email.value}`" class="hover:text-green-600 dark:hover:text-green-400 transition-colors">
                      {{ contactInfo.email.value }}
                    </a>
                  </p>
                </div>
              </div>
            </div>

            <!-- Техническая поддержка -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700">
              <div class="flex items-start space-x-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-purple-100 dark:bg-purple-900/20">
                  <component :is="contactInfo.support.icon" class="h-6 w-6 text-purple-600 dark:text-purple-400" />
                </div>
                <div class="flex-1">
                  <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ contactInfo.support.title }}</h3>
                  <p class="mt-1 text-gray-600 dark:text-gray-300">
                    <a :href="`mailto:${contactInfo.support.value}`" class="hover:text-purple-600 dark:hover:text-purple-400 transition-colors">
                      {{ contactInfo.support.value }}
                    </a>
                  </p>
                </div>
              </div>
            </div>
          </div>

          <!-- Правая колонка с картой -->
          <div class="space-y-6">
            <!-- Карта -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl overflow-hidden shadow-sm ring-1 ring-gray-200 dark:ring-gray-700">
              <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Как нас найти</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Наш офис расположен в центре Москвы</p>
              </div>
              <div ref="mapContainer" class="h-104 w-full bg-gray-100 dark:bg-gray-700 relative">
                <!-- Заглушка для карты -->
                <div v-if="!mapLoaded && !mapError" class="absolute inset-0 flex h-full items-center justify-center z-10 bg-gray-100 dark:bg-gray-700">
                  <div class="text-center text-gray-500 dark:text-gray-400">
                    <MapPinIcon class="mx-auto h-12 w-12 mb-2 animate-pulse" />
                    <p class="text-sm">Загрузка карты...</p>
                  </div>
                </div>
                
                <!-- Ошибка загрузки карты -->
                <div v-if="mapError" class="absolute inset-0 flex h-full items-center justify-center z-10 bg-gray-100 dark:bg-gray-700">
                  <div class="text-center text-gray-500 dark:text-gray-400">
                    <MapPinIcon class="mx-auto h-12 w-12 mb-2 text-red-400" />
                    <p class="text-sm">Ошибка загрузки карты</p>
                    <p class="text-xs mt-1">Москва, ул. Рабочая д.93 стр. 1, офис 250</p>
                  </div>
                </div>
              </div>
            </div>


          </div>
        </div>
      </div>
    </div>
  </MainLayout>
</template>