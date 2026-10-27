<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { Search, UserPlus, Download, Network, BarChart3, Mail, KeyRound, RotateCcw, FolderKanban, ShieldCheck, X } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { useAuthStore } from '@/stores/auth';
import swal from '@/lib/swal';
import { formatDate } from '@/lib/format';
import DataTable from '@/components/ui/DataTable.vue';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import ReinstateModal from '../components/ReinstateModal.vue';
import EmployeeForm from './EmployeeForm.vue';
import { tone, label, leavingLabel, CONTRACT_TYPES, downloadFile, paginatorOf, cleanParams } from '../lib';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const views = {
    directory: { label: 'Directory', params: { status: 'active,suspended' } },
    not_activated: { label: 'Not activated', params: { status: 'active,suspended', account: 'not_activated' }, stat: 'not_activated', can: 'employees.update' },
    without_login: { label: 'Without login', params: { status: 'active,suspended', account: 'none' }, stat: 'without_login', can: 'employees.update' },
    without_supervisor: { label: 'Without supervisor', params: { status: 'active,suspended', supervisor: 'none' }, stat: 'without_supervisor' },
    supervisors: { label: 'Supervisors', params: { status: 'active', supervisor_role: '1' }, can: 'leave.reports' },
    exited: { label: 'Exited', params: { status: 'exited', sort: 'exit_date', dir: 'desc' }, stat: 'exited' },
    transferred: { label: 'Transferred', params: { status: 'transferred' }, stat: 'transferred' },
};
const visibleViews = computed(() => Object.entries(views).filter(([, v]) => !v.can || auth.can(v.can)));

const view = ref('directory');
const rows = ref([]);
const paginator = ref(null);
const loading = ref(false);
const stats = ref({});
const lookups = ref({ branches: [], departments: [], positions: [] });
const selected = ref(new Set());
const busy = ref(false);
const reinstateFor = ref(null);
const employeeModal = ref({ open: false, id: null });

const filters = reactive({ search: '', branch_id: '', department_id: '', position_id: '', contract_type: '', gender: '' });
const sort = reactive({ sort: 'name', dir: 'asc' });
const page = ref(1);

const selectable = computed(() => ['not_activated', 'without_login'].includes(view.value));

const columns = computed(() => {
    const cols = [
        ...(selectable.value ? [{ key: 'select', label: '', export: false }] : []),
        { key: 'full_name', label: 'Employee', sortable: true, format: (v, r) => `${r.full_name} (${r.employee_number ?? '—'})` },
        { key: 'department', label: 'Department', sortable: true, format: (v) => v?.name ?? '—' },
        { key: 'position', label: 'Position', sortable: true, format: (v) => v?.title ?? '—' },
        { key: 'branch', label: 'Branch', sortable: true, format: (v) => v?.name ?? '—' },
    ];
    if (view.value === 'exited') {
        cols.push({ key: 'exit.exit_date', label: 'Exit date', format: (v) => formatDate(v) }, { key: 'exit.reason_label', label: 'Reason' });
    } else if (view.value === 'supervisors') {
        cols.push(
            { key: 'direct_reports_count', label: 'Direct reports', align: 'right' },
            { key: 'pending_leave_count', label: 'Pending leave', align: 'right' },
            { key: 'rejected_leave_count', label: 'Rejected leave', align: 'right' },
        );
    } else {
        cols.push(
            { key: 'phone', label: 'Phone' },
            { key: 'contract_type', label: 'Contract', format: label },
            { key: 'date_of_joining', label: 'Joined', sortable: true, format: (v) => formatDate(v) },
        );
        if (view.value === 'not_activated' || view.value === 'without_login') cols.push({ key: 'email', label: 'Email' });
    }
    cols.push({ key: 'status', label: 'Status', format: label });
    return cols;
});

const query = computed(() => cleanParams({ ...views[view.value].params, ...filters, sort: views[view.value].params.sort ?? sort.sort, dir: views[view.value].params.dir ?? sort.dir }));

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/employees', { params: { ...query.value, page: page.value, per_page: 25 } });
        rows.value = data.data;
        paginator.value = paginatorOf(data);
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

async function loadStats() {
    try { stats.value = (await api.get('/employees/stats')).data; } catch { /* non-critical */ }
}

let timer;
watch(filters, () => { clearTimeout(timer); timer = setTimeout(() => { page.value = 1; load(); }, 300); }, { deep: true });
watch(view, () => { page.value = 1; selected.value = new Set(); load(); });

function onSort({ key, dir }) {
    sort.sort = { full_name: 'name' }[key] ?? key;
    sort.dir = dir;
    load();
}

