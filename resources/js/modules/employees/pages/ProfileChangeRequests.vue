<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Check, X, Download, LogOut, FileText } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { formatDate, titleCase } from '@/lib/format';
import { openServerFile } from '@/lib/export';
import { useAuthStore } from '@/stores/auth';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import Tabs from '@/components/ui/Tabs.vue';
import ExitModal from '../components/ExitModal.vue';

/** HR queue for self-service requests: sensitive profile changes, resignations (→ exit) and letter requests. */
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const tab = ref(['profile', 'resignations', 'letters'].includes(route.query.tab) ? route.query.tab : 'profile');
const tabs = ref([
    { key: 'profile', label: 'Profile changes', count: null },
    { key: 'resignations', label: 'Resignations', count: null },
    { key: 'letters', label: 'Letter requests', count: null },
]);
const status = ref('pending');
const rows = ref([]);
const loading = ref(false);
const endpoints = { profile: '/staff-requests/profile-changes', resignations: '/staff-requests/resignations', letters: '/staff-requests/letters' };
const statusColors = { pending: 'yellow', approved: 'green', issued: 'green', processed: 'green', rejected: 'red', declined: 'red', cancelled: 'slate', withdrawn: 'slate' };
const labels = { national_id: 'National ID', kra_pin: 'KRA PIN', nssf_number: 'NSSF No.', shif_number: 'SHIF No.', helb_number: 'HELB No.', next_of_kin: 'Next of kin', payment: 'Pay details' };

const reject = ref({ open: false, row: null });
const issue = ref({ open: false, row: null, effective_date: '', notes: '' });
const exit = ref({ open: false, employee: null, prefill: null });
const busy = ref(false);

