<template>
  <div v-if="links && links.length >= 2" class="flex flex-wrap items-center justify-center gap-2">
    <!-- Кнопка "Первая" -->
    <PaginationButton
      v-if="showFirstLastButtons && !isFirstPage"
      :href="firstPageUrl"
      :title="'На первую страницу'"
      icon-only
      class="hidden sm:inline-flex"
    >
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-4">
        <path d="M9.195 18.44c1.25.713 2.805-.19 2.805-1.629v-2.34l6.945 3.968c1.25.714 2.805-.188 2.805-1.628V8.688c0-1.44-1.555-2.342-2.805-1.628L12 11.03v-2.34c0-1.44-1.555-2.343-2.805-1.629l-7.108 4.062c-1.26.72-1.26 2.536 0 3.256l7.108 4.061Z" />
      </svg>
    </PaginationButton>

    <!-- Кнопка "Назад" -->
    <PaginationButton
      :href="prevPageUrl"
      :disabled="!hasPrevPage"
      :title="prevPageTitle || 'Предыдущая страница'"
      class="px-3 sm:px-4"
    >
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-4 mr-1">
        <path fill-rule="evenodd" d="M7.72 12.53a.75.75 0 0 1 0-1.06l7.5-7.5a.75.75 0 1 1 1.06 1.06L9.31 12l6.97 6.97a.75.75 0 1 1-1.06 1.06l-7.5-7.5Z" clip-rule="evenodd" />
      </svg>
      <span class="hidden sm:inline">Назад</span>
    </PaginationButton>

    <!-- Номера страниц -->
    <div class="flex gap-1">
      <template v-for="(link, i) in filteredLinks" :key="i">
        <PaginationButton
          v-if="link.url || (link.active && currentPage === 1)"
          :href="link.url"
          :active="link.active"
          :title="`Текущая страница ${link.label}`"
          class="min-w-[2rem] sm:min-w-[2.5rem] px-2 sm:px-3"
        >
          <span v-html="link.label"></span>
        </PaginationButton>
        <span 
          v-else-if="link.label === '...'" 
          class="flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-sm font-medium text-zinc-400 dark:text-zinc-600"
        >
          {{ link.label }}
        </span>
      </template>
    </div>

    <!-- Кнопка "Вперед" -->
    <PaginationButton
      :href="nextPageUrl"
      :disabled="!hasNextPage"
      :title="nextPageTitle || 'Следующая страница'"
      class="px-3 sm:px-4"
    >
      <span class="hidden sm:inline">Вперед</span>
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-4 ml-1">
        <path fill-rule="evenodd" d="M16.28 11.47a.75.75 0 0 1 0 1.06l-7.5 7.5a.75.75 0 0 1-1.06-1.06L14.69 12 7.72 5.03a.75.75 0 0 1 1.06-1.06l7.5 7.5Z" clip-rule="evenodd" />
      </svg>
    </PaginationButton>

    <!-- Кнопка "Последняя" -->
    <PaginationButton
      v-if="showFirstLastButtons && !isLastPage"
      :href="lastPageUrl"
      :title="'На последнюю страницу'"
      icon-only
      class="hidden sm:inline-flex"
    >
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-4">
        <path d="M5.055 7.06c-1.25-.714-2.805.189-2.805 1.628v8.123c0 1.44 1.555 2.342 2.805 1.628L12 14.471v2.34c0 1.44 1.555 2.342 2.805 1.628l7.108-4.061c1.26-.72 1.26-2.536 0-3.256L14.805 7.06C13.555 6.346 12 7.25 12 8.688v2.34L5.055 7.06Z" />
      </svg>
    </PaginationButton>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import PaginationButton from './PaginationButton.vue';

const props = defineProps({
  links: {
    type: Array,
    required: true
  },
  showFirstLastButtons: {
    type: Boolean,
    default: true
  },
  maxVisiblePages: {
    type: Number,
    default: 7
  }
});

// Получаем заголовки из пропсов или используем дефолтные
const prevPageTitle = computed(() => {
  const prev = props.links.find(link => link.label === '&laquo; Назад');
  return prev && prev.title ? prev.title : 'Предыдущая страница';
});

const nextPageTitle = computed(() => {
  const next = props.links.find(link => link.label === 'Вперед &raquo;');
  return next && next.title ? next.title : 'Следующая страница';
});

// Фильтруем ссылки, исключая Next и Previous, т.к. они отображаются отдельно
const filteredLinks = computed(() => {
  // Получаем ссылки без первой (Previous) и последней (Next)
  const filtered = props.links.slice(1, -1);
  
  // Если нет страниц, добавим хотя бы первую страницу
  if (filtered.length === 0 && props.links.length >= 2) {
    return [{ 
      url: null, 
      label: '1', 
      active: true 
    }];
  }
  
  return filtered;
});

// Определяем текущую, первую и последнюю страницы
const currentPage = computed(() => {
  const active = filteredLinks.value.findIndex(link => link.active);
  return active >= 0 ? active + 1 : 1;
});

const totalPages = computed(() => filteredLinks.value.length);
const isFirstPage = computed(() => currentPage.value === 1);
const isLastPage = computed(() => currentPage.value === totalPages.value);

// URL для кнопок навигации
const prevPageUrl = computed(() => {
  const prev = props.links.find(link => link.label === '&laquo; Назад');
  return prev ? prev.url : null;
});

const nextPageUrl = computed(() => {
  const next = props.links.find(link => link.label === 'Вперед &raquo;');
  return next ? next.url : null;
});

const firstPageUrl = computed(() => {
  const first = filteredLinks.value[0];
  return first ? first.url : null;
});

const lastPageUrl = computed(() => {
  const last = filteredLinks.value[filteredLinks.value.length - 1];
  return last ? last.url : null;
});

// Проверяем, есть ли следующая/предыдущая страница
const hasPrevPage = computed(() => !!prevPageUrl.value);
const hasNextPage = computed(() => !!nextPageUrl.value);
</script> 