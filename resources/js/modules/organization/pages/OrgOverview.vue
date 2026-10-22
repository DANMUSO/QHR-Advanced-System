<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    Building2, MapPin, Network, Briefcase, Layers, Gift, Contact, CalendarDays, Plus, Pencil, Trash2, Search, Globe, Repeat,
} from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import { formatDate } from '@/lib/format';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Badge from '@/components/ui/Badge.vue';
import Spinner from '@/components/ui/Spinner.vue';
import CompanyPicker from '../components/CompanyPicker.vue';
import CompanyProfile from '../components/CompanyProfile.vue';
import { useCompanyContext } from '../company';

const auth = useAuthStore();
const ui = useUiStore();
const route = useRoute();
const router = useRouter();
const { cfg, companyId } = useCompanyContext();

const summary = ref({});
const tab = ref(route.query.tab || 'profile');
const tabs = computed(() => [
    { key: 'profile', label: 'Company profile', icon: Building2 },
    { key: 'branches', label: 'Branches', icon: MapPin, count: summary.value.branches },
    { key: 'departments', label: 'Departments', icon: Network, count: summary.value.departments },
    { key: 'positions', label: 'Positions', icon: Briefcase, count: summary.value.positions },
    { key: 'levels', label: 'Levels', icon: Layers, count: summary.value.levels },
    { key: 'benefits', label: 'Benefits', icon: Gift, count: summary.value.benefits },
    { key: 'contacts', label: 'Contacts', icon: Contact, count: summary.value.contacts },
    { key: 'holidays', label: 'Holidays', icon: CalendarDays, count: summary.value.holidays },
]);

/** Per-resource table columns and form fields. */
const CONFIG = {
    branches: {
        singular: 'branch', title: 'name',
        columns: [
            { key: 'name', label: 'Branch', sortable: true }, { key: 'location', label: 'Location' }, { key: 'head_name', label: 'Branch head' },
            { key: 'email', label: 'Email' }, { key: 'phone', label: 'Phone' }, { key: 'employees_count', label: 'Staff', align: 'right', sortable: true },
        ],
        fields: [
            { key: 'name', label: 'Branch name', required: true }, { key: 'location', label: 'Location' }, { key: 'address', label: 'Address', wide: true },
            { key: 'email', label: 'Email', type: 'email' }, { key: 'phone', label: 'Phone', type: 'tel' }, { key: 'head_employee_id', label: 'Branch head', type: 'employee', wide: true },
        ],
    },
    departments: {
        singular: 'department', title: 'name',
        columns: [
            { key: 'name', label: 'Department', sortable: true }, { key: 'head_name', label: 'Head of department' },
            { key: 'email', label: 'Email' }, { key: 'phone', label: 'Phone' }, { key: 'employees_count', label: 'Staff', align: 'right', sortable: true },
        ],
        fields: [
            { key: 'name', label: 'Department name', required: true, wide: true }, { key: 'email', label: 'Email', type: 'email' },
            { key: 'phone', label: 'Phone', type: 'tel' }, { key: 'head_employee_id', label: 'Head of department', type: 'employee', wide: true },
        ],
    },
    positions: {
        singular: 'position', title: 'title',
        columns: [
            { key: 'title', label: 'Position', sortable: true }, { key: 'level.name', label: 'Level' },
            { key: 'is_supervisor', label: 'Supervisory', format: (v) => (v ? 'Yes' : 'No') }, { key: 'employees_count', label: 'Staff', align: 'right', sortable: true },
        ],
        fields: [
            { key: 'title', label: 'Position title', required: true, wide: true }, { key: 'employment_level_id', label: 'Employment level', type: 'level' },
            { key: 'is_supervisor', label: 'Supervisory position', type: 'checkbox', hint: 'Staff in this position are treated as supervisors.' },
        ],
    },
    levels: {
        singular: 'level', title: 'name',
        columns: [
            { key: 'name', label: 'Level / grade', sortable: true }, { key: 'probation_months', label: 'Probation', format: (v) => `${v} month${v == 1 ? '' : 's'}` },
            { key: 'probation_review_days', label: 'Review every', format: (v) => (v ? `${v} days` : '—') },
            { key: 'positions_count', label: 'Positions', align: 'right' }, { key: 'employees_count', label: 'Staff', align: 'right', sortable: true },
        ],
        fields: [
            { key: 'name', label: 'Level name', required: true, wide: true },
            { key: 'probation_months', label: 'Probation length (months)', type: 'number', min: 0, max: 24, default: 6 },
            { key: 'probation_review_days', label: 'Probation review every (days)', type: 'number', min: 0, max: 365, default: 30 },
        ],
    },
    benefits: {
        singular: 'benefit', title: 'name',
        columns: [{ key: 'name', label: 'Benefit', sortable: true }, { key: 'description', label: 'Description' }, { key: 'employees_count', label: 'Staff enrolled', align: 'right', sortable: true }],
        fields: [{ key: 'name', label: 'Benefit name', required: true, wide: true }, { key: 'description', label: 'Description', wide: true }],
    },
    contacts: {
        singular: 'contact person', title: 'name',
        columns: [{ key: 'name', label: 'Name', sortable: true }, { key: 'role', label: 'Role' }, { key: 'email', label: 'Email' }, { key: 'phone', label: 'Phone' }],
        fields: [
            { key: 'name', label: 'Full name', required: true }, { key: 'role', label: 'Role', placeholder: 'e.g. HR contact' },
            { key: 'email', label: 'Email', type: 'email' }, { key: 'phone', label: 'Phone', type: 'tel' },
        ],
    },
    holidays: {
        singular: 'holiday', title: 'name',
        columns: [
            { key: 'date', label: 'Date', sortable: true, format: (v, r) => formatDate(v, r.recurring ? { day: 'numeric', month: 'long' } : undefined) },
            { key: 'name', label: 'Holiday', sortable: true }, { key: 'recurring', label: 'Repeats' }, { key: 'is_global', label: 'Applies to' },
        ],
        fields: [
            { key: 'name', label: 'Holiday name', required: true, wide: true }, { key: 'date', label: 'Date', type: 'date', required: true },
            { key: 'recurring', label: 'Same date every year', type: 'checkbox' },
        ],
    },
};

