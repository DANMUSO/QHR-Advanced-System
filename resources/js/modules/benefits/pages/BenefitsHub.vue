<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { Plus, Pencil, Trash2 } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { titleCase } from '@/lib/format';
import Badge from '@/components/ui/Badge.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataTable from '@/components/ui/DataTable.vue';
import LookupSelect from '@/components/ui/LookupSelect.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';

const ui = useUiStore();
const tab = ref(useRoute().query.tab === 'pension' ? 'pension' : 'benefits');
const tabs = [
    { key: 'benefits', label: 'Benefits' },
    { key: 'pension', label: 'Pension & SACCO' },
];

const rows = ref([]);
const loading = ref(false);
const modal = ref({ open: false, row: null });
const form = ref({});
const errors = ref({});
const saving = ref(false);
const confirm = ref({ open: false, row: null, loading: false });

const money = (v) => (v == null ? '' : Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

const benefitCols = [
    { key: 'name', label: 'Benefit', sortable: true },
    { key: 'type', label: 'Type', format: titleCase },
    { key: 'value', label: 'Value / Rate' },
    { key: 'eligibility', label: 'Eligibility', format: titleCase },
    { key: 'enrolled', label: 'Enrolled', align: 'right' },
    { key: 'is_active', label: 'Status' },
];
const pensionCols = [
    { key: 'employee_name', label: 'Employee', sortable: true },
    { key: 'scheme_name', label: 'Scheme' },
    { key: 'scheme_type', label: 'Type', format: (v) => (v === 'sacco' ? 'SACCO' : 'Pension') },
    { key: 'employee_contribution', label: 'Employee (KES)', align: 'right', format: money },
    { key: 'employer_contribution', label: 'Employer (KES)', align: 'right', format: money },
    { key: 'total_balance', label: 'Balance (KES)', align: 'right', format: money },
    { key: 'joined_date', label: 'Joined' },
];

const endpoint = () => (tab.value === 'benefits' ? '/benefits' : '/benefits/pension');

async function load() {
    loading.value = true; rows.value = [];
    try {
        const { data } = await api.get(endpoint());
        rows.value = data.data ?? data;
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally { loading.value = false; }
}

function switchTab(t) { tab.value = t; load(); }

function openCreate() {
    errors.value = {};
    form.value = tab.value === 'benefits'
        ? { name: '', type: 'allowance', value: '', eligibility: 'all', description: '', is_active: true }
        : { employee_id: '', scheme_type: 'pension', scheme_name: '', member_number: '', employee_contribution: '', employer_contribution: '', total_balance: '', joined_date: '' };
    modal.value = { open: true, row: null };
}

function openEdit(row) {
    errors.value = {};
    form.value = { ...row };
    modal.value = { open: true, row };
}

async function save() {
    saving.value = true; errors.value = {};
    try {
        modal.value.row
            ? await api.put(`${endpoint()}/${modal.value.row.id}`, form.value)
            : await api.post(endpoint(), form.value);
        ui.toast('Saved.', 'success'); modal.value.open = false; await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally { saving.value = false; }
}

async function remove() {
    confirm.value.loading = true;
    try {
        await api.delete(`${endpoint()}/${confirm.value.row.id}`);
        ui.toast('Deleted.', 'success');
        confirm.value.open = false;
        await load();
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally { confirm.value.loading = false; }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Benefits Administration">
            <template #actions>
                <button class="btn-primary" @click="openCreate">
                    <Plus class="size-4" /> {{ tab === 'benefits' ? 'Add Benefit' : 'Add Membership' }}
                </button>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>

        <DataTable v-else :columns="tab === 'benefits' ? benefitCols : pensionCols" :rows="rows" :loading="false" :export-name="tab">
            <template #cell-is_active="{ row }">
                <Badge :color="row.is_active ? 'green' : 'slate'">{{ row.is_active ? 'Active' : 'Inactive' }}</Badge>
            </template>
            <template #actions="{ row }">
                <button class="btn-ghost !p-1.5" title="Edit" @click="openEdit(row)"><Pencil class="size-4" /></button>
                <button class="btn-ghost !p-1.5 text-red-500" title="Delete" @click="confirm = { open: true, row, loading: false }"><Trash2 class="size-4" /></button>
            </template>
        </DataTable>

        <Modal :open="modal.open" :title="(modal.row ? 'Edit ' : 'Add ') + (tab === 'benefits' ? 'Benefit' : 'Pension / SACCO Membership')" @close="modal.open = false">
            <div v-if="tab === 'benefits'" class="space-y-4">
                <div><label class="label">Benefit Name</label><input v-model="form.name" type="text" class="input" /><p v-if="errors.name" class="field-error">{{ errors.name }}</p></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Type</label>
                        <select v-model="form.type" class="input">
                            <option value="allowance">Allowance</option>
                            <option value="insurance">Insurance</option>
                            <option value="pension">Pension</option>
                            <option value="leave">Leave</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div><label class="label">Value / Rate</label><input v-model="form.value" type="text" class="input" placeholder="e.g. KES 3,000 / month" /></div>
                </div>
                <div>
                    <label class="label">Eligibility</label>
                    <select v-model="form.eligibility" class="input">
                        <option value="all">All Employees</option>
                        <option value="permanent">Permanent Only</option>
                        <option value="management">Management</option>
                        <option value="confirmed">Confirmed Only</option>
                    </select>
                </div>
                <div><label class="label">Description</label><textarea v-model="form.description" rows="2" class="input" /></div>
                <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300"><input v-model="form.is_active" type="checkbox" class="rounded" /> Active</label>
            </div>
            <div v-else class="space-y-4">
                <div>
                    <label class="label">Employee</label>
                    <LookupSelect v-model="form.employee_id" kind="employees" placeholder="Choose an employee" :error="!!errors.employee_id" :disabled="!!modal.row" />
                    <p v-if="errors.employee_id" class="field-error">{{ errors.employee_id }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Type</label>
                        <select v-model="form.scheme_type" class="input"><option value="pension">Pension</option><option value="sacco">SACCO</option></select>
                    </div>
                    <div><label class="label">Member Number</label><input v-model="form.member_number" type="text" class="input" /></div>
                </div>
                <div><label class="label">Scheme Name</label><input v-model="form.scheme_name" type="text" class="input" /><p v-if="errors.scheme_name" class="field-error">{{ errors.scheme_name }}</p></div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div><label class="label">Employee / month</label><input v-model="form.employee_contribution" type="number" min="0" class="input" /></div>
                    <div><label class="label">Employer / month</label><input v-model="form.employer_contribution" type="number" min="0" class="input" /></div>
                    <div><label class="label">Balance</label><input v-model="form.total_balance" type="number" min="0" class="input" /></div>
                </div>
                <div><label class="label">Joined</label><input v-model="form.joined_date" type="date" class="input" /></div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>

        <ConfirmDialog :open="confirm.open" title="Delete record" danger confirm-label="Delete" :loading="confirm.loading"
            :message="`Delete ${confirm.row?.name ?? confirm.row?.scheme_name ?? 'this record'}? This cannot be undone.`"
            @confirm="remove" @close="confirm.open = false" />
    </div>
</template>
