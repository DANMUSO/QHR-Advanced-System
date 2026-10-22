<script setup>
import { ref, onMounted } from 'vue';
import { Plus, Pencil, Trash2 } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { titleCase } from '@/lib/format';
import Badge from '@/components/ui/Badge.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const ui = useUiStore();
const requests = ref([]);
const paginator = ref(null);
const loading = ref(false);
const modal = ref(false);
const editing = ref(null);
const form = ref({});
const errors = ref({});
const saving = ref(false);
const confirm = ref({ open: false, row: null, loading: false });

const typeColors = { access: 'blue', erasure: 'red', rectification: 'yellow', portability: 'green', objection: 'purple' };
const statusColors = { pending: 'yellow', in_progress: 'blue', completed: 'green', rejected: 'red' };

const columns = [
    { key: 'reference', label: 'Ref #' },
    { key: 'requester_name', label: 'Requester', sortable: true },
    { key: 'type', label: 'Request Type' },
    { key: 'submitted_date', label: 'Submitted' },
    { key: 'due_date', label: 'Due Date' },
    { key: 'status', label: 'Status' },
];

async function load(page = 1) {
    loading.value = true;
    try {
        const { data } = await api.get('/compliance/data-protection', { params: { page } });
        requests.value = data.data ?? data;
        paginator.value = data.current_page ? { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from, to: data.to } : null;
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    editing.value = null; errors.value = {};
    form.value = { requester_name: '', requester_email: '', type: 'access', description: '' };
    modal.value = true;
}

function openEdit(row) {
    editing.value = row; errors.value = {};
    form.value = { ...row };
    modal.value = true;
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        editing.value
            ? await api.put(`/compliance/data-protection/${editing.value.id}`, form.value)
            : await api.post('/compliance/data-protection', form.value);
        ui.toast(editing.value ? 'Request updated.' : 'Data request logged.', 'success');
        modal.value = false;
        await load(editing.value ? paginator.value?.current_page ?? 1 : 1);
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally {
        saving.value = false;
    }
}

async function remove() {
    confirm.value.loading = true;
    try {
        await api.delete(`/compliance/data-protection/${confirm.value.row.id}`);
        ui.toast('Deleted.', 'success');
        confirm.value.open = false;
        await load(paginator.value?.current_page ?? 1);
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally { confirm.value.loading = false; }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Data Protection (GDPR / DPA)">
            <template #actions>
                <button class="btn-primary" @click="openCreate">
                    <Plus class="size-4" /> Log Request
                </button>
            </template>
        </PageHeader>

        <DataTable :columns="columns" :rows="requests" :loading="loading" :paginator="paginator" export-name="data_requests" @page="load">
            <template #cell-type="{ row }">
                <Badge :color="typeColors[row.type] ?? 'slate'">{{ row.type }}</Badge>
            </template>
            <template #cell-due_date="{ row }">
                <span :class="row.is_overdue ? 'font-medium text-red-600 dark:text-red-400' : ''">{{ row.due_date }}<span v-if="row.is_overdue"> · overdue</span></span>
            </template>
            <template #cell-status="{ row }">
                <Badge :color="statusColors[row.status] ?? 'slate'">{{ titleCase(row.status) }}</Badge>
            </template>
            <template #actions="{ row }">
                <button class="btn-ghost !p-1.5" title="Edit" @click="openEdit(row)"><Pencil class="size-4" /></button>
                <button class="btn-ghost !p-1.5 text-red-500" title="Delete" @click="confirm = { open: true, row, loading: false }"><Trash2 class="size-4" /></button>
            </template>
        </DataTable>

        <Modal :open="modal" :title="editing ? `Data Request ${editing.reference}` : 'Log Data Request'" @close="modal = false">
            <div class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Requester Name</label>
                        <input v-model="form.requester_name" type="text" class="input" />
                        <p v-if="errors.requester_name" class="field-error">{{ errors.requester_name }}</p>
                    </div>
                    <div>
                        <label class="label">Requester Email</label>
                        <input v-model="form.requester_email" type="email" class="input" />
                        <p v-if="errors.requester_email" class="field-error">{{ errors.requester_email }}</p>
                    </div>
                </div>
                <div>
                    <label class="label">Request Type</label>
                    <select v-model="form.type" class="input">
                        <option value="access">Right of Access</option>
                        <option value="erasure">Right to Erasure</option>
                        <option value="rectification">Rectification</option>
                        <option value="portability">Data Portability</option>
                        <option value="objection">Right to Object</option>
                    </select>
                </div>
                <div>
                    <label class="label">Description</label>
                    <textarea v-model="form.description" rows="3" class="input" />
                </div>
                <template v-if="editing">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label">Status</label>
                            <select v-model="form.status" class="input">
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>
                        <div><label class="label">Due Date</label><input v-model="form.due_date" type="date" class="input" /></div>
                    </div>
                    <div><label class="label">Response Notes</label><textarea v-model="form.response_notes" rows="2" class="input" /></div>
                </template>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : editing ? 'Save' : 'Log Request' }}</button>
            </template>
        </Modal>

        <ConfirmDialog :open="confirm.open" title="Delete request" danger confirm-label="Delete" :loading="confirm.loading"
            :message="`Delete request ${confirm.row?.reference ?? ''}? The audit trail keeps a copy.`"
            @confirm="remove" @close="confirm.open = false" />
    </div>
</template>
