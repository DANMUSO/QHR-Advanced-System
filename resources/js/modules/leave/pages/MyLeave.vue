<script setup>
import { ref, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Plus, X, Pencil } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { label } from '@/lib/format';
import { useUiStore } from '@/stores/ui';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import ApplyLeave from './ApplyLeave.vue';
import HandoversPanel from '../components/HandoversPanel.vue';
import ApprovalSteps from '../components/ApprovalSteps.vue';

const ui = useUiStore();
const route = useRoute();
const router = useRouter();
const tabs = ref([
    { key: 'requests', label: 'My Requests' },
    { key: 'handovers', label: 'Handovers' },
]);
// ?tab=handovers (or the older ?tab=handover) opens the handover inbox.
const tab = ref(['handover', 'handovers'].includes(route.query.tab) ? 'handovers' : 'requests');
watch(tab, (t) => { if (route.query.tab !== t) router.replace({ query: { ...route.query, tab: t } }); });

const balances = ref([]);
const requests = ref([]);
const years = ref([]);
const year = ref(new Date().getFullYear());
const loading = ref(true);
const cancelTarget = ref(null);
const cancelling = ref(false);
const applyModal = ref({ open: false, request: null });
const detail = ref(null);

const statusColors = {
    pending_handover: 'yellow', pending_supervisor: 'blue', approved: 'green',
    rejected: 'red', handover_rejected: 'orange', cancelled: 'slate', recalled: 'purple',
};

async function loadRequests() {
    loading.value = true;
    try {
        const [bal, req] = await Promise.all([
            api.get('/leave/my-balances', { params: { year: year.value } }),
            api.get('/leave/my-requests', { params: { year: year.value } }),
        ]);
        balances.value = bal.data.data;
        requests.value = req.data.data;
        years.value = req.data.years;
    } finally { loading.value = false; }
}

async function loadHandoverCount() {
    try {
        const { data } = await api.get('/leave/handover-requests');
        tabs.value[1].count = data.data.length || undefined;
    } catch { /* badge only */ }
}

