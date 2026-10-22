<script setup>
import { ref, computed, onMounted } from 'vue';
import { Download, RefreshCw } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { label } from '@/lib/format';
import { useUiStore } from '@/stores/ui';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';

const ui = useUiStore();
const tab = ref('reports');
const tabs = [
    { key: 'reports', label: 'Payroll Reports' },
    { key: 'variance', label: 'Variance Analysis' },
    { key: 'bank', label: 'Bank & M-Pesa Files' },
    { key: 'returns', label: 'Statutory Returns' },
];

const money = (v) => (v === null || v === undefined || v === '') ? '—' : Number(v).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const rows = ref([]);
const table = ref(null); // server table: { title, columns: [{key,label,money}], rows, totals }
const loading = ref(false);
const runs = ref([]);

// Columns come from the server so screen and CSV always match.
const tableCols = computed(() => (table.value?.columns ?? []).map((c) => ({
    key: c.key, label: c.label, align: c.money ? 'right' : undefined, format: c.money ? money : undefined, sortable: c.key === 'employee_name',
})));

// reports (run reports via /runs/{id}/export/{type}?format=json)
const reportFilters = ref({ run_id: '', type: 'register' });
const reportTypes = [
    { value: 'register', label: 'Payroll register' },
    { value: 'department', label: 'Summary by department' },
    { value: 'branch', label: 'Summary by branch' },
    { value: 'components', label: 'Component breakdown' },
    { value: 'employer_cost', label: 'Employer cost' },
    { value: 'journal', label: 'GL journal' },
];

// variance
const varPrev = ref('');
const varCurr = ref('');
const variance = ref(null);
const varianceCols = [
    { key: 'employee_name', label: 'Employee', sortable: true },
    { key: 'prev_gross', label: 'Prev Gross', align: 'right', format: money },
    { key: 'curr_gross', label: 'Curr Gross', align: 'right', format: money },
    { key: 'gross_variance', label: 'Gross Var', align: 'right' },
    { key: 'prev_net', label: 'Prev Net', align: 'right', format: money },
    { key: 'curr_net', label: 'Curr Net', align: 'right', format: money },
    { key: 'variance_pct', label: 'Var %', align: 'right' },
    { key: 'status', label: 'Status' },
];
const varianceStatusColors = { ok: 'slate', flagged: 'yellow', new: 'blue', dropped: 'red' };

// bank files
const bankStatusColors = { generated: 'blue', downloaded: 'green', sent: 'green', failed: 'red' };
const bankCols = [
    { key: 'run_title', label: 'Payroll Run' },
    { key: 'type_label', label: 'Type' },
    { key: 'bank_name', label: 'Bank / Provider' },
    { key: 'records', label: 'Records', align: 'right' },
    { key: 'total_amount', label: 'Total (KES)', align: 'right', format: money },
    { key: 'status', label: 'Status' },
    { key: 'generated_at', label: 'Generated' },
];

// returns
const thisYear = new Date().getFullYear();
const returnYear = ref(thisYear);
const returnType = ref('p9a');
const returnTypes = [
    { v: 'p9a', l: 'P9A' }, { v: 'p10', l: 'P10' }, { v: 'nssf', l: 'NSSF' }, { v: 'shif', l: 'SHIF' }, { v: 'ahl', l: 'Housing Levy' }, { v: 'helb', l: 'HELB' },
];

const lockedRuns = computed(() => runs.value.filter((r) => r.status !== 'draft'));

