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

        <div class="pb-24">
            <div class="mb-6 flex gap-1 overflow-x-auto border-b border-zinc-200 [scrollbar-width:none] dark:border-zinc-800 [&::-webkit-scrollbar]:hidden" role="tablist" aria-label="Разделы мероприятия">
                <button
                    v-for="s in sections"
                    :id="`tab-${s.id}`"
                    :key="s.id"
                    type="button"
                    role="tab"
                    :aria-selected="tab === s.id"
                    :aria-controls="s.id"
                    class="-mb-px flex shrink-0 cursor-pointer items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900"
                    :class="tab === s.id
                        ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white'
                        : 'border-transparent text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                    @click="tab = s.id"
                >
                    {{ s.label }}
                    <span v-if="tabHasErrors(s.id)" class="size-2 rounded-full bg-red-500" aria-label="есть ошибки"></span>
                </button>
            </div>

            <div class="flex items-start gap-6">
            <form id="event-form" class="min-w-0 flex-1" novalidate @submit.prevent="submitForm()">
            <!-- 1. Основное -->
            <section id="sec-1" v-show="tab === 'sec-1'" role="tabpanel" :aria-labelledby="`tab-sec-1`" :class="box">
                <h3 :class="boxTitle">Основное</h3>
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div class="lg:col-span-2">
                        <label for="ev-title" :class="label">Название <span class="text-red-500">*</span></label>
                        <input id="ev-title" v-model="form.title" :class="input" placeholder="2 ступень. Первая практика гинеколога-эстетиста" />
                        <p v-if="form.errors.title" :class="error">{{ form.errors.title }}</p>
                    </div>
                    <div>
                        <label for="ev-topic" :class="label">Тема</label>
                        <input id="ev-topic" v-model="form.topic" :class="input" placeholder="Например, инъекционные методики" />
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div>
                        <SelectInput
                            id="ev-type"
                            v-model="form.event_type"
                            :options="eventTypeOptions"
                            label="Тип *"
                            placeholder="Выберите тип"
                            :error="form.errors.event_type"
                        />
                    </div>
                    <div>
                        <SelectInput
                            id="ev-format"
                            v-model="form.format"
                            :options="formatSelectOptions"
                            label="Формат"
                            placeholder="Не указан"
                            :error="form.errors.format"
                        />
                    </div>
                    <div>
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
                    </div>
                </div>
                            <div>
                    <label for="ev-short" :class="label">Кратко</label>
                    <textarea id="ev-short" v-model="form.short_description" rows="2" :class="input" placeholder="Одно-два предложения для карточки в каталоге"></textarea>
                    <p v-if="form.errors.short_description" :class="error">{{ form.errors.short_description }}</p>
                </div>
            </section>

            <!-- 2. Дата и место -->
            <section id="sec-2" v-show="tab === 'sec-2'" role="tabpanel" :aria-labelledby="`tab-sec-2`" :class="box">
                <h3 :class="boxTitle">Дата и место</h3>
                <label class="flex items-start gap-2 text-sm text-zinc-900 dark:text-white">
                    <input v-model="form.is_on_demand" type="checkbox" :class="checkbox" />
                    <span>Без даты — смотреть в любое время<span :class="hintInline">запись доступна сразу после регистрации</span></span>
                </label>
                <div v-if="!form.is_on_demand" class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div>
                        <label for="ev-sd" :class="label">Дата начала <span class="text-red-500">*</span></label>
                        <DateInput id="ev-sd" v-model="form.start_date" />
                        <p v-if="form.errors.start_date" :class="error">{{ form.errors.start_date }}</p>
                    </div>
                    <div>
                        <label for="ev-st" :class="label">Время начала, МСК</label>
                        <TimeInput id="ev-st" v-model="form.start_time" />
                        <p v-if="form.errors.start_time" :class="error">{{ form.errors.start_time }}</p>
                    </div>
                    <div>
                        <label for="ev-ed" :class="label">Дата окончания</label>
                        <DateInput id="ev-ed" v-model="form.end_date" :min="form.start_date || ''" />
                        <p v-if="form.errors.end_date" :class="error">{{ form.errors.end_date }}</p>
                    </div>
                    <div>
                        <label for="ev-et" :class="label">Время окончания, МСК</label>
                        <TimeInput id="ev-et" v-model="form.end_time" />
                        <p v-if="form.errors.end_time" :class="error">{{ form.errors.end_time }}</p>
                    </div>
                </div>
                <div v-if="form.format !== 'online'" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <label for="ev-loc" :class="label">Место проведения</label>
                        <input id="ev-loc" v-model="form.location" :class="input" placeholder="Москва, ул. Рабочая, 93" />
                        <p v-if="form.errors.location" :class="error">{{ form.errors.location }}</p>
                    </div>
                    <div>
                        <label for="ev-city" :class="label">Город</label>
                        <input id="ev-city" v-model="form.city" :class="input" placeholder="Москва" />
                        <p v-if="form.errors.city" :class="error">{{ form.errors.city }}</p>
                    </div>
                </div>
            </section>

            <!-- 3. Видео -->
            <section id="sec-3" v-show="tab === 'sec-3'" role="tabpanel" :aria-labelledby="`tab-sec-3`" :class="box">
                <h3 :class="boxTitle">Видео Kinescope</h3>
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
            <section id="sec-4" v-show="tab === 'sec-4'" role="tabpanel" :aria-labelledby="`tab-sec-4`" :class="box">
                <h3 :class="boxTitle">Доступ и оплата</h3>
                <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
                    <div>
                        <span :class="label">Оплата</span>
                        <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Оплата">
                            <label :class="[pill, !form.is_paid ? pillOn : '']">
                                <input v-model="form.is_paid" type="radio" :value="false" class="sr-only" /> Бесплатное
                            </label>
                            <label :class="[pill, form.is_paid ? pillOn : '']">
                                <input v-model="form.is_paid" type="radio" :value="true" class="sr-only" /> Платное
                            </label>
                        </div>
                        <p v-if="!form.is_paid" :class="hint">Смотреть смогут все, кто зарегистрировался.</p>
                    </div>
                    <template v-if="form.is_paid">
                        <div>
                            <label for="ev-price" :class="label">Стоимость, ₽ <span class="text-red-500">*</span></label>
                            <input id="ev-price" v-model="form.price" type="number" min="0" step="1" inputmode="numeric" :class="input" placeholder="5000" />
                            <p v-if="form.errors.price" :class="error">{{ form.errors.price }}</p>
                        </div>
                        <label class="flex items-start gap-2 text-sm text-zinc-900 lg:pt-7 dark:text-white">
                            <input v-model="form.show_price" type="checkbox" :class="checkbox" />
                            <span>Показывать цену на сайте<span :class="hintInline">иначе будет написано «Платно»</span></span>
                        </label>
                    </template>
                </div>

                <label class="flex items-start gap-2 text-sm text-zinc-900 dark:text-white">
                    <input v-model="form.registration_enabled" type="checkbox" :class="checkbox" />
                    <span>Открыта регистрация</span>
                </label>

                <div v-if="form.registration_enabled" class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
                    <div class="lg:col-span-2">
                        <label for="ev-tags" :class="label">Теги для рассылок</label>
                        <input id="ev-tags" v-model="form.tags_text" :class="input" placeholder="Например: гинекология, школа, 2026" />
                        <p :class="hint">Через запятую. Контакт в Sender получит эти теги при регистрации, по ним отбираются получатели рассылки. При создании заполняется адресом страницы и датой; при смене адреса или даты обновите вручную.</p>
                        <p v-if="form.errors.tags || form.errors['tags.0']" :class="error">{{ form.errors.tags || form.errors['tags.0'] }}</p>
                    </div>
                    <div>
                        <label for="ev-letter" :class="label">Письмо о регистрации</label>
                        <input id="ev-letter" v-model="form.letter_draft_id" :class="input" placeholder="Стандартное письмо" />
                        <p :class="hint">ID или алиас (draft-12) шаблона из Sender. Если пусто, уйдёт стандартное письмо.</p>
                        <p v-if="form.errors.letter_draft_id" :class="error">{{ form.errors.letter_draft_id }}</p>
                    </div>
                    <div>
                        <label for="ev-max" :class="label">Мест</label>
                        <input id="ev-max" v-model.number="form.max_quantity" type="number" min="0" step="1" :class="input" placeholder="Без ограничений" />
                        <p v-if="form.errors.max_quantity" :class="error">{{ form.errors.max_quantity }}</p>
                    </div>
                    <div class="lg:col-span-2">
                        <label for="ev-ext" :class="label">Регистрация на другом сайте</label>
                        <input id="ev-ext" v-model="form.external_url" type="url" :class="input" placeholder="https://" />
                        <p :class="hint">Если заполнить, кнопка «Записаться» поведёт туда.</p>
                        <p v-if="form.errors.external_url" :class="error">{{ form.errors.external_url }}</p>
                    </div>
                </div>
            </section>

            <!-- 5. Описание и материалы -->
            <section id="sec-5" v-show="tab === 'sec-5'" role="tabpanel" :aria-labelledby="`tab-sec-5`" :class="box">
                <h3 :class="boxTitle">Описание и материалы</h3>
                <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
                    <div class="lg:col-span-2">
                    <RichTextEditor
                        id="full_description"
                        label="Подробное описание"
                        v-model="form.full_description"
                        :error="form.errors.full_description"
                        placeholder="Для кого, о чём, программа"
                        :upload-endpoint="route('admin.events.upload-image')"
                        :event-id="props.event?.id ?? null"
                    />
                    </div>
                    <div class="space-y-5">
    <ImageUpload label="Обложка" v-model="form.image" v-model:delete-photo="form.delete_image" :error="form.errors.image" />

                    <div>
                        <span :class="label">Программа мероприятия</span>
                        <div v-if="currentFile" class="mt-1 flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700">
                            <span class="min-w-0 flex-1 truncate text-zinc-800 dark:text-zinc-200">{{ currentFile.name }}</span>
                            <a v-if="currentFile.url" :href="currentFile.url" target="_blank" rel="noopener" class="font-medium text-blue-600 hover:underline dark:text-blue-400">Открыть</a>
                            <button type="button" class="font-medium text-red-600 hover:underline" @click="removeFile">Убрать</button>
                        </div>
                        <label v-else :class="fileButton">
                            <input id="event_file" type="file" accept=".pdf,.docx,.jpg,.jpeg,.png" class="sr-only" @change="pickFile" />
                            Выбрать файл
                        </label>
                        <p :class="hint">Необязательно. PDF, DOCX, JPG или PNG до 10 МБ.<template v-if="form.delete_file"> Файл удалится после сохранения.</template></p>
                        <p v-if="form.errors.file" :class="error">{{ form.errors.file }}</p>
                    </div>
                    </div>
                </div>
                <SpeakerSelector id="speakers" label="Спикеры" v-model="form.speakers" :speakers="speakers" :error="form.errors.speakers" />
            </section>

            <!-- 6. Публикация -->
            <section id="sec-6" v-show="tab === 'sec-6'" role="tabpanel" :aria-labelledby="`tab-sec-6`" :class="box">
                <h3 :class="boxTitle">Публикация</h3>
                <label class="flex items-start gap-2 text-sm text-zinc-900 dark:text-white">
                    <input v-model="form.is_active" type="checkbox" :class="checkbox" />
                    <span>Показывать на сайте<span :class="hintInline">выключите, чтобы сохранить черновик</span></span>
                </label>
                <label class="flex items-start gap-2 text-sm text-zinc-900 dark:text-white">
                    <input v-model="form.is_archived" type="checkbox" :class="checkbox" />
                    <span>В архиве<span :class="hintInline">мероприятие прошло, на сайте будет в разделе записей</span></span>
                </label>
                <div class="grid grid-cols-1 gap-4 pt-2 md:grid-cols-2">
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
                        <p v-if="form.errors.sort_order" :class="error">{{ form.errors.sort_order }}</p>
                    </div>
                </div>
            </section>

        </form>

            <!-- Справа: что осталось заполнить и как увидят на сайте -->
            <aside class="hidden w-72 shrink-0 space-y-6 xl:block" aria-label="Готовность и превью">
                <div>
                    <div class="mb-2 flex items-baseline justify-between text-sm">
                        <span class="font-semibold text-zinc-900 dark:text-white">Готовность</span>
                        <span class="text-zinc-500 dark:text-zinc-400">{{ readyCount }} из {{ readiness.length }}</span>
                    </div>
                    <div class="mb-3 h-1.5 rounded-full bg-zinc-200 dark:bg-zinc-700">
                        <div class="h-1.5 rounded-full bg-zinc-900 transition-all dark:bg-white" :style="{ width: `${(readyCount / readiness.length) * 100}%` }"></div>
                    </div>
                    <ul class="space-y-1.5 text-sm">
                        <li v-for="item in readiness" :key="item.label" class="flex items-center gap-2" :class="item.done ? 'text-zinc-900 dark:text-white' : 'text-zinc-500 dark:text-zinc-400'">
                            <span class="flex size-4 shrink-0 items-center justify-center rounded-full text-[10px]" :class="item.done ? 'bg-emerald-500 text-white' : 'border border-zinc-300 dark:border-zinc-600'">{{ item.done ? "✓" : "" }}</span>
                            <button type="button" class="text-left hover:underline" @click="tab = item.tab">{{ item.label }}</button>
                        </li>
                    </ul>
                </div>

                <div>
                    <p class="mb-2 text-sm font-semibold text-zinc-900 dark:text-white">Как увидят на сайте</p>
                    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <div class="aspect-video bg-zinc-100 dark:bg-zinc-800">
                            <img v-if="previewImage" :src="previewImage" alt="" class="size-full object-cover" />
                        </div>
                        <div class="space-y-1 p-3">
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ previewMeta }}</p>
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ form.title || "Название мероприятия" }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ previewWhen }}</p>
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ previewPrice }}</p>
                        </div>
                    </div>
                </div>
            </aside>
            </div>
        </div>

        <!-- Панель сохранения всегда под рукой -->
        <div class="sticky bottom-0 z-10 -mx-4 mt-6 border-t border-zinc-200 bg-white/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-6 lg:px-6 dark:border-zinc-800 dark:bg-zinc-900/95">
            <div class="flex items-center justify-between gap-3">
                <span v-if="Object.keys(form.errors).length" class="text-sm text-red-600">Проверьте поля, отмеченные красным</span>
                <span v-else-if="form.isDirty" class="hidden text-sm text-zinc-500 sm:inline">Есть несохранённые изменения</span>
                <span v-else class="hidden text-sm text-zinc-500 sm:inline">{{ isEdit ? "Все изменения сохранены" : "Заполните основное и дату — остальное можно позже" }}</span>
                <div class="flex gap-3">
                    <Link :href="route('admin.events')" class="inline-flex items-center rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 sm:px-4 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800">Назад<span class="hidden sm:inline">&nbsp;к списку</span></Link>
                    <button type="submit" form="event-form" class="inline-flex items-center rounded-lg bg-zinc-900 px-5 py-2 text-sm font-medium text-white hover:bg-zinc-800 disabled:opacity-50 dark:bg-white dark:text-zinc-900" :disabled="form.processing">
                        {{ form.processing ? "Сохраняем…" : isEdit ? "Сохранить" : "Создать мероприятие" }}
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { useToast } from "vue-toastification";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import MultiSelectInput from "@/Components/Form/MultiSelectInput.vue";
import ImageUpload from "@/Components/Form/ImageUpload.vue";
import SpeakerSelector from "@/Components/Form/SpeakerSelector.vue";
import RichTextEditor from "@/Components/Form/RichTextEditor.vue";
import SelectInput from "@/Components/Form/SelectInput.vue";
import DateInput from "@/Components/Form/DateInput.vue";
import TimeInput from "@/Components/Form/TimeInput.vue";
import { label, input, checkbox, hint, hintInline, error, pill, pillOn, fileButton } from "@/Components/Admin/formClasses.js";

