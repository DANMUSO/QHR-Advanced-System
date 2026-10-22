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
const filters = ref({ year: new Date().getFullYear(), month: '' });

const columns = [
    { key: 'position', label: 'Position', sortable: true },
    { key: 'department', label: 'Department' },
    { key: 'applications', label: 'Applications', align: 'right' },
    { key: 'screened', label: 'Screened', align: 'right' },
    { key: 'interviewed', label: 'Interviewed', align: 'right' },
    { key: 'offers_made', label: 'Offers', align: 'right' },
    { key: 'hired', label: 'Hired', align: 'right' },
    { key: 'time_to_hire', label: 'Avg Days to Hire', align: 'right' },
];

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/recruitment/reports', { params: filters.value });
        rows.value = data.data ?? data;
    } finally {
        loading.value = false;
    }
}

async function exportReport() {
    try {
        const { data } = await api.get('/recruitment/reports/export', { params: filters.value, responseType: 'blob' });
        const url = URL.createObjectURL(data);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'hiring_report.xlsx';
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
        <PageHeader title="Hiring Reports">
            <template #actions>
                <button class="btn-secondary" :disabled="!rows.length" @click="exportReport"><Download class="size-4" /> Export</button>
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-wrap gap-3">
            <select v-model="filters.year" class="input w-28" @change="load">
                <option v-for="y in [new Date().getFullYear()-1, new Date().getFullYear()]" :key="y" :value="y">{{ y }}</option>
            </select>
        </div>

        <DataTable :columns="columns" :rows="rows" :loading="loading" export-name="hiring_report" />
    </div>
</template>
