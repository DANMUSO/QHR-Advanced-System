<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Play, CheckCircle, DollarSign, RotateCcw, X, Download, Eye, FileText, Trash2, AlertTriangle, Mail, Plus, Pencil } from 'lucide-vue-next';
import api, { errorMessage, fieldErrors } from '@/lib/api';
import { formatDateTime, label } from '@/lib/format';
import { useUiStore } from '@/stores/ui';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';

const route = useRoute();
const router = useRouter();
const ui = useUiStore();
const run = ref(null);
const meta = ref({ statutory: null, audit: [], exceptions: [], cutoff: null });
const employees = ref([]);
const paginator = ref(null);
const loading = ref(true);
const linesLoading = ref(false);
const busy = ref('');
const rejectModal = ref(false);
const rejectReason = ref('');
const deleteOpen = ref(false);
const payslip = ref({ open: false, loading: false, data: null, line: null });

const statusColors = { draft: 'slate', calculated: 'blue', reviewed: 'yellow', approved: 'green', paid: 'emerald' };
const statusHelp = {
    draft: 'Not calculated yet. Click Calculate to include every active employee with a pay profile.',
    calculated: 'Check the figures and exceptions, then mark the run as reviewed to send it for approval.',
    reviewed: 'Awaiting approval by a different user with payroll approval rights.',
    approved: 'Approved and locked. Loan and advance deductions have been posted. Mark as paid once salaries are transferred.',
    paid: 'Paid and closed.',
};

const exportTypes = [
    { value: 'register', label: 'Payroll Register' },
    { value: 'paye', label: 'P10 / PAYE Schedule' },
    { value: 'nssf', label: 'NSSF Schedule' },
    { value: 'shif', label: 'SHIF Schedule' },
    { value: 'ahl', label: 'Housing Levy Schedule' },
    { value: 'helb', label: 'HELB Schedule' },
    { value: 'bank', label: 'Bank Transfer File' },
    { value: 'mpesa', label: 'M-Pesa Bulk File' },
    { value: 'journal', label: 'GL Journal' },
    { value: 'employer_cost', label: 'Employer Cost' },
];

const money = (v) => (v === null || v === undefined || Number.isNaN(Number(v)))
    ? '—'
    : 'KES ' + Number(v).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (v) => (v === null || v === undefined) ? '—' : Number(v).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const columns = [
    { key: 'employee', label: 'Employee' },
    { key: 'basic_pay', label: 'Basic Pay', align: 'right', format: num },
    { key: 'gross_pay', label: 'Gross', align: 'right', format: num },
    { key: 'paye', label: 'PAYE', align: 'right', format: num },
    { key: 'total_deductions', label: 'Deductions', align: 'right', format: num },
    { key: 'net_pay', label: 'Net Pay', align: 'right', format: num },
];

const cards = computed(() => run.value ? [
    ['Employees', String(run.value.employee_count ?? 0)],
    ['Total Gross', money(run.value.total_gross ?? 0)],
    ['Total Deductions', money(run.value.total_deductions ?? 0)],
    ['Total Net', money(run.value.total_net ?? 0)],
    ['Total PAYE', money(run.value.total_paye ?? 0)],
    ['Employer Cost', money(run.value.total_employer_cost ?? 0)],
] : []);
const can = computed(() => run.value?.can ?? {});

async function loadLines(page = 1) {
    linesLoading.value = true;
    try {
        const { data } = await api.get(`/payroll/runs/${route.params.id}/employees`, { params: { page } });
        employees.value = data.data;
        paginator.value = { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from, to: data.to };
    } finally { linesLoading.value = false; }
}

