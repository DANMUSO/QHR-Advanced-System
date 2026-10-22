<script setup>
import { ref, onMounted } from 'vue';
import api from '@/lib/api';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';

const stats = ref({});
const loading = ref(true);

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/analytics/hr-dashboard');
        stats.value = data.data ?? data;
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="HR Dashboard" />
        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <div v-else>
            <!-- KPI Cards -->
            <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                <div v-for="(item, key) in {
                    'Total Headcount': stats.headcount,
                    'New Hires (MTD)': stats.new_hires_mtd,
                    'Exits (MTD)': stats.exits_mtd,
                    'Turnover Rate': stats.turnover_rate ? stats.turnover_rate + '%' : null,
                    'Avg Tenure (yrs)': stats.avg_tenure,
                    'Leave Utilisation': stats.leave_utilisation ? stats.leave_utilisation + '%' : null,
                    'Open Positions': stats.open_positions,
                    'Pending Approvals': stats.pending_approvals,
                }" :key="key" class="card p-4">
                    <p class="text-xs text-slate-500">{{ key }}</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-white">{{ item ?? '—' }}</p>
                </div>
            </div>

            <!-- Department breakdown -->
            <div class="card p-6">
                <h3 class="mb-4 font-semibold text-slate-900 dark:text-white">Headcount by Department</h3>
                <div v-if="stats.by_department?.length" class="space-y-3">
                    <div v-for="dept in stats.by_department" :key="dept.name" class="flex items-center gap-3">
                        <span class="w-40 truncate text-sm text-slate-600 dark:text-slate-400">{{ dept.name }}</span>
                        <div class="flex-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700" style="height:8px">
                            <div class="h-full rounded-full bg-brand-500" :style="{ width: `${(dept.count / (stats.headcount || 1)) * 100}%` }" />
                        </div>
                        <span class="w-8 text-right text-sm font-medium">{{ dept.count }}</span>
                    </div>
                </div>
                <p v-else class="text-sm text-slate-400">No department data.</p>
            </div>
        </div>
    </div>
</template>