// Секции — плоские блоки под вкладками, без рамок: название раздела уже на вкладке
const box = "space-y-5";
const boxTitle = "sr-only";

const props = defineProps({
    event: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
    speakers: { type: Array, default: () => [] },
});

const toast = useToast();
const isEdit = computed(() => !!props.event);

// Общие классы полей — один файл на всю админку
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
    city: "",
    letter_draft_id: "",
    tags_text: "",
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
const formatSelectOptions = [{ value: "", text: "Не указан" }, ...formatOptions];
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
        city: event.city || "",
        letter_draft_id: event.letter_draft_id || "",
        tags_text: Array.isArray(event.tags) ? event.tags.join(", ") : "",
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
// ---------- Готовность и превью справа ----------
const readiness = computed(() => [
    { label: "Название и тип", done: !!form.title.trim() && !!form.event_type, tab: "sec-1" },
    { label: "Дата и место", done: form.is_on_demand || !!form.start_date, tab: "sec-2" },
    { label: "Спикеры", done: form.speakers.length > 0, tab: "sec-5" },
    { label: "Обложка", done: !!previewImage.value, tab: "sec-5" },
    { label: "Описание", done: !!(form.short_description.trim() || form.full_description.trim()), tab: "sec-1" },
]);
const readyCount = computed(() => readiness.value.filter((item) => item.done).length);

