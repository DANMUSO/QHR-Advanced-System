<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Download } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';

const ui = useUiStore();
const route = useRoute();
const router = useRouter();
const tab = ref('headcount');
const tabs = [
    { key: 'headcount', label: 'Headcount' },
    { key: 'payroll-cost', label: 'Payroll Cost' },
    { key: 'absenteeism', label: 'Absenteeism' },
    { key: 'pay-gap', label: 'Gender Pay Gap' },
    { key: 'attrition', label: 'Attrition' },
    { key: 'workforce', label: 'Workforce Planning' },
];

const rows = ref([]);
const summary = ref({});
const loading = ref(false);
const year = ref(new Date().getFullYear());
const month = ref('');
const horizon = ref(12);

const colMap = {
    headcount: [
        { key: 'month', label: 'Month' },
        { key: 'opening', label: 'Opening', align: 'right' },
        { key: 'new_hires', label: 'New Hires', align: 'right' },
        { key: 'exits', label: 'Exits', align: 'right' },
        { key: 'closing', label: 'Closing', align: 'right' },
        { key: 'turnover_rate', label: 'Turnover %', align: 'right' },
    ],
    'payroll-cost': [
        { key: 'month', label: 'Month' },
        { key: 'headcount', label: 'Headcount', align: 'right' },
        { key: 'gross_payroll', label: 'Gross (KES)', align: 'right', format: v => v?.toLocaleString() },
        { key: 'total_deductions', label: 'Deductions (KES)', align: 'right', format: v => v?.toLocaleString() },
        { key: 'net_payroll', label: 'Net (KES)', align: 'right', format: v => v?.toLocaleString() },
        { key: 'cost_per_employee', label: 'Cost/Employee', align: 'right', format: v => v?.toLocaleString() },
    ],
    absenteeism: [
        { key: 'department', label: 'Department', sortable: true },
        { key: 'headcount', label: 'Headcount', align: 'right' },
        { key: 'absent_days', label: 'Absent Days', align: 'right' },
        { key: 'available_days', label: 'Available Days', align: 'right' },
        { key: 'absenteeism_rate', label: 'Rate %', align: 'right' },
        { key: 'cost_impact', label: 'Cost Impact (KES)', align: 'right', format: v => v?.toLocaleString() },
    ],
    'pay-gap': [
        { key: 'department', label: 'Department', sortable: true },
        { key: 'male_count', label: 'Male', align: 'right' },
        { key: 'female_count', label: 'Female', align: 'right' },
        { key: 'male_avg_salary', label: 'Male Avg (KES)', align: 'right', format: v => v?.toLocaleString() },
        { key: 'female_avg_salary', label: 'Female Avg (KES)', align: 'right', format: v => v?.toLocaleString() },
        { key: 'pay_gap_pct', label: 'Gap %', align: 'right' },
    ],
    attrition: [
        { key: 'photo', label: '', export: false },
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'department', label: 'Department' },
        { key: 'tenure_years', label: 'Tenure (yrs)', align: 'right' },
        { key: 'risk_score', label: 'Risk Score', align: 'right' },
        { key: 'risk_level', label: 'Risk Level' },
        { key: 'key_factors', label: 'Key Factors' },
    ],
    workforce: [
        { key: 'department', label: 'Department', sortable: true },
        { key: 'current_headcount', label: 'Current', align: 'right' },
        { key: 'projected_exits', label: 'Proj. Exits', align: 'right' },
        { key: 'planned_hires', label: 'Planned Hires', align: 'right' },
        { key: 'projected_headcount', label: 'Projected', align: 'right' },
        { key: 'gap', label: 'Gap', align: 'right' },
    ],
};

async function load() {
    loading.value = true; rows.value = []; summary.value = {};
    try {
        const params = { year: year.value, month: month.value, months: horizon.value };
        const { data } = await api.get(`/analytics/${tab.value}`, { params });
        rows.value = data.data ?? data;
        summary.value = data.summary ?? {};
    } finally { loading.value = false; }
}

