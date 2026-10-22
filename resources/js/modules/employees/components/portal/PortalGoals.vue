<script setup>
import { ref, onMounted } from 'vue';
import { Check, MessageSquare, Send, RotateCcw, Paperclip, Download, ChevronDown, ChevronUp } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { openServerFile } from '@/lib/export';
import { timeAgo, titleCase } from '@/lib/format';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import StatCard from '@/components/ui/StatCard.vue';

/** My KPI goals: accept, convert, request a review, submit the result (with evidence) and discuss with the supervisor. */
const goals = ref([]);
const summary = ref(null);
const loading = ref(false);
const statusColors = { cascaded: 'yellow', in_progress: 'blue', under_review: 'orange', submitted: 'purple', approved: 'green' };

const expanded = ref(null);
const detail = ref(null);
const detailLoading = ref(false);
const comment = ref('');

const modal = ref({ open: false, mode: 'submit', goal: null });
const form = ref({});
const errors = ref({});
const busy = ref(false);

async function load() {
    loading.value = true;
    try { const { data } = await api.get('/performance/my-goals'); goals.value = data.data ?? []; summary.value = data.summary ?? null; }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}

async function toggle(goal) {
    if (expanded.value === goal.id) { expanded.value = null; return; }
    expanded.value = goal.id; detail.value = null; comment.value = '';
    detailLoading.value = true;
    try { const { data } = await api.get(`/performance/goals/${goal.id}`); detail.value = data.data; }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { detailLoading.value = false; }
}

async function act(goal, action, label) {
    if (!await swal.confirm({ title: label, confirmLabel: 'Yes', danger: false })) return;
    try { const { data } = await api.post(`/performance/goals/${goal.id}/${action}`); swal.toast(data.message ?? 'Done.'); await load(); }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
}

function open(mode, goal) {
    modal.value = { open: true, mode, goal };
    form.value = mode === 'submit' ? { achieved: goal.achieved ?? '', comment: '', evidence: null, ratings: {} } : { reason: '' };
    errors.value = {};
}

async function save() {
    const { mode, goal } = modal.value;
    busy.value = true; errors.value = {};
    try {
        let res;
        if (mode === 'review') {
            res = await api.post(`/performance/goals/${goal.id}/request-review`, { reason: form.value.reason });
        } else {
            const fd = new FormData();
            if (form.value.achieved !== '' && form.value.achieved != null) fd.append('achieved', form.value.achieved);
            if (form.value.comment) fd.append('comment', form.value.comment);
            if (form.value.evidence) fd.append('evidence', form.value.evidence);
            Object.entries(form.value.ratings).forEach(([q, r]) => { if (r) fd.append(`ratings[${q}]`, r); });
            res = await api.post(`/performance/goals/${goal.id}/submit`, fd);
        }
        swal.toast(res.data.message ?? 'Saved.');
        modal.value.open = false;
        await load();
    } catch (e) { errors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); }
    finally { busy.value = false; }
}

