<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Plus, Search, UserCheck, ExternalLink, CheckCircle, Circle, Download, Pencil, Check, X, Send, UserPlus, FileText, Undo2, Eye, ArrowRight, Ban, Gift } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { useAuthStore } from '@/stores/auth';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';
import LookupSelect from '@/components/ui/LookupSelect.vue';

const ui = useUiStore();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const tabs = [
    { key: 'requisitions', label: 'Requisitions' },
    { key: 'postings',     label: 'Job Postings' },
    { key: 'applicants',   label: 'Applicants' },
    { key: 'interviews',   label: 'Interviews' },
    { key: 'offers',       label: 'Offers' },
    { key: 'onboarding',   label: 'Onboarding' },
    { key: 'induction',    label: 'Induction Tasks' },
    { key: 'assets',       label: 'Assets' },
];
const tab = ref(tabs.some((t) => t.key === route.query.tab) && (auth.can('recruitment.manage') || route.query.tab === 'requisitions') ? route.query.tab : 'requisitions');
// HR runs the pipeline; a supervisor with recruitment.requisition only raises and tracks their own requisitions.
const canEdit = computed(() => auth.can('recruitment.manage'));
const canRequest = computed(() => canEdit.value || auth.can('recruitment.requisition'));
const visibleTabs = computed(() => (canEdit.value ? tabs : tabs.filter((t) => t.key === 'requisitions')));

const rows = ref([]);
const paginator = ref(null);
const loading = ref(false);
const modal = ref({ open: false, row: null });
const form = ref({});
const errors = ref({});
const saving = ref(false);
const search = ref('');
const stageFilter = ref('');
const approvedRequisitions = ref([]);

// Column definitions per tab
const colMap = {
    requisitions: [
        { key: 'requisition_number', label: 'Req #' },
        { key: 'position_title', label: 'Position', sortable: true },
        { key: 'department', label: 'Department' },
        { key: 'vacancies', label: 'Vacancies', align: 'right' },
        { key: 'requested_by', label: 'Requested by' },
        { key: 'status', label: 'Status' },
    ],
    postings: [
        { key: 'title', label: 'Job Title', sortable: true },
        { key: 'department', label: 'Department' },
        { key: 'location', label: 'Location' },
        { key: 'applications', label: 'Applications', align: 'right' },
        { key: 'closing_date', label: 'Closing Date' },
        { key: 'status', label: 'Status' },
    ],
    applicants: [
        { key: 'photo', label: '', export: false },
        { key: 'name', label: 'Applicant', sortable: true },
        { key: 'posting_title', label: 'Position' },
        { key: 'stage', label: 'Stage' },
        { key: 'source', label: 'Source' },
        { key: 'applied_at', label: 'Applied' },
        { key: 'score', label: 'Score', align: 'right' },
    ],
    interviews: [
        { key: 'applicant_name', label: 'Applicant', sortable: true },
        { key: 'position', label: 'Position' },
        { key: 'interview_type', label: 'Type' },
        { key: 'scheduled_at', label: 'Scheduled' },
        { key: 'interviewer_name', label: 'Interviewer' },
        { key: 'status', label: 'Status' },
    ],
    offers: [
        { key: 'applicant_name', label: 'Applicant', sortable: true },
        { key: 'position', label: 'Position' },
        { key: 'offered_salary', label: 'Offered Salary (KES)', align: 'right', format: v => v?.toLocaleString() },
        { key: 'start_date', label: 'Start Date' },
        { key: 'expiry_date', label: 'Offer Expiry' },
        { key: 'status', label: 'Status' },
    ],
    onboarding: [],
    induction: [
        { key: 'name', label: 'Task Name', sortable: true },
        { key: 'category', label: 'Category' },
        { key: 'owner_role', label: 'Owner' },
        { key: 'due_days', label: 'Due (days)', align: 'right' },
        { key: 'is_mandatory', label: 'Mandatory' },
    ],
    assets: [
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'asset_name', label: 'Asset' },
        { key: 'asset_tag', label: 'Tag / Serial' },
        { key: 'assigned_date', label: 'Assigned' },
        { key: 'return_date', label: 'Returned' },
        { key: 'status', label: 'Status' },
    ],
};

