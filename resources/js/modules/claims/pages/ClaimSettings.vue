<script setup>
import { ref, onMounted } from 'vue';
import { Pencil, Save, Search } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { formatMoney } from '@/lib/format';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import LookupSelect from '@/components/ui/LookupSelect.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';

/** Claim approvers (HR-FEATURES §1.9) and per-employee limit overrides (§4.2). */
const ui = useUiStore();

const settings = ref(null);
const approvers = ref({ medical_approver_id: '', finance_approver_id: '' });
const savingApprovers = ref(false);
const approverErrors = ref({});

const rows = ref([]);
const paginator = ref(null);
const loading = ref(false);
const search = ref('');
const onlyOverridden = ref(false);
const page = ref(1);

const modal = ref({ open: false, row: null });
const form = ref({});
const errors = ref({});
const saving = ref(false);

const columns = [
    { key: 'name', label: 'Employee' },
    { key: 'department', label: 'Department' },
    { key: 'annual_limit', label: 'Annual limit', align: 'right', format: (v) => formatMoney(v) },
    { key: 'monthly_limit', label: 'Monthly limit', align: 'right', format: (v) => formatMoney(v) },
    { key: 'annual_used', label: 'Used this year', align: 'right', format: (v) => formatMoney(v) },
    { key: 'annual_balance', label: 'Balance', align: 'right', format: (v) => formatMoney(v) },
    { key: 'overridden', label: 'Source' },
];

async function loadSettings() {
    try {
        const { data } = await api.get('/claims/settings');
        settings.value = data;
        approvers.value = {
            medical_approver_id: data.medical_approver?.id ?? '',
            finance_approver_id: data.finance_approver?.id ?? '',
        };
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    }
}

