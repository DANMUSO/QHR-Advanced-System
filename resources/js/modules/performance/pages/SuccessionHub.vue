<script setup>
import { ref, onMounted } from 'vue';
import { Plus, ArrowRight, Pencil, Trash2, X } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';
import LookupSelect, { loadOptions } from '@/components/ui/LookupSelect.vue';

const ui = useUiStore();
const tab = ref('succession');
const tabs = [
    { key: 'succession', label: 'Succession Plans' },
    { key: 'careers', label: 'Career Paths' },
    { key: 'pools', label: 'Talent Pools' },
    { key: '9box', label: '9-Box Grid' },
];

const rows = ref([]);
const pools = ref([]);
const matrix9box = ref({ employees: [], cycles: [] });
const loading = ref(false);
const modal = ref(false);
const form = ref({});
const errors = ref({});
const saving = ref(false);
const selectedCycle = ref('');
const cycles = ref([]);

const readinessColors = { ready_now: 'green', ready_1_2_years: 'blue', ready_3_5_years: 'yellow', not_ready: 'red' };
const boxes = [
    { x: 3, y: 3, label: 'Star', color: 'bg-green-100 dark:bg-green-900/30 border-green-300 dark:border-green-700' },
    { x: 2, y: 3, label: 'High Potential', color: 'bg-blue-100 dark:bg-blue-900/30 border-blue-300 dark:border-blue-700' },
    { x: 1, y: 3, label: 'Enigma', color: 'bg-yellow-100 dark:bg-yellow-900/30 border-yellow-300 dark:border-yellow-700' },
    { x: 3, y: 2, label: 'High Performer', color: 'bg-blue-100 dark:bg-blue-900/30 border-blue-300 dark:border-blue-700' },
    { x: 2, y: 2, label: 'Core Player', color: 'bg-slate-100 dark:bg-slate-800 border-slate-300 dark:border-slate-600' },
    { x: 1, y: 2, label: 'Inconsistent', color: 'bg-yellow-100 dark:bg-yellow-900/30 border-yellow-300 dark:border-yellow-700' },
    { x: 3, y: 1, label: 'Trusted Pro', color: 'bg-yellow-100 dark:bg-yellow-900/30 border-yellow-300 dark:border-yellow-700' },
    { x: 2, y: 1, label: 'Average', color: 'bg-orange-100 dark:bg-orange-900/30 border-orange-300 dark:border-orange-700' },
    { x: 1, y: 1, label: 'Under Performer', color: 'bg-red-100 dark:bg-red-900/30 border-red-300 dark:border-red-700' },
];

const successionCols = [
    { key: 'position_title', label: 'Critical Position', sortable: true },
    { key: 'incumbent_name', label: 'Incumbent' },
    { key: 'successor_name', label: 'Successor' },
    { key: 'readiness', label: 'Readiness' },
    { key: 'risk_level', label: 'Risk' },
];
const careerCols = [
    { key: 'name', label: 'Career Path', sortable: true },
    { key: 'from_position', label: 'From → To' },
    { key: 'min_years', label: 'Min Years', align: 'right' },
    { key: 'employees_on_path', label: 'On Path', align: 'right' },
];

function boxEmployees(box) {
    return (matrix9box.value.employees ?? []).filter(e => e.performance_box === box.x && e.potential_box === box.y);
}

