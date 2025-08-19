<script setup>
import MainLayout from '@/Layouts/MainLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LockOpenIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    email: {
        type: String,
        required: true,
    },
    token: {
        type: String,
        required: true,
    },
});

const form = useForm({
    token: props.token,
    email: props.email,
});

const submit = () => {
    form.post(route('password.store'));
};
</script>

<template>
    <MainLayout>
        <Head title="Сброс пароля" />

        <div class="min-h-screen bg-gradient-to-br from-brandblue/[0.03] to-white/95 dark:from-brandblue/10 dark:to-gray-900">
            <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-md">
                    <!-- Шапка в стиле ProfileLayout -->
                    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex flex-col items-center justify-center text-center">
                            <div class="mb-4 h-16 w-16 overflow-hidden rounded-full border-2 border-green-400/20">
                                <div class="flex h-full w-full items-center justify-center bg-green-50 dark:bg-green-900/20">
                                    <LockOpenIcon class="h-10 w-10 text-green-500" />
                                </div>
                            </div>
                            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Сброс пароля</h1>
                            <p class="mt-2 text-gray-600 dark:text-gray-300">Мы сгенерируем новый пароль и отправим его на ваш email</p>
                        </div>
                    </div>

                    <!-- Форма сброса пароля в стиле ProfileLayout -->
                    <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
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

                            <div class="flex items-center justify-end">
                                <PrimaryButton
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