async function loadRuns() {
    try {
        const { data } = await api.get('/payroll/runs', { params: { per_page: 36 } });
        runs.value = data.data ?? [];
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    if (lockedRuns.value.length && !reportFilters.value.run_id) reportFilters.value.run_id = lockedRuns.value[0].id;
    const regular = lockedRuns.value.filter((r) => r.type === 'regular');
    if (regular.length && !varCurr.value) { varCurr.value = regular[0].id; varPrev.value = regular[1]?.id ?? ''; }
}

async function load() {
    loading.value = true;
    rows.value = [];
    table.value = null;
    try {
        if (tab.value === 'reports' && reportFilters.value.run_id) {
            const { data } = await api.get(`/payroll/runs/${reportFilters.value.run_id}/export/${reportFilters.value.type}`, { params: { format: 'json' } });
            table.value = data;
            rows.value = data.rows ?? [];
        } else if (tab.value === 'variance' && varCurr.value) {
            const { data } = await api.get('/payroll/variance', { params: { current: varCurr.value, previous: varPrev.value || undefined } });
            variance.value = data;
            rows.value = data.data ?? [];
        } else if (tab.value === 'bank') {
            const { data } = await api.get('/payroll/payment-files');
            rows.value = data.data ?? [];
        } else if (tab.value === 'returns') {
            const { data } = await api.get('/payroll/returns', { params: { year: returnYear.value, type: returnType.value } });
            table.value = data;
            rows.value = data.rows ?? data.data ?? [];
        }
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}

function switchTab(t) { tab.value = t; load(); }

function save(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url; a.download = filename; a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

async function exportCurrent() {
    try {
        if (tab.value === 'reports') {
            const { data } = await api.get(`/payroll/runs/${reportFilters.value.run_id}/export/${reportFilters.value.type}`, { responseType: 'blob' });
            save(data, table.value?.filename ?? `payroll-${reportFilters.value.type}.csv`);
        } else if (tab.value === 'variance') {
            const { data } = await api.get('/payroll/variance/export', { params: { current: varCurr.value, previous: varPrev.value || undefined }, responseType: 'blob' });
            save(data, 'variance-analysis.csv');
        } else if (tab.value === 'returns') {
            const { data } = await api.get('/payroll/returns/export', { params: { year: returnYear.value, type: returnType.value }, responseType: 'blob' });
            save(data, table.value?.filename ?? `${returnType.value}-${returnYear.value}.csv`);
        }
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
}

async function downloadBankFile(row) {
    try {
        const { data } = await api.get(`/payroll/payment-files/${row.id}/download`, { responseType: 'blob' });
        save(data, row.filename ?? `payment_file_${row.id}.csv`);
        load();
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
}

onMounted(async () => { await loadRuns(); load(); });
</script>

<template>
    <div>
        <PageHeader title="Payroll Reports & Outputs">
            <template #actions>
                <button v-if="tab !== 'bank'" class="btn-secondary" :disabled="!rows.length" @click="exportCurrent">
                    <Download class="size-4" /> Export CSV
                </button>
                <button v-else class="btn-ghost !p-1.5" title="Refresh" @click="load"><RefreshCw class="size-4" /></button>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <!-- Reports filters -->
        <div v-if="tab === 'reports'" class="mb-4 flex flex-wrap gap-3">
            <select v-model="reportFilters.run_id" class="input w-full sm:w-64" @change="load">
                <option value="">— Select run —</option>
                <option v-for="r in lockedRuns" :key="r.id" :value="r.id">{{ r.title }} ({{ label(r.status) }})</option>
            </select>
            <select v-model="reportFilters.type" class="input w-full sm:w-56" @change="load">
                <option v-for="t in reportTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
        </div>

        <!-- Variance filters -->
        <div v-if="tab === 'variance'" class="mb-4 flex flex-wrap items-end gap-4">
            <div class="w-full sm:w-auto">
                <label class="label">Previous run</label>
                <select v-model="varPrev" class="input sm:w-56">
                    <option value="">Automatic (previous regular run)</option>
                    <option v-for="r in lockedRuns" :key="r.id" :value="r.id">{{ r.title }}</option>
                </select>
            </div>
            <div class="w-full sm:w-auto">
                <label class="label">Current run</label>
                <select v-model="varCurr" class="input sm:w-56">
                    <option value="">— Select —</option>
                    <option v-for="r in lockedRuns" :key="r.id" :value="r.id">{{ r.title }}</option>
                </select>
            </div>
            <button class="btn-primary" :disabled="!varCurr" @click="load">Compare</button>
        </div>

        <!-- Returns filters -->
        <div v-if="tab === 'returns'" class="mb-4 flex flex-wrap gap-3">
            <select v-model="returnYear" class="input w-28" @change="load">
                <option v-for="y in [thisYear - 2, thisYear - 1, thisYear]" :key="y" :value="y">{{ y }}</option>
            </select>
            <div class="flex flex-wrap rounded-lg border border-slate-200 dark:border-slate-700">
                <button v-for="t in returnTypes" :key="t.v"
                    class="px-3 py-1.5 text-sm font-medium transition"
                    :class="returnType === t.v ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-400 dark:hover:bg-slate-800'"
                    @click="returnType = t.v; load()">{{ t.l }}</button>
            </div>
        </div>

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>

        <template v-else-if="tab === 'reports' || tab === 'returns'">
            <p v-if="tab === 'reports' && !lockedRuns.length" class="py-10 text-center text-sm text-slate-400">No calculated payroll runs yet.</p>
            <template v-else>
                <DataTable :columns="tableCols" :rows="rows" :loading="false" :export-name="tab === 'reports' ? `payroll_${reportFilters.type}` : `${returnType}_${returnYear}`" />
                <div v-if="table?.totals && Object.keys(table.totals).length && rows.length" class="card mt-3 flex flex-wrap gap-x-6 gap-y-1 p-3 text-xs text-slate-600 dark:text-slate-300">
                    <span v-for="c in table.columns.filter(c => c.money)" :key="c.key">{{ c.label }}: <strong class="tabular-nums">{{ money(table.totals[c.key]) }}</strong></span>
                </div>
            </template>
        </template>

        <template v-else-if="tab === 'variance'">
            <div v-if="variance?.summary" class="mb-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="card p-4"><p class="text-xs text-slate-500">Gross (prev → curr)</p><p class="text-sm font-bold text-slate-900 dark:text-white">{{ money(variance.summary.prev_gross) }} → {{ money(variance.summary.curr_gross) }}</p></div>
                <div class="card p-4"><p class="text-xs text-slate-500">Net (prev → curr)</p><p class="text-sm font-bold text-slate-900 dark:text-white">{{ money(variance.summary.prev_net) }} → {{ money(variance.summary.curr_net) }}</p></div>
                <div class="card p-4"><p class="text-xs text-slate-500">Headcount</p><p class="text-sm font-bold text-slate-900 dark:text-white">{{ variance.summary.prev_headcount }} → {{ variance.summary.curr_headcount }}</p></div>
                <div class="card p-4"><p class="text-xs text-slate-500">Flagged (&gt; {{ variance.threshold }}%)</p><p class="text-sm font-bold text-amber-600 dark:text-amber-400">{{ variance.summary.flagged }}</p></div>
            </div>
            <p v-if="variance && !variance.previous" class="mb-3 text-sm text-slate-500">No previous run to compare with: every line shows as new.</p>
            <DataTable :columns="varianceCols" :rows="rows" :loading="false" export-name="variance">
                <template #cell-gross_variance="{ row }">
                    <span :class="row.gross_variance > 0 ? 'text-green-600 dark:text-green-400' : row.gross_variance < 0 ? 'text-red-500' : ''">{{ money(row.gross_variance) }}</span>
                </template>
                <template #cell-variance_pct="{ row }">
                    <span :class="row.status === 'flagged' ? 'font-semibold text-amber-600 dark:text-amber-400' : ''">{{ row.variance_pct ?? '—' }}{{ row.variance_pct != null ? '%' : '' }}</span>
                </template>
                <template #cell-status="{ row }">
                    <Badge :color="varianceStatusColors[row.status] ?? 'slate'">{{ label(row.status) }}</Badge>
                </template>
            </DataTable>
        </template>

        <template v-else-if="tab === 'bank'">
            <p class="mb-3 text-sm text-slate-500">One bank and one M-Pesa file is prepared for every approved run. Downloads are logged.</p>
            <DataTable :columns="bankCols" :rows="rows" :loading="false">
                <template #cell-status="{ row }">
                    <Badge :color="bankStatusColors[row.status] ?? 'slate'">{{ label(row.status) }}</Badge>
                    <span v-if="row.download_count" class="block text-xs text-slate-400">{{ row.download_count }} download(s)</span>
                </template>
                <template #actions="{ row }">
                    <button class="btn-ghost !p-1.5" title="Download" @click="downloadBankFile(row)"><Download class="size-4" /></button>
                </template>
            </DataTable>
        </template>
    </div>
</template>
