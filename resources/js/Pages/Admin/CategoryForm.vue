<template>
    <AdminFormPage
        :form="form"
        form-id="category-form"
        :title="isEdit ? `Категория: ${category.name}` : 'Новая категория'"
        :is-edit="isEdit"
        :back-href="route('admin.categories')"
        back-label="Все категории"
        create-label="Создать категорию"
        create-hint="Достаточно названия — адрес заполнится сам"
        @submit="submitForm"
    >
        <section :class="c.box">
            <div>
                <label for="cat-name" :class="c.label">Название <span class="text-red-500">*</span></label>
                <input id="cat-name" v-model="form.name" :class="c.input" placeholder="Эстетическая гинекология" />
                <p v-if="form.errors.name" :class="c.error">{{ form.errors.name }}</p>
            </div>
            <div>
                <label for="cat-desc" :class="c.label">Описание</label>
                <textarea id="cat-desc" v-model="form.description" rows="3" :class="c.input" placeholder="Коротко, какие мероприятия сюда попадают"></textarea>
                <p v-if="form.errors.description" :class="c.error">{{ form.errors.description }}</p>
            </div>
            <label class="flex items-start gap-2 text-sm text-zinc-900 dark:text-white">
                <input v-model="form.is_active" type="checkbox" :class="c.checkbox" />
                <span>Показывать на сайте<span :class="c.hintInline">выключенная категория не видна в фильтрах каталога</span></span>
            </label>
        </section>

        <details class="rounded-lg border border-zinc-200 dark:border-zinc-700" :open="!!(form.errors.slug || form.errors.sort_order)">
            <summary class="cursor-pointer select-none px-4 py-3 text-sm font-semibold text-zinc-900 dark:text-white">Дополнительно</summary>
            <div class="space-y-4 px-4 pb-4">
                <div>
                    <label for="cat-slug" :class="c.label">Адрес</label>
                    <input id="cat-slug" v-model="form.slug" :class="c.input" placeholder="Заполнится из названия" />
                    <p v-if="form.errors.slug" :class="c.error">{{ form.errors.slug }}</p>
                </div>
                <div>
                    <label for="cat-sort" :class="c.label">Порядок в списках</label>
                    <input id="cat-sort" v-model.number="form.sort_order" type="number" min="0" :class="c.input" />
                    <p :class="c.hint">Чем меньше число, тем выше. Обычно 0.</p>
                    <p v-if="form.errors.sort_order" :class="c.error">{{ form.errors.sort_order }}</p>
                </div>
            </div>
        </details>

        <p v-if="isEdit" class="text-sm text-zinc-500 dark:text-zinc-400">Мероприятий в категории: {{ category.events_count }}</p>
    </AdminFormPage>
</template>

<script setup>
import { computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import AdminFormPage from "@/Components/Admin/AdminFormPage.vue";
import * as c from "@/Components/Admin/formClasses.js";

const props = defineProps({
    category: { type: Object, default: null },
});

const isEdit = computed(() => !!props.category);

const form = useForm({
    name: props.category?.name ?? "",
    slug: props.category?.slug ?? "",
    description: props.category?.description ?? "",
    is_active: props.category?.is_active ?? true,
    sort_order: props.category?.sort_order ?? 0,
});

function submitForm() {
    const options = {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    };
    form.transform((data) => ({ ...data, sort_order: Number(data.sort_order) || 0 }));
    if (isEdit.value) {
        form.put(route("admin.categories.update", props.category.id), options);
    } else {
        form.post(route("admin.categories.store"), options);
    }
}
</script>
