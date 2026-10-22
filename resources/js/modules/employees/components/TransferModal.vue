<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { ArrowRightLeft, Building2 } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { useAuthStore } from '@/stores/auth';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { label } from '../lib';
import EmployeePicker from './EmployeePicker.vue';

/**
 * Internal move (transfer / promotion / demotion / redeployment within the company)
 * or a transfer to another group company (new record there; login moves with the person).
 */
const props = defineProps({ open: Boolean, employee: { type: Object, default: null }, initialMode: { type: String, default: 'move' } });
const emit = defineEmits(['close', 'done']);
const auth = useAuthStore();

const today = () => new Date().toISOString().slice(0, 10);
const mode = ref('move');
const picked = ref(null);
const detail = ref(null);
const meta = ref({ branches: [], departments: [], positions: [], levels: [], supervisors: [] });
const options = ref({ companies: [] });
const move = reactive({});
const xfer = reactive({});
const errors = ref({});
const saving = ref(false);
const loading = ref(false);

const target = computed(() => detail.value);
const canCompany = computed(() => auth.can('employees.transfer'));
const canMove = computed(() => auth.can('employees.update'));

async function loadEmployee(emp) {
    detail.value = null;
    if (!emp) return;
    loading.value = true;
    try {
        detail.value = (await api.get(`/employees/${emp.id}`)).data.data;
        meta.value = (await api.get('/employees/meta', { params: { company_id: detail.value.company?.id } })).data;
        resetForms();
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

function resetForms() {
    const e = detail.value ?? {};
    Object.assign(move, {
        type: 'transfer', to_branch_id: e.branch_id ?? '', to_department_id: e.department_id ?? '', to_position_id: e.position_id ?? '',
        to_level_id: e.employment_level_id ?? '', to_supervisor_id: e.primary_supervisor_id ?? '', effective_date: today(), reason: '',
    });
    Object.assign(xfer, {
        company_id: '', employee_number: '', branch_id: '', department_id: '', position_id: '', employment_level_id: '', primary_supervisor_id: '',
        date_of_joining: today(), contract_type: e.contract_type ?? 'permanent', contract_end_date: '', probation_months: 0, reason: '', new_supervisor_id: '',
        carry_leave_balances: false,
    });
    options.value = { companies: options.value.companies };
    errors.value = {};
}

async function loadCompanies() {
    if (options.value.companies.length || !canCompany.value) return;
    try { options.value.companies = (await api.get('/employees/transfer-options')).data.companies ?? []; } catch { /* optional */ }
}

watch(() => xfer.company_id, async (id) => {
    if (!id) return;
    try {
        const { data } = await api.get('/employees/transfer-options', { params: { company_id: id } });
        options.value = data;
        xfer.employee_number = data.next_number ?? '';
        Object.assign(xfer, { branch_id: '', department_id: '', position_id: '', employment_level_id: '', primary_supervisor_id: '' });
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
});

watch(() => props.open, (o) => {
    if (!o) return;
    mode.value = props.initialMode === 'company' && canCompany.value ? 'company' : (canMove.value ? 'move' : 'company');
    picked.value = null;
    errors.value = {};
    detail.value = null;
    if (props.employee) loadEmployee(props.employee);
    loadCompanies();
});
watch(picked, (e) => { if (!props.employee) loadEmployee(e); });

const clean = (obj) => Object.fromEntries(Object.entries(obj).map(([k, v]) => [k, v === '' ? null : v]));

async function save() {
    if (!target.value) { errors.value = { employee_id: 'Choose an employee.' }; return; }
    saving.value = true;
    errors.value = {};
    try {
        const { data } = mode.value === 'move'
            ? await api.post('/employees/transfers', { ...clean(move), employee_id: target.value.id })
            : await api.post(`/employees/${target.value.id}/transfer`, clean(xfer));
        swal.toast(data.message);
        emit('done', data.data);
    } catch (e) {
        errors.value = fieldErrors(e);
        if (!Object.keys(errors.value).length) swal.toast(errorMessage(e), 'error');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Modal :open="open" title="Transfer or promote" size="lg" @close="emit('close')">
        <div class="mb-4 inline-flex rounded-xl border border-slate-200 p-1 dark:border-slate-700">
            <button v-if="canMove" type="button" class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm" :class="mode === 'move' ? 'bg-brand-600 text-white' : 'text-slate-600 dark:text-slate-300'" @click="mode = 'move'">
                <ArrowRightLeft class="size-4" /> Within company
            </button>
            <button v-if="canCompany" type="button" class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm" :class="mode === 'company' ? 'bg-brand-600 text-white' : 'text-slate-600 dark:text-slate-300'" @click="mode = 'company'">
                <Building2 class="size-4" /> To another company
            </button>
        </div>

        <div v-if="!employee" class="mb-4">
            <label class="label">Employee <span class="text-rose-500">*</span></label>
            <EmployeePicker v-model="picked" status="active,suspended" />
            <p v-if="errors.employee_id" class="field-error">{{ errors.employee_id }}</p>
        </div>

        <div v-if="loading" class="flex justify-center py-10"><Spinner /></div>

        <form novalidate v-else-if="target && mode === 'move'" class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
            <div>
                <label class="label">Type <span class="text-rose-500">*</span></label>
                <select v-model="move.type" class="input">
                    <option value="transfer">Transfer</option><option value="promotion">Promotion</option>
                    <option value="demotion">Demotion</option><option value="redeployment">Redeployment</option>
                </select>
            </div>
            <div>
                <label class="label">Effective date <span class="text-rose-500">*</span></label>
                <input v-model="move.effective_date" type="date" class="input" />
                <p v-if="errors.effective_date" class="field-error">{{ errors.effective_date }}</p>
            </div>
            <div>
                <label class="label">Branch</label>
                <select v-model="move.to_branch_id" class="input"><option v-for="b in meta.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
            </div>
            <div>
                <label class="label">Department</label>
                <select v-model="move.to_department_id" class="input"><option v-for="d in meta.departments" :key="d.id" :value="d.id">{{ d.name }}</option></select>
            </div>
            <div>
                <label class="label">Position</label>
                <select v-model="move.to_position_id" class="input"><option v-for="p in meta.positions" :key="p.id" :value="p.id">{{ p.title }}</option></select>
            </div>
            <div>
                <label class="label">Level</label>
                <select v-model="move.to_level_id" class="input"><option value="">—</option><option v-for="l in meta.levels" :key="l.id" :value="l.id">{{ l.name }}</option></select>
            </div>
            <div class="sm:col-span-2">
                <label class="label">Supervisor</label>
                <select v-model="move.to_supervisor_id" class="input"><option value="">— No change —</option><option v-for="s in meta.supervisors.filter((x) => x.id !== target.id)" :key="s.id" :value="s.id">{{ s.full_name }}</option></select>
                <p v-if="errors.to_supervisor_id" class="field-error">{{ errors.to_supervisor_id }}</p>
            </div>
            <div class="sm:col-span-2">
                <label class="label">Reason</label>
                <textarea v-model="move.reason" rows="2" class="input" />
            </div>
            <p class="text-xs text-slate-500 sm:col-span-2">Only the fields you change are applied. A future effective date schedules the move.</p>
            <button type="submit" class="hidden" />
        </form>

        <form novalidate v-else-if="target && mode === 'company'" class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
            <div class="sm:col-span-2">
                <label class="label">Target company <span class="text-rose-500">*</span></label>
                <select v-model="xfer.company_id" class="input">
                    <option value="">Select…</option>
                    <option v-for="c in options.companies.filter((c) => c.id !== target.company?.id)" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <p v-if="errors.company_id" class="field-error">{{ errors.company_id }}</p>
            </div>
            <template v-if="xfer.company_id">
                <div>
                    <label class="label">New employee number <span class="text-rose-500">*</span></label>
                    <input v-model="xfer.employee_number" class="input" />
                    <p v-if="errors.employee_number" class="field-error">{{ errors.employee_number }}</p>
                </div>
                <div>
                    <label class="label">Start date <span class="text-rose-500">*</span></label>
                    <input v-model="xfer.date_of_joining" type="date" class="input" />
                    <p v-if="errors.date_of_joining" class="field-error">{{ errors.date_of_joining }}</p>
                </div>
                <div>
                    <label class="label">Branch <span class="text-rose-500">*</span></label>
                    <select v-model="xfer.branch_id" class="input"><option value="">Select…</option><option v-for="b in options.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                    <p v-if="errors.branch_id" class="field-error">{{ errors.branch_id }}</p>
                </div>
                <div>
                    <label class="label">Department <span class="text-rose-500">*</span></label>
                    <select v-model="xfer.department_id" class="input"><option value="">Select…</option><option v-for="d in options.departments" :key="d.id" :value="d.id">{{ d.name }}</option></select>
                    <p v-if="errors.department_id" class="field-error">{{ errors.department_id }}</p>
                </div>
                <div>
                    <label class="label">Position <span class="text-rose-500">*</span></label>
                    <select v-model="xfer.position_id" class="input"><option value="">Select…</option><option v-for="p in options.positions" :key="p.id" :value="p.id">{{ p.title }}</option></select>
                    <p v-if="errors.position_id" class="field-error">{{ errors.position_id }}</p>
                </div>
                <div>
                    <label class="label">Level</label>
                    <select v-model="xfer.employment_level_id" class="input"><option value="">—</option><option v-for="l in options.levels" :key="l.id" :value="l.id">{{ l.name }}</option></select>
                </div>
                <div>
                    <label class="label">Supervisor in new company</label>
                    <select v-model="xfer.primary_supervisor_id" class="input"><option value="">— None —</option><option v-for="s in options.supervisors" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                </div>
                <div>
                    <label class="label">Contract type <span class="text-rose-500">*</span></label>
                    <select v-model="xfer.contract_type" class="input"><option v-for="c in ['permanent', 'contract', 'casual', 'intern']" :key="c" :value="c">{{ label(c) }}</option></select>
                </div>
                <div v-if="xfer.contract_type !== 'permanent'">
                    <label class="label">Contract end date <span class="text-rose-500">*</span></label>
                    <input v-model="xfer.contract_end_date" type="date" class="input" />
                    <p v-if="errors.contract_end_date" class="field-error">{{ errors.contract_end_date }}</p>
                </div>
                <div v-if="target.direct_reports_count" class="sm:col-span-2">
                    <label class="label">New supervisor for {{ target.direct_reports_count }} direct report(s) left behind</label>
                    <select v-model="xfer.new_supervisor_id" class="input"><option value="">— Leave without supervisor —</option><option v-for="s in meta.supervisors.filter((x) => x.id !== target.id)" :key="s.id" :value="s.id">{{ s.full_name }}</option></select>
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Reason</label>
                    <textarea v-model="xfer.reason" rows="2" class="input" />
                </div>
                <div class="sm:col-span-2">
                    <label class="flex cursor-pointer items-center gap-2 text-sm">
                        <input v-model="xfer.carry_leave_balances" type="checkbox" class="rounded" />
                        Carry over current-year leave balances to the new company
                    </label>
                    <p class="mt-1 text-xs text-slate-400">If unchecked, fresh opening balances are created in the target company.</p>
                </div>
            </template>
            <button type="submit" class="hidden" />
        </form>

        <template #footer>
            <button class="btn-secondary" @click="emit('close')">Cancel</button>
            <button class="btn-primary" :disabled="saving || loading || !target || (mode === 'company' && !xfer.company_id)" @click="save">
                <Spinner v-if="saving" /> {{ mode === 'move' ? 'Record move' : 'Transfer employee' }}
            </button>
        </template>
    </Modal>
</template>
