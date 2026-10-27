<script setup>
/** Company balances by employee, with adjustment and the ledger behind each balance. */
import { ref, onMounted } from 'vue';
import { SlidersHorizontal, Search } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { label } from '@/lib/format';
import { useUiStore } from '@/stores/ui';
import Avatar from '@/components/ui/Avatar.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';

const ui = useUiStore();
const thisYear = new Date().getFullYear();
const year = ref(thisYear);
const search = ref('');
const rows = ref([]);
const loading = ref(true);
const adjustModal = ref({ open: false, balance: null, employee: null });
const adjustForm = ref({ days: '', reason: '' });
const adjusting = ref(false);
const ledger = ref({ open: false, loading: false, balance: null, employee: null, rows: [] });

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/leave/balances', { params: { year: year.value, search: search.value || undefined } });
        rows.value = data.data;
    } finally { loading.value = false; }
}

async function adjust() {
    adjusting.value = true;
    try {
        await api.post(`/leave/balances/${adjustModal.value.balance.id}/adjust`, adjustForm.value);
        ui.toast('Balance adjusted.');
        adjustModal.value.open = false;
        await load();
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { adjusting.value = false; }
}

async function openLedger(balance, employee) {
    ledger.value = { open: true, loading: true, balance, employee, rows: [] };
    try {
        const { data } = await api.get(`/leave/balances/${balance.id}/transactions`);
        ledger.value.rows = data.data;
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { ledger.value.loading = false; }
}

let timer;
function onSearch() { clearTimeout(timer); timer = setTimeout(load, 300); }

onMounted(load);
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="relative w-full sm:w-64">
                <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input v-model="search" type="search" class="input pl-9" placeholder="Search employees" @input="onSearch" />
            </div>
            <select v-model="year" class="input w-28" @change="load">
                <option v-for="y in [thisYear - 1, thisYear, thisYear + 1]" :key="y" :value="y">{{ y }}</option>
            </select>
            <span class="text-xs text-slate-400">Click a balance to see its ledger.</span>
        </div>

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <div v-else-if="!rows.length" class="card p-10 text-center text-slate-400">No balances for {{ year }}.</div>
        <div v-else class="space-y-3">
            <div v-for="emp in rows" :key="emp.id" class="card p-4">
                <div class="mb-3 flex items-center gap-3">
                    <Avatar :src="emp.photo_url" :name="emp.name" size="sm" />
                    <div>
                        <p class="font-semibold text-slate-900 dark:text-white">{{ emp.name }}</p>
                        <p class="text-xs text-slate-400">{{ emp.employee_number }}<span v-if="emp.department"> · {{ emp.department }}</span></p>
                    </div>
                </div>
                <p v-if="!emp.balances.length" class="text-sm text-slate-400">No balances yet — use Admin → Initialise balances.</p>
                <div class="flex flex-wrap gap-2">
                    <div v-for="b in emp.balances" :key="b.id" class="flex items-center gap-2 rounded-lg border border-slate-100 px-3 py-2 dark:border-slate-800">
                        <button type="button" class="flex items-center gap-2 text-left" @click="openLedger(b, emp)">
                            <span class="size-2 rounded-full" :style="{ background: b.leave_type?.color }" />
                            <span>
                                <span class="block text-xs text-slate-500">{{ b.leave_type?.name }}</span>
                                <span class="block font-semibold text-slate-900 dark:text-white">{{ b.available }}</span>
                                <span class="block text-[11px] text-slate-400">{{ b.taken }} taken<template v-if="b.booked"> · {{ b.booked }} booked</template></span>
                            </span>
                        </button>
                        <button class="ml-1 text-slate-400 hover:text-brand-600" title="Adjust" @click="adjustModal = { open: true, balance: b, employee: emp }; adjustForm = { days: '', reason: '' }">
                            <SlidersHorizontal class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <Modal :open="adjustModal.open" title="Adjust Leave Balance" @close="adjustModal.open = false">
            <p class="mb-4 text-sm text-slate-500">{{ adjustModal.employee?.name }} — {{ adjustModal.balance?.leave_type?.name }} (current: {{ adjustModal.balance?.available }})</p>
            <div class="space-y-4">
                <div>
                    <label class="label">Adjustment (+ to add, − to deduct)</label>
                    <input v-model="adjustForm.days" type="number" step="0.5" class="input" placeholder="e.g. 2 or -1" />
                </div>
                <div>
                    <label class="label">Reason</label>
                    <textarea v-model="adjustForm.reason" rows="2" class="input" />
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="adjustModal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="adjusting || !adjustForm.days || !adjustForm.reason" @click="adjust">{{ adjusting ? 'Saving…' : 'Apply' }}</button>
            </template>
        </Modal>

        <Modal :open="ledger.open" :title="`Ledger — ${ledger.employee?.name ?? ''}`" size="lg" @close="ledger.open = false">
            <p class="mb-3 text-sm text-slate-500">{{ ledger.balance?.leave_type?.name }} {{ year }} · available {{ ledger.balance?.available }}</p>
            <div v-if="ledger.loading" class="flex justify-center py-10"><Spinner /></div>
            <p v-else-if="!ledger.rows.length" class="text-sm text-slate-400">No transactions.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[32rem] text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-500">
                        <tr><th class="py-2 text-left">Date</th><th class="py-2 text-left">Type</th><th class="py-2 text-right">Days</th><th class="py-2 pl-3 text-left">Reason</th><th class="py-2 text-left">By</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="t in ledger.rows" :key="t.id">
                            <td class="py-2 text-slate-500">{{ t.effective_date?.slice(0, 10) }}</td>
                            <td class="py-2">{{ label(t.type) }}</td>
                            <td class="py-2 text-right font-medium" :class="t.days < 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'">{{ t.days > 0 ? '+' : '' }}{{ t.days }}</td>
                            <td class="py-2 pl-3 text-xs text-slate-500">{{ t.reason }}</td>
                            <td class="py-2 text-xs text-slate-400">{{ t.actor?.name ?? 'System' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Modal>
    </div>
</template>
