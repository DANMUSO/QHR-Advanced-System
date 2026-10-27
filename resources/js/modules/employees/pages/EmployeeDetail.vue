<script setup>
import { ref, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Edit, Plus, Eye, ClipboardList, FileText, Download, Pencil, Trash2, LogOut, RotateCcw, ArrowRightLeft, ShieldCheck } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';
import EmployeeForm from './EmployeeForm.vue';
import ExitModal from '../components/ExitModal.vue';
import TransferModal from '../components/TransferModal.vue';
import ReinstateModal from '../components/ReinstateModal.vue';
import ProbationReviewCard from '@/modules/probation/components/ProbationReviewCard.vue';
import { useRouter } from 'vue-router';
import { formatDate } from '@/lib/format';
import { label as enumLabel, leavingLabel } from '../lib';

const route = useRoute();
const auth  = useAuthStore();
const ui    = useUiStore();

const employee  = ref(null);
const loading   = ref(true);
const activeTab = ref('personal');
const saving    = ref(false);
const editModal = ref(false);
const router    = useRouter();

// ── lifecycle actions (exit / re-instate / transfer) ─────────────────────────
const exitOpen      = ref(false);
const transferOpen  = ref(false);
const transferMode  = ref('move');
const reinstateOpen = ref(false);
function afterLifecycle(goTo = null) {
    exitOpen.value = false; transferOpen.value = false; reinstateOpen.value = false;
    if (goTo && String(goTo) !== String(route.params.id)) { router.push(`/employees/${goTo}`); return; }
    load();
    if (activeTab.value === 'lifecycle' || activeTab.value === 'history') loadTab(activeTab.value);
}

const tabs = [
    { key: 'personal',   label: 'Personal' },
    { key: 'employment', label: 'Employment' },
    { key: 'statutory',  label: 'Statutory' },
    { key: 'family',     label: 'Family & NOK' },
    { key: 'documents',  label: 'Documents' },
    { key: 'contracts',  label: 'Contracts' },
    { key: 'lifecycle',  label: 'Lifecycle' },
    { key: 'payroll',    label: 'Pay Profile' },
    { key: 'history',    label: 'History' },
];

// ── pay profile ───────────────────────────────────────────────────────────────
const payProfile = ref(null);
const payProfileLoading = ref(false);
const payProfileForm = ref({});
const payProfileErrors = ref({});
const savingProfile = ref(false);
const profileModal = ref(false);
const salaryModal = ref(false);
const salaryForm = ref({ basic_salary: '', effective_date: '', reason: 'increment', notes: '' });
const salaryErrors = ref({});
const savingSalary = ref(false);
const componentModal = ref({ open: false, row: null });
const componentForm = ref({});
const componentErrors = ref({});
const savingComponent = ref(false);

const SALARY_REASONS = [
    { value: 'initial', label: 'Initial' },
    { value: 'promotion', label: 'Promotion' },
    { value: 'increment', label: 'Increment' },
    { value: 'adjustment', label: 'Adjustment' },
];

async function loadPayProfile() {
    payProfileLoading.value = true;
    try {
        const { data } = await api.get(`/payroll/employees/${route.params.id}/profile`);
        payProfile.value = data;
    } catch (e) {
        if (e?.response?.status !== 403) ui.toast(errorMessage(e), 'error');
    } finally { payProfileLoading.value = false; }
}

function openProfileModal() {
    const p = payProfile.value?.profile;
    payProfileForm.value = {
        basic_salary: p ? '' : '',
        effective_date: employee.value?.date_of_joining ?? '',
        payment_method: p?.payment_method ?? 'bank',
        bank_name: p?.bank_name ?? '',
        bank_branch: p?.bank_branch ?? '',
        account_name: p?.account_name ?? '',
        account_number: p?.account_number ?? '',
        mpesa_phone: p?.mpesa_phone ?? '',
        pension_employee_percent: p?.pension_employee_percent ?? 0,
        pension_employer_percent: p?.pension_employer_percent ?? 0,
        disability_exempt: p?.disability_exempt ?? false,
        on_hold: p?.on_hold ?? false,
        notes: p?.notes ?? '',
    };
    if (!p) payProfileForm.value.basic_salary = '';
    payProfileErrors.value = {};
    profileModal.value = true;
}

async function saveProfile() {
    savingProfile.value = true; payProfileErrors.value = {};
    try {
        await api.put(`/payroll/employees/${route.params.id}/profile`, payProfileForm.value);
        ui.toast('Pay profile saved.');
        profileModal.value = false;
        await loadPayProfile();
    } catch (e) { payProfileErrors.value = fieldErrors(e); ui.toast(errorMessage(e), 'error'); }
    finally { savingProfile.value = false; }
}

async function saveSalary() {
    savingSalary.value = true; salaryErrors.value = {};
    try {
        await api.post(`/payroll/employees/${route.params.id}/salary`, salaryForm.value);
        ui.toast('Salary change recorded.');
        salaryModal.value = false;
        await loadPayProfile();
    } catch (e) { salaryErrors.value = fieldErrors(e); ui.toast(errorMessage(e), 'error'); }
    finally { savingSalary.value = false; }
}

async function saveComponent() {
    savingComponent.value = true; componentErrors.value = {};
    try {
        componentModal.value.row
            ? await api.put(`/payroll/employees/${route.params.id}/components/${componentModal.value.row.id}`, componentForm.value)
            : await api.post(`/payroll/employees/${route.params.id}/components`, componentForm.value);
        ui.toast('Component saved.');
        componentModal.value.open = false;
        await loadPayProfile();
    } catch (e) { componentErrors.value = fieldErrors(e); ui.toast(errorMessage(e), 'error'); }
    finally { savingComponent.value = false; }
}

