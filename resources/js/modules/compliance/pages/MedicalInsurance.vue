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

const columns = [
    { key: 'employee_name', label: 'Employee', sortable: true },
    { key: 'scheme_name', label: 'Scheme' },
    { key: 'member_number', label: 'Member #' },
    { key: 'cover_limit', label: 'Cover Limit (KES)', align: 'right', format: v => v?.toLocaleString() },
    { key: 'dependants', label: 'Dependants', align: 'right' },
    { key: 'expiry_date', label: 'Expiry' },
    { key: 'status', label: 'Status' },
];

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/compliance/medical', { params: { per_page: 100 } });
        rows.value = data.data ?? data;
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await api.post('/compliance/medical', form.value);
        ui.toast('Medical record saved.');
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
        <PageHeader title="Medical & Insurance">
            <template #actions>
                <button class="btn-primary" @click="modal = true; form = { employee_id: '', scheme_name: '', member_number: '', cover_limit: '', expiry_date: '' }">
                    <Plus class="size-4" /> Add Record
                </button>
            </template>
        </PageHeader>

        <DataTable :columns="columns" :rows="rows" :loading="loading" export-name="medical">
            <template #cell-status="{ row }">
                <Badge :color="row.status === 'active' ? 'green' : row.status === 'expiring_soon' ? 'yellow' : 'red'">{{ row.status ?? 'active' }}</Badge>
            </template>
        </DataTable>

        <Modal :open="modal" title="Add Medical / Insurance Record" @close="modal = false">
            <div class="space-y-4">
                <div>
                    <label class="label">Employee</label>
                    <LookupSelect v-model="form.employee_id" kind="employees" placeholder="Choose an employee" :error="!!errors?.employee_id" />
                    <p v-if="errors.employee_id" class="mt-1 text-xs text-red-500">{{ errors.employee_id }}</p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="label">Scheme Name</label>
                        <input v-model="form.scheme_name" type="text" class="input" placeholder="e.g. AAR, Jubilee" />
                    </div>
                    <div>
                        <label class="label">Member Number</label>
                        <input v-model="form.member_number" type="text" class="input" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="label">Cover Limit (KES)</label>
                        <input v-model="form.cover_limit" type="number" class="input" />
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
