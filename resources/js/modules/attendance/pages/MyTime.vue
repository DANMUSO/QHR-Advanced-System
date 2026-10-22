<script setup>
import { ref, computed, onMounted } from 'vue';
import { LogIn, LogOut, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import Badge from '@/components/ui/Badge.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { label } from '@/lib/format';

const ui = useUiStore();
// ?tab=timesheets opens the timesheet tab (used by decision notifications).
const tab = ref(new URLSearchParams(window.location.search).get('tab') === 'timesheets' ? 'timesheets' : 'attendance');
const timesheet = ref(null);
const canSubmit = ref(false);
const tabs = [
    { key: 'attendance', label: 'Attendance' },
    { key: 'timesheets', label: 'Timesheets' },
];

// attendance
const days = ref([]);
const attLoading = ref(false);
const punching = ref(false);
const statusColors = { present: 'green', late: 'yellow', absent: 'red', on_leave: 'blue', incomplete: 'orange', in_progress: 'sky', not_in: 'slate' };
// Clock panel state from /attendance/me/today: which punch comes next, and whether web clocking is allowed.
const clock = ref(null);
const nextDirection = computed(() => clock.value?.next_direction ?? 'in');
const clockDisabled = computed(() => punching.value || !clock.value || clock.value.web_clock_enabled === false);

// timesheets
const entries = ref([]);
const summary = ref({});
const tsLoading = ref(false);
const week = ref(currentMonday());
const submitting = ref(false);

function currentMonday() {
    const d = new Date();
    const day = d.getDay();
    d.setDate(d.getDate() - day + (day === 0 ? -6 : 1));
    return d.toISOString().slice(0, 10);
}
function addWeeks(n) {
    const d = new Date(week.value);
    d.setDate(d.getDate() + n * 7);
    week.value = d.toISOString().slice(0, 10);
    loadTimesheets();
}

async function loadClock() {
    try { clock.value = (await api.get('/attendance/me/today')).data; } catch { clock.value = null; }
}

async function loadAttendance() {
    attLoading.value = true;
    try {
        const [{ data }] = await Promise.all([api.get('/attendance/mine'), loadClock()]);
        days.value = data.data;
    } finally { attLoading.value = false; }
}

// Geofenced companies need the browser location with the punch.
function position() {
    if (!clock.value?.geofence?.required || !navigator.geolocation) return Promise.resolve({});
    return new Promise((resolve) => navigator.geolocation.getCurrentPosition(
        (p) => resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy_m: p.coords.accuracy }),
        () => resolve({}),
        { enableHighAccuracy: true, timeout: 10000 },
    ));
}

async function loadTimesheets() {
    tsLoading.value = true;
    try {
        const { data } = await api.get('/attendance/timesheets', { params: { week: week.value } });
        entries.value = data.entries ?? data.data ?? [];
        summary.value = data.summary ?? {};
        timesheet.value = data.timesheet ?? null;
        canSubmit.value = !!data.can_submit;
    } finally { tsLoading.value = false; }
}

async function punch(direction) {
    punching.value = true;
    try {
        const { data } = await api.post('/attendance/punch', { direction, ...(await position()) });
        ui.toast(data.message ?? `Clocked ${direction}.`);
        await loadAttendance();
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
        await loadClock(); // e.g. already clocked in elsewhere: show the real state
    }
    finally { punching.value = false; }
}

