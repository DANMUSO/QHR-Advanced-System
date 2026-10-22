<script setup>
import { ref, onMounted } from 'vue';
import { Check, X, Pencil } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import AmendLeaveModal from './AmendLeaveModal.vue';
import ApprovalSteps from './ApprovalSteps.vue';

const emit = defineEmits(['changed']);
const ui = useUiStore();
const rows = ref([]);
const history = ref([]);
const loading = ref(true);
const actionModal = ref({ open: false, request: null, type: '' });
const reason = ref('');
const processing = ref(false);
const amend = ref({ open: false, request: null });

const statusColors = { approved: 'green', rejected: 'red', recalled: 'purple' };

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/leave/approvals');
        rows.value = data.data;
        history.value = data.history ?? [];
    } finally { loading.value = false; }
}

async function doAction() {
    processing.value = true;
    const { request, type } = actionModal.value;
    try {
        if (type === 'approve') {
            const { data } = await api.post(`/leave/requests/${request.id}/approve`, { note: reason.value || null });
            ui.toast(data.data?.status === 'approved' ? 'Leave approved.' : 'Approved — sent to the next approver.');
        } else {
            await api.post(`/leave/requests/${request.id}/reject`, { reason: reason.value });
            ui.toast('Leave rejected.');
        }
        actionModal.value.open = false;
        await reload();
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { processing.value = false; }
}

async function reload() { await load(); emit('changed'); }

onMounted(load);
</script>

<template>
    <div>
        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <template v-else>
            <div v-if="!rows.length" class="card p-10 text-center text-slate-400">No leave requests awaiting your decision.</div>
            <div v-else class="space-y-3">
                <div v-for="r in rows" :key="r.id" class="card p-4">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                        <div class="flex flex-1 items-start gap-3">
                            <Avatar :src="r.employee?.photo_url" :name="r.employee?.name" size="md" />
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-slate-900 dark:text-white">{{ r.employee?.name }}</span>
                                    <Badge color="blue">{{ r.current_step ? `Awaiting ${r.current_step}` : r.status_label }}</Badge>
                                    <Badge v-if="r.amended" color="purple">Amended</Badge>
                                    <Badge v-if="r.unassigned" color="amber">Unassigned – waiting for HR</Badge>
                                    <span v-else-if="r.approver && !r.is_my_report" class="text-xs text-slate-400">{{ r.approver }}</span>
                                </div>
                                <p class="mt-0.5 flex items-center gap-1.5 text-sm text-slate-500">
                                    <span class="size-2 rounded-full" :style="{ background: r.leave_type?.color }" />
                                    {{ r.leave_type?.name }}<span v-if="r.employee?.department"> · {{ r.employee.department }}</span>
                                </p>
                                <p class="mt-1 text-sm text-slate-700 dark:text-slate-300">
                                    {{ r.start_date }} – {{ r.end_date }}<span v-if="r.start_time"> ({{ r.start_time }}–{{ r.end_time }})</span>
                                    · <strong>{{ r.days }} day(s)</strong>
                                </p>
                                <p v-if="r.comment" class="mt-1 text-sm text-slate-500 dark:text-slate-400">“{{ r.comment }}”</p>
                                <p v-if="r.handover1" class="mt-1 text-xs text-slate-400">
                                    Handover: {{ r.handover1.employee?.name }}<span v-if="r.handover2">, {{ r.handover2.employee?.name }}</span>
                                </p>
                                <a v-if="r.document_url" :href="r.document_url + '?inline=1'" target="_blank" class="mt-1 inline-block text-xs text-brand-600 hover:underline dark:text-brand-400">View document</a>
                                <ApprovalSteps v-if="r.approval_steps" :steps="r.approval_steps" class="mt-2" />
                            </div>
                        </div>
                        <div v-if="r.can_decide !== false" class="flex flex-wrap gap-2 sm:justify-end">
                            <button class="btn-success !py-1.5" @click="actionModal = { open: true, request: r, type: 'approve' }; reason = ''"><Check class="size-4" /> Approve</button>
                            <button class="btn-secondary !py-1.5" @click="amend = { open: true, request: r }"><Pencil class="size-4" /> Amend &amp; Approve</button>
                            <button class="btn-danger !py-1.5" @click="actionModal = { open: true, request: r, type: 'reject' }; reason = ''"><X class="size-4" /> Reject</button>
                        </div>
                        <p v-else class="text-xs text-slate-400">Waiting for another approver</p>
                    </div>
                </div>
            </div>

            <template v-if="history.length">
                <h3 class="mb-3 mt-8 text-sm font-semibold text-slate-700 dark:text-slate-300">Recently decided</h3>
                <div class="card divide-y divide-slate-100 dark:divide-slate-800">
                    <div v-for="h in history" :key="h.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-sm">
                        <span class="font-medium text-slate-900 dark:text-white">{{ h.employee?.name }}</span>
                        <span class="text-slate-500">{{ h.leave_type?.name }} · {{ h.start_date }} – {{ h.end_date }} ({{ h.days }}d)</span>
                        <Badge :color="statusColors[h.status] ?? 'slate'" class="ml-auto">{{ h.status_label }}</Badge>
                        <span class="w-full text-xs text-slate-400">{{ h.decided_by }}<span v-if="h.decision_note"> — {{ h.decision_note }}</span></span>
                    </div>
                </div>
            </template>
        </template>

        <Modal :open="actionModal.open" :title="actionModal.type === 'approve' ? 'Approve Leave' : 'Reject Leave'" @close="actionModal.open = false">
            <div class="space-y-4">
                <p class="text-sm text-slate-600 dark:text-slate-400">
                    {{ actionModal.request?.employee?.name }} — {{ actionModal.request?.leave_type?.name }}
                    ({{ actionModal.request?.start_date }} – {{ actionModal.request?.end_date }}, {{ actionModal.request?.days }} day(s))
                </p>
                <div>
                    <label class="label">{{ actionModal.type === 'reject' ? 'Reason (required)' : 'Note (optional)' }}</label>
                    <textarea v-model="reason" rows="3" class="input" />
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="actionModal.open = false">Cancel</button>
                <button :class="actionModal.type === 'approve' ? 'btn-primary' : 'btn-danger'" :disabled="processing || (actionModal.type === 'reject' && !reason.trim())" @click="doAction">
                    {{ processing ? 'Processing…' : (actionModal.type === 'approve' ? 'Approve' : 'Reject') }}
                </button>
            </template>
        </Modal>

        <AmendLeaveModal :open="amend.open" :request="amend.request" mode="approve" @close="amend.open = false" @done="reload" />
    </div>
</template>
