<script setup>
import { ref, reactive, watch } from 'vue';
import { RotateCcw } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { label } from '../lib';

/**
 * Re-instate an exited employee: a new employment period (new record) in the same company,
 * linked to the old one. The login account moves to the new record.
 */
const props = defineProps({ open: { type: Boolean, default: true }, employee: { type: Object, required: true } });
const emit = defineEmits(['close', 'done']);

const today = () => new Date().toISOString().slice(0, 10);
const meta = ref({ branches: [], departments: [], positions: [], levels: [], supervisors: [], contract_types: [] });
const form = reactive({});
const errors = ref({});
const saving = ref(false);
const loading = ref(false);

async function init() {
    errors.value = {};
    loading.value = true;
    let e = props.employee;
    if (!e.company?.id) {
        try { e = (await api.get(`/employees/${e.id}`)).data.data; } catch { /* fall back to the row we were given */ }
    }
    Object.assign(form, {
        employee_number: '', branch_id: e.branch_id ?? e.branch?.id ?? '', department_id: e.department_id ?? e.department?.id ?? '',
        position_id: e.position_id ?? e.position?.id ?? '', employment_level_id: e.employment_level_id ?? e.level?.id ?? '',
        primary_supervisor_id: '', date_of_joining: today(), contract_type: e.contract_type ?? 'permanent', contract_end_date: '',
        probation_months: 0, send_activation: true,
    });
    try {
        const companyId = e.company?.id;
        const [m, n] = await Promise.all([
            api.get('/employees/meta', { params: { company_id: companyId } }),
            api.get('/employees/next-number', { params: { company_id: companyId } }),
        ]);
        meta.value = m.data;
        form.employee_number = n.data.employee_number;
        const sup = e.primary_supervisor_id ?? e.primary_supervisor?.id;
        if (sup && m.data.supervisors.some((s) => s.id === sup)) form.primary_supervisor_id = sup;
    } catch (err) {
        swal.toast(errorMessage(err), 'error');
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, (o) => { if (o) init(); }, { immediate: true });

async function save() {
    saving.value = true;
    errors.value = {};
    const payload = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]));
    try {
        const { data } = await api.post(`/employees/${props.employee.id}/reinstate`, payload);
        swal.toast(data.message);
        emit('done', data.data?.id ?? props.employee.id);
    } catch (e) {
        errors.value = fieldErrors(e);
        if (!Object.keys(errors.value).length) swal.toast(errorMessage(e), 'error');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Modal :open="open" title="Re-instate employee" size="lg" @close="emit('close')">
        <p class="mb-4 text-sm text-slate-500">
            Re-instating <strong class="text-slate-800 dark:text-slate-200">{{ employee.full_name }}</strong> opens a new employment period with a new employee number.
            The previous record stays read-only and linked.
        </p>
        <div v-if="loading" class="flex justify-center py-10"><Spinner /></div>
        <form novalidate v-else class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
            <div>
                <label class="label">Employee number <span class="text-rose-500">*</span></label>
                <input v-model="form.employee_number" class="input" />
                <p v-if="errors.employee_number" class="field-error">{{ errors.employee_number }}</p>
            </div>
            <div>
                <label class="label">Re-joining date <span class="text-rose-500">*</span></label>
                <input v-model="form.date_of_joining" type="date" class="input" />
                <p v-if="errors.date_of_joining" class="field-error">{{ errors.date_of_joining }}</p>
            </div>
            <div>
                <label class="label">Branch <span class="text-rose-500">*</span></label>
                <select v-model="form.branch_id" class="input"><option value="">Select…</option><option v-for="b in meta.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                <p v-if="errors.branch_id" class="field-error">{{ errors.branch_id }}</p>
            </div>
            <div>
                <label class="label">Department <span class="text-rose-500">*</span></label>
                <select v-model="form.department_id" class="input"><option value="">Select…</option><option v-for="d in meta.departments" :key="d.id" :value="d.id">{{ d.name }}</option></select>
                <p v-if="errors.department_id" class="field-error">{{ errors.department_id }}</p>
            </div>
            <div>
                <label class="label">Position <span class="text-rose-500">*</span></label>
                <select v-model="form.position_id" class="input"><option value="">Select…</option><option v-for="p in meta.positions" :key="p.id" :value="p.id">{{ p.title }}</option></select>
                <p v-if="errors.position_id" class="field-error">{{ errors.position_id }}</p>
            </div>
            <div>
                <label class="label">Level</label>
                <select v-model="form.employment_level_id" class="input"><option value="">—</option><option v-for="l in meta.levels" :key="l.id" :value="l.id">{{ l.name }}</option></select>
            </div>
            <div class="sm:col-span-2">
                <label class="label">Supervisor</label>
                <select v-model="form.primary_supervisor_id" class="input"><option value="">— None —</option><option v-for="s in meta.supervisors" :key="s.id" :value="s.id">{{ s.full_name }}</option></select>
                <p v-if="errors.primary_supervisor_id" class="field-error">{{ errors.primary_supervisor_id }}</p>
            </div>
            <div>
                <label class="label">Contract type <span class="text-rose-500">*</span></label>
                <select v-model="form.contract_type" class="input"><option v-for="c in meta.contract_types" :key="c" :value="c">{{ label(c) }}</option></select>
                <p v-if="errors.contract_type" class="field-error">{{ errors.contract_type }}</p>
            </div>
            <div v-if="form.contract_type !== 'permanent'">
                <label class="label">Contract end date <span class="text-rose-500">*</span></label>
                <input v-model="form.contract_end_date" type="date" class="input" />
                <p v-if="errors.contract_end_date" class="field-error">{{ errors.contract_end_date }}</p>
            </div>
            <div>
                <label class="label">Probation (months)</label>
                <input v-model.number="form.probation_months" type="number" min="0" max="12" class="input" />
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2 dark:text-slate-300">
                <input v-model="form.send_activation" type="checkbox" class="rounded text-brand-600" /> Re-enable sign-in and email an activation link
            </label>
            <button type="submit" class="hidden" />
        </form>
        <template #footer>
            <button class="btn-secondary" @click="emit('close')">Cancel</button>
            <button class="btn-primary" :disabled="saving || loading" @click="save"><Spinner v-if="saving" /><RotateCcw v-else class="size-4" /> Re-instate</button>
        </template>
    </Modal>
</template>