async function load() {
    try {
        const { data } = await api.get(`/payroll/runs/${route.params.id}`);
        run.value = data.data;
        meta.value = { statutory: data.statutory, audit: data.audit ?? [], exceptions: data.exceptions ?? [], cutoff: data.cutoff ?? null };
        await Promise.all([loadLines(paginator.value?.current_page ?? 1), loadAdjustments()]);
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

// Workflow endpoints: POST /payroll/runs/{id}/calculate | review | reopen | approve | reject | mark-paid
const RUN_ACTIONS = ['calculate', 'review', 'reopen', 'approve', 'reject', 'mark-paid'];

async function action(endpoint, body = undefined) {
    if (!RUN_ACTIONS.includes(endpoint)) return false;
    busy.value = endpoint;
    try {
        const url = `/payroll/runs/${route.params.id}/` + endpoint;
        const { data } = await api.post(url, body);
        ui.toast(data.message ?? 'Done.');
        await load();
        return true;
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
        return false;
    } finally {
        busy.value = '';
    }
}

// Every workflow step that changes who can touch the run is confirmed first.
const confirmStep = ref(null);
const STEP_CONFIRM = {
    review: () => ({ title: 'Mark run as reviewed', confirmLabel: 'Mark reviewed',
        message: `Send '${run.value.title}' (${run.value.employee_count} employees, net ${money(run.value.total_net)}) for approval? It can no longer be edited unless it is returned.` }),
    reopen: () => ({ title: 'Return for changes', confirmLabel: 'Return run', reasonLabel: 'Reason (optional)', reasonRequired: false,
        message: `Send '${run.value.title}' back to calculated so the preparer can make changes? The review is cleared.` }),
    approve: () => ({ title: 'Approve payroll run', confirmLabel: 'Approve and lock',
        message: `Approve '${run.value.title}' for ${run.value.employee_count} employees (net ${money(run.value.total_net)})? The run is locked, loan and advance deductions are posted and employees are told their payslips are ready. This cannot be undone.` }),
    'mark-paid': () => ({ title: 'Mark run as paid', confirmLabel: 'Mark paid',
        message: `Confirm that net pay of ${money(run.value.total_net)} for '${run.value.title}' has been transferred to employees? The run is closed.` }),
};
const confirmProps = computed(() => (confirmStep.value ? STEP_CONFIRM[confirmStep.value]() : {}));

async function confirmAction(reason) {
    const step = confirmStep.value;
    const body = step === 'reopen' && reason?.trim() ? { reason: reason.trim() } : undefined;
    if (await action(step, body)) confirmStep.value = null;
}

async function reject() {
    if (!rejectReason.value.trim()) return;
    if (await action('reject', { reason: rejectReason.value })) {
        rejectModal.value = false;
        rejectReason.value = '';
    }
}

async function emailPayslips() {
    if (!confirm(`Email payslips to all ${run.value.employee_count} employees? Each will receive a PDF in their inbox.`)) return;
    busy.value = 'email';
    try {
        const { data } = await api.post(`/payroll/runs/${route.params.id}/email-payslips`);
        ui.toast(data.message);
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        busy.value = '';
    }
}

// ── one-off items (bonus, arrears, corrections) ─────────────
const adjustments = ref([]);
const components = ref([]);
const lookupEmployees = ref([]);
const adjModal = ref({ open: false, row: null });
const adjForm = ref({});
const adjErrors = ref({});
const adjDelete = ref(null);

async function loadAdjustments() {
    if (!can.value.edit && !run.value?.can?.calculate) { adjustments.value = []; return; }
    try { const { data } = await api.get(`/payroll/runs/${route.params.id}/adjustments`); adjustments.value = data.data ?? []; }
    catch { adjustments.value = []; }
}

async function openAdjustment(row = null) {
    adjErrors.value = {};
    adjForm.value = row
        ? { employee_id: row.employee_id, component_id: row.component?.id ?? '', amount: row.amount, description: row.description ?? '' }
        : { employee_id: '', component_id: '', amount: '', description: '' };
    adjModal.value = { open: true, row };
    try {
        if (!components.value.length) {
            const { data } = await api.get('/payroll/components');
            components.value = (data.data ?? data).filter((c) => c.active && c.category !== 'employer_contribution');
        }
        if (!lookupEmployees.value.length) {
            const { data } = await api.get('/lookups', { params: { only: 'employees' } });
            lookupEmployees.value = data.employees ?? [];
        }
    } catch { /* dropdowns stay empty */ }
}

async function saveAdjustment() {
    busy.value = 'adjustment'; adjErrors.value = {};
    try {
        const url = `/payroll/runs/${route.params.id}/adjustments` + (adjModal.value.row ? `/${adjModal.value.row.id}` : '');
        const { data } = adjModal.value.row ? await api.put(url, adjForm.value) : await api.post(url, adjForm.value);
        ui.toast(data.message ?? 'Saved.', data.message?.includes('cut-off') ? 'warning' : 'success');
        adjModal.value.open = false;
        await load();
    } catch (e) { adjErrors.value = fieldErrors(e); ui.toast(errorMessage(e), 'error'); }
    finally { busy.value = ''; }
}

async function removeAdjustment() {
    busy.value = 'adjustment';
    try {
        await api.delete(`/payroll/runs/${route.params.id}/adjustments/${adjDelete.value.id}`);
        ui.toast('One-off item removed. Recalculate the run to apply it.');
        adjDelete.value = null;
        await load();
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { busy.value = ''; }
}

// Payment files only exist once the run is approved.
const visibleExports = computed(() => exportTypes.filter((t) => !['bank', 'mpesa'].includes(t.value) || ['approved', 'paid'].includes(run.value?.status)));

async function destroy() {
    busy.value = 'delete';
    try {
        await api.delete(`/payroll/runs/${route.params.id}`);
        ui.toast('Payroll run deleted.');
        router.push('/payroll');
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { busy.value = ''; deleteOpen.value = false; }
}

async function exportFile(type) {
    try {
        const response = await api.get(`/payroll/runs/${route.params.id}/export/${type}`, { responseType: 'blob' });
        saveBlob(response.data, `payroll-${type}-run${route.params.id}.csv`);
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    }
}

function saveBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

async function openPayslip(row) {
    payslip.value = { open: true, loading: true, data: null, line: row };
    try {
        const { data } = await api.get(`/payroll/runs/${route.params.id}/employees/${row.id}`);
        payslip.value.data = data.data;
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
        payslip.value.open = false;
    } finally { payslip.value.loading = false; }
}

async function payslipPdf(row, download = false) {
    busy.value = `pdf-${row.id}`;
    try {
        const { data } = await api.get(`/payroll/runs/${route.params.id}/employees/${row.id}/pdf`, { responseType: 'blob' });
        const blob = new Blob([data], { type: 'application/pdf' });
        if (download) {
            const name = (row.employee?.employee_number || row.employee_name || row.id) + '';
            saveBlob(blob, `payslip-${run.value?.period ?? ''}-${name.replace(/[^A-Za-z0-9_-]+/g, '_')}.pdf`);
        } else {
            window.open(URL.createObjectURL(blob), '_blank');
        }
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { busy.value = ''; }
}

onMounted(load);
</script>

<template>
    <div>
        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <template v-else-if="run && run.id">
            <PageHeader :title="run.title" :subtitle="`${run.period_label} · pay date ${run.pay_date ?? '—'}`" back="/payroll">
                <template #actions>
                    <Badge :color="statusColors[run.status]">{{ run.status }}</Badge>

                    <button v-if="can.calculate" class="btn-secondary" :disabled="!!busy" @click="action('calculate')">
                        <Play class="size-4" /> {{ busy === 'calculate' ? 'Calculating…' : (run.status === 'draft' ? 'Calculate' : 'Recalculate') }}
                    </button>
                    <button v-if="can.review" class="btn-secondary" :disabled="!!busy" @click="confirmStep = 'review'">
                        <CheckCircle class="size-4" /> Mark Reviewed
                    </button>
                    <button v-if="can.reopen" class="btn-secondary" :disabled="!!busy" @click="confirmStep = 'reopen'">
                        <RotateCcw class="size-4" /> Return for changes
                    </button>
                    <button v-if="can.approve" class="btn-primary" :disabled="!!busy" @click="confirmStep = 'approve'">
                        <CheckCircle class="size-4" /> {{ busy === 'approve' ? 'Approving…' : 'Approve' }}
                    </button>
                    <button v-if="can.reject" class="btn-danger" :disabled="!!busy" @click="rejectModal = true">
                        <X class="size-4" /> Reject
                    </button>
                    <button v-if="can.mark_paid" class="btn-primary" :disabled="!!busy" @click="confirmStep = 'mark-paid'">
                        <DollarSign class="size-4" /> Mark Paid
                    </button>
                    <button v-if="can.email" class="btn-secondary" :disabled="!!busy" @click="emailPayslips">
                        <Mail class="size-4" /> {{ busy === 'email' ? 'Sending…' : (run.payslips_emailed_at ? 'Re-send payslips' : 'Email payslips') }}
                    </button>
                    <button v-if="can.delete" class="btn-ghost text-red-600" :disabled="!!busy" @click="deleteOpen = true">
                        <Trash2 class="size-4" />
                    </button>

                    <div v-if="can.export" class="relative group">
                        <button class="btn-secondary"><Download class="size-4" /> Export</button>
                        <div class="absolute right-0 top-full z-20 hidden w-52 rounded-lg border border-slate-200 bg-white py-1 shadow-lg group-hover:block group-focus-within:block dark:border-slate-700 dark:bg-slate-800">
                            <button v-for="t in visibleExports" :key="t.value"
                                class="block w-full px-4 py-2 text-left text-sm hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700"
                                @click="exportFile(t.value)">{{ t.label }}</button>
                        </div>
                    </div>
                </template>
            </PageHeader>

            <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-300">
                {{ statusHelp[run.status] }}
                <span v-if="can.own_pay_blocked" class="mt-1 block font-medium text-amber-700 dark:text-amber-300">
                    This run includes your own salary, so you cannot review, approve or mark it paid (conflict of interest). Another authorised user must do it.
                </span>
                <span v-else-if="run.status === 'reviewed' && !can.approve" class="block text-xs text-amber-700 dark:text-amber-300">
                    You cannot approve this run (segregation of duties: approver must differ from the preparer, calculator and reviewer, and hold payroll approval rights).
                </span>
                <span v-if="can.review_blocked_sod" class="block text-xs text-amber-700 dark:text-amber-300">
                    You calculated this run, so another payroll officer must review it (segregation of duties).
                </span>
                <span v-if="run.needs_recalculation" class="block font-medium text-amber-700 dark:text-amber-300">Inputs changed since the last calculation — recalculate before review.</span>
                <span v-if="meta.cutoff?.passed && can.edit" class="block text-xs text-amber-700 dark:text-amber-300">{{ meta.cutoff.message }}</span>
            </div>

            <!-- One-off items -->
            <div v-if="can.edit || adjustments.length" class="card mb-6 p-4">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">One-off items ({{ adjustments.length }})</h3>
                        <p class="text-xs text-slate-500">Bonuses, arrears and corrections for this run only. Recalculate after changes.</p>
                    </div>
                    <button v-if="can.edit" class="btn-secondary" :disabled="!!busy" @click="openAdjustment()"><Plus class="size-4" /> Add item</button>
                </div>
                <p v-if="!adjustments.length" class="text-sm text-slate-400">
                    {{ run.type === 'off_cycle' ? 'An off-cycle run pays only these items: add at least one, then calculate.' : 'No one-off items.' }}
                </p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="py-1 text-left">Employee</th><th class="py-1 text-left">Component</th><th class="py-1 text-left">Description</th><th class="py-1 text-right">Amount</th><th class="py-1" /></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="a in adjustments" :key="a.id">
                                <td class="py-2 text-slate-700 dark:text-slate-200">{{ a.employee_name }} <span class="text-xs text-slate-400">{{ a.employee_number }}</span></td>
                                <td class="py-2">
                                    {{ a.component?.name }}
                                    <Badge :color="a.component?.category === 'earning' ? 'green' : 'red'" class="ml-1">{{ label(a.component?.category) }}</Badge>
                                </td>
                                <td class="py-2 text-slate-500">{{ a.description ?? '—' }}</td>
                                <td class="py-2 text-right tabular-nums">{{ num(a.amount) }}</td>
                                <td class="whitespace-nowrap py-2 text-right">
                                    <template v-if="can.edit">
                                        <button class="btn-ghost !p-1.5" title="Edit" @click="openAdjustment(a)"><Pencil class="size-4" /></button>
                                        <button class="btn-ghost !p-1.5 text-red-500" title="Remove" @click="adjDelete = a"><Trash2 class="size-4" /></button>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <div v-for="[label, value] in cards" :key="label" class="card p-4">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ label }}</p>
                    <p class="mt-1 text-lg font-bold text-slate-900 dark:text-white">{{ value }}</p>
                </div>
            </div>

            <div v-if="meta.exceptions.length" class="card mb-6 p-4">
                <h3 class="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                    <AlertTriangle class="size-4 text-amber-500" /> Exceptions ({{ meta.exceptions.length }})
                </h3>
                <ul class="max-h-40 space-y-1 overflow-y-auto text-sm">
                    <li v-for="(x, i) in meta.exceptions" :key="i" class="text-slate-600 dark:text-slate-300">
                        <Badge :color="x.severity === 'error' ? 'red' : 'yellow'">{{ x.severity }}</Badge>
                        <span class="ml-2 font-medium">{{ x.employee_name }}</span> — {{ x.message }}
                    </li>
                </ul>
            </div>

            <DataTable :columns="columns" :rows="employees" :loading="linesLoading" :paginator="paginator" @page="loadLines">
                <template #cell-employee="{ row }">
                    <button class="text-left font-medium text-indigo-600 hover:underline dark:text-indigo-400" @click="openPayslip(row)">{{ row.employee?.name }}</button>
                    <span class="ml-2 text-xs text-slate-400">{{ row.employee?.department }}</span>
                </template>
                <template #actions="{ row }">
                    <button class="btn-ghost !p-1.5" title="Preview payslip" @click="openPayslip(row)"><Eye class="size-4" /></button>
                    <button class="btn-ghost !p-1.5" title="Open payslip PDF" :disabled="busy === `pdf-${row.id}`" @click="payslipPdf(row)"><FileText class="size-4" /></button>
                    <button class="btn-ghost !p-1.5" title="Download payslip PDF" :disabled="busy === `pdf-${row.id}`" @click="payslipPdf(row, true)"><Download class="size-4" /></button>
                </template>
            </DataTable>

            <div v-if="meta.audit.length" class="card mt-6 p-4">
                <h3 class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Audit trail</h3>
                <ul class="space-y-2 text-sm">
                    <li v-for="(a, i) in meta.audit" :key="i" class="flex flex-wrap justify-between gap-2 border-b border-slate-100 pb-2 last:border-0 dark:border-slate-800">
                        <span class="text-slate-700 dark:text-slate-200">{{ a.description }} <span class="text-slate-400">· {{ a.causer ?? 'System' }}</span></span>
                        <span class="text-xs text-slate-400">{{ formatDateTime(a.created_at) }}</span>
                    </li>
                </ul>
            </div>
        </template>
        <p v-else class="py-20 text-center text-slate-500">Payroll run not found.</p>

        <!-- Payslip preview -->
        <Modal :open="payslip.open" :title="payslip.data ? `Payslip · ${payslip.data.employee?.name}` : 'Payslip'" size="lg" @close="payslip.open = false">
            <div v-if="payslip.loading" class="flex justify-center py-10"><Spinner /></div>
            <div v-else-if="payslip.data" class="space-y-4 text-sm">
                <div class="grid grid-cols-2 gap-2 text-slate-600 dark:text-slate-300">
                    <div>{{ payslip.data.employee?.employee_number }} · {{ payslip.data.employee?.position ?? '—' }}</div>
                    <div class="text-right">{{ payslip.data.run?.period_label }}</div>
                    <div>{{ payslip.data.employee?.department }}</div>
                    <div class="text-right">{{ payslip.data.payment?.label }}</div>
                </div>
                <div v-for="[title, key] in [['Earnings', 'earnings'], ['Statutory deductions', 'statutory'], ['Other deductions', 'deductions'], ['Reimbursements (added to net pay)', 'reimbursements'], ['Employer contributions', 'employer']]" :key="key">
                    <template v-if="payslip.data[key]?.length">
                        <h4 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ title }}</h4>
                        <div v-for="(it, i) in payslip.data[key]" :key="i" class="flex justify-between gap-4 border-b border-slate-100 py-1 dark:border-slate-800">
                            <span class="text-slate-700 dark:text-slate-200">{{ it.name }}</span><span class="tabular-nums">{{ num(it.amount) }}</span>
                        </div>
                    </template>
                </div>
                <div class="grid grid-cols-3 gap-3 rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
                    <div><p class="text-xs text-slate-500">Gross</p><p class="font-semibold">{{ money(payslip.data.totals?.gross_pay) }}</p></div>
                    <div><p class="text-xs text-slate-500">Deductions</p><p class="font-semibold">{{ money(payslip.data.totals?.total_deductions) }}</p></div>
                    <div><p class="text-xs text-slate-500">Net pay</p><p class="font-semibold text-emerald-600 dark:text-emerald-400">{{ money(payslip.data.totals?.net_pay) }}</p></div>
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="payslip.open = false">Close</button>
                <button v-if="payslip.line" class="btn-secondary" @click="payslipPdf(payslip.line)"><FileText class="size-4" /> Open PDF</button>
                <button v-if="payslip.line" class="btn-primary" @click="payslipPdf(payslip.line, true)"><Download class="size-4" /> Download PDF</button>
            </template>
        </Modal>

        <!-- Reject modal -->
        <Modal :open="rejectModal" title="Reject Payroll Run" @close="rejectModal = false">
            <div>
                <label class="label">Reason <span class="text-red-500">*</span></label>
                <textarea v-model="rejectReason" rows="3" class="input" />
            </div>
            <template #footer>
                <button class="btn-secondary" @click="rejectModal = false">Cancel</button>
                <button class="btn-danger" :disabled="!!busy || !rejectReason.trim()" @click="reject">{{ busy === 'reject' ? 'Sending…' : 'Reject' }}</button>
            </template>
        </Modal>

        <!-- One-off item modal -->
        <Modal :open="adjModal.open" :title="adjModal.row ? 'Edit one-off item' : 'Add one-off item'" @close="adjModal.open = false">
            <div class="space-y-4">
                <div>
                    <label class="label">Employee</label>
                    <select v-model="adjForm.employee_id" class="input">
                        <option value="">— Select employee —</option>
                        <option v-for="e in lookupEmployees" :key="e.id" :value="e.id">{{ e.name }} ({{ e.employee_number }})</option>
                    </select>
                    <p v-if="adjErrors.employee_id" class="field-error">{{ adjErrors.employee_id }}</p>
                </div>
                <div>
                    <label class="label">Component</label>
                    <select v-model="adjForm.component_id" class="input">
                        <option value="">— Select component —</option>
                        <option v-for="c in components" :key="c.id" :value="c.id">{{ c.name }} ({{ label(c.category) }})</option>
                    </select>
                    <p v-if="adjErrors.component_id" class="field-error">{{ adjErrors.component_id }}</p>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Amount (KES)</label>
                        <input v-model="adjForm.amount" type="number" min="0.01" step="0.01" class="input" />
                        <p v-if="adjErrors.amount" class="field-error">{{ adjErrors.amount }}</p>
                    </div>
                    <div>
                        <label class="label">Description</label>
                        <input v-model="adjForm.description" type="text" class="input" placeholder="e.g. Q3 performance bonus" />
                    </div>
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="adjModal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="busy === 'adjustment'" @click="saveAdjustment">{{ busy === 'adjustment' ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>

        <ConfirmDialog :open="!!adjDelete" title="Remove one-off item" :message="`Remove ${adjDelete?.component?.name} for ${adjDelete?.employee_name}?`" confirm-label="Remove"
            confirm-class="btn-danger" :loading="busy === 'adjustment'" @confirm="removeAdjustment" @close="adjDelete = null" />

        <ConfirmDialog :open="!!confirmStep" v-bind="confirmProps" :loading="busy === confirmStep" @confirm="confirmAction" @close="confirmStep = null" />

        <ConfirmDialog :open="deleteOpen" title="Delete payroll run" :message="`Delete '${run?.title}'? This cannot be undone.`" confirm-label="Delete"
            confirm-class="btn-danger" :loading="busy === 'delete'" @confirm="destroy" @close="deleteOpen = false" />
    </div>
</template>
