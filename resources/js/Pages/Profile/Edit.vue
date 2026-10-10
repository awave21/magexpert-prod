<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowUpTrayIcon, CheckIcon, ExclamationCircleIcon } from '@heroicons/vue/24/outline';
import ProfileLayout from '@/Layouts/ProfileLayout.vue';
import PhoneInput from '@/Components/Form/PhoneInput.vue';
import CityAutocomplete from '@/Components/CityAutocomplete.vue';

defineProps({
    phoneVerified: { type: Boolean, default: false },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

const form = useForm({
    _method: 'patch',
    first_name: user.value.first_name || '',
    last_name: user.value.last_name || '',
    middle_name: user.value.middle_name || '',
    email: user.value.email || '',
    phone: user.value.phone || '',
    specialization: user.value.specialization || '',
    city: user.value.city || '',
    avatar: null,
    delete_avatar: false,
});

// Фото: показываем выбранный файл сразу, а сохраняем вместе с формой
const fileInput = ref(null);
const preview = ref(user.value.avatar || null);
const pickPhoto = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    form.avatar = file;
    form.delete_avatar = false;
    preview.value = URL.createObjectURL(file);
};
const removePhoto = () => {
    form.avatar = null;
    form.delete_avatar = true;
    preview.value = null;
};

const save = () => {
    form.post(route('profile.update'), { forceFormData: true, preserveScroll: true });
};

const initials = computed(() => `${user.value.first_name?.[0] ?? ''}${user.value.last_name?.[0] ?? ''}`);
const steps = computed(() => [
    { label: 'Имя и специализация', done: !!(user.value.specialization && user.value.city), href: null },
    { label: 'Согласия приняты', done: true, href: null },
    { label: 'Подтвердить email', done: !!user.value.email_verified, href: route('cabinet.security') },
    { label: 'Подтвердить телефон', done: !!user.value.phone_verified, href: route('cabinet.security') },
]);
const doneCount = computed(() => steps.value.filter((s) => s.done).length);
</script>

<template>
    <Head title="Профиль" />

    <ProfileLayout>
        <header class="flex flex-col gap-2.5">
            <span class="cab-eyebrow">Личный кабинет</span>
            <h1 class="cab-h1">Профиль</h1>
            <p class="max-w-[640px] text-base text-gray-500 dark:text-gray-400">По специализации и городу мы подбираем для вас мероприятия.</p>
        </header>

        <div class="flex flex-wrap items-start gap-7">
            <form class="flex min-w-0 flex-[999_1_560px] flex-col gap-6" @submit.prevent="save">
                <section class="cab-panel flex flex-wrap items-center gap-5 p-6 sm:p-7">
                    <div class="flex h-[124px] w-[104px] shrink-0 items-end justify-center overflow-hidden rounded-[999px_999px_18px_18px] bg-gradient-to-br from-[#f3f7fb] via-[#d5e1ef] to-[#9fb7d6] pb-5 font-display text-4xl font-medium text-brandblue-dark">
                        <img v-if="preview" :src="preview" alt="" class="h-full w-full object-cover" />
                        <template v-else>{{ initials }}</template>
                    </div>
                    <div class="flex min-w-0 flex-[1_1_240px] flex-col gap-1.5">
                        <span class="font-display text-[26px] font-medium leading-8">{{ [user.last_name, user.first_name, user.middle_name].filter(Boolean).join(' ') }}</span>
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ [user.specialization, user.city].filter(Boolean).join(' · ') || 'Специализация и город не указаны' }}</span>
                        <span class="text-[13px] text-gray-400">JPG, PNG или WEBP. Лицо крупно, светлый фон.</span>
                        <p v-if="form.errors.avatar" class="text-sm text-[#b4442c]">{{ form.errors.avatar }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2.5">
                        <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/gif,image/webp" class="sr-only" id="avatar" @change="pickPhoto" />
                        <button type="button" class="cab-btn-ghost" @click="fileInput.click()">
                            <ArrowUpTrayIcon class="h-[18px] w-[18px]" aria-hidden="true" />
                            {{ preview ? 'Заменить фото' : 'Загрузить фото' }}
                        </button>
                        <button v-if="preview" type="button" class="cab-btn-ghost !text-gray-500" @click="removePhoto">Удалить</button>
                    </div>
                </section>

                <section aria-labelledby="p-personal" class="cab-panel flex flex-col gap-[18px] p-6 sm:p-7">
                    <h2 id="p-personal" class="cab-h2">Личные данные</h2>
                    <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label for="last_name" class="cab-label">Фамилия</label>
                            <input id="last_name" v-model="form.last_name" class="cab-input" required autocomplete="family-name" />
                            <p v-if="form.errors.last_name" class="text-sm text-[#b4442c]">{{ form.errors.last_name }}</p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="first_name" class="cab-label">Имя</label>
                            <input id="first_name" v-model="form.first_name" class="cab-input" required autocomplete="given-name" />
                            <p v-if="form.errors.first_name" class="text-sm text-[#b4442c]">{{ form.errors.first_name }}</p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="middle_name" class="cab-label">Отчество</label>
                            <input id="middle_name" v-model="form.middle_name" class="cab-input" autocomplete="additional-name" />
                        </div>
                    </div>
                </section>

                <section aria-labelledby="p-work" class="cab-panel flex flex-col gap-[18px] p-6 sm:p-7">
                    <h2 id="p-work" class="cab-h2">Работа</h2>
                    <div class="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label for="specialization" class="cab-label">Специализация</label>
                            <input id="specialization" v-model="form.specialization" class="cab-input" placeholder="Акушер-гинеколог" />
                            <p v-if="form.errors.specialization" class="text-sm text-[#b4442c]">{{ form.errors.specialization }}</p>
                        </div>
                        <CityAutocomplete id="city" label="Город" v-model="form.city" :error="form.errors.city" placeholder="Начните вводить город" />
                    </div>
                </section>

                <section aria-labelledby="p-contacts" class="cab-panel flex flex-col gap-[18px] p-6 sm:p-7">
                    <h2 id="p-contacts" class="cab-h2">Контакты</h2>
                    <div class="grid grid-cols-[repeat(auto-fit,minmax(260px,1fr))] gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label for="email" class="cab-label">Email</label>
                            <input id="email" v-model="form.email" type="email" class="cab-input" required autocomplete="email" />
                            <p v-if="form.errors.email" class="text-sm text-[#b4442c]">{{ form.errors.email }}</p>
                            <span class="flex flex-wrap items-center gap-2 pt-0.5">
                                <span :class="user.email_verified ? 'cab-chip-ok' : 'cab-chip-warn'">{{ user.email_verified ? 'Подтверждён' : 'Не подтверждён' }}</span>
                                <Link v-if="!user.email_verified" :href="route('cabinet.security')" class="text-[13px] font-bold text-brandblue-dark hover:underline">Подтвердить</Link>
                            </span>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <PhoneInput id="phone" label="Телефон" v-model="form.phone" :error="form.errors.phone" placeholder="(999) 123-45-67" />
                            <span class="flex flex-wrap items-center gap-2 pt-0.5">
                                <span :class="phoneVerified ? 'cab-chip-ok' : 'cab-chip-warn'">{{ phoneVerified ? 'Подтверждён' : 'Не подтверждён' }}</span>
                                <Link v-if="!phoneVerified" :href="route('cabinet.security')" class="text-[13px] font-bold text-brandblue-dark hover:underline">Подтвердить</Link>
                            </span>
                        </div>
                    </div>
                    <span class="text-[13px] text-gray-500">После смены email или телефона их нужно подтвердить заново.</span>
                </section>

                <div class="flex flex-wrap items-center justify-end gap-3.5">
                    <span v-if="form.recentlySuccessful" class="cab-chip-ok">Изменения сохранены</span>
                    <button type="submit" class="cab-btn w-full sm:w-auto" :disabled="form.processing">{{ form.processing ? 'Сохраняем…' : 'Сохранить изменения' }}</button>
                </div>
            </form>

            <aside class="flex min-w-0 flex-[1_1_300px] flex-col gap-5">
                <section aria-labelledby="pr" class="cab-panel flex flex-col p-5">
                    <div class="flex items-baseline justify-between">
                        <h2 id="pr" class="font-display text-xl font-medium">Профиль заполнен</h2>
                        <span class="text-sm font-extrabold text-brandblue-dark">{{ doneCount }} из 4</span>
                    </div>
                    <div class="my-2.5 h-[5px] overflow-hidden rounded-full bg-brandblue-soft"><div class="h-full rounded-full bg-brandblue" :style="{ width: `${doneCount * 25}%` }"></div></div>
                    <template v-for="step in steps" :key="step.label">
                        <component
                            :is="step.done || !step.href ? 'div' : Link"
                            :href="step.done ? undefined : step.href"
                            class="flex items-center gap-3 border-t border-gray-100 py-2.5 text-gray-900 dark:border-gray-800 dark:text-white"
                        >
                            <span :class="step.done ? 'cab-chip-ok' : 'cab-chip-warn'" class="!px-1.5">
                                <CheckIcon v-if="step.done" class="h-3.5 w-3.5" aria-hidden="true" />
                                <ExclamationCircleIcon v-else class="h-3.5 w-3.5" aria-hidden="true" />
                            </span>
                            <span class="flex-1 text-sm font-semibold">{{ step.label }}</span>
                            <span v-if="!step.done && step.href" aria-hidden="true">→</span>
                        </component>
                    </template>
                </section>
            </aside>
        </div>
    </ProfileLayout>
</template>