async function load() {
    loading.value = true;
    try {
        const [list, summary] = await Promise.all([api.get(endpoints[tab.value], { params: { status: status.value, per_page: 100 } }), api.get('/staff-requests/summary')]);
        rows.value = list.data.data ?? [];
        tabs.value.forEach((t) => { t.count = summary.data.data?.[t.key] || null; });
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}
function switchTab(t) { tab.value = t; router.replace({ query: { tab: t } }); load(); }

function describe(key, value) {
    if (value == null) return '—';
    if (key === 'next_of_kin') return value.map((r) => `${r.name} (${r.relationship}, ${r.phone})`).join('; ') || '—';
    if (key === 'payment') return value.payment_method === 'mpesa' ? `M-Pesa ${value.mpesa_phone}` : [value.bank_name, value.bank_branch, value.account_name, value.account_number].filter(Boolean).join(' · ');
    return value;
}

async function approveChange(row) {
    if (!await swal.confirm({ title: `Approve ${row.employee?.name}'s change?`, text: `${row.summary} will be applied to the record.`, confirmLabel: 'Approve', danger: false })) return;
    try { const { data } = await api.post(`/staff-requests/profile-changes/${row.id}/approve`); swal.toast(data.message); await load(); }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
}

async function doReject(reason) {
    const row = reject.value.row;
    const url = tab.value === 'profile' ? `/staff-requests/profile-changes/${row.id}/reject` : tab.value === 'letters' ? `/staff-requests/letters/${row.id}/reject` : `/staff-requests/resignations/${row.id}/reject`;
    try { const { data } = await api.post(url, { reason }); swal.toast(data.message); reject.value.open = false; await load(); }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
}

async function doIssue() {
    busy.value = true;
    try {
        const { data } = await api.post(`/staff-requests/letters/${issue.value.row.id}/approve`, { effective_date: issue.value.effective_date || null, notes: issue.value.notes || null });
        swal.toast(data.message); issue.value.open = false;
        if (data.url) window.open(data.url, '_blank');
        await load();
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { busy.value = false; }
}

function processExit(row) {
    exit.value = {
        open: true,
        employee: { id: row.employee.id, full_name: row.employee.name, name: row.employee.name, photo_url: row.employee.photo_url },
        prefill: { exit_date: row.last_working_day, notice_date: row.notice_date, reason: 'resignation', comments: row.reason },
    };
}

const isMe = (row) => row.employee?.id && row.employee.id === auth.user?.employee?.id;
onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Staff requests" subtitle="Profile changes, resignations and letters requested from My Portal" />
        <div class="flex flex-wrap items-start justify-between gap-2">
            <Tabs class="min-w-0 flex-1" :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />
            <select v-model="status" class="input mb-4 w-36" @change="load">
                <option value="pending">Pending</option><option value="all">All</option>
                <option v-for="s in (tab === 'profile' ? ['approved', 'rejected', 'cancelled'] : tab === 'letters' ? ['issued', 'declined', 'cancelled'] : ['processed', 'declined', 'withdrawn'])" :key="s" :value="s">{{ titleCase(s) }}</option>
            </select>
        </div>

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <div v-else-if="!rows.length" class="card p-10 text-center text-slate-400">Nothing {{ status === 'all' ? '' : status }} here.</div>
        <div v-else class="space-y-3">
            <div v-for="r in rows" :key="r.id" class="card p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <div class="flex min-w-0 flex-1 gap-3">
                        <Avatar :src="r.employee?.photo_url" :name="r.employee?.name" size="md" />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <router-link v-if="r.employee" :to="`/employees/${r.employee.id}`" class="font-semibold text-slate-900 hover:underline dark:text-white">{{ r.employee.name }}</router-link>
                                <span class="text-xs text-slate-400">{{ r.employee?.employee_number }}</span>
                                <Badge :color="statusColors[r.status] ?? 'slate'">{{ titleCase(r.status) }}</Badge>
                            </div>
                            <p class="text-xs text-slate-500">Requested {{ formatDate(r.created_at) }}<span v-if="r.decided_at"> · decided {{ formatDate(r.decided_at) }}</span></p>

                            <!-- profile change: old → new -->
                            <div v-if="tab === 'profile'" class="mt-2 overflow-x-auto">
                                <table class="w-full min-w-[420px] text-sm">
                                    <thead class="text-left text-xs uppercase text-slate-400"><tr><th class="py-1 pr-3">Field</th><th class="py-1 pr-3">On file</th><th class="py-1">Requested</th></tr></thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                        <tr v-for="(v, k) in r.changes" :key="k" class="align-top">
                                            <td class="py-1.5 pr-3 font-medium">{{ labels[k] ?? k }}</td>
                                            <td class="py-1.5 pr-3 text-slate-500">{{ describe(k, r.previous?.[k]) }}</td>
                                            <td class="py-1.5 text-slate-900 dark:text-white">{{ describe(k, v) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p v-if="r.note" class="mt-1 text-xs text-slate-500">Note: {{ r.note }}</p>
                            </div>

                            <div v-else-if="tab === 'resignations'" class="mt-2 text-sm">
                                <p>Last working day <strong>{{ formatDate(r.last_working_day) }}</strong> · notice {{ formatDate(r.notice_date) }}</p>
                                <p class="mt-1 whitespace-pre-line text-slate-600 dark:text-slate-300">{{ r.reason }}</p>
                                <p v-if="r.exit" class="mt-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">Exit {{ r.exit.status === 'completed' ? 'completed' : 'scheduled' }} for {{ formatDate(r.exit.exit_date) }}</p>
                                <p v-else-if="r.has_exit" class="mt-1 text-xs font-medium text-amber-700 dark:text-amber-400">An exit is already recorded for this employee. Check Lifecycle › Exits.</p>
                                <button v-if="r.letter_path" class="btn-ghost mt-1 !py-1 text-xs" @click="openServerFile(r.letter_path)"><Download class="size-3.5" /> {{ r.letter_name }}</button>
                            </div>

                            <div v-else class="mt-2 text-sm">
                                <p class="font-medium">{{ r.template_name }}<span v-if="r.addressed_to" class="font-normal text-slate-500"> · to {{ r.addressed_to }}</span></p>
                                <p class="mt-1 text-slate-600 dark:text-slate-300">{{ r.purpose }}</p>
                                <button v-if="r.letter" class="btn-ghost mt-1 !py-1 text-xs" @click="openServerFile(r.letter.path)"><FileText class="size-3.5" /> {{ r.letter.reference }}</button>
                            </div>
                            <p v-if="r.decision_note" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ r.decision_note }}</p>
                        </div>
                    </div>

                    <div v-if="r.status === 'pending' && !isMe(r)" class="flex flex-wrap gap-2">
                        <button v-if="tab === 'profile'" class="btn-primary !py-1.5" @click="approveChange(r)"><Check class="size-4" /> Approve</button>
                        <button v-if="tab === 'letters'" class="btn-primary !py-1.5" @click="issue = { open: true, row: r, effective_date: '', notes: r.template === 'no_objection' ? r.purpose : '' }"><FileText class="size-4" /> Generate letter</button>
                        <button v-if="tab === 'resignations' && auth.can('employees.exit') && !r.has_exit" class="btn-primary !py-1.5" @click="processExit(r)"><LogOut class="size-4" /> Process exit</button>
                        <button class="btn-secondary !py-1.5" @click="reject = { open: true, row: r }"><X class="size-4" /> {{ tab === 'profile' ? 'Reject' : tab === 'letters' ? 'Decline' : 'Return' }}</button>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmDialog v-model:open="reject.open" title="Give a reason" message="The employee will see this reason." confirm-label="Send" danger reason-label="Reason" @confirm="doReject" />

        <Modal :open="issue.open" title="Generate letter" @close="issue.open = false">
            <div class="space-y-4">
                <p class="text-sm text-slate-500">{{ issue.row?.template_name }} for {{ issue.row?.employee?.name }}. The letter is filed under the employee's letters and they are notified.</p>
                <div><label class="label">Effective date (optional)</label><input v-model="issue.effective_date" type="date" class="input" /></div>
                <div><label class="label">Additional text {{ issue.row?.template === 'no_objection' ? '(the request)' : '(optional)' }}</label><textarea v-model="issue.notes" rows="4" class="input" /></div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="issue.open = false">Cancel</button>
                <button class="btn-primary" :disabled="busy" @click="doIssue">{{ busy ? 'Generating…' : 'Generate & issue' }}</button>
            </template>
        </Modal>

        <ExitModal :open="exit.open" :employee="exit.employee" :prefill="exit.prefill" @close="exit.open = false" @done="exit.open = false; load()" />
    </div>
</template>
