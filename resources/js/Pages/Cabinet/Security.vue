<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { DevicePhoneMobileIcon, EnvelopeIcon, TrashIcon } from '@heroicons/vue/24/outline';
import ProfileLayout from '@/Layouts/ProfileLayout.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    social: { type: Array, default: () => [] },
    phone: { type: String, default: null },
    phoneVerified: { type: Boolean, default: false },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
// кнопку подтверждения показываем, только когда Яндекс действительно отдаёт номер
const phoneCheck = computed(() => (page.props.phoneProviders ?? []).includes('yandex'));
const providers = computed(() => props.social.filter((item) => item.linked || item.available));

// Подтверждение email: новая ссылка в письме
const emailSent = ref(false);
const sending = ref(false);
const resend = () => {
    sending.value = true;
    router.post(route('email.confirm.resend'), {}, {
        preserveScroll: true,
        onSuccess: () => { emailSent.value = true; },
        onFinish: () => { sending.value = false; },
    });
};

const unlink = (item) => {
    if (confirm(`Отвязать ${item.name}? Войти через него больше не получится.`)) {
        router.delete(route('social.unlink', item.key), { preserveScroll: true });
    }
};

const password = useForm({ current_password: '', password: '', password_confirmation: '' });
const updatePassword = () => {
    password.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => password.reset(),
        onError: () => password.reset('password', 'password_confirmation'),
    });
};

const deleting = ref(false);
const deleteForm = useForm({ password: '' });
const destroy = () => {
    deleteForm.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => { deleting.value = false; },
        onFinish: () => deleteForm.reset(),
    });
};
</script>

