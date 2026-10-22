<script setup>
import { ref, onMounted } from 'vue';
import { CreditCard } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { formatDate, formatMoney, titleCase } from '@/lib/format';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import Tabs from '@/components/ui/Tabs.vue';

/** Finance payment step for approved expense claims: pay directly (M-Pesa / bank / cash) or through the next payroll. */
const ui = useUiStore();
const tab = ref('approved');
const tabs = [
    { key: 'approved', label: 'Awaiting payment' },
    { key: 'payroll_pending', label: 'Through payroll' },
    { key: 'paid', label: 'Paid' },
];

const rows = ref([]);
const totals = ref({ count: 0, amount: 0 });
const methods = ref({ mpesa: 'M-Pesa', bank: 'Bank transfer', cash: 'Cash', payroll: 'Through payroll' });
const loading = ref(false);

const modal = ref({ open: false, row: null });
const form = ref({});
const errors = ref({});
const saving = ref(false);

const columns = [
    { key: 'employee_name', label: 'Employee', sortable: true },
    { key: 'description', label: 'Description' },
    { key: 'category', label: 'Category', format: titleCase },
    { key: 'amount', label: 'Amount', align: 'right', format: (v) => formatMoney(v) },
    { key: 'expense_date', label: 'Date', format: (v) => formatDate(v) },
    { key: 'payment_method', label: 'Payment', format: (v, r) => (v ? `${methods.value[v] ?? v}${r.payment_reference ? ` · ${r.payment_reference}` : ''}` : '—') },
];

async function load() {
    loading.value = true;
    rows.value = [];
    try {
        const { data } = await api.get('/expenses/payments', { params: { status: tab.value } });
        rows.value = data.data;
        totals.value = data.totals;
        if (data.methods) methods.value = data.methods;
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

function switchTab(t) { tab.value = t; load(); }

function openPay(row) {
    errors.value = {};
    form.value = { payment_method: 'mpesa', payment_reference: '', paid_on: new Date().toISOString().slice(0, 10) };
    modal.value = { open: true, row };
}

async function pay() {
    saving.value = true;
    errors.value = {};
    try {
        const payload = form.value.payment_method === 'payroll' ? { payment_method: 'payroll' } : form.value;
        const { data } = await api.post(`/expenses/${modal.value.row.id}/pay`, payload);
        ui.toast(data.message);
        modal.value.open = false;
        await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Expense Payments" subtitle="Approved expense claims awaiting Finance">
            <template #actions>
                <div class="text-sm text-slate-500 dark:text-slate-400">{{ totals.count }} claim(s) · {{ formatMoney(totals.amount) }}</div>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <DataTable :columns="columns" :rows="rows" :loading="loading" export-name="expense-payments" empty-title="No expense claims here">
            <template #cell-employee_name="{ row }">
                <div class="flex items-center gap-2">
                    <Avatar :src="row.photo" :name="row.employee_name" size="sm" />
                    <div>
                        <div class="font-medium text-slate-900 dark:text-white">{{ row.employee_name }}</div>
                        <div class="text-xs text-slate-400">{{ row.phone || row.department }}</div>
                    </div>
                </div>
            </template>
            <template #actions="{ row }">
                <button v-if="row.status === 'approved'" class="btn-primary !py-1 !text-xs" @click="openPay(row)"><CreditCard class="size-3.5" /> Pay</button>
                <Badge v-else-if="row.status === 'payroll_pending'" color="purple">Next payroll</Badge>
                <Badge v-else color="green">Paid {{ formatDate(row.paid_at) }}</Badge>
            </template>
        </DataTable>

        <Modal :open="modal.open" title="Pay expense claim" @close="modal.open = false">
            <div class="space-y-4">
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ modal.row?.employee_name }} · {{ modal.row?.description }} · {{ formatMoney(modal.row?.amount) }}</p>
                <div>
                    <label class="label">How will it be paid?</label>
                    <select v-model="form.payment_method" class="input">
                        <option v-for="(label, key) in methods" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <p v-if="errors.payment_method" class="field-error">{{ errors.payment_method }}</p>
                </div>
                <p v-if="form.payment_method === 'payroll'" class="rounded-lg bg-violet-50 p-3 text-sm text-violet-800 dark:bg-violet-500/10 dark:text-violet-300">
                    The amount is added to the employee's next payroll run as a non-taxable reimbursement and marked paid when that run is approved.
                </p>
                <div v-else class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Reference {{ form.payment_method === 'cash' ? '(optional)' : '' }}</label>
                        <input v-model="form.payment_reference" class="input" placeholder="M-Pesa code / bank ref" />
                        <p v-if="errors.payment_reference" class="field-error">{{ errors.payment_reference }}</p>
                    </div>
                    <div>
                        <label class="label">Paid on</label>
                        <input v-model="form.paid_on" type="date" class="input" />
                        <p v-if="errors.paid_on" class="field-error">{{ errors.paid_on }}</p>
                    </div>
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="pay"><Spinner v-if="saving" /> {{ form.payment_method === 'payroll' ? 'Queue for payroll' : 'Mark paid' }}</button>
            </template>
        </Modal>
    </div>
</template>
