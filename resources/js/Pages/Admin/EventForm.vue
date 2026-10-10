<template>
    <Head :title="isEdit ? `Мероприятие: ${event.title}` : 'Новое мероприятие'" />

    <AdminLayout>
        <template #header>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0">
                    <Link :href="route('admin.events')" class="text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">← Все мероприятия</Link>
                    <h1 class="mt-1 truncate text-2xl font-semibold text-zinc-900 dark:text-white">{{ isEdit ? event.title : "Новое мероприятие" }}</h1>
                </div>
                <a v-if="publicUrl" :href="publicUrl" target="_blank" rel="noopener" class="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">Открыть на сайте ↗</a>
            </div>
        </template>

        <div class="flex items-start gap-8 pb-24">
            <nav aria-label="Разделы формы" class="sticky top-6 hidden w-48 shrink-0 xl:block">
                <ul class="space-y-1 text-sm">
                    <li v-for="s in sections" :key="s.id">
                        <a :href="`#${s.id}`" class="block rounded-md px-3 py-1.5 text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white">{{ s.label }}</a>
                    </li>
                </ul>
            </nav>

            <form id="event-form" class="min-w-0 flex-1 space-y-5" novalidate @submit.prevent="submitForm()">
            <!-- 1. Основное -->
            <section :id="`sec-1`" :class="box" class="scroll-mt-24">
                <h3 :class="boxTitle">1. Основное</h3>
                <div>
                    <label for="ev-title" :class="label">Название <span class="text-red-500">*</span></label>
                    <input id="ev-title" v-model="form.title" :class="input" placeholder="2 ступень. Первая практика гинеколога-эстетиста" />
                    <p v-if="form.errors.title" :class="error">{{ form.errors.title }}</p>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="ev-type" :class="label">Тип <span class="text-red-500">*</span></label>
                        <select id="ev-type" v-model="form.event_type" :class="input">
                            <option value="" disabled>Выберите тип</option>
                            <option v-for="o in eventTypeOptions" :key="o.value" :value="o.value">{{ o.text }}</option>
                        </select>
                        <p v-if="form.errors.event_type" :class="error">{{ form.errors.event_type }}</p>
                    </div>
                    <div>
                        <label for="ev-format" :class="label">Формат</label>
                        <select id="ev-format" v-model="form.format" :class="input">
                            <option value="">Не указан</option>
                            <option v-for="o in formatOptions" :key="o.value" :value="o.value">{{ o.text }}</option>
                        </select>
                        <p v-if="form.errors.format" :class="error">{{ form.errors.format }}</p>
                    </div>
                </div>
                <MultiSelectInput
                    id="categories"
                    label="Категории"
                    v-model="form.selected_categories"
                    :options="categoryOptions"
                    :error="form.errors.selected_categories || form.errors.categories"
                    placeholder="Выберите одну или несколько"
                    :searchable="true"
                    :show-selected="true"
                />
            </section>

            <!-- 2. Дата и место -->
            <section :id="`sec-2`" :class="box" class="scroll-mt-24">
                <h3 :class="boxTitle">2. Дата и место</h3>
                <label class="flex items-start gap-2 text-sm text-zinc-900 dark:text-white">
                    <input v-model="form.is_on_demand" type="checkbox" :class="checkbox" />
                    <span>Без даты — смотреть в любое время<span :class="hintInline">запись доступна сразу после регистрации</span></span>
                </label>
                <div v-if="!form.is_on_demand" class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="ev-sd" :class="label">Дата начала <span class="text-red-500">*</span></label>
                        <input id="ev-sd" v-model="form.start_date" type="date" :class="input" />
                        <p v-if="form.errors.start_date" :class="error">{{ form.errors.start_date }}</p>
                    </div>
                    <div>
                        <label for="ev-st" :class="label">Время начала, МСК</label>
                        <input id="ev-st" v-model="form.start_time" type="time" :class="input" />
                        <p v-if="form.errors.start_time" :class="error">{{ form.errors.start_time }}</p>
                    </div>
                    <div>
                        <label for="ev-ed" :class="label">Дата окончания</label>
                        <input id="ev-ed" v-model="form.end_date" type="date" :min="form.start_date || undefined" :class="input" />
                        <p v-if="form.errors.end_date" :class="error">{{ form.errors.end_date }}</p>
                    </div>
                    <div>
                        <label for="ev-et" :class="label">Время окончания, МСК</label>
                        <input id="ev-et" v-model="form.end_time" type="time" :class="input" />
                        <p v-if="form.errors.end_time" :class="error">{{ form.errors.end_time }}</p>
                    </div>
                </div>
                <div v-if="form.format !== 'online'">
                    <label for="ev-loc" :class="label">Место проведения</label>
                    <input id="ev-loc" v-model="form.location" :class="input" placeholder="Москва, ул. Рабочая, 93" />
                    <p v-if="form.errors.location" :class="error">{{ form.errors.location }}</p>
                </div>
            </section>

            <!-- 3. Видео -->
            <section :id="`sec-3`" :class="box" class="scroll-mt-24">
                <h3 :class="boxTitle">3. Видео Kinescope</h3>
                <div>
                    <label for="ev-video" :class="label">Ссылка или ID из Kinescope</label>
                    <input id="ev-video" v-model="videoLink" :class="input" placeholder="https://kinescope.io/abc123 или https://kinescope.io/pl/xyz" @input="parseVideoLink" />
                    <p :class="hint">Вставьте ссылку на видео или плейлист — тип определится сам. Можно оставить пустым и добавить запись позже.</p>
                    <p v-if="form.errors.kinescope_id || form.errors.kinescope_playlist_id || form.errors.kinescope_type" :class="error">
                        {{ form.errors.kinescope_id || form.errors.kinescope_playlist_id || form.errors.kinescope_type }}
                    </p>
                </div>
                <div v-if="videoId" class="flex flex-wrap items-center gap-3 text-sm">
                    <span class="rounded bg-zinc-100 px-2 py-1 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">
                        {{ form.kinescope_type === "playlist" ? "Плейлист" : "Видео" }} · {{ videoId }}
                    </span>
                    <label v-if="!detectedFromUrl" class="flex items-center gap-2 text-zinc-700 dark:text-zinc-300">
                        <input v-model="isPlaylist" type="checkbox" :class="checkbox" /> это плейлист
                    </label>
                    <button type="button" class="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400" @click="showPreview = !showPreview">
                        {{ showPreview ? "Скрыть плеер" : "Проверить в плеере" }}
                    </button>
                </div>
                <div v-if="videoId && showPreview" class="aspect-video overflow-hidden rounded-lg bg-black">
                    <iframe :src="embedUrl" class="h-full w-full" frameborder="0" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen title="Проверка видео"></iframe>
                </div>
                <label class="flex items-start gap-2 text-sm text-zinc-900 dark:text-white">
                    <input v-model="form.is_live" type="checkbox" :class="checkbox" />
                    <span>Прямая трансляция<span :class="hintInline">во время проведения мероприятие будет помечено «В эфире» и откроется чат</span></span>
                </label>
            </section>

            <!-- 4. Доступ и оплата -->
            <section :id="`sec-4`" :class="box" class="scroll-mt-24">
                <h3 :class="boxTitle">4. Доступ и оплата</h3>
                <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Оплата">
                    <label :class="[pill, !form.is_paid ? pillOn : '']">
                        <input v-model="form.is_paid" type="radio" :value="false" class="sr-only" /> Бесплатное
                    </label>
                    <label :class="[pill, form.is_paid ? pillOn : '']">
                        <input v-model="form.is_paid" type="radio" :value="true" class="sr-only" /> Платное
                    </label>
                </div>
                <div v-if="form.is_paid" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="ev-price" :class="label">Стоимость, ₽ <span class="text-red-500">*</span></label>
                        <input id="ev-price" v-model="form.price" type="number" min="0" step="1" inputmode="numeric" :class="input" placeholder="5000" />
                        <p v-if="form.errors.price" :class="error">{{ form.errors.price }}</p>
                    </div>
                    <label class="flex items-start gap-2 text-sm text-zinc-900 sm:pt-7 dark:text-white">
                        <input v-model="form.show_price" type="checkbox" :class="checkbox" />
                        <span>Показывать цену на сайте<span :class="hintInline">иначе будет написано «Платно»</span></span>
                    </label>
                </div>
                <p v-else :class="hint">Смотреть смогут все, кто зарегистрировался.</p>

                <label class="flex items-start gap-2 text-sm text-zinc-900 dark:text-white">
                    <input v-model="form.registration_enabled" type="checkbox" :class="checkbox" />
                    <span>Открыта регистрация</span>
                </label>
                <div v-if="form.registration_enabled" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="ev-max" :class="label">Мест</label>
                        <input id="ev-max" v-model.number="form.max_quantity" type="number" min="0" step="1" :class="input" placeholder="Без ограничений" />
                        <p v-if="form.errors.max_quantity" :class="error">{{ form.errors.max_quantity }}</p>
                    </div>
                    <div>
                        <label for="ev-ext" :class="label">Регистрация на другом сайте</label>
                        <input id="ev-ext" v-model="form.external_url" type="url" :class="input" placeholder="https://" />
                        <p :class="hint">Если заполнить, кнопка «Записаться» поведёт туда.</p>
                        <p v-if="form.errors.external_url" :class="error">{{ form.errors.external_url }}</p>
                    </div>
                </div>
            </section>

            <!-- 5. Описание и материалы -->
            <section :id="`sec-5`" :class="box" class="scroll-mt-24">
                <h3 :class="boxTitle">5. Описание и материалы</h3>
                <div>
                    <label for="ev-short" :class="label">Кратко</label>
                    <textarea id="ev-short" v-model="form.short_description" rows="2" :class="input" placeholder="Одно-два предложения для карточки в каталоге"></textarea>
                    <p v-if="form.errors.short_description" :class="error">{{ form.errors.short_description }}</p>
                </div>
                <RichTextEditor
                    id="full_description"
                    label="Подробное описание"
                    v-model="form.full_description"
                    :error="form.errors.full_description"
                    placeholder="Для кого, о чём, программа"
                    :upload-endpoint="route('admin.events.upload-image')"
                    :event-id="props.event?.id ?? null"
                />
                <div>
                    <label for="ev-topic" :class="label">Тема</label>
                    <input id="ev-topic" v-model="form.topic" :class="input" placeholder="Например, инъекционные методики" />
                </div>
                <SpeakerSelector id="speakers" label="Спикеры" v-model="form.speakers" :speakers="speakers" :error="form.errors.speakers" />
                <ImageUpload label="Обложка" v-model="form.image" v-model:delete-photo="form.delete_image" :error="form.errors.image" />

                <div>
                    <span :class="label">Программа мероприятия</span>
                    <div v-if="currentFile" class="mt-1 flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700">
                        <span class="min-w-0 flex-1 truncate text-zinc-800 dark:text-zinc-200">{{ currentFile.name }}</span>
                        <a v-if="currentFile.url" :href="currentFile.url" target="_blank" rel="noopener" class="font-medium text-blue-600 hover:underline dark:text-blue-400">Открыть</a>
                        <button type="button" class="font-medium text-red-600 hover:underline" @click="removeFile">Убрать</button>
                    </div>
                    <input v-else id="event_file" type="file" accept=".pdf,.docx,.jpg,.jpeg,.png" :class="[input, 'py-1.5']" @change="pickFile" />
                    <p :class="hint">Необязательно. PDF, DOCX, JPG или PNG до 10 МБ.<template v-if="form.delete_file"> Файл удалится после сохранения.</template></p>
                    <p v-if="form.errors.file" :class="error">{{ form.errors.file }}</p>
                </div>
            </section>

            <!-- 6. Публикация -->
            <section :id="`sec-6`" :class="box" class="scroll-mt-24">
                <h3 :class="boxTitle">6. Публикация</h3>
                <label class="flex items-start gap-2 text-sm text-zinc-900 dark:text-white">
                    <input v-model="form.is_active" type="checkbox" :class="checkbox" />
                    <span>Показывать на сайте<span :class="hintInline">выключите, чтобы сохранить черновик</span></span>
                </label>
                <label class="flex items-start gap-2 text-sm text-zinc-900 dark:text-white">
                    <input v-model="form.is_archived" type="checkbox" :class="checkbox" />
                    <span>В архиве<span :class="hintInline">мероприятие прошло, на сайте будет в разделе записей</span></span>
                </label>
            </section>

            <!-- Дополнительно -->
            <details class="rounded-lg border border-zinc-200 dark:border-zinc-700" :open="hasAdvancedErrors">
                <summary class="cursor-pointer select-none px-4 py-3 text-sm font-semibold text-zinc-900 dark:text-white">Дополнительно</summary>
                <div class="space-y-4 px-4 pb-4">
                    <div>
                        <label for="ev-slug" :class="label">Адрес страницы</label>
                        <input id="ev-slug" v-model="form.slug" :class="input" placeholder="Заполнится из названия" />
                        <p :class="hint">mag-expert.ru/events/<b>{{ form.slug || "адрес-из-названия" }}</b></p>
                        <p v-if="form.errors.slug" :class="error">{{ form.errors.slug }}</p>
                    </div>
                    <div>
                        <label for="ev-sort" :class="label">Порядок в списках</label>
                        <input id="ev-sort" v-model.number="form.sort_order" type="number" min="0" :class="input" />
                        <p :class="hint">Чем меньше число, тем выше. Обычно 0.</p>
                    </div>
                </div>
            </details>
        </form>
        </div>

        <!-- Панель сохранения всегда под рукой -->
        <div class="sticky bottom-0 z-10 -mx-4 mt-6 border-t border-zinc-200 bg-white/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-10 lg:px-10 dark:border-zinc-800 dark:bg-zinc-900/95">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span v-if="Object.keys(form.errors).length" class="text-sm text-red-600">Проверьте поля, отмеченные красным</span>
                <span v-else-if="form.isDirty" class="text-sm text-zinc-500">Есть несохранённые изменения</span>
                <span v-else class="text-sm text-zinc-500">{{ isEdit ? "Все изменения сохранены" : "Заполните основное и дату — остальное можно позже" }}</span>
                <div class="flex gap-3">
                    <Link :href="route('admin.events')" class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800">Назад к списку</Link>
                    <button type="submit" form="event-form" class="inline-flex items-center rounded-lg bg-zinc-900 px-5 py-2 text-sm font-medium text-white hover:bg-zinc-800 disabled:opacity-50 dark:bg-white dark:text-zinc-900" :disabled="form.processing">
                        {{ form.processing ? "Сохраняем…" : isEdit ? "Сохранить" : "Создать мероприятие" }}
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { useToast } from "vue-toastification";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import MultiSelectInput from "@/Components/Form/MultiSelectInput.vue";
import ImageUpload from "@/Components/Form/ImageUpload.vue";
import SpeakerSelector from "@/Components/Form/SpeakerSelector.vue";
import RichTextEditor from "@/Components/Form/RichTextEditor.vue";

