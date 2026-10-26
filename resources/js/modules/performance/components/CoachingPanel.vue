<script setup>
import { ref, onMounted } from 'vue';
import { Plus, Trash2, ArrowUpRight, ClipboardCheck, Lock, Pencil } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { formatDate, titleCase } from '@/lib/format';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import LookupSelect from '@/components/ui/LookupSelect.vue';

/** Performance Boost Program (§6.5): discussion points (optionally raised from a KPI), GROW sessions, PIPs with check-ins. */
const ui = useUiStore();
const view = ref('points');
const views = [
    { key: 'points', label: 'Discussion points' },
    { key: 'sessions', label: 'GROW sessions' },
    { key: 'pips', label: 'PIPs' },
];
const rows = ref([]);
const summary = ref({});
const loading = ref(false);
const kpis = ref([]); // team goals, to raise a point from a KPI

const modal = ref({ open: false, mode: null, row: null });
const form = ref({});
const errors = ref({});
const saving = ref(false);
const detail = ref(null); // PIP detail with check-ins

const pointColors = { open: 'blue', completed: 'green', extended: 'amber', escalated: 'red' };
const pipColors = { active: 'blue', extended: 'amber', completed: 'green', escalated: 'red' };

async function load() {
    loading.value = true;
    try {
        const [{ data }, overview] = await Promise.all([
            api.get(`/performance/coaching/${view.value}`),
            api.get('/performance/coaching'),
        ]);
        rows.value = (data.data ?? []).map((r) => (r.record_id ? { ...r, id: r.record_id } : r));
        summary.value = overview.data.summary ?? {};
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}

function switchView(v) { view.value = v; load(); }

async function loadKpis(employeeId) {
    kpis.value = [];
    if (!employeeId) return;
    try { const { data } = await api.get('/performance/team-goals', { params: { employee_id: employeeId, per_page: 100 } }); kpis.value = data.data ?? []; } catch {}
}

const today = () => new Date().toLocaleDateString('en-CA');

function open(mode, row = null) {
    errors.value = {};
    modal.value = { open: true, mode, row };
    if (mode === 'point') form.value = row ? { activity: row.activity, comment: row.comment ?? '', deadline: row.deadline ?? '', status: row.status } : { employee_id: '', goal_id: '', activity: '', comment: '', deadline: '' };
    if (mode === 'session') form.value = row
        ? { session_date: row.session_date, topic: row.topic, goal: row.goal ?? '', reality: row.reality ?? '', options: row.options ?? '', way_forward: row.way_forward ?? '', supervisor_comment: row.supervisor_comment ?? '', hr_comment: row.hr_comment ?? '', next_meeting_date: row.next_meeting_date ?? '', outcome: row.outcome ?? '' }
        : { employee_id: '', goal_id: '', session_date: today(), topic: '', goal: '', reality: '', options: '', way_forward: '', supervisor_comment: '', next_meeting_date: '' };
    if (mode === 'pip' || mode === 'escalate') form.value = { employee_id: '', concern: '', targets: '', action_plan: '', resources: '', start_date: today(), end_date: '', review_frequency: 'biweekly' };
    if (mode === 'checkin') form.value = { meeting_date: today(), progress_rating: 3, comment: '', recommendation: 'continue', new_end_date: '' };
    if (mode === 'close') form.value = { outcome: 'successful', comment: '' };
}

function request() {
    const { mode, row } = modal.value;
    const f = { ...form.value };
    Object.keys(f).forEach((k) => { if (f[k] === '') f[k] = null; });
    if (mode === 'point') return row ? ['put', `/performance/coaching/points/${row.id}`, f] : ['post', '/performance/coaching/points', f];
    if (mode === 'session') return row ? ['put', `/performance/coaching/sessions/${row.id}`, f] : ['post', '/performance/coaching/sessions', f];
    if (mode === 'pip') return ['post', '/performance/coaching/pips', f];
    if (mode === 'escalate') { delete f.employee_id; return ['post', `/performance/coaching/sessions/${row.id}/escalate`, f]; }
    if (mode === 'checkin') return ['post', `/performance/coaching/pips/${row.id}/checkin`, f];
    if (mode === 'close') return ['post', `/performance/coaching/pips/${row.id}/close`, f];
    return null;
}

async function save() {
    const [method, url, payload] = request();
    saving.value = true; errors.value = {};
    try {
        const { data } = await api[method](url, payload);
        ui.toast(data.message ?? 'Saved.', 'success');
        modal.value.open = false;
        if (detail.value && ['checkin', 'close'].includes(modal.value.mode)) detail.value = data.data;
        await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally { saving.value = false; }
}

async function remove(url) {
    if (!window.confirm('Delete this record?')) return;
    try { await api.delete(url); ui.toast('Deleted.', 'success'); await load(); }
    catch (e) { ui.toast(errorMessage(e), 'error'); }
}

async function openPip(row) {
    try { const { data } = await api.get(`/performance/coaching/pips/${row.id}`); detail.value = data.data; }
    catch (e) { ui.toast(errorMessage(e), 'error'); }
}

onMounted(load);
</script>

<template>
    <div>
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="card p-3"><p class="text-xs text-slate-500">Open points</p><p class="text-xl font-semibold text-slate-900 dark:text-white">{{ summary.open_points ?? 0 }}</p></div>
            <div class="card p-3"><p class="text-xs text-slate-500">Sessions</p><p class="text-xl font-semibold text-slate-900 dark:text-white">{{ summary.sessions ?? 0 }}</p></div>
            <div class="card p-3"><p class="text-xs text-slate-500">Active PIPs</p><p class="text-xl font-semibold text-slate-900 dark:text-white">{{ summary.active_pips ?? 0 }}</p></div>
            <div class="card p-3"><p class="text-xs text-slate-500">Check-ins due</p><p class="text-xl font-semibold" :class="summary.due_checkins ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white'">{{ summary.due_checkins ?? 0 }}</p></div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <Tabs :tabs="views" :model-value="view" @update:model-value="switchView" />
            <button class="btn-primary mb-5" @click="open({ points: 'point', sessions: 'session', pips: 'pip' }[view])">
                <Plus class="size-4" /> {{ { points: 'Discussion point', sessions: 'GROW session', pips: 'New PIP' }[view] }}
            </button>
        </div>

        <div v-if="loading" class="flex justify-center py-16"><Spinner /></div>
        <div v-else-if="!rows.length" class="card"><EmptyState title="Nothing recorded yet" /></div>

        <div v-else-if="view === 'points'" class="space-y-2">
            <div v-for="p in rows" :key="p.id" class="card flex flex-col gap-2 p-4 sm:flex-row sm:items-center">
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-slate-900 dark:text-white">{{ p.activity }} <Badge :color="pointColors[p.status]">{{ p.status }}</Badge> <Badge v-if="p.overdue" color="red">overdue</Badge></p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ p.employee?.name }}<span v-if="p.kpi_name"> · KPI: {{ p.kpi_name }}</span><span v-if="p.deadline"> · by {{ formatDate(p.deadline) }}</span></p>
                    <p v-if="p.comment" class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ p.comment }}</p>
                </div>
                <div v-if="p.can_manage" class="flex gap-1">
                    <button class="btn-ghost !p-1.5" title="Edit" @click="open('point', p)"><Pencil class="size-4" /></button>
                    <button class="btn-ghost !p-1.5 text-rose-600 dark:text-rose-400" title="Delete" @click="remove(`/performance/coaching/points/${p.id}`)"><Trash2 class="size-4" /></button>
                </div>
            </div>
        </div>

        <div v-else-if="view === 'sessions'" class="space-y-2">
            <div v-for="s in rows" :key="s.id" class="card p-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-slate-900 dark:text-white">{{ s.topic }}
                            <Badge :color="s.outcome === 'escalated' ? 'red' : s.outcome === 'completed' ? 'green' : s.outcome === 'extended' ? 'amber' : 'blue'">{{ s.outcome ?? 'open' }}</Badge>
                        </p>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ s.employee?.name }} · {{ formatDate(s.session_date) }}<span v-if="s.next_meeting_date"> · next {{ formatDate(s.next_meeting_date) }}</span><span v-if="s.kpi_name"> · KPI: {{ s.kpi_name }}</span></p>
                        <dl class="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                            <div v-for="k in ['goal', 'reality', 'options', 'way_forward']" v-show="s[k]" :key="k"><dt class="text-xs uppercase text-slate-400">{{ titleCase(k) }}</dt><dd class="text-slate-700 dark:text-slate-200">{{ s[k] }}</dd></div>
                            <div v-if="s.staff_comment"><dt class="text-xs uppercase text-slate-400">Staff comment</dt><dd class="text-slate-700 dark:text-slate-200">{{ s.staff_comment }}</dd></div>
                        </dl>
                    </div>
                    <div v-if="s.can_manage" class="flex flex-wrap gap-1">
                        <button class="btn-ghost !p-1.5" title="Edit" @click="open('session', s)"><Pencil class="size-4" /></button>
                        <button v-if="!s.pip_id" class="btn-secondary !py-1 text-sm" @click="open('escalate', s)"><ArrowUpRight class="size-4" /> Escalate to PIP</button>
                        <button class="btn-ghost !p-1.5 text-rose-600 dark:text-rose-400" title="Delete" @click="remove(`/performance/coaching/sessions/${s.id}`)"><Trash2 class="size-4" /></button>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="space-y-2">
            <div v-for="p in rows" :key="p.id" class="card flex cursor-pointer flex-col gap-2 p-4 hover:ring-1 hover:ring-brand-300 sm:flex-row sm:items-center" @click="openPip(p)">
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-slate-900 dark:text-white">{{ p.employee?.name }} <Badge :color="pipColors[p.status]">{{ p.status }}</Badge></p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ p.title }}</p>
                    <p class="text-xs text-slate-400">{{ formatDate(p.start_date) }} – {{ formatDate(p.end_date) }} · {{ p.checkins_count ?? 0 }} check-in(s)<span v-if="p.next_checkin_date"> · next {{ formatDate(p.next_checkin_date) }}</span></p>
                </div>
            </div>
        </div>

        <!-- PIP detail -->
        <Modal :open="!!detail" :title="`PIP — ${detail?.employee?.name ?? ''}`" size="lg" @close="detail = null">
            <div v-if="detail" class="space-y-4 text-sm">
                <div class="flex flex-wrap gap-2">
                    <Badge :color="pipColors[detail.status]">{{ detail.status }}</Badge>
                    <Badge v-if="detail.outcome" color="slate">outcome: {{ detail.outcome }}</Badge>
                    <Badge v-if="detail.disciplinary_case" color="red">Disciplinary case {{ detail.disciplinary_case.case_number }}</Badge>
                </div>
                <p class="text-slate-500">{{ formatDate(detail.start_date) }} – {{ formatDate(detail.end_date) }} · reviews {{ detail.review_frequency }} · supervisor {{ detail.supervisor_name ?? '—' }}</p>
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div><dt class="text-xs uppercase text-slate-400">Concern</dt><dd class="whitespace-pre-line text-slate-700 dark:text-slate-200">{{ detail.concern }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">Targets</dt><dd class="whitespace-pre-line text-slate-700 dark:text-slate-200">{{ detail.targets }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">Action plan</dt><dd class="whitespace-pre-line text-slate-700 dark:text-slate-200">{{ detail.action_plan }}</dd></div>
                    <div v-if="detail.resources"><dt class="text-xs uppercase text-slate-400">Resources</dt><dd class="whitespace-pre-line text-slate-700 dark:text-slate-200">{{ detail.resources }}</dd></div>
                </dl>
                <div>
                    <p class="mb-2 font-medium text-slate-900 dark:text-white">Check-ins</p>
                    <p v-if="!detail.checkins?.length" class="text-slate-500">No check-ins yet.</p>
                    <div v-for="c in detail.checkins" :key="c.id" class="mb-2 rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
                        <p class="font-medium text-slate-800 dark:text-slate-100">{{ formatDate(c.meeting_date) }} · progress {{ c.progress_rating }}/5 · {{ c.recommendation }}</p>
                        <p v-if="c.comment" class="text-slate-600 dark:text-slate-300">{{ c.comment }}</p>
                        <p class="text-xs text-slate-400">by {{ c.recorded_by ?? '—' }}</p>
                    </div>
                </div>
                <p v-if="detail.outcome_comment" class="text-slate-600 dark:text-slate-300">Closing comment: {{ detail.outcome_comment }}</p>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="detail = null">Close</button>
                <template v-if="detail?.can_manage && ['active', 'extended'].includes(detail?.status)">
                    <button class="btn-secondary" @click="open('checkin', detail)"><ClipboardCheck class="size-4" /> Check-in</button>
                    <button class="btn-primary" @click="open('close', detail)"><Lock class="size-4" /> Close PIP</button>
                </template>
            </template>
        </Modal>

        <!-- Forms -->
        <Modal :open="modal.open" size="lg" @close="modal.open = false"
            :title="{ point: 'Discussion point', session: 'GROW coaching session', pip: 'New performance improvement plan', escalate: 'Escalate to a PIP', checkin: 'PIP check-in', close: 'Close PIP' }[modal.mode]">
            <div class="space-y-4">
                <div v-if="!modal.row && ['point', 'session', 'pip'].includes(modal.mode)">
                    <label class="label">Employee *</label>
                    <LookupSelect v-model="form.employee_id" kind="employees" placeholder="Choose a team member" @update:model-value="loadKpis" />
                    <p v-if="errors.employee_id" class="field-error">{{ errors.employee_id }}</p>
                </div>
                <div v-if="!modal.row && ['point', 'session'].includes(modal.mode) && kpis.length">
                    <label class="label">Raised from KPI (optional)</label>
                    <select v-model="form.goal_id" class="input">
                        <option value="">None</option>
                        <option v-for="g in kpis" :key="g.id" :value="g.id">{{ g.kpi?.name }} ({{ g.status }})</option>
                    </select>
                </div>

                <template v-if="modal.mode === 'point'">
                    <div><label class="label">Activity *</label><input v-model="form.activity" class="input" /><p v-if="errors.activity" class="field-error">{{ errors.activity }}</p></div>
                    <div><label class="label">Comment</label><textarea v-model="form.comment" rows="2" class="input" /></div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="label">Deadline</label><input v-model="form.deadline" type="date" class="input" /></div>
                        <div v-if="modal.row"><label class="label">Status</label>
                            <select v-model="form.status" class="input"><option v-for="s in ['open', 'completed', 'extended', 'escalated']" :key="s" :value="s">{{ titleCase(s) }}</option></select>
                        </div>
                    </div>
                </template>

                <template v-if="modal.mode === 'session'">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="label">Topic *</label><input v-model="form.topic" class="input" /><p v-if="errors.topic" class="field-error">{{ errors.topic }}</p></div>
                        <div><label class="label">Date *</label><input v-model="form.session_date" type="date" class="input" /></div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div v-for="k in ['goal', 'reality', 'options', 'way_forward']" :key="k"><label class="label">{{ k === 'way_forward' ? 'Way forward / action plan' : titleCase(k) }}</label><textarea v-model="form[k]" rows="2" class="input" /></div>
                    </div>
                    <div><label class="label">Supervisor comment</label><textarea v-model="form.supervisor_comment" rows="2" class="input" /></div>
                    <div v-if="modal.row?.can_comment_hr"><label class="label">HR comment</label><textarea v-model="form.hr_comment" rows="2" class="input" /></div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="label">Next meeting</label><input v-model="form.next_meeting_date" type="date" class="input" /></div>
                        <div v-if="modal.row"><label class="label">Outcome</label>
                            <select v-model="form.outcome" class="input"><option value="">Open</option><option value="completed">Completed</option><option value="extended">Extended</option></select>
                        </div>
                    </div>
                </template>

                <template v-if="modal.mode === 'pip' || modal.mode === 'escalate'">
                    <div v-for="k in ['concern', 'targets', 'action_plan', 'resources']" :key="k">
                        <label class="label">{{ titleCase(k) }}{{ k === 'resources' ? '' : ' *' }}</label>
                        <textarea v-model="form[k]" rows="2" class="input" />
                        <p v-if="errors[k]" class="field-error">{{ errors[k] }}</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div><label class="label">Start *</label><input v-model="form.start_date" type="date" class="input" /></div>
                        <div><label class="label">End *</label><input v-model="form.end_date" type="date" class="input" /><p v-if="errors.end_date" class="field-error">{{ errors.end_date }}</p></div>
                        <div><label class="label">Review frequency</label>
                            <select v-model="form.review_frequency" class="input"><option value="weekly">Weekly</option><option value="biweekly">Every 2 weeks</option><option value="monthly">Monthly</option></select>
                        </div>
                    </div>
                </template>

                <template v-if="modal.mode === 'checkin'">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="label">Meeting date *</label><input v-model="form.meeting_date" type="date" class="input" /></div>
                        <div><label class="label">Progress (1–5) *</label><input v-model.number="form.progress_rating" type="number" min="1" max="5" class="input" /></div>
                    </div>
                    <div><label class="label">Comment</label><textarea v-model="form.comment" rows="2" class="input" /></div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="label">Recommendation *</label>
                            <select v-model="form.recommendation" class="input"><option value="continue">Continue</option><option value="extend">Extend</option><option value="complete">Finish PIP</option><option value="escalate">Escalate</option></select>
                        </div>
                        <div v-if="form.recommendation === 'extend'"><label class="label">New end date *</label><input v-model="form.new_end_date" type="date" class="input" /><p v-if="errors.new_end_date" class="field-error">{{ errors.new_end_date }}</p></div>
                    </div>
                </template>

                <template v-if="modal.mode === 'close'">
                    <div><label class="label">Outcome *</label>
                        <select v-model="form.outcome" class="input"><option value="successful">Successful</option><option value="unsuccessful">Unsuccessful</option><option value="escalated">Escalated (opens a disciplinary case)</option></select>
                    </div>
                    <div><label class="label">Comment *</label><textarea v-model="form.comment" rows="3" class="input" /><p v-if="errors.comment" class="field-error">{{ errors.comment }}</p></div>
                </template>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>
    </div>
</template>
