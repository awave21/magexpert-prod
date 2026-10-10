<template>
    <Head :title="title" />

    <AdminLayout>
        <template #header>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0">
                    <Link :href="backHref" class="text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">← {{ backLabel }}</Link>
                    <h1 class="mt-1 truncate text-2xl font-semibold text-zinc-900 dark:text-white">{{ title }}</h1>
                </div>
                <a v-if="publicUrl" :href="publicUrl" target="_blank" rel="noopener" class="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">{{ publicLabel }} ↗</a>
            </div>
        </template>

        <div class="flex items-start gap-8 pb-24">
            <nav v-if="sections.length" aria-label="Разделы формы" class="sticky top-6 hidden w-48 shrink-0 xl:block">
                <ul class="space-y-1 text-sm">
                    <li v-for="s in sections" :key="s.id">
                        <a :href="`#${s.id}`" class="block rounded-md px-3 py-1.5 text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white">{{ s.label }}</a>
                    </li>
                </ul>
            </nav>

            <form :id="formId" class="min-w-0 flex-1 space-y-5" :class="sections.length ? '' : 'max-w-3xl'" novalidate @submit.prevent="$emit('submit')">
                <slot />
            </form>

            <aside v-if="$slots.aside" class="hidden w-72 shrink-0 space-y-4 lg:block">
                <slot name="aside" />
            </aside>
        </div>

        <!-- Панель сохранения всегда под рукой -->
        <div class="sticky bottom-0 z-10 -mx-4 mt-6 border-t border-zinc-200 bg-white/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-10 lg:px-10 dark:border-zinc-800 dark:bg-zinc-900/95">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span v-if="Object.keys(form.errors).length" class="text-sm text-red-600">Проверьте поля, отмеченные красным</span>
                <span v-else-if="form.isDirty" class="text-sm text-zinc-500">Есть несохранённые изменения</span>
                <span v-else class="text-sm text-zinc-500">{{ isEdit ? "Все изменения сохранены" : createHint }}</span>
                <div class="flex gap-3">
                    <Link :href="backHref" class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800">{{ backButtonLabel }}</Link>
                    <button type="submit" :form="formId" class="inline-flex items-center rounded-lg bg-zinc-900 px-5 py-2 text-sm font-medium text-white hover:bg-zinc-800 disabled:opacity-50 dark:bg-white dark:text-zinc-900" :disabled="form.processing">
                        {{ form.processing ? "Сохраняем…" : isEdit ? "Сохранить" : createLabel }}
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<script setup>
import { onBeforeUnmount, onMounted } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";

// Каркас страницы создания/редактирования: шапка, оглавление, форма и панель «Сохранить» внизу
const props = defineProps({
    form: { type: Object, required: true },
    formId: { type: String, default: "admin-form" },
    title: { type: String, required: true },
    isEdit: { type: Boolean, default: false },
    backHref: { type: String, required: true },
    backLabel: { type: String, default: "Назад" },
    backButtonLabel: { type: String, default: "Назад к списку" },
    publicUrl: { type: String, default: null },
    publicLabel: { type: String, default: "Открыть на сайте" },
    createLabel: { type: String, default: "Создать" },
    createHint: { type: String, default: "Заполните обязательные поля, отмеченные *" },
    sections: { type: Array, default: () => [] },
});

defineEmits(["submit"]);

// Предупреждаем, если уходят со страницы с несохранёнными правками
const removeGuard = router.on("before", (event) => {
    if (props.form.processing || !props.form.isDirty || event.detail.visit.method !== "get") {
        return true;
    }
    return window.confirm("Уйти без сохранения? Изменения пропадут.");
});
const onUnload = (event) => {
    if (props.form.isDirty && !props.form.processing) {
        event.preventDefault();
        event.returnValue = "";
    }
};
onMounted(() => window.addEventListener("beforeunload", onUnload));
onBeforeUnmount(() => {
    removeGuard();
    window.removeEventListener("beforeunload", onUnload);
});
</script>
