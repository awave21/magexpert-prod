<template>
    <AdminFormPage
        :form="form"
        form-id="library-form"
        :title="isEdit ? item.title : 'Новый материал'"
        :is-edit="isEdit"
        :back-href="route('admin.medical-library')"
        back-label="Вся библиотека"
        :public-url="item?.file_url ?? null"
        public-label="Открыть файл"
        create-label="Добавить материал"
        create-hint="Нужны название, дата, язык и файл"
        @submit="submitForm"
    >
        <section :class="c.box">
            <div>
                <label for="lib-title" :class="c.label">Название <span class="text-red-500">*</span></label>
                <input id="lib-title" v-model="form.title" :class="c.input" />
                <p v-if="form.errors.title" :class="c.error">{{ form.errors.title }}</p>
            </div>
            <div>
                <label for="lib-desc" :class="c.label">Описание</label>
                <textarea id="lib-desc" v-model="form.description" rows="4" :class="c.input" placeholder="О чём материал — пара предложений для карточки"></textarea>
                <p v-if="form.errors.description" :class="c.error">{{ form.errors.description }}</p>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="lib-date" :class="c.label">Дата публикации <span class="text-red-500">*</span></label>
                    <input id="lib-date" v-model="form.publication_date" type="date" :class="c.input" />
                    <p v-if="form.errors.publication_date" :class="c.error">{{ form.errors.publication_date }}</p>
                </div>
                <div>
                    <span :class="c.label">Язык <span class="text-red-500">*</span></span>
                    <div class="flex gap-2" role="radiogroup" aria-label="Язык">
                        <label v-for="l in languages" :key="l.value" :class="[c.pill, form.language === l.value ? c.pillOn : '']">
                            <input v-model="form.language" type="radio" :value="l.value" class="sr-only" /> {{ l.text }}
                        </label>
                    </div>
                    <p v-if="form.errors.language" :class="c.error">{{ form.errors.language }}</p>
                </div>
            </div>
        </section>

        <section :class="c.box">
            <h3 :class="c.boxTitle">Файл и обложка</h3>
            <div>
                <span :class="c.label">Файл <span class="text-red-500">*</span></span>
                <div v-if="currentFile" class="mt-1 flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700">
                    <span class="min-w-0 flex-1 truncate text-zinc-800 dark:text-zinc-200">{{ currentFile.name }}</span>
                    <a v-if="currentFile.url" :href="currentFile.url" target="_blank" rel="noopener" class="font-medium text-blue-600 hover:underline dark:text-blue-400">Открыть</a>
                    <button type="button" class="font-medium text-zinc-700 hover:underline dark:text-zinc-300" @click="replaceFile">Заменить</button>
                </div>
                <input v-else ref="fileInput" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx" :class="[c.input, 'py-1.5']" @change="pickFile" />
                <p :class="c.hint">
                    PDF, Word, Excel или PowerPoint до 20 МБ.
                    <button v-if="isEdit && !currentFile" type="button" class="font-medium text-blue-600 hover:underline dark:text-blue-400" @click="keepExisting = true">Оставить прежний файл</button>
                </p>
                <p v-if="form.errors.file" :class="c.error">{{ form.errors.file }}</p>
            </div>
            <ImageUpload label="Обложка" v-model="form.image" v-model:delete-photo="form.delete_image" :error="form.errors.image" />
        </section>
    </AdminFormPage>
</template>

<script setup>
import { computed, nextTick, ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "vue-toastification";
import AdminFormPage from "@/Components/Admin/AdminFormPage.vue";
import ImageUpload from "@/Components/Form/ImageUpload.vue";
import * as c from "@/Components/Admin/formClasses.js";

const props = defineProps({
    item: { type: Object, default: null },
});

const toast = useToast();
const isEdit = computed(() => !!props.item);
const languages = [
    { value: "ru", text: "Русский" },
    { value: "en", text: "Английский" },
];

function valuesFrom(item) {
    return {
        title: item?.title ?? "",
        description: item?.description ?? "",
        publication_date: item?.publication_date ?? new Date().toISOString().slice(0, 10),
        language: item?.language ?? "ru",
        file: null,
        image: item?.image_url ?? null,
        delete_image: false,
    };
}

const form = useForm(valuesFrom(props.item));

// Файл можно только заменить: без файла материал на сайте не откроется
const keepExisting = ref(true);
const fileInput = ref(null);
const currentFile = computed(() => {
    if (form.file instanceof File) {
        return { name: form.file.name, url: null };
    }
    if (props.item?.file_name && keepExisting.value) {
        return { name: props.item.file_name, url: props.item.file_url };
    }
    return null;
});
function pickFile(event) {
    form.file = event.target.files?.[0] ?? null;
}
async function replaceFile() {
    form.file = null;
    keepExisting.value = false;
    await nextTick();
    fileInput.value?.click();
}

function submitForm() {
    form.clearErrors();
    if (!currentFile.value) {
        form.setError("file", "Загрузите файл материала.");
        return;
    }
    form.transform((data) => ({
        ...data,
        file: data.file instanceof File ? data.file : undefined,
        image: data.image instanceof File ? data.image : undefined,
        delete_image: data.delete_image ? 1 : 0,
        ...(isEdit.value ? { _method: "PUT" } : {}),
    }));
    const url = isEdit.value ? route("admin.medical-library.update", props.item.id) : route("admin.medical-library.store");
    form.post(url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: (page) => {
            if (isEdit.value) {
                keepExisting.value = true;
                form.defaults(valuesFrom(page.props.item));
                form.reset();
            }
        },
        onError: () => toast.error("Проверьте поля, отмеченные красным"),
    });
}
</script>