const props = defineProps({
    event: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
    speakers: { type: Array, default: () => [] },
});

const toast = useToast();
const isEdit = computed(() => !!props.event);

// Общие классы полей — просто и одинаково во всей форме
const box = "space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700";
const boxTitle = "text-sm font-semibold text-zinc-900 dark:text-white";
const label = "mb-1 block text-sm font-medium text-zinc-800 dark:text-zinc-200";
const input = "block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:focus:border-white dark:focus:ring-white";
const checkbox = "mt-0.5 size-4 shrink-0 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900 dark:border-zinc-600 dark:bg-zinc-800";
const hint = "mt-1 text-xs text-zinc-500 dark:text-zinc-400";
const hintInline = "block text-xs text-zinc-500 dark:text-zinc-400";
const error = "mt-1 text-sm text-red-600 dark:text-red-400";
const pill = "cursor-pointer rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 dark:border-zinc-600 dark:text-zinc-200";
const pillOn = "!border-zinc-900 bg-zinc-900 !text-white dark:!border-white dark:bg-white dark:!text-zinc-900";

const blank = {
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
    show_price: true,
    format: "online",
    image: null,
    registration_enabled: true,
    selected_categories: [],
    is_active: true,
    sort_order: 0,
    is_archived: false,
    delete_image: false,
    speakers: [],
    kinescope_id: "",
    kinescope_playlist_id: "",
    kinescope_type: "",
    is_live: false,
    max_quantity: null,
    file: null,
    delete_file: false,
    _method: "POST",
};
const form = useForm({ ...blank });

