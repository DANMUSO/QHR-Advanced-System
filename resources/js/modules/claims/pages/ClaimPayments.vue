<script setup>
import { ref, computed, onMounted } from 'vue';
import { CreditCard, X, FileText, Download, Search } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { formatDate, formatMoney } from '@/lib/format';
import { openServerFile } from '@/lib/export';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import Tabs from '@/components/ui/Tabs.vue';

/** Finance payment queue for HR-approved medical claims (HR-FEATURES §4.3): mark paid (single or bulk) or reject. */
const ui = useUiStore();
const tab = ref('hr_approved');
const tabs = [
    { key: 'hr_approved', label: 'Awaiting payment' },
    { key: 'paid', label: 'Paid' },
    { key: 'finance_rejected', label: 'Rejected' },
];

const claims = ref([]);
const totals = ref({ count: 0, amount: 0 });
const methods = ref({ mpesa: 'M-Pesa', bank: 'Bank transfer', cash: 'Cash' });
const loading = ref(true);
const search = ref('');
const selected = ref([]);

const payModal = ref({ open: false, claims: [] });
const payForm = ref({});
const payErrors = ref({});
const paying = ref(false);
const reject = ref({ open: false, claim: null, loading: false, error: '' });

const payable = (c) => c.approved_amount ?? c.total_amount;
const allSelected = computed(() => claims.value.length > 0 && selected.value.length === claims.value.length);
const selectedTotal = computed(() => claims.value.filter((c) => selected.value.includes(c.id)).reduce((s, c) => s + payable(c), 0));