const previewImage = ref(null);
watch(
    () => [form.image, form.delete_image],
    ([image, removed]) => {
        if (previewImage.value?.startsWith("blob:")) {
            URL.revokeObjectURL(previewImage.value);
        }
        previewImage.value = removed ? null : image instanceof File ? URL.createObjectURL(image) : typeof image === "string" && image ? image : null;
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    if (previewImage.value?.startsWith("blob:")) {
        URL.revokeObjectURL(previewImage.value);
    }
});

const previewMeta = computed(() => {
    const type = eventTypeOptions.find((o) => o.value === form.event_type)?.text;
    const format = formatOptions.find((o) => o.value === form.format)?.text;
    return [type, format].filter(Boolean).join(" · ") || "Тип · формат";
});
const previewWhen = computed(() => {
    if (form.is_on_demand) {
        return "В записи, без даты";
    }
    const date = form.start_date ? new Date(`${form.start_date}T00:00:00`).toLocaleDateString("ru-RU", { day: "numeric", month: "long", year: "numeric" }) : "Дата не указана";
    return [date, form.start_time, form.format !== "online" ? form.city : ""].filter(Boolean).join(" · ");
});
const previewPrice = computed(() => {
    if (!form.is_paid) {
        return "Бесплатно";
    }
    return form.show_price && form.price ? `${Number(form.price).toLocaleString("ru-RU")} ₽` : "Платно";
});