const eventTypeOptions = [
    { value: "webinar", text: "Вебинар" },
    { value: "workshop", text: "Мастер-класс" },
    { value: "course", text: "Курс" },
    { value: "seminar", text: "Семинар" },
    { value: "conference", text: "Конференция" },
    { value: "other", text: "Другое" },
];
const formatOptions = [
    { value: "online", text: "Онлайн" },
    { value: "offline", text: "Офлайн" },
    { value: "hybrid", text: "Гибрид" },
];
const categoryOptions = computed(() => props.categories.map((c) => ({ value: c.id, text: c.name })));

// ---------- Видео Kinescope: одна строка вместо «тип + ID» ----------
const videoLink = ref("");
const detectedFromUrl = ref(false);
const showPreview = ref(false);
const videoId = computed(() => (form.kinescope_type === "playlist" ? form.kinescope_playlist_id : form.kinescope_id));
const isPlaylist = computed({
    get: () => form.kinescope_type === "playlist",
    set: (value) => {
        const id = videoId.value;
        form.kinescope_type = value ? "playlist" : "video";
        form.kinescope_id = value ? "" : id;
        form.kinescope_playlist_id = value ? id : "";
    },
});
const embedUrl = computed(() => (form.kinescope_type === "playlist" ? `https://kinescope.io/embed/pl/${videoId.value}` : `https://kinescope.io/embed/${videoId.value}`));

