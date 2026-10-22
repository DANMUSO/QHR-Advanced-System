<script setup>
/**
 * Biometric terminals (attendance.manage): register, edit, activate / deactivate, rotate the push token, revoke.
 * Tokens are only returned at registration / rotation and are shown once.
 */
import { ref, onMounted } from 'vue';
import { Plus, Pencil, RefreshCw, Ban, Copy } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import LookupSelect from '@/components/ui/LookupSelect.vue';
import { timeAgo } from '@/lib/format';

const rows = ref([]);
const loading = ref(true);
const modal = ref({ open: false, row: null });
const form = ref({});
const errors = ref({});
const saving = ref(false);
const issued = ref(null); // { name, token } shown once

const columns = [
    { key: 'name', label: 'Device', sortable: true },
    { key: 'branch_name', label: 'Branch' },
    { key: 'token_hint', label: 'Token' },
    { key: 'last_seen_at', label: 'Last seen' },
    { key: 'punches_today', label: 'Punches today', align: 'right' },
    { key: 'active', label: 'Status' },
];

async function load() {
    loading.value = true;
    try { rows.value = (await api.get('/attendance/devices')).data.data; }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}

function openForm(row = null) {
    modal.value = { open: true, row };
    form.value = row ? { name: row.name, serial: row.serial ?? '', branch_id: row.branch_id ?? '', active: row.active } : { name: '', serial: '', branch_id: '', active: true };
    errors.value = {};
}

async function save() {
    saving.value = true; errors.value = {};
    try {
        const body = { ...form.value, branch_id: form.value.branch_id || null };
        const { data } = modal.value.row
            ? await api.put(`/attendance/devices/${modal.value.row.id}`, body)
            : await api.post('/attendance/devices', body);
        modal.value.open = false;
        if (data.token) issued.value = { name: data.data.name, token: data.token };
        swal.toast(data.message);
        await load();
    } catch (e) { errors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}

async function toggle(row) {
    try {
        await api.put(`/attendance/devices/${row.id}`, { name: row.name, serial: row.serial, branch_id: row.branch_id, active: !row.active });
        swal.toast(row.active ? 'Device deactivated.' : 'Device activated.');
        await load();
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
}

async function rotate(row) {
    if (!await swal.confirm({ title: `Issue a new token for '${row.name}'?`, text: 'The current token stops working immediately; update the terminal with the new one.', confirmLabel: 'Rotate token', danger: false })) return;
    try {
        const { data } = await api.post(`/attendance/devices/${row.id}/rotate`);
        issued.value = { name: row.name, token: data.token };
        await load();
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
}

async function revoke(row) {
    if (!await swal.confirm({ title: `Revoke '${row.name}'?`, text: 'It can no longer push punches. Its history is kept.', confirmLabel: 'Revoke' })) return;
    try { const { data } = await api.delete(`/attendance/devices/${row.id}`); swal.toast(data.message); await load(); }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
}

async function copyToken() {
    try { await navigator.clipboard.writeText(issued.value.token); swal.toast('Token copied.'); }
    catch { swal.toast('Copy failed — select the token and copy it manually.', 'warning'); }
}

defineExpose({ openForm });
onMounted(load);
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-500">Terminals push punches to <code class="rounded bg-slate-100 px-1 text-xs dark:bg-slate-800">/api/attendance/device/punches</code> with their bearer token.</p>
            <button class="btn-primary" @click="openForm()"><Plus class="size-4" /> Register device</button>
        </div>

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <DataTable v-else :columns="columns" :rows="rows" :loading="false">
            <template #cell-name="{ row }">
                <p class="font-medium">{{ row.name }}</p>
                <p v-if="row.serial" class="text-xs text-slate-400">S/N {{ row.serial }}</p>
            </template>
            <template #cell-token_hint="{ row }"><code class="text-xs">{{ row.token_hint ?? '—' }}</code></template>
            <template #cell-last_seen_at="{ row }">
                <span v-if="row.last_seen_at" class="text-sm">{{ timeAgo(row.last_seen_at) }}</span>
                <span v-else class="text-sm text-slate-400">Never</span>
                <p v-if="row.last_ip" class="text-xs text-slate-400">{{ row.last_ip }}</p>
            </template>
            <template #cell-active="{ row }">
                <Badge v-if="row.revoked_at" color="red">Revoked</Badge>
                <Badge v-else-if="!row.active" color="slate">Inactive</Badge>
                <Badge v-else :color="row.online ? 'green' : 'yellow'">{{ row.online ? 'Online' : 'Offline' }}</Badge>
            </template>
            <template #actions="{ row }">
                <button class="btn-ghost !p-1.5" title="Edit" @click="openForm(row)"><Pencil class="size-4" /></button>
                <button class="btn-ghost !p-1.5" title="Rotate token" @click="rotate(row)"><RefreshCw class="size-4" /></button>
                <button v-if="!row.revoked_at" class="btn-ghost !px-2 !py-1 text-xs" @click="toggle(row)">{{ row.active ? 'Deactivate' : 'Activate' }}</button>
                <button v-if="!row.revoked_at" class="btn-ghost !p-1.5 text-red-500" title="Revoke" @click="revoke(row)"><Ban class="size-4" /></button>
            </template>
        </DataTable>

        <Modal :open="modal.open" :title="modal.row ? 'Edit Device' : 'Register Device'" @close="modal.open = false">
            <div class="space-y-4">
                <div><label class="label">Name</label><input v-model="form.name" class="input" placeholder="e.g. Head office entrance" /><p v-if="errors.name" class="field-error">{{ errors.name }}</p></div>
                <div><label class="label">Serial number (optional)</label><input v-model="form.serial" class="input" /></div>
                <div>
                    <label class="label">Branch</label>
                    <LookupSelect v-model="form.branch_id" kind="branches" placeholder="All branches" :error="!!errors.branch_id" />
                    <p v-if="errors.branch_id" class="field-error">{{ errors.branch_id }}</p>
                </div>
                <label v-if="modal.row" class="flex items-center gap-2 text-sm"><input v-model="form.active" type="checkbox" class="rounded" /> Active (may push punches)</label>
                <p v-else class="text-xs text-slate-500">A push token is generated when you save. It is shown once.</p>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving || !form.name" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>

        <Modal :open="!!issued" title="Device token" @close="issued = null">
            <p class="mb-3 text-sm text-slate-600 dark:text-slate-400">Enter this token on <strong>{{ issued?.name }}</strong>. It will not be shown again.</p>
            <div class="flex items-center gap-2">
                <code class="block flex-1 break-all rounded-lg bg-slate-100 p-3 text-xs dark:bg-slate-800">{{ issued?.token }}</code>
                <button class="btn-secondary !p-2" title="Copy" @click="copyToken"><Copy class="size-4" /></button>
            </div>
            <template #footer><button class="btn-primary" @click="issued = null">Done</button></template>
        </Modal>
    </div>
</template>
