<script setup>
import { useFormErrors } from '@/lib/formErrors';
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
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';

const ui = useUiStore();
const tab = ref('hazards');
const tabs = [
    { key: 'hazards', label: 'Hazards & Risks' },
    { key: 'incidents', label: 'Incident Reports' },
];

const rows = ref([]);
const paginator = ref(null);
const loading = ref(false);
const modal = ref(false);
const editing = ref(null);
const form = ref({});
const errors = useFormErrors(() => form.value);
// Hazards and incidents are recorded after the fact: no future dates (the server rejects them too).
const maxNow = () => {
    const d = new Date();
    return `${d.toLocaleDateString('en-CA')}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};
const saving = ref(false);
const confirm = ref({ open: false, row: null, loading: false });

const hazardCols = [
    { key: 'title', label: 'Title', sortable: true },
    { key: 'category', label: 'Category', format: titleCase },
    { key: 'risk_level', label: 'Risk' },
    { key: 'reported_by', label: 'Reported By' },
    { key: 'reported_date', label: 'Date' },
    { key: 'status', label: 'Status' },
];
const incidentCols = [
    { key: 'incident_number', label: 'Incident #' },
    { key: 'title', label: 'Title', sortable: true },
    { key: 'type', label: 'Type', format: titleCase },
    { key: 'severity', label: 'Severity' },
    { key: 'location', label: 'Location' },
    { key: 'incident_date', label: 'Date' },
    { key: 'status', label: 'Status' },
];

const defaultForms = {
    hazards: { title: '', category: 'hazard', risk_level: 'medium', description: '', reported_date: '' },
    incidents: { title: '', type: 'accident', severity: 'minor', location: '', incident_date: '', description: '' },
};

const endpoint = () => (tab.value === 'hazards' ? '/compliance/health-safety' : '/compliance/incidents');

async function load(page = 1) {
    loading.value = true; rows.value = [];
    try {
        const { data } = await api.get(endpoint(), { params: { page } });
        rows.value = data.data ?? data;
        paginator.value = data.current_page ? { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from, to: data.to } : null;
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally { loading.value = false; }
}

function switchTab(t) { tab.value = t; load(); }

function openCreate() {
    editing.value = null; errors.value = {};
    form.value = { ...defaultForms[tab.value] };
    modal.value = true;
}

function openEdit(row) {
    editing.value = row; errors.value = {};
    form.value = { ...row, incident_date: row.incident_date ? row.incident_date.replace(' ', 'T') : '' };
    modal.value = true;
}

async function save() {
    saving.value = true; errors.value = {};
    try {
        editing.value
            ? await api.put(`${endpoint()}/${editing.value.id}`, form.value)
            : await api.post(endpoint(), form.value);
        ui.toast(editing.value ? 'Updated.' : 'Submitted.', 'success');
        modal.value = false;
        await load(editing.value ? paginator.value?.current_page ?? 1 : 1);
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally { saving.value = false; }
}

async function remove() {
    confirm.value.loading = true;
    try {
        await api.delete(`${endpoint()}/${confirm.value.row.id}`);
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
        <PageHeader title="Health & Safety">
            <template #actions>
                <button class="btn-primary" @click="openCreate">
                    <Plus class="size-4" /> {{ tab === 'hazards' ? 'Report Issue' : 'Report Incident' }}
                </button>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <DataTable v-else :columns="tab === 'hazards' ? hazardCols : incidentCols" :rows="rows" :loading="false" :paginator="paginator" :export-name="tab" @page="load">
            <template #cell-risk_level="{ row }">
                <Badge :color="row.risk_level === 'high' || row.risk_level === 'critical' ? 'red' : row.risk_level === 'medium' ? 'yellow' : 'green'">{{ row.risk_level }}</Badge>
            </template>
            <template #cell-severity="{ row }">
                <Badge :color="row.severity === 'serious' || row.severity === 'critical' ? 'red' : row.severity === 'moderate' ? 'yellow' : 'slate'">{{ row.severity }}</Badge>
            </template>
            <template #cell-status="{ row }">
                <Badge :color="row.status === 'closed' ? 'green' : row.status === 'open' ? 'red' : 'yellow'">{{ titleCase(row.status) }}</Badge>
            </template>
            <template #actions="{ row }">
                <button class="btn-ghost !p-1.5" title="Edit" @click="openEdit(row)"><Pencil class="size-4" /></button>
                <button class="btn-ghost !p-1.5 text-red-500" title="Delete" @click="confirm = { open: true, row, loading: false }"><Trash2 class="size-4" /></button>
            </template>
        </DataTable>

        <Modal :open="modal" :title="(editing ? 'Edit ' : 'Report ') + (tab === 'hazards' ? 'H&S Issue' : 'Incident')" @close="modal = false">
            <div class="space-y-4">
                <div>
                    <label class="label">Title</label>
                    <input v-model="form.title" type="text" class="input" />
                    <p v-if="errors.title" class="field-error">{{ errors.title }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-if="tab === 'hazards'">
                        <label class="label">Category</label>
                        <select v-model="form.category" class="input">
                            <option value="hazard">Hazard</option>
                            <option value="near_miss">Near Miss</option>
                            <option value="accident">Accident</option>
                            <option value="fire_safety">Fire Safety</option>
                            <option value="ergonomics">Ergonomics</option>
                        </select>
                    </div>
                    <div v-else>
                        <label class="label">Type</label>
                        <select v-model="form.type" class="input">
                            <option value="accident">Accident</option>
                            <option value="near_miss">Near Miss</option>
                            <option value="property_damage">Property Damage</option>
                            <option value="security">Security</option>
                        </select>
                    </div>
                    <div v-if="tab === 'hazards'">
                        <label class="label">Risk Level</label>
                        <select v-model="form.risk_level" class="input">
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    <div v-else>
                        <label class="label">Severity</label>
                        <select v-model="form.severity" class="input">
                            <option value="minor">Minor</option>
                            <option value="moderate">Moderate</option>
                            <option value="serious">Serious</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                </div>
                <div v-if="tab === 'incidents'" class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label">Location</label><input v-model="form.location" type="text" class="input" /></div>
                    <div><label class="label">Date & Time</label><input v-model="form.incident_date" type="datetime-local" class="input" :max="maxNow()" /><p v-if="errors.incident_date" class="field-error">{{ errors.incident_date }}</p></div>
                </div>
                <div v-else><label class="label">Date</label><input v-model="form.reported_date" type="date" class="input" :max="maxNow().slice(0, 10)" /><p v-if="errors.reported_date" class="field-error">{{ errors.reported_date }}</p></div>
                <div><label class="label">Description</label><textarea v-model="form.description" rows="3" class="input" /></div>
                <template v-if="editing">
                    <div>
                        <label class="label">Status</label>
                        <select v-model="form.status" class="input">
                            <option value="open">Open</option>
                            <option v-if="tab === 'hazards'" value="in_progress">In Progress</option>
                            <option v-else value="investigating">Investigating</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    <div v-if="tab === 'hazards'"><label class="label">Action Taken</label><textarea v-model="form.action_taken" rows="2" class="input" /></div>
                    <div v-else><label class="label">Corrective Action</label><textarea v-model="form.corrective_action" rows="2" class="input" /></div>
                </template>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : editing ? 'Save' : 'Submit' }}</button>
            </template>
        </Modal>

        <ConfirmDialog :open="confirm.open" title="Delete record" danger confirm-label="Delete" :loading="confirm.loading"
            :message="`Delete “${confirm.row?.title ?? ''}”? The audit trail keeps a copy.`"
            @confirm="remove" @close="confirm.open = false" />
    </div>
</template>
