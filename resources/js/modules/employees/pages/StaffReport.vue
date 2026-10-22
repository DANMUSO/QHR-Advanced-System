<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Users, User, UserRound, CalendarClock, BriefcaseBusiness } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { formatDate, titleCase } from '@/lib/format';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatCard from '@/components/ui/StatCard.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';

const router = useRouter();
const filters = reactive({ status: 'active', branch_id: '', department_id: '', contract_type: '', gender: '', age_bracket: '', joined_from: '', joined_to: '' });
const meta = ref({ branches: [], departments: [], contract_types: [], age_brackets: [] });
const rows = ref([]);
const totals = ref({});
const loading = ref(false);

const columns = [
    { key: 'full_name', label: 'Employee', sortable: true },
    { key: 'employee_number', label: 'Emp No.', sortable: true },
    { key: 'department', label: 'Department', sortable: true },
    { key: 'branch', label: 'Branch', sortable: true },
    { key: 'position', label: 'Position' },
    { key: 'gender', label: 'Gender', format: (v) => titleCase(v ?? '—') },
    { key: 'age', label: 'Age', align: 'right', sortable: true },
    { key: 'contract_type', label: 'Contract', format: (v) => titleCase(v ?? '—') },
    { key: 'date_of_joining', label: 'Joined', sortable: true, format: (v) => (v ? formatDate(v) : '—') },
    { key: 'years_of_service', label: 'Service (yrs)', align: 'right', sortable: true },
    { key: 'status', label: 'Status' },
];

const breakdowns = computed(() => [
    ['By department', totals.value.by_department],
    ['By branch', totals.value.by_branch],
    ['By contract type', totals.value.by_contract],
    ['By age', totals.value.by_age],
].filter(([, v]) => v && Object.keys(v).length));

async function load() {
    loading.value = true;
    try {
        const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''));
        const { data } = await api.get('/employees/reports', { params });
        rows.value = data.data;
        totals.value = data.totals;
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

function share(n) {
    const total = totals.value.headcount || 0;
    return total ? Math.round((n / total) * 100) : 0;
}

watch(filters, load, { deep: true });
onMounted(async () => {
    load();
    try { meta.value = (await api.get('/employees/meta')).data; } catch { /* filters optional */ }
});
</script>

<template>
    <div>
        <PageHeader title="Staff report" subtitle="Head count and demographics for the filtered staff list." back="/employees" />

        <div class="card mb-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <select v-model="filters.status" class="input !py-2">
                <option value="active">Active staff</option><option value="active,suspended">Active & suspended</option>
                <option value="exited">Exited</option><option value="all">All records</option>
            </select>
            <select v-model="filters.branch_id" class="input !py-2"><option value="">All branches</option><option v-for="b in meta.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
            <select v-model="filters.department_id" class="input !py-2"><option value="">All departments</option><option v-for="d in meta.departments" :key="d.id" :value="d.id">{{ d.name }}</option></select>
            <select v-model="filters.contract_type" class="input !py-2"><option value="">All contract types</option><option v-for="c in meta.contract_types" :key="c" :value="c">{{ titleCase(c) }}</option></select>
            <select v-model="filters.gender" class="input !py-2"><option value="">Any gender</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select>
            <select v-model="filters.age_bracket" class="input !py-2"><option value="">Any age</option><option v-for="a in meta.age_brackets" :key="a.value" :value="a.value">{{ a.label }}</option></select>
            <label class="flex items-center gap-2 text-sm text-slate-500">Joined from <input v-model="filters.joined_from" type="date" class="input !py-2" /></label>
            <label class="flex items-center gap-2 text-sm text-slate-500">to <input v-model="filters.joined_to" type="date" class="input !py-2" /></label>
        </div>

        <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <StatCard label="Head count" :value="totals.headcount ?? '—'" :icon="Users" />
            <StatCard label="Male" :value="totals.male ?? '—'" :icon="User" :hint="totals.headcount ? `${share(totals.male)}%` : ''" />
            <StatCard label="Female" :value="totals.female ?? '—'" :icon="UserRound" :hint="totals.headcount ? `${share(totals.female)}%` : ''" />
            <StatCard label="Average age" :value="totals.average_age ?? '—'" :icon="CalendarClock" />
            <StatCard label="Average service (yrs)" :value="totals.average_service ?? '—'" :icon="BriefcaseBusiness" />
        </div>

        <div v-if="breakdowns.length" class="mb-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div v-for="[title, groups] in breakdowns" :key="title" class="card p-4">
                <h3 class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">{{ title }}</h3>
                <ul class="space-y-2">
                    <li v-for="(n, name) in groups" :key="name">
                        <div class="flex justify-between text-xs"><span class="truncate text-slate-600 dark:text-slate-300">{{ titleCase(String(name)) }}</span><span class="font-medium text-slate-900 dark:text-white">{{ n }}</span></div>
                        <div class="mt-1 h-1.5 rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-1.5 rounded-full bg-brand-500" :style="{ width: share(n) + '%' }" /></div>
                    </li>
                </ul>
            </div>
        </div>

        <DataTable :columns="columns" :rows="rows" :loading="loading" export-name="staff-report" clickable empty-title="No staff match" empty-description="Adjust the filters above." @row-click="(r) => router.push(`/employees/${r.id}`)">
            <template #cell-full_name="{ row }">
                <div class="flex items-center gap-3"><Avatar :src="row.photo_url" :name="row.full_name" size="sm" /><span class="font-medium text-slate-900 dark:text-white">{{ row.full_name }}</span></div>
            </template>
            <template #cell-status="{ value }"><Badge :tone="value === 'active' ? 'green' : value === 'exited' ? 'red' : 'slate'">{{ value }}</Badge></template>
        </DataTable>
    </div>
</template>
