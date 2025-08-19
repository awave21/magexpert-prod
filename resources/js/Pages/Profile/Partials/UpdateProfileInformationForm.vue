<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import axios from 'axios';
import TextInput from '@/Components/Form/TextInput.vue';
import PhoneInput from '@/Components/Form/PhoneInput.vue';
import TextareaInput from '@/Components/Form/TextareaInput.vue';
import ImageUpload from '@/Components/Form/ImageUpload.vue';
import CityAutocomplete from '@/Components/CityAutocomplete.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const page = usePage();
const user = page.props.auth.user;

const deleteAvatar = ref(false);

const form = useForm({
    first_name: user.first_name || '',
    last_name: user.last_name || '',
    middle_name: user.middle_name || '',
    email: user.email || '',
    phone: user.phone || '',
    specialization: user.specialization || '',
    city: user.city || '',
    avatar: null,
    delete_avatar: false,
});

// Устанавливаем аватар после инициализации формы
onMounted(() => {
    if (user.avatar) {
        form.avatar = user.avatar;
    }
});

const updateProfile = () => {
    // Создаем FormData для правильной отправки файлов
    const formData = new FormData();
    
    // Добавляем все поля формы в FormData
    formData.append('first_name', form.first_name);
    formData.append('last_name', form.last_name);
    formData.append('email', form.email);
    
    if (form.middle_name) formData.append('middle_name', form.middle_name);
    if (form.phone) formData.append('phone', form.phone);
    if (form.specialization) formData.append('specialization', form.specialization);
    if (form.city) formData.append('city', form.city);
    
    // Добавляем флаг удаления аватара
    formData.append('delete_avatar', deleteAvatar.value ? '1' : '0');
    
    // Добавляем аватар, только если это файл
    if (form.avatar instanceof File) {
        formData.append('avatar', form.avatar);
    }
    
    // Добавляем _method для эмуляции PATCH запроса
    formData.append('_method', 'PATCH');
    
    // Отправляем запрос с помощью axios напрямую
    axios.post(route('profile.update'), formData, {
        headers: {
            'Content-Type': 'multipart/form-data',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        },
    })
    .then(response => {
        deleteAvatar.value = false;
        form.clearErrors();
        form.recentlySuccessful = true;
        
        // Сбрасываем флаг успешного обновления через 2 секунды
        setTimeout(() => {
            form.recentlySuccessful = false;
        }, 2000);
    })
    .catch(error => {
        // Обрабатываем ошибки валидации
        if (error.response && error.response.status === 422) {
            const errors = error.response.data.errors;
            
            // Устанавливаем ошибки в форму
            form.clearErrors();
            for (const [key, value] of Object.entries(errors)) {
                form.setError(key, value[0]);
            }
        }
    });
};
</script>

<template>
    <section>
        <form @submit.prevent="updateProfile" class="space-y-6">
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <!-- Фото профиля -->
                <div class="md:col-span-2">
                    <ImageUpload
                        label="Фото профиля"
                        v-model="form.avatar"
                        v-model:deletePhoto="deleteAvatar"
                        :error="form.errors.avatar"
                        hint="Рекомендуемый размер: 300x300px. Допустимые форматы: jpg, png, gif, webp."
                    />
                </div>

                <!-- Имя -->
                <TextInput
                    id="first_name"
                    label="Имя"
                    v-model="form.first_name"
                    :error="form.errors.first_name"
                    required
                    placeholder="Введите имя"
                />

                <!-- Фамилия -->
                <TextInput
                    id="last_name"
                    label="Фамилия"
                    v-model="form.last_name"
                    :error="form.errors.last_name"
                    required
                    placeholder="Введите фамилию"
                />

                <!-- Отчество -->
                <TextInput
                    id="middle_name"
                    label="Отчество"
                    v-model="form.middle_name"
                    :error="form.errors.middle_name"
                    placeholder="Введите отчество (если есть)"
                />

                <!-- Email -->
                <TextInput
                    id="email"
                    label="Email"
                    type="email"
                    v-model="form.email"
                    :error="form.errors.email"
                    required
                    placeholder="Введите email"
                />

                <!-- Телефон -->
                <PhoneInput
                    id="phone"
                    label="Телефон"
                    v-model="form.phone"
                    :error="form.errors.phone"
                    placeholder="(999) 123-45-67"
                />

                <!-- Город -->
                <CityAutocomplete
                    id="city"
                    label="Город"
                    v-model="form.city"
                    :error="form.errors.city"
                    placeholder="Начните вводить название города"
                />

                <!-- Направление деятельности / специализация -->
                <TextInput
                    id="specialization"
                    label="Направление деятельности / специализация"
                    v-model="form.specialization"
                    :error="form.errors.specialization"
                    placeholder="Направление деятельности"
                />
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null" class="rounded-lg bg-amber-50 p-4 dark:bg-amber-900/20">
                <p class="text-sm text-amber-700 dark:text-amber-400">
                    Ваш email не подтвержден.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="font-medium text-amber-700 underline hover:text-amber-800 dark:text-amber-400 dark:hover:text-amber-300"
                    >
                        Нажмите здесь, чтобы отправить письмо подтверждения снова.
                    </Link>
                </p>

                <div
                    v-show="status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600 dark:text-green-400"
                >
                    Новая ссылка для подтверждения была отправлена на ваш email.
                </div>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <PrimaryButton :disabled="form.processing">Сохранить</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="rounded-md bg-green-50 px-3 py-1 text-sm text-green-600 dark:bg-green-900/20 dark:text-green-400"
                    >
                        Сохранено.
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
