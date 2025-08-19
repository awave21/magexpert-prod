<script setup>
import { Head, Link } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { 
  CheckCircleIcon,
  ArrowRightIcon
} from '@heroicons/vue/24/outline';
import { 
  HomeIcon,
  ChevronRightIcon 
} from '@heroicons/vue/20/solid';

// Получаем данные из пропсов
const props = defineProps({
  message: {
    type: String,
    default: 'Оплата прошла успешно!'
  },
  description: {
    type: String,
    default: 'Спасибо за вашу оплату. Подтверждение будет отправлено на ваш email.'
  }
});
</script>

<template>
  <Head title="Оплата прошла успешно" />
  
  <MainLayout>
    <!-- Градиентный фон для всей страницы -->
    <div class="min-h-screen bg-gradient-to-br from-green-50/[0.3] to-white/95 dark:from-green-900/10 dark:to-gray-900">
      <!-- Отступ перед шапкой -->
      <div class="pt-8">
        <!-- Шапка страницы с фоном -->
        <div class="mx-auto max-w-[1440px] px-4 sm:px-6 lg:px-8">
          <div class="bg-gradient-to-r from-green-600 to-green-500 py-12 text-white rounded-2xl">
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
                          <span class="ml-1 font-medium text-white" aria-current="page">Успешная оплата</span>
                        </div>
                      </li>
                    </ol>
                  </nav>
                  
                  <h1 class="mt-4 text-3xl font-bold tracking-tight text-white sm:text-4xl">{{ message }}</h1>
                  <p class="mt-4 text-lg text-white/90">
                    {{ description }}
                  </p>
                </div>
                
                <!-- Правая колонка с иконкой успеха -->
                <div class="flex items-center justify-center md:justify-end">
                  <div class="inline-flex h-32 w-32 items-center justify-center rounded-full bg-white/10 backdrop-blur-sm">
                    <CheckCircleIcon class="h-16 w-16 text-white" />
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Основное содержимое -->
      <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
          <!-- Левая колонка с информацией -->
          <div class="space-y-6">
            <!-- Статус платежа -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700">
              <div class="flex items-start space-x-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/20">
                  <CheckCircleIcon class="h-6 w-6 text-green-600 dark:text-green-400" />
                </div>
                <div class="flex-1">
                  <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Платеж завершен</h3>
                  <p class="mt-1 text-green-600 dark:text-green-400 font-medium">Оплата прошла успешно</p>
                  <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ new Date().toLocaleString('ru-RU', {
                      year: 'numeric',
                      month: 'long',
                      day: 'numeric',
                      hour: '2-digit',
                      minute: '2-digit'
                    }) }}
                  </p>
                </div>
              </div>
            </div>

            <!-- Что дальше? -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700">
              <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Что дальше?</h3>
              
              <div class="space-y-4">
                <!-- Уведомление по email -->
                <div class="flex items-start space-x-3">
                  <div class="flex h-8 w-8 min-h-8 min-w-8 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/20 mt-0.5 flex-shrink-0">
                    <span class="text-sm font-medium text-green-600 dark:text-green-400">1</span>
                  </div>
                  <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Проверьте электронную почту</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                      На ваш email будет отправлена информация о регистрации на мероприятие
                    </p>
                  </div>
                </div>

                <!-- Личный кабинет -->
                <div class="flex items-start space-x-3">
                  <div class="flex h-8 w-8 min-h-8 min-w-8 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/20 mt-0.5 flex-shrink-0">
                    <span class="text-sm font-medium text-green-600 dark:text-green-400">2</span>
                  </div>
                  <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Войдите в личный кабинет</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                      Все ваши мероприятия доступны в личном кабинете
                    </p>
                  </div>
                </div>

                <!-- Подготовка к мероприятию -->
                <div class="flex items-start space-x-3">
                  <div class="flex h-8 w-8 min-h-8 min-w-8 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/20 mt-0.5 flex-shrink-0">
                    <span class="text-sm font-medium text-green-600 dark:text-green-400">3</span>
                  </div>
                  <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Подготовьтесь к мероприятию</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                      Дополнительная информация будет отправлена заранее
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Правая колонка с действиями -->
          <div class="space-y-6">
            <!-- Основные действия -->
            <div class="bg-gradient-to-r from-brandblue to-brandcoral rounded-2xl p-6 text-white">
              <h3 class="text-lg font-semibold mb-4">Полезные ссылки</h3>
              
              <div class="space-y-3">
                <!-- Личный кабинет -->
                <Link :href="route('dashboard')" 
                      class="flex items-center justify-between w-full p-3 bg-white/10 rounded-lg hover:bg-white/20 transition-colors">
                  <span class="font-medium">Личный кабинет</span>
                  <ArrowRightIcon class="h-5 w-5" />
                </Link>

                <!-- Все мероприятия -->
                <Link :href="route('events.index')"
                      class="flex items-center justify-between w-full p-3 bg-white/10 rounded-lg hover:bg-white/20 transition-colors">
                  <span class="font-medium">Все мероприятия</span>
                  <ArrowRightIcon class="h-5 w-5" />
                </Link>

                <!-- Главная -->
                <Link :href="route('welcome')"
                      class="flex items-center justify-between w-full p-3 bg-white/10 rounded-lg hover:bg-white/20 transition-colors">
                  <span class="font-medium">На главную</span>
                  <ArrowRightIcon class="h-5 w-5" />
                </Link>
              </div>
            </div>

            <!-- Дополнительная информация -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700">
              <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-3">Спасибо за регистрацию!</h3>
              <p class="text-sm text-gray-600 dark:text-gray-300 mb-3">
                Ваша оплата обработана, и регистрация на мероприятие завершена.
              </p>
              <p class="text-sm text-gray-600 dark:text-gray-300">
                Если у вас есть вопросы, обратитесь в нашу службу поддержки по адресу 
                <a href="mailto:support@med-ag.ru" class="text-brandblue hover:underline">support@med-ag.ru</a>
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </MainLayout>
</template>