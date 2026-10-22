<script setup>
import { ref, computed, onMounted } from 'vue';
import { Check, X, Paperclip } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { useAuthStore } from '@/stores/auth';
import { formatDate, formatMoney } from '@/lib/format';
import { openServerFile } from '@/lib/export';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import Tabs from '@/components/ui/Tabs.vue';

/** HR (Medical Approver) queue: approve (optionally a lower amount, with a note) or reject with a reason. */
const ui = useUiStore();
const auth = useAuthStore();

const scope = ref('mine');
const tabs = computed(() => [
    { key: 'mine', label: 'Awaiting my decision' },
    ...(auth.can('claims.approve') ? [{ key: 'all', label: 'All pending' }] : []),
    { key: 'decided', label: 'Decided by me' },
]);

const claims = ref([]);
const loading = ref(true);
const modal = ref({ open: false, claim: null, type: '', detail: null });
const form = ref({ approved_amount: '', note: '', reason: '' });
const errors = ref({});
const processing = ref(false);

const statusColor = { submitted: 'amber', hr_approved: 'blue', paid: 'green', hr_rejected: 'red', finance_rejected: 'orange', cancelled: 'slate' };

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/claims/approvals', { params: { scope: scope.value, per_page: 100 } });
        claims.value = data.data;
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

function switchTab(t) { scope.value = t; load(); }

async function open(claim, type) {
    errors.value = {};
    form.value = { approved_amount: claim.total_amount, note: '', reason: '' };
    modal.value = { open: true, claim, type, detail: null };
    try {
        const { data } = await api.get(`/claims/${claim.id}`);
        if (modal.value.claim?.id === claim.id) modal.value.detail = data.data ?? data;
    } catch { /* details are optional */ }
}

async function decide() {
    processing.value = true;
    errors.value = {};
    const c = modal.value.claim;
    try {
        if (modal.value.type === 'approve') {
            await api.post(`/claims/${c.id}/approve`, {
                approved_amount: form.value.approved_amount || null,
                note: form.value.note || null,
            });
            ui.toast('Claim approved and sent to Finance.');
        } else {
            await api.post(`/claims/${c.id}/reject`, { reason: form.value.reason });
            ui.toast('Claim rejected.');
        }
        modal.value.open = false;
        await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally {
        processing.value = false;
    }
}

const partial = computed(() => modal.value.claim && Number(form.value.approved_amount) < modal.value.claim.total_amount);

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Claim Approvals" subtitle="Medical claims awaiting the Medical Approver" />

        <Tabs :tabs="tabs" :model-value="scope" @update:model-value="switchTab" />

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <EmptyState v-else-if="!claims.length" :title="scope === 'decided' ? 'No decisions yet' : 'No claims awaiting approval'" />
        <div v-else class="space-y-3">
            <div v-for="c in claims" :key="c.id" class="card p-4">
                <div class="flex flex-wrap items-start gap-4">
                    <Avatar :src="c.employee?.photo_url" :name="c.employee?.name" size="md" />
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-slate-900 dark:text-white">{{ c.employee?.name }}</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ formatDate(c.claim_date) }} · {{ c.provider_name }} · {{ c.claimant_type_label }}<span v-if="c.claimant_name"> ({{ c.claimant_name }})</span></p>
                        <p class="mt-1 text-lg font-bold text-slate-900 dark:text-white">{{ formatMoney(c.total_amount) }}</p>
                        <p class="text-xs text-slate-400">
                            Ref: {{ c.reference }}<span v-if="c.receipts_count"> · {{ c.receipts_count }} receipt(s)</span>
                            <span v-if="c.balance"> · Balance: {{ formatMoney(c.balance.annual_balance) }} yr / {{ formatMoney(c.balance.monthly_balance) }} mo</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <template v-if="c.can?.hr_decide">
                            <button class="btn-primary !py-1.5" @click="open(c, 'approve')"><Check class="size-4" /> Approve</button>
                            <button class="btn-danger !py-1.5" @click="open(c, 'reject')"><X class="size-4" /> Reject</button>
                        </template>
                        <Badge v-else :color="statusColor[c.status] ?? 'slate'">{{ c.status_label }}</Badge>
                    </div>
                </div>
            </div>
        </div>

        <Modal :open="modal.open" :title="modal.type === 'approve' ? 'Approve claim' : 'Reject claim'" size="lg" @close="modal.open = false">
            <div class="space-y-4">
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ modal.claim?.employee?.name }} · {{ modal.claim?.reference }} · {{ formatMoney(modal.claim?.total_amount) }}</p>

                <div v-if="modal.detail" class="rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-700">
                    <div v-for="i in modal.detail.items" :key="i.id" class="flex justify-between py-0.5">
                        <span class="text-slate-600 dark:text-slate-300">{{ i.category_label }}<span v-if="i.description" class="text-slate-400"> · {{ i.description }}</span></span>
                        <span class="font-medium">{{ formatMoney(i.amount) }}</span>
                    </div>
                    <div v-if="modal.detail.receipts?.length" class="mt-2 flex flex-wrap gap-2 border-t border-slate-100 pt-2 dark:border-slate-800">
                        <button v-for="r in modal.detail.receipts" :key="r.id" class="btn-ghost !px-2 !py-1 text-xs" @click="openServerFile(r.url)">
                            <Paperclip class="size-3.5" /> {{ r.name }}
                        </button>
                    </div>
                    <p v-if="modal.detail.notes" class="mt-2 text-slate-500 dark:text-slate-400">{{ modal.detail.notes }}</p>
                </div>

                <template v-if="modal.type === 'approve'">
                    <div>
                        <label class="label">Approved amount (KES)</label>
                        <input v-model="form.approved_amount" type="number" min="1" :max="modal.claim?.total_amount" step="0.01" class="input" />
                        <p v-if="errors.approved_amount" class="field-error">{{ errors.approved_amount }}</p>
                    </div>
                    <div>
                        <label class="label">Note {{ partial ? '(required for a lower amount)' : '(optional)' }}</label>
                        <textarea v-model="form.note" rows="2" class="input" />
                        <p v-if="errors.note" class="field-error">{{ errors.note }}</p>
                    </div>
                </template>
                <div v-else>
                    <label class="label">Reason (required)</label>
                    <textarea v-model="form.reason" rows="3" class="input" />
                    <p v-if="errors.reason" class="field-error">{{ errors.reason }}</p>
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                <button
                    :class="modal.type === 'approve' ? 'btn-primary' : 'btn-danger'"
                    :disabled="processing || (modal.type === 'reject' && form.reason.trim().length < 3) || (modal.type === 'approve' && partial && !form.note.trim())"
                    @click="decide"
                >
                    <Spinner v-if="processing" /> {{ modal.type === 'approve' ? 'Approve' : 'Reject' }}
                </button>
            </template>
        </Modal>
    </div>
</template>
