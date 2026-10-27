<script setup>
/**
 * Attendance management. Supervisors (attendance.view-team) see their team's daily register and decide their
 * team's overtime and timesheets; HR (attendance.manage) sees the company and also manages shifts and geo-fences.
 * ?tab= deep-links a tab (the approvals inbox uses ?tab=overtime / ?tab=timesheets).
 */
import { useFormErrors } from '@/lib/formErrors';
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Plus, Pencil, Trash2, Check, X } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { label } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';
import LookupSelect from '@/components/ui/LookupSelect.vue';
import DevicesPanel from '../components/DevicesPanel.vue';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const managesAll = auth.can('attendance.manage');

const tabs = [
    { key: 'report', label: 'Daily Register' },
    { key: 'overtime', label: 'Overtime' },
    { key: 'timesheets', label: 'Timesheets' },
    ...(managesAll ? [{ key: 'shifts', label: 'Shift Schedules' }, { key: 'geofencing', label: 'Geo-Fencing' }, { key: 'devices', label: 'Devices' }] : []),
];
const initial = String(route.query.tab ?? '');
const tab = ref(tabs.some(t => t.key === initial) ? initial : 'report');
watch(tab, (t) => {
    if (route.query.tab !== t) router.replace({ query: { ...route.query, tab: t } });
    load();
});

const rows = ref([]);
const paginator = ref(null);
const page = ref(1);
const loading = ref(false);
const modal = ref({ open: false, row: null });
const form = ref({});
const errors = useFormErrors(() => form.value);
const saving = ref(false);

// report
// Local date (toISOString() is UTC and shows yesterday before 03:00 in Nairobi).
const reportDate = ref(new Date().toLocaleDateString('en-CA'));
const reportSummary = ref({});
const reportStatusColors = { present: 'green', late: 'yellow', absent: 'red', on_leave: 'blue', incomplete: 'orange', in_progress: 'green', not_in: 'slate', weekend: 'slate', holiday: 'purple' };
const reportCols = [
    { key: 'employee', label: 'Employee' },
    { key: 'first_in', label: 'Check In' },
    { key: 'last_out', label: 'Check Out' },
    { key: 'worked_formatted', label: 'Worked' },
    { key: 'overtime_formatted', label: 'Overtime' },
    { key: 'status', label: 'Status' },
];

// shifts
const shiftCols = [
    { key: 'name', label: 'Shift Name', sortable: true },
    { key: 'start_time', label: 'Start' },
    { key: 'end_time', label: 'End' },
    { key: 'break_minutes', label: 'Break (min)', align: 'right' },
    { key: 'days', label: 'Days' },
    { key: 'employee_count', label: 'Assigned', align: 'right' },
];

// overtime
const otStatus = ref('pending');
const otSummary = ref({});
const otStatusColors = { pending: 'yellow', approved: 'green', rejected: 'red', paid: 'emerald' };
const otCols = [
    { key: 'employee_name', label: 'Employee', sortable: true },
    { key: 'date', label: 'Date' },
    { key: 'day_type', label: 'Day' },
    { key: 'hours', label: 'Hours', align: 'right' },
    { key: 'rate', label: 'Rate' },
    { key: 'amount', label: 'Est. (KES)', align: 'right', format: v => v?.toLocaleString() ?? '—' },
    { key: 'status', label: 'Status' },
];

// timesheets
const tsStatus = ref('submitted');
const tsStatusColors = { submitted: 'yellow', approved: 'green', rejected: 'red' };
const tsCols = [
    { key: 'employee_name', label: 'Employee', sortable: true },
    { key: 'week_label', label: 'Week' },
    { key: 'total_hours', label: 'Hours', align: 'right' },
    { key: 'overtime_hours', label: 'Overtime', align: 'right' },
    { key: 'exceptions', label: 'Exceptions' },
    { key: 'status', label: 'Status' },
];

// geofencing
const geoCols = [
    { key: 'name', label: 'Zone Name', sortable: true },
    { key: 'address', label: 'Address' },
    { key: 'radius_meters', label: 'Radius (m)', align: 'right' },
    { key: 'branch_name', label: 'Branch' },
    { key: 'is_active', label: 'Status' },
];

// approve / reject dialog for overtime and timesheets
const decision = ref({ open: false, kind: '', row: null, approve: true, reason: '', hours: '' });
const deciding = ref(false);

const headerHint = computed(() => (managesAll ? 'All employees in the company' : 'Your team'));

function pageOf(data) {
    paginator.value = data.last_page ? { current_page: data.current_page, last_page: data.last_page, total: data.total } : null;
    return data.data ?? data;
}