const statusColorMap = {
    requisitions: { draft: 'slate', pending_approval: 'yellow', approved: 'green', rejected: 'red', filled: 'blue' },
    postings:     { draft: 'slate', published: 'green', closed: 'red', paused: 'yellow' },
    applicants:   { applied: 'slate', screening: 'yellow', interview: 'blue', offer: 'purple', hired: 'green', rejected: 'red' },
    interviews:   { scheduled: 'blue', completed: 'green', cancelled: 'red', no_show: 'slate' },
    offers:       { draft: 'slate', sent: 'blue', accepted: 'green', declined: 'red', expired: 'slate' },
    induction:    {},
    assets:       { assigned: 'blue', returned: 'green', lost: 'red', damaged: 'yellow' },
};

const defaultForms = {
    requisitions: { position_id: '', department_id: '', vacancies: 1, type: 'permanent', justification: '' },
    postings:     { title: '', requisition_id: '', department_id: '', location: '', closing_date: '', description: '', requirements: '' },
    interviews:   { applicant_id: '', interview_type: 'panel', scheduled_at: '', interviewer_id: '', location: '' },
    offers:       { applicant_id: '', offered_salary: '', start_date: '', expiry_date: '', notes: '', status: 'draft' },
    induction:    { name: '', category: 'hr', owner_role: 'hr', due_days: 7, is_mandatory: true, description: '' },
    assets:       { employee_id: '', asset_name: '', asset_tag: '', assigned_date: new Date().toLocaleDateString('en-CA'), notes: '' },
};
const editable = ['requisitions', 'postings', 'interviews', 'offers', 'induction', 'assets'];
const titles = { requisitions: 'Requisition', postings: 'Job Posting', interviews: 'Interview', offers: 'Offer', induction: 'Induction Task', assets: 'Asset Assignment' };

const endpointMap = {
    requisitions: '/recruitment/requisitions',
    postings:     '/recruitment/postings',
    applicants:   '/recruitment/applicants',
    interviews:   '/recruitment/interviews',
    offers:       '/recruitment/offers',
    onboarding:   '/recruitment/onboarding',
    induction:    '/recruitment/induction',
    assets:       '/recruitment/assets',
};

// Onboarding employees
const onboardingEmployees = ref([]);

async function load(page = 1) {
    loading.value = true; rows.value = [];
    try {
        if (tab.value === 'onboarding') {
            const { data } = await api.get(endpointMap.onboarding);
            onboardingEmployees.value = data.data ?? data;
        } else {
            const params = { page, search: search.value };
            if (tab.value === 'applicants' && stageFilter.value) params.stage = stageFilter.value;
            const { data } = await api.get(endpointMap[tab.value], { params });
            rows.value = data.data ?? data;
            if (data.current_page) paginator.value = { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from, to: data.to };
            else paginator.value = null;
        }
    } finally { loading.value = false; }
}

function switchTab(t) {
    tab.value = t; search.value = ''; stageFilter.value = '';
    router.replace({ query: { tab: t } });
    load();
}

async function openForm(row = null) {
    errors.value = {};
    form.value = row ? { ...row } : { ...(defaultForms[tab.value] ?? {}) };
    if (tab.value === 'interviews' && form.value.scheduled_at) form.value.scheduled_at = String(form.value.scheduled_at).replace(' ', 'T');
    modal.value = { open: true, row };
    if (tab.value === 'postings') {
        // Only approved requisitions can be posted.
        try { approvedRequisitions.value = (await api.get('/recruitment/requisitions', { params: { status: 'approved' } })).data.data ?? []; } catch { approvedRequisitions.value = []; }
    }
}

function onRequisitionPicked() {
    const r = approvedRequisitions.value.find((x) => x.id === Number(form.value.requisition_id));
    if (!r) return;
    form.value.department_id = r.department_id;
    if (!form.value.title) form.value.title = r.position_title;
}

