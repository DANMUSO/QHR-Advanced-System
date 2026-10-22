<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Search, ShieldCheck, Eye, FileSearch, ShieldAlert } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { formatDate, formatDateTime, titleCase } from '@/lib/format';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';

const route = useRoute();
const router = useRouter();

const tab = ref(['kyc', 'files', 'expiry'].includes(route.query.tab) ? route.query.tab : 'kyc');
const tabs = [
    { key: 'kyc', label: 'KYC & background', icon: ShieldCheck },
    { key: 'files', label: 'Employee files', icon: FileSearch },
    { key: 'expiry', label: 'Document expiry', icon: ShieldAlert },
];

const rows = ref([]);
const paginator = ref(null);
const loading = ref(false);
const search = ref('');
const kycFilter = ref('');
const expiryDays = ref(30);
const page = ref(1);

const TONE = { verified: 'green', pending: 'amber', failed: 'red', not_submitted: 'slate' };
const label = (s) => (s ? titleCase(String(s).replace(/_/g, ' ')) : 'Not submitted');

const columns = computed(() => ({
    kyc: [
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'national_id', label: 'National ID' },
        { key: 'kra_pin', label: 'KRA PIN' },
        { key: 'kyc_status', label: 'Identity', format: label },
        { key: 'background_check', label: 'Background', format: label },
        { key: 'last_verified', label: 'Last verified', format: (v) => (v ? formatDate(v) : '—') },
    ],
    files: [
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'employee_number', label: 'Emp No.' },
        { key: 'department', label: 'Department' },
        { key: 'documents_count', label: 'Documents', align: 'right' },
        { key: 'last_updated', label: 'Last updated' },
    ],
    expiry: [
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'document_type', label: 'Document type' },
        { key: 'document_number', label: 'Document #' },
        { key: 'expiry_date', label: 'Expiry date' },
        { key: 'days_remaining', label: 'Days left', align: 'right' },
    ],
}[tab.value]));

async function load() {
    loading.value = true;
    try {
        let res;
        if (tab.value === 'kyc') res = await api.get('/employees/kyc', { params: { search: search.value || undefined, status: kycFilter.value || undefined, page: page.value } });
        else if (tab.value === 'files') res = await api.get('/documents/employees', { params: { search: search.value || undefined, page: page.value } });
        else res = await api.get('/documents/expiry', { params: { days: expiryDays.value } });
        const d = res.data;
        rows.value = d.data ?? d;
        paginator.value = d.current_page ? { current_page: d.current_page, last_page: d.last_page, total: d.total, from: d.from, to: d.to } : null;
    } catch (e) {
        rows.value = [];
        swal.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

function switchTab(t) {
    tab.value = t;
    search.value = '';
    page.value = 1;
    rows.value = [];
    router.replace({ query: { ...route.query, tab: t } });
    load();
}

let timer;
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => { page.value = 1; load(); }, 300); });
watch([kycFilter, expiryDays], () => { page.value = 1; load(); });

// ── KYC detail / verification ──────────────────────────────────────────────
const detail = reactive({ open: false, row: null, history: [], loading: false, verifying: false, notes: '' });
const bg = reactive({ status: 'verified', reference: '', notes: '', saving: false, errors: {} });

async function openDetail(row) {
    Object.assign(detail, { open: true, row, history: [], loading: true, verifying: false, notes: '' });
    Object.assign(bg, { status: 'verified', reference: row.background_reference ?? '', notes: '', saving: false, errors: {} });
    try {
        detail.history = (await api.get(`/employees/kyc/${row.id}`)).data.data ?? [];
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
    } finally {
        detail.loading = false;
    }
}

async function refreshRow() {
    await load();
    const fresh = rows.value.find((r) => r.id === detail.row?.id);
    if (fresh) detail.row = fresh;
    detail.history = (await api.get(`/employees/kyc/${detail.row.id}`)).data.data ?? [];
}

