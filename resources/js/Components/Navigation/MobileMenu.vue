<template>
  <div class="space-y-1">
    <template v-for="(item, index) in navigation" :key="item.name">
      <div v-if="item.hasDropdown">
        <button
          @click="toggleDropdown(index)"
          class="flex w-full items-center justify-between rounded-xl px-3 py-2 text-base font-medium text-gray-700 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-gray-200 dark:hover:bg-gray-700 dark:hover:text-white"
          :class="[isParentActive(item.href) ? 'bg-brandblue/10 text-brandblue dark:bg-brandblue/20 dark:text-brandblue' : '']"
        >
          <span>{{ item.name }}</span>
          <ChevronDownIcon class="h-5 w-5 transition-transform duration-200" :class="{'rotate-180': openDropdown === index}" />
        </button>
        <div
          v-show="openDropdown === index"
          class="mt-1 space-y-1 rounded-xl bg-gray-50 p-1.5 dark:bg-gray-800/50"
        >
          <Link
            v-for="dropItem in item.dropdownItems"
            :key="dropItem.name"
            :href="dropItem.href"
            class="block rounded-lg px-3 py-2 text-base font-medium text-gray-700 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-gray-200 dark:hover:bg-gray-700 dark:hover:text-white"
            :class="[isActive(dropItem.href) ? 'bg-brandblue/10 text-brandblue dark:bg-brandblue/20 dark:text-brandblue' : '']"
            @click="emitClose"
          >
            {{ dropItem.name }}
          </Link>
        </div>
      </div>
      <Link
        v-else
        :href="item.href"
        class="block rounded-xl px-3 py-2 text-base font-medium transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-gray-200 dark:hover:bg-gray-700 dark:hover:text-white"
        :class="[isActive(item.href) ? 'bg-brandblue/10 text-brandblue dark:bg-brandblue/20 dark:text-brandblue' : 'text-gray-700']"
        @click="emitClose"
      >
        {{ item.name }}
      </Link>
    </template>

    <Link
      :href="route('login')"
      class="block rounded-xl px-3 py-2 text-base font-medium transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-gray-200 dark:hover:bg-gray-700 dark:hover:text-white"
      v-if="!isAuth"
      @click="emitClose"
    >
      Войти
    </Link>

    <Link
      :href="route('register')"
      class="block rounded-xl px-3 py-2 text-base font-medium transition-colors hover:bg-gray-100 text-brandblue dark:text-brandblue dark:hover:bg-gray-700"
      v-if="!isAuth"
      @click="emitClose"
    >
      Регистрация
    </Link>

    <Link
      :href="route('dashboard')"
      class="block rounded-xl px-3 py-2 text-base font-medium text-white transition-colors bg-brandcoral hover:bg-brandcoral/90 dark:bg-brandcoral dark:hover:bg-brandcoral/80"
      v-if="isAuth"
      @click="emitClose"
    >
      Личный кабинет
    </Link>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronDownIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
  navigation: {
    type: Array,
    required: true
  },
  currentUrl: {
    type: String,
    required: true
  },
  isAuth: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['close']);

const openDropdown = ref(null);

const toggleDropdown = (index) => {
  openDropdown.value = openDropdown.value === index ? null : index;
};

// Безопасное получение pathname (SSR-совместимо)
const toPath = (href) => {
  if (!href) return '/';
  try {
    if (typeof href === 'string' && /^(https?:)?\/\//i.test(href)) {
      return new URL(href).pathname || '/';
    }
    const base = typeof window !== 'undefined' ? window.location.origin : 'http://localhost';
    return new URL(href, base).pathname || '/';
  } catch (e) {
    return typeof href === 'string' && href.startsWith('/') ? href : '/';
  }
};

const isActive = (href) => {
  const path = toPath(href);
  if (path === '/') {
    return props.currentUrl === '/' || props.currentUrl.startsWith('/?');
  }
  return (
    props.currentUrl === path ||
    props.currentUrl.startsWith(path + '/') ||
    props.currentUrl.startsWith(path + '?')
  );
};

const isParentActive = (href) => {
  const path = toPath(href);
  if (path === '/') {
    return props.currentUrl === '/' || props.currentUrl.startsWith('/?');
  }
  return props.currentUrl.startsWith(path);
};

const emitClose = () => emit('close');
</script>


