<script setup>
import CheckboxInput from '@/Components/Form/CheckboxInput.vue';
import MainLayout from '@/Layouts/MainLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useToast } from 'vue-toastification';
import { ref } from 'vue';
import axios from 'axios';
import { UserIcon } from '@heroicons/vue/24/outline';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const submitting = ref(false);
const hasRetried = ref(false);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const toast = useToast();

const refreshCsrfToken = async () => {
    try {
        const response = await axios.get('/csrf-token');
        const token = response?.data?.csrf_token;
        if (token) {
            document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', token);
            return true;
        }
    } catch (e) {
        console.error('Ошибка при обновлении CSRF-токена:', e);
    }
    return false;
};

const submit = async () => {
    submitting.value = true;
    // Перед каждой отправкой — гарантированно обновляем токен
    await refreshCsrfToken();
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    form.transform((data) => ({ ...data, _token: csrfToken || undefined }));

    form.post(route('login'), {
        onError: async (errors) => {
            // Если это 419, пытаемся освежить токен и повторить попытку
            const maybeStatus = errors?.response?.status || errors?.status;
            if (maybeStatus === 419 && !hasRetried.value) {
                hasRetried.value = true;
                const refreshed = await refreshCsrfToken();
                if (refreshed) {
                    toast.info('Сессия обновлена, пробуем снова...');
                    submit();
                    return;
                }
                toast.error('Ошибка сессии. Пожалуйста, обновите страницу.');
                return;
            }

            // Показываем первую понятную ошибку
            const first = errors?.password || errors?.email;
            if (Array.isArray(first)) {
                toast.error(first[0]);
            } else if (first) {
                toast.error(first);
            } else {
                toast.error('Не удалось войти. Проверьте введённые данные.');
            }
        },
        onSuccess: () => {
            toast.success('Вход выполнен успешно');
        },
        onFinish: () => {
            form.reset('password');
            submitting.value = false;
            hasRetried.value = false;
        },
        preserveState: true,
        preserveScroll: true,
    });
};
</script>

<template>
    <MainLayout>
        <Head title="Вход" />

        <div class="min-h-screen bg-gradient-to-br from-brandblue/[0.03] to-white/95 dark:from-brandblue/10 dark:to-gray-900">
            <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-md">
                    <!-- Шапка профиля в стиле ProfileLayout -->
                    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex flex-col items-center justify-center text-center">
                            <div class="mb-4 h-16 w-16 overflow-hidden rounded-full border-2 border-brandblue/20">
                                <div class="flex h-full w-full items-center justify-center bg-gray-100 dark:bg-gray-700">
                                    <UserIcon class="h-10 w-10 text-gray-500 dark:text-gray-400" />
                                </div>
                            </div>
                            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Вход в систему</h1>
                            <p class="mt-2 text-gray-600 dark:text-gray-300">Войдите в свой аккаунт</p>
                        </div>
                    </div>

                    <!-- Форма входа в стиле ProfileLayout -->
                    <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <div v-if="status" class="mb-4 rounded-lg bg-green-50 p-4 text-sm font-medium text-green-600 dark:bg-green-900/20">
                            {{ status }}
                        </div>

                        <form @submit.prevent="submit" class="space-y-6">
                            <div>
                                <InputLabel for="email" value="Email" />

                                <TextInput
                                    id="email"
                                    type="email"
                                    class="mt-1 block w-full"
                                    v-model="form.email"
                                    required
                                    autofocus
                                    autocomplete="username"
                                />

                                <InputError class="mt-2" :message="form.errors.email" />
                            </div>

                            <div>
                                <InputLabel for="password" value="Пароль" />

                                <TextInput
                                    id="password"
                                    type="password"
                                    class="mt-1 block w-full"
                                    v-model="form.password"
                                    required
                                    autocomplete="current-password"
                                />

                                <InputError class="mt-2" :message="form.errors.password" />
                            </div>

                            <div class="block">
                                <CheckboxInput id="remember" label="Запомнить меня" v-model="form.remember" />
                            </div>

                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <Link
                                    v-if="canResetPassword"
                                    :href="route('password.request')"
                                    class="text-sm text-brandblue hover:text-brandblue/80 dark:text-brandblue/90 dark:hover:text-brandblue"
                                >
                                    Забыли пароль?
                                </Link>

                                <PrimaryButton
                                    class="w-full justify-center sm:w-auto"
                                    :class="{ 'opacity-25': form.processing }"
                                    :disabled="form.processing"
                                >
                                    Войти
                                </PrimaryButton>
                            </div>
                            
                            <div class="text-center text-sm text-gray-600 dark:text-gray-400">
                                Нет аккаунта? 
                                <Link :href="route('register')" class="text-brandblue hover:underline dark:text-brandblue/90">
                                    Зарегистрироваться
                                </Link>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>
</template>