function parseVideoLink() {
    const raw = videoLink.value.trim();
    showPreview.value = false;
    form.kinescope_id = "";
    form.kinescope_playlist_id = "";
    form.kinescope_type = "";
    detectedFromUrl.value = false;
    if (!raw) {
        return;
    }
    const playlist = raw.match(/kinescope\.io\/(?:embed\/)?pl\/([\w-]+)/i);
    const video = raw.match(/kinescope\.io\/(?:embed\/|watch\/)?([\w-]+)/i);
    if (playlist) {
        form.kinescope_type = "playlist";
        form.kinescope_playlist_id = playlist[1];
        detectedFromUrl.value = true;
    } else if (video) {
        form.kinescope_type = "video";
        form.kinescope_id = video[1];
        detectedFromUrl.value = true;
    } else if (/^[\w-]+$/.test(raw)) {
        form.kinescope_type = "video";
        form.kinescope_id = raw;
    }
}

// ---------- Программа мероприятия ----------
const existingFile = ref(null);
const currentFile = computed(() => {
    if (form.file instanceof File) {
        return { name: form.file.name, url: null };
    }
    if (existingFile.value && !form.delete_file) {
        return { name: existingFile.value.split("/").pop(), url: `/${existingFile.value}` };
    }
    return null;
});
function pickFile(event) {
    form.file = event.target.files?.[0] ?? null;
}
function removeFile() {
    if (form.file instanceof File) {
        form.file = null;
        return;
    }
    // удалится вместе с сохранением формы, без отдельного запроса
    form.delete_file = true;
}

