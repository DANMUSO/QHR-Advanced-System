<script setup>
import { ref, onMounted } from 'vue';
import { Plus, X, Pencil } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import Badge from '@/components/ui/Badge.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import SubmitClaim from './SubmitClaim.vue';

const ui = useUiStore();
const claims = ref([]);
const summary = ref({});
const loading = ref(true);
const claimModal = ref(false);
const cancelling = ref(null);

const statusColors = { submitted: 'yellow', hr_approved: 'blue', paid: 'green', hr_rejected: 'red', finance_rejected: 'orange', cancelled: 'slate' };

async function load() {
    loading.value = true;
    try {
        const [claimsRes, sumRes] = await Promise.all([
            api.get('/claims/mine'),
            api.get('/claims/summary'),
        ]);
        claims.value = claimsRes.data.data;
        summary.value = sumRes.data;
    } finally {
        loading.value = false;
    }
}

async function cancel(c) {
    if (!confirm(`Cancel claim ${c.reference}?`)) return;
    cancelling.value = c.id;
    try {
        await api.post(`/claims/${c.id}/cancel`);
        ui.toast('Claim cancelled.');
        await load();
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        cancelling.value = null;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="My Medical Claims">
            <template #actions>
                <button class="btn-primary" @click="claimModal = true"><Plus class="size-4" /> Submit Claim</button>
            </template>
        </PageHeader>

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <template v-else>
            <!-- Balance cards -->
            <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3">
                <div class="card p-4">
                    <p class="text-xs text-slate-500">Available this year</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ summary.year_balance?.annual_available?.toLocaleString() ?? '—' }}</p>
                    <p class="text-xs text-slate-400">of {{ summary.year_balance?.annual_limit?.toLocaleString() }}<span v-if="summary.year_balance?.annual_pending > 0"> · {{ summary.year_balance.annual_pending.toLocaleString() }} pending</span></p>
                </div>
                <div class="card p-4">
                    <p class="text-xs text-slate-500">Available this month</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ summary.year_balance?.monthly_available?.toLocaleString() ?? '—' }}</p>
                    <p class="text-xs text-slate-400">of {{ summary.year_balance?.monthly_limit?.toLocaleString() }}<span v-if="summary.year_balance?.monthly_pending > 0"> · {{ summary.year_balance.monthly_pending.toLocaleString() }} pending</span></p>
                </div>
            </div>

            <div class="card overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left">Reference</th>
                            <th class="px-4 py-3 text-left">Date</th>
                            <th class="px-4 py-3 text-left">Provider</th>
                            <th class="px-4 py-3 text-right">Amount</th>
                            <th class="px-4 py-3 text-left">HR Status</th>
                            <th class="px-4 py-3 text-left">Finance Status</th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="c in claims" :key="c.id" class="hover:bg-slate-50/70 dark:hover:bg-slate-800/30">
                            <td class="px-4 py-3 font-mono text-xs">{{ c.reference }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ c.claim_date }}</td>
                            <td class="px-4 py-3">{{ c.provider_name }}</td>
                            <td class="px-4 py-3 text-right font-medium">{{ c.total_amount?.toLocaleString() }}</td>
                            <td class="px-4 py-3"><Badge :color="statusColors[c.status]">{{ c.status_label ?? c.status }}</Badge></td>
                            <td class="px-4 py-3">
                                <span v-if="c.status === 'paid'" class="text-xs text-green-600 dark:text-green-400">Paid</span>
                                <span v-else-if="c.status === 'finance_rejected'" class="text-xs text-red-500">Finance rejected</span>
                                <span v-else-if="c.status === 'hr_approved'" class="text-xs text-blue-500">Awaiting payment</span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button v-if="c.status === 'submitted'" class="btn-ghost !p-1.5 text-rose-500" :disabled="cancelling === c.id" title="Cancel claim" @click="cancel(c)">
                                    <X class="size-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!claims.length">
                            <td colspan="7" class="px-4 py-10 text-center text-slate-400">No claims submitted yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>

    <SubmitClaim :open="claimModal" @close="claimModal = false" @done="load" />
</template>
