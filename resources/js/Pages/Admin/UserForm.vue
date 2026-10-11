<template>
    <AdminFormPage
        :form="form"
        form-id="user-form"
        :title="isEdit ? displayName : 'Новый пользователь'"
        :is-edit="isEdit"
        :back-href="isEdit ? route('admin.users.show', user.id) : route('admin.users')"
        :back-label="isEdit ? 'Карточка пользователя' : 'Все пользователи'"
        :back-button-label="isEdit ? 'Назад к карточке' : 'Назад к списку'"
        create-label="Создать пользователя"
        create-hint="Обязательны ФИО, email и пароль"
        @submit="submitForm"
    >
        <section :class="c.box">
            <h3 :class="c.boxTitle">Человек</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label for="u-last" :class="c.label">Фамилия <span class="text-red-500">*</span></label>
                    <input id="u-last" v-model="form.last_name" autocomplete="off" :class="c.input" />
                    <p v-if="form.errors.last_name" :class="c.error">{{ form.errors.last_name }}</p>
                </div>
                <div>
                    <label for="u-first" :class="c.label">Имя <span class="text-red-500">*</span></label>
                    <input id="u-first" v-model="form.first_name" autocomplete="off" :class="c.input" />
                    <p v-if="form.errors.first_name" :class="c.error">{{ form.errors.first_name }}</p>
                </div>
                <div>
                    <label for="u-middle" :class="c.label">Отчество</label>
                    <input id="u-middle" v-model="form.middle_name" autocomplete="off" :class="c.input" />
                    <p v-if="form.errors.middle_name" :class="c.error">{{ form.errors.middle_name }}</p>
                </div>
            </div>
            <ImageUpload label="Фото" v-model="form.avatar" v-model:delete-photo="form.delete_avatar" :error="form.errors.avatar" />
        </section>

        <section :class="c.box">
            <h3 :class="c.boxTitle">Контакты</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="u-email" :class="c.label">Email <span class="text-red-500">*</span></label>
                    <input id="u-email" v-model="form.email" type="email" autocomplete="off" :class="c.input" />
                    <p v-if="isEdit" :class="c.hint">На этот адрес приходят письма и уведомления.</p>
                    <p v-if="form.errors.email" :class="c.error">{{ form.errors.email }}</p>
                </div>
                <PhoneInput id="u-phone" v-model="form.phone" label="Телефон" :error="form.errors.phone" />
            </div>
        </section>

        <section :class="c.box">
            <h3 :class="c.boxTitle">Работа</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label for="u-company" :class="c.label">Организация</label>
                    <input id="u-company" v-model="form.company" :class="c.input" />
                    <p v-if="form.errors.company" :class="c.error">{{ form.errors.company }}</p>
                </div>
                <div>
                    <label for="u-position" :class="c.label">Должность</label>
                    <input id="u-position" v-model="form.position" :class="c.input" />
                    <p v-if="form.errors.position" :class="c.error">{{ form.errors.position }}</p>
                </div>
                <div>
                    <label for="u-city" :class="c.label">Город</label>
                    <input id="u-city" v-model="form.city" :class="c.input" />
                    <p v-if="form.errors.city" :class="c.error">{{ form.errors.city }}</p>
                </div>
            </div>
        </section>

        <section v-if="!isEdit" :class="c.box">
            <h3 :class="c.boxTitle">Вход</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="u-pass" :class="c.label">Пароль <span class="text-red-500">*</span></label>
                    <input id="u-pass" v-model="form.password" type="password" autocomplete="new-password" :class="c.input" />
                    <p :class="c.hint">Не короче 8 символов. Человек сможет сменить его в кабинете.</p>
                    <p v-if="form.errors.password" :class="c.error">{{ form.errors.password }}</p>
                </div>
                <div>
                    <label for="u-pass2" :class="c.label">Пароль ещё раз <span class="text-red-500">*</span></label>
                    <input id="u-pass2" v-model="form.password_confirmation" type="password" autocomplete="new-password" :class="c.input" />
                </div>
            </div>
            <div v-if="isAdmin && roles.length">
                <span :class="c.label">Роли</span>
                <div class="flex flex-wrap gap-2">
                    <label v-for="role in roles" :key="role.id" :class="[c.pill, form.roles.includes(role.name) ? c.pillOn : '']">
                        <input v-model="form.roles" type="checkbox" :value="role.name" class="sr-only" /> {{ roleLabel(role.name) }}
                    </label>
                </div>
                <p :class="c.hint">Без ролей — обычный пользователь сайта.</p>
                <p v-if="form.errors.roles" :class="c.error">{{ form.errors.roles }}</p>
            </div>
        </section>
        <p v-else :class="c.hint">Роли и доступы к мероприятиям меняются в карточке пользователя.</p>
    </AdminFormPage>
</template>

<script setup>
import { computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "vue-toastification";
import AdminFormPage from "@/Components/Admin/AdminFormPage.vue";
import ImageUpload from "@/Components/Form/ImageUpload.vue";
import PhoneInput from "@/Components/Form/PhoneInput.vue";
import * as c from "@/Components/Admin/formClasses.js";

const props = defineProps({
    user: { type: Object, default: null },
    roles: { type: Array, default: () => [] },
    isAdmin: { type: Boolean, default: false },
});

const toast = useToast();
const isEdit = computed(() => !!props.user);
const displayName = computed(() => [props.user?.last_name, props.user?.first_name, props.user?.middle_name].filter(Boolean).join(" ") || props.user?.email);

const roleNames = { admin: "Администратор", manager: "Менеджер", editor: "Редактор", user: "Пользователь" };
const roleLabel = (name) => roleNames[name] ?? name;

const form = useForm({
    first_name: props.user?.first_name ?? "",
    last_name: props.user?.last_name ?? "",
    middle_name: props.user?.middle_name ?? "",
    email: props.user?.email ?? "",
    company: props.user?.company ?? "",
    position: props.user?.position ?? "",
    city: props.user?.city ?? "",
    phone: props.user?.phone ?? "",
    avatar: props.user?.avatar || null,
    delete_avatar: false,
    password: "",
    password_confirmation: "",
    roles: [],
});

function submitForm() {
    form.transform((data) => {
        const payload = {
            ...data,
            avatar: data.avatar instanceof File ? data.avatar : undefined,
            delete_avatar: data.delete_avatar ? 1 : 0,
        };
        if (isEdit.value) {
            delete payload.password;
            delete payload.password_confirmation;
            delete payload.roles;
            payload._method = "PUT";
        }
        return payload;
    });
    const url = isEdit.value ? route("admin.users.update", props.user.id) : route("admin.users.store");
    form.post(url, {
        forceFormData: true,
        preserveScroll: true,
        onError: () => toast.error("Проверьте поля, отмеченные красным"),
    });
}
</script>