async function load() {
    loading.value = true;
    try {
        if (tab.value === 'succession') {
            const { data } = await api.get('/succession');
            rows.value = data.data ?? data;
        } else if (tab.value === 'careers') {
            const { data } = await api.get('/succession/careers');
            rows.value = data.data ?? data;
        } else if (tab.value === 'pools') {
            const { data } = await api.get('/succession/talent-pools');
            pools.value = data.data ?? data;
        } else if (tab.value === '9box') {
            const [cycRes, empRes] = await Promise.all([
                api.get('/performance/cycles'),
                selectedCycle.value ? api.get('/succession/9box', { params: { cycle_id: selectedCycle.value } }) : Promise.resolve({ data: { data: [] } }),
            ]);
            cycles.value = cycRes.data.data ?? [];
            matrix9box.value = { employees: empRes.data.data ?? [], cycles: cycles.value };
            // Default to the most recent closed cycle (the one with final scores) so the grid is not blank.
            if (!selectedCycle.value && cycles.value.length) {
                const pick = cycles.value.find((c) => c.status === 'closed') ?? cycles.value[0];
                selectedCycle.value = pick.id;
                const { data } = await api.get('/succession/9box', { params: { cycle_id: pick.id } });
                matrix9box.value = { employees: data.data ?? [], cycles: cycles.value };
            }
        }
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally { loading.value = false; }
}

function switchTab(t) { tab.value = t; load(); }

const editing = ref(null);
const memberPick = ref('');
const confirm = ref({ open: false, row: null, loading: false });
const endpoint = () => (tab.value === 'succession' ? '/succession' : tab.value === 'careers' ? '/succession/careers' : '/succession/talent-pools');
const blankForms = {
    succession: () => ({ position_id: '', incumbent_id: '', successor_id: '', readiness: 'ready_1_2_years', risk_level: 'medium', notes: '' }),
    careers: () => ({ name: '', from_position_id: '', to_position_id: '', min_years: 2, requirements: '' }),
    pools: () => ({ name: '', description: '', criteria: '', members: [] }),
};

function openCreate() {
    editing.value = null; errors.value = {}; memberPick.value = '';
    form.value = blankForms[tab.value]();
    modal.value = true;
}

function openEdit(row) {
    editing.value = row; errors.value = {}; memberPick.value = '';
    form.value = { ...row, members: [...(row.members ?? [])] };
    modal.value = true;
}

async function addMember(id) {
    if (!id || form.value.members.some((m) => m.id === Number(id))) { memberPick.value = ''; return; }
    const emp = (await loadOptions('employees')).find((e) => e.id === Number(id));
    if (emp) form.value.members.push({ id: emp.id, name: emp.name, photo_url: emp.photo_url });
    memberPick.value = '';
}

async function save() {
    saving.value = true; errors.value = {};
    const payload = { ...form.value };
    if (tab.value === 'pools') payload.employee_ids = (form.value.members ?? []).map((m) => m.id);
    delete payload.members;
    try {
        editing.value
            ? await api.put(`${endpoint()}/${editing.value.id}`, payload)
            : await api.post(endpoint(), payload);
        ui.toast('Saved.', 'success'); modal.value = false; await load();
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
        await load();
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally { confirm.value.loading = false; }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Succession & Talent">
            <template #actions>
                <button v-if="tab !== '9box'" class="btn-primary" @click="openCreate">
                    <Plus class="size-4" /> {{ { succession: 'Add Plan', careers: 'Add Path', pools: 'New Pool' }[tab] }}
                </button>
                <template v-else-if="tab === '9box'">
                    <select v-model="selectedCycle" class="input w-64" @change="load">
                        <option value="">— Select Review Cycle —</option>
                        <option v-for="c in cycles" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </template>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>

        <!-- Succession Plans -->
        <DataTable v-else-if="tab === 'succession'" :columns="successionCols" :rows="rows" :loading="false" export-name="succession">
            <template #cell-readiness="{ row }">
                <Badge :color="readinessColors[row.readiness] ?? 'slate'">{{ row.readiness?.replace(/_/g, ' ') }}</Badge>
            </template>
            <template #cell-risk_level="{ row }">
                <Badge :color="row.risk_level === 'high' ? 'red' : row.risk_level === 'medium' ? 'yellow' : 'green'">{{ row.risk_level }}</Badge>
            </template>
            <template #actions="{ row }">
                <button class="btn-ghost !p-1.5" title="Edit" @click="openEdit(row)"><Pencil class="size-4" /></button>
                <button class="btn-ghost !p-1.5 text-red-500" title="Delete" @click="confirm = { open: true, row, loading: false }"><Trash2 class="size-4" /></button>
            </template>
        </DataTable>

        <!-- Career Paths -->
        <DataTable v-else-if="tab === 'careers'" :columns="careerCols" :rows="rows" :loading="false" export-name="career_paths">
            <template #cell-from_position="{ row }">
                <span class="flex items-center gap-1">{{ row.from_position }} <ArrowRight class="size-3 text-slate-400" /> {{ row.to_position }}</span>
            </template>
            <template #actions="{ row }">
                <button class="btn-ghost !p-1.5" title="Edit" @click="openEdit(row)"><Pencil class="size-4" /></button>
                <button class="btn-ghost !p-1.5 text-red-500" title="Delete" @click="confirm = { open: true, row, loading: false }"><Trash2 class="size-4" /></button>
            </template>
        </DataTable>

        <!-- Talent Pools -->
        <template v-else-if="tab === 'pools'">
            <div v-if="!pools.length" class="card p-10 text-center text-slate-400">No talent pools defined.</div>
            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="pool in pools" :key="pool.id" class="card p-5">
                    <div class="mb-2 flex items-start justify-between">
                        <h3 class="font-semibold text-slate-900 dark:text-white">{{ pool.name }}</h3>
                        <div class="flex items-center gap-1">
                            <Badge color="blue">{{ pool.members_count ?? 0 }} members</Badge>
                            <button class="btn-ghost !p-1" title="Edit" @click="openEdit(pool)"><Pencil class="size-4" /></button>
                            <button class="btn-ghost !p-1 text-red-500" title="Delete" @click="confirm = { open: true, row: pool, loading: false }"><Trash2 class="size-4" /></button>
                        </div>
                    </div>
                    <p class="mb-3 text-sm text-slate-500">{{ pool.description }}</p>
                    <div class="flex -space-x-2">
                        <Avatar v-for="m in (pool.members ?? []).slice(0, 5)" :key="m.id" :src="m.photo_url" :name="m.name" size="sm" class="ring-2 ring-white dark:ring-slate-900" />
                        <div v-if="(pool.members_count ?? 0) > 5" class="flex size-8 items-center justify-center rounded-full bg-slate-200 text-xs font-medium ring-2 ring-white dark:bg-slate-700 dark:ring-slate-900">
                            +{{ pool.members_count - 5 }}
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- 9-Box Grid -->
        <template v-else-if="tab === '9box'">
            <div class="flex">
                <div class="flex w-12 items-center justify-center">
                    <span class="-rotate-90 whitespace-nowrap text-xs font-medium text-slate-500">Potential →</span>
                </div>
                <div class="flex-1">
                    <div class="grid grid-cols-3 gap-2">
                        <div v-for="box in boxes" :key="`${box.x}-${box.y}`" class="min-h-[120px] rounded-lg border p-3" :class="box.color">
                            <p class="mb-2 text-xs font-semibold text-slate-700 dark:text-slate-300">{{ box.label }}</p>
                            <div class="flex flex-wrap gap-1">
                                <div v-for="emp in boxEmployees(box)" :key="emp.id" :title="emp.name">
                                    <Avatar :src="emp.photo_url" :name="emp.name" size="sm" />
                                </div>
                                <p v-if="!boxEmployees(box).length" class="text-xs text-slate-400">—</p>
                            </div>
                        </div>
                    </div>
                    <div class="mt-1 grid grid-cols-3 gap-2 text-center text-xs text-slate-400">
                        <span>Low Performance</span><span>Medium Performance</span><span>High Performance</span>
                    </div>
                </div>
            </div>
        </template>

        <!-- Modal -->
        <Modal :open="modal" :title="(editing ? 'Edit ' : 'New ') + (tab === 'succession' ? 'Succession Plan' : tab === 'careers' ? 'Career Path' : 'Talent Pool')" @close="modal = false">
            <div class="space-y-4">
                <template v-if="tab === 'succession'">
                    <div><label class="label">Critical position</label><LookupSelect v-model="form.position_id" kind="positions" placeholder="Choose a position" :error="!!errors?.position_id" /><p v-if="errors.position_id" class="mt-1 text-xs text-red-500">{{ errors.position_id }}</p></div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="label">Incumbent</label><LookupSelect v-model="form.incumbent_id" kind="employees" placeholder="Choose an employee" :error="!!errors?.incumbent_id" /></div>
                        <div><label class="label">Successor</label><LookupSelect v-model="form.successor_id" kind="employees" placeholder="Choose an employee" :error="!!errors?.successor_id" /><p v-if="errors.successor_id" class="mt-1 text-xs text-red-500">{{ errors.successor_id }}</p></div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label">Readiness</label>
                            <select v-model="form.readiness" class="input">
                                <option value="ready_now">Ready Now</option>
                                <option value="ready_1_2_years">Ready in 1-2 Years</option>
                                <option value="ready_3_5_years">Ready in 3-5 Years</option>
                                <option value="not_ready">Not Ready</option>
                            </select>
                        </div>
                        <div>
                            <label class="label">Flight Risk</label>
                            <select v-model="form.risk_level" class="input">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                    </div>
                    <div><label class="label">Notes</label><textarea v-model="form.notes" rows="3" class="input" /></div>
                </template>
                <template v-else-if="tab === 'careers'">
                    <div><label class="label">Path Name</label><input v-model="form.name" type="text" class="input" /><p v-if="errors.name" class="mt-1 text-xs text-red-500">{{ errors.name }}</p></div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="label">From position</label><LookupSelect v-model="form.from_position_id" kind="positions" placeholder="Choose a position" :error="!!errors?.from_position_id" /></div>
                        <div><label class="label">To position</label><LookupSelect v-model="form.to_position_id" kind="positions" placeholder="Choose a position" :error="!!errors?.to_position_id" /></div>
                    </div>
                    <div><label class="label">Min Years in Role</label><input v-model="form.min_years" type="number" min="0" class="input" /></div>
                    <div><label class="label">Requirements</label><textarea v-model="form.requirements" rows="3" class="input" /></div>
                </template>
                <template v-else>
                    <div><label class="label">Pool Name</label><input v-model="form.name" type="text" class="input" /><p v-if="errors.name" class="mt-1 text-xs text-red-500">{{ errors.name }}</p></div>
                    <div><label class="label">Description</label><textarea v-model="form.description" rows="2" class="input" /></div>
                    <div><label class="label">Criteria</label><textarea v-model="form.criteria" rows="3" class="input" /></div>
                    <div>
                        <label class="label">Members</label>
                        <LookupSelect v-model="memberPick" kind="employees" placeholder="Add an employee…" @update:model-value="addMember" />
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span v-for="m in form.members" :key="m.id" class="inline-flex items-center gap-1 rounded-full bg-slate-100 py-1 pl-1 pr-2 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                <Avatar :src="m.photo_url" :name="m.name" size="xs" /> {{ m.name }}
                                <button class="text-slate-400 hover:text-red-500" title="Remove" @click="form.members = form.members.filter((x) => x.id !== m.id)"><X class="size-3" /></button>
                            </span>
                            <span v-if="!form.members?.length" class="text-xs text-slate-400">No members yet.</span>
                        </div>
                    </div>
                </template>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>

        <ConfirmDialog :open="confirm.open" title="Delete" danger confirm-label="Delete" :loading="confirm.loading"
            :message="`Delete ${confirm.row?.name ?? confirm.row?.position_title ?? 'this record'}? The audit trail keeps a copy.`"
            @confirm="remove" @close="confirm.open = false" />
    </div>
</template>
