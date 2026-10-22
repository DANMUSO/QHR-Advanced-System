<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Plus, Pause, Play, Check, X } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { formatDate, label } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import Badge from '@/components/ui/Badge.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import Tabs from '@/components/ui/Tabs.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const canManage = computed(() => auth.can('payroll.manage'));
const canDecideAdvances = computed(() => auth.can('payroll.manage') || auth.can('payroll.approve'));

const tab = ref('runs');
const tabs = computed(() => [
    { key: 'runs', label: 'Payroll Runs' },
    ...(canManage.value ? [{ key: 'loans', label: 'Loans' }] : []),
    ...(canDecideAdvances.value ? [{ key: 'advances', label: 'Salary Advances', count: advanceCounts.value.pending || null }] : []),
]);

const money = (v) => (v === null || v === undefined) ? '—' : Number(v).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const employees = ref([]);
async function loadEmployees() {
    if (employees.value.length) return;
    try { const { data } = await api.get('/lookups', { params: { only: 'employees' } }); employees.value = data.employees ?? []; } catch { /* dropdown stays empty */ }
}

// ── runs ──────────────────────────────────────────────────
const runs = ref([]);
const paginator = ref(null);
const loading = ref(false);
const createModal = ref(false);
const runForm = ref({});
const runErrors = ref({});
const creating = ref(false);
const leavers = ref([]);
const leaversLoading = ref(false);
const employeeSearch = ref('');

const reasons = [
    { value: 'bonus', label: 'Bonus' },
    { value: 'arrears', label: 'Arrears' },
    { value: 'correction', label: 'Correction' },
];
const runStatusColors = { draft: 'slate', calculated: 'blue', reviewed: 'yellow', approved: 'green', paid: 'emerald' };
const runCols = [
    { key: 'title', label: 'Period', sortable: true },
    { key: 'type', label: 'Type' },
    { key: 'pay_date', label: 'Pay Date' },
    { key: 'employee_count', label: 'Employees', align: 'right' },
    { key: 'total_gross', label: 'Gross (KES)', align: 'right', format: money },
    { key: 'total_net', label: 'Net Pay (KES)', align: 'right', format: money },
    { key: 'status', label: 'Status' },
];

const isFinalDues = computed(() => runForm.value.type === 'off_cycle' && runForm.value.off_cycle_reason === 'final_dues');
// "Final dues" is offered as its own run type; the API stores it as an off-cycle run with reason final_dues.
const runKind = computed({
    get: () => (isFinalDues.value ? 'final_dues' : runForm.value.type),
    set: (v) => {
        if (v === 'final_dues') { runForm.value.type = 'off_cycle'; runForm.value.off_cycle_reason = 'final_dues'; return; }
        runForm.value.type = v;
        if (runForm.value.off_cycle_reason === 'final_dues') runForm.value.off_cycle_reason = 'bonus';
    },
});
const reasonLabels = { bonus: 'Bonus', arrears: 'Arrears', correction: 'Correction', final_dues: 'Final dues (leaver)' };
const isOffCycle = computed(() => runForm.value.type === 'off_cycle' && !isFinalDues.value);
const selectedLeaver = computed(() => leavers.value.find((l) => l.id === Number(runForm.value.employee_id)) ?? null);
const filteredEmployees = computed(() => {
    const q = employeeSearch.value.trim().toLowerCase();
    return q ? employees.value.filter((e) => `${e.name} ${e.employee_number ?? ''}`.toLowerCase().includes(q)) : employees.value;
});

function newRun(preset = {}) {
    runErrors.value = {};
    employeeSearch.value = '';
    runForm.value = {
        period: new Date().toISOString().slice(0, 7), pay_date: '', type: 'regular', off_cycle_reason: 'bonus', notes: '', calculate: true,
        employee_ids: [], employee_id: '', notice_pay: '', leave_days: '', exit_date: '', ...preset,
    };
    createModal.value = true;
    loadEmployees();
    loadLeavers();
}

