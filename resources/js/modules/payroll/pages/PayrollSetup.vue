<script setup>
import { ref, computed, onMounted } from 'vue';
import { Plus, Pencil, Trash2, Users } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { label } from '@/lib/format';
import { useUiStore } from '@/stores/ui';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';

const ui = useUiStore();
const tab = ref('structures');
const tabs = [
    { key: 'structures', label: 'Pay Structures' },
    { key: 'components', label: 'Pay Components' },
    { key: 'statutory', label: 'Statutory Rates' },
    { key: 'calendar', label: 'Payroll Calendar' },
    { key: 'settings', label: 'Settings' },
    { key: 'calculator', label: 'Calculator' },
];

// ── settings ─────────────────────────────────────────────
const settings = ref(null);
const SETTING_FIELDS = [
    { key: 'pay_day', label: 'Pay day (day of month)', type: 'number', min: 1, max: 31, step: 1 },
    { key: 'cutoff_day', label: 'Input cut-off day', type: 'number', min: 1, max: 31, step: 1, help: 'Changes after this day are flagged on the run.' },
    { key: 'overtime_multiplier_normal', label: 'Overtime multiplier (weekday)', type: 'number', min: 1, max: 5, step: 0.05 },
    { key: 'overtime_multiplier_holiday', label: 'Overtime multiplier (rest day / holiday)', type: 'number', min: 1, max: 5, step: 0.05 },
    { key: 'overtime_monthly_hours', label: 'Monthly hours for OT rate', type: 'number', min: 1, max: 400, step: 1, help: 'Blank = hours per day × working days.' },
    { key: 'leave_encashment_divisor', label: 'Leave encashment divisor (days)', type: 'number', min: 1, max: 31, step: 1, help: 'Daily rate = basic ÷ divisor.' },
    { key: 'advance_max_percent', label: 'Salary advance limit (% of basic)', type: 'number', min: 0, max: 100, step: 1 },
    { key: 'variance_threshold', label: 'Variance flag threshold (%)', type: 'number', min: 0, max: 1000, step: 0.5, help: 'Lines changing more than this vs the previous run are flagged.' },
];
async function saveSettings() {
    saving.value = true; errors.value = {};
    const payload = { ...settings.value };
    if (payload.overtime_monthly_hours === '') payload.overtime_monthly_hours = null;
    try {
        const { data } = await api.put('/payroll/settings', payload);
        settings.value = data.data;
        ui.toast(data.message ?? 'Saved.');
    } catch (e) { errors.value = fieldErrors(e); ui.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}

// ── calculator (/payroll/preview) ────────────────────────
const calc = ref({ basic: 50000, allowances: 0, non_taxable: 0, pension_percent: 0, insurance_premium: 0, date: new Date().toISOString().slice(0, 10) });
const calcResult = ref(null);
const calcRows = [
    ['gross_pay', 'Gross pay'], ['nssf_employee', 'NSSF (employee)'], ['shif', 'SHIF'], ['ahl_employee', 'Housing levy'],
    ['pension_employee', 'Pension'], ['taxable_pay', 'Taxable pay'], ['tax_charged', 'Tax charged'], ['personal_relief', 'Personal relief'],
    ['insurance_relief', 'Insurance relief'], ['paye', 'PAYE'], ['total_deductions', 'Total deductions'], ['net_pay', 'Net pay'],
    ['nssf_employer', 'NSSF (employer)'], ['ahl_employer', 'Housing levy (employer)'], ['employer_cost', 'Employer cost'],
];
async function runCalculator() {
    saving.value = true;
    try { const { data } = await api.post('/payroll/preview', calc.value); calcResult.value = data.data; }
    catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}

// ── structure apply ──────────────────────────────────────
const applyModal = ref({ open: false, structure: null, employee_ids: [], effective_from: '', search: '' });
const profiles = ref([]);
async function openApply(row) {
    applyModal.value = { open: true, structure: row, employee_ids: [], effective_from: new Date().toISOString().slice(0, 8) + '01', search: '' };
    try { const { data } = await api.get('/payroll/profiles', { params: { profile: 'set', per_page: 200 } }); profiles.value = data.data ?? []; }
    catch { profiles.value = []; }
}
const filteredProfiles = computed(() => {
    const q = applyModal.value.search.trim().toLowerCase();
    return q ? profiles.value.filter((p) => `${p.name} ${p.employee_number ?? ''} ${p.department ?? ''}`.toLowerCase().includes(q)) : profiles.value;
});
function toggleApply(id) {
    const ids = applyModal.value.employee_ids;
    const i = ids.indexOf(id);
    i === -1 ? ids.push(id) : ids.splice(i, 1);
}
async function applyStructure() {
    saving.value = true;
    try {
        const m = applyModal.value;
        const { data } = await api.post(`/payroll/structures/${m.structure.id}/apply`, { employee_ids: m.employee_ids, effective_from: m.effective_from });
        ui.toast(data.message ?? 'Applied.');
        applyModal.value.open = false;
        await load();
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}

// ── shared ──────────────────────────────────────────────
const rows = ref([]);
const loading = ref(false);
const modal = ref({ open: false, row: null });
const form = ref({});
const errors = ref({});
const levels = ref([]);
const saving = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

// ── structures ───────────────────────────────────────────
const structureCols = [
    { key: 'name', label: 'Structure Name', sortable: true },
    { key: 'grade_name', label: 'Grade' },
    { key: 'basic_salary', label: 'Basic (KES)', align: 'right', format: v => v?.toLocaleString() },
    { key: 'components_count', label: 'Components', align: 'right' },
    { key: 'employee_count', label: 'Employees', align: 'right' },
];

// Default components carried by a structure ([{component_id, amount, rate}]); Apply assigns them to employees.
const allComponents = ref([]);
const structureComponentOptions = computed(() => allComponents.value.filter((c) => c.active
    && !['loan', 'advance', 'overtime', 'leave_encashment', 'notice_pay'].includes(c.role)));
async function loadAllComponents() {
    if (allComponents.value.length) return;
    try { const { data } = await api.get('/payroll/components'); allComponents.value = data.data ?? data; } catch { /* picker stays empty */ }
}
const componentById = (id) => allComponents.value.find((c) => c.id === Number(id));
function addStructureComponent() {
    form.value.components.push({ component_id: '', amount: '', rate: '' });
}
function structureComponentHint(row) {
    const c = componentById(row.component_id);
    if (!c) return '';
    if (c.calculation === 'formula') return `Formula: ${c.formula}`;
    if (c.calculation === 'fixed') return c.default_amount != null ? `Default KES ${Number(c.default_amount).toLocaleString()}` : 'No default amount: enter one';
    return c.default_rate != null ? `Default ${c.default_rate}% (${calcLabels[c.calculation]})` : 'No default rate: enter one';
}

// ── components ───────────────────────────────────────────
const typeColors = { earning: 'green', deduction: 'red', employer_contribution: 'blue' };
const categoryLabels = { earning: 'Earning', deduction: 'Deduction', employer_contribution: 'Employer contribution' };
const calcLabels = { fixed: 'Fixed amount', percent_of_basic: '% of basic', percent_of_gross: '% of gross', formula: 'Formula' };
const componentRoles = ref([]);
const componentCols = [
    { key: 'name', label: 'Component', sortable: true, class: 'max-w-[14rem] truncate' },
    { key: 'code', label: 'Code', class: 'whitespace-nowrap font-mono text-xs' },
    { key: 'category', label: 'Type', class: 'whitespace-nowrap' },
    { key: 'calculation', label: 'Calculation', format: v => calcLabels[v] ?? v, class: 'whitespace-nowrap' },
    { key: 'value', label: 'Value / Rate', class: 'max-w-[14rem] truncate' },
    { key: 'taxable', label: 'Taxable' },
    { key: 'active', label: 'Status' },
];
function componentValue(row) {
    if (row.calculation === 'formula') return row.formula || '—';
    if (row.calculation === 'fixed') return row.default_amount != null ? `KES ${Number(row.default_amount).toLocaleString()}` : 'Per employee';
    return row.default_rate != null ? `${row.default_rate}%` : '—';
}
function componentForm(row = null) {
    return {
        name: row?.name ?? '', code: row?.code ?? '', category: row?.category ?? 'earning', calculation: row?.calculation ?? 'fixed',
        default_amount: row?.default_amount ?? '', default_rate: row?.default_rate ?? '', formula: row?.formula ?? '',
        role: row?.role ?? '', taxable: row?.taxable ?? true, pensionable: row?.pensionable ?? false,
        recurring: row?.recurring ?? true, active: row?.active ?? true, gl_account: row?.gl_account ?? '',
    };
}

// ── statutory ────────────────────────────────────────────
const TYPE_LABELS = {
    paye_band: 'PAYE Tax Bands', nssf: 'NSSF', shif: 'SHIF',
    ahl: 'Affordable Housing Levy', helb: 'HELB', pension: 'Pension',
    personal_relief: 'Personal Relief', insurance_relief: 'Insurance Relief',
    pension_cap: 'Pension Cap (monthly, KES)', disability_exemption: 'Disability Exemption',
};
const statutorySections = [
    { key: 'paye', label: 'PAYE Tax Bands' },
    { key: 'nssf', label: 'NSSF Rates' },
    { key: 'shif', label: 'SHIF Rates' },
    { key: 'helb', label: 'HELB Deduction' },
    { key: 'nita', label: 'NITA Levy' },
    { key: 'housing_levy', label: 'Affordable Housing Levy' },
];
const statutoryRates = ref({});
const statutoryNotice = ref('');
const statutoryTypes = ref({});
const statutoryModal = ref({ open: false, rowId: null, label: '' });
const statutoryForm = ref({});
const STAT_FIELDS = ['type', 'name', 'lower_limit', 'upper_limit', 'rate', 'employer_rate', 'amount', 'cap', 'tax_deductible', 'effective_from', 'effective_to', 'notes'];
function openStatutory(row = null) {
    errors.value = {};
    const base = row ?? { type: 'nssf', name: '', tax_deductible: false, effective_from: new Date().toISOString().slice(0, 10) };
    statutoryForm.value = Object.fromEntries(STAT_FIELDS.map(f => [f, base[f] ?? (f === 'tax_deductible' ? false : '')]));
    statutoryModal.value = { open: true, rowId: row?.id ?? null, label: row ? row.name : 'new statutory rate' };
}

// ── calendar ─────────────────────────────────────────────
const calYear = ref(new Date().getFullYear());
const calModal = ref({ open: false, row: null });
const calForm = ref({});
const calCols = [
    { key: 'month_name', label: 'Month' },
    { key: 'cutoff_date', label: 'Cutoff Date' },
    { key: 'pay_date', label: 'Pay Date' },
    { key: 'status', label: 'Status' },
    { key: 'notes', label: 'Notes' },
];

// ── load ─────────────────────────────────────────────────
async function load() {
    loading.value = true;
    try {
        if (tab.value === 'structures') {
            const { data } = await api.get('/payroll/structures');
            rows.value = data.data ?? data;
        } else if (tab.value === 'components') {
            const { data } = await api.get('/payroll/components');
            rows.value = data.data ?? data;
            componentRoles.value = data.roles ?? [];
        } else if (tab.value === 'statutory') {
            const { data } = await api.get('/payroll/statutory');
            statutoryNotice.value = data.notice ?? '';
            statutoryTypes.value = data.types ?? {};
            const rows = data.data ?? [];
            statutoryRates.value = rows.reduce((acc, r) => {
                if (!acc[r.type]) acc[r.type] = [];
                acc[r.type].push(r);
                return acc;
            }, {});
        } else if (tab.value === 'calendar') {
            const { data } = await api.get('/payroll/calendar', { params: { year: calYear.value } });
            rows.value = data.data ?? data;
        } else if (tab.value === 'settings') {
            const { data } = await api.get('/payroll/settings');
            settings.value = data.data;
        }
    } finally {
        loading.value = false;
    }
}

function switchTab(t) { tab.value = t; load(); }

// ── structures / components CRUD ─────────────────────────
function openNew() {
    errors.value = {};
    if (tab.value === 'structures') {
        form.value = { name: '', employment_level_id: '', basic_salary: '', components: [] };
        loadAllComponents();
    } else {
        form.value = componentForm();
    }
    modal.value = { open: true, row: null };
}

function openEdit(row) {
    errors.value = {};
    form.value = tab.value === 'components'
        ? componentForm(row)
        : {
            name: row.name, employment_level_id: row.employment_level_id ?? '', basic_salary: row.basic_salary,
            components: (row.component_list ?? []).map((c) => ({ component_id: c.component_id, amount: c.amount ?? '', rate: c.rate ?? '' })),
        };
    if (tab.value === 'structures') loadAllComponents();
    modal.value = { open: true, row };
}

function componentPayload() {
    const f = { ...form.value };
    for (const k of ['code', 'default_amount', 'default_rate', 'formula', 'role', 'gl_account']) if (f[k] === '') f[k] = null;
    if (f.calculation === 'fixed') { f.default_rate = null; f.formula = null; }
    else if (f.calculation === 'formula') { f.default_amount = null; f.default_rate = null; }
    else { f.default_amount = null; f.formula = null; }
    return f;
}

async function save() {
    saving.value = true; errors.value = {};
    const base = tab.value === 'structures' ? '/payroll/structures' : '/payroll/components';
    const payload = tab.value === 'components' ? componentPayload() : {
        ...form.value,
        employment_level_id: form.value.employment_level_id || null,
        components: form.value.components.filter((c) => c.component_id).map((c) => ({
            component_id: Number(c.component_id), amount: c.amount === '' ? null : c.amount, rate: c.rate === '' ? null : c.rate,
        })),
    };
    try {
        modal.value.row
            ? await api.put(`${base}/${modal.value.row.id}`, payload)
            : await api.post(base, payload);
        ui.toast('Saved.');
        modal.value.open = false;
        await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally { saving.value = false; }
}

async function remove() {
    deleting.value = true;
    const base = tab.value === 'structures' ? '/payroll/structures' : '/payroll/components';
    try {
        await api.delete(`${base}/${deleteTarget.value.id}`);
        ui.toast('Deleted.');
        deleteTarget.value = null;
        await load();
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { deleting.value = false; }
}

// ── statutory ────────────────────────────────────────────
async function saveStatutory() {
    saving.value = true; errors.value = {};
    const payload = Object.fromEntries(Object.entries(statutoryForm.value).map(([k, v]) => [k, v === '' ? null : v]));
    try {
        statutoryModal.value.rowId
            ? await api.put(`/payroll/statutory/${statutoryModal.value.rowId}`, payload)
            : await api.post('/payroll/statutory', payload);
        ui.toast(statutoryModal.value.rowId ? 'Statutory rate updated.' : 'Statutory rate added.');
        statutoryModal.value.open = false;
        await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally { saving.value = false; }
}

// ── calendar ─────────────────────────────────────────────
async function generateCalendar() {
    try {
        await api.post('/payroll/calendar/generate', { year: calYear.value });
        ui.toast(`Calendar generated for ${calYear.value}.`);
        await load();
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
}

async function saveCalendar() {
    saving.value = true; errors.value = {};
    try {
        await api.put(`/payroll/calendar/${calModal.value.row.id}`, calForm.value);
        ui.toast('Updated.');
        calModal.value.open = false;
        await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally { saving.value = false; }
}

onMounted(async () => {
    load();
    try { levels.value = (await api.get('/lookups', { params: { only: 'levels' } })).data.levels ?? []; } catch { /* dropdown stays empty */ }
});
</script>

<template>
    <div>
        <PageHeader title="Payroll Setup">
            <template #actions>
                <template v-if="tab === 'structures' || tab === 'components'">
                    <button class="btn-primary" @click="openNew"><Plus class="size-4" /> Add</button>
                </template>
                <template v-if="tab === 'statutory'">
                    <button class="btn-primary" @click="openStatutory()"><Plus class="size-4" /> Add rate</button>
                </template>
                <template v-if="tab === 'calendar'">
                    <select v-model="calYear" class="input w-28" @change="load">
                        <option v-for="y in [new Date().getFullYear()-1, new Date().getFullYear(), new Date().getFullYear()+1]" :key="y" :value="y">{{ y }}</option>
                    </select>
                    <button class="btn-secondary" @click="generateCalendar">Generate {{ calYear }}</button>
                </template>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>

        <!-- Structures -->
        <template v-else-if="tab === 'structures'">
            <DataTable :columns="structureCols" :rows="rows" :loading="false" export-name="pay_structures">
                <template #cell-components_count="{ row }">
                    <span :title="(row.component_list ?? []).map((c) => c.name).join(', ')">{{ row.components_count }}</span>
                </template>
                <template #actions="{ row }">
                    <button class="btn-ghost !p-1.5" title="Apply to employees" @click="openApply(row)"><Users class="size-4" /></button>
                    <button class="btn-ghost !p-1.5" @click="openEdit(row)"><Pencil class="size-4" /></button>
                    <button class="btn-ghost !p-1.5 text-red-500" @click="deleteTarget = row"><Trash2 class="size-4" /></button>
                </template>
            </DataTable>
        </template>

        <!-- Settings -->
        <template v-else-if="tab === 'settings'">
            <div v-if="settings" class="card max-w-3xl p-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div v-for="f in SETTING_FIELDS" :key="f.key">
                        <label class="label">{{ f.label }}</label>
                        <input v-model="settings[f.key]" :type="f.type" :min="f.min" :max="f.max" :step="f.step" class="input" />
                        <p v-if="f.help" class="mt-1 text-xs text-slate-500">{{ f.help }}</p>
                        <p v-if="errors[f.key]" class="field-error">{{ errors[f.key] }}</p>
                    </div>
                    <div>
                        <label class="label">Emailed payslip password</label>
                        <select v-model="settings.payslip_password" class="input">
                            <option value="none">None</option>
                            <option value="national_id">Employee's national ID number</option>
                        </select>
                        <p class="mt-1 text-xs text-slate-500">Encrypts emailed payslip PDFs. Employees without an ID number receive an unprotected PDF.</p>
                    </div>
                </div>
                <div class="mt-5 flex justify-end">
                    <button class="btn-primary" :disabled="saving" @click="saveSettings">{{ saving ? 'Saving…' : 'Save settings' }}</button>
                </div>
            </div>
        </template>

        <!-- Calculator -->
        <template v-else-if="tab === 'calculator'">
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="card p-5">
                    <p class="mb-4 text-sm text-slate-500">Check PAYE, NSSF, SHIF and Housing Levy for a salary using the statutory rates in force on the chosen date.</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div><label class="label">Basic salary (KES)</label><input v-model="calc.basic" type="number" min="0" class="input" /></div>
                        <div><label class="label">Taxable allowances</label><input v-model="calc.allowances" type="number" min="0" class="input" /></div>
                        <div><label class="label">Non-taxable allowances</label><input v-model="calc.non_taxable" type="number" min="0" class="input" /></div>
                        <div><label class="label">Pension (% of basic)</label><input v-model="calc.pension_percent" type="number" min="0" max="30" class="input" /></div>
                        <div><label class="label">Insurance premium</label><input v-model="calc.insurance_premium" type="number" min="0" class="input" /></div>
                        <div><label class="label">Rates as of</label><input v-model="calc.date" type="date" class="input" /></div>
                    </div>
                    <div class="mt-5 flex justify-end">
                        <button class="btn-primary" :disabled="saving" @click="runCalculator">{{ saving ? 'Calculating…' : 'Calculate' }}</button>
                    </div>
                </div>
                <div class="card p-5">
                    <p v-if="!calcResult" class="text-sm text-slate-400">Results appear here.</p>
                    <table v-else class="w-full text-sm">
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="[k, label] in calcRows" :key="k" :class="['net_pay', 'paye', 'employer_cost'].includes(k) ? 'font-semibold' : ''">
                                <td class="py-1.5 text-slate-600 dark:text-slate-300">{{ label }}</td>
                                <td class="py-1.5 text-right tabular-nums text-slate-900 dark:text-white">{{ Number(calcResult[k] ?? 0).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <!-- Components -->
        <template v-else-if="tab === 'components'">
            <DataTable :columns="componentCols" :rows="rows" :loading="false" export-name="pay_components">
                <template #cell-category="{ row }">
                    <Badge :color="typeColors[row.category] ?? 'slate'">{{ categoryLabels[row.category] ?? row.category }}</Badge>
                </template>
                <template #cell-value="{ row }">{{ componentValue(row) }}</template>
                <template #cell-taxable="{ row }">
                    <Badge :color="row.taxable ? 'red' : 'slate'">{{ row.taxable ? 'Taxable' : 'Non-taxable' }}</Badge>
                </template>
                <template #cell-active="{ row }">
                    <Badge :color="row.active ? 'green' : 'slate'">{{ row.active ? 'Active' : 'Inactive' }}</Badge>
                </template>
                <template #actions="{ row }">
                    <button class="btn-ghost !p-1.5" @click="openEdit(row)"><Pencil class="size-4" /></button>
                    <button class="btn-ghost !p-1.5 text-red-500" @click="deleteTarget = row"><Trash2 class="size-4" /></button>
                </template>
            </DataTable>
        </template>

        <!-- Statutory Rates -->
        <template v-else-if="tab === 'statutory'">
            <div class="space-y-6">
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700/60 dark:bg-amber-900/20 dark:text-amber-200" role="note">
                    <p class="font-semibold">Confirm statutory rates before every payroll</p>
                    <p class="mt-1">{{ statutoryNotice || 'Rates are applied by effective date. Always confirm them against the official NSSF, KRA and SHA notices before running payroll.' }}</p>
                </div>
                <div v-for="(typeRows, type) in statutoryRates" :key="type" class="card overflow-x-auto p-5">
                    <h3 class="mb-3 font-semibold text-slate-900 dark:text-white">{{ TYPE_LABELS[type] ?? statutoryTypes[type] ?? label(type) }}</h3>
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="py-1 text-left">Name</th>
                                <th class="py-1 text-right">Lower</th>
                                <th class="py-1 text-right">Upper</th>
                                <th class="py-1 text-right">Rate %</th>
                                <th class="py-1 text-right">Amount</th>
                                <th class="py-1 text-right">Cap</th>
                                <th class="py-1 text-right">Employer %</th>
                                <th class="py-1 text-left pl-3">Effective</th>
                                <th class="py-1" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="r in typeRows" :key="r.id" :class="r.in_force ? '' : 'opacity-60'">
                                <td class="py-2">
                                    {{ r.name }}
                                    <Badge v-if="r.in_force" color="green" class="ml-1">In force</Badge>
                                    <p v-if="r.notes" class="text-xs text-slate-500">{{ r.notes }}</p>
                                </td>
                                <td class="py-2 text-right">{{ r.lower_limit?.toLocaleString() ?? '—' }}</td>
                                <td class="py-2 text-right">{{ r.upper_limit?.toLocaleString() ?? '—' }}</td>
                                <td class="py-2 text-right">{{ r.rate ?? '—' }}</td>
                                <td class="py-2 text-right">{{ r.amount?.toLocaleString() ?? '—' }}</td>
                                <td class="py-2 text-right">{{ r.cap?.toLocaleString() ?? '—' }}</td>
                                <td class="py-2 text-right">{{ r.employer_rate ?? '—' }}</td>
                                <td class="py-2 pl-3 whitespace-nowrap">{{ r.effective_from }} → {{ r.effective_to ?? 'open' }}</td>
                                <td class="py-2 text-right">
                                    <button class="btn-ghost !p-1" @click="openStatutory(r)">
                                        <Pencil class="size-3.5" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <!-- Calendar -->
        <template v-else-if="tab === 'calendar'">
            <DataTable :columns="calCols" :rows="rows" :loading="false">
                <template #cell-status="{ row }">
                    <Badge :color="row.status === 'paid' ? 'green' : row.status === 'processing' ? 'blue' : 'slate'">{{ label(row.status ?? 'scheduled') }}</Badge>
                </template>
                <template #actions="{ row }">
                    <button class="btn-ghost !p-1.5" @click="calModal = { open: true, row }; calForm = { cutoff_date: row.cutoff_date, pay_date: row.pay_date, notes: row.notes }">
                        <Pencil class="size-4" />
                    </button>
                </template>
            </DataTable>
        </template>

        <!-- Structure / Component modal -->
        <Modal :open="modal.open" :title="modal.row ? 'Edit' : 'Add'" :size="tab === 'structures' ? 'lg' : 'md'" @close="modal.open = false">
            <div class="space-y-4">
                <template v-if="tab === 'structures'">
                    <div>
                        <label class="label">Structure Name</label>
                        <input v-model="form.name" type="text" class="input" />
                        <p v-if="errors.name" class="mt-1 text-xs text-red-500">{{ errors.name }}</p>
                    </div>
                    <div>
                        <label class="label" for="setup-grade">Grade / employment level</label>
                        <select id="setup-grade" v-model="form.employment_level_id" class="input">
                            <option value="">— Any grade —</option>
                            <option v-for="l in levels" :key="l.id" :value="l.id">{{ l.name }}</option>
                        </select>
                        <p v-if="errors.employment_level_id" class="field-error">{{ errors.employment_level_id }}</p>
                    </div>
                    <div>
                        <label class="label">Basic Salary (KES)</label>
                        <input v-model="form.basic_salary" type="number" class="input" />
                        <p v-if="errors.basic_salary" class="mt-1 text-xs text-red-500">{{ errors.basic_salary }}</p>
                    </div>
                    <div>
                        <div class="mb-1 flex items-center justify-between gap-2">
                            <label class="label !mb-0">Pay components</label>
                            <button type="button" class="btn-ghost !px-2 !py-1 text-xs" @click="addStructureComponent"><Plus class="size-3.5" /> Add component</button>
                        </div>
                        <p class="mb-2 text-xs text-slate-500">Assigned to employees when you apply the structure. Leave amount / rate blank to use the component's default.</p>
                        <p v-if="!form.components?.length" class="rounded-md bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">No components yet.</p>
                        <div v-for="(c, i) in form.components" :key="i" class="mb-2 rounded-lg border border-slate-200 p-2 dark:border-slate-700">
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_7rem_6rem_auto] sm:items-center">
                                <select v-model="c.component_id" class="input" :aria-label="`Component ${i + 1}`">
                                    <option value="">— Select component —</option>
                                    <option v-for="opt in structureComponentOptions" :key="opt.id" :value="opt.id"
                                        :disabled="form.components.some((o, j) => j !== i && Number(o.component_id) === opt.id)">
                                        {{ opt.name }} ({{ categoryLabels[opt.category] ?? label(opt.category) }})
                                    </option>
                                </select>
                                <input v-model="c.amount" type="number" min="0" step="0.01" class="input" placeholder="Amount"
                                    :disabled="componentById(c.component_id) && componentById(c.component_id).calculation !== 'fixed'" aria-label="Amount (KES)" />
                                <input v-model="c.rate" type="number" min="0" max="100" step="0.01" class="input" placeholder="Rate %"
                                    :disabled="componentById(c.component_id) && !['percent_of_basic', 'percent_of_gross'].includes(componentById(c.component_id).calculation)" aria-label="Rate (%)" />
                                <button type="button" class="btn-ghost !p-1.5 text-red-500" title="Remove" @click="form.components.splice(i, 1)"><Trash2 class="size-4" /></button>
                            </div>
                            <p v-if="structureComponentHint(c)" class="mt-1 text-xs text-slate-400">{{ structureComponentHint(c) }}</p>
                            <p v-if="errors[`components.${i}.component_id`]" class="field-error">{{ errors[`components.${i}.component_id`] }}</p>
                        </div>
                    </div>
                </template>
                <template v-else>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="label">Name</label>
                            <input v-model="form.name" type="text" class="input" />
                            <p v-if="errors.name" class="mt-1 text-xs text-red-500">{{ errors.name }}</p>
                        </div>
                        <div>
                            <label class="label">Code <span class="text-xs font-normal text-slate-400">(optional — generated from name)</span></label>
                            <input v-model="form.code" type="text" class="input uppercase" placeholder="e.g. MEAL" />
                            <p v-if="errors.code" class="mt-1 text-xs text-red-500">{{ errors.code }}</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label">Type</label>
                            <select v-model="form.category" class="input">
                                <option value="earning">Earning</option>
                                <option value="deduction">Deduction</option>
                                <option value="employer_contribution">Employer contribution</option>
                            </select>
                            <p v-if="errors.category" class="mt-1 text-xs text-red-500">{{ errors.category }}</p>
                        </div>
                        <div>
                            <label class="label">Calculation</label>
                            <select v-model="form.calculation" class="input">
                                <option value="fixed">Fixed amount</option>
                                <option value="percent_of_basic">% of basic</option>
                                <option value="percent_of_gross">% of gross</option>
                                <option value="formula">Formula</option>
                            </select>
                            <p v-if="errors.calculation" class="mt-1 text-xs text-red-500">{{ errors.calculation }}</p>
                        </div>
                    </div>
                    <div v-if="form.calculation === 'fixed'">
                        <label class="label">Default amount (KES) <span class="text-xs font-normal text-slate-400">(can be overridden per employee)</span></label>
                        <input v-model="form.default_amount" type="number" min="0" step="0.01" class="input" />
                        <p v-if="errors.default_amount" class="mt-1 text-xs text-red-500">{{ errors.default_amount }}</p>
                    </div>
                    <div v-else-if="form.calculation === 'formula'">
                        <label class="label">Formula <span class="text-red-500">*</span></label>
                        <input v-model="form.formula" type="text" class="input font-mono" placeholder="e.g. basic * 0.1" />
                        <p v-if="errors.formula" class="mt-1 text-xs text-red-500">{{ errors.formula }}</p>
                    </div>
                    <div v-else>
                        <label class="label">Rate (%) <span class="text-red-500">*</span></label>
                        <input v-model="form.default_rate" type="number" min="0" max="100" step="0.01" class="input" />
                        <p v-if="errors.default_rate" class="mt-1 text-xs text-red-500">{{ errors.default_rate }}</p>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label">System role</label>
                            <select v-model="form.role" class="input">
                                <option value="">None</option>
                                <option v-for="r in componentRoles" :key="r" :value="r">{{ label(r) }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="label">GL account</label>
                            <input v-model="form.gl_account" type="text" class="input" />
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-x-5 gap-y-2">
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.taxable" type="checkbox" class="rounded" /> Taxable</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.pensionable" type="checkbox" class="rounded" /> Pensionable</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.recurring" type="checkbox" class="rounded" /> Recurring</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.active" type="checkbox" class="rounded" /> Active</label>
                    </div>
                </template>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>

        <!-- Statutory modal -->
        <Modal :open="statutoryModal.open" :title="statutoryModal.rowId ? `Edit ${statutoryModal.label}` : 'Add statutory rate'" @close="statutoryModal.open = false">
            <div class="space-y-4">
                <p class="rounded-md bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-900/20 dark:text-amber-200">
                    Confirm every rate against the official NSSF, KRA and SHA notices. To change a rate, add a new row with its effective date
                    (the previous open row of the same type is closed the day before) rather than editing history.
                </p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Type</label>
                        <select v-model="statutoryForm.type" class="input">
                            <option v-for="(label, key) in statutoryTypes" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <p v-if="errors.type" class="mt-1 text-xs text-red-500">{{ errors.type }}</p>
                    </div>
                    <div>
                        <label class="label">Name</label>
                        <input v-model="statutoryForm.name" type="text" class="input" />
                        <p v-if="errors.name" class="mt-1 text-xs text-red-500">{{ errors.name }}</p>
                    </div>
                    <div v-for="f in ['lower_limit', 'upper_limit', 'rate', 'employer_rate', 'amount', 'cap']" :key="f">
                        <label class="label">{{ f.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) }}{{ ['rate', 'employer_rate'].includes(f) ? ' (%)' : ' (KES)' }}</label>
                        <input v-model="statutoryForm[f]" type="number" min="0" step="0.01" class="input" />
                        <p v-if="errors[f]" class="mt-1 text-xs text-red-500">{{ errors[f] }}</p>
                    </div>
                    <div>
                        <label class="label">Effective from</label>
                        <input v-model="statutoryForm.effective_from" type="date" class="input" />
                        <p v-if="errors.effective_from" class="mt-1 text-xs text-red-500">{{ errors.effective_from }}</p>
                    </div>
                    <div>
                        <label class="label">Effective to</label>
                        <input v-model="statutoryForm.effective_to" type="date" class="input" />
                        <p v-if="errors.effective_to" class="mt-1 text-xs text-red-500">{{ errors.effective_to }}</p>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm"><input v-model="statutoryForm.tax_deductible" type="checkbox" class="rounded" /> Tax deductible</label>
                <div><label class="label">Notes / source</label><textarea v-model="statutoryForm.notes" rows="2" class="input" /></div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="statutoryModal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="saveStatutory">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>

        <!-- Calendar modal -->
        <Modal :open="calModal.open" :title="`Edit ${calModal.row?.month_name}`" @close="calModal.open = false">
            <div class="space-y-4">
                <div><label class="label">Cutoff Date</label><input v-model="calForm.cutoff_date" type="date" class="input" /></div>
                <div><label class="label">Pay Date</label><input v-model="calForm.pay_date" type="date" class="input" /></div>
                <div><label class="label">Notes</label><textarea v-model="calForm.notes" rows="2" class="input" /></div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="calModal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="saveCalendar">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>

        <!-- Apply structure -->
        <Modal :open="applyModal.open" :title="`Apply ${applyModal.structure?.name ?? 'structure'}`" size="lg" @close="applyModal.open = false">
            <div class="space-y-4">
                <p class="text-sm text-slate-500">Links the selected pay profiles to this structure and assigns its components from the effective date (open assignments of the same components are ended the day before). Basic salary is not changed.</p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><label class="label">Effective from</label><input v-model="applyModal.effective_from" type="date" class="input" /></div>
                    <div><label class="label">Search</label><input v-model="applyModal.search" type="search" class="input" placeholder="Name, number, department" /></div>
                </div>
                <div class="max-h-64 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-700">
                    <label v-for="p in filteredProfiles" :key="p.id" class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800">
                        <input type="checkbox" class="rounded" :checked="applyModal.employee_ids.includes(p.id)" @change="toggleApply(p.id)" />
                        <span class="text-slate-700 dark:text-slate-200">{{ p.name }}</span>
                        <span class="text-xs text-slate-400">{{ p.employee_number }} · {{ p.department ?? '—' }}</span>
                    </label>
                    <p v-if="!filteredProfiles.length" class="px-3 py-2 text-sm text-slate-400">No employees with a pay profile.</p>
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="applyModal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving || !applyModal.employee_ids.length || !applyModal.effective_from" @click="applyStructure">
                    {{ saving ? 'Applying…' : `Apply to ${applyModal.employee_ids.length}` }}
                </button>
            </template>
        </Modal>

        <ConfirmDialog :open="!!deleteTarget" title="Delete" :message="`Delete '${deleteTarget?.name}'?`" confirm-label="Delete" confirm-class="btn-danger" :loading="deleting" @confirm="remove" @close="deleteTarget = null" />
    </div>
</template>
