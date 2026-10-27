<script setup>
import { useFormErrors } from '@/lib/formErrors';
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Plus, Play, Lock, Download, Award, CheckCircle2 } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { useAuthStore } from '@/stores/auth';
import { formatDate, titleCase } from '@/lib/format';
import { openServerFile } from '@/lib/export';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';
import LookupSelect from '@/components/ui/LookupSelect.vue';
import GoalList from '../components/GoalList.vue';
import PlansPanel from '../components/PlansPanel.vue';
import CoachingPanel from '../components/CoachingPanel.vue';

/** Supervisor + HR performance workspace (§6.1–6.5). Admin-only tabs are hidden for supervisors. */
const ui = useUiStore();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const isAdmin = computed(() => auth.can('performance.admin'));

const tabs = computed(() => [
    { key: 'approvals', label: 'Approvals' },
    { key: 'team', label: 'Team KPIs' },
    { key: 'plans', label: 'Assessment Plans' },
    { key: 'coaching', label: 'Coaching & PIP' },
    { key: 'appraisals', label: 'Appraisals' },
    { key: 'reviews', label: '360° Feedback' },
    { key: 'recognition', label: 'Recognition' },
    { key: 'cycles', label: 'Review Cycles' },
    ...(isAdmin.value ? [{ key: 'calibration', label: 'Calibration' }, { key: 'reports', label: 'Reports' }] : []),
]);
const tab = ref('approvals');
const rows = ref([]);
const loading = ref(false);
const cycles = ref([]);
const cycleId = ref('');
const teamStatus = ref('');
const bands = ref([]);

const modal = ref({ open: false, mode: null, row: null });
const form = ref({});
const errors = useFormErrors(() => form.value);
const saving = ref(false);

const cycleColors = { draft: 'slate', active: 'green', closed: 'blue' };
const appraisalColors = { pending: 'slate', self_review: 'amber', manager_review: 'purple', completed: 'green' };
const categoryColors = { appreciation: 'brand', star_performer: 'amber', innovation: 'blue', teamwork: 'green', customer_service: 'purple', leadership: 'red' };

const cycleCols = [
    { key: 'name', label: 'Cycle', sortable: true }, { key: 'type', label: 'Type' }, { key: 'period_start', label: 'Start' },
    { key: 'period_end', label: 'End' }, { key: 'participants', label: 'Participants', align: 'right' },
    { key: 'completion_pct', label: 'Completion', align: 'right' }, { key: 'status', label: 'Status' },
];
const appraisalCols = [
    { key: 'employee_name', label: 'Employee', sortable: true }, { key: 'kpi_score', label: 'KPI', align: 'right' },
    { key: 'competency_score', label: 'Competency', align: 'right' }, { key: 'final_score', label: 'Final', align: 'right' },
    { key: 'rating_band', label: 'Band' }, { key: 'status', label: 'Status' },
];
const calibrationCols = [
    { key: 'employee_name', label: 'Employee', sortable: true }, { key: 'department', label: 'Department' },
    { key: 'score', label: 'Score', align: 'right' }, { key: 'manager_rating', label: 'Supervisor band' },
    { key: 'calibrated_rating', label: 'Calibrated band' },
];
const feedbackCols = [
    { key: 'subject_name', label: 'Subject', sortable: true }, { key: 'title', label: 'Review' }, { key: 'responses', label: 'Responses', align: 'right' },
    { key: 'due_date', label: 'Due' }, { key: 'status', label: 'Status' },
];
const reportCols = [
    { key: 'employee_name', label: 'Employee', sortable: true }, { key: 'department', label: 'Department' },
    { key: 'goals_count', label: 'KPIs', align: 'right' }, { key: 'approved', label: 'Done', align: 'right' },
    { key: 'not_done', label: 'Not done', align: 'right' }, { key: 'not_accepted', label: 'Not accepted', align: 'right' },
    { key: 'not_approved', label: 'Not approved', align: 'right' }, { key: 'under_review', label: 'Under review', align: 'right' },
    { key: 'avg_score', label: 'KPI score', align: 'right' }, { key: 'final_score', label: 'Final', align: 'right' },
    { key: 'overall_rating', label: 'Band' },
];

async function loadCycles() {
    try {
        const { data } = await api.get('/performance/cycles');
        cycles.value = data.data ?? [];
        if (!cycleId.value) cycleId.value = (cycles.value.find((c) => c.status === 'active') ?? cycles.value[0])?.id ?? '';
    } catch { cycles.value = []; }
}

