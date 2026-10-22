<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { LogOut, ArrowRightLeft, RotateCcw, Search, ClipboardCheck, XCircle } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { formatDate } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import ExitModal from '../components/ExitModal.vue';
import TransferModal from '../components/TransferModal.vue';
import ReinstateModal from '../components/ReinstateModal.vue';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const tab = ref(route.query.tab === 'moves' ? 'moves' : 'exits');
const tabs = [{ key: 'exits', label: 'Exits', icon: LogOut }, { key: 'moves', label: 'Transfers & promotions', icon: ArrowRightLeft }];

const rows = ref([]);
const paginator = ref(null);
const loading = ref(false);
const filters = reactive({ search: '', status: '', page: 1 });

const STATUS_TONE = { scheduled: 'sky', completed: 'green', cancelled: 'slate', pending: 'amber' };

const columns = computed(() => (tab.value === 'exits'
    ? [
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'exit_reason_label', label: 'Reason' },
        { key: 'exit_date', label: 'Last day', format: (v) => formatDate(v) },
        { key: 'clearance_status', label: 'Clearance' },
        { key: 'status', label: 'Status' },
    ]
    : [
        { key: 'employee_name', label: 'Employee', sortable: true },
        { key: 'type_label', label: 'Type' },
        { key: 'change', label: 'Change', export: false },
        { key: 'effective_date', label: 'Effective', format: (v) => formatDate(v) },
        { key: 'status', label: 'Status' },
    ]));

