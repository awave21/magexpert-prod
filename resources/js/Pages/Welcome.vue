<script setup>
import { Head } from "@inertiajs/vue3";
import MainLayout from "@/Layouts/MainLayout.vue";
import AboutUs from "@/Components/AboutUs.vue";

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
    laravelVersion: {
        type: String,
        required: true,
    },
    phpVersion: {
        type: String,
        required: true,
    },
    upcomingEvent: {
        type: Object,
        default: null,
    },
});

const telegram = {
    url: "https://t.me/beautifulgynecology", // TODO: подставь реальную ссылку
    image: "storage/all/beautiful-gynecology.jpeg", // TODO: путь к картинке в /public или storage
    title: "Telegram-канал «Красивая гинекология»",
    badge: "Telegram-канал",
    description:
        "Telegram-канал «Красивая гинекология» — это профессиональное сообщество гинекологов-эстетистов, где врачи обмениваются опытом, обсуждают клинические случаи и делятся новейшей научной информацией.",
};
</script>

<template>
    <Head title="Главная" />

    <MainLayout>
        <div
            class="py-10 md:py-30 bg-gradient-to-b from-white to-gray-100 dark:from-gray-800 dark:to-gray-900"
        >
            <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8">
                <div
                    class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-center"
                >
                    <!-- Левая часть с контентом -->
                    <div class="lg:col-span-7">
                        <h1
                            class="text-4xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-5xl lg:text-6xl"
                        >
                            Профессиональное развитие медицинских специалистов
                        </h1>
                        <p
                            class="mt-6 text-xl text-gray-600 dark:text-gray-300"
                        >
                            Участвуйте в ведущих медицинских мероприятиях,
                            изучайте новейшие исследования и развивайтесь вместе
                            с экспертами отрасли
                        </p>
                        <div class="mt-10 flex flex-col sm:flex-row gap-4">
                            <a
                                :href="route('events.index')"
                                class="rounded-full bg-brandblue px-8 py-3 text-lg font-semibold text-white transition-colors hover:bg-brandblue/90 text-center"
                            >
                                Найти мероприятие
                            </a>
                            <a
                                :href="route('documents.index')"
                                class="rounded-full bg-gray-100 px-8 py-3 text-lg font-semibold text-gray-800 transition-colors hover:bg-gray-200 dark:bg-gray-700 dark:text-white dark:hover:bg-gray-600 text-center"
                            >
                                Изучить библиотеку
                            </a>
                        </div>
                    </div>

                    <!-- Правая часть с ближайшим мероприятием -->
                    <div class="lg:col-span-5">
                        <div
                            v-if="upcomingEvent"
                            class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden"
                        >
                            <!-- Изображение мероприятия -->
                            <div
                                v-if="upcomingEvent.image"
                                class="h-48 bg-cover bg-center"
                                :style="{
                                    backgroundImage: `url(${upcomingEvent.image})`,
                                }"
                            ></div>
                            <div
                                v-else
                                class="h-48 bg-gradient-to-br from-brandblue to-brandcoral flex items-center justify-center"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-16 w-16 text-white opacity-60"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                                    />
                                </svg>
                            </div>

                            <!-- Информация о мероприятии -->
                            <div class="p-6">
                                <div class="mb-4">
                                    <span
                                        class="inline-block px-3 py-1 text-xs font-semibold text-brandblue bg-brandblue/10 rounded-full mb-2"
                                    >
                                        Ближайшее мероприятие
                                    </span>
                                    <h3
                                        class="text-xl font-semibold text-gray-900 dark:text-white"
                                    >
                                        {{ upcomingEvent.title }}
                                    </h3>
                                    <p
                                        class="text-gray-600 dark:text-gray-300 mt-2 flex items-center"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="h-4 w-4 mr-2"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                                            />
                                        </svg>
                                        <span v-if="upcomingEvent.is_on_demand">
                                            По запросу
                                        </span>
                                        <span v-else>
                                            {{ upcomingEvent.start_date }}
                                            <span
                                                v-if="upcomingEvent.start_time"
                                            >
                                                в
                                                {{
                                                    upcomingEvent.start_time
                                                }}</span
                                            >
                                        </span>
                                    </p>
                                    <p
                                        v-if="
                                            upcomingEvent.location &&
                                            !upcomingEvent.is_on_demand
                                        "
                                        class="text-gray-600 dark:text-gray-300 mt-1 flex items-center"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="h-4 w-4 mr-2"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"
                                            />
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"
                                            />
                                        </svg>
                                        {{ upcomingEvent.location }}
                                    </p>
                                </div>
                                <a
                                    :href="`/events/${upcomingEvent.slug}`"
                                    class="inline-block w-full bg-brandcoral text-white font-semibold py-3 px-6 rounded-full text-center transition-colors hover:bg-brandcoral/90"
                                >
                                    {{
                                        upcomingEvent.is_on_demand
                                            ? "Смотреть"
                                            : "Записаться"
                                    }}
                                </a>
                            </div>
                        </div>

                        <!-- Заглушка, если нет ближайших мероприятий -->
                        <div
                            v-else
                            class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden"
                        >
                            <div
                                class="h-48 bg-gradient-to-br from-brandblue to-brandcoral flex items-center justify-center"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-16 w-16 text-white opacity-60"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                                    />
                                </svg>
                            </div>
                            <div class="p-6">
                                <div class="mb-4">
                                    <span
                                        class="inline-block px-3 py-1 text-xs font-semibold text-brandblue bg-brandblue/10 rounded-full mb-2"
                                    >
                                        Мероприятия
                                    </span>
                                    <h3
                                        class="text-xl font-semibold text-gray-900 dark:text-white"
                                    >
                                        Скоро появятся новые мероприятия
                                    </h3>
                                    <p
                                        class="text-gray-600 dark:text-gray-300 mt-2"
                                    >
                                        Следите за обновлениями
                                    </p>
                                </div>
                                <a
                                    :href="route('events.index')"
                                    class="inline-block w-full bg-brandcoral text-white font-semibold py-3 px-6 rounded-full text-center transition-colors hover:bg-brandcoral/90"
                                >
                                    Все мероприятия
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Блок с видеоотчетами -->
        <div
            class="py-20 md:py-24 bg-gradient-to-b from-gray-50 via-white to-gray-50 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900 relative"
        >
            <!-- Декоративные элементы -->
            <div class="absolute inset-0 overflow-hidden">
                <div
                    class="absolute -top-40 -right-40 w-80 h-80 bg-brandblue/5 rounded-full blur-3xl"
                ></div>
                <div
                    class="absolute -bottom-40 -left-40 w-80 h-80 bg-brandcoral/5 rounded-full blur-3xl"
                ></div>
            </div>
            <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8 relative">
                <div class="text-center mb-16">
                    <h2
                        class="text-4xl font-bold text-gray-900 dark:text-white mb-4"
                    >
                        Видеоотчёты с наших конгрессов
                    </h2>
                    <div
                        class="w-24 h-1 bg-gradient-to-r from-brandblue to-brandcoral mx-auto rounded-full"
                    ></div>
                </div>

                <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
                    <!-- III Конгресс -->
                    <div
                        class="group bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden hover:border-brandcoral/40 hover:shadow-xl hover:-translate-y-1 transition-all duration-300"
                    >
                        <div class="relative aspect-video">
                            <iframe
                                src="https://kinescope.io/embed/rg7LMutq7nQa6DQxMnrz84?preload=metadata&autoplay=false"
                                allow="autoplay; fullscreen; picture-in-picture; encrypted-media;"
                                frameborder="0"
                                loading="lazy"
                                class="absolute inset-0 w-full h-full"
                                title="Видеоотчёт с III Международного конгресса"
                            >
                            </iframe>
                        </div>
                        <div class="p-6">
                            <div class="mb-4">
                                <span
                                    class="inline-block px-3 py-1 text-xs font-bold text-brandcoral bg-brandcoral/15 rounded-full mb-3 group-hover:bg-brandcoral/25 transition-colors"
                                >
                                    III Конгресс
                                </span>
                                <h3
                                    class="text-xl font-bold text-gray-900 dark:text-white leading-tight mb-3 group-hover:text-brandcoral transition-colors"
                                >
                                    Видеоотчёт с III Международного конгресса
                                </h3>
                                <p
                                    class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed"
                                >
                                    "МЕЖДИСЦИПЛИНАРНЫЕ АСПЕКТЫ КЛИНИЧЕСКОЙ И
                                    ЭСТЕТИЧЕСКОЙ ГИНЕКОЛОГИИ"
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- II Конгресс -->
                    <div
                        class="group bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden hover:border-brandblue/40 hover:shadow-xl hover:-translate-y-1 transition-all duration-300"
                    >
                        <div class="relative aspect-video">
                            <iframe
                                src="https://kinescope.io/embed/0f9RsKWqa6KZcMYSxCQFRo?preload=metadata&autoplay=false"
                                allow="autoplay; fullscreen; picture-in-picture; encrypted-media;"
                                frameborder="0"
                                loading="lazy"
                                class="absolute inset-0 w-full h-full"
                                title="Видеоотчёт с II Международного конгресса"
                            >
                            </iframe>
                        </div>
                        <div class="p-6">
                            <div class="mb-4">
                                <span
                                    class="inline-block px-3 py-1 text-xs font-bold text-brandblue bg-brandblue/15 rounded-full mb-3 group-hover:bg-brandblue/25 transition-colors"
                                >
                                    II Конгресс
                                </span>
                                <h3
                                    class="text-xl font-bold text-gray-900 dark:text-white leading-tight mb-3 group-hover:text-brandblue transition-colors"
                                >
                                    Видеоотчёт с II Международного конгресса
                                </h3>
                                <p
                                    class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed"
                                >
                                    "МЕЖДИСЦИПЛИНАРНЫЕ АСПЕКТЫ КЛИНИЧЕСКОЙ И
                                    ЭСТЕТИЧЕСКОЙ ГИНЕКОЛОГИИ"
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- I Конгресс -->
                    <div
                        class="group bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden hover:border-orange-400/40 hover:shadow-xl hover:-translate-y-1 transition-all duration-300"
                    >
                        <div class="relative aspect-video">
                            <iframe
                                src="https://kinescope.io/embed/o3kjs51wvK6FDVDsRNnGfB?preload=metadata&autoplay=false"
                                allow="autoplay; fullscreen; picture-in-picture; encrypted-media;"
                                frameborder="0"
                                loading="lazy"
                                class="absolute inset-0 w-full h-full"
                                title="Видеоотчёт с I Международного конгресса"
                            >
                            </iframe>
                        </div>
                        <div class="p-6">
                            <div class="mb-4">
                                <span
                                    class="inline-block px-3 py-1 text-xs font-bold text-orange-600 bg-orange-100 dark:bg-orange-900/30 dark:text-orange-400 rounded-full mb-3 group-hover:bg-orange-200 dark:group-hover:bg-orange-900/50 transition-colors"
                                >
                                    I Конгресс
                                </span>
                                <h3
                                    class="text-xl font-bold text-gray-900 dark:text-white leading-tight mb-3 group-hover:text-orange-600 dark:group-hover:text-orange-400 transition-colors"
                                >
                                    Видеоотчёт с I Международного конгресса
                                </h3>
                                <p
                                    class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed"
                                >
                                    "МЕЖДИСЦИПЛИНАРНЫЕ АСПЕКТЫ КЛИНИЧЕСКОЙ И
                                    ЭСТЕТИЧЕСКОЙ ГИНЕКОЛОГИИ"
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Блок "О нас" -->
        <div class="py-20 md:py-24 bg-white dark:bg-gray-800">
            <!-- Декоративные линии -->
            <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Заголовок и основная информация -->
                <!-- Блок "О нас" -->
                <AboutUs />

                <!-- Реализованные проекты -->
                <div class="mb-16">
                    <h3
                        class="text-2xl font-bold text-gray-900 dark:text-white text-center mb-12"
                    >
                        В списке реализованных проектов:
                    </h3>
                    <div
                        class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-12"
                    >
                        <div
                            class="group bg-white dark:bg-gray-800 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 hover:border-brandcoral/30 hover:shadow-lg transition-all duration-300"
                        >
                            <div
                                class="w-12 h-12 bg-gradient-to-br from-brandcoral to-brandcoral/80 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform duration-300"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-6 w-6 text-white"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9v-9m0-9v9m0 9c-5 0-9-4-9-9s4-9 9-9"
                                    />
                                </svg>
                            </div>
                            <h4
                                class="font-semibold text-gray-900 dark:text-white mb-2"
                            >
                                Международные конгрессы
                            </h4>
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                C участием российских и зарубежных экспертов
                            </p>
                        </div>

                        <div
                            class="group bg-white dark:bg-gray-800 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 hover:border-brandblue/30 hover:shadow-lg transition-all duration-300"
                        >
                            <div
                                class="w-12 h-12 bg-gradient-to-br from-brandblue to-brandblue/80 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform duration-300"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-6 w-6 text-white"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"
                                    />
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"
                                    />
                                </svg>
                            </div>
                            <h4
                                class="font-semibold text-gray-900 dark:text-white mb-2"
                            >
                                Региональные
                            </h4>
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                Медицинские мероприятия
                            </p>
                        </div>

                        <div
                            class="group bg-white dark:bg-gray-800 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 hover:border-orange-400/30 hover:shadow-lg transition-all duration-300"
                        >
                            <div
                                class="w-12 h-12 bg-gradient-to-br from-orange-500 to-orange-600 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform duration-300"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-6 w-6 text-white"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                    />
                                </svg>
                            </div>
                            <h4
                                class="font-semibold text-gray-900 dark:text-white mb-2"
                            >
                                Мастер-классы
                            </h4>
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                C постановкой руки от ведущих специалистов
                            </p>
                        </div>

                        <div
                            class="group bg-white dark:bg-gray-800 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 hover:border-gray-400/30 hover:shadow-lg transition-all duration-300"
                        >
                            <div
                                class="w-12 h-12 bg-gradient-to-br from-gray-600 to-gray-700 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform duration-300"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-6 w-6 text-white"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"
                                    />
                                </svg>
                            </div>
                            <h4
                                class="font-semibold text-gray-900 dark:text-white mb-2"
                            >
                                Вебинары
                            </h4>
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                На актуальные темы
                            </p>
                        </div>
                    </div>
                    <div class="text-center">
                        <p
                            class="text-xl font-semibold text-brandblue bg-brandblue/10 rounded-2xl px-8 py-4 inline-block"
                        >
                            Мы уверены, что с нами вам будет полезно!
                        </p>
                    </div>
                </div>

                <!-- Ключевые особенности - Современный grid дизайн -->
                <div class="relative max-w-7xl mx-auto">
                    <!-- Заголовок секции -->
                    <div class="text-center mb-16">
                        <h3
                            class="text-3xl font-bold text-gray-900 dark:text-white mb-4"
                        >
                            Наши ключевые особенности
                        </h3>
                        <div
                            class="w-24 h-1 bg-gradient-to-r from-brandblue to-brandcoral mx-auto rounded-full"
                        ></div>
                    </div>

                    <!-- Сетка карточек -->
                    <div
                        class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 gap-8"
                    >
                        <!-- Профессионально -->
                        <div
                            class="group relative overflow-hidden bg-gradient-to-br from-brandblue/5 via-white to-brandblue/10 dark:from-brandblue/10 dark:via-gray-800 dark:to-brandblue/20 rounded-3xl p-8 border border-brandblue/20 hover:border-brandblue/40 hover:shadow-2xl hover:-translate-y-2 transition-all duration-500"
                        >
                            <!-- Декоративные элементы -->
                            <div
                                class="absolute top-0 right-0 w-32 h-32 bg-brandblue/10 rounded-full -translate-y-16 translate-x-16 group-hover:scale-150 transition-transform duration-700"
                            ></div>
                            <div
                                class="absolute bottom-0 left-0 w-24 h-24 bg-brandblue/5 rounded-full translate-y-12 -translate-x-12 group-hover:scale-125 transition-transform duration-700"
                            ></div>

                            <div class="relative z-10">
                                <!-- Иконка -->
                                <div
                                    class="w-16 h-16 bg-gradient-to-br from-brandblue to-brandblue/80 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 group-hover:rotate-3 transition-all duration-500 shadow-lg"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="1.5"
                                        stroke="currentColor"
                                        class="h-8 w-8 text-white"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"
                                        />
                                    </svg>
                                </div>

                                <!-- Заголовок -->
                                <h4
                                    class="text-2xl font-bold text-brandblue mb-4 tracking-wide group-hover:text-brandblue/80 transition-colors duration-300"
                                >
                                    ПРОФЕССИОНАЛЬНО
                                </h4>

                                <!-- Описание -->
                                <p
                                    class="text-gray-700 dark:text-gray-300 leading-relaxed group-hover:text-gray-900 dark:group-hover:text-gray-100 transition-colors duration-300"
                                >
                                    Профессиональный опыт от международных и
                                    российских лидеров мнений различных областей
                                    медицины
                                </p>
                            </div>
                        </div>

                        <!-- Научно -->
                        <div
                            class="group relative overflow-hidden bg-gradient-to-br from-brandcoral/5 via-white to-brandcoral/10 dark:from-brandcoral/10 dark:via-gray-800 dark:to-brandcoral/20 rounded-3xl p-8 border border-brandcoral/20 hover:border-brandcoral/40 hover:shadow-2xl hover:-translate-y-2 transition-all duration-500"
                        >
                            <!-- Декоративные элементы -->
                            <div
                                class="absolute top-0 right-0 w-32 h-32 bg-brandcoral/10 rounded-full -translate-y-16 translate-x-16 group-hover:scale-150 transition-transform duration-700"
                            ></div>
                            <div
                                class="absolute bottom-0 left-0 w-24 h-24 bg-brandcoral/5 rounded-full translate-y-12 -translate-x-12 group-hover:scale-125 transition-transform duration-700"
                            ></div>

                            <div class="relative z-10">
                                <!-- Иконка -->
                                <div
                                    class="w-16 h-16 bg-gradient-to-br from-brandcoral to-brandcoral/80 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 group-hover:rotate-3 transition-all duration-500 shadow-lg"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="1.5"
                                        stroke="currentColor"
                                        class="h-8 w-8 text-white"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443a55.378 55.378 0 0 1 5.25 2.882V15"
                                        />
                                    </svg>
                                </div>

                                <!-- Заголовок -->
                                <h4
                                    class="text-2xl font-bold text-brandcoral mb-4 tracking-wide group-hover:text-brandcoral/80 transition-colors duration-300"
                                >
                                    НАУЧНО
                                </h4>

                                <!-- Описание -->
                                <p
                                    class="text-gray-700 dark:text-gray-300 leading-relaxed group-hover:text-gray-900 dark:group-hover:text-gray-100 transition-colors duration-300"
                                >
                                    Познавательные мероприятия ориентированные
                                    как на теорию, так и на практику
                                </p>
                            </div>
                        </div>

                        <!-- Универсально -->
                        <div
                            class="group relative overflow-hidden bg-gradient-to-br from-gray-50 via-white to-gray-100 dark:from-gray-700/20 dark:via-gray-800 dark:to-gray-600/20 rounded-3xl p-8 border border-gray-200 dark:border-gray-600 hover:border-gray-400 hover:shadow-2xl hover:-translate-y-2 transition-all duration-500"
                        >
                            <!-- Декоративные элементы -->
                            <div
                                class="absolute top-0 right-0 w-32 h-32 bg-gray-200/30 dark:bg-gray-500/20 rounded-full -translate-y-16 translate-x-16 group-hover:scale-150 transition-transform duration-700"
                            ></div>
                            <div
                                class="absolute bottom-0 left-0 w-24 h-24 bg-gray-100/50 dark:bg-gray-600/20 rounded-full translate-y-12 -translate-x-12 group-hover:scale-125 transition-transform duration-700"
                            ></div>

                            <div class="relative z-10">
                                <!-- Иконка -->
                                <div
                                    class="w-16 h-16 bg-gradient-to-br from-gray-600 to-gray-700 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 group-hover:rotate-3 transition-all duration-500 shadow-lg"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="1.5"
                                        stroke="currentColor"
                                        class="h-8 w-8 text-white"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0V12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12V5.25"
                                        />
                                    </svg>
                                </div>

                                <!-- Заголовок -->
                                <h4
                                    class="text-2xl font-bold text-gray-700 dark:text-gray-300 mb-4 tracking-wide group-hover:text-gray-800 dark:group-hover:text-gray-200 transition-colors duration-300"
                                >
                                    УНИВЕРСАЛЬНО
                                </h4>

                                <!-- Описание -->
                                <p
                                    class="text-gray-700 dark:text-gray-300 leading-relaxed group-hover:text-gray-900 dark:group-hover:text-gray-100 transition-colors duration-300 mb-4"
                                >
                                    Возможность участия в различных форматах:
                                </p>

                                <!-- Теги форматов -->
                                <div class="flex flex-wrap gap-2">
                                    <span
                                        class="inline-block px-3 py-1.5 bg-brandblue text-white rounded-full text-sm font-bold group-hover:scale-105 transition-transform duration-300"
                                        >ОНЛАЙН</span
                                    >
                                    <span
                                        class="inline-block px-3 py-1.5 bg-brandcoral text-white rounded-full text-sm font-bold group-hover:scale-105 transition-transform duration-300"
                                        >ОФЛАЙН</span
                                    >
                                    <span
                                        class="inline-block px-3 py-1.5 bg-gray-600 text-white rounded-full text-sm font-bold group-hover:scale-105 transition-transform duration-300"
                                        >ГИБРИД</span
                                    >
                                </div>
                            </div>
                        </div>

                        <!-- Доступно -->
                        <div
                            class="group relative overflow-hidden bg-gradient-to-br from-orange-50 via-white to-orange-100 dark:from-orange-900/20 dark:via-gray-800 dark:to-orange-800/20 rounded-3xl p-8 border border-orange-200 dark:border-orange-700 hover:border-orange-400 hover:shadow-2xl hover:-translate-y-2 transition-all duration-500"
                        >
                            <!-- Декоративные элементы -->
                            <div
                                class="absolute top-0 right-0 w-32 h-32 bg-orange-200/30 dark:bg-orange-600/20 rounded-full -translate-y-16 translate-x-16 group-hover:scale-150 transition-transform duration-700"
                            ></div>
                            <div
                                class="absolute bottom-0 left-0 w-24 h-24 bg-orange-100/50 dark:bg-orange-700/20 rounded-full translate-y-12 -translate-x-12 group-hover:scale-125 transition-transform duration-700"
                            ></div>

                            <div class="relative z-10">
                                <!-- Иконка -->
                                <div
                                    class="w-16 h-16 bg-gradient-to-br from-orange-500 to-orange-600 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 group-hover:rotate-3 transition-all duration-500 shadow-lg"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="1.5"
                                        stroke="currentColor"
                                        class="h-8 w-8 text-white"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M13.5 10.5V6.75a4.5 4.5 0 1 1 9 0v3.75M3.75 21.75h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H3.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"
                                        />
                                    </svg>
                                </div>

                                <!-- Заголовок -->
                                <h4
                                    class="text-2xl font-bold text-orange-600 dark:text-orange-400 mb-4 tracking-wide group-hover:text-orange-700 dark:group-hover:text-orange-300 transition-colors duration-300"
                                >
                                    ДОСТУПНО
                                </h4>

                                <!-- Описание -->
                                <p
                                    class="text-gray-700 dark:text-gray-300 leading-relaxed group-hover:text-gray-900 dark:group-hover:text-gray-100 transition-colors duration-300"
                                >
                                    Платформа
                                    <span class="font-semibold text-brandblue"
                                        >МедАльянсГрупп Expert</span
                                    >
                                    — это удобный и доступный инструмент,
                                    позволяющий «прокачать» свои навыки в
                                    удобном для участника формате
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>
</template>
