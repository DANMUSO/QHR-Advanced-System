<script setup>
import { ref, onMounted } from 'vue';
import { Download } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const ui = useUiStore();
const rows = ref([]);
const loading = ref(false);
const filters = ref({ year: new Date().getFullYear(), month: '', type: '' });

const columns = [
    { key: 'employee_name', label: 'Employee', sortable: true },
    { key: 'department', label: 'Department' },
    { key: 'expenses_total', label: 'Expenses (KES)', align: 'right', format: v => v?.toLocaleString() },
    { key: 'claims_total', label: 'Claims (KES)', align: 'right', format: v => v?.toLocaleString() },
    { key: 'advances_total', label: 'Advances (KES)', align: 'right', format: v => v?.toLocaleString() },
    { key: 'total', label: 'Total (KES)', align: 'right', format: v => v?.toLocaleString() },
];

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/claims/reports', { params: filters.value });
        rows.value = data.data ?? data;
    } finally {
        loading.value = false;
    }
}

async function exportReport() {
    try {
        const { data } = await api.get('/claims/reports/export', { params: filters.value, responseType: 'blob' });
        const url = URL.createObjectURL(data);
        const a = document.createElement('a');
        a.href = url;
        a.download = `finance-report-${filters.value.year}${filters.value.month ? '-' + String(filters.value.month).padStart(2, '0') : ''}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Finance Reports">
            <template #actions>
                <button class="btn-secondary" :disabled="!rows.length" @click="exportReport"><Download class="size-4" /> Export</button>
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-wrap gap-3">
            <select v-model="filters.year" class="input w-28" @change="load">
                <option v-for="y in [new Date().getFullYear()-1, new Date().getFullYear()]" :key="y" :value="y">{{ y }}</option>
            </select>
            <select v-model="filters.month" class="input w-36" @change="load">
                <option value="">All Months</option>
                <option v-for="m in 12" :key="m" :value="m">{{ new Date(2000, m-1).toLocaleString('default', { month: 'long' }) }}</option>
            </select>
        </div>

        <DataTable :columns="columns" :rows="rows" :loading="loading" export-name="finance_report" />
    </div>
</template>
