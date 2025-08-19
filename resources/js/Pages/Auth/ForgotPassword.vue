<script setup>
import MainLayout from '@/Layouts/MainLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { onMounted, watch, ref } from 'vue';
import { useToast } from 'vue-toastification';
import { KeyIcon } from '@heroicons/vue/24/outline';
import axios from 'axios';

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
});

const hasRetried = ref(false);

const toast = useToast();

function showStatusToast(message) {
    if (!message) return;
    if (message.includes('Повторный запрос возможен')) {
        toast.warning(message);
    } else {
        toast.success(message);
    }
}

onMounted(() => {
    showStatusToast(props.status);
});

watch(
    () => props.status,
    (newVal, oldVal) => {
        if (newVal && newVal !== oldVal) {
            showStatusToast(newVal);
        }
    }
);

// Обновление CSRF-токена — как на странице входа
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
    // Перед отправкой гарантированно обновляем токен
    await refreshCsrfToken();
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    form.transform((data) => ({ ...data, _token: csrfToken || undefined }));

    form.post(route('password.email'), {
        onError: async (errors) => {
            // Обработка 419 и одна автоматическая повторная попытка
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

            if (errors?.email) {
                const msg = Array.isArray(errors.email) ? errors.email[0] : errors.email;
                toast.error(msg);
            } else {
                toast.error('Не удалось отправить запрос. Попробуйте позже.');
            }
        },
        onFinish: () => {
            hasRetried.value = false;
        },
        preserveState: true,
        preserveScroll: true,
    });
};
</script>

<template>
    <MainLayout>
        <Head title="Восстановление пароля" />

        <div class="min-h-screen bg-gradient-to-br from-brandblue/[0.03] to-white/95 dark:from-brandblue/10 dark:to-gray-900">
            <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-md">
                    <!-- Шапка в стиле ProfileLayout -->
                    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex flex-col items-center justify-center text-center">
                            <div class="mb-4 h-16 w-16 overflow-hidden rounded-full border-2 border-amber-400/20">
                                <div class="flex h-full w-full items-center justify-center bg-amber-50 dark:bg-amber-900/20">
                                    <KeyIcon class="h-10 w-10 text-amber-500" />
                                </div>
                            </div>
                            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Восстановление пароля</h1>
                            <p class="mt-2 text-gray-600 dark:text-gray-300">Мы сгенерируем новый пароль и отправим его на ваш email</p>
                        </div>
                    </div>

                    <!-- Форма восстановления пароля в стиле ProfileLayout -->
                    <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <div class="mb-6 text-sm text-gray-600 dark:text-gray-400">
                            Укажите ваш email. Если он зарегистрирован, мы сгенерируем новый пароль и отправим его на почту.
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

                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <Link
                                    :href="route('login')"
                                    class="block w-full text-center text-sm text-brandblue hover:text-brandblue/80 dark:text-brandblue/90 dark:hover:text-brandblue sm:w-auto"
                                >
                                    Вернуться к входу
                                </Link>

                                <PrimaryButton
                                    class="w-full justify-center sm:w-auto"
                                    :class="{ 'opacity-25': form.processing }"
                                    :disabled="form.processing"
                                >
                                    Получить новый пароль
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>
</template>