// ---------- Заполнение при редактировании ----------
function resetForm() {
    form.defaults({ ...blank });
    form.reset();
    form.clearErrors();
    videoLink.value = "";
    detectedFromUrl.value = false;
    showPreview.value = false;
    existingFile.value = null;
}

function fill(event) {
    const values = {
        ...blank,
        title: event.title || "",
        slug: event.slug || "",
        start_date: event.start_date ? String(event.start_date).slice(0, 10) : "",
        start_time: event.start_time ? String(event.start_time).slice(0, 5) : "",
        end_date: event.end_date ? String(event.end_date).slice(0, 10) : "",
        end_time: event.end_time ? String(event.end_time).slice(0, 5) : "",
        is_on_demand: !!event.is_on_demand,
        event_type: event.event_type || "",
        short_description: event.short_description || "",
        full_description: event.full_description || "",
        topic: event.topic || "",
        location: event.location || "",
        external_url: event.external_url || "",
        price: event.price ? String(Math.round(Number(event.price))) : "",
        is_paid: !!event.is_paid,
        show_price: event.show_price ?? true,
        format: event.format || "",
        image: event.image || null,
        registration_enabled: event.registration_enabled ?? true,
        selected_categories: (event.categories || []).map((c) => c.id),
        is_active: event.is_active ?? true,
        sort_order: event.sort_order || 0,
        is_archived: !!event.is_archived,
        speakers: (event.speakers || []).map((s) => ({
            id: s.id,
            role: s.pivot?.role || "",
            topic: s.pivot?.topic || "",
            sort_order: s.pivot?.sort_order || 0,
        })),
        kinescope_id: event.kinescope_id || "",
        kinescope_playlist_id: event.kinescope_playlist_id || "",
        kinescope_type: event.kinescope_type || (event.kinescope_playlist_id ? "playlist" : event.kinescope_id ? "video" : ""),
        is_live: !!event.is_live,
        max_quantity: event.max_quantity ?? null,
        _method: "PUT",
    };
    form.defaults(values);
    form.reset();
    form.clearErrors();
    existingFile.value = event.file_path || null;
    const id = values.kinescope_type === "playlist" ? values.kinescope_playlist_id : values.kinescope_id;
    videoLink.value = id ? (values.kinescope_type === "playlist" ? `https://kinescope.io/pl/${id}` : `https://kinescope.io/${id}`) : "";
    detectedFromUrl.value = !!id;
    showPreview.value = false;
}

