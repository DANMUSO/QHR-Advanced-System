<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Plus, X, Check } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { useAuthStore } from '@/stores/auth';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';
import SubmitClaim from '@/modules/claims/pages/SubmitClaim.vue';
import ApplyLeave from '@/modules/leave/pages/ApplyLeave.vue';
import PortalProfile from '../components/portal/PortalProfile.vue';
import PortalPayslips from '../components/portal/PortalPayslips.vue';
import PortalGoals from '../components/portal/PortalGoals.vue';
import PortalAppraisals from '../components/portal/PortalAppraisals.vue';
import PortalTraining from '../components/portal/PortalTraining.vue';
import PortalDocuments from '../components/portal/PortalDocuments.vue';
import PortalRequests from '../components/portal/PortalRequests.vue';
import PortalGrievances from '../components/portal/PortalGrievances.vue';
import PortalRecognition from '../components/portal/PortalRecognition.vue';
import PortalSuggestions from '../components/portal/PortalSuggestions.vue';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const hasEmployee = !!auth.user?.employee;
const tabs = [
    { key: 'profile',     label: 'Profile' },
    { key: 'leave',       label: 'Leave' },
    { key: 'payslips',    label: 'Payslips' },
    { key: 'advances',    label: 'Salary Advance' },
    { key: 'goals',       label: 'Goals & KPIs' },
    { key: 'appraisals',  label: 'Appraisals' },
    { key: 'training',    label: 'Training' },
    { key: 'expenses',    label: 'Expenses' },
    { key: 'claims',      label: 'Medical Claims' },
    { key: 'documents',   label: 'Documents' },
    { key: 'requests',    label: 'Requests' },
    { key: 'recognition', label: 'Recognition' },
    { key: 'grievances',  label: 'Grievances' },
    { key: 'suggestions', label: 'Suggestion Box' },
];
// Deep links (notifications) open a tab: /portal?tab=requests
const tab = ref(tabs.some((t) => t.key === route.query.tab) ? route.query.tab : 'profile');

// ── leave ─────────────────────────────────────────────────
const leaveTab = ref('requests');
const leaveTabs = [{ key: 'requests', label: 'My Requests' }, { key: 'handovers', label: 'Handovers' }];
const balances = ref([]);
const requests = ref([]);
const leaveYears = ref([]);
const leaveYear = ref(new Date().getFullYear());
const leaveLoading = ref(false);
const cancelTarget = ref(null);
const cancelling = ref(false);
const handovers = ref([]);
const handoverLoading = ref(false);
const handoverModal = ref({ open: false, request: null, approve: true });
const handoverReason = ref('');
const handoverProcessing = ref(false);
const statusColors = { pending_handover: 'yellow', pending_supervisor: 'blue', approved: 'green', rejected: 'red', handover_rejected: 'orange', cancelled: 'slate', recalled: 'purple' };

async function loadLeave() {
    leaveLoading.value = true;
    try {
        const [bal, req] = await Promise.all([
            api.get('/leave/my-balances', { params: { year: leaveYear.value } }),
            api.get('/leave/my-requests', { params: { year: leaveYear.value } }),
        ]);
        balances.value = bal.data.data; requests.value = req.data.data; leaveYears.value = req.data.years;
    } finally { leaveLoading.value = false; }
}
async function loadHandovers() {
    handoverLoading.value = true;
    try { const { data } = await api.get('/leave/handover-requests'); handovers.value = data.data; }
    finally { handoverLoading.value = false; }
}
function switchLeaveTab(t) { leaveTab.value = t; t === 'requests' ? loadLeave() : loadHandovers(); }
async function cancelLeave(row) {
    if (!await swal.confirm({ title: 'Cancel this leave request?', confirmLabel: 'Yes, Cancel', danger: true })) return;
    cancelling.value = true;
    try { await api.post(`/leave/requests/${row.id}/cancel`); swal.toast('Cancelled.'); cancelTarget.value = null; await loadLeave(); }
    catch (e) { swal.toast(errorMessage(e), 'error'); } finally { cancelling.value = false; }
}
async function decideHandover() {
    handoverProcessing.value = true;
    try {
        await api.post(`/leave/requests/${handoverModal.value.request.id}/handover-decide`, { approve: handoverModal.value.approve, reason: handoverReason.value });
        swal.toast(handoverModal.value.approve ? 'Accepted.' : 'Declined.'); handoverModal.value.open = false; await loadHandovers();
    } catch (e) { swal.toast(errorMessage(e), 'error'); } finally { handoverProcessing.value = false; }
}

