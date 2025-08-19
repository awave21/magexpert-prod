<script setup>
import { Head } from '@inertiajs/vue3';
import ProfileLayout from '@/Layouts/ProfileLayout.vue';
import { DocumentTextIcon, AcademicCapIcon, CalendarIcon, BookOpenIcon } from '@heroicons/vue/24/outline';
</script>

<template>
    <Head title="Сертификаты" />

    <ProfileLayout>
        <div class="p-6">
            <h1 class="mb-6 text-2xl font-bold text-gray-800 dark:text-white">Ваши сертификаты</h1>
            
            <!-- Заглушка для сертификатов -->
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <div class="mb-6 flex h-24 w-24 items-center justify-center rounded-full bg-brandblue/10 dark:bg-brandblue/20">
                        <AcademicCapIcon class="h-12 w-12 text-brandblue" />
                    </div>
                    <h2 class="mb-2 text-xl font-semibold text-gray-800 dark:text-white">Здесь будут ваши сертификаты</h2>
                    <p class="max-w-md text-gray-600 dark:text-gray-400">
                        После успешного прохождения курсов и мероприятий, ваши сертификаты будут доступны в этом разделе.
                        Вы сможете просматривать, скачивать и делиться ими.
                    </p>
                    
                    <div class="mt-8 flex flex-wrap justify-center gap-4">
                        <a href="/events" class="inline-flex items-center rounded-full bg-brandblue px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-brandblue/90">
                            <CalendarIcon class="mr-2 h-5 w-5" />
                            Записаться на мероприятие
                        </a>
                        
                        <a href="/library" class="inline-flex items-center rounded-full border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                            <BookOpenIcon class="mr-2 h-5 w-5" />
                            Изучить материалы
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </ProfileLayout>
</template> 