<script setup>
import { ref, onMounted } from "vue";

defineProps({
    navigationBreakpoint: {
        type: String,
        default: "md",
    },
});

const showConsent = ref(false);

onMounted(() => {
    if (!localStorage.getItem("cookie_consent")) {
        showConsent.value = true;
    }
});

const accept = () => {
    localStorage.setItem("cookie_consent", "true");
    showConsent.value = false;
};
</script>

<template>
    <div
        v-if="showConsent"
        class="fixed bottom-[calc(4rem+env(safe-area-inset-bottom))] left-0 right-0 z-50 bg-white border-t border-gray-200 p-4 dark:bg-gray-800 dark:border-gray-700"
        :class="navigationBreakpoint === 'lg' ? 'lg:bottom-0' : 'md:bottom-0'"
    >
        <div
            class="mx-auto max-w-7xl flex flex-col lg:flex-row items-stretch lg:items-center lg:justify-between space-y-4 lg:space-y-0"
        >
            <p
                class="text-sm text-gray-700 dark:text-gray-300 order-2 lg:order-1"
            >
                Этот сайт использует файлы cookie для улучшения работы и анализа
                трафика. Продолжая пользоваться сайтом, вы соглашаетесь с
                использованием cookie.
            </p>
            <button
                @click="accept"
                class="bg-brandcoral hover:bg-brandcoral/80 text-white px-6 py-2 rounded-lg font-medium transition-colors duration-200 self-start lg:self-auto order-1 lg:order-2"
            >
                Принять
            </button>
        </div>
    </div>
</template>
