<script setup>
import { ref, onMounted } from 'vue';
import api from '@/lib/api';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const rows = ref([]);
const loading = ref(false);
const filters = ref({ days: 30 });

const columns = [
    { key: 'photo', label: '', export: false },
    { key: 'employee_name', label: 'Employee', sortable: true },
    { key: 'document_type', label: 'Document Type' },
    { key: 'document_number', label: 'Document #' },
    { key: 'expiry_date', label: 'Expiry Date' },
    { key: 'days_remaining', label: 'Days Left', align: 'right' },
    { key: 'status', label: 'Status' },
];

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/documents/expiry', { params: filters.value });
        rows.value = data.data ?? data;
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Document Expiry Alerts" />

        <div class="mb-4 flex items-center gap-3">
            <label class="text-sm font-medium text-slate-600 dark:text-slate-400">Expiring within:</label>
            <select v-model="filters.days" class="input w-36" @change="load">
                <option :value="7">7 days</option>
                <option :value="14">14 days</option>
                <option :value="30">30 days</option>
                <option :value="60">60 days</option>
                <option :value="90">90 days</option>
            </select>
        </div>

        <DataTable :columns="columns" :rows="rows" :loading="loading" export-name="document_expiry">
            <template #cell-photo="{ row }">
                <Avatar :src="row.photo_url" :name="row.employee_name" size="sm" />
            </template>
            <template #cell-days_remaining="{ row }">
                <span :class="row.days_remaining <= 7 ? 'text-red-500 font-bold' : row.days_remaining <= 30 ? 'text-yellow-500 font-semibold' : ''">
                    {{ row.days_remaining }}
                </span>
            </template>
            <template #cell-status="{ row }">
                <Badge :color="row.days_remaining <= 0 ? 'red' : row.days_remaining <= 7 ? 'red' : row.days_remaining <= 30 ? 'yellow' : 'green'">
                    {{ row.days_remaining <= 0 ? 'Expired' : row.days_remaining <= 7 ? 'Critical' : row.days_remaining <= 30 ? 'Expiring Soon' : 'Valid' }}
                </Badge>
            </template>
        </DataTable>
    </div>
</template>
