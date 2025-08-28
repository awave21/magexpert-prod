<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import MainLayout from "@/Layouts/MainLayout.vue";
import EventFilters from "@/Components/Events/EventFilters.vue";
import EventCard from "@/Components/Events/EventCard.vue";
import {
    MagnifyingGlassIcon,
    AdjustmentsHorizontalIcon,
    UserGroupIcon,
    CalendarDaysIcon,
    MapPinIcon,
    UserIcon,
} from "@heroicons/vue/24/outline";
import {
    ArrowLongRightIcon,
    HomeIcon,
    ChevronRightIcon,
} from "@heroicons/vue/20/solid";

const props = defineProps({
    events: Object,
    categories: Array,
    speakersCount: Number,
    randomSpeakers: Array,
    filters: Object,
});

// Локальное состояние для хранения мероприятий
const localEvents = ref([...props.events.data]);
const hasMorePages = ref(props.events.next_page_url !== null);
const loadingMore = ref(false);
const loadMoreRef = ref(null);

// Наблюдение за изменением фильтров для обновления локального состояния
watch(
    () => props.filters,
    (newFilters) => {
        // Обновляем локальное состояние в соответствии с фильтрами
        searchQuery.value = newFilters.search || "";
        activeTab.value = newFilters.filter || "all";

        // Обновляем состояние сортировки
        if (newFilters.sort && newFilters.direction) {
            sortOption.value = `${newFilters.sort}_${newFilters.direction}`;
        }
    },
    { deep: true, immediate: true }
);

// Функция для загрузки следующей страницы
const loadMoreEvents = () => {
    if (!hasMorePages.value || loadingMore.value) return;

    loadingMore.value = true;

    // Используем значение из реактивной переменной sortOption
    const [sort, direction] = sortOption.value.split("_");

    // Формируем параметры запроса на основе текущих фильтров
    const params = {
        search: searchQuery.value,
        filter: activeTab.value,
        sort: sort,
        direction: direction,
        page: props.events.current_page + 1,
    };

    // Добавляем дополнительные параметры фильтрации, если они есть
    if (props.filters.category) {
        params.category = props.filters.category;
    }

    if (props.filters.type) {
        params.type = props.filters.type;
    }

    if (props.filters.format) {
        params.format = props.filters.format;
    }

    // Используем базовый URL без параметров пагинации
    router.get(route("events.index"), params, {
        preserveState: true,
        preserveScroll: true,
        only: ["events"],
        onSuccess: (page) => {
            // Добавляем новые данные к существующим
            localEvents.value = [
                ...localEvents.value,
                ...page.props.events.data,
            ];
            hasMorePages.value = page.props.events.next_page_url !== null;
            loadingMore.value = false;
        },
        onError: () => {
            loadingMore.value = false;
        },
    });
};

// Intersection Observer для автоматической подгрузки
onMounted(() => {
    const observer = new IntersectionObserver(
        (entries) => {
            if (entries[0].isIntersecting) {
                loadMoreEvents();
            }
        },
        { threshold: 0.1 }
    );

    if (loadMoreRef.value) {
        observer.observe(loadMoreRef.value);
    }

    onUnmounted(() => {
        if (loadMoreRef.value) {
            observer.unobserve(loadMoreRef.value);
        }
    });
});

// Состояние фильтров
const searchQuery = ref(props.filters.search || "");
const activeTab = ref(props.filters.filter || "all"); // all, upcoming, archive
const showFilters = ref(false);
const sortOption = ref(
    props.filters.sort && props.filters.direction
        ? `${props.filters.sort}_${props.filters.direction}`
        : "start_date_asc"
);

watch(
    () => props.filters,
    (newFilters) => {
        if (newFilters.sort && newFilters.direction) {
            sortOption.value = `${newFilters.sort}_${newFilters.direction}`;
        } else {
            sortOption.value = "start_date_asc";
        }
    },
    { deep: true, immediate: true }
);

// Вычисляемые свойства
const eventCount = computed(() => props.events.total || 0);

// Функция для склонения слова "эксперт" в зависимости от числа
const declensionExperts = (count) => {
    // Массив окончаний для разных форм
    const declension = ["эксперт", "эксперта", "экспертов"];

    // Особые случаи для чисел от 11 до 19
    if (count % 100 >= 11 && count % 100 <= 19) {
        return declension[2];
    }

    // Для остальных чисел смотрим на последнюю цифру
    const lastDigit = count % 10;
    if (lastDigit === 1) {
        return declension[0];
    } else if (lastDigit >= 2 && lastDigit <= 4) {
        return declension[1];
    } else {
        return declension[2];
    }
};