async function removeComponent(comp) {
    try {
        await api.delete(`/payroll/employees/${route.params.id}/components/${comp.id}`);
        ui.toast('Component removed.');
        await loadPayProfile();
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
}

// ── tab-specific data ─────────────────────────────────────────────────────────
const tabRows    = ref([]);
const tabLoading = ref(false);
const modal      = ref({ open: false, row: null });
const form       = ref({});
const errors     = ref({});

// lifecycle sub-tab
const lifecycleTab  = ref('transfers');
const lifecycleTabs = [
    { key: 'transfers', label: 'Transfers' },
    { key: 'probation', label: 'Probation' },
    { key: 'exits',     label: 'Exits' },
];
const probationReview = ref(null);

// documents sub-tab
const docTab  = ref('files');
const docTabs = [
    { key: 'files',  label: 'Files' },
    { key: 'expiry', label: 'Expiry Alerts' },
];

// ── loaders ───────────────────────────────────────────────────────────────────
async function load() {
    loading.value = true;
    try {
        const { data } = await api.get(`/employees/${route.params.id}`);
        employee.value = data.data;
    } finally { loading.value = false; }
}

async function loadTab(t) {
    activeTab.value = t;
    if (t === 'payroll') { await loadPayProfile(); return; }
    if (['personal','employment','statutory','family'].includes(t)) return;
    tabLoading.value = true; tabRows.value = [];
    try {
        if (t === 'documents') {
            const { data } = await api.get(`/documents/employees/${route.params.id}`);
            tabRows.value = data.documents ?? data.data ?? [];
            docChecklist.value = data.checklist ?? [];
            docLetters.value = data.letters ?? [];
        } else if (t === 'contracts') {
            const { data } = await api.get('/employees/contracts', { params: { employee_id: route.params.id } });
            tabRows.value = data.data ?? data;
        } else if (t === 'lifecycle') {
            await loadLifecycleTab(lifecycleTab.value);
        } else if (t === 'history') {
            const { data } = await api.get(`/employees/${route.params.id}/history`);
            tabRows.value = data.data ?? [];
        }
    } finally { tabLoading.value = false; }
}

async function loadLifecycleTab(lt) {
    lifecycleTab.value = lt;
    tabLoading.value = true; tabRows.value = [];
    try {
        if (lt === 'transfers') {
            const { data } = await api.get('/employees/transfers', { params: { employee_id: route.params.id } });
            tabRows.value = data.data ?? data;
        } else if (lt === 'probation') {
            const { data } = await api.get(`/probation/employees/${route.params.id}`);
            probationReview.value = data.data ?? null;
        } else if (lt === 'exits') {
            const { data } = await api.get('/employees/exits', { params: { employee_id: route.params.id } });
            tabRows.value = data.data ?? data;
        }
    } finally { tabLoading.value = false; }
}

// ── letters ───────────────────────────────────────────────────────────────────
const letterTemplates = ref([]);
const letterModal     = ref(false);
const letterForm      = ref({ template: '', effective_date: '', notes: '' });
const letterErrors    = ref({});
const generatingLetter = ref(false);

async function openLetterModal() {
    if (!letterTemplates.value.length) {
        const { data } = await api.get('/employees/letter-templates');
        letterTemplates.value = data.data ?? data;
    }
    letterModal.value = true;
    letterForm.value  = { template: '', effective_date: '', notes: '' };
}

async function generateLetter() {
    generatingLetter.value = true; letterErrors.value = {};
    try {
        const { data } = await api.post('/employees/letters', { ...letterForm.value, employee_id: route.params.id });
        ui.toast('Letter generated.');
        if (data.url) window.open(data.url, '_blank');
        letterModal.value = false;
    } catch (e) { letterErrors.value = fieldErrors(e); ui.toast(errorMessage(e), 'error'); }
    finally { generatingLetter.value = false; }
}

// ── contracts ─────────────────────────────────────────────────────────────────
const contractStatusColors = { active: 'green', expired: 'red', terminated: 'slate', probation: 'yellow' };
const contractCols = [
    { key: 'contract_type', label: 'Type', format: (v) => enumLabel(v) },
    { key: 'start_date',    label: 'Start Date' },
    { key: 'end_date',      label: 'End Date' },
    { key: 'gross_salary',  label: 'Gross (KES)', align: 'right', format: v => v?.toLocaleString() },
    { key: 'status',        label: 'Status' },
];

async function saveContract() {
    saving.value = true; errors.value = {};
    try {
        const payload = { ...form.value, employee_id: route.params.id };
        modal.value.row
            ? await api.put(`/employees/contracts/${modal.value.row.id}`, payload)
            : await api.post('/employees/contracts', payload);
        ui.toast('Contract saved.'); modal.value.open = false;
        await loadTab('contracts');
    } catch (e) { errors.value = fieldErrors(e); ui.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}

// ── lifecycle ─────────────────────────────────────────────────────────────────
const transferTypeColors    = { transfer: 'blue', promotion: 'green', demotion: 'red', redeployment: 'yellow' };
const probationStatusColors = { on_probation: 'yellow', extended: 'orange', confirmed: 'green', terminated: 'red' };
const exitReasonColors      = { resignation: 'blue', termination: 'red', retirement: 'green', redundancy: 'yellow', death: 'slate' };

const transferCols = [
    { key: 'type',           label: 'Type' },
    { key: 'from_department',label: 'From Dept', format: v => (typeof v === 'object' ? v?.name : v) ?? '—' },
    { key: 'to_department',  label: 'To Dept',   format: v => (typeof v === 'object' ? v?.name : v) ?? '—' },
    { key: 'to_position',    label: 'To Position', format: v => (typeof v === 'object' ? v?.title : v) ?? '—' },
    { key: 'effective_date', label: 'Effective Date' },
    { key: 'status',         label: 'Status' },
];
const exitCols = [
    { key: 'exit_reason',     label: 'Reason', format: (v, r) => r?.exit_reason_label ?? v?.replace(/_/g,' ') ?? '—' },
    { key: 'notice_date',     label: 'Notice Date' },
    { key: 'exit_date',       label: 'Last Day' },
    { key: 'clearance_status',label: 'Clearance' },
    { key: 'status',          label: 'Status' },
];


// ── documents ─────────────────────────────────────────────────────────────────
const expiryCols = [
    { key: 'document_type', label: 'Document Type' },
    { key: 'document_number', label: 'Document #' },
    { key: 'expiry_date',   label: 'Expiry Date' },
    { key: 'days_remaining',label: 'Days Left', align: 'right' },
    { key: 'status',        label: 'Status' },
];
const uploadModal  = ref(false);
const uploadForm   = ref({ title: '', document_type_id: '', notes: '', file: null });
const uploadErrors = ref({});
const uploading    = ref(false);
const docChecklist = ref([]);
const docLetters   = ref([]); // HR-generated letters filed in the personal file

async function loadDocTab(dt) {
    docTab.value = dt;
    tabLoading.value = true; tabRows.value = [];
    try {
        if (dt === 'files') {
            const { data } = await api.get(`/documents/employees/${route.params.id}`);
            tabRows.value = data.documents ?? data.data ?? [];
            docChecklist.value = data.checklist ?? [];
            docLetters.value = data.letters ?? [];
        } else {
            const { data } = await api.get('/documents/expiry', { params: { employee_id: route.params.id } });
            tabRows.value = data.data ?? data;
        }
    } finally { tabLoading.value = false; }
}

async function uploadDoc() {
    uploading.value = true; uploadErrors.value = {};
    try {
        const fd = new FormData();
        Object.entries(uploadForm.value).forEach(([k, v]) => { if (v !== null && v !== '') fd.append(k, v); });
        await api.post(`/documents/employees/${route.params.id}`, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
        ui.toast('Document uploaded.'); uploadModal.value = false;
        await loadDocTab('files');
    } catch (e) { uploadErrors.value = fieldErrors(e); ui.toast(errorMessage(e), 'error'); }
    finally { uploading.value = false; }
}

// Deep links: ?tab=contracts (contract alerts), ?tab=lifecycle (probation decisions from the inbox).
onMounted(async () => {
    await load();
    const t = route.query.tab;
    if (t && tabs.some((x) => x.key === t)) loadTab(t);
    if (t === 'lifecycle' && employee.value?.probation_status && auth.can('probation.manage')) loadLifecycleTab('probation');
});
// Same component is reused when moving between profiles (e.g. after a re-instatement or transfer).
watch(() => route.params.id, (id, old) => { if (id && id !== old) { activeTab.value = 'personal'; load(); } });
</script>

<template>
    <div>
        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <template v-else-if="employee">
            <!-- Header card -->
            <div class="card mb-6 flex flex-wrap items-start gap-6 p-6">
                <Avatar :src="employee.photo_url" :name="employee.full_name" size="xl" />
                <div class="flex-1">
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-bold text-slate-900 dark:text-white">{{ employee.full_name }}</h1>
                        <Badge :color="employee.status === 'active' ? 'green' : employee.status === 'exited' ? 'red' : 'slate'">{{ enumLabel(employee.status) }}</Badge>
                        <Badge v-if="employee.leaving_on && employee.status !== 'exited'" color="red">{{ leavingLabel(employee.leaving_on) }}</Badge>
                    </div>
                    <p class="mt-1 text-slate-500">{{ employee.position?.title }} · {{ employee.department?.name }} · {{ employee.branch?.name }}</p>
                    <p class="text-sm text-slate-400">{{ employee.employee_number }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button v-if="employee.abilities?.transfer || (auth.can('employees.update') && ['active','suspended'].includes(employee.status))" class="btn-secondary" @click="transferMode = 'move'; transferOpen = true">
                        <ArrowRightLeft class="size-4" /> Transfer / promote
                    </button>
                    <button v-if="employee.abilities?.reinstate" class="btn-secondary" @click="reinstateOpen = true">
                        <RotateCcw class="size-4" /> Re-instate
                    </button>
                    <RouterLink v-if="auth.can('employees.update')" :to="{ path: '/employees/records', query: { tab: 'kyc' } }" class="btn-secondary">
                        <ShieldCheck class="size-4" /> KYC
                    </RouterLink>
                    <button v-if="employee.abilities?.exit" class="btn-danger" @click="exitOpen = true">
                        <LogOut class="size-4" /> Exit
                    </button>
                    <button v-if="auth.can('employees.update')" class="btn-secondary" @click="openLetterModal">
                        <FileText class="size-4" /> Generate Letter
                    </button>
                    <button v-if="auth.can('employees.update')" class="btn-primary" @click="editModal = true">
                        <Edit class="size-4" /> Edit
                    </button>
                </div>
            </div>

            <Tabs :tabs="tabs" :model-value="activeTab" @update:model-value="loadTab" />

            <!-- ── PERSONAL ── -->
            <div v-if="activeTab === 'personal'" class="card mt-4 p-6">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="[label, value] in [['Email', employee.email],['Phone', employee.phone],['Gender', enumLabel(employee.gender)],['Date of Birth', formatDate(employee.date_of_birth)],['Marital Status', enumLabel(employee.marital_status)],['Residence', employee.residence],['Postal Address', employee.postal_address]]" :key="label">
                        <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ label }}</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ value || '—' }}</dd>
                    </div>
                </dl>
            </div>

            <!-- ── EMPLOYMENT ── -->
            <div v-else-if="activeTab === 'employment'" class="card mt-4 p-6">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="[label, value] in [['Branch', employee.branch?.name || '—'],['Department', employee.department?.name || '—'],['Position', employee.position?.title || '—'],['Level', employee.level?.name || '—'],['Date of Joining', formatDate(employee.date_of_joining)],['Contract Type', enumLabel(employee.contract_type)],['Contract End', employee.contract_end_date ? formatDate(employee.contract_end_date) : '—'],['Probation Status', enumLabel(employee.probation_status)],['Probation End', employee.probation_end_date ? formatDate(employee.probation_end_date) : '—'],['Probation Months', employee.probation_months ?? '—'],['Primary Supervisor', employee.primary_supervisor?.full_name || '—'],['Secondary Supervisor', employee.secondary_supervisor?.full_name || '—']]" :key="label">
                        <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ label }}</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ value || '—' }}</dd>
                    </div>
                </dl>
            </div>

            <!-- ── STATUTORY ── -->
            <div v-else-if="activeTab === 'statutory'" class="card mt-4 p-6">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="[label, value] in [['National ID', employee.national_id],['KRA PIN', employee.kra_pin],['NSSF No.', employee.nssf_number],['SHIF No.', employee.shif_number],['HELB No.', employee.helb_number]]" :key="label">
                        <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ label }}</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ value || '—' }}</dd>
                    </div>
                </dl>
            </div>

            <!-- ── FAMILY ── -->
            <div v-else-if="activeTab === 'family'" class="card mt-4 p-6">
                <h3 class="mb-3 font-semibold text-slate-900 dark:text-white">Next of Kin</h3>
                <div v-if="employee.next_of_kin?.length" class="space-y-3">
                    <div v-for="nok in employee.next_of_kin" :key="nok.id" class="rounded-lg border border-slate-100 p-4 dark:border-slate-800">
                        <p class="font-medium">{{ nok.name }} <span class="text-sm text-slate-400">({{ nok.relationship }})</span></p>
                        <p class="text-sm text-slate-500">{{ nok.phone }}</p>
                    </div>
                </div>
                <p v-else class="text-slate-400">No next of kin recorded.</p>
            </div>

            <!-- ── DOCUMENTS ── -->
            <template v-else-if="activeTab === 'documents'">
                <div class="mt-4 mb-3 flex items-center justify-between">
                    <Tabs :tabs="docTabs" :model-value="docTab" @update:model-value="loadDocTab" />
                    <button v-if="docTab === 'files'" class="btn-primary" @click="uploadModal = true; uploadForm = { title: '', document_type_id: '', notes: '', file: null }">
                        <Plus class="size-4" /> Upload
                    </button>
                </div>
                <div v-if="tabLoading" class="flex justify-center py-10"><Spinner /></div>
                <template v-else-if="docTab === 'files'">
                    <div v-if="docChecklist.length" class="mb-3 flex flex-wrap gap-2">
                        <span v-for="item in docChecklist" :key="item.document_type_id"
                            class="rounded-full border px-3 py-1 text-xs"
                            :class="item.status === 'present' ? 'border-green-200 bg-green-50 text-green-700' : item.status === 'expired' ? 'border-orange-200 bg-orange-50 text-orange-700' : 'border-red-200 bg-red-50 text-red-700'">
                            {{ item.name }}
                        </span>
                    </div>
                    <div class="card overflow-hidden">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                                <tr><th class="px-4 py-3 text-left">Document</th><th class="px-4 py-3 text-left">Type</th><th class="px-4 py-3 text-left">Issued</th><th class="px-4 py-3 text-left">Expires</th><th class="px-4 py-3" /></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="doc in tabRows" :key="doc.id" class="hover:bg-slate-50/70 dark:hover:bg-slate-800/30">
                                    <td class="px-4 py-3 font-medium">{{ doc.title }}</td>
                                    <td class="px-4 py-3 text-slate-500">{{ doc.type ?? '—' }}</td>
                                    <td class="px-4 py-3 text-slate-500">{{ doc.issue_date ?? '—' }}</td>
                                    <td class="px-4 py-3"><span :class="doc.expiry_status === 'expired' ? 'text-red-500' : doc.expiry_status === 'expiring' ? 'text-orange-500' : 'text-slate-500'">{{ doc.expiry_date ?? '—' }}</span></td>
                                    <td class="px-4 py-3 text-right"><a :href="`${$base}/api/documents/employee-docs/${doc.id}/download`" target="_blank" class="btn-ghost !p-1.5"><Download class="size-4" /></a></td>
                                </tr>
                                <tr v-for="l in docLetters" :key="`letter-${l.id}`" class="hover:bg-slate-50/70 dark:hover:bg-slate-800/30">
                                    <td class="px-4 py-3 font-medium">{{ l.subject }} <span class="text-xs font-normal text-slate-400">{{ l.reference }}</span></td>
                                    <td class="px-4 py-3 text-slate-500">Letter · {{ l.template_name }}</td>
                                    <td class="px-4 py-3 text-slate-500">{{ formatDate(l.issued_at) }}</td>
                                    <td class="px-4 py-3 text-slate-500">—</td>
                                    <td class="px-4 py-3 text-right"><a :href="`${$base}${l.path}`" target="_blank" class="btn-ghost !p-1.5"><Download class="size-4" /></a></td>
                                </tr>
                                <tr v-if="!tabRows.length && !docLetters.length"><td colspan="5" class="px-4 py-10 text-center text-slate-400">No documents uploaded.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </template>
                <DataTable v-else :columns="expiryCols" :rows="tabRows" :loading="false">
                    <template #cell-days_remaining="{ row }">
                        <span :class="row.days_remaining <= 7 ? 'text-red-500 font-bold' : row.days_remaining <= 30 ? 'text-yellow-500 font-semibold' : ''">{{ row.days_remaining }}</span>
                    </template>
                    <template #cell-status="{ row }">
                        <Badge :color="row.days_remaining <= 0 ? 'red' : row.days_remaining <= 30 ? 'yellow' : 'green'">{{ row.days_remaining <= 0 ? 'Expired' : row.days_remaining <= 30 ? 'Expiring Soon' : 'Valid' }}</Badge>
                    </template>
                </DataTable>
            </template>

            <!-- ── CONTRACTS ── -->
            <template v-else-if="activeTab === 'contracts'">
                <div class="mt-4 mb-3 flex justify-end">
                    <button v-if="auth.can('employees.update')" class="btn-primary" @click="modal = { open: true, row: null }; form = { contract_type: 'permanent', start_date: '', end_date: '', gross_salary: '', notes: '' }; errors = {}">
                        <Plus class="size-4" /> New Contract
                    </button>
                </div>
                <div v-if="tabLoading" class="flex justify-center py-10"><Spinner /></div>
                <DataTable v-else :columns="contractCols" :rows="tabRows" :loading="false">
                    <template #cell-status="{ row }">
                        <Badge :color="contractStatusColors[row.status] ?? 'slate'">{{ enumLabel(row.status) }}</Badge>
                    </template>
                    <template #actions="{ row }">
                        <button class="btn-ghost !p-1.5" @click="modal = { open: true, row }; form = { ...row }; errors = {}"><Eye class="size-4" /></button>
                    </template>
                </DataTable>
            </template>

            <!-- ── LIFECYCLE ── -->
            <template v-else-if="activeTab === 'lifecycle'">
                <div class="mt-4 mb-3">
                    <Tabs :tabs="lifecycleTabs" :model-value="lifecycleTab" @update:model-value="loadLifecycleTab" />
                </div>
                <div v-if="tabLoading" class="flex justify-center py-10"><Spinner /></div>
                <!-- Transfers -->
                <DataTable v-else-if="lifecycleTab === 'transfers'" :columns="transferCols" :rows="tabRows" :loading="false">
                    <template #cell-type="{ row }"><Badge :color="transferTypeColors[row.type] ?? 'slate'">{{ enumLabel(row.type) }}</Badge></template>
                    <template #cell-status="{ row }"><Badge :color="row.status === 'approved' ? 'green' : 'yellow'">{{ enumLabel(row.status) }}</Badge></template>
                </DataTable>
                <!-- Probation -->
                <template v-else-if="lifecycleTab === 'probation'">
                    <div v-if="!probationReview" class="card p-10 text-center text-slate-400">No probation review found.</div>
                    <div v-else class="card p-6">
                        <ProbationReviewCard :review="probationReview" @updated="() => { load(); loadLifecycleTab('probation'); }" />
                    </div>
                </template>
                <!-- Exits -->
                <DataTable v-else-if="lifecycleTab === 'exits'" :columns="exitCols" :rows="tabRows" :loading="false">
                    <template #cell-exit_reason="{ row }"><Badge :color="exitReasonColors[row.exit_reason] ?? 'slate'">{{ row.exit_reason_label ?? enumLabel(row.exit_reason) }}</Badge></template>
                    <template #cell-clearance_status="{ row }"><Badge :color="row.clearance_status === 'cleared' ? 'green' : 'yellow'">{{ row.clearance_status ?? 'pending' }}</Badge></template>
                    <template #cell-status="{ row }"><Badge :color="row.status === 'completed' ? 'green' : 'blue'">{{ enumLabel(row.status) }}</Badge></template>
                    <template #actions="{ row }">
                        <RouterLink v-if="row.final_dues_url && row.status !== 'cancelled' && auth.can('payroll.manage')" :to="row.final_dues_url" class="btn-ghost !px-2 !py-1.5 text-xs">Prepare final dues</RouterLink>
                    </template>
                </DataTable>
            </template>

            <!-- ── PAY PROFILE ── -->
            <template v-else-if="activeTab === 'payroll'">
                <div v-if="payProfileLoading" class="flex justify-center py-20"><Spinner /></div>
                <template v-else>
                    <div class="mt-4 mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold text-slate-900 dark:text-white">
                            {{ payProfile?.profile ? 'Pay Profile' : 'No pay profile yet' }}
                        </h3>
                        <div class="flex gap-2">
                            <button v-if="payProfile?.profile && auth.can('payroll.manage')" class="btn-secondary" @click="salaryModal = true; salaryForm = { basic_salary: '', effective_date: new Date().toLocaleDateString('en-CA'), reason: payProfile?.salary_history?.length ? 'increment' : 'initial', notes: '' }; salaryErrors = {}">
                                Change Salary
                            </button>
                            <button v-if="auth.can('payroll.manage')" class="btn-primary" @click="openProfileModal">
                                {{ payProfile?.profile ? 'Edit Profile' : 'Create Profile' }}
                            </button>
                        </div>
                    </div>

                    <div v-if="payProfile?.profile" class="space-y-5">
                        <!-- Summary -->
                        <div class="card p-5">
                            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Basic Salary</dt>
                                    <dd class="mt-1 text-lg font-bold text-slate-900 dark:text-white">KES {{ Number(payProfile.profile.basic_salary).toLocaleString() }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Payment Method</dt>
                                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ payProfile.profile.payment_method === 'mpesa' ? 'M-Pesa' : enumLabel(payProfile.profile.payment_method) }}</dd>
                                </div>
                                <div v-if="payProfile.profile.payment_method === 'bank'">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Account</dt>
                                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ payProfile.profile.account_number_masked ?? '—' }}</dd>
                                </div>
                                <div v-if="payProfile.profile.payment_method === 'mpesa'">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">M-Pesa</dt>
                                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ payProfile.profile.mpesa_phone_masked ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Payment Ready</dt>
                                    <dd class="mt-1">
                                        <Badge :tone="payProfile.profile.payment_ready ? 'green' : 'red'" :title="(payProfile.profile.readiness_issues ?? []).join(' ')">{{ payProfile.profile.payment_ready ? 'Yes' : 'Incomplete' }}</Badge>
                                        <p v-for="msg in payProfile.profile.readiness_issues ?? []" :key="msg" class="mt-1 text-xs text-red-500">{{ msg }}</p>
                                    </dd>
                                </div>
                                <div v-if="payProfile.profile.on_hold">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Status</dt>
                                    <dd class="mt-1"><Badge tone="amber">On Hold</Badge></dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Salary history -->
                        <div v-if="payProfile.salary_history?.length" class="card p-5">
                            <h4 class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Salary History</h4>
                            <table class="w-full text-sm">
                                <thead class="text-xs uppercase tracking-wide text-slate-400">
                                    <tr>
                                        <th class="pb-2 text-left">Effective Date</th>
                                        <th class="pb-2 text-right">Basic Salary</th>
                                        <th class="pb-2 text-right">Change</th>
                                        <th class="pb-2 text-left">Reason</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr v-for="h in payProfile.salary_history" :key="h.id">
                                        <td class="py-2 text-slate-600 dark:text-slate-300">{{ h.effective_date }}</td>
                                        <td class="py-2 text-right font-medium">KES {{ Number(h.basic_salary).toLocaleString() }}</td>
                                        <td class="py-2 text-right" :class="h.change_pct > 0 ? 'text-emerald-600' : h.change_pct < 0 ? 'text-red-500' : 'text-slate-400'">
                                            {{ h.change_pct !== null ? (h.change_pct > 0 ? '+' : '') + h.change_pct + '%' : '—' }}
                                        </td>
                                        <td class="py-2 text-slate-500">{{ h.reason_label ?? enumLabel(h.reason) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Components -->
                        <div class="card p-5">
                            <div class="mb-3 flex items-center justify-between">
                                <h4 class="text-sm font-semibold text-slate-900 dark:text-white">Pay Components</h4>
                                <button v-if="auth.can('payroll.manage')" class="btn-primary !py-1.5 text-xs" @click="componentModal = { open: true, row: null }; componentForm = { component_id: '', amount: '', rate: '', effective_from: new Date().toLocaleDateString('en-CA'), effective_to: '', notes: '' }; componentErrors = {}">
                                    <Plus class="size-3.5" /> Add
                                </button>
                            </div>
                            <div v-if="!payProfile.components?.length" class="text-sm text-slate-400">No components assigned.</div>
                            <table v-else class="w-full text-sm">
                                <thead class="text-xs uppercase tracking-wide text-slate-400">
                                    <tr>
                                        <th class="pb-2 text-left">Component</th>
                                        <th class="pb-2 text-left">Category</th>
                                        <th class="pb-2 text-right">Amount / Rate</th>
                                        <th class="pb-2 text-left">From</th>
                                        <th class="pb-2 text-left">To</th>
                                        <th class="pb-2" />
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr v-for="c in payProfile.components" :key="c.id" :class="!c.active ? 'opacity-50' : ''">
                                        <td class="py-2 font-medium text-slate-800 dark:text-slate-200">{{ c.component?.name }}</td>
                                        <td class="py-2 text-slate-500">{{ c.component?.category_label ?? enumLabel(c.component?.category) }}</td>
                                        <td class="py-2 text-right">
                                            <span v-if="c.amount !== null">KES {{ Number(c.amount).toLocaleString() }}</span>
                                            <span v-else-if="c.rate !== null">{{ c.rate }}%</span>
                                            <span v-else class="text-slate-400">Default</span>
                                        </td>
                                        <td class="py-2 text-slate-500">{{ c.effective_from }}</td>
                                        <td class="py-2 text-slate-500">{{ c.effective_to ?? '—' }}</td>
                                        <td class="py-2 text-right">
                                            <button v-if="auth.can('payroll.manage') && c.active" class="btn-ghost !p-1" @click="componentModal = { open: true, row: c }; componentForm = { component_id: c.component_id, amount: c.amount ?? '', rate: c.rate ?? '', effective_from: c.effective_from, effective_to: c.effective_to ?? '', notes: c.notes ?? '' }; componentErrors = {}">
                                                <Pencil class="size-3.5" />
                                            </button>
                                            <button v-if="auth.can('payroll.manage') && c.active" class="btn-ghost !p-1 text-red-500" @click="removeComponent(c)">
                                                <Trash2 class="size-3.5" />
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Recent payslips -->
                        <div v-if="payProfile.recent_payslips?.length" class="card p-5">
                            <h4 class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Recent Payslips</h4>
                            <div class="flex flex-wrap gap-3">
                                <RouterLink v-for="ps in payProfile.recent_payslips" :key="ps.id" :to="`/payroll/runs/${ps.run_id}`"
                                    class="rounded-lg border border-slate-200 px-3 py-2 text-sm hover:border-brand-400 dark:border-slate-700">
                                    <p class="font-medium text-slate-800 dark:text-slate-200">{{ ps.title }}</p>
                                    <p class="text-xs text-slate-500">Net: KES {{ Number(ps.net_pay).toLocaleString() }}</p>
                                </RouterLink>
                            </div>
                        </div>
                    </div>

                    <div v-else class="card mt-4 p-10 text-center">
                        <p class="text-slate-500">No pay profile. Click "Create Profile" to set up salary and payment details.</p>
                    </div>

                    <!-- Profile modal -->
                    <Modal :open="profileModal" :title="payProfile?.profile ? 'Edit Pay Profile' : 'Create Pay Profile'" size="lg" @close="profileModal = false">
                        <div class="space-y-4">
                            <div v-if="!payProfile?.profile">
                                <label class="label">Basic Salary (KES) <span class="text-red-500">*</span></label>
                                <input v-model="payProfileForm.basic_salary" type="number" min="0" class="input" />
                                <p v-if="payProfileErrors.basic_salary" class="mt-1 text-xs text-red-500">{{ payProfileErrors.basic_salary }}</p>
                            </div>
                            <div v-if="!payProfile?.profile">
                                <label class="label">Effective Date</label>
                                <input v-model="payProfileForm.effective_date" type="date" class="input" :min="employee?.date_of_joining?.slice(0, 10) || undefined" />
                            </div>
                            <div>
                                <label class="label">Payment Method</label>
                                <select v-model="payProfileForm.payment_method" class="input">
                                    <option value="bank">Bank transfer</option>
                                    <option value="mpesa">M-Pesa</option>
                                    <option value="cash">Cash</option>
                                </select>
                            </div>
                            <template v-if="payProfileForm.payment_method === 'bank'">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="label">Bank Name</label>
                                        <input v-model="payProfileForm.bank_name" type="text" class="input" />
                                        <p v-if="payProfileErrors.bank_name" class="mt-1 text-xs text-red-500">{{ payProfileErrors.bank_name }}</p>
                                    </div>
                                    <div>
                                        <label class="label">Branch</label>
                                        <input v-model="payProfileForm.bank_branch" type="text" class="input" />
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="label">Account Name</label>
                                        <input v-model="payProfileForm.account_name" type="text" class="input" />
                                    </div>
                                    <div>
                                        <label class="label">Account Number</label>
                                        <input v-model="payProfileForm.account_number" type="text" class="input" />
                                        <p v-if="payProfileErrors.account_number" class="mt-1 text-xs text-red-500">{{ payProfileErrors.account_number }}</p>
                                    </div>
                                </div>
                            </template>
                            <div v-if="payProfileForm.payment_method === 'mpesa'">
                                <label class="label">M-Pesa Phone</label>
                                <input v-model="payProfileForm.mpesa_phone" type="tel" placeholder="07XXXXXXXX" class="input" />
                                <p v-if="payProfileErrors.mpesa_phone" class="mt-1 text-xs text-red-500">{{ payProfileErrors.mpesa_phone }}</p>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="label">Pension Employee %</label>
                                    <input v-model="payProfileForm.pension_employee_percent" type="number" min="0" max="30" step="0.5" class="input" />
                                </div>
                                <div>
                                    <label class="label">Pension Employer %</label>
                                    <input v-model="payProfileForm.pension_employer_percent" type="number" min="0" max="30" step="0.5" class="input" />
                                </div>
                            </div>
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="payProfileForm.disability_exempt" type="checkbox" class="rounded text-brand-600" />
                                Disability exemption
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="payProfileForm.on_hold" type="checkbox" class="rounded text-brand-600" />
                                On hold (exclude from payroll runs)
                            </label>
                        </div>
                        <template #footer>
                            <button class="btn-secondary" @click="profileModal = false">Cancel</button>
                            <button class="btn-primary" :disabled="savingProfile" @click="saveProfile">{{ savingProfile ? 'Saving…' : 'Save' }}</button>
                        </template>
                    </Modal>

                    <!-- Salary change modal -->
                    <Modal :open="salaryModal" title="Record Salary Change" @close="salaryModal = false">
                        <div class="space-y-4">
                            <div>
                                <label class="label">New Basic Salary (KES) <span class="text-red-500">*</span></label>
                                <input v-model="salaryForm.basic_salary" type="number" min="0" class="input" />
                                <p v-if="salaryErrors.basic_salary" class="mt-1 text-xs text-red-500">{{ salaryErrors.basic_salary }}</p>
                            </div>
                            <div>
                                <label class="label">Effective Date <span class="text-red-500">*</span></label>
                                <input v-model="salaryForm.effective_date" type="date" class="input" :min="employee?.date_of_joining?.slice(0, 10) || undefined" />
                                <p v-if="salaryErrors.effective_date" class="mt-1 text-xs text-red-500">{{ salaryErrors.effective_date }}</p>
                            </div>
                            <div>
                                <label class="label">Reason</label>
                                <select v-model="salaryForm.reason" class="input">
                                    <option v-for="r in SALARY_REASONS" :key="r.value" :value="r.value">{{ r.label }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="label">Notes</label>
                                <textarea v-model="salaryForm.notes" rows="2" class="input" />
                            </div>
                        </div>
                        <template #footer>
                            <button class="btn-secondary" @click="salaryModal = false">Cancel</button>
                            <button class="btn-primary" :disabled="savingSalary" @click="saveSalary">{{ savingSalary ? 'Saving…' : 'Save' }}</button>
                        </template>
                    </Modal>

                    <!-- Component modal -->
                    <Modal :open="componentModal.open" :title="componentModal.row ? 'Edit Component' : 'Add Component'" @close="componentModal.open = false">
                        <div class="space-y-4">
                            <div v-if="!componentModal.row">
                                <label class="label">Component <span class="text-red-500">*</span></label>
                                <select v-model="componentForm.component_id" class="input">
                                    <option value="">— Select —</option>
                                    <option v-for="c in payProfile?.available_components" :key="c.id" :value="c.id">{{ c.name }} ({{ c.category }})</option>
                                </select>
                                <p v-if="componentErrors.component_id" class="mt-1 text-xs text-red-500">{{ componentErrors.component_id }}</p>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="label">Amount (KES)</label>
                                    <input v-model="componentForm.amount" type="number" min="0" class="input" placeholder="Leave blank for default" />
                                </div>
                                <div>
                                    <label class="label">Rate (%)</label>
                                    <input v-model="componentForm.rate" type="number" min="0" max="100" step="0.1" class="input" placeholder="Leave blank for default" />
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="label">Effective From <span class="text-red-500">*</span></label>
                                    <input v-model="componentForm.effective_from" type="date" class="input" :min="employee?.date_of_joining?.slice(0, 10) || undefined" />
                                    <p v-if="componentErrors.effective_from" class="mt-1 text-xs text-red-500">{{ componentErrors.effective_from }}</p>
                                </div>
                                <div>
                                    <label class="label">Effective To</label>
                                    <input v-model="componentForm.effective_to" type="date" class="input" />
                                </div>
                            </div>
                            <div>
                                <label class="label">Notes</label>
                                <input v-model="componentForm.notes" type="text" class="input" />
                            </div>
                        </div>
                        <template #footer>
                            <button class="btn-secondary" @click="componentModal.open = false">Cancel</button>
                            <button class="btn-primary" :disabled="savingComponent" @click="saveComponent">{{ savingComponent ? 'Saving…' : 'Save' }}</button>
                        </template>
                    </Modal>
                </template>
            </template>

            <!-- ── HISTORY ── -->
            <template v-else-if="activeTab === 'history'">
                <div v-if="tabLoading" class="flex justify-center py-10"><Spinner /></div>
                <div v-else-if="!tabRows.length" class="card mt-4 p-6 text-center text-slate-400 text-sm">No activity recorded yet.</div>
                <div v-else class="mt-4 space-y-2">
                    <div v-for="a in tabRows" :key="a.id" class="card flex items-start gap-4 p-4">
                        <div class="flex-1">
                            <p class="text-sm font-medium text-slate-900 dark:text-white">{{ a.description }}</p>
                            <p class="text-xs text-slate-400">{{ a.causer }} · {{ a.at?.slice(0,16).replace('T',' ') }}</p>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ── MODALS ── -->

            <!-- Letter generator -->
            <Modal :open="letterModal" title="Generate Letter" @close="letterModal = false">
                <div class="space-y-4">
                    <div>
                        <label class="label">Letter Template</label>
                        <select v-model="letterForm.template" class="input">
                            <option value="">— Select template —</option>
                            <option v-for="t in letterTemplates" :key="t.key ?? t.id" :value="t.key ?? t.id">{{ t.name }}</option>
                        </select>
                        <p v-if="letterErrors.template" class="mt-1 text-xs text-red-500">{{ letterErrors.template }}</p>
                    </div>
                    <div><label class="label">Effective Date</label><input v-model="letterForm.effective_date" type="date" class="input" /></div>
                    <div><label class="label">Notes</label><textarea v-model="letterForm.notes" rows="3" class="input" /></div>
                </div>
                <template #footer>
                    <button class="btn-secondary" @click="letterModal = false">Cancel</button>
                    <button class="btn-primary" :disabled="generatingLetter || !letterForm.template" @click="generateLetter">{{ generatingLetter ? 'Generating…' : 'Generate' }}</button>
                </template>
            </Modal>

            <!-- Contract modal -->
            <Modal :open="modal.open" :title="modal.row ? 'Edit Contract' : 'New Contract'" @close="modal.open = false">
                <div class="space-y-4">
                    <div><label class="label">Contract Type</label><select v-model="form.contract_type" class="input"><option value="permanent">Permanent</option><option value="fixed_term">Fixed Term</option><option value="casual">Casual</option><option value="probation">Probation</option><option value="internship">Internship</option></select></div>
                    <div class="grid grid-cols-2 gap-4"><div><label class="label">Start Date</label><input v-model="form.start_date" type="date" class="input" /></div><div><label class="label">End Date</label><input v-model="form.end_date" type="date" class="input" /></div></div>
                    <div><label class="label">Gross Salary (KES)</label><input v-model="form.gross_salary" type="number" class="input" /></div>
                    <div><label class="label">Notes</label><textarea v-model="form.notes" rows="2" class="input" /></div>
                </div>
                <template #footer>
                    <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                    <button class="btn-primary" :disabled="saving" @click="saveContract">{{ saving ? 'Saving…' : 'Save' }}</button>
                </template>
            </Modal>

            <!-- Lifecycle actions -->
            <ExitModal :open="exitOpen" :employee="employee" @close="exitOpen = false" @done="afterLifecycle()" />
            <TransferModal :open="transferOpen" :employee="employee" :initial-mode="transferMode" @close="transferOpen = false" @done="(d) => afterLifecycle(d?.company_id ? d.id : null)" />
            <ReinstateModal v-if="reinstateOpen" :employee="employee" @close="reinstateOpen = false" @done="(id) => afterLifecycle(id)" />

            <!-- Edit employee modal -->
            <EmployeeForm :open="editModal" :employee-id="employee.id" @close="editModal = false" @done="() => { editModal = false; load(); }" />

            <!-- Upload document modal -->
            <Modal :open="uploadModal" title="Upload Document" @close="uploadModal = false">
                <div class="space-y-4">
                    <div><label class="label">Title</label><input v-model="uploadForm.title" type="text" class="input" :class="uploadErrors.title && 'border-red-500'" /><p v-if="uploadErrors.title" class="mt-1 text-xs text-red-500">{{ uploadErrors.title }}</p></div>
                    <div><label class="label">Document Type</label><select v-model="uploadForm.document_type_id" class="input"><option value="">— Select type —</option><option v-for="t in docChecklist" :key="t.document_type_id" :value="t.document_type_id">{{ t.name }}</option></select></div>
                    <div><label class="label">File</label><input type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="input" @change="uploadForm.file = $event.target.files[0]" /><p v-if="uploadErrors.file" class="mt-1 text-xs text-red-500">{{ uploadErrors.file }}</p></div>
                    <div><label class="label">Notes</label><textarea v-model="uploadForm.notes" rows="2" class="input" /></div>
                </div>
                <template #footer>
                    <button class="btn-secondary" @click="uploadModal = false">Cancel</button>
                    <button class="btn-primary" :disabled="uploading" @click="uploadDoc">{{ uploading ? 'Uploading…' : 'Upload' }}</button>
                </template>
            </Modal>
        </template>
    </div>
</template>
