<script setup>
/** Handover requests: accept, accept with amended dates, or decline with a reason. */
import { ref, onMounted } from 'vue';
import { Check, X, Pencil } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { label } from '@/lib/format';
import { useUiStore } from '@/stores/ui';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import AmendLeaveModal from './AmendLeaveModal.vue';

const emit = defineEmits(['changed']);
const ui = useUiStore();
const rows = ref([]);
const history = ref([]);
const loading = ref(true);
const modal = ref({ open: false, request: null, approve: true });
const reason = ref('');
const processing = ref(false);
const amend = ref({ open: false, request: null });

const myStatusColors = { approved: 'green', rejected: 'red', pending: 'yellow' };

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/leave/handover-requests');
        rows.value = data.data;
        history.value = data.history ?? [];
    } finally { loading.value = false; }
}

async function decide() {
    processing.value = true;
    try {
        await api.post(`/leave/requests/${modal.value.request.id}/handover-decide`, { approve: modal.value.approve, reason: reason.value || null });
        ui.toast(modal.value.approve ? 'Handover accepted.' : 'Handover declined.');
        modal.value.open = false;
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
            <div v-if="!rows.length" class="card p-10 text-center text-slate-400">No pending handover requests.</div>
            <div v-else class="space-y-3">
                <div v-for="r in rows" :key="r.id" class="card p-4">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                        <div class="flex flex-1 items-start gap-3">
                            <Avatar :src="r.employee?.photo_url" :name="r.employee?.name" size="md" />
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-slate-900 dark:text-white">{{ r.employee?.name }}</span>
                                    <Badge v-if="r.escalated" color="orange">Escalated to you</Badge>
                                </div>
                                <p class="text-sm text-slate-500">{{ r.leave_type?.name }} · {{ r.start_date }} – {{ r.end_date }} ({{ r.days }} day(s))</p>
                                <p v-if="r.duties" class="mt-1 whitespace-pre-line text-sm text-slate-600 dark:text-slate-400">{{ r.duties }}</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2 sm:justify-end">
                            <button class="btn-success !py-1.5" @click="modal = { open: true, request: r, approve: true }; reason = ''"><Check class="size-4" /> Accept</button>
                            <button class="btn-secondary !py-1.5" @click="amend = { open: true, request: r }"><Pencil class="size-4" /> Amend dates</button>
                            <button class="btn-danger !py-1.5" @click="modal = { open: true, request: r, approve: false }; reason = ''"><X class="size-4" /> Decline</button>
                        </div>
                    </div>
                </div>
            </div>

            <template v-if="history.length">
                <h3 class="mb-3 mt-8 text-sm font-semibold text-slate-700 dark:text-slate-300">Answered</h3>
                <div class="card divide-y divide-slate-100 dark:divide-slate-800">
                    <div v-for="h in history" :key="h.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-sm">
                        <span class="font-medium text-slate-900 dark:text-white">{{ h.employee?.name }}</span>
                        <span class="text-slate-500">{{ h.leave_type?.name }} · {{ h.start_date }} – {{ h.end_date }}</span>
                        <Badge :color="myStatusColors[h.my_status] ?? 'slate'" class="ml-auto">{{ h.my_status ? label(h.my_status) : '—' }}</Badge>
                    </div>
                </div>
            </template>
        </template>

        <Modal :open="modal.open" :title="modal.approve ? 'Accept Handover' : 'Decline Handover'" @close="modal.open = false">
            <p class="mb-3 text-sm text-slate-600 dark:text-slate-400">{{ modal.request?.employee?.name }} — {{ modal.request?.start_date }} – {{ modal.request?.end_date }}</p>
            <label class="label">{{ modal.approve ? 'Note (optional)' : 'Reason (required)' }}</label>
            <textarea v-model="reason" rows="3" class="input" />
            <template #footer>
                <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                <button :class="modal.approve ? 'btn-primary' : 'btn-danger'" :disabled="processing || (!modal.approve && !reason.trim())" @click="decide">
                    {{ processing ? 'Saving…' : (modal.approve ? 'Accept' : 'Decline') }}
                </button>
            </template>
        </Modal>

        <AmendLeaveModal :open="amend.open" :request="amend.request" mode="handover" @close="amend.open = false" @done="reload" />
    </div>
</template>