async function load() {
    loading.value = true; rows.value = [];
    try {
        if (tab.value === 'report') {
            const { data } = await api.get('/attendance/daily', { params: { date: reportDate.value } });
            rows.value = data.data; reportSummary.value = data.summary ?? {};
        } else if (tab.value === 'shifts') {
            rows.value = pageOf((await api.get('/attendance/shifts')).data);
        } else if (tab.value === 'overtime') {
            const { data } = await api.get('/attendance/overtime', { params: { status: otStatus.value || undefined, page: page.value } });
            rows.value = pageOf(data); otSummary.value = data.summary ?? {};
        } else if (tab.value === 'timesheets') {
            rows.value = pageOf((await api.get('/attendance/timesheets/approvals', { params: { status: tsStatus.value || undefined, page: page.value } })).data);
        } else if (tab.value === 'geofencing') {
            rows.value = pageOf((await api.get('/attendance/geofencing')).data);
        }
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}

function goPage(p) { page.value = p; load(); }

function openDecision(kind, row, approve) {
    decision.value = { open: true, kind, row, approve, reason: '', hours: kind === 'overtime' ? row.hours : '' };
}

async function decide() {
    const d = decision.value;
    deciding.value = true;
    try {
        const base = d.kind === 'overtime' ? `/attendance/overtime/${d.row.id}` : `/attendance/timesheets/${d.row.id}`;
        const body = { reason: d.reason || null };
        if (d.kind === 'overtime' && d.approve && Number(d.hours) > 0 && Number(d.hours) < d.row.hours) body.minutes = Math.round(Number(d.hours) * 60);
        const { data } = await api.post(`${base}/${d.approve ? 'approve' : 'reject'}`, body);
        swal.toast(data.message);
        decision.value.open = false;
        await load();
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { deciding.value = false; }
}

async function save() {
    saving.value = true; errors.value = {};
    const base = tab.value === 'shifts' ? '/attendance/shifts' : '/attendance/geofencing';
    try {
        modal.value.row ? await api.put(`${base}/${modal.value.row.id}`, form.value) : await api.post(base, form.value);
        swal.toast('Saved.'); modal.value.open = false; await load();
    } catch (e) { errors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}

async function remove(row) {
    if (!await swal.confirm({ title: `Delete '${row.name}'?`, text: 'This cannot be undone.' })) return;
    const base = tab.value === 'shifts' ? '/attendance/shifts' : '/attendance/geofencing';
    try { await api.delete(`${base}/${row.id}`); swal.toast('Deleted.'); await load(); }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Attendance Management" :subtitle="headerHint">
            <template #actions>
                <input v-if="tab === 'report'" v-model="reportDate" type="date" class="input w-40" @change="load" />
                <button v-if="tab === 'shifts'" class="btn-primary" @click="modal = { open: true, row: null }; form = { name: '', start_time: '08:00', end_time: '17:00', break_minutes: 60, days: 'Mon-Fri' }; errors = {}">
                    <Plus class="size-4" /> Add Shift
                </button>
                <select v-if="tab === 'overtime'" v-model="otStatus" class="input w-36" @change="page = 1; load()">
                    <option value="">All</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="paid">Paid</option>
                </select>
                <select v-if="tab === 'timesheets'" v-model="tsStatus" class="input w-36" @change="page = 1; load()">
                    <option value="submitted">Submitted</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                </select>
                <button v-if="tab === 'geofencing'" class="btn-primary" @click="modal = { open: true, row: null }; form = { name: '', latitude: '', longitude: '', radius_meters: 200, branch_id: '' }; errors = {}">
                    <Plus class="size-4" /> Add Zone
                </button>
            </template>
        </PageHeader>

        <Tabs v-model="tab" :tabs="tabs" />

        <div v-if="tab === 'report'" class="mb-4 flex flex-wrap gap-3">
            <div v-for="[key, label, color] in [['present','Present','bg-green-500'],['late','Late','bg-yellow-500'],['absent','Absent','bg-red-500'],['on_leave','On Leave','bg-blue-500'],['not_in','Not in yet','bg-slate-400'],['off','Off / Holiday','bg-slate-300']]" :key="key" class="card flex items-center gap-3 px-4 py-3">
                <span class="size-2.5 rounded-full" :class="color" />
                <span class="text-sm font-medium">{{ label }}: <strong>{{ reportSummary[key] ?? 0 }}</strong></span>
            </div>
        </div>
        <div v-if="tab === 'overtime'" class="mb-4 flex flex-wrap gap-2 text-xs">
            <Badge v-for="(s, k) in otSummary" :key="k" :color="otStatusColors[k] ?? 'slate'">{{ label(k) }}: {{ s.count }} ({{ s.hours }} h)</Badge>
        </div>

        <DevicesPanel v-if="tab === 'devices'" />

        <div v-else-if="loading" class="flex justify-center py-20"><Spinner /></div>

        <DataTable v-else-if="tab === 'report'" :columns="reportCols" :rows="rows" :loading="false">
            <template #cell-employee="{ row }">
                <div class="flex items-center gap-2">
                    <Avatar :src="row.employee?.photo_url" :name="row.employee?.name" size="sm" />
                    <div>
                        <p class="font-medium">{{ row.employee?.name }}</p>
                        <p class="text-xs text-slate-400">{{ row.employee?.branch }}</p>
                    </div>
                </div>
            </template>
            <template #cell-status="{ row }">
                <Badge :color="reportStatusColors[row.status] ?? 'slate'">{{ row.status_label ?? row.status }}</Badge>
            </template>
        </DataTable>

        <DataTable v-else-if="tab === 'shifts'" :columns="shiftCols" :rows="rows" :loading="false">
            <template #actions="{ row }">
                <button class="btn-ghost !p-1.5" title="Edit" @click="modal = { open: true, row }; form = { ...row, working_days: undefined }; errors = {}"><Pencil class="size-4" /></button>
                <button class="btn-ghost !p-1.5 text-red-500" title="Delete" @click="remove(row)"><Trash2 class="size-4" /></button>
            </template>
        </DataTable>

        <DataTable v-else-if="tab === 'overtime'" :columns="otCols" :rows="rows" :loading="false" export-name="overtime">
            <template #cell-status="{ row }">
                <Badge :color="otStatusColors[row.status] ?? 'slate'">{{ label(row.status) }}</Badge>
                <p v-if="row.reason" class="mt-0.5 max-w-48 truncate text-xs text-slate-400" :title="row.reason">{{ row.reason }}</p>
            </template>
            <template #actions="{ row }">
                <template v-if="row.can_decide">
                    <button class="btn-ghost !p-1.5 text-green-600" title="Approve" @click="openDecision('overtime', row, true)"><Check class="size-4" /></button>
                    <button class="btn-ghost !p-1.5 text-red-500" title="Reject" @click="openDecision('overtime', row, false)"><X class="size-4" /></button>
                </template>
            </template>
        </DataTable>

        <DataTable v-else-if="tab === 'timesheets'" :columns="tsCols" :rows="rows" :loading="false" export-name="timesheets">
            <template #cell-exceptions="{ row }">
                <span v-if="row.incomplete_days || row.absent_days" class="text-xs font-medium text-amber-700 dark:text-amber-300">
                    <template v-if="row.incomplete_days">{{ row.incomplete_days }} missing clock-out</template><template v-if="row.incomplete_days && row.absent_days"> · </template><template v-if="row.absent_days">{{ row.absent_days }} absent</template>
                </span>
                <span v-else class="text-xs text-slate-400">None</span>
            </template>
            <template #cell-status="{ row }">
                <Badge :color="tsStatusColors[row.status] ?? 'slate'">{{ label(row.status) }}</Badge>
                <p v-if="row.note" class="mt-0.5 max-w-48 truncate text-xs text-slate-400" :title="row.note">{{ row.note }}</p>
            </template>
            <template #actions="{ row }">
                <template v-if="row.status === 'submitted'">
                    <button class="btn-ghost !p-1.5 text-green-600" title="Approve" @click="openDecision('timesheet', row, true)"><Check class="size-4" /></button>
                    <button class="btn-ghost !p-1.5 text-red-500" title="Reject" @click="openDecision('timesheet', row, false)"><X class="size-4" /></button>
                </template>
            </template>
        </DataTable>

        <DataTable v-else-if="tab === 'geofencing'" :columns="geoCols" :rows="rows" :loading="false">
            <template #cell-is_active="{ row }">
                <Badge :color="row.is_active ? 'green' : 'slate'">{{ row.is_active ? 'Active' : 'Inactive' }}</Badge>
            </template>
            <template #actions="{ row }">
                <button class="btn-ghost !p-1.5" title="Edit" @click="modal = { open: true, row }; form = { ...row }; errors = {}"><Pencil class="size-4" /></button>
                <button class="btn-ghost !p-1.5 text-red-500" title="Delete" @click="remove(row)"><Trash2 class="size-4" /></button>
            </template>
        </DataTable>

        <div v-if="!loading && paginator && paginator.last_page > 1 && ['overtime', 'timesheets'].includes(tab)" class="mt-4 flex items-center justify-end gap-2 text-sm">
            <button class="btn-secondary !py-1" :disabled="paginator.current_page <= 1" @click="goPage(paginator.current_page - 1)">Previous</button>
            <span class="text-slate-500">Page {{ paginator.current_page }} of {{ paginator.last_page }}</span>
            <button class="btn-secondary !py-1" :disabled="paginator.current_page >= paginator.last_page" @click="goPage(paginator.current_page + 1)">Next</button>
        </div>

        <!-- Approve / reject overtime or a timesheet -->
        <Modal :open="decision.open" :title="(decision.approve ? 'Approve ' : 'Reject ') + (decision.kind === 'overtime' ? 'Overtime' : 'Timesheet')" @close="decision.open = false">
            <div class="space-y-4">
                <p class="text-sm text-slate-600 dark:text-slate-400">
                    {{ decision.row?.employee_name }} —
                    <template v-if="decision.kind === 'overtime'">{{ decision.row?.date }} · {{ decision.row?.hours }} h</template>
                    <template v-else>{{ decision.row?.week_label }} · {{ decision.row?.total_hours }} h ({{ decision.row?.overtime_hours }} h overtime)</template>
                </p>
                <div v-if="decision.kind !== 'overtime' && (decision.row?.incomplete_days || decision.row?.absent_days)" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                    <p class="font-medium">Exceptions this week: <template v-if="decision.row.incomplete_days">{{ decision.row.incomplete_days }} day(s) without a clock-out</template><template v-if="decision.row.incomplete_days && decision.row.absent_days">, </template><template v-if="decision.row.absent_days">{{ decision.row.absent_days }} absent day(s)</template>.</p>
                    <p v-if="decision.row.note" class="mt-1">Employee's note: {{ decision.row.note }}</p>
                </div>
                <div v-if="decision.kind === 'overtime' && decision.approve">
                    <label class="label">Hours to approve</label>
                    <input v-model="decision.hours" type="number" min="0.25" :max="decision.row?.hours" step="0.25" class="input w-32" />
                    <p class="mt-1 text-xs text-slate-400">Lower it to approve fewer hours than claimed.</p>
                </div>
                <div>
                    <label class="label">{{ decision.approve ? 'Note (optional)' : 'Reason (required)' }}</label>
                    <textarea v-model="decision.reason" rows="3" class="input" />
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="decision.open = false">Cancel</button>
                <button :class="decision.approve ? 'btn-primary' : 'btn-danger'" :disabled="deciding || (!decision.approve && !decision.reason.trim())" @click="decide">
                    {{ deciding ? 'Saving…' : (decision.approve ? 'Approve' : 'Reject') }}
                </button>
            </template>
        </Modal>

        <!-- Shift / zone editor -->
        <Modal :open="modal.open" :title="modal.row ? 'Edit' : (tab === 'shifts' ? 'Add Shift' : 'Add Zone')" @close="modal.open = false">
            <div class="space-y-4">
                <template v-if="tab === 'shifts'">
                    <div><label class="label">Shift Name</label><input v-model="form.name" type="text" class="input" /><p v-if="errors.name" class="field-error">{{ errors.name }}</p></div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="label">Start Time</label><input v-model="form.start_time" type="time" class="input" /></div>
                        <div><label class="label">End Time</label><input v-model="form.end_time" type="time" class="input" /><p v-if="errors.end_time" class="field-error">{{ errors.end_time }}</p></div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="label">Break (min)</label><input v-model="form.break_minutes" type="number" min="0" class="input" /></div>
                        <div><label class="label">Working Days</label><input v-model="form.days" type="text" class="input" placeholder="Mon-Fri" /></div>
                    </div>
                </template>
                <template v-else>
                    <div><label class="label">Zone Name</label><input v-model="form.name" type="text" class="input" /><p v-if="errors.name" class="field-error">{{ errors.name }}</p></div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="label">Latitude</label><input v-model="form.latitude" type="number" step="0.000001" class="input" /></div>
                        <div><label class="label">Longitude</label><input v-model="form.longitude" type="number" step="0.000001" class="input" /></div>
                    </div>
                    <div><label class="label">Radius (metres)</label><input v-model="form.radius_meters" type="number" min="20" class="input" /></div>
                    <div><label class="label">Branch</label><LookupSelect v-model="form.branch_id" kind="branches" placeholder="Choose a branch" :error="!!errors?.branch_id" /></div>
                </template>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>
    </div>
</template>
