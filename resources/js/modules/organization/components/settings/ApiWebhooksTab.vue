<script setup>
import { ref, reactive, onMounted } from 'vue';
import { Plus, Pencil, Trash2, Copy, Send, KeyRound } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { formatDate } from '@/lib/format';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Badge from '@/components/ui/Badge.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useCompanyContext } from '../../company';

/** Personal API tokens of the signed-in admin and the company's outgoing webhooks. */
const ui = useUiStore();
const { cfg } = useCompanyContext();

const tokens = ref([]);
const hooks = ref([]);
const events = ref({});
const loading = ref(false);
const columns = [
    { key: 'url', label: 'Endpoint' },
    { key: 'events', label: 'Events' },
    { key: 'last_triggered_at', label: 'Last delivery' },
    { key: 'is_active', label: 'Status' },
];

async function load() {
    loading.value = true;
    try {
        const [t, w] = await Promise.all([api.get('/organization/api-tokens'), api.get('/organization/webhooks', cfg())]);
        tokens.value = t.data.data ?? [];
        hooks.value = w.data.data ?? [];
        events.value = w.data.options?.events ?? {};
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

// ---- tokens ----------------------------------------------------------------------------------
const tokenForm = reactive({ open: false, name: '', expires_in_days: 365, errors: {}, saving: false });
const newToken = ref(null);
async function createToken() {
    tokenForm.saving = true;
    tokenForm.errors = {};
    try {
        const { data } = await api.post('/organization/api-tokens', { name: tokenForm.name, expires_in_days: tokenForm.expires_in_days || null });
        newToken.value = data.token;
        tokenForm.open = false;
        load();
    } catch (e) {
        tokenForm.errors = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally {
        tokenForm.saving = false;
    }
}
async function revoke(t) {
    try {
        ui.toast((await api.delete(`/organization/api-tokens/${t.id}`)).data.message);
        load();
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    }
}
function copy(text) {
    navigator.clipboard?.writeText(text);
    ui.toast('Copied.');
}

// ---- webhooks --------------------------------------------------------------------------------
const editor = reactive({ open: false, id: null, form: {}, errors: {}, saving: false });
function open(row = null) {
    Object.assign(editor, {
        open: true, id: row?.id ?? null, errors: {}, saving: false,
        form: { url: row?.url ?? '', secret: '', events: [...(row?.events ?? [])], is_active: row?.is_active ?? true, has_secret: row?.has_secret },
    });
}
function toggleEvent(key) {
    const list = editor.form.events;
    editor.form.events = list.includes(key) ? list.filter((e) => e !== key) : [...list, key];
}
async function save() {
    editor.saving = true;
    editor.errors = {};
    const { has_secret, ...payload } = editor.form;
    try {
        const { data } = editor.id
            ? await api.put(`/organization/webhooks/${editor.id}`, payload, cfg())
            : await api.post('/organization/webhooks', payload, cfg());
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
async function test(row) {
    try {
        const { data } = await api.post(`/organization/webhooks/${row.id}/test`, {}, cfg());
        ui.toast(data.message, data.ok ? 'success' : 'warning');
        load();
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    }
}
const del = reactive({ row: null, loading: false });
async function remove() {
    del.loading = true;
    try {
        ui.toast((await api.delete(`/organization/webhooks/${del.row.id}`, cfg())).data.message);
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
    <div class="space-y-6">
        <section class="card p-5">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Your API tokens</h3>
                    <p class="text-xs text-slate-500">Tokens act as your account. Send them as <code>Authorization: Bearer &lt;token&gt;</code>.</p>
                </div>
                <button class="btn-secondary" @click="Object.assign(tokenForm, { open: true, name: '', expires_in_days: 365, errors: {} })"><Plus class="size-4" /> New token</button>
            </div>
            <div v-if="newToken" class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-500/30 dark:bg-emerald-500/10">
                <p class="mb-1 text-sm font-medium text-emerald-800 dark:text-emerald-300">Copy this token now. It will not be shown again.</p>
                <div class="flex items-center gap-2">
                    <code class="min-w-0 flex-1 break-all rounded bg-white px-2 py-1 text-xs dark:bg-slate-900">{{ newToken }}</code>
                    <button class="btn-ghost !p-1.5" title="Copy" @click="copy(newToken)"><Copy class="size-4" /></button>
                </div>
            </div>
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                <li v-for="t in tokens" :key="t.id" class="flex items-center gap-3 py-2.5">
                    <KeyRound class="size-4 shrink-0 text-slate-400" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ t.name }}</p>
                        <p class="text-xs text-slate-500">Last used {{ t.last_used_at ? formatDate(t.last_used_at) : 'never' }} · {{ t.expires_at ? `expires ${formatDate(t.expires_at)}` : 'no expiry' }}</p>
                    </div>
                    <button class="btn-ghost !p-1.5 !text-rose-600" title="Revoke" @click="revoke(t)"><Trash2 class="size-4" /></button>
                </li>
                <li v-if="!tokens.length && !loading" class="py-4 text-center text-sm text-slate-400">No API tokens.</li>
            </ul>
        </section>

        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Webhooks</h3>
                    <p class="text-xs text-slate-500">Signed JSON events (header <code>X-HR-Signature: sha256=…</code>) sent to your endpoints.</p>
                </div>
                <button class="btn-primary" @click="open()"><Plus class="size-4" /> Add webhook</button>
            </div>
            <DataTable :columns="columns" :rows="hooks" :loading="loading" empty-title="No webhooks yet">
                <template #cell-url="{ value }"><span class="break-all font-mono text-xs">{{ value }}</span></template>
                <template #cell-events="{ value }">
                    <span v-if="!value?.length" class="text-xs text-slate-500">All events</span>
                    <div v-else class="flex flex-wrap gap-1"><Badge v-for="e in value" :key="e" tone="slate" class="!normal-case">{{ e }}</Badge></div>
                </template>
                <template #cell-last_triggered_at="{ row, value }">
                    <span v-if="value" :class="row.last_status >= 200 && row.last_status < 300 ? 'text-emerald-600' : 'text-rose-600'">{{ formatDate(value) }} · {{ row.last_status || 'failed' }}</span>
                    <span v-else class="text-slate-400">Never</span>
                </template>
                <template #cell-is_active="{ value }"><Badge :tone="value ? 'green' : 'slate'">{{ value ? 'Active' : 'Paused' }}</Badge></template>
                <template #actions="{ row }">
                    <button class="btn-ghost !p-1.5" title="Send test ping" @click="test(row)"><Send class="size-4" /></button>
                    <button class="btn-ghost !p-1.5" title="Edit" @click="open(row)"><Pencil class="size-4" /></button>
                    <button class="btn-ghost !p-1.5 !text-rose-600" title="Delete" @click="del.row = row"><Trash2 class="size-4" /></button>
                </template>
            </DataTable>
        </section>

        <Modal :open="tokenForm.open" title="New API token" @close="tokenForm.open = false">
            <form class="space-y-4" @submit.prevent="createToken">
                <div>
                    <label class="label">Name</label>
                    <input v-model="tokenForm.name" class="input" placeholder="e.g. Payroll integration" />
                    <p v-if="tokenForm.errors.name" class="field-error">{{ tokenForm.errors.name }}</p>
                </div>
                <div>
                    <label class="label">Expires after (days, blank = never)</label>
                    <input v-model="tokenForm.expires_in_days" type="number" min="1" max="730" class="input sm:w-40" />
                </div>
                <button type="submit" class="hidden" />
            </form>
            <template #footer>
                <button class="btn-secondary" @click="tokenForm.open = false">Cancel</button>
                <button class="btn-primary" :disabled="tokenForm.saving" @click="createToken"><Spinner v-if="tokenForm.saving" /> Create token</button>
            </template>
        </Modal>

        <Modal :open="editor.open" :title="editor.id ? 'Edit webhook' : 'Add webhook'" @close="editor.open = false">
            <form class="space-y-4" @submit.prevent="save">
                <div>
                    <label class="label">Endpoint URL</label>
                    <input v-model="editor.form.url" type="url" class="input" placeholder="https://…" />
                    <p v-if="editor.errors.url" class="field-error">{{ editor.errors.url }}</p>
                </div>
                <div>
                    <label class="label">Signing secret</label>
                    <input v-model="editor.form.secret" type="password" class="input" autocomplete="off" :placeholder="editor.form.has_secret ? 'Saved; leave blank to keep' : 'Optional, at least 8 characters'" />
                    <p v-if="editor.errors.secret" class="field-error">{{ editor.errors.secret }}</p>
                </div>
                <div>
                    <p class="label">Events (none ticked = all events)</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label v-for="(label, key) in events" :key="key" class="flex items-center gap-2 text-sm">
                            <input type="checkbox" class="rounded text-brand-600" :checked="editor.form.events.includes(key)" @change="toggleEvent(key)" />
                            {{ label }} <code class="text-xs text-slate-400">{{ key }}</code>
                        </label>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm"><input v-model="editor.form.is_active" type="checkbox" class="rounded text-brand-600" /> Active</label>
                <button type="submit" class="hidden" />
            </form>
            <template #footer>
                <button class="btn-secondary" @click="editor.open = false">Cancel</button>
                <button class="btn-primary" :disabled="editor.saving" @click="save"><Spinner v-if="editor.saving" /> Save</button>
            </template>
        </Modal>

        <ConfirmDialog :open="!!del.row" title="Delete webhook" :message="`Stop sending events to ${del.row?.url}?`" confirm-label="Delete" danger
            :loading="del.loading" @confirm="remove" @close="del.row = null" />
    </div>
</template>