async function loadLeavers() {
    if (leavers.value.length || leaversLoading.value) return;
    leaversLoading.value = true;
    try { const { data } = await api.get('/payroll/leavers'); leavers.value = data.data ?? []; } catch { /* picker stays empty */ }
    finally { leaversLoading.value = false; }
}

// Final dues default to the leaver's exit month (also once the leaver list loads for a deep link).
watch(selectedLeaver, () => {
    if (isFinalDues.value && selectedLeaver.value?.exit_date) {
        runForm.value.exit_date = selectedLeaver.value.exit_date;
        runForm.value.period = selectedLeaver.value.exit_date.slice(0, 7);
    }
});

function toggleEmployee(id) {
    const ids = runForm.value.employee_ids;
    const i = ids.indexOf(id);
    i === -1 ? ids.push(id) : ids.splice(i, 1);
}

async function loadRuns(page = 1) {
    loading.value = true;
    try {
        const { data } = await api.get('/payroll/runs', { params: { page } });
        runs.value = data.data;
        paginator.value = { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from, to: data.to };
    } finally { loading.value = false; }
}

async function createRun() {
    creating.value = true; runErrors.value = {};
    const f = runForm.value;
    const payload = { period: f.period, pay_date: f.pay_date || null, type: f.type, notes: f.notes || null, calculate: f.calculate };
    if (f.type === 'off_cycle') {
        payload.off_cycle_reason = f.off_cycle_reason;
        if (isFinalDues.value) {
            Object.assign(payload, {
                employee_id: f.employee_id || null,
                notice_pay: f.notice_pay === '' ? null : f.notice_pay,
                leave_days: f.leave_days === '' ? null : f.leave_days,
                exit_date: f.exit_date || null,
            });
        } else {
            payload.employee_ids = f.employee_ids;
        }
    }
    try {
        const { data } = await api.post('/payroll/runs', payload);
        swal.toast(data.data.status === 'calculated' ? `Payroll run created and calculated (${data.data.employee_count} employees).` : 'Payroll run created. Add one-off items, then calculate.');
        createModal.value = false;
        router.push(`/payroll/runs/${data.data.id}`);
    } catch (e) { runErrors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); }
    finally { creating.value = false; }
}

// ── loans ─────────────────────────────────────────────────
const loans = ref([]);
const loanSummary = ref(null);
const loanPaginator = ref(null);
const loansLoading = ref(false);
const loanModal = ref(false);
const loanForm = ref({});
const loanErrors = ref({});
const savingLoan = ref(false);
const loanDetail = ref({ open: false, loading: false, data: null });
const loanBusy = ref('');

const loanStatusColors = { active: 'blue', paused: 'yellow', completed: 'green', settled: 'emerald' };
const loanCols = [
    { key: 'reference', label: 'Ref' },
    { key: 'employee_name', label: 'Employee', sortable: true },
    { key: 'description', label: 'Purpose' },
    { key: 'principal', label: 'Principal', align: 'right', format: money },
    { key: 'interest', label: 'Interest', align: 'right' },
    { key: 'total_repayable', label: 'Total repayable', align: 'right', format: money },
    { key: 'monthly_deduction', label: 'Next instalment', align: 'right', format: money },
    { key: 'balance', label: 'Balance', align: 'right', format: money },
    { key: 'progress', label: 'Paid' },
    { key: 'status', label: 'Status' },
];

async function loadLoans(page = 1) {
    loansLoading.value = true;
    try {
        const { data } = await api.get('/payroll/loans', { params: { page } });
        loans.value = data.data;
        loanSummary.value = data.summary ?? null;
        loanPaginator.value = { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from, to: data.to };
    } finally { loansLoading.value = false; }
}

