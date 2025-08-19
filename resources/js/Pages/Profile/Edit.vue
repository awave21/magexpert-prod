<script setup>
import ProfileLayout from '@/Layouts/ProfileLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';
import { UserCircleIcon, KeyIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <Head title="Профиль" />

    <ProfileLayout>
        <div class="p-6">
            <h1 class="mb-6 text-2xl font-bold text-gray-800 dark:text-white">Управление профилем</h1>
            
            <div class="space-y-8">
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-6 flex items-center">
                        <div class="mr-4 flex h-12 w-12 items-center justify-center rounded-full bg-brandblue/10 dark:bg-brandblue/20">
                            <UserCircleIcon class="h-7 w-7 text-brandblue" />
                        </div>
                        <div>
                            <h2 class="text-xl font-semibold text-gray-800 dark:text-white">Информация профиля</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Обновите информацию вашего профиля и email адрес</p>
                        </div>
                    </div>
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-6 flex items-center">
                        <div class="mr-4 flex h-12 w-12 items-center justify-center rounded-full bg-amber-50 dark:bg-amber-900/20">
                            <KeyIcon class="h-7 w-7 text-amber-500" />
                        </div>
                        <div>
                            <h2 class="text-xl font-semibold text-gray-800 dark:text-white">Изменение пароля</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Убедитесь, что ваш аккаунт использует надежный пароль</p>
                        </div>
                    </div>
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-6 flex items-center">
                        <div class="mr-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-50 dark:bg-red-900/20">
                            <ExclamationTriangleIcon class="h-7 w-7 text-red-500" />
                        </div>
                        <div>
                            <h2 class="text-xl font-semibold text-gray-800 dark:text-white">Удаление аккаунта</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Навсегда удалите свой аккаунт</p>
                        </div>
                    </div>
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </ProfileLayout>
</template>
