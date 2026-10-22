<script setup>
/** Leave administration: scheduled-job history, bulk balance upload, initialise balances, ledger reconciliation. */
import { ref, onMounted } from 'vue';
import { Upload, PlayCircle, Scale } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import Badge from '@/components/ui/Badge.vue';
import Spinner from '@/components/ui/Spinner.vue';

const props = defineProps({ section: { type: String, default: 'runs' } });
const ui = useUiStore();
const thisYear = new Date().getFullYear();
const years = [thisYear - 1, thisYear, thisYear + 1];

const runs = ref([]);
const runsLoading = ref(true);
const jobLabels = { accrual: 'Monthly accrual', carry_forward: 'Year-end carry-forward', reminders: 'Approval reminders' };

const upload = ref({ year: thisYear, mode: 'adjust', reason: '', file: null });
const uploading = ref(false);
const uploadResult = ref(null);

const initYear = ref(thisYear);
const initialising = ref(false);

const recYear = ref(thisYear);
const reconciling = ref(false);
const recResult = ref(null);

async function loadRuns() {
    runsLoading.value = true;
    try { runs.value = (await api.get('/leave/runs')).data.data; }
    finally { runsLoading.value = false; }
}

async function doUpload() {
    uploading.value = true; uploadResult.value = null;
    try {
        const fd = new FormData();
        Object.entries(upload.value).forEach(([k, v]) => v !== null && v !== '' && fd.append(k, v));
        const { data } = await api.post('/leave/balances/upload', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
        uploadResult.value = data;
        ui.toast(data.message, data.errors?.length ? 'warning' : 'success');
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { uploading.value = false; }
}

async function initialise() {
    initialising.value = true;
    try {
        const { data } = await api.post('/leave/balances/initialise', { year: initYear.value });
        ui.toast(data.message);
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { initialising.value = false; }
}

async function reconcile(apply) {
    reconciling.value = true;
    try {
        const { data } = await api.post('/leave/balances/reconcile', { year: recYear.value, apply });
        recResult.value = data;
        ui.toast(data.message);
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { reconciling.value = false; }
}

function summary(run) {
    const s = run.summary ?? {};
    if (run.job === 'accrual') return `${s.employees ?? run.records} employees · ${s.days ?? 0} days`;
    if (run.job === 'carry_forward') return `${s.employees ?? run.records} balances · ${s.carried ?? 0} carried · ${s.forfeited ?? 0} forfeited`;
    return `${run.records} sent`;
}

onMounted(loadRuns);
</script>

<template>
    <div class="grid gap-6 xl:grid-cols-2">
        <!-- Job history -->
        <section id="runs" class="card p-5 xl:col-span-2" :class="props.section === 'runs' && 'ring-1 ring-brand-300 dark:ring-brand-500/40'">
            <h3 class="mb-3 font-semibold text-slate-900 dark:text-white">Accrual &amp; carry-forward runs</h3>
            <div v-if="runsLoading" class="flex justify-center py-8"><Spinner /></div>
            <p v-else-if="!runs.length" class="text-sm text-slate-400">No scheduled jobs have run yet.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[34rem] text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-500">
                        <tr><th class="py-2 text-left">Job</th><th class="py-2 text-left">Period</th><th class="py-2 text-left">Result</th><th class="py-2 text-left">Ran</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="r in runs" :key="r.id">
                            <td class="py-2"><Badge :color="r.job === 'accrual' ? 'blue' : r.job === 'carry_forward' ? 'purple' : 'slate'">{{ jobLabels[r.job] ?? r.job }}</Badge></td>
                            <td class="py-2 font-medium">{{ r.period }}</td>
                            <td class="py-2 text-slate-600 dark:text-slate-400">{{ summary(r) }}</td>
                            <td class="py-2 text-xs text-slate-400">{{ r.ran_at?.slice(0, 16).replace('T', ' ') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Bulk upload -->
        <section class="card space-y-4 p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">Upload balances (CSV)</h3>
            <p class="text-xs text-slate-500">Columns: <code>employee_number, leave type code, days</code> (e.g. <code>EMP001, ANNUAL, 12</code>). A header row is optional.</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label">Year</label><select v-model="upload.year" class="input"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select></div>
                <div>
                    <label class="label">Mode</label>
                    <select v-model="upload.mode" class="input">
                        <option value="adjust">Add the days (adjust)</option>
                        <option value="set">Set the available balance</option>
                    </select>
                </div>
            </div>
            <div><label class="label">Reason</label><input v-model="upload.reason" class="input" placeholder="e.g. Migration from old system" /></div>
            <input type="file" accept=".csv,text/csv" class="input" @change="upload.file = $event.target.files[0]" />
            <button class="btn-primary" :disabled="uploading || !upload.file" @click="doUpload"><Upload class="size-4" /> {{ uploading ? 'Uploading…' : 'Upload' }}</button>
            <div v-if="uploadResult?.errors?.length" class="max-h-40 overflow-y-auto rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                <p v-for="(err, i) in uploadResult.errors" :key="i">{{ err }}</p>
            </div>
        </section>

        <div class="space-y-6">
            <!-- Initialise -->
            <section class="card space-y-3 p-5">
                <h3 class="font-semibold text-slate-900 dark:text-white">Initialise balances</h3>
                <p class="text-xs text-slate-500">Creates missing balances (opening entitlement and accruals due) for every active employee.</p>
                <div class="flex flex-wrap items-center gap-3">
                    <select v-model="initYear" class="input w-28"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                    <button class="btn-primary" :disabled="initialising" @click="initialise"><PlayCircle class="size-4" /> {{ initialising ? 'Working…' : 'Initialise' }}</button>
                </div>
            </section>

            <!-- Reconcile -->
            <section class="card space-y-3 p-5">
                <h3 class="font-semibold text-slate-900 dark:text-white">Reconcile with the ledger</h3>
                <p class="text-xs text-slate-500">Recomputes every balance from its transactions. Preview first; applying rewrites only rows that differ.</p>
                <div class="flex flex-wrap items-center gap-3">
                    <select v-model="recYear" class="input w-28"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                    <button class="btn-secondary" :disabled="reconciling" @click="reconcile(false)"><Scale class="size-4" /> Preview</button>
                    <button class="btn-primary" :disabled="reconciling || !recResult?.data?.length || recResult?.applied" @click="reconcile(true)">Apply fixes</button>
                </div>
                <div v-if="recResult" class="text-sm">
                    <p class="text-slate-600 dark:text-slate-400">{{ recResult.message }}</p>
                    <ul v-if="recResult.data.length" class="mt-2 max-h-56 divide-y divide-slate-100 overflow-y-auto text-xs dark:divide-slate-800">
                        <li v-for="d in recResult.data" :key="d.id" class="py-1.5">
                            <span class="font-medium text-slate-800 dark:text-slate-200">{{ d.employee }}</span> · {{ d.leave_type }}:
                            {{ d.stored }} → <strong>{{ d.ledger }}</strong>
                        </li>
                    </ul>
                </div>
            </section>
        </div>
    </div>
</template>
