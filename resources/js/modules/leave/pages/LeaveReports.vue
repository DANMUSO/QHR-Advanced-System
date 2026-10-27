<script setup>
import { ref, onMounted } from 'vue';
import { Download } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';

const ui = useUiStore();
const rows = ref([]);
const columns = ref([]);
const loading = ref(false);
const reportType = ref('taken');
const year = ref(new Date().getFullYear());
const filters = ref({ department_id: '', leave_type_id: '' });

const tabs = [
    { key: 'taken',        label: 'Leave Taken' },
    { key: 'balances',     label: 'Balances' },
    { key: 'rollover',     label: 'Roll-Over' },
    { key: 'forfeiture',   label: 'Forfeiture' },
    { key: 'handover',     label: 'Handover' },
    { key: 'consolidated', label: 'Consolidated' },
    { key: 'forensic',     label: 'Forensic' },
];

const columnMap = {
    taken: [
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'department', label: 'Department' },
        { key: 'leave_type', label: 'Leave Type' },
        { key: 'entitled', label: 'Entitled', align: 'right' },
        { key: 'taken', label: 'Taken', align: 'right' },
        { key: 'pending', label: 'Pending', align: 'right' },
        { key: 'rejected', label: 'Rejected', align: 'right' },
        { key: 'balance', label: 'Balance', align: 'right' },
    ],
    balances: [
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'department', label: 'Department' },
        { key: 'leave_type', label: 'Leave Type' },
        { key: 'available', label: 'Available', align: 'right' },
        { key: 'earned', label: 'Earned', align: 'right' },
        { key: 'taken', label: 'Taken', align: 'right' },
        { key: 'booked', label: 'Booked', align: 'right' },
        { key: 'pending', label: 'Pending', align: 'right' },
    ],
    rollover: [
        { key: 'employee_number', label: 'No.' },
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'department', label: 'Department' },
        { key: 'leave_type', label: 'Leave Type' },
        { key: 'days', label: 'Days Carried Forward', align: 'right' },
        { key: 'date', label: 'Date' },
        { key: 'reason', label: 'Note' },
    ],
    forfeiture: [
        { key: 'employee_number', label: 'No.' },
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'department', label: 'Department' },
        { key: 'leave_type', label: 'Leave Type' },
        { key: 'days', label: 'Days Forfeited', align: 'right' },
        { key: 'date', label: 'Date' },
        { key: 'reason', label: 'Note' },
    ],
    handover: [
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'leave_type', label: 'Leave Type' },
        { key: 'start_date', label: 'Start' },
        { key: 'end_date', label: 'End' },
        { key: 'handover1', label: 'Handover 1' },
        { key: 'handover1_status', label: 'H1 Status' },
        { key: 'handover2', label: 'Handover 2' },
        { key: 'handover2_status', label: 'H2 Status' },
        { key: 'supervisor_status', label: 'Supervisor' },
    ],
    consolidated: [
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'department', label: 'Department' },
        { key: 'annual_balance', label: 'Annual', align: 'right' },
        { key: 'sick_balance', label: 'Sick', align: 'right' },
        { key: 'compassionate_balance', label: 'Compassionate', align: 'right' },
        { key: 'study_balance', label: 'Study', align: 'right' },
        { key: 'total_taken', label: 'Total Taken', align: 'right' },
    ],
    forensic: [
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'transaction_type', label: 'Type' },
        { key: 'leave_type', label: 'Leave Type' },
        { key: 'days', label: 'Days', align: 'right' },
        { key: 'balance_after', label: 'Balance After', align: 'right' },
        { key: 'description', label: 'Description' },
        { key: 'created_at', label: 'Date' },
    ],
};

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/leave/reports', {
            params: { report: reportType.value, year: year.value, ...filters.value },
        });
        rows.value = data.data ?? data;
        // Use backend columns when available (consolidated has dynamic leave-type columns)
        if (data.columns?.length) columns.value = data.columns;
        else columns.value = columnMap[reportType.value] ?? [];
    } finally {
        loading.value = false;
    }
}

async function exportReport() {
    try {
        const response = await api.get('/leave/reports/export', {
            params: { report: reportType.value, year: year.value, ...filters.value },
            responseType: 'blob',
        });
        const url = URL.createObjectURL(response.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = `leave_${reportType.value}_${year.value}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    }
}

function switchTab(t) { reportType.value = t; columns.value = []; load(); }

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Leave Reports">
            <template #actions>
                <select v-model="year" class="input w-28" @change="load">
                    <option v-for="y in [new Date().getFullYear()-1, new Date().getFullYear(), new Date().getFullYear()+1]" :key="y" :value="y">{{ y }}</option>
                </select>
                <button class="btn-secondary" @click="exportReport"><Download class="size-4" /> Export CSV</button>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="reportType" @update:model-value="switchTab" />

        <DataTable
            :columns="columns.length ? columns : (columnMap[reportType] ?? [])"
            :rows="rows"
            :loading="loading"
            :export-name="`leave_${reportType}_${year}`"
        >
            <template #cell-balance="{ row }">
                <span :class="row.balance < 0 ? 'font-semibold text-red-500' : ''">{{ row.balance }}</span>
            </template>
            <template #cell-forfeited="{ row }">
                <span class="font-semibold text-amber-600">{{ row.forfeited }}</span>
            </template>
            <template #cell-days="{ row }">
                <span :class="row.days < 0 ? 'text-red-500' : row.days > 0 ? 'text-green-600' : ''">
                    {{ row.days > 0 ? '+' : '' }}{{ row.days }}
                </span>
            </template>
        </DataTable>
    </div>
</template>