function switchTab(t) { tab.value = t; router.replace({ query: { ...route.query, tab: t } }); load(); }

async function exportReport() {
    try {
        const params = { year: year.value, month: month.value };
        const { data } = await api.get(`/analytics/${tab.value}/export`, { params, responseType: 'blob' });
        const url = URL.createObjectURL(data);
        const a = document.createElement('a'); a.href = url; a.download = `${tab.value}_report.csv`; a.click();
        URL.revokeObjectURL(url);
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
}

onMounted(() => {
    if (tabs.some((t) => t.key === route.query.tab)) tab.value = route.query.tab;
    load();
});
</script>

<template>
    <div>
        <PageHeader title="Analytics Reports">
            <template #actions>
                <button class="btn-secondary" :disabled="!rows.length" @click="exportReport"><Download class="size-4" /> Export</button>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <!-- Filters -->
        <div class="mb-4 flex flex-wrap gap-3">
            <template v-if="tab !== 'attrition' && tab !== 'workforce'">
                <select v-model="year" class="input w-28" @change="load">
                    <option v-for="y in [new Date().getFullYear()-2, new Date().getFullYear()-1, new Date().getFullYear()]" :key="y" :value="y">{{ y }}</option>
                </select>
            </template>
            <template v-if="tab === 'absenteeism'">
                <select v-model="month" class="input w-36" @change="load">
                    <option value="">All Months</option>
                    <option v-for="m in 12" :key="m" :value="m">{{ new Date(2000, m-1).toLocaleString('default', { month: 'long' }) }}</option>
                </select>
            </template>
            <template v-if="tab === 'workforce'">
                <select v-model="horizon" class="input w-36" @change="load">
                    <option :value="3">3 months</option>
                    <option :value="6">6 months</option>
                    <option :value="12">12 months</option>
                    <option :value="24">24 months</option>
                </select>
            </template>
        </div>

        <!-- Payroll cost summary cards -->
        <div v-if="tab === 'payroll-cost' && Object.keys(summary).length" class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div v-for="(val, key) in summary" :key="key" class="card p-4">
                <p class="text-xs text-slate-500">{{ key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) }}</p>
                <p class="mt-1 text-xl font-bold text-slate-900 dark:text-white">{{ typeof val === 'number' ? val.toLocaleString() : val }}</p>
            </div>
        </div>

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <DataTable v-else :columns="colMap[tab]" :rows="rows" :loading="false" :export-name="tab">
            <template #cell-photo="{ row }"><Avatar :src="row.photo_url" :name="row.employee_name" size="sm" /></template>
            <template #cell-turnover_rate="{ row }">
                <span :class="row.turnover_rate > 5 ? 'text-red-500 font-semibold' : ''">{{ row.turnover_rate ?? '—' }}%</span>
            </template>
            <template #cell-absenteeism_rate="{ row }">
                <span :class="row.absenteeism_rate > 5 ? 'text-red-500 font-semibold' : ''">{{ row.absenteeism_rate ?? '—' }}%</span>
            </template>
            <template #cell-pay_gap_pct="{ row }">
                <span :class="Math.abs(row.pay_gap_pct ?? 0) > 10 ? 'text-red-500 font-semibold' : ''">{{ row.pay_gap_pct ?? '—' }}%</span>
            </template>
            <template #cell-risk_level="{ row }">
                <Badge :color="row.risk_level === 'high' ? 'red' : row.risk_level === 'medium' ? 'yellow' : 'green'">{{ row.risk_level }}</Badge>
            </template>
            <template #cell-gap="{ row }">
                <span :class="row.gap < 0 ? 'text-red-500 font-semibold' : row.gap > 0 ? 'text-green-600' : ''">
                    {{ row.gap > 0 ? '+' : '' }}{{ row.gap ?? '—' }}
                </span>
            </template>
        </DataTable>
    </div>
</template>
