<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { ClipboardCheck, CheckCircle2 } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { formatDate } from '@/lib/format';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';

/** My appraisals: self-assessment (competency questionnaire), the final result and its acknowledgement. */
const route = useRoute();
const appraisals = ref([]);
const loading = ref(false);
const statusColors = { pending: 'yellow', self_review: 'blue', manager_review: 'purple', completed: 'green' };

// Self-assessment questionnaire
const review = ref(null);
const reviewOpen = ref(false);
const ratings = ref({});
const comments = ref({});
const reviewErrors = ref({});
const saving = ref(false);
const unanswered = computed(() => (review.value?.categories ?? []).flatMap((c) => c.questions).filter((q) => !ratings.value[q.id]).length);

// Acknowledgement
const ack = ref({ open: false, appraisal: null, comment: '' });

async function load() {
    loading.value = true;
    try { const { data } = await api.get('/performance/my-appraisals'); appraisals.value = data.data ?? []; }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}

async function openSelfReview(a) {
    try {
        let id = a.questionnaire_id;
        if (!id || ['pending', 'self_review'].includes(a.status)) {
            const { data } = await api.post(`/performance/appraisals/${a.id}/self-review`);
            id = data.questionnaire_id;
        }
        await openReview(id);
    } catch (e) {
        swal.toast(e?.response?.status === 403 ? 'The self-assessment form is not open to you yet. Please contact HR.' : errorMessage(e), 'error');
        await load();
    }
}

/** Opens a questionnaire by id (also used for the ?review= deep link from My Performance). */
async function openReview(id) {
    try {
        const { data } = await api.get(`/performance/reviews/${id}`);
        review.value = data.data;
        ratings.value = Object.fromEntries(review.value.categories.flatMap((c) => c.questions).map((q) => [q.id, q.self ?? '']));
        comments.value = Object.fromEntries(review.value.categories.map((c) => [c.id, c.comments?.self ?? '']));
        reviewErrors.value = {};
        reviewOpen.value = true;
    } catch (e) {
        swal.toast(e?.response?.status === 403 ? 'The self-assessment form is not open to you yet. Please contact HR.' : errorMessage(e), 'error');
        await load();
    }
}

