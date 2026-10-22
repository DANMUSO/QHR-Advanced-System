<script setup>
import { ref, computed, onMounted } from 'vue';
import { Plus, ChevronLeft, Trash2, Send, Pencil } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { useAuthStore } from '@/stores/auth';
import { formatDate } from '@/lib/format';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import LookupSelect from '@/components/ui/LookupSelect.vue';
import Spinner from '@/components/ui/Spinner.vue';

/** Assessment plans (§6.1): Plan → Focus areas → Objectives (weights total 100%) → KPIs, and KPI assignment. */
const ui = useUiStore();
const auth = useAuthStore();
const isAdmin = computed(() => auth.can('performance.admin'));

const plans = ref([]);
const loading = ref(false);
const setup = ref({ cycles: [], units: [], frequencies: [], categories: [], current_cycle_id: null });
const plan = ref(null); // open plan (detail)
const team = ref([]);

const modal = ref({ open: false, mode: null, ctx: null });
const form = ref({});
const errors = ref({});
const saving = ref(false);

const planCols = [
    { key: 'name', label: 'Plan', sortable: true },
    { key: 'cycle', label: 'Cycle' },
    { key: 'scope', label: 'For' },
    { key: 'focus_areas_count', label: 'Focus areas', align: 'right' },
    { key: 'kpis_count', label: 'KPIs', align: 'right' },
    { key: 'total_weight', label: 'Weight', align: 'right' },
    { key: 'created_by', label: 'Created by' },
];

const questions = computed(() => setup.value.categories.flatMap((c) => [
    ...(c.questions ?? []).map((q) => ({ ...q, group: c.name })),
    ...(c.children ?? []).flatMap((s) => (s.questions ?? []).map((q) => ({ ...q, group: `${c.name} › ${s.name}` }))),
]));

async function loadSetup() {
    try { const { data } = await api.get('/performance/setup'); setup.value = data; } catch {}
}

async function loadPlans() {
    loading.value = true;
    try { const { data } = await api.get('/performance/plans', { params: { per_page: 100 } }); plans.value = data.data ?? []; }
    finally { loading.value = false; }
}

async function openPlan(row) {
    loading.value = true;
    try { const { data } = await api.get(`/performance/plans/${row.id}`); plan.value = data.data; }
    catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}

async function refreshPlan() {
    if (plan.value) await openPlan(plan.value);
}

function openModal(mode, ctx = null, values = {}) {
    errors.value = {};
    form.value = values;
    modal.value = { open: true, mode, ctx };
    if (mode === 'assign' && !team.value.length) {
        api.get('/performance/team-overview').then(({ data }) => { team.value = data.members ?? []; }).catch(() => {});
    }
}

const newKpi = () => ({ name: '', description: '', type: 'quantitative', kpi_type: 'absolute', unit_id: setup.value.units[0]?.id ?? '', target: '',
    frequency_id: '', due_date: '', is_recurring: false, question_ids: [] });

/** One save handler for every modal: [method, url, payload, after]. */
function request() {
    const { mode, ctx } = modal.value;
    const f = form.value;
    switch (mode) {
        case 'plan': return ctx ? ['put', `/performance/plans/${ctx.id}`, f] : ['post', '/performance/plans', f];
        case 'area': return ctx ? ['put', `/performance/focus-areas/${ctx.id}`, f] : ['post', `/performance/plans/${plan.value.id}/focus-areas`, f];
        case 'objective': return ctx?.id ? ['put', `/performance/objectives/${ctx.id}`, f] : ['post', `/performance/focus-areas/${ctx.area_id}/objectives`, f];
        case 'kpi': return ctx?.id ? ['put', `/performance/kpis/${ctx.id}`, f] : ['post', `/performance/objectives/${ctx.objective_id}/kpis`, f];
        case 'assign': return ['post', `/performance/kpis/${ctx.id}/assign`, {
            mode: f.mode, split: f.split, due_date: f.due_date || null,
            assignees: f.mode === 'self' ? [{ employee_id: f.owner_id || auth.user?.employee?.id }] : f.assignees.map((a) => ({ employee_id: a.employee_id, target: f.split === 'distributed' ? a.target : undefined })),
        }];
        default: return null;
    }
}

