<script setup>
import { useFormErrors } from '@/lib/formErrors';
import { ref, onMounted } from 'vue';
import { Plus, MessageSquare } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const ui = useUiStore();
const rows = ref([]);
const paginator = ref(null);
const loading = ref(false);
const modal = ref(false);
const form = ref({});
const errors = useFormErrors(() => form.value);
const saving = ref(false);

const statusColors = { open: 'blue', in_progress: 'yellow', resolved: 'green', closed: 'slate' };
const priorityColors = { low: 'slate', medium: 'yellow', high: 'red', critical: 'red' };

const columns = [
    { key: 'ticket_number', label: 'Ticket #' },
    { key: 'subject', label: 'Subject' },
    { key: 'category', label: 'Category' },
    { key: 'priority', label: 'Priority' },
    { key: 'status', label: 'Status' },
    { key: 'created_at', label: 'Opened' },
];

async function load(page = 1) {
    loading.value = true;
    try {
        const { data } = await api.get('/organization/support', { params: { page } });
        rows.value = data.data;
        paginator.value = { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from, to: data.to };
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await api.post('/organization/support', form.value);
        ui.toast('Support ticket submitted.');
        modal.value = false;
        await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Support Tickets">
            <template #actions>
                <button class="btn-primary" @click="modal = true; form = { subject: '', category: 'technical', priority: 'medium', description: '' }">
                    <Plus class="size-4" /> New Ticket
                </button>
            </template>
        </PageHeader>

        <DataTable :columns="columns" :rows="rows" :loading="loading" :paginator="paginator" @page="load">
            <template #cell-status="{ row }">
                <Badge :color="statusColors[row.status] ?? 'slate'">{{ row.status }}</Badge>
            </template>
            <template #cell-priority="{ row }">
                <Badge :color="priorityColors[row.priority] ?? 'slate'">{{ row.priority }}</Badge>
            </template>
            <template #actions="{ row }">
                <button class="btn-ghost !p-1.5" title="View"><MessageSquare class="size-4" /></button>
            </template>
        </DataTable>

        <Modal :open="modal" title="New Support Ticket" @close="modal = false">
            <div class="space-y-4">
                <div>
                    <label class="label">Subject</label>
                    <input v-model="form.subject" type="text" class="input" />
                    <p v-if="errors.subject" class="mt-1 text-xs text-red-500">{{ errors.subject }}</p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="label">Category</label>
                        <select v-model="form.category" class="input">
                            <option value="technical">Technical</option>
                            <option value="payroll">Payroll</option>
                            <option value="leave">Leave</option>
                            <option value="access">Access / Permissions</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Priority</label>
                        <select v-model="form.priority" class="input">
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="label">Description</label>
                    <textarea v-model="form.description" rows="4" class="input" />
                    <p v-if="errors.description" class="mt-1 text-xs text-red-500">{{ errors.description }}</p>
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Submitting…' : 'Submit Ticket' }}</button>
            </template>
        </Modal>
    </div>
</template>
