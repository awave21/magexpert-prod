<template>
    <div class="overflow-x-auto">
        <div class="min-w-[560px]">
            <div class="grid gap-1" style="grid-template-columns: 2rem repeat(24, minmax(0, 1fr))">
                <span></span>
                <span v-for="hour in 24" :key="`h${hour}`" class="text-center text-[10px] text-zinc-400">{{ (hour - 1) % 3 === 0 ? hour - 1 : "" }}</span>
                <template v-for="(row, day) in grid" :key="day">
                    <span class="flex items-center text-xs text-zinc-500 dark:text-zinc-400">{{ days[day] }}</span>
                    <span
                        v-for="(value, hour) in row"
                        :key="`${day}-${hour}`"
                        class="aspect-square rounded-[3px]"
                        :style="cellStyle(value)"
                        :title="`${days[day]}, ${hour}:00 — ${value}`"
                    ></span>
                </template>
            </div>
            <div class="mt-3 flex items-center justify-end gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                меньше
                <span v-for="step in 5" :key="step" class="size-3 rounded-[3px]" :style="cellStyle((step / 5) * max)"></span>
                больше
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from "vue";

// Когда пользователи активны: строки — дни недели, столбцы — часы
const props = defineProps({
    grid: { type: Array, required: true },
});

const days = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"];
const max = computed(() => Math.max(1, ...props.grid.flat()));

const cellStyle = (value) => {
    if (!value) {
        return { backgroundColor: "rgba(113,113,122,0.12)" };
    }
    const alpha = 0.2 + (value / max.value) * 0.8;
    return { backgroundColor: `rgba(63,101,150,${alpha.toFixed(2)})` };
};
</script>
