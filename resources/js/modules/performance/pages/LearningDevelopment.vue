<script setup>
import { useFormErrors } from '@/lib/formErrors';
import { ref, onMounted } from 'vue';
import { Plus, BookOpen, Users } from 'lucide-vue-next';
import { label } from '@/lib/format';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';
import LookupSelect from '@/components/ui/LookupSelect.vue';

const ui = useUiStore();
const tab = ref('catalogue');
const tabs = [
    { key: 'catalogue', label: 'Training Catalogue' },
    { key: 'skills', label: 'Skills Matrix' },
    { key: 'certifications', label: 'Certifications' },
];

const rows = ref([]);
const loading = ref(false);
const modal = ref(false);
const form = ref({});
const errors = useFormErrors(() => form.value);
const saving = ref(false);

// skills matrix specific
const matrix = ref({ employees: [], skills: [] });
const deptFilter = ref('');

const catalogueCols = [
    { key: 'name', label: 'Course Name', sortable: true },
    { key: 'category', label: 'Category' },
    { key: 'provider', label: 'Provider' },
    { key: 'duration_hours', label: 'Hours', align: 'right' },
    { key: 'delivery', label: 'Delivery' },
    { key: 'enrolled', label: 'Enrolled', align: 'right' },
];
const certCols = [
    { key: 'employee_name', label: 'Employee', sortable: true },
    { key: 'certification_name', label: 'Certification' },
    { key: 'issuing_body', label: 'Issuing Body' },
    { key: 'issue_date', label: 'Issue Date' },
    { key: 'expiry_date', label: 'Expiry Date' },
    { key: 'status', label: 'Status' },
];

function levelColor(level) {
    if (!level) return 'bg-slate-100 dark:bg-slate-800';
    if (level >= 4) return 'bg-green-500';
    if (level >= 3) return 'bg-blue-400';
    if (level >= 2) return 'bg-yellow-400';
    return 'bg-red-400';
}

async function load() {
    loading.value = true;
    try {
        if (tab.value === 'catalogue') {
            const { data } = await api.get('/learning/catalogue');
            rows.value = data.data ?? data;
        } else if (tab.value === 'skills') {
            const { data } = await api.get('/learning/skills', { params: { department_id: deptFilter.value } });
            matrix.value = data.data ?? data;
        } else {
            const { data } = await api.get('/learning/certifications');
            rows.value = data.data ?? data;
        }
    } finally { loading.value = false; }
}

function switchTab(t) { tab.value = t; load(); }