async function save() {
    const [method, url, payload] = request();
    saving.value = true; errors.value = {};
    try {
        const { data } = await api[method](url, payload);
        ui.toast(data.message ?? 'Saved.', 'success');
        modal.value.open = false;
        if (modal.value.mode === 'plan' && !modal.value.ctx) await openPlan(data.data);
        else await refreshPlan();
        loadPlans();
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally { saving.value = false; }
}

async function remove(url, label) {
    if (!window.confirm(`Delete ${label}?`)) return;
    try {
        const { data } = await api.delete(url);
        ui.toast(data.message ?? 'Deleted.', 'success');
        if (url === `/performance/plans/${plan.value?.id}`) plan.value = null; else await refreshPlan();
        loadPlans();
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
}

function toggleAssignee(id, name) {
    const list = form.value.assignees;
    const i = list.findIndex((a) => a.employee_id === id);
    i >= 0 ? list.splice(i, 1) : list.push({ employee_id: id, name, target: '' });
}
function addAssignee(id) {
    if (!id || form.value.assignees.some((a) => a.employee_id === Number(id))) return;
    form.value.assignees.push({ employee_id: Number(id), name: `Employee #${id}`, target: '' });
    form.value.extra = '';
}

onMounted(() => { loadSetup(); loadPlans(); });
</script>

<template>
    <div>
        <div v-if="loading && !plans.length && !plan" class="flex justify-center py-16"><Spinner /></div>

        <!-- Plan list -->
        <template v-else-if="!plan">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-slate-500 dark:text-slate-400">Build an assessment plan, weight its objectives to 100%, add KPIs, then cascade them to your team.</p>
                <button class="btn-primary" @click="openModal('plan', null, { name: '', cycle_id: setup.current_cycle_id ?? '', position_id: '', employee_id: '', start_date: '', description: '' })">
                    <Plus class="size-4" /> New plan
                </button>
            </div>
            <DataTable :columns="planCols" :rows="plans" :loading="false" clickable @row-click="openPlan">
                <template #cell-cycle="{ row }">{{ row.cycle?.name ?? '—' }}</template>
                <template #cell-scope="{ row }">{{ row.position ?? row.employee ?? 'Everyone' }}</template>
                <template #cell-total_weight="{ row }">
                    <Badge :color="row.total_weight === 100 ? 'green' : 'amber'">{{ row.total_weight }}%</Badge>
                </template>
            </DataTable>
        </template>

        <!-- Plan detail -->
        <template v-else>
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <button class="btn-ghost !px-2 !py-1 text-sm" @click="plan = null"><ChevronLeft class="size-4" /> All plans</button>
                    <h2 class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ plan.name }}</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        {{ plan.cycle?.name }} · {{ plan.position ?? plan.employee ?? 'Everyone' }}<span v-if="plan.start_date"> · from {{ formatDate(plan.start_date) }}</span> · by {{ plan.created_by ?? '—' }}
                    </p>
                    <p v-if="plan.description" class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ plan.description }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :color="plan.total_weight === 100 ? 'green' : 'amber'">Objective weights {{ plan.total_weight }}% / 100%</Badge>
                    <template v-if="plan.can_edit">
                        <button class="btn-secondary !py-1.5" @click="openModal('plan', plan, { name: plan.name, cycle_id: plan.cycle_id, position_id: plan.position_id ?? '', employee_id: plan.employee_id ?? '', start_date: plan.start_date ?? '', description: plan.description ?? '' })"><Pencil class="size-4" /> Edit</button>
                        <button class="btn-primary !py-1.5" @click="openModal('area', null, { name: '' })"><Plus class="size-4" /> Focus area</button>
                        <button class="btn-ghost !p-1.5 text-rose-600 dark:text-rose-400" title="Delete plan" @click="remove(`/performance/plans/${plan.id}`, 'this plan')"><Trash2 class="size-4" /></button>
                    </template>
                </div>
            </div>

            <div v-if="!plan.focus_areas.length" class="card p-8 text-center text-sm text-slate-500">Add a focus area (for example Capital or Governance) to start.</div>
            <div v-for="fa in plan.focus_areas" :key="fa.id" class="card mb-4 p-4">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="font-semibold text-slate-900 dark:text-white">{{ fa.name }}</p>
                    <div v-if="plan.can_edit" class="flex gap-1">
                        <button class="btn-ghost !py-1 text-sm" @click="openModal('objective', { area_id: fa.id }, { name: '', weight: '' })"><Plus class="size-4" /> Objective</button>
                        <button class="btn-ghost !p-1.5" title="Rename" @click="openModal('area', fa, { name: fa.name })"><Pencil class="size-4" /></button>
                        <button class="btn-ghost !p-1.5 text-rose-600 dark:text-rose-400" title="Delete" @click="remove(`/performance/focus-areas/${fa.id}`, fa.name)"><Trash2 class="size-4" /></button>
                    </div>
                </div>
                <p v-if="!fa.objectives.length" class="text-sm text-slate-500">No objectives yet.</p>
                <div v-for="o in fa.objectives" :key="o.id" class="mb-3 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-medium text-slate-800 dark:text-slate-100">{{ o.name }} <Badge color="brand">{{ o.weight }}%</Badge></p>
                        <div v-if="plan.can_edit" class="flex gap-1">
                            <button class="btn-ghost !py-1 text-sm" @click="openModal('kpi', { objective_id: o.id }, newKpi())"><Plus class="size-4" /> KPI</button>
                            <button class="btn-ghost !p-1.5" title="Edit" @click="openModal('objective', o, { name: o.name, weight: o.weight })"><Pencil class="size-4" /></button>
                            <button class="btn-ghost !p-1.5 text-rose-600 dark:text-rose-400" title="Delete" @click="remove(`/performance/objectives/${o.id}`, o.name)"><Trash2 class="size-4" /></button>
                        </div>
                    </div>
                    <div v-for="k in o.kpis" :key="k.id" class="mt-2 flex flex-col gap-2 rounded-lg bg-slate-50 p-3 sm:flex-row sm:items-center dark:bg-slate-800/60">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-800 dark:text-slate-100">{{ k.name }}
                                <Badge :color="k.type === 'qualitative' ? 'sky' : 'slate'">{{ k.type }}</Badge>
                                <Badge v-if="k.is_recurring" color="purple">recurring</Badge>
                            </p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                <template v-if="k.type === 'quantitative'">Target {{ k.target }} {{ k.unit?.name }} · {{ k.kpi_type ?? 'absolute' }}</template>
                                <template v-else>{{ k.questions?.length ?? 0 }} question(s)</template>
                                <span v-if="k.frequency"> · {{ k.frequency.name }}</span> · due {{ formatDate(k.due_date) }} · assigned to {{ k.goals_count }} ({{ k.approved_goals_count }} approved)
                            </p>
                        </div>
                        <div class="flex gap-1">
                            <button class="btn-secondary !py-1 text-sm" :disabled="plan.total_weight !== 100" :title="plan.total_weight !== 100 ? 'Objective weights must total 100% first' : 'Assign'"
                                @click="openModal('assign', k, { mode: 'cascade', split: 'equal', assignees: [], due_date: '', owner_id: '', extra: '' })"><Send class="size-4" /> Assign</button>
                            <template v-if="plan.can_edit">
                                <button class="btn-ghost !p-1.5" title="Edit" @click="openModal('kpi', k, { name: k.name, description: k.description ?? '', type: k.type, kpi_type: k.kpi_type ?? 'absolute', unit_id: k.unit_id ?? '', target: k.target ?? '', frequency_id: k.frequency_id ?? '', due_date: k.due_date, is_recurring: !!k.is_recurring, question_ids: (k.questions ?? []).map((q) => q.id) })"><Pencil class="size-4" /></button>
                                <button class="btn-ghost !p-1.5 text-rose-600 dark:text-rose-400" title="Delete" @click="remove(`/performance/kpis/${k.id}`, k.name)"><Trash2 class="size-4" /></button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <Modal :open="modal.open" :size="modal.mode === 'kpi' || modal.mode === 'assign' ? 'lg' : 'md'"
            :title="{ plan: modal.ctx ? 'Edit plan' : 'New assessment plan', area: 'Focus area', objective: 'Strategic objective', kpi: 'KPI / activity', assign: `Assign: ${modal.ctx?.name ?? ''}` }[modal.mode]"
            @close="modal.open = false">
            <div class="space-y-4">
                <template v-if="modal.mode === 'plan'">
                    <div><label class="label">Plan name *</label><input v-model="form.name" class="input" /><p v-if="errors.name" class="field-error">{{ errors.name }}</p></div>
                    <div>
                        <label class="label">Review cycle *</label>
                        <select v-model="form.cycle_id" class="input">
                            <option value="">Choose a cycle</option>
                            <option v-for="c in setup.cycles" :key="c.id" :value="c.id">{{ c.name }} ({{ c.status }})</option>
                        </select>
                        <p v-if="errors.cycle_id" class="field-error">{{ errors.cycle_id }}</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="label">Position (optional)</label><LookupSelect v-model="form.position_id" kind="positions" placeholder="Any position" /></div>
                        <div><label class="label">Or one employee</label><LookupSelect v-model="form.employee_id" kind="employees" placeholder="Nobody in particular" /></div>
                    </div>
                    <p v-if="errors.employee_id" class="field-error">{{ errors.employee_id }}</p>
                    <div><label class="label">Start date</label><input v-model="form.start_date" type="date" class="input" /></div>
                    <div><label class="label">Description</label><textarea v-model="form.description" rows="2" class="input" /></div>
                </template>

                <template v-else-if="modal.mode === 'area'">
                    <div><label class="label">Name *</label><input v-model="form.name" class="input" placeholder="e.g. Capital, Governance" /><p v-if="errors.name" class="field-error">{{ errors.name }}</p></div>
                </template>

                <template v-else-if="modal.mode === 'objective'">
                    <div><label class="label">Objective *</label><input v-model="form.name" class="input" /><p v-if="errors.name" class="field-error">{{ errors.name }}</p></div>
                    <div>
                        <label class="label">Weight (%) *</label>
                        <input v-model="form.weight" type="number" min="0" max="100" step="any" class="input" />
                        <p class="mt-1 text-xs text-slate-500">Objective weights in a plan must add up to 100%. Currently {{ plan?.total_weight ?? 0 }}%.</p>
                        <p v-if="errors.weight" class="field-error">{{ errors.weight }}</p>
                    </div>
                </template>

                <template v-else-if="modal.mode === 'kpi'">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2"><label class="label">Name *</label><input v-model="form.name" class="input" /><p v-if="errors.name" class="field-error">{{ errors.name }}</p></div>
                        <div>
                            <label class="label">Type *</label>
                            <select v-model="form.type" class="input" :disabled="!!modal.ctx?.id && modal.ctx.goals_count > 0">
                                <option value="quantitative">Quantitative (target value)</option>
                                <option value="qualitative">Qualitative (questions)</option>
                            </select>
                        </div>
                        <div><label class="label">End / due date *</label><input v-model="form.due_date" type="date" class="input" /><p v-if="errors.due_date" class="field-error">{{ errors.due_date }}</p></div>
                        <template v-if="form.type === 'quantitative'">
                            <div>
                                <label class="label">Unit *</label>
                                <select v-model="form.unit_id" class="input"><option v-for="u in setup.units" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                                <p v-if="errors.unit_id" class="field-error">{{ errors.unit_id }}</p>
                            </div>
                            <div><label class="label">Target *</label><input v-model="form.target" type="number" min="0" step="any" class="input" /><p v-if="errors.target" class="field-error">{{ errors.target }}</p></div>
                            <div>
                                <label class="label">KPI type</label>
                                <select v-model="form.kpi_type" class="input"><option value="absolute">Absolute</option><option value="percentage">Percentage</option></select>
                            </div>
                        </template>
                        <div>
                            <label class="label">Frequency</label>
                            <select v-model="form.frequency_id" class="input">
                                <option value="">None</option>
                                <option v-for="fq in setup.frequencies" :key="fq.id" :value="fq.id">{{ fq.name }} ({{ fq.days }} days)</option>
                            </select>
                        </div>
                    </div>
                    <div v-if="form.type === 'qualitative'">
                        <label class="label">Questions from the question bank *</label>
                        <div class="max-h-56 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2 dark:border-slate-700">
                            <label v-for="q in questions" :key="q.id" class="flex items-start gap-2 rounded px-1 py-1 text-sm hover:bg-slate-50 dark:hover:bg-slate-800">
                                <input v-model="form.question_ids" type="checkbox" :value="q.id" class="mt-0.5 rounded border-slate-300 dark:border-slate-600" />
                                <span><span class="text-xs text-slate-400">{{ q.group }}</span><br />{{ q.question }}</span>
                            </label>
                        </div>
                        <p v-if="errors.question_ids" class="field-error">{{ errors.question_ids }}</p>
                    </div>
                    <div><label class="label">Description</label><textarea v-model="form.description" rows="2" class="input" /></div>
                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input v-model="form.is_recurring" type="checkbox" class="rounded border-slate-300 dark:border-slate-600" /> Recurring (cloned for the next period when it ends)
                    </label>
                </template>

                <template v-else-if="modal.mode === 'assign'">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label">Assignment mode</label>
                            <select v-model="form.mode" class="input"><option value="cascade">Cascade to staff</option><option value="self">Self (owner scores it)</option></select>
                        </div>
                        <div v-if="form.mode === 'cascade'">
                            <label class="label">Split</label>
                            <select v-model="form.split" class="input" :disabled="modal.ctx?.type === 'qualitative'">
                                <option value="equal">Equal — full target each</option>
                                <option value="distributed">Distributed — share the target</option>
                            </select>
                        </div>
                        <div><label class="label">Due date (optional)</label><input v-model="form.due_date" type="date" class="input" /></div>
                    </div>
                    <div v-if="form.mode === 'self'">
                        <label class="label">Owner</label>
                        <LookupSelect v-model="form.owner_id" kind="employees" placeholder="Me" />
                    </div>
                    <div v-else>
                        <label class="label">Direct reports</label>
                        <p v-if="!team.length" class="text-sm text-slate-500">No direct reports found.</p>
                        <label v-for="m in team" :key="m.id" class="flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-slate-50 dark:hover:bg-slate-800">
                            <input type="checkbox" class="rounded border-slate-300 dark:border-slate-600" :checked="form.assignees.some((a) => a.employee_id === m.id)" @change="toggleAssignee(m.id, m.name)" />
                            <span class="flex-1 text-sm">{{ m.name }}</span>
                        </label>
                        <div v-if="isAdmin" class="mt-2">
                            <label class="label">Add another employee (HR)</label>
                            <LookupSelect v-model="form.extra" kind="employees" placeholder="Search employees" @update:model-value="addAssignee" />
                        </div>
                        <div v-if="form.assignees.length" class="mt-3 space-y-2">
                            <div v-for="a in form.assignees" :key="a.employee_id" class="flex items-center gap-2 text-sm">
                                <span class="flex-1">{{ a.name }}</span>
                                <input v-if="form.split === 'distributed'" v-model="a.target" type="number" min="0" step="any" class="input !w-28 !py-1" placeholder="Target" />
                                <button class="btn-ghost !p-1" @click="toggleAssignee(a.employee_id)"><Trash2 class="size-4" /></button>
                            </div>
                            <p v-if="form.split === 'distributed'" class="text-xs text-slate-500">Shares must not exceed the KPI target ({{ modal.ctx?.target }}), except for % units.</p>
                        </div>
                    </div>
                    <p v-if="errors.assignees || errors.plan" class="field-error">{{ errors.assignees ?? errors.plan }}</p>
                </template>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>
    </div>
</template>
