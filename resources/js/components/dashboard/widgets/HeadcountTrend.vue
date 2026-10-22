<script setup>
import { computed } from 'vue';
import { Bar } from 'vue-chartjs';
import { TrendingUp } from 'lucide-vue-next';
import { themeColors } from '@/lib/charts';
import { useUiStore } from '@/stores/ui';
import WidgetHeader from '../WidgetHeader.vue';

const props = defineProps({ data: Object, title: String });
const ui = useUiStore();

const chartData = computed(() => ({
    labels: props.data.series.map((s) => s.month),
    datasets: [
        { type: 'line', label: 'Headcount', data: props.data.series.map((s) => s.headcount), borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,0.12)', fill: true, tension: 0.35, yAxisID: 'y', pointRadius: 3, order: 0 },
        { type: 'bar', label: 'Joiners', data: props.data.series.map((s) => s.joiners), backgroundColor: '#10b981', borderRadius: 4, yAxisID: 'y1', order: 1 },
        { type: 'bar', label: 'Leavers', data: props.data.series.map((s) => -s.leavers), backgroundColor: '#f43f5e', borderRadius: 4, yAxisID: 'y1', order: 1 },
    ],
}));

const options = computed(() => {
    const c = themeColors(ui.isDark);
    return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'bottom', labels: { color: c.text } },
            tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${Math.abs(ctx.parsed.y)}` } },
        },
        scales: {
            x: { stacked: true, grid: { display: false }, ticks: { color: c.text } },
            y: { position: 'left', grid: { color: c.grid }, ticks: { color: c.text, precision: 0 }, title: { display: true, text: 'Headcount', color: c.text } },
            y1: { position: 'right', stacked: true, grid: { display: false }, ticks: { color: c.text, precision: 0, callback: (v) => Math.abs(v) }, title: { display: true, text: 'Movement', color: c.text } },
        },
    };
});
</script>

<template>
    <div class="flex flex-col">
        <WidgetHeader :title="title" :icon="TrendingUp"
            icon-class="bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400"
            subtitle="Month-end headcount with joiners and leavers" />
        <div class="h-64 p-5"><Bar :data="chartData" :options="options" /></div>
    </div>
</template>