async function submitTimesheet() {
    submitting.value = true;
    try {
        await api.post('/attendance/timesheets/submit', { week: week.value });
        ui.toast('Timesheet submitted for approval.');
        await loadTimesheets();
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { submitting.value = false; }
}

function switchTab(t) {
    tab.value = t;
    if (t === 'attendance') loadAttendance();
    else loadTimesheets();
}

onMounted(() => (tab.value === 'timesheets' ? loadTimesheets() : loadAttendance()));
</script>

<template>
    <div>
        <PageHeader title="My Time">
            <template #actions>
                <template v-if="tab === 'attendance'">
                    <span v-if="clock?.summary?.first_in" class="text-sm text-slate-500">
                        In {{ clock.summary.first_in }}<span v-if="clock.summary.last_out && !clock.clocked_in"> · Out {{ clock.summary.last_out }}</span>
                    </span>
                    <button v-if="nextDirection === 'in'" class="btn-primary" :disabled="clockDisabled" @click="punch('in')"><LogIn class="size-4" /> Clock In</button>
                    <button v-else class="btn-secondary" :disabled="clockDisabled" @click="punch('out')"><LogOut class="size-4" /> Clock Out</button>
                </template>
                <button v-else class="btn-primary" :disabled="submitting || !canSubmit" @click="submitTimesheet">
                    {{ submitting ? 'Submitting…' : 'Submit Week' }}
                </button>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <!-- Attendance tab -->
        <template v-if="tab === 'attendance'">
            <div v-if="attLoading" class="flex justify-center py-20"><Spinner /></div>
            <div v-else class="card overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left">Date</th>
                            <th class="px-4 py-3 text-left">Check In</th>
                            <th class="px-4 py-3 text-left">Check Out</th>
                            <th class="px-4 py-3 text-right">Worked</th>
                            <th class="px-4 py-3 text-right">Overtime</th>
                            <th class="px-4 py-3 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="d in days" :key="d.id ?? d.date" class="hover:bg-slate-50/70 dark:hover:bg-slate-800/30">
                            <td class="px-4 py-3 font-medium">{{ d.date }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ d.first_in ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ d.last_out ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">{{ d.worked_formatted ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">{{ d.overtime_formatted ?? '—' }}</td>
                            <td class="px-4 py-3"><Badge v-if="d.status" :color="statusColors[d.status] ?? 'slate'">{{ d.status_label ?? label(d.status) }}</Badge></td>
                        </tr>
                        <tr v-if="!days.length"><td colspan="6" class="px-4 py-10 text-center text-slate-400">No attendance records.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Timesheets tab -->
        <template v-else>
            <div class="mb-4 flex items-center gap-3">
                <button class="btn-ghost !p-1.5" @click="addWeeks(-1)"><ChevronLeft class="size-4" /></button>
                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Week of {{ week }}</span>
                <button class="btn-ghost !p-1.5" @click="addWeeks(1)"><ChevronRight class="size-4" /></button>
            </div>
            <div v-if="timesheet && timesheet.status !== 'submitted'" class="mb-4 rounded-lg p-3 text-sm"
                :class="timesheet.status === 'approved' ? 'bg-green-50 text-green-800 dark:bg-green-500/10 dark:text-green-300' : 'bg-red-50 text-red-800 dark:bg-red-500/10 dark:text-red-300'">
                Timesheet {{ label(timesheet.status).toLowerCase() }}<span v-if="timesheet.decided_by"> by {{ timesheet.decided_by }}</span>.
                <span v-if="timesheet.reason">Reason: {{ timesheet.reason }}</span>
                <span v-if="timesheet.status === 'rejected'"> You can correct and resubmit this week.</span>
            </div>
            <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div v-for="(val, key) in { 'Total Hours': summary.total_hours, 'Regular': summary.regular_hours, 'Overtime': summary.overtime_hours, 'Status': summary.status_label ?? label(summary.status) }" :key="key" class="card p-4">
                    <p class="text-xs text-slate-500">{{ key }}</p>
                    <p class="mt-1 text-xl font-bold text-slate-900 dark:text-white">{{ val ?? '—' }}</p>
                </div>
            </div>
            <div v-if="tsLoading" class="flex justify-center py-20"><Spinner /></div>
            <div v-else class="card overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left">Date</th>
                            <th class="px-4 py-3 text-left">Day</th>
                            <th class="px-4 py-3 text-right">Clock In</th>
                            <th class="px-4 py-3 text-right">Clock Out</th>
                            <th class="px-4 py-3 text-right">Hours</th>
                            <th class="px-4 py-3 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="e in entries" :key="e.date" class="hover:bg-slate-50/70 dark:hover:bg-slate-800/30">
                            <td class="px-4 py-3">{{ e.date }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ e.day_name }}</td>
                            <td class="px-4 py-3 text-right">{{ e.clock_in ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">{{ e.clock_out ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-medium">{{ e.hours ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <Badge v-if="e.status" :color="statusColors[e.status] ?? 'slate'">{{ e.status_label ?? label(e.status) }}</Badge>
                            </td>
                        </tr>
                        <tr v-if="!entries.length"><td colspan="6" class="px-4 py-10 text-center text-slate-400">No timesheet data for this week.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>
