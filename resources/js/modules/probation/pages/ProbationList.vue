<script setup>
import { ref, onMounted } from 'vue';
import { ClipboardList } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { formatDate } from '@/lib/format';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import ProbationReviewCard from '../components/ProbationReviewCard.vue';

const ui = useUiStore();
const employees = ref([]);
const paginator = ref(null);
const loading = ref(true);
const statusFilter = ref('');
const reviewModal = ref({ open: false, employee: null, review: null });

const statusColors = { on_probation: 'yellow', extended: 'orange', confirmed: 'green', terminated: 'red' };
const reviewLabels = { pending: 'Awaiting first review', in_review: 'In review', hr_review: 'Awaiting HR decision' };

async function load(page = 1) {
    loading.value = true;
    try {
        const { data } = await api.get('/probation', { params: { page, probation_status: statusFilter.value || undefined } });
        employees.value = data.data;
        paginator.value = { current_page: data.current_page, last_page: data.last_page, total: data.total };
    } finally {
        loading.value = false;
    }
}

async function openReview(emp) {
    try {
        const { data } = await api.get(`/probation/employees/${emp.id}`);
        reviewModal.value = { open: true, employee: emp, review: data.data };
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    }
}

async function afterUpdate() {
    if (reviewModal.value.employee) await openReview(reviewModal.value.employee);
    await load(paginator.value?.current_page ?? 1);
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Probation Management" subtitle="Staff on probation, including extended probations.">
            <select v-model="statusFilter" class="input !w-44 !py-2" @change="load()">
                <option value="">On probation &amp; extended</option>
                <option value="on_probation">On probation</option>
                <option value="extended">Extended</option>
            </select>
        </PageHeader>
        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <div v-else-if="!employees.length" class="card p-10 text-center text-slate-400">No employees on probation.</div>
        <div v-else class="space-y-3">
            <div v-for="emp in employees" :key="emp.id" class="card p-4">
                <div class="flex flex-wrap items-start gap-4">
                    <Avatar :src="emp.photo_url" :name="emp.name" size="md" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <RouterLink :to="`/employees/${emp.id}`" class="font-semibold text-slate-900 hover:underline dark:text-white">{{ emp.name }}</RouterLink>
                            <Badge :color="statusColors[emp.probation_status] ?? 'slate'">{{ emp.probation_status?.replace('_', ' ') }}</Badge>
                        </div>
                        <p class="text-sm text-slate-500">{{ emp.position }} · {{ emp.department }}</p>
                        <p class="text-sm text-slate-700 dark:text-slate-300">Probation ends: <strong>{{ formatDate(emp.probation_end_date) }}</strong>
                            <span v-if="emp.days_remaining !== null" class="ml-2 text-xs" :class="emp.days_remaining <= 7 ? 'text-red-500' : 'text-slate-400'">({{ emp.days_remaining }} days)</span>
                        </p>
                        <p v-if="emp.review" class="mt-1 text-xs text-slate-400">
                            {{ reviewLabels[emp.review.status] ?? emp.review.status }}<span v-if="emp.review.current_stage && emp.review.status !== 'hr_review'"> · {{ emp.review.current_stage }}</span>
                        </p>
                    </div>
                    <button class="btn-secondary !py-1.5" @click="openReview(emp)"><ClipboardList class="size-4" /> Review</button>
                </div>
            </div>
            <div v-if="paginator?.last_page > 1" class="flex justify-center gap-2">
                <button class="btn-secondary" :disabled="paginator.current_page <= 1" @click="load(paginator.current_page - 1)">Previous</button>
                <button class="btn-secondary" :disabled="paginator.current_page >= paginator.last_page" @click="load(paginator.current_page + 1)">Next</button>
            </div>
        </div>

        <Modal :open="reviewModal.open" :title="`Probation review · ${reviewModal.employee?.name ?? ''}`" size="lg" @close="reviewModal.open = false">
            <ProbationReviewCard v-if="reviewModal.review" :review="reviewModal.review" @updated="afterUpdate" />
            <p v-else class="text-sm text-slate-400">No probation review for this employee.</p>
        </Modal>
    </div>
</template>
