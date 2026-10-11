<template>
    <AdminFormPage
        :form="form"
        form-id="speaker-form"
        :title="isEdit ? speaker.full_name : 'Новый спикер'"
        :is-edit="isEdit"
        :back-href="route('admin.speakers')"
        back-label="Все спикеры"
        create-label="Добавить спикера"
        create-hint="Обязательны только имя и фамилия"
        @submit="submitForm"
    >
        <section :class="c.box">
            <h3 :class="c.boxTitle">Кто это</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label for="sp-last" :class="c.label">Фамилия <span class="text-red-500">*</span></label>
                    <input id="sp-last" v-model="form.last_name" :class="c.input" />
                    <p v-if="form.errors.last_name" :class="c.error">{{ form.errors.last_name }}</p>
                </div>
                <div>
                    <label for="sp-first" :class="c.label">Имя <span class="text-red-500">*</span></label>
                    <input id="sp-first" v-model="form.first_name" :class="c.input" />
                    <p v-if="form.errors.first_name" :class="c.error">{{ form.errors.first_name }}</p>
                </div>
                <div>
                    <label for="sp-middle" :class="c.label">Отчество</label>
                    <input id="sp-middle" v-model="form.middle_name" :class="c.input" />
                    <p v-if="form.errors.middle_name" :class="c.error">{{ form.errors.middle_name }}</p>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="sp-pos" :class="c.label">Должность</label>
                    <input id="sp-pos" v-model="form.position" :class="c.input" placeholder="Врач акушер-гинеколог, к.м.н." />
                    <p v-if="form.errors.position" :class="c.error">{{ form.errors.position }}</p>
                </div>
                <div>
                    <label for="sp-company" :class="c.label">Где работает</label>
                    <input id="sp-company" v-model="form.company" :class="c.input" placeholder="Клиника, кафедра" />
                    <p v-if="form.errors.company" :class="c.error">{{ form.errors.company }}</p>
                </div>
            </div>
            <ImageUpload label="Фото" v-model="form.photo" v-model:delete-photo="form.delete_photo" :error="form.errors.photo" />
        </section>

        <section :class="c.box">
            <h3 :class="c.boxTitle">О спикере</h3>
            <div>
                <label for="sp-regalia" :class="c.label">Регалии</label>
                <textarea id="sp-regalia" v-model="form.regalia" rows="3" :class="c.input" placeholder="Звания, членство в обществах, опыт"></textarea>
                <p :class="c.hint">Показываются под именем на странице мероприятия.</p>
                <p v-if="form.errors.regalia" :class="c.error">{{ form.errors.regalia }}</p>
            </div>
            <div>
                <label for="sp-desc" :class="c.label">Подробнее</label>
                <textarea id="sp-desc" v-model="form.description" rows="5" :class="c.input"></textarea>
                <p v-if="form.errors.description" :class="c.error">{{ form.errors.description }}</p>
            </div>
        </section>

        <section :class="c.box">
            <h3 :class="c.boxTitle">Публикация</h3>
            <label class="flex items-start gap-2 text-sm text-zinc-900 dark:text-white">
                <input v-model="form.is_active" type="checkbox" :class="c.checkbox" />
                <span>Показывать на сайте<span :class="c.hintInline">скрытого спикера нельзя выбрать в новом мероприятии</span></span>
            </label>
            <div class="max-w-48">
                <label for="sp-sort" :class="c.label">Порядок в списках</label>
                <input id="sp-sort" v-model.number="form.sort_order" type="number" min="0" :class="c.input" />
                <p :class="c.hint">Чем меньше число, тем выше.</p>
                <p v-if="form.errors.sort_order" :class="c.error">{{ form.errors.sort_order }}</p>
            </div>
        </section>

        <section v-if="isEdit" :class="c.box">
            <h3 :class="c.boxTitle">Мероприятия спикера</h3>
            <ul v-if="events.length" class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                <li v-for="event in events" :key="event.id" class="flex items-center justify-between gap-3 py-2">
                    <Link :href="route('admin.events.edit', event.id)" class="min-w-0 truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ event.title }}</Link>
                    <span class="shrink-0 text-zinc-500">{{ formatDate(event.start_date) }}</span>
                </li>
            </ul>
            <p v-else :class="c.hint">Пока не участвует ни в одном мероприятии. Спикера добавляют на странице мероприятия.</p>
        </section>
    </AdminFormPage>
</template>

<script setup>
import { computed } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import { useToast } from "vue-toastification";
import AdminFormPage from "@/Components/Admin/AdminFormPage.vue";
import ImageUpload from "@/Components/Form/ImageUpload.vue";
import * as c from "@/Components/Admin/formClasses.js";

const props = defineProps({
    speaker: { type: Object, default: null },
    events: { type: Array, default: () => [] },
});

const toast = useToast();
const isEdit = computed(() => !!props.speaker);

function valuesFrom(speaker) {
    return {
        first_name: speaker?.first_name ?? "",
        last_name: speaker?.last_name ?? "",
        middle_name: speaker?.middle_name ?? "",
        position: speaker?.position ?? "",
        company: speaker?.company ?? "",
        regalia: speaker?.regalia ?? "",
        description: speaker?.description ?? "",
        photo: speaker?.photo ?? null,
        delete_photo: false,
        is_active: speaker?.is_active ?? true,
        sort_order: speaker?.sort_order ?? 0,
    };
}

const form = useForm(valuesFrom(props.speaker));

const formatDate = (value) => (value ? new Date(`${value}T00:00:00`).toLocaleDateString("ru-RU") : "без даты");

function submitForm() {
    form.transform((data) => ({
        ...data,
        sort_order: Number(data.sort_order) || 0,
        // старое фото уже на сервере — отправляем только новый файл
        photo: data.photo instanceof File ? data.photo : undefined,
        delete_photo: data.delete_photo ? 1 : 0,
        ...(isEdit.value ? { _method: "PUT" } : {}),
    }));
    const url = isEdit.value ? route("admin.speakers.update", props.speaker.id) : route("admin.speakers.store");
    form.post(url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: (page) => {
            if (isEdit.value) {
                form.defaults(valuesFrom(page.props.speaker));
                form.reset();
            }
        },
        onError: () => toast.error("Проверьте поля, отмеченные красным"),
    });
}
</script>
