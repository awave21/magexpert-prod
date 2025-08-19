<script setup>
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline';

const confirmingUserDeletion = ref(false);
const passwordInput = ref(null);

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;

    nextTick(() => passwordInput.value.focus());
};

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;

    form.clearErrors();
    form.reset();
};
</script>

<template>
    <section>
        <div class="flex items-center">
            <DangerButton @click="confirmUserDeletion" class="inline-flex items-center whitespace-nowrap px-5 py-2.5">
                <ExclamationTriangleIcon class="mr-2 h-5 w-5" />
                <span>Удалить аккаунт</span>
            </DangerButton>
            <p class="ml-4 text-sm text-gray-500 dark:text-gray-400">
                Это действие необратимо. Все ваши данные будут удалены.
            </p>
        </div>

        <Modal :show="confirmingUserDeletion" @close="closeModal">
            <div class="p-6">
                <div class="flex items-center">
                    <div class="mr-4 flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/20">
                        <ExclamationTriangleIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                            Вы уверены, что хотите удалить свой аккаунт?
                        </h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            После удаления вашей учетной записи все её ресурсы и данные будут безвозвратно удалены.
                        </p>
                    </div>
                </div>

                <div class="mt-6">
                    <TextInput
                        id="password"
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        label="Подтвердите пароль"
                        :error="form.errors.password"
                        placeholder="Введите пароль для подтверждения"
                        @keyup.enter="deleteUser"
                    />
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closeModal">
                        Отмена
                    </SecondaryButton>

                    <DangerButton
                        class="ms-3"
                        :class="{ 'opacity-25': form.processing }"
                        :disabled="form.processing"
                        @click="deleteUser"
                    >
                        Удалить аккаунт
                    </DangerButton>
                </div>
            </div>
        </Modal>
    </section>
</template>
