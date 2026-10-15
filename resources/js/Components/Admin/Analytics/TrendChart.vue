<template>
    <div :style="{ height: `${height}px` }">
        <Line v-if="type === 'line'" :data="chartData" :options="options" />
        <Bar v-else :data="chartData" :options="options" />
    </div>
</template>

<script setup>
import { computed } from "vue";
import { Bar, Line } from "vue-chartjs";
import { useAdminStore } from "@/Stores/adminStore";
import { baseOptions, seriesColors } from "@/Components/Admin/Analytics/chartSetup.js";

// График по оси времени: линии или столбики, один или несколько рядов
const props = defineProps({
    labels: { type: Array, required: true },
    series: { type: Array, required: true }, // [{ label, data, color? }]
    type: { type: String, default: "line" },
    stacked: { type: Boolean, default: false },
    height: { type: Number, default: 260 },
    suffix: { type: String, default: "" },
    max: { type: Number, default: undefined },
});

const store = useAdminStore();

const chartData = computed(() => ({
    labels: props.labels,
    datasets: props.series.map((item, index) => {
        const color = item.color ?? seriesColors[index % seriesColors.length];
        return props.type === "line"
            ? {
                  label: item.label,
                  data: item.data,
                  borderColor: color,
                  backgroundColor: `${color}22`,
                  fill: props.series.length === 1,
                  tension: 0.35,
                  borderWidth: 2,
                  borderDash: item.dash,
                  pointRadius: props.labels.length > 45 ? 0 : 2,
                  pointHoverRadius: 4,
              }
            : {
                  label: item.label,
                  data: item.data,
                  backgroundColor: color,
                  borderRadius: 4,
                  maxBarThickness: 28,
                  stack: props.stacked ? "all" : undefined,
              };
    }),
}));

const options = computed(() => {
    const options = baseOptions(store.darkMode, {
        plugins: {
            ...baseOptions(store.darkMode).plugins,
            legend: { ...baseOptions(store.darkMode).plugins.legend, display: props.series.length > 1 },
            tooltip: {
                ...baseOptions(store.darkMode).plugins.tooltip,
                callbacks: { label: (item) => ` ${item.dataset.label}: ${item.formattedValue}${props.suffix}` },
            },
        },
    });
    if (props.stacked) {
        options.scales.x.stacked = true;
        options.scales.y.stacked = true;
    }
    if (props.max !== undefined) {
        options.scales.y.max = props.max;
    }
    return options;
});
</script>
