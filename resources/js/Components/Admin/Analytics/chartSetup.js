import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from "chart.js";

// Подключаем только то, что нужно графикам админки: так сборка остаётся лёгкой
Chart.register(ArcElement, BarController, BarElement, CategoryScale, DoughnutController, Filler, Legend, LineController, LineElement, LinearScale, PointElement, Tooltip);

// Фирменные цвета для рядов и сегментов
export const palette = {
    blue: "#3f6596",
    sky: "#6186b6",
    pale: "#a9bfdc",
    coral: "#f27b63",
    amber: "#e0a43a",
    zinc: "#a1a1aa",
};

export const seriesColors = [palette.blue, palette.coral, palette.sky, palette.amber, palette.zinc];

const font = { family: '"Manrope", ui-sans-serif, system-ui, sans-serif', size: 12 };

// Общие настройки: цвета текста и сетки зависят от темы
export function baseOptions(dark, extra = {}) {
    const text = dark ? "#a1a1aa" : "#71717a";
    const grid = dark ? "rgba(255,255,255,0.07)" : "rgba(9,9,11,0.06)";

    return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: "index", intersect: false },
        plugins: {
            legend: { position: "bottom", labels: { color: text, font, usePointStyle: true, boxWidth: 8, padding: 16 } },
            tooltip: {
                backgroundColor: dark ? "#27272a" : "#18181b",
                titleFont: { ...font, weight: "600" },
                bodyFont: font,
                padding: 10,
                cornerRadius: 8,
            },
        },
        scales: {
            x: { ticks: { color: text, font, maxRotation: 0, autoSkip: true, maxTicksLimit: 8 }, grid: { display: false }, border: { display: false } },
            y: { beginAtZero: true, ticks: { color: text, font, precision: 0 }, grid: { color: grid }, border: { display: false } },
        },
        ...extra,
    };
}

export const shortDate = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString("ru-RU", { day: "numeric", month: "short" }).replace(".", "");
