<script setup>
import { ref, computed, watch } from 'vue';
import { CheckCircle2, Circle, CircleDot, SkipForward, Gavel, Send, FileText } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { formatDate } from '@/lib/format';
import { openServerFile } from '@/lib/export';
import Badge from '@/components/ui/Badge.vue';
import Spinner from '@/components/ui/Spinner.vue';

// One probation review: stage timeline, the current reviewer's comment form and (HR) the final decision.
const props = defineProps({ review: { type: Object, required: true } });
// The card shows the server's reply straight away (no stale "Awaiting HR decision" while the parent reloads).
const latest = ref(null);
watch(() => props.review, () => { latest.value = null; });
const review = computed(() => latest.value ?? props.review);
const emit = defineEmits(['updated']);
const ui = useUiStore();

const STATUS = {
    pending: ['Awaiting first review', 'yellow'], in_review: ['In review', 'blue'], hr_review: ['Awaiting HR decision', 'purple'],
    confirmed: ['Confirmed', 'green'], extended: ['Extended', 'orange'], terminated: ['Terminated', 'red'],
};
const status = computed(() => STATUS[review.value.status] ?? [review.value.status, 'slate']);

const comment = ref('');
const decision = ref({ decision: 'confirm', comment: '', extension_end_date: '' });
const errors = ref({});
const saving = ref(false);

async function submitStage() {
    saving.value = true; errors.value = {};
    try {
        const { data } = await api.post(`/probation/reviews/${props.review.id}/review`, { comment: comment.value });
        ui.toast(data.message); comment.value = '';
        if (data.data?.id) latest.value = data.data;
        emit('updated', data.data);
    } catch (e) { errors.value = fieldErrors(e); ui.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}

async function submitDecision() {
    saving.value = true; errors.value = {};
    try {
        const { data } = await api.post(`/probation/reviews/${props.review.id}/hr-decision`, decision.value);
        ui.toast(data.message);
        if (data.data?.id) latest.value = data.data;
        if (data.data?.letter_url) openServerFile(data.data.letter_url);
        emit('updated', data.data);
    } catch (e) { errors.value = fieldErrors(e); ui.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <p class="font-semibold text-slate-900 dark:text-white">Probation ends {{ formatDate(review.probation_end_date) }}</p>
                <p class="text-xs text-slate-500">Review cycle {{ review.cycle ?? 1 }}<span v-if="review.current_stage"> · now at: {{ review.current_stage }}</span></p>
            </div>
            <Badge :color="status[1]">{{ status[0] }}</Badge>
        </div>

        <ol class="space-y-3">
            <li v-for="(s, i) in review.stages ?? []" :key="i" class="flex gap-3">
                <CheckCircle2 v-if="s.state === 'done'" class="mt-0.5 size-5 shrink-0 text-green-500" />
                <SkipForward v-else-if="s.state === 'skipped'" class="mt-0.5 size-5 shrink-0 text-slate-400" />
                <CircleDot v-else-if="s.state === 'current'" class="mt-0.5 size-5 shrink-0 text-brand-500" />
                <Circle v-else class="mt-0.5 size-5 shrink-0 text-slate-300 dark:text-slate-600" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ s.label }}</p>
                    <p v-if="s.entry?.skipped" class="text-xs text-slate-400">{{ s.entry.note }}</p>
                    <template v-else-if="s.entry?.comment">
                        <p class="mt-1 whitespace-pre-line rounded-lg bg-slate-50 p-3 text-sm text-slate-700 dark:bg-slate-800/50 dark:text-slate-300">{{ s.entry.comment }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ s.entry.by_name }} · {{ formatDate(s.entry.at) }}</p>
                    </template>
                </div>
            </li>
            <li class="flex gap-3">
                <Gavel class="mt-0.5 size-5 shrink-0" :class="review.decision ? 'text-green-500' : review.status === 'hr_review' ? 'text-brand-500' : 'text-slate-300 dark:text-slate-600'" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-slate-800 dark:text-slate-200">HR decision</p>
                    <template v-if="review.decision">
                        <p class="mt-1 text-sm font-semibold capitalize">{{ review.decision }}<span v-if="review.extension_end_date"> to {{ formatDate(review.extension_end_date) }}</span></p>
                        <p class="whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ review.hr_comment }}</p>
                        <p class="text-xs text-slate-400">{{ review.hr_reviewer }} · {{ formatDate(review.hr_reviewed_at) }}</p>
                        <button v-if="review.letter_url" class="btn-ghost mt-1 !py-1 text-xs" @click="openServerFile(review.letter_url)"><FileText class="size-3.5" /> Confirmation letter</button>
                    </template>
                </div>
            </li>
        </ol>

        <!-- Current stage reviewer -->
        <div v-if="review.can_review" class="rounded-xl border border-brand-200 p-4 dark:border-brand-500/30">
            <label class="label">Your comment ({{ review.current_stage }})</label>
            <textarea v-model="comment" rows="3" class="input" placeholder="Performance, conduct, recommendation…" />
            <p v-if="errors.comment" class="field-error">{{ errors.comment }}</p>
            <div class="mt-3 flex justify-end">
                <button class="btn-primary" :disabled="saving || !comment.trim()" @click="submitStage"><Spinner v-if="saving" /><Send v-else class="size-4" /> Submit review</button>
            </div>
        </div>

        <!-- HR final decision -->
        <div v-if="review.can_decide" class="rounded-xl border border-purple-200 p-4 dark:border-purple-500/30">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="label">Decision</label>
                    <select v-model="decision.decision" class="input">
                        <option value="confirm">Confirm (generates the confirmation letter)</option>
                        <option value="extend">Extend probation</option>
                        <option value="terminate">Terminate (exits the employee)</option>
                    </select>
                </div>
                <div v-if="decision.decision === 'extend'">
                    <label class="label">New probation end date</label>
                    <input v-model="decision.extension_end_date" type="date" class="input" />
                    <p v-if="errors.extension_end_date" class="field-error">{{ errors.extension_end_date }}</p>
                </div>
            </div>
            <label class="label mt-3">HR comment</label>
            <textarea v-model="decision.comment" rows="3" class="input" />
            <p v-if="errors.comment" class="field-error">{{ errors.comment }}</p>
            <div class="mt-3 flex justify-end">
                <button :class="decision.decision === 'terminate' ? 'btn-danger' : 'btn-primary'" :disabled="saving || !decision.comment.trim()" @click="submitDecision">
                    <Spinner v-if="saving" /> Record decision
                </button>
            </div>
        </div>
    </div>
</template>
