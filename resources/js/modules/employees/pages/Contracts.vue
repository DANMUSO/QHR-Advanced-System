<script setup>
import { ref, reactive, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Search, Download, LogOut } from 'lucide-vue-next';
import api from '@/lib/api';
import { formatDate } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

// Company-wide contract register: expiring soon, and expired contracts waiting for HR to renew or exit.
// Contracts are issued / edited from the employee profile (Contracts tab).
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const rows = ref([]);
const paginator = ref(null);
const loading = ref(false);
const views = { action: 'Expired: action required', expiring: 'Expiring in 30 days', active: 'Active', all: 'All' };
const filters = reactive({ view: views[route.query.view] ? route.query.view : 'action', search: '' });

const statusColors = { active: 'green', draft: 'slate', expired: 'red', terminated: 'slate', superseded: 'slate' };
const columns = [
    { key: 'employee_name', label: 'Employee', sortable: true },
    { key: 'reference', label: 'Reference' },
    { key: 'contract_type_label', label: 'Type' },
    { key: 'start_date', label: 'Start', format: (v) => formatDate(v) },
    { key: 'end_date', label: 'End', format: (v) => (v ? formatDate(v) : '—') },
    { key: 'status', label: 'Status' },
];

async function load(page = 1) {
    loading.value = true;
    const params = { page, search: filters.search || undefined };
    if (filters.view === 'action') params.action_required = 1;
    if (filters.view === 'expiring') params.expiring_days = 30;
    if (filters.view === 'active') params.status = 'active';
    try {
        const { data } = await api.get('/employees/contracts', { params });
        rows.value = data.data;
        paginator.value = { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from, to: data.to };
    } finally {
        loading.value = false;
    }
}

watch(() => filters.view, (v) => { router.replace({ query: { view: v } }); load(); });
onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Contracts" subtitle="Expiring and expired contracts. Renew from the employee's Contracts tab, or exit the employee." back="/employees" />

        <div class="mb-4 flex flex-wrap gap-2">
            <button v-for="(label, key) in views" :key="key"
                class="rounded-lg border px-3 py-1.5 text-sm font-medium transition"
                :class="filters.view === key ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400'"
                @click="filters.view = key">{{ label }}</button>
        </div>

        <DataTable :columns="columns" :rows="rows" :loading="loading" :paginator="paginator" export-name="contracts" clickable
            empty-title="Nothing here" empty-description="No contracts match this view."
            @row-click="(r) => router.push(`/employees/${r.employee_id}?tab=contracts`)" @page="load">
            <template #toolbar>
                <div class="relative w-full sm:w-72">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input v-model="filters.search" class="input !py-2 !pl-9" placeholder="Search employee…" @keyup.enter="load()" />
                </div>
            </template>
            <template #cell-employee_name="{ row }">
                <div class="flex items-center gap-3">
                    <Avatar :src="row.photo_url" :name="row.employee_name" size="sm" />
                    <div class="min-w-0">
                        <p class="truncate font-medium text-slate-900 dark:text-white">{{ row.employee_name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ row.employee_number }}<span v-if="row.department"> · {{ row.department }}</span></p>
                    </div>
                </div>
            </template>
            <template #cell-status="{ row }">
                <Badge :color="statusColors[row.status] ?? 'slate'">{{ row.status }}</Badge>
                <Badge v-if="row.action_required" color="red" class="ml-1">Renew or exit</Badge>
                <span v-else-if="row.status === 'active' && row.days_to_expiry !== null && row.days_to_expiry <= 30" class="ml-1 text-xs text-amber-600">{{ row.days_to_expiry }}d left</span>
            </template>
            <template #actions="{ row }">
                <a :href="row.pdf_url" target="_blank" class="btn-ghost !p-1.5" title="Contract PDF" @click.stop><Download class="size-4" /></a>
                <RouterLink v-if="row.action_required && auth.can('employees.exit')" :to="`/employees/${row.employee_id}`" class="btn-ghost !p-1.5" title="Open profile to exit" @click.stop><LogOut class="size-4" /></RouterLink>
            </template>
        </DataTable>
    </div>
</template>