const publicUrl = computed(() => (props.event?.slug ? route("events.show", props.event.slug) : null));

const tab = ref("sec-1");
const tabFields = {
    "sec-1": ["title", "event_type", "format", "selected_categories", "categories"],
    "sec-2": ["start_date", "start_time", "end_date", "end_time", "location", "city", "is_on_demand"],
    "sec-3": ["kinescope_id", "kinescope_playlist_id", "kinescope_type"],
    "sec-4": ["price", "show_price", "tags", "tags.0", "letter_draft_id", "max_quantity", "external_url"],
    "sec-5": ["short_description", "full_description", "topic", "speakers", "image", "file"],
    "sec-6": ["slug", "sort_order"],
};
const tabHasErrors = (id) => tabFields[id].some((key) => form.errors[key]);
// после неудачного сохранения открываем первую вкладку с ошибкой
watch(
    () => ({ ...form.errors }),
    () => {
        const first = Object.keys(tabFields).find((id) => tabHasErrors(id));
        if (first && !tabHasErrors(tab.value)) {
            tab.value = first;
        }
    },
);

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
        city: data.city || null,
        letter_draft_id: data.letter_draft_id || null,
        tags: (data.tags_text || "").split(/[,\n]/).map((t) => t.trim()).filter(Boolean),
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
