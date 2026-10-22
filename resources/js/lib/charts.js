import {
    Chart, ArcElement, BarElement, CategoryScale, LinearScale, LineElement, PointElement, Filler, Tooltip, Legend,
    BarController, LineController, DoughnutController, PieController, RadarController, RadialLinearScale,
} from 'chart.js';

// Controllers are needed for mixed charts (e.g. a line dataset inside a <Bar> chart) and radar charts.
Chart.register(
    ArcElement, BarElement, CategoryScale, LinearScale, LineElement, PointElement, Filler, Tooltip, Legend,
    BarController, LineController, DoughnutController, PieController, RadarController, RadialLinearScale,
);

Chart.defaults.font.family = 'Inter, ui-sans-serif, system-ui, sans-serif';
Chart.defaults.font.size = 11;
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.boxWidth = 8;

export const palette = ['#6366f1', '#10b981', '#f59e0b', '#f43f5e', '#0ea5e9', '#8b5cf6', '#14b8a6', '#ec4899'];

/** Axis / grid colours that read well in both themes. */
export function themeColors(dark) {
    return {
        text: dark ? '#94a3b8' : '#64748b',
        grid: dark ? 'rgba(148,163,184,0.12)' : 'rgba(100,116,139,0.12)',
    };
}
