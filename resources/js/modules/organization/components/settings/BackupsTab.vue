<script setup>
import { ref, reactive, onMounted } from 'vue';
import { Download, Trash2, DatabaseBackup, Info } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { formatDate } from '@/lib/format';
import { openServerFile } from '@/lib/export';
import DataTable from '@/components/ui/DataTable.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Badge from '@/components/ui/Badge.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useCompanyContext } from '../../company';

/** Company data backups (compressed JSON on the private disk). */
const ui = useUiStore();
const { cfg } = useCompanyContext();

const rows = ref([]);
const loading = ref(false);
const creating = ref(false);
const tone = { completed: 'green', running: 'blue', failed: 'red' };
const columns = [
    { key: 'filename', label: 'Backup' },
    { key: 'records', label: 'Records', align: 'right' },
    { key: 'size_human', label: 'Size' },
    { key: 'status', label: 'Status' },
    { key: 'created_at', label: 'Created' },
];

async function load() {
    loading.value = true;
    try {
        rows.value = (await api.get('/organization/backups', cfg())).data.data ?? [];
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

async function create() {
    creating.value = true;
    try {
        ui.toast((await api.post('/organization/backups', {}, cfg())).data.message);
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        creating.value = false;
        load();
    }
}

const del = reactive({ row: null, loading: false });
async function remove() {
    del.loading = true;
    try {
        ui.toast((await api.delete(`/organization/backups/${del.row.id}`, cfg())).data.message);
        del.row = null;
        load();
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        del.loading = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex max-w-3xl gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200">
                <Info class="mt-0.5 size-4 shrink-0" />
                <p>
                    A backup contains every record of this company, including linked detail rows (claim items, payroll lines, dependants…).
                    Passwords, two-factor secrets and integration credentials are never included.
                    <strong>Restoring is not available from this screen.</strong> A system administrator validates and restores a backup on the server with
                    <code class="text-xs">php artisan organization:restore-backup &lt;id&gt;</code> (dry run) and <code class="text-xs">--force</code> to apply;
                    restore merges rows by id and never deletes newer data.
                </p>
            </div>
            <button class="btn-primary" :disabled="creating" @click="create"><Spinner v-if="creating" /><DatabaseBackup v-else class="size-4" /> Create backup</button>
        </div>
        <DataTable :columns="columns" :rows="rows" :loading="loading" empty-title="No backups yet">
            <template #cell-filename="{ row, value }">
                <span class="font-mono text-xs">{{ value }}</span>
                <p v-if="row.error" class="text-xs text-rose-600">{{ row.error }}</p>
            </template>
            <template #cell-status="{ value }"><Badge :tone="tone[value] ?? 'slate'">{{ value }}</Badge></template>
            <template #cell-created_at="{ row, value }">{{ formatDate(value) }}<span v-if="row.user" class="text-xs text-slate-400"> · {{ row.user.name }}</span></template>
            <template #actions="{ row }">
                <button v-if="row.status === 'completed'" class="btn-ghost !p-1.5" title="Download" @click="openServerFile(`/api/organization/backups/${row.id}/download`)"><Download class="size-4" /></button>
                <button class="btn-ghost !p-1.5 !text-rose-600" title="Delete" @click="del.row = row"><Trash2 class="size-4" /></button>
            </template>
        </DataTable>
        <ConfirmDialog :open="!!del.row" title="Delete backup" :message="`Delete ${del.row?.filename}? The file is removed permanently.`" confirm-label="Delete" danger
            :loading="del.loading" @confirm="remove" @close="del.row = null" />
    </div>
</template>