async function load() {
    loading.value = true;
    selected.value = [];
    try {
        const { data } = await api.get('/claims/payments', { params: { status: tab.value, search: search.value || undefined, per_page: 100 } });
        claims.value = data.data;
        totals.value = data.totals ?? totals.value;
        if (data.methods) methods.value = data.methods;
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

function switchTab(t) { tab.value = t; load(); }
function toggleAll() { selected.value = allSelected.value ? [] : claims.value.map((c) => c.id); }

function openPay(list) {
    payErrors.value = {};
    payForm.value = { payment_method: 'mpesa', payment_reference: '', paid_on: new Date().toISOString().slice(0, 10), note: '' };
    payModal.value = { open: true, claims: list };
}

async function pay() {
    paying.value = true;
    payErrors.value = {};
    const list = payModal.value.claims;
    try {
        if (list.length === 1) {
            await api.post(`/claims/${list[0].id}/pay`, payForm.value);
            ui.toast('Claim marked as paid.');
        } else {
            const { data } = await api.post('/claims/bulk-pay', { ...payForm.value, ids: list.map((c) => c.id) });
            ui.toast(data.message + (data.skipped?.length ? ` ${data.skipped.length} skipped.` : ''), data.skipped?.length ? 'warning' : 'success');
            if (data.batch) openServerFile(`/api/claims/payment-schedule?batch=${encodeURIComponent(data.batch)}`);
        }
        payModal.value.open = false;
        await load();
    } catch (e) {
        payErrors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally {
        paying.value = false;
    }
}

async function confirmReject(reason) {
    reject.value.loading = true;
    reject.value.error = '';
    try {
        await api.post(`/claims/${reject.value.claim.id}/finance-reject`, { reason });
        ui.toast('Claim rejected; the amount was restored to the employee\'s balance.');
        reject.value.open = false;
        await load();
    } catch (e) {
        reject.value.error = errorMessage(e);
    } finally {
        reject.value.loading = false;
    }
}

function schedule() {
    openServerFile(`/api/claims/payment-schedule?ids=${selected.value.join(',')}`);
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Claim Payments" subtitle="Medical claims approved by HR, awaiting Finance">
            <template #actions>
                <div class="text-sm text-slate-500 dark:text-slate-400">{{ totals.count }} claim(s) · {{ formatMoney(totals.amount) }}</div>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="relative w-full sm:w-72">
                <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input v-model="search" type="search" class="input !pl-9" placeholder="Search name or reference" @keyup.enter="load" />
            </div>
            <template v-if="tab === 'hr_approved' && claims.length">
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" :checked="allSelected" @change="toggleAll" /> Select all
                </label>
                <template v-if="selected.length">
                    <button class="btn-primary !py-1.5" @click="openPay(claims.filter((c) => selected.includes(c.id)))">
                        <CreditCard class="size-4" /> Pay {{ selected.length }} · {{ formatMoney(selectedTotal) }}
                    </button>
                    <button class="btn-secondary !py-1.5" @click="schedule"><Download class="size-4" /> Payment schedule</button>
                </template>
            </template>
        </div>

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <EmptyState v-else-if="!claims.length" :title="tab === 'hr_approved' ? 'No claims awaiting payment' : 'Nothing here yet'" />
        <div v-else class="space-y-3">
            <div v-for="c in claims" :key="c.id" class="card p-4">
                <div class="flex flex-wrap items-start gap-4">
                    <input v-if="tab === 'hr_approved'" v-model="selected" type="checkbox" :value="c.id" class="mt-3" :aria-label="`Select ${c.reference}`" />
                    <Avatar :src="c.employee?.photo_url" :name="c.employee?.name" size="md" />
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-slate-900 dark:text-white">{{ c.employee?.name }}</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ formatDate(c.claim_date) }} · {{ c.provider_name }} · {{ c.claimant_type_label }}</p>
                        <p class="mt-1 text-lg font-bold text-slate-900 dark:text-white">
                            {{ formatMoney(payable(c)) }}
                            <span v-if="c.approved_amount !== null && c.approved_amount < c.total_amount" class="text-xs font-normal text-slate-400">of {{ formatMoney(c.total_amount) }} claimed</span>
                        </p>
                        <p class="text-xs text-slate-400">Phone: {{ c.employee?.phone || '—' }} · Ref: {{ c.reference }}<span v-if="c.employee?.branch"> · {{ c.employee.branch }}</span></p>
                        <p v-if="c.status === 'paid'" class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Paid {{ formatDate(c.paid_on) }} via {{ methods[c.payment_method] ?? c.payment_method }}<span v-if="c.payment_reference"> · {{ c.payment_reference }}</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <template v-if="c.status === 'hr_approved'">
                            <button class="btn-primary !py-1.5" @click="openPay([c])"><CreditCard class="size-4" /> Mark paid</button>
                            <button class="btn-danger !py-1.5" @click="reject = { open: true, claim: c, loading: false, error: '' }"><X class="size-4" /> Reject</button>
                        </template>
                        <button v-else-if="c.status === 'paid'" class="btn-secondary !py-1.5" @click="openServerFile(`/api/claims/${c.id}/confirmation`)">
                            <FileText class="size-4" /> Payment confirmation
                        </button>
                        <Badge v-else color="red">Rejected by Finance</Badge>
                    </div>
                </div>
            </div>
        </div>

        <Modal :open="payModal.open" :title="payModal.claims.length > 1 ? `Pay ${payModal.claims.length} claims` : 'Mark as paid'" @close="payModal.open = false">
            <div class="space-y-4">
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    <template v-if="payModal.claims.length === 1">{{ payModal.claims[0].employee?.name }} · {{ formatMoney(payable(payModal.claims[0])) }} · {{ payModal.claims[0].employee?.phone || 'no phone' }}</template>
                    <template v-else>Total {{ formatMoney(payModal.claims.reduce((s, c) => s + payable(c), 0)) }}. A payment schedule CSV opens after saving.</template>
                </p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Payment method</label>
                        <select v-model="payForm.payment_method" class="input">
                            <option v-for="(label, key) in methods" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <p v-if="payErrors.payment_method" class="field-error">{{ payErrors.payment_method }}</p>
                    </div>
                    <div>
                        <label class="label">Paid on</label>
                        <input v-model="payForm.paid_on" type="date" class="input" />
                        <p v-if="payErrors.paid_on" class="field-error">{{ payErrors.paid_on }}</p>
                    </div>
                </div>
                <div>
                    <label class="label">Payment reference</label>
                    <input v-model="payForm.payment_reference" class="input" placeholder="e.g. M-Pesa code or bank ref" />
                    <p v-if="payErrors.payment_reference" class="field-error">{{ payErrors.payment_reference }}</p>
                </div>
                <div>
                    <label class="label">Note (optional)</label>
                    <textarea v-model="payForm.note" rows="2" class="input" />
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="payModal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="paying" @click="pay"><Spinner v-if="paying" /> Mark paid</button>
            </template>
        </Modal>

        <ConfirmDialog
            :open="reject.open"
            title="Reject payment"
            :message="`${reject.claim?.employee?.name ?? ''} · ${reject.claim ? formatMoney(payable(reject.claim)) : ''}. The amount is restored to the employee's balance and they are notified by email and SMS.`"
            reason-label="Reason (required)"
            confirm-label="Reject"
            danger
            :loading="reject.loading"
            :error="reject.error"
            @close="reject.open = false"
            @confirm="confirmReject"
        />
    </div>
</template>
