<template>
    <SlideOverModal
        :show="show"
        @close="closeModal"
        @backdropClick="closeModal"
    >
        <template #title>{{
            isEdit
                ? "Редактирование мероприятия"
                : "Создание нового мероприятия"
        }}</template>

        <form @submit.prevent="submitForm" class="space-y-5">
            <TextInput
                id="title"
                label="Название"
                v-model="form.title"
                :error="form.errors.title"
                required
                placeholder="Введите название мероприятия"
            />

            <TextInput
                id="slug"
                label="URL-адрес"
                v-model="form.slug"
                :error="form.errors.slug"
                placeholder="Оставьте пустым для автоматической генерации"
            >
                <template #hint>
                    Используется в URL. Если оставить пустым, будет сгенерирован
                    автоматически.
                </template>
            </TextInput>

            <div class="space-y-4">
                <CheckboxInput
                    id="is_on_demand"
                    label="Мероприятие по запросу"
                    v-model="form.is_on_demand"
                >
                    <template #hint>
                        Укажите, если мероприятие проводится по запросу без
                        фиксированного времени
                    </template>
                </CheckboxInput>

                <div
                    v-if="!form.is_on_demand"
                    class="grid grid-cols-1 md:grid-cols-2 gap-4"
                >
                    <TextInput
                        id="start_date"
                        label="Дата начала"
                        v-model="form.start_date"
                        type="date"
                        :error="form.errors.start_date"
                        required
                    />
                    <TextInput
                        id="start_time"
                        label="Время начала"
                        v-model="form.start_time"
                        type="time"
                        :error="form.errors.start_time"
                    />
                    <TextInput
                        id="end_date"
                        label="Дата окончания"
                        v-model="form.end_date"
                        type="date"
                        :error="form.errors.end_date"
                    />
                    <TextInput
                        id="end_time"
                        label="Время окончания"
                        v-model="form.end_time"
                        type="time"
                        :error="form.errors.end_time"
                    />
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <SelectInput
                    id="event_type"
                    label="Тип мероприятия"
                    v-model="form.event_type"
                    :options="eventTypeOptions"
                    :error="form.errors.event_type"
                    placeholder="Выберите тип"
                    required
                />

                <MultiSelectInput
                    id="categories"
                    label="Категории мероприятия"
                    v-model="form.selected_categories"
                    :options="categoryOptions"
                    :error="
                        form.errors.selected_categories ||
                        form.errors.categories
                    "
                    placeholder="Выберите категории"
                    :searchable="true"
                    :show-selected="true"
                >
                    <template #hint>
                        Выберите одну или несколько категорий для мероприятия
                    </template>
                </MultiSelectInput>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <SelectInput
                    id="format"
                    label="Формат"
                    v-model="form.format"
                    :options="formatOptions"
                    :error="form.errors.format"
                    placeholder="Выберите формат"
                />
                <TextInput
                    id="max_quantity"
                    label="Максимальное число мест"
                    type="number"
                    min="0"
                    step="1"
                    v-model.number="form.max_quantity"
                    :error="form.errors.max_quantity"
                    placeholder="Оставьте пустым для безлимита"
                />

                <TextInput
                    id="location"
                    label="Место проведения"
                    v-model="form.location"
                    :error="form.errors.location"
                    placeholder="Введите место проведения"
                />
                <TextInput
                    id="external_url"
                    label="Ссылка на внешний сайт"
                    v-model="form.external_url"
                    :error="form.errors.external_url"
                    placeholder="https://example.com"
                    type="url"
                >
                    <template #hint>
                        Если указана ссылка, кнопка "Записаться" будет
                        перенаправлять на этот сайт вместо регистрации
                    </template>
                </TextInput>
            </div>

            <div class="space-y-3 p-4 bg-gray-50 rounded-lg dark:bg-gray-800">
                <h4 class="text-sm font-medium text-gray-900 dark:text-white">
                    Настройки оплаты
                </h4>

                <CheckboxInput
                    id="is_paid"
                    label="Платное мероприятие"
                    v-model="form.is_paid"
                    :error="form.errors.is_paid"
                >
                    <template #hint>
                        Отметьте, если мероприятие платное (независимо от
                        указания конкретной цены)
                    </template>
                </CheckboxInput>

                <CheckboxInput
                    id="show_price"
                    label="Показывать цену на сайте"
                    v-model="form.show_price"
                    :error="form.errors.show_price"
                    :disabled="!form.is_paid"
                >
                    <template #hint>
                        {{
                            form.is_paid
                                ? 'Отметьте, если нужно показывать цену посетителям сайта (если цена не указана, покажется "Платно")'
                                : "Доступно только для платных мероприятий"
                        }}
                    </template>
                </CheckboxInput>

                <!-- Поле стоимости - показывается для всех платных мероприятий -->
                <div v-if="form.is_paid" class="transition-all duration-200">
                    <TextInput
                        id="price"
                        label="Стоимость (необязательно)"
                        type="number"
                        min="0"
                        step="0.01"
                        v-model="form.price"
                        :error="form.errors.price"
                        placeholder="Введите стоимость мероприятия"
                    >
                        <template #hint>
                            Укажите стоимость участия. Можно оставить пустым
                            если цена уточняется позже
                        </template>
                    </TextInput>
                </div>

                <!-- Информационное сообщение -->
                <div
                    v-if="form.is_paid && !form.show_price"
                    class="text-sm text-gray-600 dark:text-gray-400 italic"
                >
                    💡 Цена будет скрыта от посетителей. Отобразится просто
                    "Платно"
                </div>
                <div
                    v-if="!form.is_paid"
                    class="text-sm text-gray-600 dark:text-gray-400 italic"
                >
                    💡 Мероприятие отмечено как бесплатное.
                </div>
            </div>

            <TextInput
                id="topic"
                label="Тема"
                v-model="form.topic"
                :error="form.errors.topic"
                placeholder="Введите тему мероприятия"
            />

            <!-- Секция Кинескопа -->
            <div class="space-y-4 p-4 bg-gray-50 rounded-lg dark:bg-gray-800">
                <h4 class="text-sm font-medium text-gray-900 dark:text-white">
                    Контент Кинескопа
                </h4>

                <SelectInput
                    id="kinescope_type"
                    label="Тип контента"
                    v-model="form.kinescope_type"
                    :options="kinescopeTypeOptions"
                    :error="form.errors.kinescope_type"
                    placeholder="Выберите тип контента"
                >
                    <template #hint>
                        Выберите тип контента: отдельное видео или плейлист
                    </template>
                </SelectInput>

                <div
                    v-if="form.kinescope_type === 'video'"
                    class="transition-all duration-200"
                >
                    <TextInput
                        id="kinescope_id"
                        label="ID видео Кинескопа"
                        v-model="form.kinescope_id"
                        :error="form.errors.kinescope_id"
                        placeholder="Введите ID видео Кинескопа"
                    >
                        <template #hint>
                            Идентификатор видео с платформы Кинескоп для
                            встраивания записи мероприятия
                        </template>
                    </TextInput>
                </div>

                <div
                    v-if="form.kinescope_type === 'playlist'"
                    class="transition-all duration-200"
                >
                    <TextInput
                        id="kinescope_playlist_id"
                        label="ID плейлиста Кинескопа"
                        v-model="form.kinescope_playlist_id"
                        :error="form.errors.kinescope_playlist_id"
                        placeholder="Введите ID плейлиста Кинескопа"
                    >
                        <template #hint>
                            Идентификатор плейлиста с платформы Кинескоп для
                            встраивания нескольких видео
                        </template>
                    </TextInput>
                </div>

                <!-- Информационное сообщение -->
                <div
                    v-if="!form.kinescope_type"
                    class="text-sm text-gray-600 dark:text-gray-400 italic"
                >
                    💡 Выберите тип контента чтобы указать ID видео или
                    плейлиста
                </div>
                <div
                    v-if="form.kinescope_type === 'video' && form.kinescope_id"
                    class="text-sm text-gray-600 dark:text-gray-400 italic"
                >
                    📹 Будет встроено одно видео с ID: {{ form.kinescope_id }}
                </div>
                <div
                    v-if="
                        form.kinescope_type === 'playlist' &&
                        form.kinescope_playlist_id
                    "
                    class="text-sm text-gray-600 dark:text-gray-400 italic"
                >
                    🎬 Будет встроен плейлист с ID:
                    {{ form.kinescope_playlist_id }}
                </div>
            </div>

            <!-- Секция письма Sendsay -->
            <div class="space-y-4 p-4 bg-gray-50 rounded-lg dark:bg-gray-800">
                <h4 class="text-sm font-medium text-gray-900 dark:text-white">
                    Уведомления Sendsay
                </h4>

                <TextInput
                    id="groupsensay"
                    label="Группа Sendsay"
                    v-model="form.groupsensay"
                    :error="form.errors.groupsensay"
                    placeholder="Введите имя группы Sendsay"
                >
                    <template #hint> Group Sendsay </template>
                </TextInput>

                <TextInput
                    id="letter_draft_id"
                    label="ID письма"
                    v-model="form.letter_draft_id"
                    :error="form.errors.letter_draft_id"
                    placeholder="Введите ID письма Sendsay"
                >
                    <template #hint>
                        Идентификатор письма в Sendsay, которое будет отправлено
                        участникам мероприятия
                    </template>
                </TextInput>

                <!-- Информационное сообщение -->
                <div
                    v-if="form.letter_draft_id"
                    class="text-sm text-gray-600 dark:text-gray-400 italic"
                >
                    📧 Будет использован письмо с ID: {{ form.letter_draft_id }}
                </div>
                <div
                    v-if="!form.letter_draft_id"
                    class="text-sm text-gray-600 dark:text-gray-400 italic"
                >
                    💡 Укажите ID письма для автоматической отправки участникам
                </div>
            </div>

            <TextareaInput
                id="short_description"
                label="Краткое описание"
                v-model="form.short_description"
                :error="form.errors.short_description"
                rows="2"
                placeholder="Введите краткое описание мероприятия"
            />

            <RichTextEditor
                id="full_description"
                label="Полное описание"
                v-model="form.full_description"
                :error="form.errors.full_description"
                placeholder="Введите полное описание мероприятия"
                :upload-endpoint="route('admin.events.upload-image')"
                :event-id="props.event?.id ?? null"
            />

            <SpeakerSelector
                id="speakers"
                label="Спикеры мероприятия"
                v-model="form.speakers"
                :speakers="speakers"
                :error="form.errors.speakers"
            />

            <ImageUpload
                label="Изображение"
                v-model="form.image"
                v-model:delete-photo="form.delete_image"
                :error="form.errors.image"
            />

            <TextInput
                id="sort_order"
                label="Порядок сортировки"
                type="number"
                min="0"
                v-model="form.sort_order"
                :error="form.errors.sort_order"
                placeholder="0"
            />
            <!-- Поле загрузки файла -->
            <div class="mt-4">
                <label
                    for="event_file"
                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                >
                    Прикрепить файл *
                </label>
                <input
                    type="file"
                    id="event_file"
                    @change="(e) => (form.file = e.target.files[0])"
                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-brandblue focus:outline-none focus:ring-1 focus:ring-brandblue sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Поддерживаются файлы: PDF, DOCX, JPG, PNG (макс. 10 МБ)
                </p>
                <span v-if="form.errors.file" class="text-red-500 text-xs mt-1">
                    {{ form.errors.file }}
                </span>
            </div>
            <!-- Блок отображения текущего файла -->
            <div v-if="form.file && !isEdit">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Текущий файл:
                    {{ form.file instanceof File ? form.file.name : form.file }}
                </p>
                <a :href="`/${props.event.file_path}`" target="_blank">
                    Просмотреть файл
                </a>
            </div>

            <!-- Если редактируем существующее мероприятие -->
            <div v-if="isEdit && props.event?.file_path">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Текущий файл:
                    {{ form.file instanceof File ? form.file.name : form.file }}
                </p>
                <a
                    :href="`/${props.event.file_path}`"
                    target="_blank"
                    class="text-blue-500 hover:underline text-sm"
                >
                    Скачать файл
                </a>
                <button
                    @click="confirmDeleteFile"
                    type="button"
                    class="text-red-500 hover:text-red-700 text-sm font-medium"
                >
                    Удалить
                </button>
            </div>
            <div class="space-y-3">
                <CheckboxInput
                    id="registration_enabled"
                    label="Разрешить регистрацию на мероприятие"
                    v-model="form.registration_enabled"
                />
                <CheckboxInput
                    id="is_live"
                    label="Прямая трансляция"
                    v-model="form.is_live"
                >
                    <template #hint>
                        Отметьте, если событие транслируется в прямом эфире
                    </template>
                </CheckboxInput>
                <CheckboxInput
                    id="is_active"
                    label="Активное мероприятие"
                    v-model="form.is_active"
                />
                <CheckboxInput
                    id="is_archived"
                    label="В архиве"
                    v-model="form.is_archived"
                />
            </div>
        </form>

        <template #footer>
            <div class="flex justify-end gap-3">
                <SecondaryButton @click="closeModal"> Отмена </SecondaryButton>
                <PrimaryButton
                    @click="submitForm"
                    :processing="form.processing"
                >
                    {{ isEdit ? "Сохранить" : "Создать мероприятие" }}
                </PrimaryButton>
            </div>
        </template>
    </SlideOverModal>