const endpoints = {
    approvals: () => api.get('/performance/approvals'),
    team: () => api.get('/performance/team-goals', { params: { per_page: 200, status: teamStatus.value || undefined } }),
    appraisals: () => api.get('/performance/appraisals', { params: { cycle_id: cycleId.value || undefined } }),
    reviews: () => api.get('/performance/reviews', { params: { per_page: 200 } }),
    recognition: () => api.get('/performance/recognition', { params: { per_page: 60 } }),
    calibration: () => api.get('/performance/calibration', { params: { cycle_id: cycleId.value || undefined } }),
    reports: () => api.get('/performance/reports', { params: { cycle_id: cycleId.value || undefined } }),
};

async function load() {
    if (['plans', 'coaching'].includes(tab.value)) return; // self-loading panels
    loading.value = true; rows.value = [];
    try {
        if (tab.value === 'cycles') { await loadCycles(); rows.value = cycles.value; return; }
        const { data } = await endpoints[tab.value]();
        rows.value = data.data ?? [];
        if (tab.value === 'calibration') bands.value = data.bands ?? [];
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}

function switchTab(t) {
    tab.value = t;
    router.replace({ query: { ...route.query, tab: t } });
    load();
}
function open(mode, row = null) {
    errors.value = {};
    modal.value = { open: true, mode, row };
    if (mode === 'cycle') form.value = { name: '', type: 'annual', period_start: '', period_end: '', kpi_weight: 70, competency_weight: 30 };
    if (mode === 'finalize') form.value = { supervisor_comment: row?.supervisor_comment ?? '' };
    if (mode === 'calibrate') form.value = { calibrated_rating: row?.calibrated_rating ?? '', note: row?.calibration_note ?? '' };
    if (mode === 'review') form.value = { employee_id: '', due_date: '', title: '' };
    if (mode === 'recognition') form.value = { employee_id: '', title: '', category: 'appreciation', message: '' };
}

function request() {
    const { mode, row } = modal.value;
    const f = form.value;
    if (mode === 'cycle') return ['post', '/performance/cycles', f];
    if (mode === 'finalize') return ['post', '/performance/appraisals/finalize', { employee_id: row.employee.id, cycle_id: cycleId.value, supervisor_comment: f.supervisor_comment }];
    if (mode === 'calibrate') return ['post', `/performance/calibration/${row.id}`, { calibrated_rating: f.calibrated_rating || null, note: f.note || null }];
    if (mode === 'review') return ['post', '/performance/reviews', { ...f, title: f.title || null }];
    if (mode === 'recognition') return ['post', '/performance/recognition', f];
    return null;
}

async function save() {
    const [method, url, payload] = request();
    saving.value = true; errors.value = {};
    try {
        const { data } = await api[method](url, payload);
        ui.toast(data.message ?? 'Saved.', 'success');
        modal.value.open = false;
        await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally { saving.value = false; }
}

async function cycleAction(row, action) {
    try {
        const { data } = await api.post(`/performance/cycles/${row.id}/${action}`);
        ui.toast(data.message, 'success');
        await load();
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
}

async function changeCycle() { await load(); }

onMounted(async () => {
    if (tabs.value.some((t) => t.key === route.query.tab)) tab.value = route.query.tab;
    await loadCycles();
    load();
});
</script>

<template>
    <div>
        <PageHeader title="Performance Management">
            <template #actions>
                <select v-if="['appraisals', 'calibration', 'reports'].includes(tab)" v-model="cycleId" class="input w-56" @change="changeCycle">
                    <option v-for="c in cycles" :key="c.id" :value="c.id">{{ c.name }} ({{ c.status }})</option>
                </select>
                <button v-if="tab === 'reports' && cycleId" class="btn-secondary" @click="openServerFile(`/api/performance/reports/export?cycle_id=${cycleId}`)"><Download class="size-4" /> Export</button>
                <button v-if="tab === 'cycles' && isAdmin" class="btn-primary" @click="open('cycle')"><Plus class="size-4" /> New cycle</button>
                <button v-if="tab === 'reviews'" class="btn-primary" @click="open('review')"><Plus class="size-4" /> Assign review</button>
                <button v-if="tab === 'recognition'" class="btn-primary" @click="open('recognition')"><Award class="size-4" /> Recognise someone</button>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <PlansPanel v-if="tab === 'plans'" />
        <CoachingPanel v-else-if="tab === 'coaching'" />
        <div v-else-if="loading" class="flex justify-center py-20"><Spinner /></div>

        <GoalList v-else-if="tab === 'approvals'" :goals="rows" show-employee empty-title="Nothing waiting for you"
            empty-text="Submitted KPI results and review requests that need your decision appear here." @changed="load" />

        <template v-else-if="tab === 'team'">
            <div class="mb-4 flex flex-wrap gap-2">
                <select v-model="teamStatus" class="input w-56" @change="load">
                    <option value="">All statuses</option>
                    <option value="cascaded">Awaiting acceptance</option>
                    <option value="in_progress">In progress</option>
                    <option value="under_review">Under review</option>
                    <option value="submitted">Submitted</option>
                    <option value="approved">Approved</option>
                </select>
            </div>
            <GoalList :goals="rows" show-employee empty-title="No team KPIs" empty-text="Assign KPIs from an assessment plan." @changed="load" />
        </template>

        <DataTable v-else-if="tab === 'appraisals'" :columns="appraisalCols" :rows="rows" :loading="false" export-name="appraisals">
            <template #cell-employee_name="{ row }">
                <div class="flex items-center gap-2"><Avatar :src="row.employee?.photo_url" :name="row.employee_name" size="sm" />{{ row.employee_name }}</div>
            </template>
            <template #cell-rating_band="{ row }">{{ row.rating_band ?? '—' }}<span v-if="row.is_preview" class="text-xs text-slate-400"> (live)</span></template>
            <template #cell-status="{ row }">
                <Badge :color="appraisalColors[row.status] ?? 'slate'">{{ row.status_label ?? row.status }}</Badge>
                <Badge v-if="row.acknowledged_at" color="green" class="ml-1">acknowledged</Badge>
            </template>
            <template #actions="{ row }">
                <button v-if="!row.acknowledged_at" class="btn-secondary !py-1 text-sm" @click="open('finalize', row)">
                    <CheckCircle2 class="size-4" /> {{ row.status === 'completed' ? 'Re-finalise' : 'Finalise' }}
                </button>
            </template>
        </DataTable>

        <DataTable v-else-if="tab === 'calibration'" :columns="calibrationCols" :rows="rows" :loading="false" export-name="calibration">
            <template #cell-calibrated_rating="{ row }">
                <span v-if="row.calibrated_rating" class="font-medium">{{ row.calibrated_rating }}</span><span v-else class="text-slate-400">—</span>
                <p v-if="row.calibration_note" class="text-xs text-slate-400">{{ row.calibration_note }}</p>
            </template>
            <template #actions="{ row }">
                <button class="btn-secondary !py-1 text-sm" @click="open('calibrate', row)">Calibrate</button>
            </template>
        </DataTable>

        <DataTable v-else-if="tab === 'reviews'" :columns="feedbackCols" :rows="rows" :loading="false" export-name="reviews">
            <template #cell-due_date="{ row }">{{ formatDate(row.due_date) }}</template>
            <template #cell-status="{ row }"><Badge :color="row.status === 'completed' ? 'green' : row.status === 'pending' ? 'amber' : 'blue'">{{ titleCase(row.status) }}</Badge></template>
        </DataTable>

        <template v-else-if="tab === 'recognition'">
            <div v-if="!rows.length" class="card p-10 text-center text-slate-400">No recognition yet.</div>
            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="item in rows" :key="item.id" class="card p-5">
                    <div class="mb-3 flex items-center gap-3">
                        <Avatar :src="item.employee?.photo_url" :name="item.employee?.name" size="md" />
                        <div>
                            <p class="font-semibold text-slate-900 dark:text-white">{{ item.employee?.name }}</p>
                            <p class="text-xs text-slate-400">{{ item.employee?.position }}</p>
                        </div>
                    </div>
                    <Badge :color="categoryColors[item.category] ?? 'slate'">{{ titleCase(item.category) }}</Badge>
                    <p v-if="item.title" class="mt-2 font-medium text-slate-800 dark:text-slate-100">{{ item.title }}</p>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ item.message }}</p>
                    <p class="mt-2 text-xs text-slate-400">By {{ item.awarded_by }} · {{ formatDate(item.created_at) }}</p>
                </div>
            </div>
        </template>

        <DataTable v-else-if="tab === 'cycles'" :columns="cycleCols" :rows="rows" :loading="false">
            <template #cell-type="{ row }">{{ titleCase(row.type ?? '') }}</template>
            <template #cell-completion_pct="{ row }">{{ row.completion_pct ?? 0 }}%</template>
            <template #cell-status="{ row }"><Badge :color="cycleColors[row.status] ?? 'slate'">{{ row.status }}</Badge></template>
            <template v-if="isAdmin" #actions="{ row }">
                <button v-if="row.status === 'draft'" class="btn-ghost !p-1.5 text-emerald-600" title="Activate" @click="cycleAction(row, 'activate')"><Play class="size-4" /></button>
                <button v-if="row.status === 'active'" class="btn-ghost !p-1.5" title="Close" @click="cycleAction(row, 'close')"><Lock class="size-4" /></button>
            </template>
        </DataTable>

        <DataTable v-else-if="tab === 'reports'" :columns="reportCols" :rows="rows" :loading="false" export-name="performance_report" />

        <Modal :open="modal.open" @close="modal.open = false"
            :title="{ cycle: 'New review cycle', finalize: `Finalise appraisal — ${modal.row?.employee_name ?? ''}`, calibrate: `Calibrate — ${modal.row?.employee_name ?? ''}`, review: 'Assign a 360° / competency review', recognition: 'Recognise a colleague' }[modal.mode]">
            <div class="space-y-4">
                <template v-if="modal.mode === 'cycle'">
                    <div><label class="label">Name *</label><input v-model="form.name" class="input" /><p v-if="errors.name" class="field-error">{{ errors.name }}</p></div>
                    <div><label class="label">Type</label>
                        <select v-model="form.type" class="input"><option value="annual">Annual</option><option value="mid_year">Mid-year</option><option value="quarterly">Quarterly</option><option value="probation">Probation</option></select>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="label">Start *</label><input v-model="form.period_start" type="date" class="input" /><p v-if="errors.start_date" class="field-error">{{ errors.start_date }}</p></div>
                        <div><label class="label">End *</label><input v-model="form.period_end" type="date" class="input" /><p v-if="errors.end_date" class="field-error">{{ errors.end_date }}</p></div>
                        <div><label class="label">KPI weight %</label><input v-model="form.kpi_weight" type="number" min="0" max="100" class="input" /></div>
                        <div><label class="label">Competency weight %</label><input v-model="form.competency_weight" type="number" min="0" max="100" class="input" /></div>
                    </div>
                    <p v-if="errors.kpi_weight" class="field-error">{{ errors.kpi_weight }}</p>
                </template>

                <template v-if="modal.mode === 'finalize'">
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        KPI {{ modal.row?.kpi_score ?? '—' }} · competency {{ modal.row?.competency_score ?? '—' }} · final {{ modal.row?.final_score ?? '—' }} ({{ modal.row?.rating_band ?? '—' }}).
                        The employee is notified and asked to acknowledge.
                    </p>
                    <div><label class="label">Summary comment *</label><textarea v-model="form.supervisor_comment" rows="4" class="input" /><p v-if="errors.supervisor_comment" class="field-error">{{ errors.supervisor_comment }}</p></div>
                </template>

                <template v-if="modal.mode === 'calibrate'">
                    <p class="text-sm text-slate-500 dark:text-slate-400">Score {{ modal.row?.score }} · supervisor band {{ modal.row?.manager_rating }}</p>
                    <div><label class="label">Calibrated band</label>
                        <select v-model="form.calibrated_rating" class="input"><option value="">Keep supervisor band</option><option v-for="b in bands" :key="b" :value="b">{{ b }}</option></select>
                    </div>
                    <div><label class="label">Note</label><textarea v-model="form.note" rows="3" class="input" /></div>
                </template>

                <template v-if="modal.mode === 'review'">
                    <div><label class="label">Employee *</label><LookupSelect v-model="form.employee_id" kind="employees" placeholder="Choose a team member" /><p v-if="errors.employee_id" class="field-error">{{ errors.employee_id }}</p></div>
                    <div><label class="label">Title</label><input v-model="form.title" class="input" placeholder="Defaults to the cycle's competency review" /></div>
                    <div><label class="label">Due date *</label><input v-model="form.due_date" type="date" class="input" /><p v-if="errors.due_date" class="field-error">{{ errors.due_date }}</p></div>
                </template>

                <template v-if="modal.mode === 'recognition'">
                    <div><label class="label">Colleague *</label><LookupSelect v-model="form.employee_id" kind="employees" placeholder="Who are you recognising?" /><p v-if="errors.employee_id" class="field-error">{{ errors.employee_id }}</p></div>
                    <div><label class="label">Title</label><input v-model="form.title" class="input" maxlength="150" placeholder="e.g. Above and beyond" /></div>
                    <div><label class="label">Category</label>
                        <select v-model="form.category" class="input"><option v-for="c in Object.keys(categoryColors)" :key="c" :value="c">{{ titleCase(c) }}</option></select>
                    </div>
                    <div><label class="label">Message *</label><textarea v-model="form.message" rows="3" class="input" /><p v-if="errors.message" class="field-error">{{ errors.message }}</p></div>
                </template>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>
    </div>
</template>