async function save() {
    saving.value = true; errors.value = {};
    const endpoint = tab.value === 'catalogue' ? '/learning/catalogue' : '/learning/certifications';
    try {
        await api.post(endpoint, form.value);
        ui.toast('Saved.'); modal.value = false; await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally { saving.value = false; }
}

// ── Enrol staff in a course (HR / L&D) ─────────────────────
const enrol = ref({ open: false, course: null, rows: [], loading: false, employeeId: '', saving: false, error: '' });
async function openEnrol(course) {
    enrol.value = { open: true, course, rows: [], loading: true, employeeId: '', saving: false, error: '' };
    try { const { data } = await api.get(`/learning/catalogue/${course.id}/enrollments`); enrol.value.rows = data.data ?? []; }
    catch (e) { enrol.value.error = errorMessage(e); }
    finally { enrol.value.loading = false; }
}
async function addEnrolment() {
    if (!enrol.value.employeeId) return;
    enrol.value.saving = true; enrol.value.error = '';
    try {
        const { data } = await api.post(`/learning/catalogue/${enrol.value.course.id}/enrollments`, { employee_ids: [enrol.value.employeeId] });
        ui.toast(data.message ?? 'Enrolled.', 'success');
        await openEnrol(enrol.value.course);
        await load();
    } catch (e) { enrol.value.error = errorMessage(e); }
    finally { enrol.value.saving = false; }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Learning & Development">
            <template #actions>
                <template v-if="tab === 'catalogue'">
                    <button class="btn-primary" @click="modal = true; form = { name: '', category: '', provider: '', duration_hours: '', delivery: 'classroom', description: '' }; errors = {}">
                        <Plus class="size-4" /> Add Course
                    </button>
                </template>
                <template v-else-if="tab === 'skills'">
                    <input v-model="deptFilter" type="text" class="input w-48" placeholder="Department ID (optional)" @change="load" />
                </template>
                <template v-else>
                    <button class="btn-primary" @click="modal = true; form = { employee_id: '', certification_name: '', issuing_body: '', issue_date: '', expiry_date: '' }; errors = {}">
                        <Plus class="size-4" /> Add Certification
                    </button>
                </template>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>

        <!-- Catalogue -->
        <DataTable v-else-if="tab === 'catalogue'" :columns="catalogueCols" :rows="rows" :loading="false">
            <template #cell-delivery="{ row }">
                <Badge :color="row.delivery === 'online' ? 'blue' : row.delivery === 'classroom' ? 'green' : 'yellow'">{{ row.delivery }}</Badge>
            </template>
            <template #actions="{ row }">
                <button class="btn-ghost !p-1.5" title="Enrolments" @click="openEnrol(row)"><Users class="size-4" /></button>
            </template>
        </DataTable>

        <!-- Skills Matrix -->
        <template v-else-if="tab === 'skills'">
            <div class="card overflow-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800/50">
                        <tr>
                            <th class="sticky left-0 bg-slate-50 px-4 py-3 text-left dark:bg-slate-800/50">Employee</th>
                            <th v-for="skill in matrix.skills" :key="skill.id" class="px-3 py-3 text-center text-xs">{{ skill.name }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="emp in matrix.employees" :key="emp.id">
                            <td class="sticky left-0 bg-white px-4 py-3 font-medium dark:bg-slate-900">{{ emp.name }}</td>
                            <td v-for="skill in matrix.skills" :key="skill.id" class="px-3 py-3 text-center">
                                <div class="mx-auto size-6 rounded" :class="levelColor(emp.skills?.[skill.id])" :title="`Level ${emp.skills?.[skill.id] ?? 0}`" />
                            </td>
                        </tr>
                        <tr v-if="!matrix.employees?.length">
                            <td :colspan="(matrix.skills?.length ?? 0) + 1" class="px-4 py-10 text-center text-slate-400">No data available.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-3 flex flex-wrap gap-3 text-xs text-slate-500">
                <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-green-500" /> Expert (4-5)</span>
                <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-blue-400" /> Proficient (3)</span>
                <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-yellow-400" /> Developing (2)</span>
                <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-red-400" /> Beginner (1)</span>
                <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-slate-100 dark:bg-slate-800" /> Not assessed</span>
            </div>
        </template>

        <!-- Certifications -->
        <DataTable v-else-if="tab === 'certifications'" :columns="certCols" :rows="rows" :loading="false" export-name="certifications">
            <template #cell-status="{ row }">
                <Badge :color="row.status === 'valid' ? 'green' : row.status === 'expiring_soon' ? 'yellow' : 'red'">{{ row.status ?? 'valid' }}</Badge>
            </template>
        </DataTable>

        <!-- Modal: Catalogue or Certifications -->
        <Modal :open="modal" :title="tab === 'catalogue' ? 'Add Course' : 'Add Certification'" @close="modal = false">
            <div class="space-y-4">
                <template v-if="tab === 'catalogue'">
                    <div><label class="label">Course Name</label><input v-model="form.name" type="text" class="input" /><p v-if="errors.name" class="mt-1 text-xs text-red-500">{{ errors.name }}</p></div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="label">Category</label><input v-model="form.category" type="text" class="input" /></div>
                        <div><label class="label">Provider</label><input v-model="form.provider" type="text" class="input" /></div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="label">Duration (hours)</label><input v-model="form.duration_hours" type="number" class="input" /></div>
                        <div>
                            <label class="label">Delivery</label>
                            <select v-model="form.delivery" class="input">
                                <option value="classroom">Classroom</option>
                                <option value="online">Online</option>
                                <option value="blended">Blended</option>
                            </select>
                        </div>
                    </div>
                    <div><label class="label">Description</label><textarea v-model="form.description" rows="3" class="input" /></div>
                </template>
                <template v-else>
                    <div><label class="label">Employee</label><LookupSelect v-model="form.employee_id" kind="employees" placeholder="Choose an employee" :error="!!errors?.employee_id" /><p v-if="errors.employee_id" class="mt-1 text-xs text-red-500">{{ errors.employee_id }}</p></div>
                    <div><label class="label">Certification Name</label><input v-model="form.certification_name" type="text" class="input" /><p v-if="errors.certification_name" class="mt-1 text-xs text-red-500">{{ errors.certification_name }}</p></div>
                    <div><label class="label">Issuing Body</label><input v-model="form.issuing_body" type="text" class="input" /></div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="label">Issue Date</label><input v-model="form.issue_date" type="date" class="input" /></div>
                        <div><label class="label">Expiry Date</label><input v-model="form.expiry_date" type="date" class="input" /></div>
                    </div>
                </template>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>
        <Modal :open="enrol.open" :title="`Enrolments – ${enrol.course?.title ?? enrol.course?.name ?? ''}`" size="lg" @close="enrol.open = false">
            <div class="space-y-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <label class="label">Add employee</label>
                        <LookupSelect v-model="enrol.employeeId" kind="employees" placeholder="Search staff…" :exclude="enrol.rows.map((r) => r.employee_id)" />
                    </div>
                    <button class="btn-primary" :disabled="!enrol.employeeId || enrol.saving" @click="addEnrolment"><Plus class="size-4" /> Enrol</button>
                </div>
                <p v-if="enrol.error" class="text-sm text-red-600">{{ enrol.error }}</p>
                <div v-if="enrol.loading" class="flex justify-center py-6"><Spinner /></div>
                <p v-else-if="!enrol.rows.length" class="py-6 text-center text-sm text-slate-400">Nobody is enrolled yet.</p>
                <ul v-else class="divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="r in enrol.rows" :key="r.id" class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span class="font-medium text-slate-800 dark:text-slate-100">{{ r.employee?.name ?? '—' }}</span>
                        <span class="flex items-center gap-2 text-xs text-slate-500">{{ r.progress ?? 0 }}% <Badge>{{ label(r.status) }}</Badge></span>
                    </li>
                </ul>
            </div>
        </Modal>
    </div>
</template>
