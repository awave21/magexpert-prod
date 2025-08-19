<script setup>
import MainLayout from '@/Layouts/MainLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import PhoneInput from '@/Components/Form/PhoneInput.vue';
import CityAutocomplete from '@/Components/CityAutocomplete.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useToast } from 'vue-toastification';
import { UserPlusIcon } from '@heroicons/vue/24/outline';

const form = useForm({
    first_name: '',
    last_name: '',
    middle_name: '',
    email: '',
    phone: '',
    specialization: '',
    city: '',
    password: '',
    password_confirmation: '',
});

const toast = useToast();

const submit = () => {
    form.post(route('register'), {
        onError: (errors) => {
            // Берём первое сообщение из валидации
            const first = Object.values(errors)[0];
            if (Array.isArray(first)) {
                toast.error(first[0]);
            } else if (first) {
                toast.error(first);
            } else {
                toast.error('Проверьте правильность заполнения полей.');
            }
        },
        onSuccess: () => {
            toast.success('Регистрация прошла успешно');
        },
        onFinish: () => form.reset('password', 'password_confirmation'),
        preserveState: true,
        preserveScroll: true,
    });
};
</script>

<template>
    <MainLayout>
        <Head title="Регистрация" />

        <div class="min-h-screen bg-gradient-to-br from-brandblue/[0.03] to-white/95 dark:from-brandblue/10 dark:to-gray-900">
            <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-md">
                    <!-- Шапка в стиле ProfileLayout -->
                    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex flex-col items-center justify-center text-center">
                            <div class="mb-4 h-16 w-16 overflow-hidden rounded-full border-2 border-brandcoral/20">
                                <div class="flex h-full w-full items-center justify-center bg-brandcoral/10 dark:bg-brandcoral/20">
                                    <UserPlusIcon class="h-10 w-10 text-brandcoral" />
                                </div>
                            </div>
                            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Регистрация</h1>
                            <p class="mt-2 text-gray-600 dark:text-gray-300">Создайте новый аккаунт</p>
                        </div>
                    </div>

                    <!-- Форма регистрации в стиле ProfileLayout -->
                    <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <form @submit.prevent="submit" class="space-y-6">
                            <div class="grid grid-cols-1 gap-4">
                                <!-- Фамилия -->
                                <div>
                                    <InputLabel for="last_name" value="Фамилия" required />
                                    <TextInput
                                        id="last_name"
                                        type="text"
                                        class="mt-1 block w-full"
                                        v-model="form.last_name"
                                        required
                                        autofocus
                                        autocomplete="family-name"
                                    />
                                    <InputError class="mt-1" :message="form.errors.last_name" />
                                </div>

                                <!-- Имя -->
                                <div>
                                    <InputLabel for="first_name" value="Имя" required />
                                    <TextInput
                                        id="first_name"
                                        type="text"
                                        class="mt-1 block w-full"
                                        v-model="form.first_name"
                                        required
                                        autocomplete="given-name"
                                    />
                                    <InputError class="mt-1" :message="form.errors.first_name" />
                                </div>
                            </div>

                            <!-- Отчество -->
                            <div>
                                <InputLabel for="middle_name" value="Отчество" />
                                <TextInput
                                    id="middle_name"
                                    type="text"
                                    class="mt-1 block w-full"
                                    v-model="form.middle_name"
                                    autocomplete="additional-name"
                                />
                                <InputError class="mt-1" :message="form.errors.middle_name" />
                            </div>

                            <!-- Email -->
                            <div>
                                <InputLabel for="email" value="Email" required />
                                <TextInput
                                    id="email"
                                    type="email"
                                    class="mt-1 block w-full"
                                    v-model="form.email"
                                    required
                                    autocomplete="username"
                                />
                                <InputError class="mt-1" :message="form.errors.email" />
                            </div>

                            <!-- Телефон -->
                            <div>
                                <InputLabel for="phone" value="Телефон" />
                                <PhoneInput
                                    id="phone"
                                    v-model="form.phone"
                                    class="mt-1 block w-full"
                                />
                                <InputError class="mt-1" :message="form.errors.phone" />
                            </div>

                            <div class="grid grid-cols-1 gap-4">
                                <!-- Направление деятельности / специализация -->
                                <div>
                                    <InputLabel for="specialization" value="Направление деятельности / специализация" />
                                    <TextInput
                                        id="specialization"
                                        type="text"
                                        class="mt-1 block w-full"
                                        v-model="form.specialization"
                                        autocomplete="organization"
                                    />
                                    <InputError class="mt-1" :message="form.errors.specialization" />
                                </div>

                                
                            </div>

                            <!-- Город -->
                            <div>
                                <CityAutocomplete
                                    id="city"
                                    label="Город"
                                    v-model="form.city"
                                    :error="form.errors.city"
                                    placeholder="Введите город"
                                />
                            </div>

                            <!-- Пароль -->
                            <div>
                                <InputLabel for="password" value="Пароль" required />
                                <TextInput
                                    id="password"
                                    type="password"
                                    class="mt-1 block w-full"
                                    v-model="form.password"
                                    required
                                    autocomplete="new-password"
                                />
                                <InputError class="mt-1" :message="form.errors.password" />
                            </div>

                            <!-- Подтверждение пароля -->
                            <div>
                                <InputLabel
                                    for="password_confirmation"
                                    value="Подтверждение пароля"
                                    required
                                />
                                <TextInput
                                    id="password_confirmation"
                                    type="password"
                                    class="mt-1 block w-full"
                                    v-model="form.password_confirmation"
                                    required
                                    autocomplete="new-password"
                                />
                                <InputError
                                    class="mt-1"
                                    :message="form.errors.password_confirmation"
                                />
                            </div>

                            <div class="flex items-center justify-between pt-4">
                                <Link
                                    :href="route('login')"
                                    class="text-sm text-brandblue hover:text-brandblue/80 dark:text-brandblue/90 dark:hover:text-brandblue transition-colors duration-200"
                                >
                                    Уже зарегистрированы?
                                </Link>

                                <PrimaryButton
                                    :class="{ 'opacity-50 cursor-not-allowed': form.processing }"
                                    :disabled="form.processing"
                                >
                                    Зарегистрироваться
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>
</template>

