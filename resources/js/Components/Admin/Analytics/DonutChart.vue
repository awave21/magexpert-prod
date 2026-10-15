<template>
    <div class="flex items-center gap-5">
        <div class="relative shrink-0" :style="{ width: `${size}px`, height: `${size}px` }">
            <Doughnut :data="chartData" :options="options" />
            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                <span class="font-display text-2xl font-semibold text-zinc-950 dark:text-white">{{ total }}</span>
                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ caption }}</span>
            </div>
        </div>
        <ul class="min-w-0 flex-1 space-y-2 text-sm">
            <li v-for="(item, index) in items" :key="item.label" class="flex items-center gap-2">
                <span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: colors[index % colors.length] }"></span>
                <span class="min-w-0 flex-1 truncate text-zinc-700 dark:text-zinc-300">{{ item.label }}</span>
                <span class="font-medium tabular-nums text-zinc-950 dark:text-white">{{ item.count }}</span>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { computed } from "vue";
import { Doughnut } from "vue-chartjs";
import { palette } from "@/Components/Admin/Analytics/chartSetup.js";

// Кольцевая диаграмма с подписями справа; в центре — сумма
const props = defineProps({
    items: { type: Array, required: true }, // [{ label, count }]
    caption: { type: String, default: "всего" },
    size: { type: Number, default: 150 },
});

const colors = [palette.blue, palette.coral, palette.sky, palette.amber, palette.zinc];
const total = computed(() => props.items.reduce((sum, item) => sum + item.count, 0));

const chartData = computed(() => ({
    labels: props.items.map((item) => item.label),
    datasets: [{
        data: props.items.map((item) => item.count),
        backgroundColor: props.items.map((_, index) => colors[index % colors.length]),
        borderWidth: 0,
        hoverOffset: 4,
    }],
}));

const options = { responsive: true, maintainAspectRatio: false, cutout: "72%", plugins: { legend: { display: false } } };
</script>