// The profile tab has no table config; fall back so the always-rendered editor/confirm bindings never read from undefined.
const conf = computed(() => CONFIG[tab.value] ?? { singular: '', title: 'name', columns: [], fields: [] });
const rows = ref([]);
const loading = ref(false);
const search = ref('');
const year = ref(new Date().getFullYear());
const employees = ref([]);
const levels = ref([]);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return rows.value;
    return rows.value.filter((r) => Object.values(r).some((v) => typeof v === 'string' && v.toLowerCase().includes(q)));
});

async function loadSummary() {
    try { summary.value = (await api.get('/organization/summary', cfg())).data; } catch { summary.value = {}; }
}

async function load() {
    if (tab.value === 'profile') return;
    loading.value = true;
    try {
        const params = tab.value === 'holidays' ? { year: year.value } : {};
        rows.value = (await api.get(`/organization/${tab.value}`, cfg({ params }))).data.data;
    } catch (e) {
        rows.value = [];
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

async function loadLookups() {
    const { data } = await api.get('/lookups', cfg({ params: { only: 'employees,levels' } }));
    employees.value = data.employees ?? [];
    levels.value = data.levels ?? [];
}

// ---- editor ---------------------------------------------------------------
const editor = reactive({ open: false, id: null, form: {}, errors: {}, saving: false });

async function openEditor(row = null) {
    if (conf.value.fields.some((f) => ['employee', 'level'].includes(f.type))) loadLookups();
    const form = {};
    for (const f of conf.value.fields) form[f.key] = row ? row[f.key] ?? (f.type === 'checkbox' ? false : '') : f.default ?? (f.type === 'checkbox' ? false : '');
    if (tab.value === 'holidays') form.is_global = row ? row.is_global : false;
    Object.assign(editor, { open: true, id: row?.id ?? null, form, errors: {}, saving: false });
}

async function save() {
    editor.saving = true;
    editor.errors = {};
    const payload = { ...editor.form };
    for (const f of conf.value.fields) if (payload[f.key] === '' && f.type !== 'checkbox') payload[f.key] = null;
    if (companyId.value) payload.company_id = companyId.value;
    try {
        const { data } = editor.id
            ? await api.put(`/organization/${tab.value}/${editor.id}`, payload, cfg())
            : await api.post(`/organization/${tab.value}`, payload, cfg());
        ui.toast(data.message);
        editor.open = false;
        load();
        loadSummary();
    } catch (e) {
        editor.errors = fieldErrors(e);
        if (!Object.keys(editor.errors).length) ui.toast(errorMessage(e), 'error');
    } finally {
        editor.saving = false;
    }
}

// ---- delete ---------------------------------------------------------------
const del = reactive({ row: null, loading: false, error: '' });
async function remove() {
    del.loading = true;
    del.error = '';
    try {
        const { data } = await api.delete(`/organization/${tab.value}/${del.row.id}`, cfg());
        ui.toast(data.message);
        del.row = null;
        load();
        loadSummary();
    } catch (e) {
        del.error = errorMessage(e);
    } finally {
        del.loading = false;
    }
}

function canEdit(row) {
    return !(tab.value === 'holidays' && row.is_global && !auth.isSuperAdmin);
}

function switchTab(t) {
    tab.value = t;
    search.value = '';
    rows.value = [];
    router.replace({ query: { ...route.query, tab: t } });
    load();
}

function quickAdd(t) {
    switchTab(t);
    openEditor();
}

function companyChanged() {
    loadSummary();
    load();
    profileKey.value++;
}
const profileKey = ref(0);

watch(year, load);
onMounted(() => { loadSummary(); load(); });
</script>

<template>
    <div>
        <PageHeader title="Organisation structure" subtitle="Company profile, branches, departments, positions, levels, benefits, contacts and holidays.">
            <CompanyPicker @change="companyChanged" />
            <button v-if="tab !== 'profile'" class="btn-primary" @click="openEditor()"><Plus class="size-4" /> Add {{ conf.singular }}</button>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <CompanyProfile v-if="tab === 'profile' && companyId" :key="profileKey + '-' + companyId" :company-id="companyId" :summary="summary" @quick-add="quickAdd" />
        <div v-else-if="tab === 'profile' && !companyId" class="card mt-6 p-10 text-center">
            <Building2 class="mx-auto mb-3 size-10 text-slate-300" />
            <p class="font-medium text-slate-600 dark:text-slate-300">Select a company to view its profile</p>
            <p class="mt-1 text-sm text-slate-400">Use the company picker in the top bar or the selector above.</p>
            <div class="mt-4 flex justify-center"><CompanyPicker @change="companyChanged" /></div>
        </div>

        <DataTable
            v-else
            :columns="conf.columns"
            :rows="filtered"
            :loading="loading"
            :export-name="tab"
            :empty-title="search.trim() ? `No ${tab} match “${search.trim()}”` : `No ${tab} yet`"
            :empty-description="search.trim() ? 'Try a different search term or clear the search.' : `Add your first ${conf.singular} to get started.`"
        >
            <template #toolbar>
                <div class="relative w-full sm:w-72">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input v-model="search" class="input !py-2 !pl-9" :placeholder="`Search ${tab}…`" :aria-label="`Search ${tab}`" />
                </div>
                <select v-if="tab === 'holidays'" v-model="year" class="input !w-32 !py-2">
                    <option v-for="y in [year - 1, year, year + 1].filter((v, i, a) => a.indexOf(v) === i)" :key="y" :value="y">{{ y }}</option>
                </select>
            </template>
            <template #cell-name="{ row, value }">
                <span class="font-medium text-slate-900 dark:text-white">{{ value }}</span>
            </template>
            <template #cell-title="{ row, value }">
                <span class="font-medium text-slate-900 dark:text-white">{{ value }}</span>
                <Badge v-if="row.is_supervisor" tone="brand" class="ml-2">Supervisor</Badge>
            </template>
            <template #cell-is_supervisor="{ value }">
                <span :class="value ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'">{{ value ? 'Yes' : 'No' }}</span>
            </template>
            <template #cell-head_name="{ value }">
                <span v-if="value">{{ value }}</span><span v-else class="text-slate-400">Not set</span>
            </template>
            <template #cell-recurring="{ value }">
                <Badge v-if="value" tone="sky"><Repeat class="size-3" /> Every year</Badge><span v-else class="text-slate-400">Once</span>
            </template>
            <template #cell-is_global="{ value }">
                <Badge v-if="value" tone="amber"><Globe class="size-3" /> All companies</Badge><Badge v-else tone="slate">This company</Badge>
            </template>
            <template #actions="{ row }">
                <template v-if="canEdit(row)">
                    <button class="btn-ghost !p-1.5" title="Edit" @click="openEditor(row)"><Pencil class="size-4" /></button>
                    <button class="btn-ghost !p-1.5 !text-rose-600" title="Delete" @click="del.row = row; del.error = ''"><Trash2 class="size-4" /></button>
                </template>
                <span v-else class="text-xs text-slate-400">Managed by platform</span>
            </template>
        </DataTable>

        <Modal :open="editor.open" :title="(editor.id ? 'Edit ' : 'Add ') + conf.singular" size="lg" @close="editor.open = false">
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
                <div v-for="f in conf.fields" :key="f.key" :class="(f.wide || f.type === 'checkbox') && 'sm:col-span-2'">
                    <label v-if="f.type === 'checkbox'" class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                        <input v-model="editor.form[f.key]" type="checkbox" class="mt-0.5 rounded text-brand-600" />
                        <span>
                            <span class="block text-sm font-medium text-slate-800 dark:text-slate-200">{{ f.label }}</span>
                            <span v-if="f.hint" class="block text-xs text-slate-500">{{ f.hint }}</span>
                        </span>
                    </label>
                    <template v-else>
                        <label class="label">{{ f.label }} <span v-if="f.required" class="text-rose-500">*</span></label>
                        <select v-if="f.type === 'employee'" v-model="editor.form[f.key]" class="input">
                            <option :value="null">— None —</option>
                            <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}{{ e.employee_number ? ` (${e.employee_number})` : '' }}</option>
                        </select>
                        <select v-else-if="f.type === 'level'" v-model="editor.form[f.key]" class="input">
                            <option :value="null">— None —</option>
                            <option v-for="l in levels" :key="l.id" :value="l.id">{{ l.name }}</option>
                        </select>
                        <input v-else v-model="editor.form[f.key]" :type="f.type || 'text'" :min="f.min" :max="f.max" :placeholder="f.placeholder" class="input" />
                    </template>
                    <p v-if="editor.errors[f.key]" class="field-error">{{ editor.errors[f.key] }}</p>
                </div>
                <label v-if="tab === 'holidays' && auth.isSuperAdmin && !editor.id" class="flex items-center gap-2 text-sm sm:col-span-2">
                    <input v-model="editor.form.is_global" type="checkbox" class="rounded text-brand-600" /> Applies to all companies (public holiday)
                </label>
                <p v-if="editor.errors.company_id" class="field-error sm:col-span-2">{{ editor.errors.company_id }}</p>
                <button type="submit" class="hidden" />
            </form>
            <template #footer>
                <button class="btn-secondary" @click="editor.open = false">Cancel</button>
                <button class="btn-primary" :disabled="editor.saving" @click="save"><Spinner v-if="editor.saving" /> {{ editor.id ? 'Save changes' : 'Create' }}</button>
            </template>
        </Modal>

        <ConfirmDialog
            :open="!!del.row"
            :title="`Delete ${conf?.singular}`"
            :message="`Delete “${del.row?.[conf?.title]}”? This cannot be undone.`"
            confirm-label="Delete"
            danger
            :loading="del.loading"
            :error="del.error"
            @confirm="remove"
            @close="del.row = null"
        />
    </div>
</template>