<template>
    <Head title="Вход и безопасность" />

    <ProfileLayout>
        <header class="flex flex-col gap-2.5">
            <span class="cab-eyebrow">Личный кабинет</span>
            <h1 class="cab-h1">Вход и безопасность</h1>
        </header>

        <div class="flex flex-wrap items-start gap-7">
            <div class="flex min-w-0 flex-[999_1_560px] flex-col gap-6">
                <section aria-labelledby="s1" class="cab-panel flex flex-col p-6 sm:p-7">
                    <h2 id="s1" class="cab-h2">Подтверждение</h2>
                    <p class="mb-2 mt-1 text-sm text-gray-500 dark:text-gray-400">Подтверждённые email и телефон помогают восстановить доступ к аккаунту.</p>

                    <div class="flex flex-wrap items-center gap-4 border-t border-gray-100 py-4 dark:border-gray-800">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full" :class="user.email_verified ? 'bg-[#e4f1e9] text-[#2f6b4f]' : 'bg-[#fbecd3] text-[#8a5512]'"><EnvelopeIcon class="h-5 w-5" aria-hidden="true" /></span>
                        <div class="min-w-0 flex-[1_1_240px]">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <span class="font-bold">Email</span>
                                <span v-if="user.email_verified" class="cab-chip-ok">Подтверждён</span>
                                <span v-else-if="emailSent" class="cab-chip-blue">Письмо отправлено</span>
                                <span v-else class="cab-chip-warn">Не подтверждён</span>
                            </div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ user.email }}<template v-if="!user.email_verified"> — ссылка действует 7 дней</template></div>
                        </div>
                        <button v-if="!user.email_verified && !emailSent" type="button" class="cab-btn w-full sm:w-auto" :disabled="sending" @click="resend">{{ sending ? 'Отправляем…' : 'Отправить ссылку' }}</button>
                    </div>

                    <div class="flex flex-wrap items-center gap-4 border-t border-gray-100 py-4 dark:border-gray-800">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full" :class="phoneVerified ? 'bg-[#e4f1e9] text-[#2f6b4f]' : phoneCheck ? 'bg-[#fbecd3] text-[#8a5512]' : 'bg-gray-100 text-gray-500 dark:bg-gray-800'"><DevicePhoneMobileIcon class="h-5 w-5" aria-hidden="true" /></span>
                        <div class="min-w-0 flex-[1_1_240px]">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <span class="font-bold">Телефон</span>
                                <span v-if="phoneVerified" class="cab-chip-ok">Подтверждён</span>
                                <span v-else-if="phoneCheck" class="cab-chip-warn">Не подтверждён</span>
                            </div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                {{ phone || 'Номер не указан' }}<template v-if="!phoneVerified && phoneCheck"> — подтвердим номером из Яндекс ID, без СМС. Откроется Яндекс и сразу вернёт сюда</template>
                            </div>
                        </div>
                        <a v-if="!phoneVerified && phoneCheck" :href="route('social.link', 'yandex')" class="cab-btn-ghost w-full sm:w-auto">
                            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-[#fc3f1d] text-xs font-extrabold text-white" aria-hidden="true">Я</span>
                            Подтвердить через Яндекс
                        </a>
                    </div>
                </section>

                <section v-if="providers.length" aria-labelledby="s2" class="cab-panel flex flex-col p-6 sm:p-7">
                    <h2 id="s2" class="cab-h2">Вход через сервисы</h2>
                    <p class="mb-2 mt-1 text-sm text-gray-500 dark:text-gray-400">Входите в один клик, без пароля.</p>
                    <div v-for="item in providers" :key="item.key" class="flex flex-wrap items-center gap-4 border-t border-gray-100 py-4 dark:border-gray-800">
                        <span
                            class="flex h-12 w-12 shrink-0 items-center justify-center text-white"
                            :class="item.key === 'yandex' ? 'rounded-full bg-[#fc3f1d] text-lg font-extrabold' : 'rounded-xl bg-[#0077ff] text-sm font-extrabold'"
                            aria-hidden="true"
                        >{{ item.key === 'yandex' ? 'Я' : 'VK' }}</span>
                        <div class="min-w-0 flex-[1_1_240px]">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <span class="font-bold">{{ item.key === 'yandex' ? 'Яндекс ID' : 'VK ID' }}</span>
                                <span v-if="item.linked" class="cab-chip-ok">Привязан</span>
                            </div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ item.linked ? (item.email || 'Аккаунт привязан') : 'Не привязан' }}</div>
                        </div>
                        <button v-if="item.linked" type="button" class="cab-btn-ghost w-full !text-gray-500 sm:w-auto" @click="unlink(item)">Отвязать</button>
                        <a v-else :href="route('social.link', item.key)" class="cab-btn-ghost w-full sm:w-auto">Привязать</a>
                    </div>
                </section>

                <form aria-labelledby="s3" class="cab-panel flex flex-col gap-[18px] p-6 sm:p-7" @submit.prevent="updatePassword">
                    <div>
                        <h2 id="s3" class="cab-h2">Пароль</h2>
                        <p class="pt-0.5 text-sm text-gray-500 dark:text-gray-400">Не короче 8 символов.</p>
                    </div>
                    <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label for="current_password" class="cab-label">Текущий пароль</label>
                            <input id="current_password" v-model="password.current_password" type="password" class="cab-input" autocomplete="current-password" />
                            <p v-if="password.errors.current_password" class="text-sm text-[#b4442c]">{{ password.errors.current_password }}</p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="password" class="cab-label">Новый пароль</label>
                            <input id="password" v-model="password.password" type="password" class="cab-input" autocomplete="new-password" />
                            <p v-if="password.errors.password" class="text-sm text-[#b4442c]">{{ password.errors.password }}</p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="password_confirmation" class="cab-label">Повторите новый пароль</label>
                            <input id="password_confirmation" v-model="password.password_confirmation" type="password" class="cab-input" autocomplete="new-password" />
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-3">
                        <span v-if="password.recentlySuccessful" class="cab-chip-ok">Пароль изменён</span>
                        <button type="submit" class="cab-btn-ghost w-full sm:w-auto" :disabled="password.processing">Изменить пароль</button>
                    </div>
                </form>

                <section aria-labelledby="s4" class="cab-panel flex flex-wrap items-center gap-[18px] !border-[#f5c2b6] p-6 sm:p-7">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brandcoral-soft text-[#b4442c]"><TrashIcon class="h-5 w-5" aria-hidden="true" /></span>
                    <div class="min-w-0 flex-[1_1_280px]">
                        <h2 id="s4" class="font-display text-xl font-medium">Удалить аккаунт</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Личные данные будут стёрты, войти будет нельзя. Отменить нельзя.</p>
                    </div>
                    <button type="button" class="cab-btn-ghost w-full !border-[#f5c2b6] !text-[#b4442c] sm:w-auto" @click="deleting = true">Удалить аккаунт</button>
                </section>
            </div>

            <aside class="flex min-w-0 flex-[1_1_300px] flex-col gap-5">
                <section v-if="!user.email_verified" class="cab-panel flex flex-col gap-1.5 p-5">
                    <span class="font-display text-xl font-medium">Не пришло письмо?</span>
                    <span class="text-[13px] leading-[19px] text-gray-500 dark:text-gray-400">Проверьте папки «Спам» и «Промоакции». Новую ссылку можно запросить кнопкой «Отправить ссылку».</span>
                    <span class="pt-1.5 text-[13px] leading-[19px] text-gray-500 dark:text-gray-400">Если адрес указан с ошибкой, исправьте его в <a :href="route('profile.edit')" class="font-bold text-brandblue-dark hover:underline">профиле</a>.</span>
                </section>
            </aside>
        </div>

        <Modal :show="deleting" @close="deleting = false">
            <form class="flex flex-col gap-4 p-6" @submit.prevent="destroy">
                <h2 class="font-display text-2xl font-medium">Удалить аккаунт?</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">Личные данные будут стёрты, войти в аккаунт будет нельзя. Чтобы подтвердить, введите пароль.</p>
                <div class="flex flex-col gap-1.5">
                    <label for="delete_password" class="cab-label">Пароль</label>
                    <input id="delete_password" v-model="deleteForm.password" type="password" class="cab-input" autocomplete="current-password" />
                    <p v-if="deleteForm.errors.password" class="text-sm text-[#b4442c]">{{ deleteForm.errors.password }}</p>
                </div>
                <div class="flex flex-wrap justify-end gap-3">
                    <button type="button" class="cab-btn-ghost" @click="deleting = false">Отмена</button>
                    <button type="submit" class="cab-btn !bg-[#b4442c]" :disabled="deleteForm.processing">Удалить навсегда</button>
                </div>
            </form>
        </Modal>
    </ProfileLayout>
</template>