// ── expenses ──────────────────────────────────────────────
const expenseTab = ref('expenses');
const expenseTabs = [{ key: 'expenses', label: 'Expenses' }, { key: 'travel', label: 'Travel' }];
const expenses = ref([]);
const expensesLoading = ref(false);
const expenseModal = ref(false);
const expenseForm = ref({});
const expenseErrors = ref({});
const expenseSaving = ref(false);
const expenseStatusColors = { draft: 'slate', submitted: 'blue', approved: 'green', rejected: 'red', paid: 'emerald', pending: 'yellow', completed: 'blue', repaid: 'blue' };
const expenseEndpoints = { expenses: '/expenses', travel: '/expenses/travel' };
const expenseDefaults = {
    expenses: { description: '', category: 'transport', amount: '', expense_date: '', notes: '' },
    travel:   { destination: '', purpose: '', departure_date: '', return_date: '', estimated_cost: '', notes: '' },
};
async function loadExpenses() {
    expensesLoading.value = true;
    try { const { data } = await api.get(expenseEndpoints[expenseTab.value]); expenses.value = data.data ?? data; }
    finally { expensesLoading.value = false; }
}
function switchExpenseTab(t) { expenseTab.value = t; loadExpenses(); }
async function saveExpense() {
    expenseSaving.value = true; expenseErrors.value = {};
    try { await api.post(expenseEndpoints[expenseTab.value], expenseForm.value); swal.toast('Submitted.'); expenseModal.value = false; await loadExpenses(); }
    catch (e) { expenseErrors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); } finally { expenseSaving.value = false; }
}

// ── salary advances (payroll module) ─────────────────────
const advances = ref([]);
const advancesLoading = ref(false);
const advanceModal = ref(false);
const advanceForm = ref({ amount: '', reason: '', repayment_months: 3 });
const advanceErrors = ref({});
const advanceSaving = ref(false);
const advanceStatusColors = { pending: 'yellow', approved: 'green', rejected: 'red', recovered: 'slate' };
async function loadAdvances() {
    advancesLoading.value = true;
    try { const { data } = await api.get('/payroll/my-advances'); advances.value = data.data ?? data; }
    finally { advancesLoading.value = false; }
}
async function requestAdvance() {
    advanceSaving.value = true; advanceErrors.value = {};
    try { await api.post('/payroll/advances/request', advanceForm.value); swal.toast('Advance request submitted.'); advanceModal.value = false; await loadAdvances(); }
    catch (e) { advanceErrors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); } finally { advanceSaving.value = false; }
}
async function cancelAdvance(adv) {
    if (!await swal.confirm({ title: 'Cancel this advance request?', danger: true })) return;
    try { await api.post(`/payroll/advances/${adv.id}/cancel`); swal.toast('Cancelled.'); await loadAdvances(); }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
}

// ── claims ────────────────────────────────────────────────
const claims = ref([]);
const claimsLoading = ref(false);
const claimStatusColors = { submitted: 'blue', hr_approved: 'yellow', paid: 'green', hr_rejected: 'red', finance_rejected: 'red', cancelled: 'slate' };
const claimModal = ref(false);
const applyLeaveModal = ref(false);
async function loadClaims() {
    claimsLoading.value = true;
    try { const { data } = await api.get('/claims'); claims.value = data.data ?? data; }
    finally { claimsLoading.value = false; }
}

// ── tab switch ────────────────────────────────────────────
// Tabs backed by a portal component load themselves when mounted.
const loaders = { leave: loadLeave, advances: loadAdvances, expenses: loadExpenses, claims: loadClaims };
function switchTab(t) {
    tab.value = t;
    loaders[t]?.();
    router.replace({ query: { ...route.query, tab: t === 'profile' ? undefined : t } });
}

onMounted(() => { if (hasEmployee) loaders[tab.value]?.(); });
</script>