// Вычисляемое свойство для определения количества оставшихся экспертов
const remainingSpeakers = computed(() => {
    // Считаем, сколько экспертов не показано на странице
    const remaining =
        props.speakersCount -
        (props.randomSpeakers.length >= 3 ? 3 : props.randomSpeakers.length);
    return remaining > 0 ? remaining : 0;
});

// Строка с количеством оставшихся экспертов с правильным склонением
const remainingSpeakersText = computed(() => {
    if (remainingSpeakers.value === 0) return "";
    return `+${remainingSpeakers.value} ${declensionExperts(
        remainingSpeakers.value
    )}`;
});

// Варианты для фильтров
const eventTypeOptions = {
    webinar: "Вебинар",
    conference: "Конференция",
    workshop: "Мастер-класс",
    other: "Другое",
};

const formatOptions = {
    online: "Онлайн",
    offline: "Офлайн",
    hybrid: "Гибридный",
};

// Функция для применения фильтров
const applyFilters = (newFilters) => {
    router.get(
        route("events.index"),
        {
            search: searchQuery.value,
            filter: activeTab.value,
            category: newFilters.category,
            type: newFilters.type,
            format: newFilters.format,
        },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                showFilters.value = false;
            },
        }
    );
};

// Переключение вкладок
const switchTab = (tab) => {
    activeTab.value = tab;

    // Применяем фильтр через query-параметры
    window.location.href = route("events.index", { filter: tab });
};

// Функция для изменения сортировки
const changeSorting = (event) => {
    const value = event.target.value;
    sortOption.value = value;

    const [sort, direction] = value.split("_");

    router.get(
        route("events.index"),
        {
            search: searchQuery.value,
            filter: activeTab.value,
            sort,
            direction,
            category: props.filters.category,
            type: props.filters.type,
            format: props.filters.format,
        },
        {
            preserveScroll: true,
            replace: true, // ✅ чтобы обновлялся URL
            only: ["events", "filters"], // ✅ вернуть filters с сервера
        }
    );
};

// Поиск по Enter
const performSearch = () => {
    const [sort, direction] = (sortOption.value || "start_date_asc").split("_");
    router.get(
        route("events.index"),
        {
            search: searchQuery.value,
            filter: activeTab.value,
            sort,
            direction,
            category: props.filters.category,
            type: props.filters.type,
            format: props.filters.format,
        },
        {
            preserveScroll: false,
            replace: true,
            only: ["events", "filters"], // ⬅️ вернуть filters
        }
    );
};

// Автопоиск без Enter (дебаунс, от 3 символов; очистка — сброс результата)
let searchDebounce = null;
watch(searchQuery, (newValue, oldValue) => {
    const serverSearch = props.filters.search || "";
    // Если текущее значение совпадает с тем, что пришло с сервера, ничего не делаем
    if (newValue === serverSearch) return;

    // Условия автопоиска: >= 3 символов или очистка после >= 3
    const shouldSearch =
        newValue.length >= 3 ||
        (oldValue && oldValue.length >= 3 && newValue.length === 0);
    if (!shouldSearch) return;

    if (searchDebounce) clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        performSearch();
    }, 500);
});
</script>