async function loadLimits() {
    loading.value = true;
    try {
        const { data } = await api.get('/claims/limits', {
            params: { search: search.value || undefined, overridden: onlyOverridden.value ? 1 : undefined, page: page.value },
        });
        rows.value = data.data;
        paginator.value = data;
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

async function saveApprovers() {
    savingApprovers.value = true;
    approverErrors.value = {};
    try {
        const { data } = await api.put('/claims/settings/approvers', {
            medical_approver_id: approvers.value.medical_approver_id || null,
            finance_approver_id: approvers.value.finance_approver_id || null,
        });
        ui.toast(data.message);
        (data.warnings ?? []).forEach((w) => ui.toast(w, 'warning'));
        await loadSettings();
    } catch (e) {
        approverErrors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally {
        savingApprovers.value = false;
    }
}

function edit(row) {
    errors.value = {};
    form.value = { annual_limit: row.annual_override ?? '', monthly_limit: row.monthly_override ?? '', notes: row.notes ?? '' };
    modal.value = { open: true, row };
}

async function saveLimit() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await api.put(`/claims/limits/${modal.value.row.employee_id}`, {
            annual_limit: form.value.annual_limit === '' ? null : form.value.annual_limit,
            monthly_limit: form.value.monthly_limit === '' ? null : form.value.monthly_limit,
            notes: form.value.notes || null,
        });
        ui.toast(data.message);
        modal.value.open = false;
        await loadLimits();
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally {
        saving.value = false;
    }
}

function goto(p) { page.value = p; loadLimits(); }
function applyFilter() { page.value = 1; loadLimits(); }

onMounted(() => { loadSettings(); loadLimits(); });
</script>

<template>
    <div>
        <PageHeader title="Claim Settings" subtitle="Who approves and pays medical claims, and each employee's limits" />

        <div class="card mb-6 p-5">
            <h2 class="mb-1 font-semibold text-slate-900 dark:text-white">Claim approvers<span v-if="settings"> · {{ settings.company.name }}</span></h2>
            <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">Assigning a person grants them the matching permission (approve or pay claims); the previous holder loses it.</p>
            <div v-if="!settings" class="flex justify-center py-6"><Spinner /></div>
            <div v-else class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="label">Medical Approver (HR)</label>
                    <LookupSelect v-model="approvers.medical_approver_id" kind="employees" placeholder="Anyone with claim approval rights" />
                    <p v-if="approverErrors.medical_approver_id" class="field-error">{{ approverErrors.medical_approver_id }}</p>
                    <p v-else-if="settings.medical_approver && !settings.medical_approver.has_login" class="mt-1 text-xs text-amber-600 dark:text-amber-400">This person has no active login.</p>
                </div>
                <div>
                    <label class="label">Finance Payment Person</label>
                    <LookupSelect v-model="approvers.finance_approver_id" kind="employees" placeholder="Anyone with claim payment rights" />
                    <p v-if="approverErrors.finance_approver_id" class="field-error">{{ approverErrors.finance_approver_id }}</p>
                    <p v-else-if="settings.finance_approver && !settings.finance_approver.has_login" class="mt-1 text-xs text-amber-600 dark:text-amber-400">This person has no active login.</p>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <p v-if="settings" class="text-xs text-slate-500 dark:text-slate-400">
                    Company defaults: {{ formatMoney(settings.defaults.annual_limit) }} a year · {{ formatMoney(settings.defaults.monthly_limit) }} a month (change them in company settings).
                </p>
                <button class="btn-primary" :disabled="savingApprovers || !settings" @click="saveApprovers"><Spinner v-if="savingApprovers" /><Save v-else class="size-4" /> Save approvers</button>
            </div>
        </div>

        <h2 class="mb-3 font-semibold text-slate-900 dark:text-white">Employee limits</h2>
        <DataTable :columns="columns" :rows="rows" :loading="loading" :paginator="paginator" row-key="employee_id" export-name="claim-limits" @page="goto">
            <template #toolbar>
                <div class="relative w-full sm:w-64">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input v-model="search" type="search" class="input !pl-9" placeholder="Search employees" @keyup.enter="applyFilter" />
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input v-model="onlyOverridden" type="checkbox" @change="applyFilter" /> Overrides only
                </label>
            </template>
            <template #cell-name="{ row }">
                <div class="font-medium text-slate-900 dark:text-white">{{ row.name }}</div>
                <div class="text-xs text-slate-400">{{ row.employee_number }}</div>
            </template>
            <template #cell-overridden="{ row }">
                <Badge :color="row.overridden ? 'purple' : 'slate'">{{ row.overridden ? 'Override' : 'Default' }}</Badge>
            </template>
            <template #actions="{ row }">
                <button class="btn-ghost !p-1.5" title="Edit limits" @click="edit(row)"><Pencil class="size-4" /></button>
            </template>
        </DataTable>

        <Modal :open="modal.open" :title="`Claim limits · ${modal.row?.name ?? ''}`" @close="modal.open = false">
            <div class="space-y-4">
                <p class="text-sm text-slate-500 dark:text-slate-400">Leave a field empty to use the company default.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Annual limit (KES)</label>
                        <input v-model="form.annual_limit" type="number" min="0" step="0.01" class="input" :placeholder="settings ? String(settings.defaults.annual_limit) : ''" />
                        <p v-if="errors.annual_limit" class="field-error">{{ errors.annual_limit }}</p>
                    </div>
                    <div>
                        <label class="label">Monthly limit (KES)</label>
                        <input v-model="form.monthly_limit" type="number" min="0" step="0.01" class="input" :placeholder="settings ? String(settings.defaults.monthly_limit) : ''" />
                        <p v-if="errors.monthly_limit" class="field-error">{{ errors.monthly_limit }}</p>
                    </div>
                </div>
                <div>
                    <label class="label">Notes</label>
                    <input v-model="form.notes" class="input" maxlength="500" />
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="saveLimit"><Spinner v-if="saving" /> Save</button>
            </template>
        </Modal>
    </div>
</template>