function newLoan() {
    loanErrors.value = {};
    loanForm.value = { employee_id: '', principal: '', interest_method: 'flat', interest_rate: 0, instalments: 12, start_period: new Date().toISOString().slice(0, 7), description: '' };
    loanModal.value = true;
}

async function saveLoan() {
    savingLoan.value = true; loanErrors.value = {};
    try {
        const { data } = await api.post('/payroll/loans', loanForm.value);
        swal.toast(data.message ?? 'Loan recorded.'); loanModal.value = false; await loadLoans();
        loanDetail.value = { open: true, loading: false, data: data.data };
    } catch (e) { loanErrors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); }
    finally { savingLoan.value = false; }
}

async function openLoan(row) {
    loanDetail.value = { open: true, loading: true, data: null };
    try {
        const { data } = await api.get(`/payroll/loans/${row.id}`);
        loanDetail.value.data = data.data;
    } catch (e) { swal.toast(errorMessage(e), 'error'); loanDetail.value.open = false; }
    finally { loanDetail.value.loading = false; }
}

// pause | resume | settle
async function loanAction(kind) {
    const loan = loanDetail.value.data;
    if (!loan) return;
    if (kind === 'settle' && !window.confirm(`Settle ${loan.reference} now? Outstanding KES ${money(loan.balance)} will be recorded as repaid outside payroll.`)) return;
    loanBusy.value = kind;
    try {
        const body = kind === 'settle' ? { reason: 'Early settlement' } : {};
        const { data } = await api.post(`/payroll/loans/${loan.id}/${kind}`, body);
        swal.toast(data.message ?? 'Done.');
        loanDetail.value.data = data.data;
        await loadLoans(loanPaginator.value?.current_page ?? 1);
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { loanBusy.value = ''; }
}

// ── salary advances ───────────────────────────────────────
const advances = ref([]);
const advancePaginator = ref(null);
const advancesLoading = ref(false);
const advanceStatus = ref('pending');
const advanceCounts = ref({ pending: 0 });
const approveModal = ref({ open: false, row: null, recovery_period: '', repayment_months: 1, note: '' });
const rejectTarget = ref(null);
const advanceBusy = ref(false);
const advanceStatusColors = { pending: 'yellow', approved: 'blue', recovered: 'green', rejected: 'red', cancelled: 'slate' };
const advanceCols = [
    { key: 'employee_name', label: 'Employee' },
    { key: 'amount', label: 'Amount', align: 'right', format: money },
    { key: 'repayment_months', label: 'Repayment' },
    { key: 'balance', label: 'Outstanding', align: 'right', format: money },
    { key: 'recovery_period', label: 'Recovery from' },
    { key: 'reason', label: 'Reason', class: 'max-w-[16rem] truncate' },
    { key: 'status', label: 'Status' },
];

async function loadAdvances(page = 1) {
    advancesLoading.value = true;
    try {
        const { data } = await api.get('/payroll/advances', { params: { page, status: advanceStatus.value || undefined } });
        advances.value = data.data;
        advancePaginator.value = { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from, to: data.to };
        if (advanceStatus.value === 'pending') advanceCounts.value.pending = data.total;
    } finally { advancesLoading.value = false; }
}

async function countPendingAdvances() {
    if (!canDecideAdvances.value) return;
    try { const { data } = await api.get('/payroll/advances', { params: { status: 'pending', per_page: 1 } }); advanceCounts.value.pending = data.total; } catch { /* badge only */ }
}

function openApprove(row) {
    approveModal.value = { open: true, row, recovery_period: row.recovery_period ?? new Date().toISOString().slice(0, 7), repayment_months: row.repayment_months ?? 1, note: '' };
}

async function approveAdvance() {
    const m = approveModal.value;
    advanceBusy.value = true;
    try {
        const { data } = await api.post(`/payroll/advances/${m.row.id}/approve`, { recovery_period: m.recovery_period, repayment_months: Number(m.repayment_months), note: m.note || null });
        swal.toast(data.message ?? 'Approved.');
        approveModal.value.open = false;
        await loadAdvances(advancePaginator.value?.current_page ?? 1);
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { advanceBusy.value = false; }
}

async function rejectAdvance(reason) {
    advanceBusy.value = true;
    try {
        const { data } = await api.post(`/payroll/advances/${rejectTarget.value.id}/reject`, { reason });
        swal.toast(data.message ?? 'Rejected.');
        rejectTarget.value = null;
        await loadAdvances(advancePaginator.value?.current_page ?? 1);
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { advanceBusy.value = false; }
}

function switchTab(t) {
    tab.value = t;
    router.replace({ query: t === 'runs' ? {} : { tab: t } });
    if (t === 'runs') loadRuns();
    else if (t === 'loans') { loadLoans(); loadEmployees(); }
    else loadAdvances();
}

onMounted(() => {
    const wanted = route.query.tab;
    if (wanted && tabs.value.some((t) => t.key === wanted)) switchTab(wanted);
    else loadRuns();
    countPendingAdvances();
});

// Deep link from the Exit page (/payroll?final_dues=<employee_id>, also via the /payroll/runs redirect): open New Run
// pre-set to final dues for that employee. Watched so it also works when the page is already open or permissions load late.
watch([() => route.query.final_dues, canManage], ([id, manage]) => {
    if (!id || !manage) return;
    newRun({ type: 'off_cycle', off_cycle_reason: 'final_dues', employee_id: Number(id) });
    const { final_dues, ...rest } = route.query;
    router.replace({ query: rest });
}, { immediate: true });
</script>

<template>
    <div>
        <PageHeader title="Payroll">
            <template #actions>
                <button v-if="tab === 'runs' && canManage" class="btn-primary" @click="newRun()"><Plus class="size-4" /> New Run</button>
                <button v-else-if="tab === 'loans' && canManage" class="btn-primary" @click="newLoan"><Plus class="size-4" /> New Loan</button>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <DataTable v-if="tab === 'runs'" :columns="runCols" :rows="runs" :loading="loading" :paginator="paginator"
            clickable @page="loadRuns" @row-click="r => router.push(`/payroll/runs/${r.id}`)">
            <template #cell-status="{ row }">
                <Badge :color="runStatusColors[row.status]">{{ label(row.status) }}</Badge>
            </template>
            <template #cell-type="{ row }">
                {{ row.type === 'off_cycle' ? 'Off-cycle' : 'Regular' }}
                <span v-if="row.off_cycle_reason" class="block text-xs text-slate-400">{{ reasonLabels[row.off_cycle_reason] ?? row.off_cycle_reason }}</span>
            </template>
        </DataTable>

        <template v-else-if="tab === 'loans'">
            <div v-if="loanSummary" class="mb-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="card p-4"><p class="text-xs text-slate-500">Active loans</p><p class="text-lg font-bold text-slate-900 dark:text-white">{{ loanSummary.active }}</p></div>
                <div class="card p-4"><p class="text-xs text-slate-500">Paused</p><p class="text-lg font-bold text-slate-900 dark:text-white">{{ loanSummary.paused }}</p></div>
                <div class="card p-4"><p class="text-xs text-slate-500">Outstanding (incl. interest)</p><p class="text-lg font-bold text-slate-900 dark:text-white">KES {{ money(loanSummary.outstanding) }}</p></div>
                <button class="card p-4 text-left hover:ring-1 hover:ring-brand-300" @click="switchTab('advances')"><p class="text-xs text-slate-500">Pending advances</p><p class="text-lg font-bold text-slate-900 dark:text-white">{{ loanSummary.pending_advances }}</p></button>
            </div>
            <DataTable :columns="loanCols" :rows="loans" :loading="loansLoading" :paginator="loanPaginator"
                export-name="loans" clickable @page="loadLoans" @row-click="openLoan">
                <template #cell-interest="{ row }">
                    {{ money(row.total_interest) }}
                    <span class="block text-xs text-slate-400">{{ row.interest_rate }}% {{ label(row.interest_method) }}</span>
                </template>
                <template #cell-progress="{ row }">{{ row.paid_instalments }}/{{ row.instalments }}</template>
                <template #cell-status="{ row }">
                    <Badge :color="loanStatusColors[row.status] ?? 'slate'">{{ label(row.status) }}</Badge>
                </template>
            </DataTable>
        </template>

        <template v-else-if="tab === 'advances'">
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <button v-for="s in ['pending', 'approved', 'recovered', 'rejected', '']" :key="s || 'all'"
                    class="rounded-full px-3 py-1 text-sm font-medium transition"
                    :class="advanceStatus === s ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'"
                    @click="advanceStatus = s; loadAdvances()">{{ s ? s.charAt(0).toUpperCase() + s.slice(1) : 'All' }}</button>
            </div>
            <DataTable :columns="advanceCols" :rows="advances" :loading="advancesLoading" :paginator="advancePaginator" export-name="salary_advances" @page="loadAdvances">
                <template #cell-employee_name="{ row }">
                    <span class="font-medium text-slate-900 dark:text-white">{{ row.employee_name }}</span>
                    <span class="block text-xs text-slate-400">{{ row.employee?.employee_number }} · {{ formatDate(row.created_at) }}</span>
                </template>
                <template #cell-repayment_months="{ row }">
                    {{ row.repayment_months }} × KES {{ money(row.monthly_recovery) }}
                </template>
                <template #cell-status="{ row }">
                    <Badge :color="advanceStatusColors[row.status] ?? 'slate'">{{ label(row.status) }}</Badge>
                    <span v-if="row.decided_by" class="block text-xs text-slate-400">{{ row.decided_by }}</span>
                </template>
                <template #actions="{ row }">
                    <template v-if="row.status === 'pending' && row.employee?.id !== auth.user?.employee?.id">
                        <button class="btn-ghost !p-1.5 text-emerald-600" title="Approve" @click="openApprove(row)"><Check class="size-4" /></button>
                        <button class="btn-ghost !p-1.5 text-red-500" title="Reject" @click="rejectTarget = row"><X class="size-4" /></button>
                    </template>
                </template>
            </DataTable>
        </template>

        <!-- New Run modal -->
        <Modal :open="createModal" title="New Payroll Run" size="lg" @close="createModal = false">
            <div class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Type</label>
                        <select v-model="runKind" class="input">
                            <option value="regular">Regular (monthly)</option>
                            <option value="off_cycle">Off-cycle (bonus, arrears, correction)</option>
                            <option value="final_dues">Final dues (leaver)</option>
                        </select>
                    </div>
                    <div v-if="isOffCycle">
                        <label class="label">Reason</label>
                        <select v-model="runForm.off_cycle_reason" class="input">
                            <option v-for="r in reasons" :key="r.value" :value="r.value">{{ r.label }}</option>
                        </select>
                        <p v-if="runErrors.off_cycle_reason" class="field-error">{{ runErrors.off_cycle_reason }}</p>
                    </div>
                </div>

                <!-- Final dues -->
                <div v-if="isFinalDues" class="space-y-4 rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                    <div>
                        <label class="label">Leaver</label>
                        <select v-model="runForm.employee_id" class="input">
                            <option value="">{{ leaversLoading ? 'Loading…' : (leavers.length ? '— Select a leaver —' : 'No leavers') }}</option>
                            <option v-for="l in leavers" :key="l.id" :value="l.id">
                                {{ l.name }} ({{ l.employee_number }}) · {{ l.scheduled ? 'leaving' : 'left' }} {{ l.exit_date ?? '—' }}{{ l.final_dues_run ? ' · final dues run exists' : '' }}
                            </option>
                        </select>
                        <p v-if="runErrors.employee_id" class="field-error">{{ runErrors.employee_id }}</p>
                        <p v-if="runForm.employee_id && !leaversLoading && !selectedLeaver" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                            This employee has no recorded or scheduled exit, or no pay profile. Record the exit first, then prepare final dues.
                        </p>
                        <p v-if="selectedLeaver?.final_dues_run" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                            A final dues run already exists for this employee
                            (<RouterLink :to="`/payroll/runs/${selectedLeaver.final_dues_run.id}`" class="underline">open it</RouterLink>, {{ selectedLeaver.final_dues_run.status }}).
                        </p>
                        <div v-if="!leaversLoading && !leavers.length" class="mt-2 rounded-md bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                            No leavers yet. Employees appear here once an exit is recorded or scheduled for them (Employees → Exit) and they have a pay profile.
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="label">Last working day</label>
                            <input v-model="runForm.exit_date" type="date" class="input" />
                            <p v-if="runErrors.exit_date" class="field-error">{{ runErrors.exit_date }}</p>
                        </div>
                        <div>
                            <label class="label">Notice pay (KES)</label>
                            <input v-model="runForm.notice_pay" type="number" min="0" step="0.01" class="input" placeholder="0" />
                            <p v-if="runErrors.notice_pay" class="field-error">{{ runErrors.notice_pay }}</p>
                        </div>
                        <div>
                            <label class="label">Leave days to encash</label>
                            <input v-model="runForm.leave_days" type="number" min="0" step="0.5" class="input" placeholder="From leave balance" />
                            <p v-if="runErrors.leave_days" class="field-error">{{ runErrors.leave_days }}</p>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500">
                        Final dues pay the exit month's prorated salary (unless the regular run already paid it), leave encashment
                        (basic ÷ encashment divisor × days; blank uses the leave balance) and notice pay, and recover outstanding loans and advances in full.
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Period (month)</label>
                        <input v-model="runForm.period" type="month" class="input" />
                        <p v-if="runErrors.period" class="field-error">{{ runErrors.period }}</p>
                    </div>
                    <div>
                        <label class="label">Pay date <span class="text-xs font-normal text-slate-400">(optional — calendar / pay day)</span></label>
                        <input v-model="runForm.pay_date" type="date" class="input" />
                        <p v-if="runErrors.pay_date" class="field-error">{{ runErrors.pay_date }}</p>
                    </div>
                </div>

                <!-- Off-cycle employee picker -->
                <div v-if="isOffCycle">
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <label class="label !mb-0">Employees <span class="text-xs font-normal text-slate-400">({{ runForm.employee_ids.length }} selected)</span></label>
                        <button v-if="runForm.employee_ids.length" type="button" class="text-xs text-brand-600 hover:underline dark:text-brand-400" @click="runForm.employee_ids = []">Clear</button>
                    </div>
                    <input v-model="employeeSearch" type="search" class="input mb-2" placeholder="Search name or number…" />
                    <div class="max-h-48 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-700">
                        <label v-for="e in filteredEmployees" :key="e.id" class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800">
                            <input type="checkbox" class="rounded" :checked="runForm.employee_ids.includes(e.id)" @change="toggleEmployee(e.id)" />
                            <span class="text-slate-700 dark:text-slate-200">{{ e.name }}</span>
                            <span class="text-xs text-slate-400">{{ e.employee_number }}</span>
                        </label>
                        <p v-if="!filteredEmployees.length" class="px-3 py-2 text-sm text-slate-400">No employees found.</p>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Off-cycle runs pay only one-off items. Add bonus / arrears amounts on the run page before calculating; employees you add items for are included automatically.</p>
                </div>

                <div><label class="label">Notes</label><textarea v-model="runForm.notes" rows="2" class="input" /></div>
                <label v-if="!isFinalDues" class="flex items-center gap-2 text-sm">
                    <input v-model="runForm.calculate" type="checkbox" class="rounded" />
                    {{ isOffCycle ? 'Calculate immediately (uncheck to add one-off items first)' : 'Calculate immediately (all employees with a pay profile)' }}
                </label>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="createModal = false">Cancel</button>
                <button class="btn-primary" :disabled="creating || (isFinalDues && !runForm.employee_id)" @click="createRun">{{ creating ? 'Working…' : 'Create' }}</button>
            </template>
        </Modal>

        <!-- New Loan modal -->
        <Modal :open="loanModal" title="New Loan" @close="loanModal = false">
            <div class="space-y-4">
                <div>
                    <label class="label">Employee</label>
                    <select v-model="loanForm.employee_id" class="input">
                        <option value="">— Select employee —</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }} ({{ e.employee_number }})</option>
                    </select>
                    <p v-if="loanErrors.employee_id" class="field-error">{{ loanErrors.employee_id }}</p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="label">Principal (KES)</label>
                        <input v-model="loanForm.principal" type="number" min="100" class="input" />
                        <p v-if="loanErrors.principal" class="field-error">{{ loanErrors.principal }}</p>
                    </div>
                    <div>
                        <label class="label">Instalments (months)</label>
                        <input v-model="loanForm.instalments" type="number" min="1" max="120" class="input" />
                        <p v-if="loanErrors.instalments" class="field-error">{{ loanErrors.instalments }}</p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="label">Interest method</label>
                        <select v-model="loanForm.interest_method" class="input">
                            <option value="flat">Flat</option>
                            <option value="reducing">Reducing balance</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Interest rate (% p.a.)</label>
                        <input v-model="loanForm.interest_rate" type="number" step="0.1" min="0" class="input" />
                        <p v-if="loanErrors.interest_rate" class="field-error">{{ loanErrors.interest_rate }}</p>
                    </div>
                </div>
                <div>
                    <label class="label">First deduction month</label>
                    <input v-model="loanForm.start_period" type="month" class="input" />
                    <p v-if="loanErrors.start_period" class="field-error">{{ loanErrors.start_period }}</p>
                </div>
                <div>
                    <label class="label">Description</label>
                    <input v-model="loanForm.description" type="text" class="input" />
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="loanModal = false">Cancel</button>
                <button class="btn-primary" :disabled="savingLoan" @click="saveLoan">{{ savingLoan ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>

        <!-- Loan detail / schedule -->
        <Modal :open="loanDetail.open" :title="loanDetail.data ? `${loanDetail.data.reference} · ${loanDetail.data.employee_name}` : 'Loan'" size="xl" @close="loanDetail.open = false">
            <div v-if="loanDetail.loading" class="flex justify-center py-10"><Spinner /></div>
            <div v-else-if="loanDetail.data" class="space-y-4 text-sm">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div><p class="text-xs text-slate-500">Principal</p><p class="font-semibold">KES {{ money(loanDetail.data.principal) }}</p></div>
                    <div><p class="text-xs text-slate-500">Interest ({{ loanDetail.data.interest_rate }}% {{ label(loanDetail.data.interest_method) }})</p><p class="font-semibold">KES {{ money(loanDetail.data.total_interest) }}</p></div>
                    <div><p class="text-xs text-slate-500">Total repayable</p><p class="font-semibold">KES {{ money(loanDetail.data.total_repayable) }}</p></div>
                    <div><p class="text-xs text-slate-500">Balance (principal + interest)</p><p class="font-semibold text-indigo-600 dark:text-indigo-400">KES {{ money(loanDetail.data.balance) }}</p></div>
                </div>
                <p class="text-xs text-slate-500">
                    Repaid KES {{ money(loanDetail.data.amount_repaid) }} · outstanding principal KES {{ money(loanDetail.data.outstanding_principal) }}
                    + interest KES {{ money(loanDetail.data.outstanding_interest) }} · status
                    <Badge :color="loanStatusColors[loanDetail.data.status] ?? 'slate'">{{ label(loanDetail.data.status) }}</Badge>
                </p>
                <p v-if="loanDetail.data.status === 'paused'" class="rounded-md bg-amber-50 p-2 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                    Deductions are paused: payroll skips this loan until it is resumed (final dues still recover the balance).
                </p>
                <div class="max-h-80 overflow-auto rounded-lg border border-slate-200 dark:border-slate-700">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800">
                            <tr>
                                <th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Month</th>
                                <th class="px-3 py-2 text-right">Principal</th><th class="px-3 py-2 text-right">Interest</th>
                                <th class="px-3 py-2 text-right">Instalment</th><th class="px-3 py-2 text-right">Balance after</th>
                                <th class="px-3 py-2 text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="s in loanDetail.data.schedule" :key="s.number">
                                <td class="px-3 py-1.5">{{ s.number }}</td><td class="px-3 py-1.5">{{ s.period }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ money(s.principal) }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ money(s.interest) }}</td>
                                <td class="px-3 py-1.5 text-right font-medium tabular-nums">{{ money(s.amount) }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ money(s.balance_after) }}</td>
                                <td class="px-3 py-1.5"><Badge :color="s.status === 'pending' ? 'slate' : 'green'">{{ label(s.status) }}</Badge></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="loanDetail.open = false">Close</button>
                <template v-if="canManage">
                    <button v-if="loanDetail.data?.status === 'active'" class="btn-secondary" :disabled="!!loanBusy" @click="loanAction('pause')">
                        <Pause class="size-4" /> {{ loanBusy === 'pause' ? 'Pausing…' : 'Pause deductions' }}
                    </button>
                    <button v-if="loanDetail.data?.status === 'paused'" class="btn-secondary" :disabled="!!loanBusy" @click="loanAction('resume')">
                        <Play class="size-4" /> {{ loanBusy === 'resume' ? 'Resuming…' : 'Resume deductions' }}
                    </button>
                    <button v-if="['active', 'paused'].includes(loanDetail.data?.status)" class="btn-danger" :disabled="!!loanBusy" @click="loanAction('settle')">
                        {{ loanBusy === 'settle' ? 'Settling…' : 'Settle early' }}
                    </button>
                </template>
            </template>
        </Modal>

        <!-- Approve advance -->
        <Modal :open="approveModal.open" :title="`Approve advance · ${approveModal.row?.employee_name ?? ''}`" @close="approveModal.open = false">
            <div v-if="approveModal.row" class="space-y-4 text-sm">
                <p class="text-slate-600 dark:text-slate-300">
                    KES {{ money(approveModal.row.amount) }} requested. {{ approveModal.row.reason }}
                </p>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="label">First recovery month</label>
                        <input v-model="approveModal.recovery_period" type="month" class="input" />
                    </div>
                    <div>
                        <label class="label">Repayment (months)</label>
                        <input v-model="approveModal.repayment_months" type="number" min="1" max="6" class="input" />
                    </div>
                </div>
                <p class="text-xs text-slate-500">
                    Recovered as {{ approveModal.repayment_months }} monthly deduction(s) of about KES
                    {{ money(approveModal.row.amount / Math.max(1, Number(approveModal.repayment_months) || 1)) }}.
                </p>
                <div><label class="label">Note (optional)</label><textarea v-model="approveModal.note" rows="2" class="input" /></div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="approveModal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="advanceBusy" @click="approveAdvance">{{ advanceBusy ? 'Approving…' : 'Approve' }}</button>
            </template>
        </Modal>

        <ConfirmDialog :open="!!rejectTarget" title="Reject salary advance" :message="`Reject ${rejectTarget?.employee_name}'s request for KES ${money(rejectTarget?.amount)}?`"
            confirm-label="Reject" confirm-class="btn-danger" reason-label="Reason" :loading="advanceBusy" @confirm="rejectAdvance" @close="rejectTarget = null" />
    </div>
</template>
