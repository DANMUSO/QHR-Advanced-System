<script setup>
import { useFormErrors } from '@/lib/formErrors';
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Plus, Download, Pencil, Trash2, FileText } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { openServerFile } from '@/lib/export';
import { useUiStore } from '@/stores/ui';
import Badge from '@/components/ui/Badge.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';
import LookupSelect from '@/components/ui/LookupSelect.vue';

const ui = useUiStore();
const route = useRoute();
const router = useRouter();
const tab = ref('disciplinary');
const tabs = [
    { key: 'disciplinary', label: 'Disciplinary Cases' },
    { key: 'grievances', label: 'Grievances' },
    { key: 'warnings', label: 'Warning Letters' },
];

const rows = ref([]);
const paginator = ref(null);
const loading = ref(false);
const modal = ref(false);
const form = ref({});
const errors = useFormErrors(() => form.value);
const saving = ref(false);

// column definitions per tab
const colMap = {
    disciplinary: [
        { key: 'case_number', label: 'Case #' },
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'offence', label: 'Offence' },
        { key: 'pip_id', label: 'Source', export: false },
        { key: 'reported_date', label: 'Reported' },
        { key: 'outcome', label: 'Outcome' },
        { key: 'status', label: 'Status' },
    ],
    grievances: [
        { key: 'reference', label: 'Ref #' },
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'subject', label: 'Subject' },
        { key: 'category', label: 'Category' },
        { key: 'filed_date', label: 'Filed' },
        { key: 'assigned_to', label: 'Assigned To' },
        { key: 'status', label: 'Status' },
    ],
    warnings: [
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'level', label: 'Level' },
        { key: 'reason', label: 'Reason' },
        { key: 'issued_date', label: 'Issued' },
        { key: 'expiry_date', label: 'Expires' },
        { key: 'issued_by', label: 'Issued By' },
    ],
};

const statusColors = {
    disciplinary: { open: 'blue', under_investigation: 'yellow', hearing_scheduled: 'purple', closed: 'green', appealed: 'red' },
    grievances: { open: 'blue', investigating: 'yellow', mediation: 'purple', resolved: 'green', escalated: 'red' },
};
const outcomeColors = { warning: 'yellow', final_warning: 'red', suspension: 'red', dismissal: 'red', exonerated: 'green', pending: 'slate' };
const levelColors = { verbal: 'yellow', written: 'orange', final: 'red' };

const defaultForms = {
    disciplinary: { employee_id: '', offence: '', reported_date: '', description: '' },
    grievances: { employee_id: '', category: 'workplace', subject: '', description: '', filed_date: '' },
    warnings: { employee_id: '', level: 'written', reason: '', issued_date: '', expiry_months: 6, generate_letter: true },
};

async function load(page = 1) {
    loading.value = true;
    rows.value = [];
    try {
        const { data } = await api.get(`/compliance/${tab.value}`, { params: { page } });
        rows.value = data.data ?? data;
        if (data.current_page) paginator.value = { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from, to: data.to };
        else paginator.value = null;
    } finally { loading.value = false; }
}

function switchTab(t) { tab.value = t; router.replace({ query: { ...route.query, tab: t } }); load(); }