async function addComment(goal) {
    if (!comment.value.trim()) return;
    try {
        await api.post(`/performance/goals/${goal.id}/comment`, { comment: comment.value });
        comment.value = '';
        const { data } = await api.get(`/performance/goals/${goal.id}`); detail.value = data.data;
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
}

onMounted(load);
</script>

<template>
    <div>
        <div v-if="summary" class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard label="Goals" :value="summary.total" />
            <StatCard label="Completed" :value="`${summary.completion_pct}%`" />
            <StatCard label="Due this week" :value="summary.due_soon" />
            <StatCard label="Overdue" :value="summary.overdue" />
        </div>

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <div v-else-if="!goals.length" class="card p-10 text-center text-slate-400">No goals assigned yet.</div>
        <div v-else class="space-y-3">
            <div v-for="g in goals" :key="g.id" class="card p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-semibold text-slate-900 dark:text-white">{{ g.kpi?.name ?? 'Goal' }}</p>
                            <Badge :color="statusColors[g.status] ?? 'slate'">{{ titleCase(g.status) }}</Badge>
                        </div>
                        <p v-if="g.objective" class="text-xs text-slate-400">{{ g.objective.name }}<span v-if="g.cycle"> · {{ g.cycle.name }}</span></p>
                        <p class="mt-1 text-sm text-slate-500">
                            Target: {{ g.target ?? '—' }}{{ g.kpi?.unit ? ` ${g.kpi.unit}` : '' }} · Due {{ g.due_date ?? '—' }}
                            <span v-if="g.days_left != null && g.status !== 'approved'" :class="g.days_left < 0 ? 'text-red-500' : ''">({{ g.days_left < 0 ? `${-g.days_left}d overdue` : `${g.days_left}d left` }})</span>
                        </p>
                        <div v-if="g.target" class="mt-2 max-w-sm">
                            <div class="h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-full rounded-full bg-brand-500" :style="{ width: `${g.progress_pct ?? 0}%` }" /></div>
                            <p class="mt-1 text-xs text-slate-400">Achieved {{ g.achieved ?? 0 }} ({{ g.progress_pct ?? 0 }}%)<span v-if="g.score != null"> · score {{ g.score }}</span></p>
                        </div>
                        <p v-if="g.rejection_reason && g.status === 'in_progress'" class="mt-2 text-xs text-red-600 dark:text-red-400">Returned: {{ g.rejection_reason }}</p>
                        <p v-if="g.review_reason && g.status === 'under_review'" class="mt-2 text-xs text-orange-600 dark:text-orange-400">Review requested: {{ g.review_reason }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button v-if="g.can?.accept" class="btn-primary !py-1.5" @click="act(g, 'accept', 'Accept this target?')"><Check class="size-4" /> Accept</button>
                        <button v-if="g.can?.convert" class="btn-secondary !py-1.5" @click="act(g, 'convert', 'Convert to a self-set goal?')">Self-assess</button>
                        <button v-if="g.can?.review" class="btn-secondary !py-1.5" @click="open('review', g)"><RotateCcw class="size-4" /> Request review</button>
                        <button v-if="g.can?.submit" class="btn-primary !py-1.5" @click="open('submit', g)"><Send class="size-4" /> Submit result</button>
                        <button class="btn-ghost !py-1.5" @click="toggle(g)"><MessageSquare class="size-4" /><component :is="expanded === g.id ? ChevronUp : ChevronDown" class="size-4" /></button>
                    </div>
                </div>

                <div v-if="expanded === g.id" class="mt-4 border-t border-slate-100 pt-4 dark:border-slate-800">
                    <div v-if="detailLoading" class="flex justify-center py-6"><Spinner /></div>
                    <template v-else-if="detail">
                        <p v-if="detail.kpi?.description" class="mb-3 text-sm text-slate-600 dark:text-slate-300">{{ detail.kpi.description }}</p>
                        <button v-if="detail.has_evidence" class="btn-ghost mb-3 !py-1 text-xs" @click="openServerFile(`/api/performance/goals/${g.id}/evidence`)"><Download class="size-4" /> {{ detail.evidence_name ?? 'Evidence' }}</button>
                        <ul class="space-y-2">
                            <li v-for="ev in detail.events" :key="ev.id" class="rounded-lg bg-slate-50 p-2.5 text-sm dark:bg-slate-800/50">
                                <p class="text-xs text-slate-500"><span class="font-medium text-slate-700 dark:text-slate-200">{{ ev.by }}</span> · {{ titleCase(ev.action) }} · {{ timeAgo(ev.created_at) }}</p>
                                <p v-if="ev.comment" class="mt-0.5 whitespace-pre-line">{{ ev.comment }}</p>
                            </li>
                            <li v-if="!detail.events?.length" class="text-sm text-slate-400">No activity yet.</li>
                        </ul>
                        <form class="mt-3 flex gap-2" @submit.prevent="addComment(g)">
                            <input v-model="comment" class="input" placeholder="Add a comment for your supervisor…" />
                            <button class="btn-secondary" :disabled="!comment.trim()">Send</button>
                        </form>
                    </template>
                </div>
            </div>
        </div>

        <Modal :open="modal.open" :title="modal.mode === 'review' ? 'Request a review' : 'Submit result'" @close="modal.open = false">
            <div v-if="modal.mode === 'review'">
                <p class="mb-3 text-sm text-slate-500">Explain why the target for <strong>{{ modal.goal?.kpi?.name }}</strong> should be reviewed. Your supervisor can amend or keep it.</p>
                <label class="label">Reason</label><textarea v-model="form.reason" rows="4" class="input" />
                <p v-if="errors.reason" class="field-error">{{ errors.reason }}</p>
            </div>
            <div v-else class="space-y-4">
                <p class="text-sm text-slate-500">{{ modal.goal?.kpi?.name }} · Target {{ modal.goal?.target ?? '—' }}{{ modal.goal?.kpi?.unit ? ` ${modal.goal.kpi.unit}` : '' }}</p>
                <div v-if="modal.goal?.kpi?.questions?.length" class="space-y-3">
                    <div v-for="q in modal.goal.kpi.questions" :key="q.id">
                        <label class="label">{{ q.question }}</label>
                        <select v-model="form.ratings[q.id]" class="input"><option value="">— Rate —</option><option v-for="n in 10" :key="n" :value="n">{{ n }}</option></select>
                    </div>
                </div>
                <div v-else>
                    <label class="label">Achieved value</label><input v-model="form.achieved" type="number" step="0.01" min="0" class="input" />
                    <p v-if="errors.achieved" class="field-error">{{ errors.achieved }}</p>
                </div>
                <div><label class="label">Comment</label><textarea v-model="form.comment" rows="3" class="input" /></div>
                <div>
                    <label class="label flex items-center gap-1"><Paperclip class="size-3.5" /> Evidence (optional)</label>
                    <input type="file" class="input" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv,.txt" @change="form.evidence = $event.target.files[0] ?? null" />
                    <p v-if="errors.evidence" class="field-error">{{ errors.evidence }}</p>
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="busy || (modal.mode === 'review' && !form.reason?.trim())" @click="save">{{ busy ? 'Sending…' : modal.mode === 'review' ? 'Request review' : 'Submit' }}</button>
            </template>
        </Modal>
    </div>
</template>
