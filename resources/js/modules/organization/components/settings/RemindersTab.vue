<script setup>
import { ref, reactive, onMounted } from 'vue';
import { Plus, Pencil, Trash2, Eye, Send } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { formatDate } from '@/lib/format';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Badge from '@/components/ui/Badge.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useCompanyContext } from '../../company';

/** Automatic reminders, sent daily at 07:00 by organization:send-reminders. */
const ui = useUiStore();
const { cfg } = useCompanyContext();

const rows = ref([]);
const options = ref({ events: {}, channels: {}, recipients: {} });
const loading = ref(false);
const columns = [
    { key: 'name', label: 'Reminder', sortable: true },
    { key: 'event_label', label: 'Event' },
    { key: 'days_before', label: 'Days before', align: 'right' },
    { key: 'channel', label: 'Channel' },
    { key: 'recipients', label: 'Sent to' },
    { key: 'last_run_at', label: 'Last run' },
    { key: 'is_active', label: 'Status' },
];

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/organization/reminders', cfg());
        rows.value = data.data ?? [];
        if (data.options) options.value = data.options;
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

const editor = reactive({ open: false, id: null, form: {}, errors: {}, saving: false });
function open(row = null) {
    Object.assign(editor, {
        open: true, id: row?.id ?? null, errors: {}, saving: false,
        form: row
            ? { name: row.name, event: row.event, days_before: row.days_before, channel: row.channel, recipients: row.recipients, is_active: row.is_active }
            : { name: '', event: 'contract_expiry', days_before: 30, channel: 'email', recipients: 'hr', is_active: true },
    });
}
async function save() {
    editor.saving = true;
    editor.errors = {};
    try {
        const { data } = editor.id
            ? await api.put(`/organization/reminders/${editor.id}`, editor.form, cfg())
            : await api.post('/organization/reminders', editor.form, cfg());
        ui.toast(data.message);
        editor.open = false;
        load();
    } catch (e) {
        editor.errors = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally {
        editor.saving = false;
    }
}

const preview = reactive({ row: null, items: [], loading: false, sending: false });
async function openPreview(row) {
    Object.assign(preview, { row, items: [], loading: true });
    try {
        preview.items = (await api.post(`/organization/reminders/${row.id}/run`, {}, cfg())).data.data ?? [];
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        preview.loading = false;
    }
}
async function sendNow() {
    preview.sending = true;
    try {
        const { data } = await api.post(`/organization/reminders/${preview.row.id}/run`, { send: true }, cfg());
        ui.toast(data.message);
        preview.row = null;
        load();
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        preview.sending = false;
    }
}

const del = reactive({ row: null, loading: false });
async function remove() {
    del.loading = true;
    try {
        const { data } = await api.delete(`/organization/reminders/${del.row.id}`, cfg());
        ui.toast(data.message);
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
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-500">Reminders run every morning at 07:00 and notify the chosen people before each event.</p>
            <button class="btn-primary" @click="open()"><Plus class="size-4" /> Add reminder</button>
        </div>
        <DataTable :columns="columns" :rows="rows" :loading="loading" empty-title="No reminders yet">
            <template #cell-name="{ value }"><span class="font-medium text-slate-900 dark:text-white">{{ value }}</span></template>
            <template #cell-channel="{ value }">{{ options.channels[value] ?? value }}</template>
            <template #cell-recipients="{ value }">{{ options.recipients[value] ?? value }}</template>
            <template #cell-last_run_at="{ row, value }">
                <span v-if="value">{{ formatDate(value) }} · {{ row.last_run_count ?? 0 }}</span><span v-else class="text-slate-400">Never</span>
            </template>
            <template #cell-is_active="{ value }"><Badge :tone="value ? 'green' : 'slate'">{{ value ? 'Active' : 'Paused' }}</Badge></template>
            <template #actions="{ row }">
                <button class="btn-ghost !p-1.5" title="Preview today's matches" @click="openPreview(row)"><Eye class="size-4" /></button>
                <button class="btn-ghost !p-1.5" title="Edit" @click="open(row)"><Pencil class="size-4" /></button>
                <button class="btn-ghost !p-1.5 !text-rose-600" title="Delete" @click="del.row = row"><Trash2 class="size-4" /></button>
            </template>
        </DataTable>

        <Modal :open="editor.open" :title="editor.id ? 'Edit reminder' : 'Add reminder'" @close="editor.open = false">
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
                <div class="sm:col-span-2">
                    <label class="label">Name</label>
                    <input v-model="editor.form.name" class="input" />
                    <p v-if="editor.errors.name" class="field-error">{{ editor.errors.name }}</p>
                </div>
                <div>
                    <label class="label">Event</label>
                    <select v-model="editor.form.event" class="input"><option v-for="(l, k) in options.events" :key="k" :value="k">{{ l }}</option></select>
                </div>
                <div>
                    <label class="label">Days before</label>
                    <input v-model="editor.form.days_before" type="number" min="0" max="365" class="input" />
                    <p v-if="editor.errors.days_before" class="field-error">{{ editor.errors.days_before }}</p>
                </div>
                <div>
                    <label class="label">Channel</label>
                    <select v-model="editor.form.channel" class="input"><option v-for="(l, k) in options.channels" :key="k" :value="k">{{ l }}</option></select>
                </div>
                <div>
                    <label class="label">Send to</label>
                    <select v-model="editor.form.recipients" class="input"><option v-for="(l, k) in options.recipients" :key="k" :value="k">{{ l }}</option></select>
                </div>
                <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="editor.form.is_active" type="checkbox" class="rounded text-brand-600" /> Active</label>
                <button type="submit" class="hidden" />
            </form>
            <template #footer>
                <button class="btn-secondary" @click="editor.open = false">Cancel</button>
                <button class="btn-primary" :disabled="editor.saving" @click="save"><Spinner v-if="editor.saving" /> Save</button>
            </template>
        </Modal>

        <Modal :open="!!preview.row" :title="`Today's matches: ${preview.row?.name ?? ''}`" @close="preview.row = null">
            <div v-if="preview.loading" class="flex justify-center py-8"><Spinner /></div>
            <p v-else-if="!preview.items.length" class="py-6 text-center text-sm text-slate-500">No employees match this reminder today.</p>
            <ul v-else class="max-h-80 divide-y divide-slate-100 overflow-y-auto text-sm dark:divide-slate-800">
                <li v-for="m in preview.items" :key="m.employee_id" class="py-2"><p class="font-medium">{{ m.name }}</p><p class="text-xs text-slate-500">{{ m.message }}</p></li>
            </ul>
            <template #footer>
                <button class="btn-secondary" @click="preview.row = null">Close</button>
                <button class="btn-primary" :disabled="preview.sending || !preview.items.length" @click="sendNow"><Spinner v-if="preview.sending" /><Send v-else class="size-4" /> Send now</button>
            </template>
        </Modal>

        <ConfirmDialog :open="!!del.row" title="Delete reminder" :message="`Delete “${del.row?.name}”?`" confirm-label="Delete" danger
            :loading="del.loading" @confirm="remove" @close="del.row = null" />
    </div>
</template>