async function saveReview(submit) {
    saving.value = true; reviewErrors.value = {};
    try {
        const { data } = await api.post(`/performance/reviews/${review.value.id}/respond`, { ratings: ratings.value, comments: comments.value, submit });
        swal.toast(data.message);
        if (submit) { reviewOpen.value = false; await load(); }
    } catch (e) { reviewErrors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}

async function acknowledge() {
    try {
        const { data } = await api.post(`/performance/appraisals/${ack.value.appraisal.id}/acknowledge`, { comment: ack.value.comment || null });
        swal.toast(data.message); ack.value.open = false; await load();
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
}

const canSelfReview = (a) => a.status !== 'completed' && ['pending', 'self_review'].includes(a.status) && a.cycle_status === 'active';

onMounted(async () => {
    await load();
    if (route.query.review) await openReview(route.query.review);
});
</script>

<template>
    <div>
        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <div v-else-if="!appraisals.length" class="card p-10 text-center text-slate-400">No appraisals found.</div>
        <div v-else class="space-y-4">
            <div v-for="a in appraisals" :key="a.id" class="card p-4 sm:p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold text-slate-900 dark:text-white">{{ a.cycle_name }}</h3>
                            <Badge :color="statusColors[a.status] ?? 'slate'">{{ a.status_label ?? a.status }}</Badge>
                            <Badge v-if="a.acknowledged_at" color="green"><CheckCircle2 class="size-3" /> Acknowledged</Badge>
                        </div>
                        <p class="mt-1 text-sm text-slate-500">Period: {{ a.period_start }} – {{ a.period_end }}<span v-if="a.supervisor"> · Supervisor: {{ a.supervisor }}</span></p>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                            <span v-if="a.kpi_score != null">KPIs: <strong>{{ a.kpi_score }}%</strong> <span class="text-xs text-slate-400">({{ a.kpi_weight }}%)</span></span>
                            <span v-if="a.competency_score != null">Competencies: <strong>{{ a.competency_score }}%</strong> <span class="text-xs text-slate-400">({{ a.competency_weight }}%)</span></span>
                            <span v-if="a.final_score != null">Final: <strong>{{ a.final_score }}%</strong><span v-if="a.rating_band"> · {{ a.rating_band?.label ?? a.rating_band }}</span></span>
                            <span v-if="a.is_preview && a.final_score != null" class="text-xs text-slate-400">(provisional)</span>
                        </div>
                        <p v-if="a.manager_comment" class="mt-2 text-sm italic text-slate-500">"{{ a.manager_comment }}"</p>
                        <p v-if="a.employee_comment" class="mt-1 text-xs text-slate-400">Your comment: {{ a.employee_comment }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button v-if="canSelfReview(a)" class="btn-primary !py-1.5" @click="openSelfReview(a)"><ClipboardCheck class="size-4" /> {{ a.status === 'pending' ? 'Start self-assessment' : 'Continue self-assessment' }}</button>
                        <button v-else-if="a.questionnaire_id && a.status !== 'pending'" class="btn-secondary !py-1.5" @click="openSelfReview(a)">View assessment</button>
                        <button v-if="a.status === 'completed' && !a.acknowledged_at" class="btn-primary !py-1.5" @click="ack = { open: true, appraisal: a, comment: '' }"><CheckCircle2 class="size-4" /> Acknowledge</button>
                    </div>
                </div>
                <div v-if="a.goals?.length" class="mt-4 space-y-1.5">
                    <div v-for="g in a.goals" :key="g.id" class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/50">
                        <span class="min-w-0 truncate">{{ g.kpi_name }}</span>
                        <span class="shrink-0 text-xs text-slate-500">{{ g.achievement_pct != null ? `${g.achievement_pct}%` : '—' }}<span v-if="g.weight"> · weight {{ g.weight }}%</span></span>
                    </div>
                </div>
            </div>
        </div>

        <Modal :open="reviewOpen" :title="review?.title ?? 'Self-assessment'" size="lg" @close="reviewOpen = false">
            <template v-if="review">
                <p class="mb-4 text-sm text-slate-500">
                    Rate yourself on each competency<span v-if="review.scale?.length"> ({{ review.scale.map((s) => `${s.value} = ${s.label}`).join(', ') }})</span>.
                    <span v-if="review.due_date">Due {{ formatDate(review.due_date) }}.</span>
                </p>
                <div class="space-y-5">
                    <div v-for="c in review.categories" :key="c.id">
                        <h4 class="mb-2 font-semibold text-slate-800 dark:text-slate-100">{{ c.name }}</h4>
                        <div class="space-y-2">
                            <div v-for="q in c.questions" :key="q.id" class="flex flex-col gap-2 rounded-lg border border-slate-100 p-3 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
                                <p class="text-sm">{{ q.question }}<span v-if="q.supervisor" class="ml-1 text-xs text-slate-400">(supervisor: {{ q.supervisor }})</span></p>
                                <select v-model="ratings[q.id]" class="input sm:w-40" :disabled="!review.can_rate">
                                    <option value="">— Rate —</option>
                                    <option v-for="n in (review.scale?.length ? review.scale.map((s) => s.value) : review.max_scale)" :key="n" :value="n">{{ n }}<template v-if="review.scale?.length"> – {{ review.scale.find((s) => s.value === n)?.label }}</template></option>
                                </select>
                            </div>
                        </div>
                        <textarea v-model="comments[c.id]" rows="2" class="input mt-2" placeholder="Comment (optional)" :disabled="!review.can_rate" />
                    </div>
                </div>
                <p v-if="reviewErrors.ratings" class="field-error mt-3">{{ reviewErrors.ratings }}</p>
            </template>
            <template #footer>
                <button class="btn-secondary" @click="reviewOpen = false">Close</button>
                <template v-if="review?.can_rate">
                    <button class="btn-secondary" :disabled="saving" @click="saveReview(false)">Save draft</button>
                    <button class="btn-primary" :disabled="saving || unanswered > 0" :title="unanswered ? `${unanswered} left to rate` : ''" @click="saveReview(true)">Submit{{ unanswered ? ` (${unanswered} left)` : '' }}</button>
                </template>
            </template>
        </Modal>

        <Modal :open="ack.open" title="Acknowledge appraisal" @close="ack.open = false">
            <p class="mb-3 text-sm text-slate-500">Confirm you have seen your final appraisal for <strong>{{ ack.appraisal?.cycle_name }}</strong>. You may add a comment.</p>
            <textarea v-model="ack.comment" rows="3" class="input" placeholder="Comment (optional)" />
            <template #footer>
                <button class="btn-secondary" @click="ack.open = false">Cancel</button>
                <button class="btn-primary" @click="acknowledge">Acknowledge</button>
            </template>
        </Modal>
    </div>
</template>