function toggle(id) {
    const s = new Set(selected.value);
    s.has(id) ? s.delete(id) : s.add(id);
    selected.value = s;
}
function toggleAll() {
    selected.value = selected.value.size === rows.value.length ? new Set() : new Set(rows.value.map((r) => r.id));
}

async function bulk(action) {
    if (!selected.value.size) return;
    busy.value = true;
    try {
        const url = action === 'create' ? '/employees/accounts/bulk-create' : '/employees/accounts/bulk-resend';
        const { data } = await api.post(url, { employee_ids: [...selected.value] });
        swal.toast(data.message, data.failed?.length ? 'warning' : 'success');
        data.failed?.slice(0, 3).forEach((f) => swal.toast(`${f.name}: ${f.error}`, 'warning'));
        selected.value = new Set();
        load(); loadStats();
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
    } finally { busy.value = false; }
}

// Row action in progress (creating a login also sends the activation email, which can take a moment).
const rowBusy = ref(null);
async function single(row, action) {
    if (rowBusy.value) return;
    if (action === 'create' && !row.email) { swal.toast(`Add a work email for ${row.full_name} before creating a login.`, 'warning'); return; }
    rowBusy.value = row.id;
    try {
        const { data } = await api.post(`/employees/${row.id}/account${action === 'resend' ? '/resend' : ''}`);
        swal.toast(data.message ?? (action === 'create' ? 'Login created.' : 'Activation email sent.'), 'success');
        selected.value.delete(row.id);
        await Promise.all([load(), loadStats()]);
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { rowBusy.value = null; }
}

async function exportCsv() {
    try {
        await downloadFile('/employees/export', query.value, 'employees.csv');
    } catch (e) { swal.toast(errorMessage(e, 'Export failed.'), 'error'); }
}

function resetFilters() {
    Object.assign(filters, { search: '', branch_id: '', department_id: '', position_id: '', contract_type: '', gender: '' });
}
const hasFilters = computed(() => Object.values(filters).some(Boolean));

onMounted(async () => {
    load();
    loadStats();
    try { lookups.value = (await api.get('/lookups', { params: { only: 'branches,departments,positions' } })).data; } catch { /* filters optional */ }

    // Applicant conversion: /employees/new?applicant_id=X&prefill={...}
    if (route.query.applicant_id && auth.can('employees.create')) {
        employeeModal.value = { open: true, id: null };
    }
});
</script>

<template>
    <div>
        <PageHeader title="Employees" :subtitle="`${stats.active ?? '…'} active staff`">
            <RouterLink to="/employees/org-chart" class="btn-secondary"><Network class="size-4" /> Org chart</RouterLink>
            <RouterLink to="/employees/reports" class="btn-secondary"><BarChart3 class="size-4" /> Staff report</RouterLink>
            <RouterLink v-if="auth.can('employees.update')" to="/employees/lifecycle" class="btn-secondary"><FolderKanban class="size-4" /> Lifecycle</RouterLink>
            <RouterLink v-if="auth.can('employees.update')" to="/employees/records" class="btn-secondary"><ShieldCheck class="size-4" /> Records &amp; KYC</RouterLink>
            <button v-if="auth.can('employees.create')" class="btn-primary" @click="employeeModal = { open: true, id: null }"><UserPlus class="size-4" /> Add employee</button>
        </PageHeader>

        <div class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-800">
            <button
                v-for="[key, v] in visibleViews" :key="key"
                class="-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition"
                :class="view === key ? 'border-brand-600 text-brand-700 dark:text-brand-300' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'"
                @click="view = key"
            >
                {{ v.label }}
                <span v-if="v.stat && stats[v.stat] !== undefined" class="rounded-full px-1.5 text-xs" :class="stats[v.stat] && ['not_activated','without_login','without_supervisor'].includes(key) ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' : 'bg-slate-100 dark:bg-slate-800'">{{ stats[v.stat] }}</span>
            </button>
        </div>

        <div v-if="selectable && selected.size" class="card mb-3 flex flex-wrap items-center gap-3 p-3 text-sm">
            <span class="font-medium">{{ selected.size }} selected</span>
            <button v-if="view === 'without_login'" class="btn-primary !py-1.5" :disabled="busy" @click="bulk('create')"><KeyRound class="size-4" /> Create logins &amp; email activation</button>
            <button v-if="view === 'not_activated'" class="btn-primary !py-1.5" :disabled="busy" @click="bulk('resend')"><Mail class="size-4" /> Resend activation</button>
            <button class="btn-ghost !py-1.5" @click="selected = new Set()">Clear</button>
        </div>

        <DataTable
            :columns="columns" :rows="rows" :loading="loading" :paginator="paginator" clickable
            empty-title="No employees found" :empty-description="hasFilters ? 'Try clearing the filters.' : undefined"
            @page="(p) => { page = p; load(); }" @sort="onSort" @row-click="(r) => router.push(`/employees/${r.id}`)"
        >
            <template #toolbar>
                <div class="relative w-full sm:w-64">
                    <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input v-model="filters.search" type="search" placeholder="Name, number, email, phone…" class="input pl-9" />
                </div>
                <select v-model="filters.branch_id" class="input w-auto"><option value="">All branches</option><option v-for="b in lookups.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                <select v-model="filters.department_id" class="input w-auto"><option value="">All departments</option><option v-for="d in lookups.departments" :key="d.id" :value="d.id">{{ d.name }}</option></select>
                <select v-model="filters.position_id" class="input w-auto"><option value="">All positions</option><option v-for="p in lookups.positions" :key="p.id" :value="p.id">{{ p.title }}</option></select>
                <select v-model="filters.contract_type" class="input w-auto"><option value="">All contracts</option><option v-for="c in CONTRACT_TYPES" :key="c.value" :value="c.value">{{ c.label }}</option></select>
                <select v-model="filters.gender" class="input w-auto"><option value="">Any gender</option><option value="male">Male</option><option value="female">Female</option></select>
                <button v-if="hasFilters" class="btn-ghost !py-2" @click="resetFilters"><X class="size-4" /> Clear</button>
                <button class="btn-secondary !py-2 sm:ml-auto" :disabled="!paginator?.total" @click="exportCsv"><Download class="size-4" /> Export CSV</button>
            </template>

            <template #cell-select="{ row }">
                <input type="checkbox" class="rounded text-brand-600" :checked="selected.has(row.id)" @click.stop="toggle(row.id)" />
            </template>
            <template #cell-full_name="{ row }">
                <div class="flex items-center gap-3">
                    <Avatar :src="row.photo_url" :name="row.full_name" size="sm" />
                    <div class="min-w-0">
                        <p class="truncate font-medium text-slate-900 dark:text-white">{{ row.full_name }}</p>
                        <p class="text-xs text-slate-500">{{ row.employee_number }}</p>
                    </div>
                </div>
            </template>
            <template #cell-status="{ row }">
                <div class="flex flex-wrap gap-1">
                    <Badge :tone="tone(row.status)">{{ label(row.status) }}</Badge>
                    <Badge v-if="row.probation_status === 'on_probation' && row.status === 'active'" tone="amber">Probation</Badge>
                    <Badge v-if="view === 'exited' && row.reinstated" tone="sky">Re-instated</Badge>
                    <Badge v-if="row.leaving_on && row.status !== 'exited'" tone="red" :title="`Exit scheduled for ${formatDate(row.leaving_on)}`">{{ leavingLabel(row.leaving_on) }}</Badge>
                </div>
            </template>
            <template v-if="view === 'not_activated' || view === 'without_login' || view === 'exited'" #actions="{ row }">
                <button v-if="view === 'without_login'" class="btn-ghost !p-1.5" :title="row.email ? 'Create login' : 'Add a work email first'" :class="!row.email && 'opacity-50'" :disabled="rowBusy === row.id" @click="single(row, 'create')"><Spinner v-if="rowBusy === row.id" /><KeyRound v-else class="size-4" /></button>
                <button v-if="view === 'not_activated'" class="btn-ghost !p-1.5" title="Resend activation email" :disabled="rowBusy === row.id" @click="single(row, 'resend')"><Spinner v-if="rowBusy === row.id" /><Mail v-else class="size-4" /></button>
                <button v-if="view === 'exited' && !row.reinstated && auth.can('employees.exit')" class="btn-ghost !py-1 text-xs" @click="reinstateFor = row"><RotateCcw class="size-4" /> Re-instate</button>
            </template>
        </DataTable>
        <div v-if="selectable && rows.length" class="mt-2 text-right">
            <button class="text-xs text-brand-600 hover:underline" @click="toggleAll">{{ selected.size === rows.length ? 'Deselect page' : 'Select all on this page' }}</button>
        </div>

        <ReinstateModal v-if="reinstateFor" :employee="reinstateFor" @close="reinstateFor = null" @done="(id) => router.push(`/employees/${id}`)" />
        <EmployeeForm :open="employeeModal.open" :employee-id="employeeModal.id" @close="employeeModal.open = false" @done="(id) => { employeeModal.open = false; router.push(`/employees/${id}`); }" />
    </div>
</template>