async function save() {
    saving.value = true; errors.value = {};
    const endpoint = endpointMap[tab.value];
    try {
        const payload = { ...form.value };
        if (modal.value.row && ['requisitions', 'offers'].includes(tab.value)) delete payload.status; // status moves through the actions
        modal.value.row
            ? await api.put(`${endpoint}/${modal.value.row.id}`, payload)
            : await api.post(endpoint, payload);
        ui.toast('Saved.'); modal.value.open = false; await load();
    } catch (e) { errors.value = fieldErrors(e); ui.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}

async function post(url, body = {}, reload = true) {
    try {
        const { data } = await api.post(url, body);
        ui.toast(data.message ?? 'Done.');
        if (reload) await load(paginator.value?.current_page ?? 1);
        return true;
    } catch (e) { ui.toast(errorMessage(e), 'error'); return false; }
}

// Requisition approval (by someone other than the requester)
const rejecting = ref({ open: false, row: null, loading: false });
async function rejectRequisition(note) {
    rejecting.value.loading = true;
    if (await post(`/recruitment/requisitions/${rejecting.value.row.id}/reject`, { note })) rejecting.value.open = false;
    rejecting.value.loading = false;
}

async function moveApplicantStage(row, stage, reason = null) {
    try {
        const { data } = await api.patch(`/recruitment/applicants/${row.id}/stage`, { stage, reason });
        ui.toast(data.message ?? 'Stage updated.');
        await load(paginator.value?.current_page ?? 1);
        if (detail.value.open) await openApplicant(row);
        return true;
    } catch (e) { ui.toast(errorMessage(e), 'error'); return false; }
}

// Applicant rejection (reason required) and detail view (interviews, score, offers, CV).
const rejectingApplicant = ref({ open: false, row: null, loading: false });
async function rejectApplicant(reason) {
    rejectingApplicant.value.loading = true;
    if (await moveApplicantStage(rejectingApplicant.value.row, 'rejected', reason)) rejectingApplicant.value.open = false;
    rejectingApplicant.value.loading = false;
}
const detail = ref({ open: false, loading: false, data: null });
async function openApplicant(row) {
    detail.value = { open: true, loading: true, data: detail.value.data?.id === row.id ? detail.value.data : null };
    try { detail.value.data = (await api.get(`/recruitment/applicants/${row.id}`)).data.data; }
    catch (e) { ui.toast(errorMessage(e), 'error'); detail.value.open = false; }
    finally { detail.value.loading = false; }
}
// "Make offer" from an applicant: open the Offers tab with the applicant chosen.
async function offerFor(row) {
    detail.value.open = false;
    switchTab('offers');
    await openForm();
    form.value.applicant_id = row.id;
}
// Completing an interview is only possible once its scheduled time has passed (the server enforces it too).
const interviewInFuture = computed(() => tab.value === 'interviews' && form.value.scheduled_at && new Date(form.value.scheduled_at) > new Date());
const stageLabels = { applied: 'Applied', screening: 'Screening', interview: 'Interview', offer: 'Offer', hired: 'Hired', rejected: 'Rejected' };

// Hired applicant / accepted offer → prefilled onboarding form on the Employees page.
function convert(applicantId) {
    router.push({ path: '/employees', query: { applicant_id: applicantId } });
}

async function returnAsset(row) {
    try { await api.put(`/recruitment/assets/${row.id}`, { status: 'returned' }); ui.toast('Asset marked returned.'); await load(); }
    catch (e) { ui.toast(errorMessage(e), 'error'); }
}

async function completeOnboardingTask(empId, taskId) {
    try { await api.post(`/recruitment/onboarding/${empId}/tasks/${taskId}/complete`); ui.toast('Task updated.'); await load(); }
    catch (e) { ui.toast(errorMessage(e), 'error'); }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Recruitment Pipeline">
            <template #actions>
                <a href="/careers" target="_blank" class="btn-secondary"><ExternalLink class="size-4" /> Careers page</a>
                <button v-if="(tab === 'requisitions' ? canRequest : canEdit) && editable.includes(tab)" class="btn-primary" @click="openForm()">
                    <Plus class="size-4" />
                    {{ { requisitions: 'New Requisition', postings: 'New Posting', interviews: 'Schedule Interview', offers: 'Create Offer', induction: 'Add Task', assets: 'Assign Asset' }[tab] }}
                </button>
            </template>
        </PageHeader>

        <Tabs :tabs="visibleTabs" :model-value="tab" @update:model-value="switchTab" />

        <!-- Applicant stage filter -->
        <div v-if="tab === 'applicants'" class="mb-4 flex flex-wrap gap-2">
            <button v-for="(label, key) in { '': 'All', applied: 'Applied', screening: 'Screening', interview: 'Interview', offer: 'Offer', hired: 'Hired', rejected: 'Rejected' }" :key="key"
                class="rounded-lg border px-3 py-1.5 text-sm font-medium transition"
                :class="stageFilter === key ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400'"
                @click="stageFilter = key; load()">{{ label }}</button>
        </div>

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>

        <!-- Onboarding special view -->
        <template v-else-if="tab === 'onboarding'">
            <div v-if="!onboardingEmployees.length" class="card p-10 text-center text-slate-400">No employees currently onboarding.</div>
            <div v-else class="space-y-4">
                <div v-for="emp in onboardingEmployees" :key="emp.id" class="card p-5">
                    <div class="mb-4 flex flex-wrap items-center gap-4">
                        <Avatar :src="emp.photo_url" :name="emp.name" size="md" />
                        <div class="min-w-0 flex-1">
                            <RouterLink :to="`/employees/${emp.id}`" class="font-semibold text-slate-900 hover:underline dark:text-white">{{ emp.name }}</RouterLink>
                            <p class="text-sm text-slate-500">{{ emp.position }} · Starts {{ emp.start_date }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ emp.completed_tasks }}/{{ emp.total_tasks }} tasks</p>
                            <div class="mt-1 h-1.5 w-32 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                <div class="h-full rounded-full bg-brand-500" :style="{ width: `${emp.progress ?? 0}%` }" />
                            </div>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div v-for="task in emp.tasks" :key="task.id" class="flex items-center gap-3 rounded-lg border border-slate-100 px-3 py-2 dark:border-slate-800">
                            <button :disabled="!canEdit" :title="task.completed ? 'Reopen' : 'Mark complete'" @click="completeOnboardingTask(emp.id, task.id)">
                                <CheckCircle v-if="task.completed" class="size-5 text-green-500" />
                                <Circle v-else class="size-5 text-slate-300 hover:text-brand-500" />
                            </button>
                            <span class="flex-1 text-sm text-slate-700 dark:text-slate-300" :class="task.completed ? 'line-through text-slate-400' : ''">{{ task.name }}</span>
                            <span v-if="task.overdue" class="text-xs text-rose-600">Overdue</span>
                            <span v-else-if="task.due_date" class="text-xs text-slate-400">Due {{ task.due_date }}</span>
                            <Badge v-if="task.owner" color="slate" class="text-xs">{{ task.owner }}</Badge>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- Standard table views -->
        <DataTable v-else :columns="colMap[tab]" :rows="rows" :loading="false" :paginator="paginator" :export-name="tab" @page="load">
            <template #toolbar>
                <div v-if="['applicants','postings','requisitions'].includes(tab)" class="relative">
                    <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input v-model="search" type="search" placeholder="Search…" class="input pl-9 w-full sm:w-64" @input="load()" />
                </div>
            </template>
            <template #cell-photo="{ row }"><Avatar :src="row.photo_url" :name="row.name" size="sm" /></template>
            <template #cell-status="{ row }">
                <Badge :color="(statusColorMap[tab] ?? {})[row.status] ?? 'slate'">{{ row.status?.replace('_', ' ') }}</Badge>
                <p v-if="tab === 'requisitions' && row.decision_note" class="mt-0.5 max-w-[14rem] truncate text-xs text-slate-400" :title="row.decision_note">{{ row.decision_note }}</p>
            </template>
            <template #cell-stage="{ row }">
                <Badge :color="(statusColorMap.applicants)[row.stage] ?? 'slate'">{{ row.stage }}</Badge>
                <RouterLink v-if="row.employee_id" :to="`/employees/${row.employee_id}`" class="ml-1 text-xs text-brand-600 hover:underline">Employee</RouterLink>
            </template>
            <template #cell-is_mandatory="{ row }">
                <Badge :color="row.is_mandatory ? 'red' : 'slate'">{{ row.is_mandatory ? 'Mandatory' : 'Optional' }}</Badge>
            </template>
            <template #actions="{ row }">
                <!-- Requisitions -->
                <template v-if="tab === 'requisitions'">
                    <button v-if="row.can_decide" class="btn-ghost !p-1.5 text-green-600" title="Approve" @click="post(`/recruitment/requisitions/${row.id}/approve`)"><Check class="size-4" /></button>
                    <button v-if="row.can_decide" class="btn-ghost !p-1.5 text-rose-600" title="Reject" @click="rejecting = { open: true, row, loading: false }"><X class="size-4" /></button>
                    <button v-if="(canEdit || row.requested_by_id === auth.user?.id) && !['approved','filled'].includes(row.status)" class="btn-ghost !p-1.5" title="Edit" @click="openForm(row)"><Pencil class="size-4" /></button>
                </template>
                <!-- Postings -->
                <template v-else-if="tab === 'postings'">
                    <a v-if="row.public_url" :href="row.public_url" target="_blank" class="btn-ghost !p-1.5" title="View on careers page"><ExternalLink class="size-4" /></a>
                    <button v-if="canEdit" class="btn-ghost !p-1.5" title="Edit" @click="openForm(row)"><Pencil class="size-4" /></button>
                </template>
                <!-- Applicants -->
                <template v-else-if="tab === 'applicants'">
                    <button class="btn-ghost !p-1.5" title="View applicant" @click="openApplicant(row)"><Eye class="size-4" /></button>
                    <a v-if="row.cv_url" :href="row.cv_url" target="_blank" class="btn-ghost !p-1.5" title="Download CV"><FileText class="size-4" /></a>
                    <button v-if="canEdit && row.next_stage" class="btn-ghost !p-1.5 text-brand-600" :title="`Move to ${stageLabels[row.next_stage]}`" @click="moveApplicantStage(row, row.next_stage)"><ArrowRight class="size-4" /></button>
                    <button v-if="canEdit && row.can_offer && row.stage === 'interview'" class="btn-ghost !p-1.5 text-purple-600" title="Make offer" @click="offerFor(row)"><Gift class="size-4" /></button>
                    <button v-if="canEdit && row.can_reject" class="btn-ghost !p-1.5 text-rose-600" title="Reject" @click="rejectingApplicant = { open: true, row, loading: false }"><Ban class="size-4" /></button>
                    <button v-if="canEdit && row.stage === 'offer'" class="btn-ghost !p-1.5 text-green-500" title="Mark Hired" @click="moveApplicantStage(row, 'hired')"><UserCheck class="size-4" /></button>
                    <button v-if="canEdit && row.can_convert" class="btn-ghost !px-2 !py-1.5 text-xs text-brand-600" title="Convert to employee" @click="convert(row.id)"><UserPlus class="size-4" /> Convert</button>
                </template>
                <!-- Offers -->
                <template v-else-if="tab === 'offers'">
                    <a v-if="row.pdf_url" :href="row.pdf_url" target="_blank" class="btn-ghost !p-1.5" title="Download PDF"><Download class="size-4" /></a>
                    <template v-if="canEdit">
                        <button v-if="['draft','expired'].includes(row.status)" class="btn-ghost !p-1.5" title="Mark sent" @click="post(`/recruitment/offers/${row.id}/status`, { status: 'sent' })"><Send class="size-4" /></button>
                        <button v-if="row.status === 'sent'" class="btn-ghost !p-1.5 text-green-600" title="Accepted" @click="post(`/recruitment/offers/${row.id}/status`, { status: 'accepted' })"><Check class="size-4" /></button>
                        <button v-if="['sent','expired'].includes(row.status)" class="btn-ghost !p-1.5 text-rose-600" title="Declined" @click="post(`/recruitment/offers/${row.id}/status`, { status: 'declined' })"><X class="size-4" /></button>
                        <button v-if="['draft','sent','expired'].includes(row.status)" class="btn-ghost !p-1.5" title="Edit" @click="openForm(row)"><Pencil class="size-4" /></button>
                        <button v-if="row.can_convert" class="btn-ghost !px-2 !py-1.5 text-xs text-brand-600" title="Convert to employee" @click="convert(row.applicant_id)"><UserPlus class="size-4" /> Convert</button>
                    </template>
                    <RouterLink v-if="row.employee_id" :to="`/employees/${row.employee_id}`" class="btn-ghost !px-2 !py-1.5 text-xs">Employee</RouterLink>
                </template>
                <!-- Assets -->
                <template v-else-if="tab === 'assets' && canEdit">
                    <button v-if="row.status === 'assigned'" class="btn-ghost !p-1.5 text-green-600" title="Mark returned" @click="returnAsset(row)"><Undo2 class="size-4" /></button>
                    <button class="btn-ghost !p-1.5" title="Edit" @click="openForm(row)"><Pencil class="size-4" /></button>
                </template>
                <template v-else-if="['interviews','induction'].includes(tab) && canEdit">
                    <button class="btn-ghost !p-1.5" title="Edit" @click="openForm(row)"><Pencil class="size-4" /></button>
                </template>
            </template>
        </DataTable>

        <!-- Create / Edit Modal -->
        <Modal :open="modal.open" :title="(modal.row ? 'Edit ' : 'New ') + (titles[tab] ?? '')" @close="modal.open = false">
            <div class="space-y-4">
                <!-- Requisitions -->
                <template v-if="tab === 'requisitions'">
                    <div><label class="label">Position</label><LookupSelect v-model="form.position_id" kind="positions" placeholder="Choose a position" :error="!!errors?.position_id" /><p v-if="errors.position_id" class="field-error">{{ errors.position_id }}</p></div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div><label class="label">Department</label><LookupSelect v-model="form.department_id" kind="departments" placeholder="Choose a department" :error="!!errors?.department_id" /><p v-if="errors.department_id" class="field-error">{{ errors.department_id }}</p></div>
                        <div><label class="label">Vacancies</label><input v-model="form.vacancies" type="number" min="1" class="input" /></div>
                    </div>
                    <div><label class="label">Employment Type</label><select v-model="form.type" class="input"><option value="permanent">Permanent</option><option value="fixed_term">Fixed Term</option><option value="internship">Internship</option><option value="casual">Casual</option></select></div>
                    <div><label class="label">Justification</label><textarea v-model="form.justification" rows="3" class="input" /></div>
                    <p class="text-xs text-slate-400">The requisition goes for approval by someone other than you.</p>
                </template>
                <!-- Postings -->
                <template v-else-if="tab === 'postings'">
                    <div v-if="!modal.row">
                        <label class="label">Requisition (approved)</label>
                        <select v-model="form.requisition_id" class="input" @change="onRequisitionPicked">
                            <option value="">— None —</option>
                            <option v-for="r in approvedRequisitions" :key="r.id" :value="r.id">{{ r.requisition_number }} · {{ r.position_title }} ({{ r.vacancies }})</option>
                        </select>
                        <p v-if="errors.requisition_id" class="field-error">{{ errors.requisition_id }}</p>
                    </div>
                    <div><label class="label">Job Title</label><input v-model="form.title" type="text" class="input" /><p v-if="errors.title" class="field-error">{{ errors.title }}</p></div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div><label class="label">Department</label><LookupSelect v-model="form.department_id" kind="departments" placeholder="Choose a department" :error="!!errors?.department_id" /></div>
                        <div><label class="label">Location</label><input v-model="form.location" type="text" class="input" /></div>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div><label class="label">Closing Date</label><input v-model="form.closing_date" type="date" class="input" /><p v-if="errors.closing_date" class="field-error">{{ errors.closing_date }}</p></div>
                        <div v-if="modal.row"><label class="label">Status</label><select v-model="form.status" class="input"><option value="draft">Draft</option><option value="published">Published</option><option value="paused">Paused</option><option value="closed">Closed</option></select></div>
                    </div>
                    <div><label class="label">Job Description</label><textarea v-model="form.description" rows="4" class="input" /></div>
                    <div><label class="label">Requirements</label><textarea v-model="form.requirements" rows="3" class="input" /></div>
                </template>
                <!-- Interviews -->
                <template v-else-if="tab === 'interviews'">
                    <div v-if="!modal.row"><label class="label">Applicant</label><LookupSelect v-model="form.applicant_id" kind="applicants" placeholder="Choose an applicant" :error="!!errors?.applicant_id" /><p v-if="errors.applicant_id" class="field-error">{{ errors.applicant_id }}</p></div>
                    <div><label class="label">Interview Type</label><select v-model="form.interview_type" class="input"><option value="phone">Phone Screen</option><option value="panel">Panel Interview</option><option value="technical">Technical</option><option value="hr">HR Interview</option><option value="final">Final Interview</option></select></div>
                    <div><label class="label">Date & Time</label><input v-model="form.scheduled_at" type="datetime-local" class="input" /></div>
                    <div><label class="label">Interviewer</label><LookupSelect v-model="form.interviewer_id" kind="employees" placeholder="Choose an employee" :error="!!errors?.interviewer_id" /></div>
                    <div><label class="label">Location / Link</label><input v-model="form.location" type="text" class="input" /></div>
                    <template v-if="modal.row">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div><label class="label">Status</label><select v-model="form.status" class="input"><option value="scheduled">Scheduled</option><option value="completed" :disabled="interviewInFuture">Completed</option><option value="cancelled">Cancelled</option><option value="no_show" :disabled="interviewInFuture">No show</option></select><p v-if="errors.status" class="field-error">{{ errors.status }}</p><p v-else-if="interviewInFuture" class="mt-1 text-xs text-slate-400">Can be completed once the interview time has passed.</p></div>
                            <div><label class="label">Score (0-100)</label><input v-model="form.score" type="number" min="0" max="100" class="input" /></div>
                        </div>
                        <div><label class="label">Feedback</label><textarea v-model="form.feedback" rows="3" class="input" /></div>
                    </template>
                </template>
                <!-- Offers -->
                <template v-else-if="tab === 'offers'">
                    <div v-if="!modal.row"><label class="label">Applicant</label><LookupSelect v-model="form.applicant_id" kind="applicants" placeholder="Choose an applicant" :error="!!errors?.applicant_id" /><p v-if="errors.applicant_id" class="field-error">{{ errors.applicant_id }}</p><p v-else class="mt-1 text-xs text-slate-400">Only interviewed applicants who are not yet hired can receive an offer.</p></div>
                    <div><label class="label">Offered Salary (KES)</label><input v-model="form.offered_salary" type="number" class="input" /><p v-if="errors.offered_salary" class="field-error">{{ errors.offered_salary }}</p></div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div><label class="label">Start Date</label><input v-model="form.start_date" type="date" class="input" /></div>
                        <div><label class="label">Offer Expiry</label><input v-model="form.expiry_date" type="date" class="input" /><p v-if="errors.expiry_date" class="field-error">{{ errors.expiry_date }}</p></div>
                    </div>
                    <div><label class="label">Notes</label><textarea v-model="form.notes" rows="3" class="input" /></div>
                    <label v-if="!modal.row" class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input type="checkbox" class="rounded" :checked="form.status === 'sent'" @change="form.status = $event.target.checked ? 'sent' : 'draft'" /> Mark as sent to the candidate now
                    </label>
                </template>
                <!-- Induction -->
                <template v-else-if="tab === 'induction'">
                    <div><label class="label">Task Name</label><input v-model="form.name" type="text" class="input" /><p v-if="errors.name" class="field-error">{{ errors.name }}</p></div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div><label class="label">Owner / Category</label><select v-model="form.category" class="input" @change="form.owner_role = form.category"><option value="hr">HR</option><option value="it">IT</option><option value="finance">Finance</option><option value="security">Security</option><option value="manager">Line manager</option></select></div>
                        <div><label class="label">Due (days after joining)</label><input v-model="form.due_days" type="number" min="0" class="input" /></div>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300"><input v-model="form.is_mandatory" type="checkbox" class="rounded" /> Mandatory</label>
                    <div><label class="label">Description</label><textarea v-model="form.description" rows="3" class="input" /></div>
                </template>
                <!-- Assets -->
                <template v-else-if="tab === 'assets'">
                    <div v-if="!modal.row"><label class="label">Employee</label><LookupSelect v-model="form.employee_id" kind="employees" placeholder="Choose an employee" :error="!!errors?.employee_id" /><p v-if="errors.employee_id" class="field-error">{{ errors.employee_id }}</p></div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div><label class="label">Asset Name</label><input v-model="form.asset_name" type="text" class="input" placeholder="e.g. Laptop" /><p v-if="errors.asset_name" class="field-error">{{ errors.asset_name }}</p></div>
                        <div><label class="label">Tag / Serial No.</label><input v-model="form.asset_tag" type="text" class="input" /></div>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div><label class="label">Assigned Date</label><input v-model="form.assigned_date" type="date" class="input" /></div>
                        <div v-if="modal.row"><label class="label">Status</label><select v-model="form.status" class="input"><option value="assigned">Assigned</option><option value="returned">Returned</option><option value="lost">Lost</option><option value="damaged">Damaged</option></select></div>
                    </div>
                    <div><label class="label">Notes</label><textarea v-model="form.notes" rows="2" class="input" /></div>
                </template>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>

        <ConfirmDialog :open="rejectingApplicant.open" title="Reject applicant" danger confirm-label="Reject"
            :message="`Reject ${rejectingApplicant.row?.name ?? ''}? The reason is kept on the applicant's record.`"
            reason-label="Reason" :loading="rejectingApplicant.loading" @close="rejectingApplicant.open = false" @confirm="rejectApplicant" />

        <Modal :open="detail.open" :title="detail.data?.name ?? 'Applicant'" size="lg" @close="detail.open = false">
            <div v-if="detail.loading && !detail.data" class="flex justify-center py-10"><Spinner /></div>
            <div v-else-if="detail.data" class="space-y-5 text-sm">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div><p class="text-xs text-slate-400">Position</p><p class="font-medium">{{ detail.data.posting_title ?? '—' }}</p></div>
                    <div><p class="text-xs text-slate-400">Stage</p><Badge :color="statusColorMap.applicants[detail.data.stage] ?? 'slate'">{{ stageLabels[detail.data.stage] ?? detail.data.stage }}</Badge></div>
                    <div><p class="text-xs text-slate-400">Email / phone</p><p>{{ detail.data.email }}<span v-if="detail.data.phone"> · {{ detail.data.phone }}</span></p></div>
                    <div><p class="text-xs text-slate-400">Score</p><p class="font-medium">{{ detail.data.score ?? 'Not scored' }}</p></div>
                    <div><p class="text-xs text-slate-400">Applied</p><p>{{ detail.data.applied_at ?? '—' }} <span v-if="detail.data.source" class="text-slate-400">via {{ detail.data.source }}</span></p></div>
                    <div><p class="text-xs text-slate-400">CV</p><a v-if="detail.data.cv_url" :href="detail.data.cv_url" target="_blank" class="text-brand-600 hover:underline">Download CV</a><span v-else class="text-slate-400">No CV on file</span></div>
                </div>
                <div v-if="detail.data.cover_letter"><p class="mb-1 text-xs text-slate-400">Cover letter</p><p class="whitespace-pre-line">{{ detail.data.cover_letter }}</p></div>
                <div v-if="detail.data.notes"><p class="mb-1 text-xs text-slate-400">Notes</p><p class="whitespace-pre-line">{{ detail.data.notes }}</p></div>
                <div>
                    <p class="mb-2 font-semibold">Interviews</p>
                    <p v-if="!detail.data.interviews?.length" class="text-slate-400">No interviews yet.</p>
                    <div v-for="iv in detail.data.interviews" :key="iv.id" class="mb-2 rounded-lg border border-slate-100 px-3 py-2 dark:border-slate-800">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium capitalize">{{ iv.interview_type }}</span>
                            <span class="text-slate-500">{{ iv.scheduled_at }}</span>
                            <span v-if="iv.interviewer_name" class="text-slate-500">· {{ iv.interviewer_name }}</span>
                            <Badge :color="statusColorMap.interviews[iv.status] ?? 'slate'" class="ml-auto">{{ iv.status?.replace('_', ' ') }}</Badge>
                            <span v-if="iv.score !== null" class="font-medium">{{ iv.score }}/100</span>
                        </div>
                        <p v-if="iv.feedback" class="mt-1 whitespace-pre-line text-slate-500">{{ iv.feedback }}</p>
                    </div>
                </div>
                <div>
                    <p class="mb-2 font-semibold">Offers</p>
                    <p v-if="!detail.data.offers?.length" class="text-slate-400">No offers yet.</p>
                    <div v-for="o in detail.data.offers" :key="o.id" class="mb-2 flex flex-wrap items-center gap-2 rounded-lg border border-slate-100 px-3 py-2 dark:border-slate-800">
                        <span class="font-medium">KES {{ o.offered_salary?.toLocaleString() }}</span>
                        <span class="text-slate-500">starts {{ o.start_date }} · expires {{ o.expiry_date }}</span>
                        <Badge :color="statusColorMap.offers[o.status] ?? 'slate'" class="ml-auto">{{ o.status }}</Badge>
                        <a :href="o.pdf_url" target="_blank" class="btn-ghost !p-1.5" title="Offer letter"><Download class="size-4" /></a>
                    </div>
                </div>
            </div>
            <template #footer>
                <template v-if="canEdit && detail.data">
                    <button v-if="detail.data.can_reject" class="btn-secondary text-rose-600" @click="rejectingApplicant = { open: true, row: detail.data, loading: false }"><Ban class="size-4" /> Reject</button>
                    <button v-if="detail.data.can_offer && detail.data.stage === 'interview'" class="btn-secondary" @click="offerFor(detail.data)"><Gift class="size-4" /> Make offer</button>
                    <button v-if="detail.data.next_stage" class="btn-primary" @click="moveApplicantStage(detail.data, detail.data.next_stage)"><ArrowRight class="size-4" /> Move to {{ stageLabels[detail.data.next_stage] }}</button>
                </template>
                <button class="btn-secondary" @click="detail.open = false">Close</button>
            </template>
        </Modal>

        <ConfirmDialog :open="rejecting.open" title="Reject requisition" danger confirm-label="Reject"
            :message="`Reject ${rejecting.row?.requisition_number ?? ''}? The requester can edit and resubmit it.`"
            reason-label="Reason" :loading="rejecting.loading" @close="rejecting.open = false" @confirm="rejectRequisition" />
    </div>
</template>
