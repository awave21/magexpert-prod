<script setup>
import { ref, onMounted } from "vue";

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
        class="fixed bottom-0 left-0 right-0 z-50 bg-white border-t border-gray-200 p-4 dark:bg-gray-800 dark:border-gray-700 md:bottom-0"
        :class="{
            'md:bottom-0': true,
            'bottom-16 md:bottom-0': $page.props.auth.user,
        }"
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