async function load() {
    loading.value = true;
    try {
        const url = tab.value === 'exits' ? '/employees/exits' : '/employees/transfers';
        const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== null));
        const { data } = await api.get(url, { params });
        rows.value = data.data;
        paginator.value = { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from, to: data.to };
    } catch (e) {
        rows.value = [];
        swal.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

function switchTab(t) {
    tab.value = t;
    Object.assign(filters, { search: '', status: '', page: 1 });
    router.replace({ query: { tab: t } });
    load();
}

let timer;
watch(() => filters.search, () => { clearTimeout(timer); timer = setTimeout(() => { filters.page = 1; load(); }, 300); });
watch(() => filters.status, () => { filters.page = 1; load(); });

// ── actions ──────────────────────────────────────────────────────────────
const exitOpen = ref(false);
const transferOpen = ref(false);
const reinstateFor = ref(null);

function afterChange() { exitOpen.value = false; transferOpen.value = false; load(); }

async function cancelExit(row) {
    if (!(await swal.confirm({ title: 'Cancel scheduled exit?', text: `${row.employee_name} will remain an active employee.`, confirmLabel: 'Cancel exit' }))) return;
    try {
        const { data } = await api.post(`/employees/exits/${row.id}/cancel`);
        swal.toast(data.message);
        load();
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
}

async function cancelMove(row) {
    if (!(await swal.confirm({ title: 'Cancel scheduled move?', text: `The ${row.type_label.toLowerCase()} for ${row.employee_name} will not be applied.`, confirmLabel: 'Cancel move' }))) return;
    try {
        const { data } = await api.delete(`/employees/transfers/${row.id}`);
        swal.toast(data.message);
        load();
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
}

async function openReinstate(row) {
    try {
        reinstateFor.value = (await api.get(`/employees/${row.employee_id}`)).data.data;
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
}

// clearance checklist
const clearance = reactive({ open: false, row: null, items: [], interview: '', override: '', saving: false });
function openClearance(row) {
    Object.assign(clearance, { open: true, row, items: row.clearance.map((c) => ({ ...c })), interview: row.exit_interview ?? '', override: '', saving: false });
}
// Ticking "assets" while assets are still out needs an HR override note.
const needsAssetOverride = computed(() => !!clearance.row?.outstanding_assets?.length
    && clearance.items.some((c) => c.key === 'assets' && c.done && !clearance.row.clearance.find((x) => x.key === 'assets')?.done));
async function saveClearance() {
    clearance.saving = true;
    try {
        const { data } = await api.put(`/employees/exits/${clearance.row.id}`, {
            clearance: Object.fromEntries(clearance.items.map((c) => [c.key, c.done])),
            exit_interview: clearance.interview || null,
            assets_override_note: needsAssetOverride.value ? clearance.override : null,
        });
        swal.toast(data.message);
        clearance.open = false;
        load();
    } catch (e) { swal.toast(errorMessage(e), 'error'); } finally { clearance.saving = false; }
}

function change(row) {
    const parts = [];
    if (row.to_company) parts.push(`${row.from_company ?? '—'} → ${row.to_company}`);
    if (row.to_department && row.to_department !== row.from_department) parts.push(`${row.from_department ?? '—'} → ${row.to_department}`);
    if (row.to_position && row.to_position !== row.from_position) parts.push(`${row.from_position ?? '—'} → ${row.to_position}`);
    if (row.to_branch && row.to_branch !== row.from_branch) parts.push(`${row.from_branch ?? '—'} → ${row.to_branch}`);
    if (row.to_supervisor) parts.push(`Reports to ${row.to_supervisor}`);
    return parts.join(' · ') || '—';
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Employee lifecycle" subtitle="Exits, re-instatements, transfers and promotions." back="/employees">
            <RouterLink v-if="auth.can('employees.exit')" to="/compliance/exits" class="btn-secondary"><ClipboardCheck class="size-4" /> Clearance board</RouterLink>
            <button v-if="auth.can('employees.update') || auth.can('employees.transfer')" class="btn-secondary" @click="transferOpen = true"><ArrowRightLeft class="size-4" /> Transfer / promote</button>
            <button v-if="auth.can('employees.exit')" class="btn-danger" @click="exitOpen = true"><LogOut class="size-4" /> Exit employee</button>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <DataTable
            :columns="columns"
            :rows="rows"
            :loading="loading"
            :paginator="paginator"
            :export-name="tab"
            clickable
            :empty-title="tab === 'exits' ? 'No exits recorded' : 'No transfers or promotions'"
            empty-description="Use the buttons above to record one."
            @row-click="(r) => router.push(`/employees/${r.employee_id}`)"
            @page="(p) => { filters.page = p; load(); }"
        >
            <template #toolbar>
                <div class="relative w-full sm:w-72">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input v-model="filters.search" class="input !py-2 !pl-9" placeholder="Search employee…" />
                </div>
                <select v-model="filters.status" class="input !w-40 !py-2">
                    <option value="">Any status</option>
                    <option value="scheduled">Scheduled</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option>
                </select>
            </template>
            <template #cell-employee_name="{ row }">
                <div class="flex items-center gap-3">
                    <Avatar :src="row.photo_url" :name="row.employee_name" size="sm" />
                    <div class="min-w-0">
                        <p class="truncate font-medium text-slate-900 dark:text-white">{{ row.employee_name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ row.employee_number }}<span v-if="row.position"> · {{ row.position }}</span></p>
                    </div>
                </div>
            </template>
            <template #cell-change="{ row }"><span class="text-sm text-slate-600 dark:text-slate-300">{{ change(row) }}</span></template>
            <template #cell-clearance_status="{ value }">
                <Badge :tone="value === 'cleared' ? 'green' : value === 'in_progress' ? 'amber' : 'slate'">{{ (value ?? 'pending').replace('_', ' ') }}</Badge>
            </template>
            <template #cell-status="{ row, value }">
                <Badge :tone="STATUS_TONE[value] ?? 'slate'">{{ value }}</Badge>
                <Badge v-if="row.reinstated" tone="brand" class="ml-1">Re-instated</Badge>
            </template>
            <template #actions="{ row }">
                <template v-if="tab === 'exits'">
                    <button v-if="auth.can('employees.exit') && row.status !== 'cancelled'" class="btn-ghost !p-1.5" title="Clearance & exit interview" @click.stop="openClearance(row)"><ClipboardCheck class="size-4" /></button>
                    <button v-if="auth.can('employees.exit') && row.status === 'scheduled'" class="btn-ghost !p-1.5 !text-rose-600" title="Cancel scheduled exit" @click.stop="cancelExit(row)"><XCircle class="size-4" /></button>
                    <RouterLink v-if="auth.can('payroll.manage') && row.status !== 'cancelled' && row.final_dues_url" :to="row.final_dues_url" class="btn-ghost !px-2 !py-1.5 text-xs" title="Prepare final dues" @click.stop>Final dues</RouterLink>
                    <button v-if="auth.can('employees.exit') && row.can_reinstate" class="btn-ghost !px-2 !py-1.5 text-xs" title="Re-instate" @click.stop="openReinstate(row)"><RotateCcw class="size-4" /> Re-instate</button>
                </template>
                <button v-else-if="auth.can('employees.update') && row.status === 'scheduled'" class="btn-ghost !p-1.5 !text-rose-600" title="Cancel scheduled move" @click.stop="cancelMove(row)"><XCircle class="size-4" /></button>
            </template>
        </DataTable>

        <ExitModal :open="exitOpen" @close="exitOpen = false" @done="afterChange" />
        <TransferModal :open="transferOpen" @close="transferOpen = false" @done="afterChange" />
        <ReinstateModal v-if="reinstateFor" :employee="reinstateFor" @close="reinstateFor = null" @done="(id) => router.push(`/employees/${id}`)" />

        <Modal :open="clearance.open" :title="`Clearance · ${clearance.row?.employee_name ?? ''}`" @close="clearance.open = false">
            <div class="space-y-2">
                <label v-for="c in clearance.items" :key="c.key" class="flex items-center gap-3 rounded-lg border border-slate-200 p-2.5 text-sm dark:border-slate-700">
                    <input v-model="c.done" type="checkbox" class="rounded text-brand-600" />
                    <span class="text-slate-800 dark:text-slate-200">{{ c.label }}</span>
                    <span v-if="c.override_note" class="ml-auto text-xs text-amber-600 dark:text-amber-400" :title="c.override_note">Overridden</span>
                </label>
                <div v-if="clearance.row?.outstanding_assets?.length" class="rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                    <p class="font-semibold">Assets not yet returned</p>
                    <p>{{ clearance.row.outstanding_assets.map((a) => a.asset_name + (a.asset_tag ? ` (${a.asset_tag})` : '')).join(', ') }}</p>
                    <p class="mt-1">Mark them returned in Recruitment → Assets, or give an override note to clear this item.</p>
                </div>
                <div v-if="needsAssetOverride">
                    <label class="label">Asset override note</label>
                    <textarea v-model="clearance.override" rows="2" class="input" placeholder="Why the assets item can be cleared anyway" />
                </div>
                <label class="label mt-3">Exit interview notes</label>
                <textarea v-model="clearance.interview" rows="3" class="input" />
            </div>
            <template #footer>
                <button class="btn-secondary" @click="clearance.open = false">Cancel</button>
                <RouterLink v-if="clearance.row?.final_dues_url && auth.can('payroll.manage')" :to="clearance.row.final_dues_url" class="btn-secondary mr-auto">Prepare final dues</RouterLink>
                <button class="btn-primary" :disabled="clearance.saving || (needsAssetOverride && !clearance.override.trim())" @click="saveClearance"><Spinner v-if="clearance.saving" /> Save</button>
            </template>
        </Modal>
    </div>
</template>