if (props.event) {
    fill(props.event);
}

// ---------- Закрытие и сохранение ----------
const sections = [
    { id: "sec-1", label: "Основное" },
    { id: "sec-2", label: "Дата и место" },
    { id: "sec-3", label: "Видео" },
    { id: "sec-4", label: "Доступ и оплата" },
    { id: "sec-5", label: "Описание и материалы" },
    { id: "sec-6", label: "Публикация" },
];
const publicUrl = computed(() => (props.event?.slug ? route("events.show", props.event.slug) : null));

const hasAdvancedErrors = computed(() => ["slug", "sort_order"].some((key) => form.errors[key]));

// Предупреждаем, если уходят со страницы с несохранёнными правками
let saving = false;
const removeGuard = router.on("before", (event) => {
    if (saving || !form.isDirty || event.detail.visit.method !== "get") {
        return true;
    }
    return window.confirm("Уйти без сохранения? Изменения пропадут.");
});
const onUnload = (event) => {
    if (form.isDirty && !saving) {
        event.preventDefault();
        event.returnValue = "";
    }
};
onMounted(() => window.addEventListener("beforeunload", onUnload));
onBeforeUnmount(() => {
    removeGuard();
    window.removeEventListener("beforeunload", onUnload);
});

function submitForm() {
    const url = isEdit.value ? route("admin.events.update", props.event.id) : route("admin.events.store");

    form.transform((data) => ({
        ...data,
        start_date: data.is_on_demand ? null : data.start_date || null,
        start_time: data.is_on_demand ? null : data.start_time || null,
        end_date: data.is_on_demand ? null : data.end_date || null,
        end_time: data.is_on_demand ? null : data.end_time || null,
        location: data.location || null,
        external_url: data.external_url || null,
        format: data.format || null,
        price: data.is_paid && data.price !== "" ? data.price : null,
        show_price: data.is_paid ? data.show_price : false,
        kinescope_type: data.kinescope_type || null,
        kinescope_id: data.kinescope_type === "video" ? data.kinescope_id : null,
        kinescope_playlist_id: data.kinescope_type === "playlist" ? data.kinescope_playlist_id : null,
        categories: data.selected_categories || [],
        max_quantity: data.max_quantity === "" || data.max_quantity == null ? null : Number(data.max_quantity),
        sort_order: Number(data.sort_order) || 0,
        delete_file: data.delete_file ? 1 : 0,
        file: data.file instanceof File ? data.file : null,
        image: data.image instanceof File ? data.image : undefined,
    })).post(url, {
        forceFormData: true,
        preserveScroll: true,
        onBefore: () => { saving = true; },
        onSuccess: (page) => {
            // «Сохранено» покажет AdminLayout по сообщению с сервера
            if (props.event) {
                fill(page.props.event ?? props.event);
            }
        },
        onError: () => {
            toast.error("Проверьте поля, отмеченные красным");
        },
        onFinish: () => { saving = false; },
    });
}
</script>