async function cancelRequest(reason) {
    cancelling.value = true;
    try {
        await api.post(`/leave/requests/${cancelTarget.value.id}/cancel`, { reason: reason || null });
        ui.toast('Leave request cancelled.');
        cancelTarget.value = null;
        await loadRequests();
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { cancelling.value = false; }
}

onMounted(() => { loadRequests(); loadHandoverCount(); });
</script>

<template>
    <div>
        <PageHeader title="My Leave">
            <template #actions>
                <button v-if="tab === 'requests'" class="btn-primary" @click="applyModal = { open: true, request: null }"><Plus class="size-4" /> Apply for Leave</button>
            </template>
        </PageHeader>

        <Tabs v-model="tab" :tabs="tabs" />

        <template v-if="tab === 'requests'">
            <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
            <template v-else>
                <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                    <div v-for="b in balances" :key="b.leave_type_id" class="card p-4">
                        <div class="mb-1 flex items-center gap-2">
                            <span class="size-2.5 rounded-full" :style="{ background: b.color }" />
                            <span class="truncate text-xs font-medium text-slate-500">{{ b.name }}</span>
                        </div>
                        <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ b.projected ?? '∞' }}</p>
                        <p class="text-xs text-slate-400">{{ b.taken }} taken<template v-if="b.booked"> · {{ b.booked }} booked</template> · {{ b.pending }} pending</p>
                    </div>
                </div>
                <div class="mb-4 flex items-center gap-3">
                    <label class="text-sm font-medium text-slate-600 dark:text-slate-400" for="my-leave-year">Year</label>
                    <select id="my-leave-year" v-model="year" class="input w-28" @change="loadRequests">
                        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                    </select>
                </div>
                <div class="card overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                            <tr>
                                <th class="px-4 py-3 text-left">Type</th>
                                <th class="px-4 py-3 text-left">Dates</th>
                                <th class="px-4 py-3 text-right">Days</th>
                                <th class="px-4 py-3 text-left">Status</th>
                                <th class="px-4 py-3 text-left">Submitted</th>
                                <th class="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="r in requests" :key="r.id" class="cursor-pointer hover:bg-slate-50/70 dark:hover:bg-slate-800/30" @click="detail = r">
                                <td class="px-4 py-3">
                                    <span class="flex items-center gap-2"><span class="size-2 rounded-full" :style="{ background: r.leave_type?.color }" /> {{ r.leave_type?.name }}</span>
                                </td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-400">{{ r.start_date }} – {{ r.end_date }}<span v-if="r.start_time"> ({{ r.start_time }}–{{ r.end_time }})</span></td>
                                <td class="px-4 py-3 text-right font-medium">{{ r.days }}</td>
                                <td class="px-4 py-3"><Badge :color="statusColors[r.status]">{{ r.status_label }}</Badge></td>
                                <td class="px-4 py-3 text-xs text-slate-400">{{ r.submitted_at?.slice(0, 10) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right" @click.stop>
                                    <button v-if="r.can_edit" class="btn-ghost !p-1.5" title="Edit" @click="applyModal = { open: true, request: r }"><Pencil class="size-4" /></button>
                                    <button v-if="r.can_cancel" class="btn-ghost !p-1.5 text-red-500" title="Cancel" @click="cancelTarget = r"><X class="size-4" /></button>
                                </td>
                            </tr>
                            <tr v-if="!requests.length">
                                <td colspan="6" class="px-4 py-10 text-center text-slate-400">No leave requests for {{ year }}.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </template>

        <HandoversPanel v-else @changed="loadHandoverCount" />

        <ConfirmDialog :open="!!cancelTarget" title="Cancel Leave Request"
            :message="cancelTarget?.status === 'approved' ? 'This approved leave will be cancelled and the days returned to your balance.' : 'Cancel this leave request?'"
            confirm-label="Cancel Request" danger reason-label="Reason (optional)" :reason-required="false" :loading="cancelling"
            @confirm="cancelRequest" @close="cancelTarget = null" />

        <ApplyLeave :open="applyModal.open" :request="applyModal.request" @close="applyModal.open = false" @done="loadRequests" />

        <Modal :open="!!detail" :title="detail?.leave_type?.name ?? 'Leave request'" @close="detail = null">
            <div v-if="detail" class="space-y-3 text-sm">
                <p><Badge :color="statusColors[detail.status]">{{ detail.status_label }}</Badge></p>
                <p class="text-slate-700 dark:text-slate-300">{{ detail.start_date }} – {{ detail.end_date }} · {{ detail.days }} day(s)</p>
                <p v-if="detail.amended" class="text-xs text-purple-600 dark:text-purple-300">Amended from {{ detail.original_start_date }} – {{ detail.original_end_date }} ({{ detail.original_days }} days)</p>
                <div v-if="detail.handover1" class="text-xs text-slate-500">
                    Handover: {{ detail.handover1.employee?.name }} ({{ detail.handover1.status }})<span v-if="detail.handover2">, {{ detail.handover2.employee?.name }} ({{ detail.handover2.status }})</span>
                    <p v-if="detail.handover1.reason" class="mt-0.5">“{{ detail.handover1.reason }}”</p>
                </div>
                <ApprovalSteps v-if="detail.approval_steps" :steps="detail.approval_steps" />
                <ul v-if="detail.approval_history?.length" class="space-y-1 text-xs text-slate-500">
                    <li v-for="(h, i) in detail.approval_history" :key="i">{{ h.label }}: {{ label(h.decision) }} by {{ h.by_name }}<span v-if="h.note"> — {{ h.note }}</span></li>
                </ul>
                <p v-if="detail.decision_note" class="text-slate-500">Note: {{ detail.decision_note }}</p>
                <p v-if="detail.cancel_reason" class="text-slate-500">Cancelled: {{ detail.cancel_reason }}</p>
                <p v-if="detail.recall_date" class="text-slate-500">Recalled on {{ detail.recall_date }} ({{ detail.recalled_days }} day(s) returned)</p>
            </div>
        </Modal>
    </div>
</template>