</template>

<script setup>
import { computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "vue-toastification";
import SlideOverModal from "@/Components/Modal/SlideOverModal.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import TextInput from "@/Components/Form/TextInput.vue";
import TextareaInput from "@/Components/Form/TextareaInput.vue";
import SelectInput from "@/Components/Form/SelectInput.vue";
import MultiSelectInput from "@/Components/Form/MultiSelectInput.vue";
import CheckboxInput from "@/Components/Form/CheckboxInput.vue";
import ImageUpload from "@/Components/Form/ImageUpload.vue";
import SpeakerSelector from "@/Components/Form/SpeakerSelector.vue";
import RichTextEditor from "@/Components/Form/RichTextEditor.vue";

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    event: {
        type: Object,
        default: null,
    },
    categories: {
        type: Array,
        default: () => [],
    },
    speakers: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(["close", "created", "updated"]);
const toast = useToast();

const isEdit = computed(() => !!props.event);

const form = useForm({
    title: "",
    slug: "",
    start_date: "",
    start_time: "",
    end_date: "",
    end_time: "",
    is_on_demand: false,
    event_type: "",
    short_description: "",
    full_description: "",
    topic: "",
    location: "",
    external_url: "",
    price: "",
    is_paid: false,
    show_price: false,
    format: "",
    image: null,
    registration_enabled: true,
    selected_categories: [], // Множественный выбор категорий
    is_active: true,
    sort_order: 0,
    is_archived: false,
    delete_image: false,
    speakers: [],
    kinescope_id: "",
    kinescope_playlist_id: "",
    kinescope_type: "",
    is_live: false,
    letter_draft_id: "",
    groupsensay: "",
    max_quantity: null,
    file: null,
    delete_file: false, // Флаг для удаления файла
    _method: "POST",
});

const eventTypeOptions = [
    { value: "webinar", text: "Вебинар" },
    { value: "conference", text: "Конференция" },
    { value: "workshop", text: "Мастер-класс" },
    { value: "other", text: "Другое" },
];

const formatOptions = [
    { value: "online", text: "Онлайн" },
    { value: "offline", text: "Офлайн" },
    { value: "hybrid", text: "Гибридный" },
];

const kinescopeTypeOptions = [
    { value: "video", text: "Отдельное видео" },
    { value: "playlist", text: "Плейлист" },
];

const categoryOptions = computed(() =>
    props.categories.map((category) => ({
        value: category.id,
        text: category.name,
    }))
);
const confirmDeleteFile = () => {
    if (window.confirm("Вы уверены, что хотите удалить файл?")) {
        deleteFile();
    }
};

const deleteFile = () => {
    if (window.confirm("Вы уверены, что хотите удалить файл?")) {
        // сразу убираем файл из интерфейса
        form.file = null;
        form.delete_file = true;

        if (props.event) {
            props.event.file_path = null; // сразу убираем отображение файла
        }

        submitForm({ keepOpen: true });
    }
};

watch(
    () => props.event,
    (newEvent) => {
        if (newEvent) {
            form.title = newEvent.title || "";
            form.slug = newEvent.slug || "";
            // Даты уже приходят в формате Y-m-d из модели, используем как есть
            form.start_date = newEvent.start_date || "";
            form.start_time = newEvent.start_time || "";
            form.end_date = newEvent.end_date || "";
            form.end_time = newEvent.end_time || "";
            form.is_on_demand = newEvent.is_on_demand ?? false;
            form.event_type = newEvent.event_type || "";
            form.short_description = newEvent.short_description || "";
            form.full_description = newEvent.full_description || "";
            form.topic = newEvent.topic || "";
            form.location = newEvent.location || "";
            form.external_url = newEvent.external_url || ""; // Внешняя ссылка
            form.price = newEvent.price || "";
            form.format = newEvent.format || "";
            form.registration_enabled = newEvent.registration_enabled ?? true;
            form.is_active = newEvent.is_active ?? true;
            form.sort_order = newEvent.sort_order || 0;
            form.is_archived = newEvent.is_archived ?? false;
            form.image = newEvent.image || null;
            form.kinescope_id = newEvent.kinescope_id || "";
            form.kinescope_playlist_id = newEvent.kinescope_playlist_id || "";
            form.kinescope_type = newEvent.kinescope_type || "";
            form.is_paid = newEvent.is_paid ?? false;
            form.show_price = newEvent.show_price ?? false;
            form.is_live = newEvent.is_live ?? false;
            form.letter_draft_id = newEvent.letter_draft_id || "";
            form.groupsensay = newEvent.groupsensay || "";
            form.max_quantity = newEvent.max_quantity ?? null;
            form.file = newEvent.file_path || null; // Путь к файлу программы

            // Загружаем выбранные категории
            form.selected_categories = newEvent.categories
                ? newEvent.categories.map((cat) => cat.id)
                : [];

            // Загружаем спикеров, если они есть
            form.speakers = newEvent.speakers
                ? newEvent.speakers.map((speaker) => ({
                      id: speaker.id,
                      role: speaker.pivot.role || "",
                      topic: speaker.pivot.topic || "",
                      sort_order: speaker.pivot.sort_order || 0,
                  }))
                : [];

            form._method = "PUT";
        } else {
            resetForm();
            form._method = "POST";
        }
    },
    { immediate: true }
);

// Автоматически сбрасываем show_price если мероприятие становится бесплатным
watch(
    () => form.is_paid,
    (newValue) => {
        if (!newValue) {
            form.show_price = false;
            form.price = "";
        }
    }
);

// Автоматически включаем показ цены если она указана
watch(
    () => form.price,
    (newValue) => {
        if (newValue && form.is_paid && !form.show_price) {
            form.show_price = true;
        }
    }
);

// Очищаем поля Кинескопа при смене типа
watch(
    () => form.kinescope_type,
    (newValue, oldValue) => {
        if (oldValue && newValue !== oldValue) {
            form.kinescope_id = "";
            form.kinescope_playlist_id = "";
        }
    }
);

const closeModal = () => {
    resetForm();
    emit("close");
};

function resetForm() {
    form.reset();
    form.clearErrors();
}

const submitForm = (options = { keepOpen: false, onSuccess: null }) => {
    const url = isEdit.value
        ? route("admin.events.update", props.event.id)
        : route("admin.events.store");

    form.transform((data) => ({
        ...data,
        delete_file: data.delete_file ? 1 : 0,
        start_time: data.start_time || null,
        end_date: data.end_date || null,
        end_time: data.end_time || null,
        price: data.price === "" ? null : data.price,
        is_paid: data.is_paid,
        show_price: data.is_paid ? data.show_price : false,
        kinescope_id:
            data.kinescope_type === "video" ? data.kinescope_id : null,
        kinescope_playlist_id:
            data.kinescope_type === "playlist"
                ? data.kinescope_playlist_id
                : null,
        kinescope_type: data.kinescope_type || null,
        categories: data.selected_categories || [],
        is_live: data.is_live,
        letter_draft_id: data.letter_draft_id || null,
        groupsensay: data.groupsensay || null,
        max_quantity:
            data.max_quantity === "" || data.max_quantity == null
                ? null
                : Number(data.max_quantity),
    })).post(url, {
        forceFormData:
            form.image instanceof File ||
            form.file instanceof File ||
            form.delete_file,
        onSuccess: () => {
            const message = isEdit.value
                ? "Мероприятие успешно обновлено"
                : "Мероприятие успешно создано";
            toast.success(message);

            // сбрасываем флаг удаления
            form.delete_file = false;

            if (!options.keepOpen) {
                closeModal();
                emit(isEdit.value ? "updated" : "created");
            } else {
                emit("updated"); // событие, если хотим сразу обновить родителя
            }
        },
        onError: () => {
            toast.error("Пожалуйста, исправьте ошибки в форме");
        },
    });
};
const submitFormAndClose = () => {
    submitForm({
        keepOpen: true, // не закрывать сразу внутри submitForm
        onSuccess: () => {
            closeModal(); // закрываем только при успехе
        },
    });
};
</script>
