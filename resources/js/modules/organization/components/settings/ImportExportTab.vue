<script setup>
import { ref, computed, onMounted } from 'vue';
import { Upload, Download, FileSpreadsheet, ChevronDown, ChevronRight } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { formatDate } from '@/lib/format';
import Badge from '@/components/ui/Badge.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useCompanyContext } from '../../company';

/** CSV bulk import (employees, structure, holidays) and export. */
const ui = useUiStore();
const { cfg } = useCompanyContext();

const rows = ref([]);
const types = ref([]);
const type = ref('employees');
const loading = ref(false);
const uploading = ref(false);
const fileInput = ref(null);
const expanded = ref(null);
const statusTone = { pending: 'amber', processing: 'blue', completed: 'green', failed: 'red' };
const current = computed(() => types.value.find((t) => t.value === type.value));

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/organization/imports', cfg());
        rows.value = data.data ?? [];
        if (data.types?.length) types.value = data.types;
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

async function download(url, filename) {
    try {
        const { data } = await api.get(url, cfg({ responseType: 'blob' }));
        const href = URL.createObjectURL(data);
        const a = document.createElement('a');
        a.href = href;
        a.download = filename;
        a.click();
        URL.revokeObjectURL(href);
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    }
}

async function upload(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    uploading.value = true;
    const fd = new FormData();
    fd.append('file', file);
    fd.append('type', type.value);
    try {
        const { data } = await api.post('/organization/imports', fd, cfg());
        ui.toast(data.message, data.data?.failed ? 'warning' : 'success');
        expanded.value = data.data?.failed ? data.data.id : null;
    } catch (err) {
        ui.toast(errorMessage(err), 'error');
    } finally {
        uploading.value = false;
        fileInput.value.value = '';
        load();
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-5">
        <section class="card p-5">
            <h3 class="mb-4 text-sm font-semibold text-slate-900 dark:text-white">Import or export data</h3>
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="label" for="imp-type">Data type</label>
                    <select id="imp-type" v-model="type" class="input w-56">
                        <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                    </select>
                </div>
                <button class="btn-secondary" @click="download(`/organization/imports/template/${type}`, `${type}_template.csv`)"><FileSpreadsheet class="size-4" /> Template</button>
                <button class="btn-secondary" @click="download(`/organization/exports/${type}`, `${type}.csv`)"><Download class="size-4" /> Export current data</button>
                <label class="btn-primary cursor-pointer">
                    <Spinner v-if="uploading" /><Upload v-else class="size-4" /> {{ uploading ? 'Importing…' : 'Upload CSV' }}
                    <input ref="fileInput" type="file" accept=".csv,text/csv" class="sr-only" :disabled="uploading" @change="upload" />
                </label>
            </div>
            <p v-if="current" class="mt-3 text-xs text-slate-500">Columns: <span class="font-mono">{{ current.columns.join(', ') }}</span></p>
            <ul v-if="type === 'employees'" class="mt-3 list-disc space-y-1 pl-5 text-xs text-slate-500">
                <li>New employees go through the normal onboarding: probation from their level, an employment contract, supervisor and the “employee hired” automation.</li>
                <li>New rows are checked like the Add employee form: email, phone, gender, date of birth, national ID, branch, department, position and a next of kin are required, and phone, national ID and KRA PIN must be valid. Invalid rows are skipped and listed with their row number.</li>
                <li><strong>supervisor</strong>: the supervisor's employee number or email. List supervisors above their team in the file.</li>
                <li><strong>create_login</strong>: “yes” creates a login and emails an activation link (needs an email address).</li>
                <li>Rows with an existing employee number update that record. Save Excel files as “CSV UTF-8”. Max 5 MB.</li>
            </ul>
        </section>

        <section class="card overflow-hidden">
            <div class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-900 dark:border-slate-800 dark:text-white">Import history</div>
            <div v-if="loading" class="flex justify-center py-10"><Spinner /></div>
            <p v-else-if="!rows.length" class="px-5 py-10 text-center text-sm text-slate-400">No imports yet.</p>
            <ul v-else class="divide-y divide-slate-100 dark:divide-slate-800">
                <li v-for="r in rows" :key="r.id" class="px-5 py-3">
                    <button class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 text-left text-sm" :disabled="!r.errors?.length" @click="expanded = expanded === r.id ? null : r.id">
                        <component :is="expanded === r.id ? ChevronDown : ChevronRight" class="size-4 text-slate-400" :class="!r.errors?.length && 'invisible'" />
                        <span class="font-medium text-slate-900 dark:text-white">{{ r.type_label }}</span>
                        <span class="min-w-0 flex-1 truncate text-slate-500">{{ r.filename }}</span>
                        <span class="text-slate-500">{{ r.imported ?? 0 }}/{{ r.total_rows ?? 0 }} imported</span>
                        <span v-if="r.failed" class="font-semibold text-rose-600">{{ r.failed }} failed</span>
                        <Badge :tone="statusTone[r.status] ?? 'slate'">{{ r.status }}</Badge>
                        <span class="text-xs text-slate-400">{{ formatDate(r.created_at) }} · {{ r.user?.name }}</span>
                    </button>
                    <ul v-if="expanded === r.id" class="mt-2 max-h-60 space-y-1 overflow-y-auto rounded-lg bg-rose-50 p-3 text-xs text-rose-800 dark:bg-rose-500/10 dark:text-rose-300">
                        <li v-for="(err, i) in r.errors" :key="i"><strong>Row {{ err.row }}:</strong> {{ err.message }}</li>
                    </ul>
                </li>
            </ul>
        </section>
    </div>
</template>
