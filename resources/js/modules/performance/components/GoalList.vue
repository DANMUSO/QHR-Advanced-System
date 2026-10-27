<script setup>
import { useFormErrors } from '@/lib/formErrors';
import { ref, computed } from 'vue';
import { Check, X, Send, MessageSquareWarning, GitBranch, Repeat, CalendarClock, PencilLine, Trash2, Paperclip } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { formatDate, titleCase } from '@/lib/format';
import { openServerFile } from '@/lib/export';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

/**
 * KPI goal cards with every lifecycle action the API allows on each goal (goal.can):
 * employee — accept, cascade further, convert to self-assessment, request a review, submit a result;
 * approver — approve, reject, extend, amend / decline a review request, withdraw.
 */
const props = defineProps({
    goals: { type: Array, default: () => [] },
    showEmployee: { type: Boolean, default: false },
    emptyTitle: { type: String, default: 'No KPIs here' },
    emptyText: { type: String, default: '' },
});
const emit = defineEmits(['changed']);
const ui = useUiStore();

const statusColors = { cascaded: 'amber', in_progress: 'blue', under_review: 'orange', submitted: 'purple', approved: 'green' };
const statusLabels = { cascaded: 'Awaiting acceptance', in_progress: 'In progress', under_review: 'Under review', submitted: 'Submitted', approved: 'Approved' };

const modal = ref({ open: false, mode: null, goal: null });
const form = ref({});
const errors = useFormErrors(() => form.value);
const saving = ref(false);
const team = ref([]);

const titles = {
    review: 'Request a review', cascade: 'Cascade to my team', submit: 'Submit result', approve: 'Approve result', reject: 'Reject result',
    extend: 'Extend deadline', amend: 'Amend KPI', reject_review: 'Decline review request', withdraw: 'Withdraw KPI',
};

async function quick(goal, action, message) {
    try {
        await api.post(`/performance/goals/${goal.id}/${action}`);
        ui.toast(message, 'success');
        emit('changed');
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
}

async function open(mode, goal) {
    errors.value = {};
    form.value = {
        comment: '', reason: '', achieved: '', evidence: null, ratings: {}, due_date: goal.due_date, target: goal.target,
        split: 'equal', assignees: [],
    };
    modal.value = { open: true, mode, goal };
    if (mode === 'cascade' && !team.value.length) {
        try { const { data } = await api.get('/performance/team-overview'); team.value = data.members ?? []; } catch { team.value = []; }
    }
}

const selected = computed(() => form.value.assignees ?? []);
function toggleAssignee(m) {
    const list = form.value.assignees;
    const i = list.findIndex((a) => a.employee_id === m.id);
    i >= 0 ? list.splice(i, 1) : list.push({ employee_id: m.id, name: m.name, target: '' });
}

async function save() {
    const { mode, goal } = modal.value;
    saving.value = true; errors.value = {};
    try {
        let res;
        if (mode === 'review') res = await api.post(`/performance/goals/${goal.id}/request-review`, { reason: form.value.reason });
        else if (mode === 'cascade') {
            res = await api.post(`/performance/goals/${goal.id}/cascade`, {
                split: form.value.split,
                assignees: form.value.assignees.map((a) => ({ employee_id: a.employee_id, target: form.value.split === 'distributed' ? a.target : undefined })),
            });
        } else if (mode === 'submit') {
            const fd = new FormData();
            if (goal.kpi?.type === 'qualitative') Object.entries(form.value.ratings).forEach(([q, r]) => fd.append(`ratings[${q}]`, r));
            else fd.append('achieved', form.value.achieved);
            if (form.value.comment) fd.append('comment', form.value.comment);
            if (form.value.evidence) fd.append('evidence', form.value.evidence);
            res = await api.post(`/performance/goals/${goal.id}/submit`, fd);
        } else if (mode === 'approve') res = await api.post(`/performance/goals/${goal.id}/approve`, { comment: form.value.comment || null });
        else if (mode === 'reject') res = await api.post(`/performance/goals/${goal.id}/reject`, { reason: form.value.reason });
        else if (mode === 'extend') res = await api.post(`/performance/goals/${goal.id}/extend`, { due_date: form.value.due_date, comment: form.value.comment || null });
        else if (mode === 'amend') res = await api.post(`/performance/goals/${goal.id}/amend`, { target: form.value.target || null, due_date: form.value.due_date || null, comment: form.value.comment || null });
        else if (mode === 'reject_review') res = await api.post(`/performance/goals/${goal.id}/reject-review`, { comment: form.value.comment });
        else if (mode === 'withdraw') res = await api.delete(`/performance/goals/${goal.id}`);
        ui.toast(res?.data?.message ?? 'Saved.', 'success');
        modal.value.open = false;
        emit('changed');
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally { saving.value = false; }
}

function fmt(n) { return n === null || n === undefined || n === '' ? '—' : Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 }); }
</script>