async function verifyIdentity(row = detail.row) {
    detail.verifying = true;
    try {
        const { data } = await api.post(`/employees/${row.id}/kyc/verify`, { type: 'identity', notes: detail.notes || null });
        swal.toast(data.message);
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
    } finally {
        detail.verifying = false;
        if (detail.open) await refreshRow(); else load();
    }
}

async function recordBackground() {
    bg.saving = true;
    bg.errors = {};
    try {
        const { data } = await api.post(`/employees/${detail.row.id}/kyc/verify`, { type: 'background', status: bg.status, reference: bg.reference || null, notes: bg.notes || null });
        swal.toast(data.message);
        await refreshRow();
    } catch (e) {
        bg.errors = fieldErrors(e);
        if (!Object.keys(bg.errors).length) swal.toast(errorMessage(e), 'error');
    } finally {
        bg.saving = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Records & KYC" subtitle="Identity verification, background checks and employee document files." back="/employees" />

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <DataTable
            :columns="columns"
            :rows="rows"
            :loading="loading"
            :paginator="paginator"
            :export-name="`records-${tab}`"
            :clickable="tab !== 'expiry'"
            empty-title="Nothing to show"
            empty-description="No records match the current filters."
            @row-click="(r) => (tab === 'kyc' ? openDetail(r) : router.push(`/employees/${r.employee_id ?? r.id}`))"
            @page="(p) => { page = p; load(); }"
        >
            <template #toolbar>
                <div v-if="tab !== 'expiry'" class="relative w-full sm:w-72">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input v-model="search" class="input !py-2 !pl-9" placeholder="Search employees…" />
                </div>
                <select v-if="tab === 'kyc'" v-model="kycFilter" class="input !w-44 !py-2">
                    <option value="">Any identity status</option>
                    <option value="verified">Verified</option><option value="failed">Failed</option>
                    <option value="pending">Pending</option><option value="not_submitted">Not submitted</option>
                </select>
                <select v-if="tab === 'expiry'" v-model="expiryDays" class="input !w-44 !py-2">
                    <option v-for="d in [7, 14, 30, 60, 90]" :key="d" :value="d">Within {{ d }} days</option>
                </select>
            </template>
            <template #cell-employee_name="{ row }">
                <div class="flex items-center gap-3">
                    <Avatar :src="row.photo_url" :name="row.employee_name" size="sm" />
                    <div class="min-w-0">
                        <p class="truncate font-medium text-slate-900 dark:text-white">{{ row.employee_name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ row.employee_number }}<span v-if="row.department"> · {{ row.department }}</span></p>
                    </div>
                </div>
            </template>
            <template #cell-kyc_status="{ row }">
                <Badge :tone="TONE[row.kyc_status] ?? 'slate'">{{ label(row.kyc_status) }}</Badge>
                <p v-if="row.issues?.length" class="mt-0.5 text-xs text-rose-500">{{ row.issues.length }} issue(s)</p>
            </template>
            <template #cell-background_check="{ row }"><Badge :tone="TONE[row.background_check] ?? 'slate'">{{ label(row.background_check) }}</Badge></template>
            <template #cell-days_remaining="{ row }">
                <span :class="row.days_remaining <= 7 ? 'font-bold text-rose-500' : row.days_remaining <= 30 ? 'font-semibold text-amber-500' : ''">{{ row.days_remaining }}</span>
            </template>
            <template v-if="tab === 'kyc'" #actions="{ row }">
                <button class="btn-ghost !p-1.5" title="View checks" @click.stop="openDetail(row)"><Eye class="size-4" /></button>
                <button v-if="row.kyc_status !== 'verified'" class="btn-ghost !p-1.5" title="Run identity verification" @click.stop="verifyIdentity(row)"><ShieldCheck class="size-4 text-emerald-500" /></button>
            </template>
        </DataTable>

        <Modal :open="detail.open" :title="detail.row ? `KYC · ${detail.row.employee_name}` : 'KYC'" size="lg" @close="detail.open = false">
            <template v-if="detail.row">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Identity</p>
                        <Badge class="mt-1" :tone="TONE[detail.row.kyc_status] ?? 'slate'">{{ label(detail.row.kyc_status) }}</Badge>
                        <dl class="mt-3 space-y-1 text-sm">
                            <div class="flex justify-between gap-2"><dt class="text-slate-500">National ID</dt><dd class="text-slate-800 dark:text-slate-200">{{ detail.row.national_id || '—' }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-slate-500">KRA PIN</dt><dd class="text-slate-800 dark:text-slate-200">{{ detail.row.kra_pin || '—' }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-slate-500">NSSF</dt><dd class="text-slate-800 dark:text-slate-200">{{ detail.row.nssf_number || '—' }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-slate-500">SHIF</dt><dd class="text-slate-800 dark:text-slate-200">{{ detail.row.shif_number || '—' }}</dd></div>
                        </dl>
                        <ul v-if="detail.row.issues?.length" class="mt-2 list-disc pl-5 text-xs text-rose-500"><li v-for="i in detail.row.issues" :key="i">{{ i }}</li></ul>
                        <input v-model="detail.notes" class="input mt-3 !py-1.5 text-sm" placeholder="Notes (optional)" />
                        <button class="btn-primary mt-2 w-full justify-center" :disabled="detail.verifying" @click="verifyIdentity()">
                            <Spinner v-if="detail.verifying" /><ShieldCheck v-else class="size-4" /> Run identity verification
                        </button>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Background check</p>
                        <Badge class="mt-1" :tone="TONE[detail.row.background_check] ?? 'slate'">{{ label(detail.row.background_check) }}</Badge>
                        <div class="mt-3 space-y-2">
                            <select v-model="bg.status" class="input !py-1.5 text-sm">
                                <option value="verified">Verified / clear</option><option value="pending">Pending</option><option value="failed">Failed</option>
                            </select>
                            <input v-model="bg.reference" class="input !py-1.5 text-sm" placeholder="Reference (e.g. certificate of good conduct no.)" />
                            <p v-if="bg.errors.reference" class="field-error">{{ bg.errors.reference }}</p>
                            <textarea v-model="bg.notes" rows="2" class="input text-sm" placeholder="Notes" />
                            <button class="btn-secondary w-full justify-center" :disabled="bg.saving" @click="recordBackground"><Spinner v-if="bg.saving" /> Record outcome</button>
                        </div>
                    </div>
                </div>

                <h4 class="mb-2 mt-5 text-sm font-semibold text-slate-900 dark:text-white">Check history</h4>
                <div v-if="detail.loading" class="flex justify-center py-6"><Spinner /></div>
                <p v-else-if="!detail.history.length" class="text-sm text-slate-400">No checks recorded yet.</p>
                <ul v-else class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    <li v-for="h in detail.history" :key="h.id" class="flex flex-wrap items-center gap-2 py-2">
                        <Badge :tone="TONE[h.status] ?? 'slate'">{{ label(h.status) }}</Badge>
                        <span class="text-slate-700 dark:text-slate-300">{{ titleCase(h.check_type) }}</span>
                        <span v-if="h.reference" class="text-xs text-slate-500">#{{ h.reference }}</span>
                        <span class="ml-auto text-xs text-slate-500">{{ h.verified_by ?? 'System' }} · {{ h.verified_at ? formatDateTime(h.verified_at) : '—' }}</span>
                        <p v-if="h.issues?.length" class="w-full text-xs text-rose-500">{{ h.issues.join('; ') }}</p>
                        <p v-if="h.notes" class="w-full text-xs text-slate-500">{{ h.notes }}</p>
                    </li>
                </ul>
            </template>
            <template #footer>
                <RouterLink v-if="detail.row" :to="`/employees/${detail.row.id}`" class="btn-ghost">Open profile</RouterLink>
                <button class="btn-secondary" @click="detail.open = false">Close</button>
            </template>
        </Modal>
    </div>
</template>
