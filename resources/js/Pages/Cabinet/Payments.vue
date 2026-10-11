<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import ProfileLayout from '@/Layouts/ProfileLayout.vue';

const props = defineProps({
    payments: { type: Array, default: () => [] },
});

const statuses = {
    completed: { text: 'Оплачено', cls: 'cab-chip-ok' },
    pending: { text: 'Ожидает оплаты', cls: 'cab-chip-warn' },
    processing: { text: 'Обрабатывается', cls: 'cab-chip-blue' },
    failed: { text: 'Не прошёл', cls: 'cab-chip-warn' },
    cancelled: { text: 'Отменён', cls: 'cab-chip-blue !bg-gray-100 !text-gray-600' },
    refunded: { text: 'Возврат', cls: 'cab-chip-blue' },
};
const status = (payment) => statuses[payment.status] ?? { text: payment.status, cls: 'cab-chip-blue' };

const formatDate = (date) => (date ? new Date(`${date}T00:00:00`).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }) : '');
const formatAmount = (payment) => `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(payment.amount)} ${payment.currency === 'RUB' ? '₽' : payment.currency}`;
const formats = { online: 'онлайн', offline: 'офлайн', hybrid: 'гибрид' };

const pending = computed(() => props.payments.find((payment) => payment.status === 'pending' && payment.slug));
</script>

<template>
    <Head title="Платежи" />

    <ProfileLayout>
        <header class="flex flex-col gap-2.5">
            <span class="cab-eyebrow">Личный кабинет</span>
            <h1 class="cab-h1">Платежи</h1>
            <p class="max-w-[640px] text-base text-gray-500 dark:text-gray-400">Оплаченные мероприятия. Чек приходит на почту сразу после оплаты.</p>
        </header>

        <div class="flex flex-wrap items-start gap-7">
            <div class="flex min-w-0 flex-[999_1_560px] flex-col gap-4">
                <template v-if="payments.length">
                    <!-- Таблица на широком экране -->
                    <section class="cab-panel hidden overflow-x-auto px-4 pb-2 pt-1 md:block" aria-label="История платежей">
                        <table class="w-full border-collapse text-[15px]">
                            <thead>
                                <tr class="text-left text-xs font-extrabold uppercase tracking-[.12em] text-gray-500">
                                    <th class="px-3 pb-3 pt-4 font-extrabold">Дата</th>
                                    <th class="px-3 pb-3 pt-4 font-extrabold">Мероприятие</th>
                                    <th class="px-3 pb-3 pt-4 text-right font-extrabold">Сумма</th>
                                    <th class="px-3 pb-3 pt-4 font-extrabold">Статус</th>
                                    <th class="px-3 pb-3 pt-4"><span class="sr-only">Действие</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="payment in payments" :key="payment.id" class="border-t border-gray-100 dark:border-gray-800">
                                    <td class="whitespace-nowrap px-3 py-4 text-gray-500">{{ formatDate(payment.date) }}</td>
                                    <td class="px-3 py-4">
                                        <div class="font-bold">{{ payment.title }}</div>
                                        <div v-if="payment.format" class="text-[13px] text-gray-500">{{ formats[payment.format] }}</div>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-right font-bold tabular-nums">{{ formatAmount(payment) }}</td>
                                    <td class="px-3 py-4"><span :class="status(payment).cls">{{ status(payment).text }}</span></td>
                                    <td class="px-3 py-4 text-right">
                                        <Link v-if="payment.status === 'pending' && payment.slug" :href="route('events.show', payment.slug)" class="cab-btn !min-h-10 !px-4 !text-[13px]">Оплатить</Link>
                                        <Link v-else-if="payment.status === 'completed' && payment.slug" :href="route('my-events.view', payment.slug)" class="text-sm font-bold text-brandblue-dark hover:underline">Смотреть</Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </section>

                    <!-- Карточки на телефоне -->
                    <article v-for="payment in payments" :key="`m${payment.id}`" class="cab-panel flex flex-col gap-2.5 !rounded-[20px] p-4 md:hidden">
                        <div class="flex justify-between gap-2.5"><span class="text-xs font-semibold text-gray-500">{{ formatDate(payment.date) }}</span><span :class="status(payment).cls">{{ status(payment).text }}</span></div>
                        <div class="text-[15px] font-bold leading-5">{{ payment.title }}</div>
                        <div class="flex items-center justify-between gap-2.5 border-t border-gray-100 pt-2.5 dark:border-gray-800">
                            <span class="text-[17px] font-extrabold tabular-nums">{{ formatAmount(payment) }}</span>
                            <Link v-if="payment.status === 'pending' && payment.slug" :href="route('events.show', payment.slug)" class="cab-btn !min-h-11">Оплатить</Link>
                            <Link v-else-if="payment.status === 'completed' && payment.slug" :href="route('my-events.view', payment.slug)" class="inline-flex min-h-11 items-center text-sm font-bold text-brandblue-dark">Смотреть</Link>
                        </div>
                    </article>
                </template>

                <div v-else class="cab-panel flex flex-col items-center gap-3 p-9 text-center">
                    <span class="font-display text-[22px] font-medium">Платежей пока нет</span>
                    <span class="text-sm text-gray-500">Здесь появятся оплаченные мероприятия. Бесплатные — в разделе «Мероприятия».</span>
                    <Link :href="route('events.index')" class="cab-btn">Открыть каталог</Link>
                </div>
            </div>

            <aside class="flex min-w-0 flex-[1_1_300px] flex-col gap-5">
                <section v-if="pending" class="cab-panel flex flex-col gap-1.5 !border-[#fcd9a0] p-5">
                    <span class="font-display text-xl font-medium">Ожидает оплаты</span>
                    <span class="text-[13px] leading-[19px] text-gray-500 dark:text-gray-400">{{ pending.title }}. Доступ к мероприятию откроется после оплаты.</span>
                    <Link :href="route('events.show', pending.slug)" class="cab-btn mt-2 self-start">Оплатить {{ formatAmount(pending) }}</Link>
                </section>
                <section class="cab-panel flex flex-col gap-1.5 p-5">
                    <span class="text-[15px] font-bold">Счёт для организации</span>
                    <span class="text-[13px] leading-[19px] text-gray-500 dark:text-gray-400">Если оплачивает клиника, напишите нам — подскажем, как оформить оплату.</span>
                    <a href="mailto:info@mag-expert.ru" class="pt-1 text-sm font-bold text-brandblue-dark">info@mag-expert.ru</a>
                </section>
            </aside>
        </div>
    </ProfileLayout>
</template>