<template>
    <div>
        <div v-if="!goals.length" class="card"><EmptyState :title="emptyTitle" :description="emptyText" /></div>
        <div v-else class="space-y-3">
            <div v-for="g in goals" :key="g.id" class="card p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <Avatar v-if="showEmployee" :src="g.employee?.photo_url" :name="g.employee?.name" size="md" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-semibold text-slate-900 dark:text-white">{{ g.kpi?.name ?? 'KPI' }}</p>
                            <Badge :color="statusColors[g.status] ?? 'slate'">{{ statusLabels[g.status] ?? g.status }}</Badge>
                            <Badge v-if="g.kpi?.type === 'qualitative'" color="sky">Qualitative</Badge>
                            <Badge v-if="g.mode === 'self'" color="slate">Self</Badge>
                            <Badge v-else-if="g.split" color="slate">{{ titleCase(g.split) }}</Badge>
                        </div>
                        <p v-if="showEmployee" class="text-sm text-slate-600 dark:text-slate-300">{{ g.employee?.name }}<span v-if="g.employee?.position" class="text-slate-400"> · {{ g.employee.position }}</span></p>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            <span v-if="g.plan">{{ g.plan }}</span><span v-if="g.focus_area"> · {{ g.focus_area }}</span><span v-if="g.objective"> · {{ g.objective.name }} ({{ g.objective.weight }}%)</span>
                        </p>
                        <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-600 dark:text-slate-300">
                            <span>Target <strong>{{ fmt(g.target) }}</strong> {{ g.kpi?.unit ?? '' }}</span>
                            <span v-if="g.achieved !== null">Achieved <strong>{{ fmt(g.achieved) }}</strong></span>
                            <span v-if="g.status === 'approved'">Score <strong>{{ fmt(g.score) }}</strong> ({{ fmt(g.achievement_pct) }}%)</span>
                            <span :class="g.days_left < 0 && g.status !== 'approved' ? 'text-rose-600 dark:text-rose-400' : ''">Due {{ formatDate(g.due_date) }}</span>
                            <span v-if="g.children_count">Cascaded to {{ g.children_count }}</span>
                        </div>
                        <div v-if="g.target > 0" class="mt-2 h-1.5 w-full max-w-sm overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                            <div class="h-full rounded-full bg-brand-500" :style="{ width: `${g.progress_pct ?? 0}%` }" />
                        </div>
                        <p v-if="g.review_reason" class="mt-2 text-xs text-orange-600 dark:text-orange-400">Review requested: {{ g.review_reason }}</p>
                        <p v-if="g.rejection_reason && g.status === 'in_progress'" class="mt-1 text-xs text-rose-600 dark:text-rose-400">Rejected: {{ g.rejection_reason }}</p>
                        <p v-if="g.employee_comment" class="mt-1 text-xs text-slate-500 dark:text-slate-400">Employee: {{ g.employee_comment }}</p>
                        <p v-if="g.supervisor_comment" class="mt-1 text-xs text-slate-500 dark:text-slate-400">Supervisor: {{ g.supervisor_comment }}</p>
                        <button v-if="g.has_evidence" class="mt-1 inline-flex items-center gap-1 text-xs text-brand-600 hover:underline dark:text-brand-300" @click="openServerFile(`/api/performance/goals/${g.id}/evidence`)">
                            <Paperclip class="size-3" /> {{ g.evidence_name ?? 'Evidence' }}
                        </button>
                    </div>
                    <div class="flex flex-wrap gap-2 sm:max-w-xs sm:justify-end">
                        <button v-if="g.can?.accept" class="btn-primary !py-1.5" @click="quick(g, 'accept', 'KPI accepted.')"><Check class="size-4" /> Accept</button>
                        <button v-if="g.can?.cascade" class="btn-secondary !py-1.5" @click="open('cascade', g)"><GitBranch class="size-4" /> Cascade</button>
                        <button v-if="g.can?.convert" class="btn-secondary !py-1.5" @click="quick(g, 'convert', 'Converted to a self-assessment.')"><Repeat class="size-4" /> Make self</button>
                        <button v-if="g.can?.review" class="btn-ghost !py-1.5 text-orange-600 dark:text-orange-400" @click="open('review', g)"><MessageSquareWarning class="size-4" /> Request review</button>
                        <button v-if="g.can?.submit && !g.is_rollup" class="btn-primary !py-1.5" @click="open('submit', g)"><Send class="size-4" /> Submit</button>
                        <button v-if="g.can?.approve" class="btn-primary !py-1.5" @click="open('approve', g)"><Check class="size-4" /> Approve</button>
                        <button v-if="g.can?.reject" class="btn-danger !py-1.5" @click="open('reject', g)"><X class="size-4" /> Reject</button>
                        <button v-if="g.can?.amend" class="btn-primary !py-1.5" @click="open('amend', g)"><PencilLine class="size-4" /> Amend</button>
                        <button v-if="g.can?.reject_review" class="btn-secondary !py-1.5" @click="open('reject_review', g)"><X class="size-4" /> Decline review</button>
                        <button v-if="g.can?.extend" class="btn-ghost !py-1.5" @click="open('extend', g)"><CalendarClock class="size-4" /> Extend</button>
                        <button v-if="g.can?.withdraw" class="btn-ghost !py-1.5 text-rose-600 dark:text-rose-400" @click="open('withdraw', g)"><Trash2 class="size-4" /></button>
                    </div>
                </div>
            </div>
        </div>

        <Modal :open="modal.open" :title="titles[modal.mode]" :size="modal.mode === 'cascade' ? 'lg' : 'md'" @close="modal.open = false">
            <div class="space-y-4">
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ modal.goal?.kpi?.name }}<span v-if="showEmployee && modal.goal?.employee"> · {{ modal.goal.employee.name }}</span> · target {{ fmt(modal.goal?.target) }}
                </p>

                <div v-if="modal.mode === 'review' || modal.mode === 'reject'">
                    <label class="label">Reason <span class="text-rose-500">*</span></label>
                    <textarea v-model="form.reason" rows="3" class="input" />
                    <p v-if="errors.reason" class="field-error">{{ errors.reason }}</p>
                </div>

                <template v-if="modal.mode === 'cascade'">
                    <div>
                        <label class="label">How should the target be split?</label>
                        <select v-model="form.split" class="input" :disabled="modal.goal?.kpi?.type === 'qualitative'">
                            <option value="equal">Equal — everyone gets the full target</option>
                            <option value="distributed">Distributed — split the target between people</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Direct reports</label>
                        <p v-if="!team.length" class="text-sm text-slate-500">You have no direct reports to cascade to.</p>
                        <div class="max-h-64 space-y-1 overflow-y-auto">
                            <label v-for="m in team" :key="m.id" class="flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-slate-50 dark:hover:bg-slate-800">
                                <input type="checkbox" class="rounded border-slate-300 dark:border-slate-600" :checked="selected.some((a) => a.employee_id === m.id)" @change="toggleAssignee(m)" />
                                <span class="flex-1 text-sm">{{ m.name }}</span>
                                <input v-if="form.split === 'distributed' && selected.some((a) => a.employee_id === m.id)" v-model="selected.find((a) => a.employee_id === m.id).target"
                                    type="number" min="0" step="any" class="input !w-28 !py-1" placeholder="Target" />
                            </label>
                        </div>
                        <p v-if="errors.assignees" class="field-error">{{ errors.assignees }}</p>
                    </div>
                </template>

                <template v-if="modal.mode === 'submit'">
                    <div v-if="modal.goal?.kpi?.type === 'qualitative'" class="space-y-3">
                        <div v-for="q in modal.goal.kpi.questions" :key="q.id">
                            <label class="label">{{ q.question }}</label>
                            <div class="flex flex-wrap gap-1">
                                <button v-for="n in 5" :key="n" type="button" class="h-9 w-9 rounded-lg border text-sm font-medium"
                                    :class="form.ratings[q.id] === n ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-200 dark:border-slate-700'"
                                    @click="form.ratings[q.id] = n">{{ n }}</button>
                            </div>
                        </div>
                        <p v-if="errors.ratings" class="field-error">{{ errors.ratings }}</p>
                    </div>
                    <div v-else>
                        <label class="label">Achieved value <span class="text-rose-500">*</span></label>
                        <input v-model="form.achieved" type="number" step="any" min="0" class="input" />
                        <p v-if="errors.achieved" class="field-error">{{ errors.achieved }}</p>
                    </div>
                    <div>
                        <label class="label">Evidence (optional)</label>
                        <input type="file" class="input" @change="form.evidence = $event.target.files[0] ?? null" />
                        <p v-if="errors.evidence" class="field-error">{{ errors.evidence }}</p>
                    </div>
                </template>

                <template v-if="modal.mode === 'extend' || modal.mode === 'amend'">
                    <div v-if="modal.mode === 'amend'">
                        <label class="label">Target</label>
                        <input v-model="form.target" type="number" step="any" min="0" class="input" />
                        <p v-if="errors.target" class="field-error">{{ errors.target }}</p>
                    </div>
                    <div>
                        <label class="label">Due date</label>
                        <input v-model="form.due_date" type="date" class="input" />
                        <p v-if="errors.due_date" class="field-error">{{ errors.due_date }}</p>
                    </div>
                </template>

                <p v-if="modal.mode === 'withdraw'" class="text-sm text-slate-600 dark:text-slate-300">The KPI is removed from {{ modal.goal?.employee?.name ?? 'the employee' }}'s list. This is only possible before it is accepted.</p>

                <div v-if="['submit', 'approve', 'extend', 'amend', 'reject_review'].includes(modal.mode)">
                    <label class="label">Comment<span v-if="modal.mode === 'reject_review'" class="text-rose-500"> *</span></label>
                    <textarea v-model="form.comment" rows="3" class="input" />
                    <p v-if="errors.comment" class="field-error">{{ errors.comment }}</p>
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                <button :class="['reject', 'withdraw'].includes(modal.mode) ? 'btn-danger' : 'btn-primary'" :disabled="saving" @click="save">{{ saving ? 'Saving…' : titles[modal.mode] }}</button>
            </template>
        </Modal>
    </div>
</template>
