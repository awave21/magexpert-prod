<template>
  <AdminLayout>
    <template #header>
      <div>
        <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">Ключи MCP</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Доступ ИИ-ассистента к мероприятиям, спикерам, категориям, партнёрам и библиотеке</p>
      </div>
    </template>

    <div class="space-y-8">
      <!-- Новый ключ показывается один раз -->
      <div v-if="newKey" class="rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/40 dark:bg-amber-500/10">
        <p class="text-sm font-semibold text-zinc-900 dark:text-white">Сохраните ключ сейчас: после обновления страницы он больше не покажется</p>
        <div class="mt-3 flex flex-col gap-2 sm:flex-row">
          <code class="min-w-0 flex-1 break-all rounded-lg bg-white px-3 py-2 text-sm text-zinc-900 ring-1 ring-zinc-200 dark:bg-zinc-900 dark:text-white dark:ring-zinc-700">{{ newKey }}</code>
          <button type="button" :class="primaryButton" @click="copy(newKey)">{{ copied === 'key' ? 'Скопировано' : 'Копировать' }}</button>
        </div>
      </div>

      <section class="space-y-3">
        <h2 :class="boxTitle">Адрес сервера</h2>
        <div class="flex flex-col gap-2 sm:flex-row">
          <code class="min-w-0 flex-1 break-all rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-900 dark:bg-zinc-800 dark:text-white">{{ serverUrl }}</code>
          <button type="button" :class="pill" @click="copy(serverUrl, 'url')">{{ copied === 'url' ? 'Скопировано' : 'Копировать' }}</button>
        </div>
        <p :class="hint">В настройках ассистента добавьте удалённый MCP-сервер с этим адресом и заголовком Authorization: Bearer и вашим ключом.</p>
      </section>

      <section class="space-y-3">
        <h2 :class="boxTitle">Новый ключ</h2>
        <form class="flex flex-col gap-2 sm:flex-row" @submit.prevent="create">
          <input v-model="form.name" type="text" maxlength="100" placeholder="Название, например «Claude на ноутбуке»" :class="[input, 'sm:max-w-sm']" />
          <button type="submit" :disabled="form.processing" :class="primaryButton">Создать ключ</button>
        </form>
        <p v-if="form.errors.name" :class="error">{{ form.errors.name }}</p>
      </section>

      <section class="space-y-3">
        <h2 :class="boxTitle">Действующие ключи</h2>
        <p v-if="!keys.length" class="text-sm text-zinc-500 dark:text-zinc-400">Ключей пока нет.</p>
        <ul v-else class="divide-y divide-zinc-100 dark:divide-zinc-800/70">
          <li v-for="key in keys" :key="key.id" class="flex items-center justify-between gap-3 py-3">
            <div class="min-w-0">
              <p class="[overflow-wrap:anywhere] text-sm font-medium text-zinc-900 dark:text-white">{{ key.name }}<span v-if="key.owner" class="font-normal text-zinc-500"> · {{ key.owner }}</span></p>
              <p class="text-xs text-zinc-500 dark:text-zinc-400">Создан {{ fmt(key.created_at) }} · {{ key.last_used_at ? 'использован ' + fmt(key.last_used_at) : 'ещё не использовался' }}</p>
            </div>
            <button type="button" :class="[pill, 'shrink-0 !text-red-600 dark:!text-red-400']" @click="revoke(key)">Отозвать</button>
          </li>
        </ul>
      </section>

      <section class="space-y-3">
        <h2 :class="boxTitle">Журнал действий ассистента</h2>
        <p v-if="!log.length" class="text-sm text-zinc-500 dark:text-zinc-400">Пока ничего не менялось.</p>
        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="border-b border-zinc-200 text-left text-xs font-medium text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
              <tr><th class="px-3 py-2">Когда</th><th class="px-3 py-2">Действие</th><th class="px-3 py-2">Объект</th></tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/70">
              <tr v-for="row in log" :key="row.id">
                <td class="whitespace-nowrap px-3 py-2 text-zinc-500 dark:text-zinc-400">{{ fmt(row.at) }}</td>
                <td class="px-3 py-2 text-zinc-900 dark:text-white">{{ actions[row.action] ?? row.action }}</td>
                <td class="px-3 py-2 [overflow-wrap:anywhere] text-zinc-700 dark:text-zinc-300">{{ row.subject }}<span v-if="row.label"> · {{ row.label }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </AdminLayout>
</template>

<script setup>
import { ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import { boxTitle, hint, input, error, pill, primaryButton } from "@/Components/Admin/formClasses.js";

defineProps({
  keys: { type: Array, default: () => [] },
  log: { type: Array, default: () => [] },
  serverUrl: { type: String, required: true },
  newKey: { type: String, default: null },
});

const actions = { create: "Создано", update: "Изменено", delete: "Удалено", publish: "Опубликовано", unpublish: "Скрыто" };
const form = useForm({ name: "" });
const copied = ref("");

const fmt = (iso) => (iso ? new Date(iso).toLocaleString("ru-RU", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "");

function create() {
  form.post(route("admin.mcp.keys.store"), { preserveScroll: true, onSuccess: () => form.reset() });
}

function revoke(key) {
  if (confirm(`Отозвать ключ «${key.name}»? Ассистент с этим ключом потеряет доступ.`)) {
    router.delete(route("admin.mcp.keys.destroy", key.id), { preserveScroll: true });
  }
}

async function copy(text, which = "key") {
  try {
    await navigator.clipboard.writeText(text);
    copied.value = which;
    setTimeout(() => (copied.value = ""), 1500);
  } catch {
    copied.value = "";
  }
}
</script>
