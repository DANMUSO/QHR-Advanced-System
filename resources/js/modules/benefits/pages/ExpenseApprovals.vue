<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { Check, X } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { formatDate, formatMoney, titleCase } from '@/lib/format';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';

/** Expense claims and travel requests awaiting the signed-in approver (supervisor, or HR fallback), plus recent decisions. */
const ui = useUiStore();
const tab = ref(useRoute().query.tab === 'travel' ? 'travel' : 'expenses');
const tabs = [
    { key: 'expenses', label: 'Expense claims' },
    { key: 'travel', label: 'Travel requests' },
];

const rows = ref([]);
const loading = ref(false);
const dialog = ref({ open: false, row: null, action: '', loading: false, error: '' });

const statusColor = { submitted: 'amber', pending: 'amber', approved: 'blue', paid: 'green', payroll_pending: 'purple', rejected: 'red' };

const expenseCols = [
    { key: 'employee_name', label: 'Employee', sortable: true },
    { key: 'description', label: 'Description' },
    { key: 'category', label: 'Category', format: titleCase },
    { key: 'amount', label: 'Amount', align: 'right', format: (v) => formatMoney(v) },
    { key: 'expense_date', label: 'Date', format: (v) => formatDate(v) },
    { key: 'status', label: 'Status' },
];
const travelCols = [
    { key: 'employee_name', label: 'Employee', sortable: true },
    { key: 'destination', label: 'Destination' },
    { key: 'purpose', label: 'Purpose' },
    { key: 'departure_date', label: 'Dates', format: (v, r) => `${formatDate(r.departure_date)} – ${formatDate(r.return_date)}` },
    { key: 'estimated_cost', label: 'Est. cost', align: 'right', format: (v) => formatMoney(v) },
    { key: 'status', label: 'Status' },
];

const base = () => (tab.value === 'travel' ? '/expenses/travel' : '/expenses');

async function load() {
    loading.value = true;
    rows.value = [];
    try {
        const { data } = await api.get(`${base()}/approvals`);
        rows.value = data.data ?? data;
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

function switchTab(t) { tab.value = t; load(); }

function ask(row, action) {
    dialog.value = { open: true, row, action, loading: false, error: '' };
}

async function confirm(reason) {
    const { row, action } = dialog.value;
    dialog.value.loading = true;
    dialog.value.error = '';
    try {
        const { data } = await api.post(`${base()}/${row.id}/${action}`, action === 'reject' ? { reason } : {});
        ui.toast(data.message);
        dialog.value.open = false;
        await load();
    } catch (e) {
        dialog.value.error = errorMessage(e);
    } finally {
        dialog.value.loading = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Expense & Travel Approvals" subtitle="Requests from your team awaiting your decision" />

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <DataTable
            :columns="tab === 'travel' ? travelCols : expenseCols"
            :rows="rows"
            :loading="loading"
            :export-name="tab === 'travel' ? 'travel-approvals' : 'expense-approvals'"
            empty-title="Nothing awaiting your approval"
        >
            <template #cell-employee_name="{ row }">
                <div class="flex items-center gap-2">
                    <Avatar :src="row.photo" :name="row.employee_name" size="sm" />
                    <div>
                        <div class="font-medium text-slate-900 dark:text-white">{{ row.employee_name }}</div>
                        <div class="text-xs text-slate-400">{{ row.department }}</div>
                    </div>
                </div>
            </template>
            <template #cell-status="{ row }">
                <Badge :color="statusColor[row.status] ?? 'slate'">{{ row.status === 'payroll_pending' ? 'via payroll' : row.status }}</Badge>
                <p v-if="row.decision_note && row.status === 'rejected'" class="mt-1 max-w-48 truncate text-xs text-slate-400" :title="row.decision_note">{{ row.decision_note }}</p>
            </template>
            <template #actions="{ row }">
                <template v-if="row.can_decide">
                    <button class="btn-ghost !p-1.5 text-emerald-600 dark:text-emerald-400" title="Approve" @click="ask(row, 'approve')"><Check class="size-4" /></button>
                    <button class="btn-ghost !p-1.5 text-rose-600 dark:text-rose-400" title="Reject" @click="ask(row, 'reject')"><X class="size-4" /></button>
                </template>
            </template>
        </DataTable>

        <ConfirmDialog
            :open="dialog.open"
            :title="dialog.action === 'approve' ? 'Approve request' : 'Reject request'"
            :message="dialog.row ? `${dialog.row.employee_name} · ${dialog.row.description ?? dialog.row.destination} · ${formatMoney(dialog.row.amount ?? dialog.row.estimated_cost)}` : ''"
            :reason-label="dialog.action === 'reject' ? 'Reason (required)' : undefined"
            :confirm-label="dialog.action === 'approve' ? 'Approve' : 'Reject'"
            :danger="dialog.action === 'reject'"
            :loading="dialog.loading"
            :error="dialog.error"
            @close="dialog.open = false"
            @confirm="confirm"
        />
    </div>
</template>