<template>
    <Head title="Мероприятия" />

    <MainLayout>
        <!-- Градиентный фон для всей страницы -->
        <div
            class="min-h-screen bg-gradient-to-br from-brandblue/[0.03] to-white/95 dark:from-brandblue/10 dark:to-gray-900"
        >
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
                                    <nav
                                        class="flex text-sm text-white/80"
                                        aria-label="Хлебные крошки"
                                    >
                                        <ol class="flex items-center space-x-1">
                                            <li>
                                                <div class="flex items-center">
                                                    <Link
                                                        href="/"
                                                        class="hover:text-white"
                                                    >
                                                        <HomeIcon
                                                            class="h-4 w-4 flex-shrink-0"
                                                            aria-hidden="true"
                                                        />
                                                        <span class="sr-only"
                                                            >Главная</span
                                                        >
                                                    </Link>
                                                </div>
                                            </li>
                                            <li>
                                                <div class="flex items-center">
                                                    <ChevronRightIcon
                                                        class="h-5 w-5 flex-shrink-0 text-white/60"
                                                        aria-hidden="true"
                                                    />
                                                    <span
                                                        class="ml-1 font-medium text-white"
                                                        aria-current="page"
                                                        >Мероприятия</span
                                                    >
                                                </div>
                                            </li>
                                        </ol>
                                    </nav>

                                    <h1
                                        class="mt-4 text-3xl font-bold tracking-tight text-white sm:text-4xl"
                                    >
                                        Мероприятия
                                    </h1>
                                    <p class="mt-4 text-lg text-white/90">
                                        Вебинары, курсы и конференции для
                                        медицинских специалистов.
                                        Регистрируйтесь на ближайшие события или
                                        смотрите прошедшие в записи.
                                    </p>
                                </div>

                                <!-- Правая колонка с аватарками спикеров -->
                                <div
                                    class="flex items-center justify-center md:justify-end"
                                >
                                    <div class="flex items-center">
                                        <div class="flex -space-x-2 mr-3">
                                            <template
                                                v-for="(
                                                    speaker, index
                                                ) in randomSpeakers"
                                                :key="speaker.id"
                                            >
                                                <div
                                                    v-if="index < 3"
                                                    class="inline-block h-16 w-16 rounded-full border-2 border-white bg-gray-100 overflow-hidden"
                                                >
                                                    <img
                                                        v-if="speaker.photo"
                                                        :src="speaker.photo"
                                                        :alt="`${speaker.first_name} ${speaker.last_name}`"
                                                        class="h-full w-full object-cover"
                                                    />
                                                    <div
                                                        v-else
                                                        class="flex h-full w-full items-center justify-center bg-brandblue/20 text-white text-lg font-medium"
                                                    >
                                                        {{
                                                            speaker
                                                                .first_name[0]
                                                        }}{{
                                                            speaker.last_name[0]
                                                        }}
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                        <span
                                            v-if="remainingSpeakersText"
                                            class="font-semibold text-white text-lg whitespace-nowrap"
                                        >
                                            {{ remainingSpeakersText }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Основное содержимое -->
            <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-6 lg:px-8">
                <!-- Фильтры и поиск - адаптивная версия -->
                <div class="mb-8">
                    <!-- Мобильная версия: поиск и фильтры в колонку -->
                    <div class="block md:hidden">
                        <!-- Поисковая строка -->
                        <div class="relative mb-4">
                            <div
                                class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3"
                            >
                                <MagnifyingGlassIcon
                                    class="h-5 w-5 text-gray-400"
                                    aria-hidden="true"
                                />
                            </div>
                            <input
                                v-model="searchQuery"
                                @keyup.enter="performSearch"
                                type="text"
                                class="block w-full rounded-full border-0 py-3 pl-10 pr-4 text-gray-900 bg-white ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-brandblue dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-brandblue h-12"
                                placeholder="Поиск мероприятий..."
                            />
                        </div>

                        <!-- Вкладки на всю ширину -->
                        <div class="mb-3">
                            <div
                                class="bg-white inline-flex w-full rounded-full p-1 ring-1 ring-inset ring-gray-300 dark:bg-gray-800 dark:ring-gray-700 h-12"
                            >
                                <button
                                    @click="switchTab('all')"
                                    :class="[
                                        activeTab === 'all'
                                            ? 'bg-brandcoral text-white shadow'
                                            : 'text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white',
                                    ]"
                                    class="rounded-full flex-1 px-3 py-2.5 text-xs font-medium transition-colors h-10"
                                >
                                    Все
                                </button>
                                <button
                                    @click="switchTab('upcoming')"
                                    :class="[
                                        activeTab === 'upcoming'
                                            ? 'bg-brandcoral text-white shadow'
                                            : 'text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white',
                                    ]"
                                    class="rounded-full flex-1 px-1 py-2.5 text-xs font-medium transition-colors h-10"
                                >
                                    Предстоящие
                                </button>
                                <button
                                    @click="switchTab('archive')"
                                    :class="[
                                        activeTab === 'archive'
                                            ? 'bg-brandcoral text-white shadow'
                                            : 'text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white',
                                    ]"
                                    class="rounded-full flex-1 px-3 py-2.5 text-xs font-medium transition-colors h-10"
                                >
                                    Прошедшие
                                </button>
                            </div>
                        </div>

                        <!-- Кнопка фильтров на всю ширину -->
                        <button
                            @click="showFilters = !showFilters"
                            class="inline-flex items-center justify-center w-full rounded-full bg-white px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 ring-1 ring-inset ring-gray-300 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 dark:ring-gray-700 h-12"
                        >
                            <AdjustmentsHorizontalIcon
                                class="mr-1.5 h-5 w-5 text-gray-500 dark:text-gray-400"
                            />
                            Фильтры
                        </button>
                    </div>

                    <!-- Десктоп версия: оригинальный дизайн в одну строку -->
                    <div class="hidden md:block">
                        <div
                            class="flex flex-col space-y-4 md:flex-row md:items-center md:space-y-0 md:space-x-4"
                        >
                            <!-- Поисковая строка, занимающая больше места -->
                            <div class="relative flex-grow">
                                <div
                                    class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3"
                                >
                                    <MagnifyingGlassIcon
                                        class="h-5 w-5 text-gray-400"
                                        aria-hidden="true"
                                    />
                                </div>
                                <input
                                    v-model="searchQuery"
                                    @keyup.enter="performSearch"
                                    type="text"
                                    class="block w-full rounded-full border-0 py-3 pl-10 pr-4 text-gray-900 bg-white ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-brandblue dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-brandblue h-12"
                                    placeholder="Поиск мероприятий..."
                                />
                            </div>

                            <!-- Вкладки для фильтрации -->
                            <div
                                class="flex items-center space-x-2 flex-shrink-0"
                            >
                                <div
                                    class="bg-white inline-flex rounded-full p-1 ring-1 ring-inset ring-gray-300 dark:bg-gray-800 dark:ring-gray-700 h-12"
                                >
                                    <button
                                        @click="switchTab('all')"
                                        :class="[
                                            activeTab === 'all'
                                                ? 'bg-brandcoral text-white shadow'
                                                : 'text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white',
                                        ]"
                                        class="rounded-full px-5 py-2.5 text-sm font-medium transition-colors h-10"
                                    >
                                        Все
                                    </button>
                                    <button
                                        @click="switchTab('upcoming')"
                                        :class="[
                                            activeTab === 'upcoming'
                                                ? 'bg-brandcoral text-white shadow'
                                                : 'text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white',
                                        ]"
                                        class="rounded-full px-5 py-2.5 text-sm font-medium transition-colors h-10"
                                    >
                                        Предстоящие
                                    </button>
                                    <button
                                        @click="switchTab('archive')"
                                        :class="[
                                            activeTab === 'archive'
                                                ? 'bg-brandcoral text-white shadow'
                                                : 'text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white',
                                        ]"
                                        class="rounded-full px-5 py-2.5 text-sm font-medium transition-colors h-10"
                                    >
                                        Прошедшие
                                    </button>
                                </div>

                                <!-- Кнопка фильтров в том же стиле -->
                                <button
                                    @click="showFilters = !showFilters"
                                    class="inline-flex items-center rounded-full bg-white px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 ring-1 ring-inset ring-gray-300 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 dark:ring-gray-700 h-12"
                                >
                                    <AdjustmentsHorizontalIcon
                                        class="mr-1.5 h-5 w-5 text-gray-500 dark:text-gray-400"
                                    />
                                    Фильтры
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Количество результатов и сортировка -->
                <div
                    class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Найдено
                        <span
                            class="font-medium text-gray-900 dark:text-white"
                            >{{ eventCount }}</span
                        >
                        мероприятий
                    </p>
                    <div class="mt-2 sm:mt-0">
                        <select
                            v-model="sortOption"
                            @change="changeSorting"
                            class="rounded-md border-gray-300 py-2 pl-3 pr-10 text-sm text-gray-900 focus:border-brandblue focus:outline-none focus:ring-brandblue dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                        >
                            <option value="start_date_asc">
                                По дате (сначала старые)
                            </option>
                            <option value="start_date_desc">
                                По дате (сначала новые)
                            </option>
                            <option value="title_asc">По названию (А-Я)</option>
                            <option value="title_desc">
                                По названию (Я-А)
                            </option>
                        </select>
                    </div>
                </div>

                <!-- Сетка мероприятий с обновленным стилем карточек -->
                <div
                    class="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <EventCard
                        v-for="event in localEvents"
                        :key="event.id"
                        :event="event"
                    />
                </div>

                <!-- Элемент для отслеживания прокрутки -->
                <div ref="loadMoreRef" class="h-10"></div>

                <!-- Индикатор загрузки -->
                <div v-if="loadingMore" class="mt-8 flex justify-center">
                    <svg
                        class="animate-spin h-8 w-8 text-brandblue"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                    >
                        <circle
                            class="opacity-25"
                            cx="12"
                            cy="12"
                            r="10"
                            stroke="currentColor"
                            stroke-width="4"
                        ></circle>
                        <path
                            class="opacity-75"
                            fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                        ></path>
                    </svg>
                </div>

                <!-- Сообщение, когда все загружено -->
                <div
                    v-if="
                        !hasMorePages && !loadingMore && localEvents.length > 0
                    "
                    class="mt-8 text-center text-gray-500 dark:text-gray-400"
                >
                    Вы просмотрели все мероприятия.
                </div>
            </div>

            <!-- Панель фильтров справа -->
            <EventFilters
                :show="showFilters"
                :categories="categories"
                :filters="filters"
                :event-type-options="eventTypeOptions"
                :format-options="formatOptions"
                @close="showFilters = false"
                @apply="applyFilters"
            />
        </div>
    </MainLayout>
</template>
