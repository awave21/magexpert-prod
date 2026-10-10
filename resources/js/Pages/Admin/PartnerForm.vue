<template>
    <AdminFormPage
        :form="form"
        form-id="partner-form"
        :title="isEdit ? partner.name : 'Новый партнёр'"
        :is-edit="isEdit"
        :back-href="route('admin.partners')"
        back-label="Все партнёры"
        create-label="Добавить партнёра"
        create-hint="Нужны название и логотип"
        @submit="submitForm"
    >
        <section :class="c.box">
            <div>
                <label for="pt-name" :class="c.label">Название <span class="text-red-500">*</span></label>
                <input id="pt-name" v-model="form.name" :class="c.input" />
                <p v-if="form.errors.name" :class="c.error">{{ form.errors.name }}</p>
            </div>
            <div>
                <label for="pt-site" :class="c.label">Сайт</label>
                <input id="pt-site" v-model="form.website_url" type="url" :class="c.input" placeholder="https://" />
                <p v-if="form.errors.website_url" :class="c.error">{{ form.errors.website_url }}</p>
            </div>
            <div>
                <label for="pt-desc" :class="c.label">Описание</label>
                <textarea id="pt-desc" v-model="form.description" rows="4" maxlength="1000" :class="c.input"></textarea>
                <p :class="c.hint">{{ form.description.length }}/1000</p>
                <p v-if="form.errors.description" :class="c.error">{{ form.errors.description }}</p>
            </div>
            <div>
                <ImageUpload label="Логотип *" v-model="form.logo" :error="form.errors.logo" />
                <p :class="c.hint">Квадратный, около 300×300, до 2 МБ.<template v-if="isEdit"> Чтобы заменить, выберите новый файл.</template></p>
            </div>
        </section>
    </AdminFormPage>
</template>

<script setup>
import { computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "vue-toastification";
import AdminFormPage from "@/Components/Admin/AdminFormPage.vue";
import ImageUpload from "@/Components/Form/ImageUpload.vue";
import * as c from "@/Components/Admin/formClasses.js";

const props = defineProps({
    partner: { type: Object, default: null },
});

const toast = useToast();
const isEdit = computed(() => !!props.partner);

function valuesFrom(partner) {
    return {
        name: partner?.name ?? "",
        description: partner?.description ?? "",
        website_url: partner?.website_url ?? "",
        logo: partner?.logo_url ?? null,
    };
}

const form = useForm(valuesFrom(props.partner));

function submitForm() {
    form.clearErrors();
    if (!form.logo) {
        form.setError("logo", "Загрузите логотип.");
        return;
    }
    form.transform((data) => ({
        ...data,
        logo: data.logo instanceof File ? data.logo : undefined,
        ...(isEdit.value ? { _method: "PUT" } : {}),
    }));
    const url = isEdit.value ? route("admin.partners.update", props.partner.id) : route("admin.partners.store");
    form.post(url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: (page) => {
            if (isEdit.value) {
                form.defaults(valuesFrom(page.props.partner));
                form.reset();
            }
        },
        onError: () => toast.error("Проверьте поля, отмеченные красным"),
    });
}
</script>
