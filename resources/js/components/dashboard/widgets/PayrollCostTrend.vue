<script setup>
import { computed } from 'vue';
import { Bar } from 'vue-chartjs';
import { Wallet } from 'lucide-vue-next';
import { themeColors } from '@/lib/charts';
import { useUiStore } from '@/stores/ui';
import WidgetHeader from '../WidgetHeader.vue';

const props = defineProps({ data: Object, title: String });
const ui = useUiStore();

const kes = (v) => 'KES ' + Number(v ?? 0).toLocaleString('en-KE', { maximumFractionDigits: 0 });
const hasData = computed(() => (props.data?.series ?? []).some((s) => s.gross > 0));

const chartData = computed(() => ({
    labels: props.data.series.map((s) => s.month),
    datasets: [
        { type: 'line', label: 'Employer cost', data: props.data.series.map((s) => s.employer_cost), borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.12)', tension: 0.35, pointRadius: 3, order: 0 },
        { type: 'bar', label: 'Gross pay', data: props.data.series.map((s) => s.gross), backgroundColor: '#6366f1', borderRadius: 4, order: 1 },
        { type: 'bar', label: 'Net pay', data: props.data.series.map((s) => s.net), backgroundColor: '#10b981', borderRadius: 4, order: 1 },
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
            tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${kes(ctx.parsed.y)}` } },
        },
        scales: {
            x: { grid: { display: false }, ticks: { color: c.text } },
            y: { grid: { color: c.grid }, ticks: { color: c.text, callback: (v) => (v >= 1e6 ? `${v / 1e6}M` : v >= 1e3 ? `${v / 1e3}K` : v) } },
        },
    };
});
</script>

<template>
    <div class="flex flex-col">
        <WidgetHeader :title="title" :icon="Wallet" subtitle="Approved and paid payroll, all run types" to="/payroll" link-label="Payroll" />
        <div v-if="!hasData" class="p-8 text-center text-sm text-slate-400">No approved payroll in the last 12 months.</div>
        <div v-else class="h-72 p-5"><Bar :data="chartData" :options="options" /></div>
    </div>
</template>
