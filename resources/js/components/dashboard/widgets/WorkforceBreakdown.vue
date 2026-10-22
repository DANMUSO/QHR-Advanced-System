<script setup>
import { ref, computed } from 'vue';
import { Doughnut } from 'vue-chartjs';
import { PieChart } from 'lucide-vue-next';
import { palette } from '@/lib/charts';
import { titleCase } from '@/lib/format';
import WidgetHeader from '../WidgetHeader.vue';

const props = defineProps({ data: Object, title: String });
const tab = ref('by_department');
const tabs = { by_department: 'Department', by_branch: 'Branch', by_contract: 'Contract' };

const rows = computed(() => (props.data[tab.value] || []).map((r) => ({ label: tab.value === 'by_contract' ? titleCase(r.label) : r.label, value: Number(r.value) })));
const total = computed(() => rows.value.reduce((a, r) => a + r.value, 0) || 1);

const chartData = computed(() => ({
    labels: rows.value.map((r) => r.label),
    datasets: [{ data: rows.value.map((r) => r.value), backgroundColor: palette, borderWidth: 0, hoverOffset: 6 }],
}));
const options = { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { display: false } } };
</script>

<template>
    <div class="flex flex-col pb-5">
        <WidgetHeader :title="title" :icon="PieChart"
            icon-class="bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400" />
        <div class="mx-5 mt-3 flex gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
            <button
                v-for="(label, key) in tabs"
                :key="key"
                class="flex-1 rounded-lg px-2 py-1.5 text-xs font-medium transition"
                :class="tab === key ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500'"
                @click="tab = key"
            >{{ label }}</button>
        </div>
        <div class="relative mx-auto mt-4 size-36">
            <Doughnut :data="chartData" :options="options" />
            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-xl font-bold">{{ total }}</span>
                <span class="text-[11px] text-slate-500">staff</span>
            </div>
        </div>
        <ul class="mt-4 space-y-2 px-5">
            <li v-for="(r, i) in rows" :key="r.label" class="flex items-center gap-2 text-sm">
                <span class="size-2.5 shrink-0 rounded-full" :style="{ background: palette[i % palette.length] }" />
                <span class="flex-1 truncate text-slate-600 dark:text-slate-400">{{ r.label }}</span>
                <span class="font-semibold">{{ r.value }}</span>
                <span class="w-10 text-right text-xs text-slate-400">{{ Math.round((r.value / total) * 100) }}%</span>
            </li>
        </ul>
    </div>
</template>