async function generateLetter(row) {
    try {
        const { data } = await api.post(`/compliance/warnings/${row.id}/letter`);
        ui.toast(data.message, 'success');
        if (data.pdf_url) openServerFile(data.pdf_url);
        await load(paginator.value?.current_page ?? 1);
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
}

const editing = ref(null);
const confirm = ref({ open: false, row: null, loading: false });

function openCreate() {
    editing.value = null; errors.value = {};
    form.value = { ...defaultForms[tab.value] };
    modal.value = true;
}

function openEdit(row) {
    editing.value = row; errors.value = {};
    form.value = { ...row, employee_id: row.employee_id };
    modal.value = true;
}

async function remove() {
    confirm.value.loading = true;
    try {
        await api.delete(`/compliance/${tab.value}/${confirm.value.row.id}`);
        ui.toast('Deleted.', 'success');
        confirm.value.open = false;
        await load(paginator.value?.current_page ?? 1);
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally { confirm.value.loading = false; }
}

async function save() {
    saving.value = true; errors.value = {};
    try {
        const { data } = editing.value
            ? await api.put(`/compliance/${tab.value}/${editing.value.id}`, form.value)
            : await api.post(`/compliance/${tab.value}`, form.value);
        ui.toast('Saved.', 'success');
        if (tab.value === 'warnings' && !editing.value && data.pdf_url) openServerFile(data.pdf_url);
        modal.value = false;
        await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally { saving.value = false; }
}

onMounted(() => {
    if (tabs.some((t) => t.key === route.query.tab)) tab.value = route.query.tab;
    load();
});
</script>

<template>
    <div>
        <PageHeader title="Compliance Cases">
            <template #actions>
                <button class="btn-primary" @click="openCreate">
                    <Plus class="size-4" />
                    {{ tab === 'disciplinary' ? 'Open Case' : tab === 'grievances' ? 'File Grievance' : 'Issue Warning' }}
                </button>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <DataTable v-else :columns="colMap[tab]" :rows="rows" :loading="false" :paginator="paginator" :export-name="tab" @page="load">
            <!-- disciplinary badges -->
            <template #cell-outcome="{ row }">
                <Badge :color="outcomeColors[row.outcome] ?? 'slate'">{{ row.outcome ?? 'pending' }}</Badge>
            </template>
            <!-- shared status -->
            <template #cell-status="{ row }">
                <Badge :color="(statusColors[tab] ?? {})[row.status] ?? 'slate'">{{ row.status }}</Badge>
            </template>
            <template #cell-pip_id="{ row }">
                <Badge v-if="row.pip_id" color="purple">Escalated PIP</Badge>
                <span v-else class="text-xs text-slate-400">Manual</span>
            </template>
            <template #cell-employee_name="{ row }">
                <span :class="row.anonymous ? 'italic text-slate-500 dark:text-slate-400' : ''">{{ row.employee_name ?? '—' }}</span>
            </template>
            <!-- warning level -->
            <template #cell-level="{ row }">
                <Badge :color="levelColors[row.level] ?? 'slate'">{{ row.level }}</Badge>
            </template>
            <!-- warning download -->
            <template #actions="{ row }">
                <button v-if="tab === 'warnings' && row.pdf_url" class="btn-ghost !p-1.5" title="Download warning letter" @click="openServerFile(row.pdf_url)">
                    <Download class="size-4" />
                </button>
                <button v-else-if="tab === 'warnings'" class="btn-ghost !p-1.5" title="Generate warning letter" @click="generateLetter(row)">
                    <FileText class="size-4" />
                </button>
                <button class="btn-ghost !p-1.5" title="Edit" @click="openEdit(row)"><Pencil class="size-4" /></button>
                <button class="btn-ghost !p-1.5 text-red-500" title="Delete" @click="confirm = { open: true, row, loading: false }"><Trash2 class="size-4" /></button>
            </template>
        </DataTable>

        <!-- Create modal -->
        <Modal :open="modal" :title="editing ? 'Edit Record' : tab === 'disciplinary' ? 'Open Disciplinary Case' : tab === 'grievances' ? 'File Grievance' : 'Issue Warning Letter'" @close="modal = false">
            <div class="space-y-4">
                <div v-if="!(editing && tab === 'grievances' && editing.anonymous)">
                    <label class="label">Employee</label>
                    <LookupSelect v-model="form.employee_id" kind="employees" placeholder="Choose an employee" :error="!!errors?.employee_id" />
                    <p v-if="errors.employee_id" class="mt-1 text-xs text-red-500">{{ errors.employee_id }}</p>
                </div>

                <!-- disciplinary -->
                <template v-if="tab === 'disciplinary'">
                    <div>
                        <label class="label">Offence / Allegation</label>
                        <input v-model="form.offence" type="text" class="input" />
                        <p v-if="errors.offence" class="mt-1 text-xs text-red-500">{{ errors.offence }}</p>
                    </div>
                    <div><label class="label">Date Reported</label><input v-model="form.reported_date" type="date" class="input" /></div>
                    <div><label class="label">Description</label><textarea v-model="form.description" rows="3" class="input" /></div>
                    <template v-if="editing">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="label">Status</label>
                                <select v-model="form.status" class="input">
                                    <option value="open">Open</option>
                                    <option value="under_investigation">Under Investigation</option>
                                    <option value="hearing_scheduled">Hearing Scheduled</option>
                                    <option value="closed">Closed</option>
                                    <option value="appealed">Appealed</option>
                                </select>
                            </div>
                            <div><label class="label">Hearing Date</label><input v-model="form.hearing_date" type="date" class="input" /></div>
                        </div>
                        <div>
                            <label class="label">Outcome</label>
                            <select v-model="form.outcome" class="input">
                                <option :value="null">Pending</option>
                                <option value="warning">Warning</option>
                                <option value="final_warning">Final Warning</option>
                                <option value="suspension">Suspension</option>
                                <option value="dismissal">Dismissal</option>
                                <option value="exonerated">Exonerated</option>
                            </select>
                        </div>
                        <div><label class="label">Outcome Notes</label><textarea v-model="form.outcome_notes" rows="2" class="input" /></div>
                    </template>
                </template>

                <!-- grievances -->
                <template v-else-if="tab === 'grievances'">
                    <div>
                        <label class="label">Category</label>
                        <select v-model="form.category" class="input">
                            <option value="workplace">Workplace Conduct</option>
                            <option value="harassment">Harassment</option>
                            <option value="discrimination">Discrimination</option>
                            <option value="pay">Pay Dispute</option>
                            <option value="working_conditions">Working Conditions</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <p v-if="editing?.anonymous" class="rounded-lg bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">Filed anonymously. The employee is notified of status changes without their identity being shown.</p>
                    <div><label class="label">Subject</label><input v-model="form.subject" type="text" class="input" maxlength="200" /></div>
                    <div><label class="label">Date Filed</label><input v-model="form.filed_date" type="date" class="input" /></div>
                    <div>
                        <label class="label">Description</label>
                        <textarea v-model="form.description" rows="3" class="input" />
                        <p v-if="errors.description" class="mt-1 text-xs text-red-500">{{ errors.description }}</p>
                    </div>
                    <template v-if="editing">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="label">Status</label>
                                <select v-model="form.status" class="input">
                                    <option value="open">Open</option>
                                    <option value="investigating">Investigating</option>
                                    <option value="mediation">Mediation</option>
                                    <option value="resolved">Resolved</option>
                                    <option value="escalated">Escalated</option>
                                </select>
                            </div>
                            <div><label class="label">Assigned To</label><LookupSelect v-model="form.assigned_to_employee_id" kind="employees" placeholder="Choose an investigator" /></div>
                        </div>
                        <div><label class="label">Resolution</label><textarea v-model="form.resolution" rows="2" class="input" /></div>
                    </template>
                </template>

                <!-- warnings -->
                <template v-else>
                    <div>
                        <label class="label">Warning Level</label>
                        <select v-model="form.level" class="input">
                            <option value="verbal">Verbal Warning</option>
                            <option value="written">Written Warning</option>
                            <option value="final">Final Written Warning</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Reason</label>
                        <textarea v-model="form.reason" rows="3" class="input" />
                        <p v-if="errors.reason" class="mt-1 text-xs text-red-500">{{ errors.reason }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="label">Issue Date</label><input v-model="form.issued_date" type="date" class="input" /></div>
                        <div v-if="!editing"><label class="label">Validity (months)</label><input v-model="form.expiry_months" type="number" min="1" class="input" /></div>
                        <div v-else><label class="label">Expiry Date</label><input v-model="form.expiry_date" type="date" class="input" /></div>
                    </div>
                    <label v-if="!editing" class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input v-model="form.generate_letter" type="checkbox" class="rounded border-slate-300 dark:border-slate-600" />
                        Generate the warning letter (PDF) and file it with the employee's letters
                    </label>
                </template>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>

        <ConfirmDialog :open="confirm.open" title="Delete record" danger confirm-label="Delete" :loading="confirm.loading"
            message="Delete this record? It is removed from the register (the audit trail keeps a copy)."
            @confirm="remove" @close="confirm.open = false" />
    </div>
</template>
