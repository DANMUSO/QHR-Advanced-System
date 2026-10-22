<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Award, CheckCircle2, GraduationCap, MessageSquare } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { formatDate, titleCase } from '@/lib/format';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import LookupSelect from '@/components/ui/LookupSelect.vue';
import GoalList from '../components/GoalList.vue';

/** Employee performance self-service (§6.3): KPIs, appraisals, coaching / PIP, recognition and learning. */
const ui = useUiStore();
const route = useRoute();
const router = useRouter();
const tabs = [
    { key: 'goals', label: 'My KPIs' },
    { key: 'appraisals', label: 'Appraisals' },
    { key: 'coaching', label: 'Coaching' },
    { key: 'recognition', label: 'Recognition' },
    { key: 'learning', label: 'Learning' },
];
const tab = ref('goals');
const loading = ref(false);
const goals = ref([]);
const summary = ref({});
const appraisals = ref([]);
const coaching = ref({ points: [], sessions: [], pips: [] });
const recognition = ref({ received: [], given: [] });
const catalogue = ref([]);
const training = ref([]);

const modal = ref({ open: false, mode: null, row: null });
const form = ref({});
const errors = ref({});
const saving = ref(false);

async function load() {
    loading.value = true;
    try {
        if (tab.value === 'goals') {
            const { data } = await api.get('/performance/my-goals', { params: { per_page: 100 } });
            goals.value = data.data ?? []; summary.value = data.summary ?? {};
        } else if (tab.value === 'appraisals') {
            appraisals.value = (await api.get('/performance/my-appraisals')).data.data ?? [];
        } else if (tab.value === 'coaching') {
            coaching.value = (await api.get('/performance/my-coaching')).data;
        } else if (tab.value === 'recognition') {
            recognition.value = (await api.get('/performance/recognition/my')).data;
        } else if (tab.value === 'learning') {
            const [c, m] = await Promise.all([api.get('/performance/learning/catalogue'), api.get('/performance/learning/my')]);
            catalogue.value = c.data.data ?? []; training.value = m.data.data ?? [];
        }
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}

function switchTab(t) { tab.value = t; router.replace({ query: { tab: t } }); load(); }

function open(mode, row = null) {
    errors.value = {};
    modal.value = { open: true, mode, row };
    if (mode === 'acknowledge') form.value = { comment: '' };
    if (mode === 'staff_comment') form.value = { staff_comment: row.staff_comment ?? '' };
    if (mode === 'recognise') form.value = { employee_id: '', title: '', message: '' };
    if (mode === 'progress') form.value = { progress: row.progress ?? 0, status: row.status };
}

async function save() {
    const { mode, row } = modal.value;
    const call = {
        acknowledge: () => api.post(`/performance/appraisals/${row.id}/acknowledge`, form.value),
        staff_comment: () => api.put(`/performance/coaching/sessions/${row.id}`, form.value),
        recognise: () => api.post('/performance/recognition', form.value),
        progress: () => api.put(`/performance/learning/my/${row.id}`, { progress: Number(form.value.progress), status: form.value.status }),
    }[mode];
    saving.value = true; errors.value = {};
    try {
        const { data } = await call();
        ui.toast(data.message ?? 'Saved.', 'success');
        modal.value.open = false;
        await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally { saving.value = false; }
}

async function enrol(course) {
    try { const { data } = await api.post(`/performance/learning/catalogue/${course.id}/enrol`); ui.toast(data.message, 'success'); await load(); }
    catch (e) { ui.toast(errorMessage(e), 'error'); }
}

async function startSelfReview(a) {
    try { const { data } = await api.post(`/performance/appraisals/${a.id}/self-review`); ui.toast(data.message, 'success'); router.push({ path: '/portal', query: { tab: 'appraisals', review: data.questionnaire_id } }); }
    catch (e) { ui.toast(errorMessage(e), 'error'); }
}

onMounted(() => {
    if (tabs.some((t) => t.key === route.query.tab)) tab.value = route.query.tab;
    load();
});
</script>

<template>
    <div>
        <PageHeader title="My Performance" />
        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />
        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>

        <template v-else-if="tab === 'goals'">
            <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="card p-3"><p class="text-xs text-slate-500">KPIs</p><p class="text-xl font-semibold text-slate-900 dark:text-white">{{ summary.total ?? 0 }}</p></div>
                <div class="card p-3"><p class="text-xs text-slate-500">Completed</p><p class="text-xl font-semibold text-slate-900 dark:text-white">{{ summary.completion_pct ?? 0 }}%</p></div>
                <div class="card p-3"><p class="text-xs text-slate-500">KPI score</p><p class="text-xl font-semibold text-slate-900 dark:text-white">{{ summary.kpi_score ?? '—' }}</p></div>
                <div class="card p-3"><p class="text-xs text-slate-500">Due in 7 days / overdue</p><p class="text-xl font-semibold" :class="summary.overdue ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white'">{{ summary.due_soon ?? 0 }} / {{ summary.overdue ?? 0 }}</p></div>
            </div>
            <GoalList :goals="goals" empty-title="No KPIs assigned yet" empty-text="KPIs your supervisor cascades to you appear here." @changed="load" />
        </template>

        <template v-else-if="tab === 'appraisals'">
            <div v-if="!appraisals.length" class="card"><EmptyState title="No appraisals yet" /></div>
            <div v-for="a in appraisals" :key="a.id" class="card mb-3 p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <div class="flex-1">
                        <p class="font-semibold text-slate-900 dark:text-white">{{ a.cycle_name }} <Badge :color="a.status === 'completed' ? 'green' : 'amber'">{{ a.status_label }}</Badge></p>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ formatDate(a.period_start) }} – {{ formatDate(a.period_end) }}<span v-if="a.is_preview"> · live preview</span></p>
                        <p class="mt-2 text-sm text-slate-700 dark:text-slate-200">KPI {{ a.kpi_score ?? '—' }} ({{ a.kpi_weight }}%) · Competency {{ a.competency_score ?? '—' }} ({{ a.competency_weight }}%) · Final <strong>{{ a.final_score ?? '—' }}</strong> {{ a.rating_band ? `· ${a.rating_band}` : '' }}</p>
                        <p v-if="a.manager_comment" class="mt-1 text-sm text-slate-600 dark:text-slate-300">Supervisor: {{ a.manager_comment }}</p>
                        <p v-if="a.acknowledged_at" class="mt-1 text-xs text-emerald-600 dark:text-emerald-400">Acknowledged {{ formatDate(a.acknowledged_at) }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button v-if="a.status !== 'completed' && a.cycle_status === 'active'" class="btn-secondary !py-1.5" @click="startSelfReview(a)"><MessageSquare class="size-4" /> Self-review</button>
                        <button v-if="a.status === 'completed' && !a.acknowledged_at" class="btn-primary !py-1.5" @click="open('acknowledge', a)"><CheckCircle2 class="size-4" /> Acknowledge</button>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="tab === 'coaching'">
            <div v-if="!coaching.points.length && !coaching.sessions.length && !coaching.pips.length" class="card"><EmptyState title="No coaching records" /></div>
            <div v-for="p in coaching.pips" :key="`pip-${p.id}`" class="card mb-3 border-l-4 border-rose-400 p-4">
                <p class="font-semibold text-slate-900 dark:text-white">Performance improvement plan <Badge :color="['active', 'extended'].includes(p.status) ? 'amber' : p.status === 'escalated' ? 'red' : 'green'">{{ p.status }}</Badge></p>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ formatDate(p.start_date) }} – {{ formatDate(p.end_date) }}<span v-if="p.next_checkin_date"> · next check-in {{ formatDate(p.next_checkin_date) }}</span></p>
                <p class="mt-2 text-sm text-slate-700 dark:text-slate-200"><strong>Concern:</strong> {{ p.concern }}</p>
                <p class="text-sm text-slate-700 dark:text-slate-200"><strong>Targets:</strong> {{ p.targets }}</p>
                <p class="text-sm text-slate-700 dark:text-slate-200"><strong>Action plan:</strong> {{ p.action_plan }}</p>
                <p v-for="c in p.checkins" :key="c.id" class="mt-1 text-xs text-slate-500">{{ formatDate(c.meeting_date) }} · progress {{ c.progress_rating }}/5 · {{ c.recommendation }}<span v-if="c.comment"> — {{ c.comment }}</span></p>
            </div>
            <div v-for="s in coaching.sessions" :key="`s-${s.id}`" class="card mb-3 p-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
                    <div class="flex-1">
                        <p class="font-semibold text-slate-900 dark:text-white">{{ s.topic }} <span class="text-sm font-normal text-slate-500">· {{ formatDate(s.session_date) }} with {{ s.supervisor_name ?? 'your supervisor' }}</span></p>
                        <p v-for="k in ['goal', 'reality', 'options', 'way_forward']" v-show="s[k]" :key="k" class="text-sm text-slate-700 dark:text-slate-200"><strong>{{ titleCase(k) }}:</strong> {{ s[k] }}</p>
                        <p v-if="s.staff_comment" class="mt-1 text-sm text-slate-600 dark:text-slate-300">My comment: {{ s.staff_comment }}</p>
                    </div>
                    <button v-if="s.can_comment_staff" class="btn-secondary !py-1.5" @click="open('staff_comment', s)">Comment</button>
                </div>
            </div>
            <div v-for="p in coaching.points" :key="`dp-${p.id}`" class="card mb-2 p-3 text-sm">
                <span class="font-medium text-slate-900 dark:text-white">{{ p.activity }}</span> <Badge color="blue">{{ p.status }}</Badge>
                <span v-if="p.kpi_name" class="text-slate-500"> · KPI {{ p.kpi_name }}</span><span v-if="p.deadline" class="text-slate-500"> · by {{ formatDate(p.deadline) }}</span>
                <p v-if="p.comment" class="text-slate-600 dark:text-slate-300">{{ p.comment }}</p>
            </div>
        </template>

        <template v-else-if="tab === 'recognition'">
            <div class="mb-4 flex justify-end"><button class="btn-primary" @click="open('recognise')"><Award class="size-4" /> Recognise a colleague</button></div>
            <div class="grid gap-6 lg:grid-cols-2">
                <div v-for="side in ['received', 'given']" :key="side">
                    <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">{{ side === 'received' ? 'Received' : 'Given' }}</h3>
                    <div v-if="!recognition[side]?.length" class="card p-6 text-center text-sm text-slate-400">Nothing yet.</div>
                    <div v-for="r in recognition[side]" :key="r.id" class="card mb-2 p-4">
                        <div class="flex items-center gap-3">
                            <Avatar :src="r.employee?.photo_url" :name="r.employee?.name" size="sm" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-slate-900 dark:text-white">{{ side === 'received' ? `From ${r.awarded_by}` : `To ${r.employee?.name}` }}</p>
                                <p class="text-xs text-slate-400">{{ titleCase(r.category) }} · {{ formatDate(r.created_at) }}</p>
                            </div>
                        </div>
                        <p v-if="r.title" class="mt-2 font-medium text-slate-800 dark:text-slate-100">{{ r.title }}</p>
                        <p class="text-sm text-slate-600 dark:text-slate-300">{{ r.message }}</p>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="tab === 'learning'">
            <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">My training</h3>
            <div v-if="!training.length" class="card mb-6 p-6 text-center text-sm text-slate-400">You are not enrolled in any course.</div>
            <div v-for="t in training" :key="t.id" class="card mb-2 flex flex-col gap-2 p-4 sm:flex-row sm:items-center">
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-slate-900 dark:text-white">{{ t.course_name }} <Badge :color="t.status === 'completed' ? 'green' : t.status === 'cancelled' ? 'slate' : 'blue'">{{ titleCase(t.status) }}</Badge></p>
                    <div class="mt-2 h-1.5 w-full max-w-xs overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-full rounded-full bg-brand-500" :style="{ width: `${t.progress}%` }" /></div>
                </div>
                <button v-if="!['completed', 'cancelled'].includes(t.status)" class="btn-secondary !py-1.5" @click="open('progress', t)">Update progress</button>
            </div>
            <h3 class="mb-2 mt-6 text-sm font-semibold uppercase tracking-wide text-slate-500">Course catalogue</h3>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="c in catalogue" :key="c.id" class="card flex flex-col p-4">
                    <p class="font-medium text-slate-900 dark:text-white">{{ c.name }}</p>
                    <p class="text-xs text-slate-400">{{ [c.category, c.provider, c.delivery, c.duration_hours ? `${c.duration_hours} h` : null].filter(Boolean).join(' · ') }}</p>
                    <p v-if="c.description" class="mt-1 flex-1 text-sm text-slate-600 dark:text-slate-300">{{ c.description }}</p>
                    <div class="mt-3">
                        <button v-if="c.can_enrol" class="btn-primary !py-1.5" @click="enrol(c)"><GraduationCap class="size-4" /> Enrol</button>
                        <Badge v-else-if="c.enrollment_status" color="green">{{ titleCase(c.enrollment_status) }}</Badge>
                    </div>
                </div>
            </div>
        </template>

        <Modal :open="modal.open" @close="modal.open = false"
            :title="{ acknowledge: 'Acknowledge appraisal', staff_comment: 'My comment on this session', recognise: 'Recognise a colleague', progress: 'Update training progress' }[modal.mode]">
            <div class="space-y-4">
                <div v-if="modal.mode === 'acknowledge'"><label class="label">Comment (optional)</label><textarea v-model="form.comment" rows="3" class="input" /></div>
                <div v-if="modal.mode === 'staff_comment'"><label class="label">Comment</label><textarea v-model="form.staff_comment" rows="4" class="input" /></div>
                <template v-if="modal.mode === 'recognise'">
                    <div><label class="label">Colleague *</label><LookupSelect v-model="form.employee_id" kind="employees" placeholder="Who are you recognising?" /><p v-if="errors.employee_id" class="field-error">{{ errors.employee_id }}</p></div>
                    <div><label class="label">Title</label><input v-model="form.title" class="input" maxlength="150" /></div>
                    <div><label class="label">Message *</label><textarea v-model="form.message" rows="3" class="input" /><p v-if="errors.message" class="field-error">{{ errors.message }}</p></div>
                </template>
                <template v-if="modal.mode === 'progress'">
                    <div><label class="label">Progress: {{ form.progress }}%</label><input v-model="form.progress" type="range" min="0" max="100" step="5" class="w-full" /></div>
                    <div><label class="label">Status</label>
                        <select v-model="form.status" class="input"><option value="enrolled">Enrolled</option><option value="in_progress">In progress</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select>
                    </div>
                </template>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>
    </div>
</template>
