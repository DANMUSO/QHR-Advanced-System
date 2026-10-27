<script setup>
import { useFormErrors } from '@/lib/formErrors';
import { ref, onMounted } from 'vue';
import { Plus } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import LookupSelect from '@/components/ui/LookupSelect.vue';

const ui = useUiStore();
const rows = ref([]);
const loading = ref(false);
const modal = ref(false);
const form = ref({});
const errors = useFormErrors(() => form.value);
const saving = ref(false);

const statusColors = { clear: 'green', flagged: 'red', pending: 'yellow', expired: 'slate' };

const columns = [
    { key: 'employee_name', label: 'Employee', sortable: true },
    { key: 'type', label: 'Type' },
    { key: 'reference_number', label: 'Reference #' },
    { key: 'issue_date', label: 'Issue Date' },
    { key: 'expiry_date', label: 'Expiry Date' },
    { key: 'status', label: 'Status' },
];

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/compliance/crb', { params: { per_page: 100 } });
        rows.value = data.data ?? data;
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await api.post('/compliance/crb', form.value);
        ui.toast('Record saved.');
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
        <PageHeader title="CRB & Good Conduct">
            <template #actions>
                <button class="btn-primary" @click="modal = true; form = { employee_id: '', type: 'good_conduct', reference_number: '', issue_date: '', expiry_date: '' }">
                    <Plus class="size-4" /> Add Record
                </button>
            </template>
        </PageHeader>

        <DataTable :columns="columns" :rows="rows" :loading="loading" export-name="crb">
            <template #cell-status="{ row }">
                <Badge :color="statusColors[row.status] ?? 'slate'">{{ row.status }}</Badge>
            </template>
        </DataTable>

        <Modal :open="modal" title="Add CRB / Good Conduct Record" @close="modal = false">
            <div class="space-y-4">
                <div>
                    <label class="label">Employee</label>
                    <LookupSelect v-model="form.employee_id" kind="employees" placeholder="Choose an employee" :error="!!errors?.employee_id" />
                    <p v-if="errors.employee_id" class="mt-1 text-xs text-red-500">{{ errors.employee_id }}</p>
                </div>
                <div>
                    <label class="label">Type</label>
                    <select v-model="form.type" class="input">
                        <option value="good_conduct">Certificate of Good Conduct</option>
                        <option value="crb_check">CRB Check</option>
                        <option value="police_clearance">Police Clearance</option>
                    </select>
                </div>
                <div>
                    <label class="label">Reference Number</label>
                    <input v-model="form.reference_number" type="text" class="input" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="label">Issue Date</label>
                        <input v-model="form.issue_date" type="date" class="input" />
                    </div>
                    <div>
                        <label class="label">Expiry Date</label>
                        <input v-model="form.expiry_date" type="date" class="input" />
                    </div>
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>
    </div>
</template>