<template>
    <div>
        <PageHeader title="My Portal" />

        <div v-if="!hasEmployee" class="card mt-6 p-10 text-center">
            <p class="text-slate-500">Your account is not linked to an employee record.</p>
            <p class="mt-1 text-sm text-slate-400">My Portal is only available to employees. Contact HR if this is unexpected.</p>
        </div>

        <template v-else>
        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <PortalProfile v-if="tab === 'profile'" />

        <!-- ── LEAVE ── -->
        <template v-else-if="tab === 'leave'">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
                <Tabs class="min-w-0 flex-1" :tabs="leaveTabs" :model-value="leaveTab" @update:model-value="switchLeaveTab" />
                <button v-if="leaveTab === 'requests'" class="btn-primary" @click="applyLeaveModal = true"><Plus class="size-4" /> Apply</button>
            </div>
            <template v-if="leaveTab === 'requests'">
                <div v-if="leaveLoading" class="flex justify-center py-20"><Spinner /></div>
                <template v-else>
                    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        <div v-for="b in balances" :key="b.leave_type_id" class="card p-4">
                            <div class="mb-1 flex items-center gap-2"><span class="size-2.5 rounded-full" :style="{ background: b.color }" /><span class="text-xs font-medium text-slate-500">{{ b.name }}</span></div>
                            <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ b.projected ?? '∞' }}</p>
                            <p class="text-xs text-slate-400">{{ b.taken }} taken · {{ b.pending }} pending</p>
                        </div>
                    </div>
                    <div class="mb-4 flex items-center gap-3">
                        <label class="text-sm font-medium text-slate-600 dark:text-slate-400">Year:</label>
                        <select v-model="leaveYear" class="input w-28" @change="loadLeave"><option v-for="y in leaveYears" :key="y" :value="y">{{ y }}</option></select>
                    </div>
                    <div class="card overflow-x-auto">
                        <table class="w-full min-w-[640px] text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                                <tr><th class="px-4 py-3 text-left">Type</th><th class="px-4 py-3 text-left">Dates</th><th class="px-4 py-3 text-right">Days</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Submitted</th><th class="px-4 py-3" /></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="r in requests" :key="r.id" class="hover:bg-slate-50/70 dark:hover:bg-slate-800/30">
                                    <td class="px-4 py-3"><span class="flex items-center gap-2"><span class="size-2 rounded-full" :style="{ background: r.leave_type?.color }" />{{ r.leave_type?.name }}</span></td>
                                    <td class="px-4 py-3 text-slate-500">{{ r.start_date }} – {{ r.end_date }}</td>
                                    <td class="px-4 py-3 text-right font-medium">{{ r.days }}</td>
                                    <td class="px-4 py-3"><Badge :color="statusColors[r.status]">{{ r.status_label }}</Badge></td>
                                    <td class="px-4 py-3 text-xs text-slate-400">{{ r.submitted_at?.slice(0,10) }}</td>
                                    <td class="px-4 py-3 text-right"><button v-if="['pending_handover','pending_supervisor'].includes(r.status) || (r.status === 'approved' && r.start_date > new Date().toISOString().slice(0,10))" class="btn-ghost !p-1.5 text-red-500" :title="r.status === 'approved' ? 'Cancel approved leave' : 'Cancel request'" @click="cancelLeave(r)"><X class="size-4" /></button></td>
                                </tr>
                                <tr v-if="!requests.length"><td colspan="6" class="px-4 py-10 text-center text-slate-400">No leave requests for {{ leaveYear }}.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </template>
            <template v-else>
                <div v-if="handoverLoading" class="flex justify-center py-20"><Spinner /></div>
                <div v-else-if="!handovers.length" class="card p-10 text-center text-slate-400">No pending handover requests.</div>
                <div v-else class="space-y-3">
                    <div v-for="r in handovers" :key="r.id" class="card p-4">
                        <div class="flex flex-wrap items-start gap-4">
                            <Avatar :src="r.employee?.photo_url" :name="r.employee?.name" size="md" />
                            <div class="flex-1"><p class="font-semibold">{{ r.employee?.name }}</p><p class="text-sm text-slate-500">{{ r.leave_type?.name }} · {{ r.start_date }} – {{ r.end_date }}</p></div>
                            <div class="flex gap-2">
                                <button class="btn-success !py-1.5" @click="handoverModal = { open: true, request: r, approve: true }; handoverReason = ''"><Check class="size-4" /> Accept</button>
                                <button class="btn-danger !py-1.5" @click="handoverModal = { open: true, request: r, approve: false }; handoverReason = ''"><X class="size-4" /> Decline</button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </template>

        <PortalPayslips v-else-if="tab === 'payslips'" />

        <!-- ── SALARY ADVANCE ── -->
        <template v-else-if="tab === 'advances'">
            <div class="mb-4 flex justify-end">
                <button class="btn-primary" @click="advanceModal = true; advanceForm = { amount: '', reason: '', repayment_months: 3 }; advanceErrors = {}">
                    <Plus class="size-4" /> Request Advance
                </button>
            </div>
            <div v-if="advancesLoading" class="flex justify-center py-20"><Spinner /></div>
            <div v-else class="card overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                        <tr><th class="px-4 py-3 text-left">Amount (KES)</th><th class="px-4 py-3 text-left">Reason</th><th class="px-4 py-3 text-left">Repayment</th><th class="px-4 py-3 text-left">Recovered</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3" /></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="a in advances" :key="a.id" class="hover:bg-slate-50/70 dark:hover:bg-slate-800/30">
                            <td class="px-4 py-3 font-medium">{{ a.amount?.toLocaleString() }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ a.reason }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ a.repayment_months }} month{{ a.repayment_months > 1 ? 's' : '' }}</td>
                            <td class="px-4 py-3">{{ a.amount_recovered?.toLocaleString() ?? '0' }}</td>
                            <td class="px-4 py-3"><Badge :color="advanceStatusColors[a.status] ?? 'slate'">{{ a.status }}</Badge></td>
                            <td class="px-4 py-3 text-right">
                                <button v-if="a.status === 'pending'" class="btn-ghost !p-1.5 text-red-500" title="Cancel" @click="cancelAdvance(a)"><X class="size-4" /></button>
                            </td>
                        </tr>
                        <tr v-if="!advances.length"><td colspan="6" class="px-4 py-10 text-center text-slate-400">No salary advance requests yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <PortalGoals v-else-if="tab === 'goals'" />
        <PortalAppraisals v-else-if="tab === 'appraisals'" />
        <PortalTraining v-else-if="tab === 'training'" />

        <!-- ── EXPENSES ── -->
        <template v-else-if="tab === 'expenses'">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
                <Tabs class="min-w-0 flex-1" :tabs="expenseTabs" :model-value="expenseTab" @update:model-value="switchExpenseTab" />
                <button class="btn-primary" @click="expenseModal = true; expenseForm = { ...expenseDefaults[expenseTab] }; expenseErrors = {}">
                    <Plus class="size-4" /> {{ expenseTab === 'expenses' ? 'Submit Expense' : 'New Request' }}
                </button>
            </div>
            <div v-if="expensesLoading" class="flex justify-center py-20"><Spinner /></div>
            <div v-else class="card overflow-x-auto">
                <table class="w-full min-w-[560px] text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left">{{ expenseTab === 'expenses' ? 'Description' : 'Destination' }}</th>
                            <th class="px-4 py-3 text-left">Date</th>
                            <th class="px-4 py-3 text-right">Amount (KES)</th>
                            <th class="px-4 py-3 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="e in expenses" :key="e.id" class="hover:bg-slate-50/70 dark:hover:bg-slate-800/30">
                            <td class="px-4 py-3 font-medium">{{ e.description ?? e.destination }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ e.expense_date ?? e.departure_date }}</td>
                            <td class="px-4 py-3 text-right">{{ (e.amount ?? e.estimated_cost)?.toLocaleString() }}</td>
                            <td class="px-4 py-3"><Badge :color="expenseStatusColors[e.status] ?? 'slate'">{{ e.status }}</Badge></td>
                        </tr>
                        <tr v-if="!expenses.length"><td colspan="4" class="px-4 py-10 text-center text-slate-400">No records yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── CLAIMS ── -->
        <template v-else-if="tab === 'claims'">
            <div class="mb-4 flex justify-end"><button class="btn-primary" @click="claimModal = true"><Plus class="size-4" /> Submit Claim</button></div>
            <div v-if="claimsLoading" class="flex justify-center py-20"><Spinner /></div>
            <div v-else class="card overflow-x-auto">
                <table class="w-full min-w-[560px] text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                        <tr><th class="px-4 py-3 text-left">Reference</th><th class="px-4 py-3 text-left">Provider</th><th class="px-4 py-3 text-left">Date</th><th class="px-4 py-3 text-right">Amount (KES)</th><th class="px-4 py-3 text-left">Status</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="c in claims" :key="c.id" class="hover:bg-slate-50/70 dark:hover:bg-slate-800/30">
                            <td class="px-4 py-3 font-medium">{{ c.reference }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ c.provider_name }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ c.claim_date }}</td>
                            <td class="px-4 py-3 text-right">{{ c.total_amount?.toLocaleString() }}</td>
                            <td class="px-4 py-3"><Badge :color="claimStatusColors[c.status] ?? 'slate'">{{ c.status }}</Badge></td>
                        </tr>
                        <tr v-if="!claims.length"><td colspan="5" class="px-4 py-10 text-center text-slate-400">No claims submitted yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <PortalDocuments v-else-if="tab === 'documents'" />
        <PortalRequests v-else-if="tab === 'requests'" />
        <PortalRecognition v-else-if="tab === 'recognition'" />
        <PortalGrievances v-else-if="tab === 'grievances'" />
        <PortalSuggestions v-else-if="tab === 'suggestions'" />

        <!-- ── MODALS ── -->
        <Modal :open="handoverModal.open" :title="handoverModal.approve ? 'Accept Handover' : 'Decline Handover'" @close="handoverModal.open = false">
            <div><label class="label">{{ handoverModal.approve ? 'Note (optional)' : 'Reason (required)' }}</label><textarea v-model="handoverReason" rows="3" class="input" /></div>
            <template #footer>
                <button class="btn-secondary" @click="handoverModal.open = false">Cancel</button>
                <button :class="handoverModal.approve ? 'btn-primary' : 'btn-danger'" :disabled="handoverProcessing" @click="decideHandover">{{ handoverProcessing ? '…' : (handoverModal.approve ? 'Accept' : 'Decline') }}</button>
            </template>
        </Modal>

        <Modal :open="expenseModal" :title="expenseTab === 'expenses' ? 'Submit Expense' : 'New Travel Request'" @close="expenseModal = false">
            <div class="space-y-4">
                <template v-if="expenseTab === 'expenses'">
                    <div><label class="label">Description</label><input v-model="expenseForm.description" type="text" class="input" /><p v-if="expenseErrors.description" class="field-error">{{ expenseErrors.description }}</p></div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div><label class="label">Category</label><select v-model="expenseForm.category" class="input"><option value="transport">Transport</option><option value="accommodation">Accommodation</option><option value="meals">Meals</option><option value="other">Other</option></select></div>
                        <div><label class="label">Amount (KES)</label><input v-model="expenseForm.amount" type="number" class="input" /><p v-if="expenseErrors.amount" class="field-error">{{ expenseErrors.amount }}</p></div>
                    </div>
                    <div><label class="label">Date</label><input v-model="expenseForm.expense_date" type="date" class="input" /></div>
                </template>
                <template v-else>
                    <div><label class="label">Destination</label><input v-model="expenseForm.destination" type="text" class="input" /></div>
                    <div><label class="label">Purpose</label><input v-model="expenseForm.purpose" type="text" class="input" /></div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div><label class="label">Departure</label><input v-model="expenseForm.departure_date" type="date" class="input" /></div>
                        <div><label class="label">Return</label><input v-model="expenseForm.return_date" type="date" class="input" /></div>
                    </div>
                    <div><label class="label">Estimated Cost (KES)</label><input v-model="expenseForm.estimated_cost" type="number" class="input" /></div>
                </template>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="expenseModal = false">Cancel</button>
                <button class="btn-primary" :disabled="expenseSaving" @click="saveExpense">{{ expenseSaving ? 'Submitting…' : 'Submit' }}</button>
            </template>
        </Modal>

        <Modal :open="advanceModal" title="Request Salary Advance" @close="advanceModal = false">
            <div class="space-y-4">
                <div>
                    <label class="label">Amount (KES) <span class="text-rose-500">*</span></label>
                    <input v-model="advanceForm.amount" type="number" min="1" class="input" />
                    <p v-if="advanceErrors.amount" class="field-error">{{ advanceErrors.amount }}</p>
                </div>
                <div>
                    <label class="label">Reason <span class="text-rose-500">*</span></label>
                    <textarea v-model="advanceForm.reason" rows="3" class="input" />
                    <p v-if="advanceErrors.reason" class="field-error">{{ advanceErrors.reason }}</p>
                </div>
                <div>
                    <label class="label">Repayment Period</label>
                    <select v-model="advanceForm.repayment_months" class="input">
                        <option :value="1">1 month</option>
                        <option :value="2">2 months</option>
                        <option :value="3">3 months</option>
                        <option :value="6">6 months</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-400">Recovered in equal instalments from your payslip.</p>
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="advanceModal = false">Cancel</button>
                <button class="btn-primary" :disabled="advanceSaving" @click="requestAdvance">{{ advanceSaving ? 'Submitting…' : 'Submit Request' }}</button>
            </template>
        </Modal>

        <SubmitClaim :open="claimModal" @close="claimModal = false" @done="loadClaims" />
        <ApplyLeave :open="applyLeaveModal" @close="applyLeaveModal = false" @done="loadLeave" />
        </template>
    </div>
</template>
