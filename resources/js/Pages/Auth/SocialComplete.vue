<script setup>
import MainLayout from '@/Layouts/MainLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { UserPlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    provider: { type: String, required: true },
    firstName: { type: String, default: '' },
    lastName: { type: String, default: '' },
    email: { type: String, default: '' },
});

const form = useForm({
    first_name: props.firstName,
    last_name: props.lastName,
    email: props.email,
    privacy_consent: false,
    oferta_consent: false,
    newsletter_consent: false,
});

const submit = () => form.post(route('social.store'));
</script>

<template>
    <MainLayout>
        <Head title="Завершение регистрации" />

        <div class="min-h-screen bg-gradient-to-br from-brandblue/[0.03] to-white/95 dark:from-brandblue/10 dark:to-gray-900">
            <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-md">
                    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-6 text-center dark:border-gray-700 dark:bg-gray-800">
                        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-brandblue/10">
                            <UserPlusIcon class="h-9 w-9 text-brandblue" />
                        </div>
                        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Почти готово</h1>
                        <p class="mt-2 text-gray-600 dark:text-gray-300">
                            Вы вошли через {{ provider }}. Проверьте данные и подтвердите согласия, чтобы создать аккаунт.
                        </p>
                    </div>

                    <form @submit.prevent="submit" class="space-y-5 rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <TextInput id="first_name" label="Имя" v-model="form.first_name" :error="form.errors.first_name" required />
                        <TextInput id="last_name" label="Фамилия" v-model="form.last_name" :error="form.errors.last_name" required />

                        <div v-if="email">
                            <span class="block text-sm font-medium text-zinc-900 dark:text-white">Email</span>
                            <p class="mt-1 rounded-lg bg-gray-50 px-4 py-2 text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ email }}</p>
                            <InputError class="mt-1" :message="form.errors.email" />
                        </div>
                        <TextInput v-else id="email" type="email" label="Email" v-model="form.email" :error="form.errors.email"
                            placeholder="name@example.ru" required />
                        <p v-if="!email" class="-mt-3 text-xs text-gray-500">{{ provider }} не передал email. Укажите адрес, на который будут приходить письма о мероприятиях.</p>

                        <div class="space-y-3 border-t border-gray-100 pt-4 dark:border-gray-700">
                            <label class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300">
                                <input v-model="form.privacy_consent" type="checkbox" class="mt-0.5 size-4 rounded border-zinc-300 text-brandblue focus:ring-brandblue/20" />
                                <span>Даю <a href="/storage/politics/soglasie-na-obrabotku-personalnyh-dannyh-medalyans-expert.pdf" target="_blank" class="text-brandblue hover:underline">согласие на обработку персональных данных</a></span>
                            </label>
                            <InputError :message="form.errors.privacy_consent" />
                            <label class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300">
                                <input v-model="form.oferta_consent" type="checkbox" class="mt-0.5 size-4 rounded border-zinc-300 text-brandblue focus:ring-brandblue/20" />
                                <span>Принимаю условия <a href="/storage/politics/publichnaya-oferta-dlya-medalyans-expert.pdf" target="_blank" class="text-brandblue hover:underline">публичной оферты</a></span>
                            </label>
                            <InputError :message="form.errors.oferta_consent" />
                            <label class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300">
                                <input v-model="form.newsletter_consent" type="checkbox" class="mt-0.5 size-4 rounded border-zinc-300 text-brandblue focus:ring-brandblue/20" />
                                <span>Хочу получать новости о мероприятиях</span>
                            </label>
                        </div>

                        <p class="rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
                            Уже регистрировались на сайте с другим email?
                            <Link :href="route('login')" class="font-medium text-brandblue hover:underline">Войдите по паролю</Link>
                            — {{ provider }} привяжется к вашему аккаунту, и второй аккаунт не появится.
                        </p>

                        <div class="flex items-center justify-between pt-2">
                            <Link :href="route('login')" class="text-sm text-brandblue hover:underline">Отмена</Link>
                            <PrimaryButton :disabled="form.processing || !form.privacy_consent || !form.oferta_consent" :class="{ 'opacity-50': form.processing }">
                                Создать аккаунт
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </MainLayout>
</template>
