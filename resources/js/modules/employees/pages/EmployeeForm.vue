<script setup>
import { ref, computed, watch } from 'vue';
import { useRoute } from 'vue-router';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { useAuthStore } from '@/stores/auth';
import Modal from '@/components/ui/Modal.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';

const auth = useAuthStore();
const route = useRoute();

const props = defineProps({
    open: Boolean,
    employeeId: { type: [String, Number], default: null },
    applicantId: { type: [String, Number], default: null },
});
const emit = defineEmits(['close', 'done']);

const isEdit  = computed(() => !!props.employeeId);
const isSuperAdmin = computed(() => auth.isSuperAdmin);
const companies    = computed(() => auth.user?.companies ?? []);
const meta = ref(null);
const loading = ref(false);
const saving = ref(false);
const errors = ref({});
const activeTab = ref('personal');

// Which tab each field lives on, so errors can be counted per tab and the form can jump to the first one.
const TAB_FIELDS = {
    personal: ['first_name', 'last_name', 'other_names', 'email', 'phone', 'gender', 'date_of_birth', 'marital_status', 'residence', 'postal_address'],
    employment: ['company_id', 'employee_number', 'date_of_joining', 'contract_type', 'contract_end_date', 'gross_salary', 'branch_id', 'department_id',
        'position_id', 'employment_level_id', 'primary_supervisor_id', 'secondary_supervisor_id', 'probation_months', 'has_medical_cover', 'create_login', 'benefit_ids'],
    statutory: ['national_id', 'kra_pin', 'nssf_number', 'shif_number', 'helb_number'],
    family: ['next_of_kin', 'dependants', 'spouse_name', 'spouse_phone', 'spouse_email'],
};
const tabOf = (field) => Object.keys(TAB_FIELDS).find((t) => TAB_FIELDS[t].includes(field.split('.')[0])) ?? 'personal';
const tabs = computed(() => [
    { key: 'personal', label: 'Personal' },
    { key: 'employment', label: 'Employment' },
    { key: 'statutory', label: 'Statutory' },
    { key: 'family', label: 'Family & NOK' },
].map((t) => {
    const n = Object.keys(errors.value).filter((k) => tabOf(k) === t.key).length;
    return n ? { ...t, label: `${t.label} ⚠`, count: n } : t;
}));

const cap = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ') : s);
const needsEndDate = computed(() => form.value.contract_type && form.value.contract_type !== 'permanent');
// Probation months follow the employment level's default until the user types their own value.
const probationTouched = ref(false);
const applicant = ref(null);

/** Mirrors the server's required rules so every missing field is flagged in one go (server errors are merged in). */
function clientErrors() {
    const f = form.value;
    const out = {};
    const req = { first_name: 'First name', last_name: 'Last name', email: 'Email', phone: 'Phone', gender: 'Gender', date_of_birth: 'Date of birth',
        employee_number: 'Employee number', date_of_joining: 'Date of joining', contract_type: 'Contract type', branch_id: 'Branch',
        department_id: 'Department', position_id: 'Position', national_id: 'National ID' };
    Object.entries(req).forEach(([k, name]) => { if (f[k] === '' || f[k] == null) out[k] = `${name} is required.`; });
    if (isSuperAdmin.value && !isEdit.value && !f.company_id) out.company_id = 'Company is required.';
    if (needsEndDate.value && !f.contract_end_date) out.contract_end_date = 'An end date is required for contract, casual and intern staff.';
    else if (needsEndDate.value && f.date_of_joining && f.contract_end_date <= f.date_of_joining) out.contract_end_date = 'The contract end date must be after the date of joining.';
    (f.next_of_kin ?? []).forEach((n, i) => {
        if (!n.name) out[`next_of_kin.${i}.name`] = 'Next of kin name is required.';
        if (!n.phone) out[`next_of_kin.${i}.phone`] = 'Next of kin phone is required.';
    });
    return out;
}

function showErrors(errs) {
    errors.value = errs;
    const order = ['personal', 'employment', 'statutory', 'family'];
    const first = order.find((t) => Object.keys(errs).some((k) => tabOf(k) === t));
    if (first && !Object.keys(errs).some((k) => tabOf(k) === activeTab.value)) activeTab.value = first;
}

const defaultForm = () => ({
    first_name: '', last_name: '', other_names: '', email: '', phone: '',
    gender: '', date_of_birth: '', marital_status: '', postal_address: '', residence: '',
    national_id: '', kra_pin: '', nssf_number: '', shif_number: '', helb_number: '',
    branch_id: '', department_id: '', position_id: '', employment_level_id: '',
    primary_supervisor_id: '', secondary_supervisor_id: '',
    date_of_joining: '', contract_type: 'permanent', contract_end_date: '',
    probation_months: 0, has_medical_cover: true,
    employee_number: '', company_id: '',
    next_of_kin: [{ name: '', relationship: 'parent', phone: '', email: '', address: '' }],
    benefit_ids: [],
    create_login: true,
    spouse_name: '', spouse_phone: '', spouse_email: '',
});

const form = ref(defaultForm());

// Watchers that read `form` must come after its declaration: declaring one earlier throws
// "Cannot access 'form' before initialization" when the component is set up.
// Picking a position (user action only, so loading a saved record keeps its level) fills in the
// position's employment level; the level watcher then fills in probation months.
function onPositionPicked() {
    const position = meta.value?.positions?.find((p) => String(p.id) === String(form.value.position_id));
    if (position?.employment_level_id) form.value.employment_level_id = position.employment_level_id;
}
watch(() => form.value.employment_level_id, (id) => {
    if (probationTouched.value || !id) return;
    const level = meta.value?.levels?.find((l) => String(l.id) === String(id));
    if (level && level.probation_months != null) form.value.probation_months = level.probation_months;
});

// Clear a field's validation message as soon as the user changes that field.
watch(() => ({ ...form.value }), (now, before) => {
    if (!before) return;
    const changed = Object.keys(now).filter((k) => JSON.stringify(now[k]) !== JSON.stringify(before[k]) && errors.value[k]);
    if (changed.length) errors.value = Object.fromEntries(Object.entries(errors.value).filter(([k]) => !changed.includes(k)));
}, { deep: true });
// Next-of-kin rows are edited in place, so compare against a snapshot to clear e.g. next_of_kin.0.phone.
let nokSnapshot = [];
watch(() => form.value.next_of_kin, (rows) => {
    const stale = Object.keys(errors.value).filter((k) => {
        const [root, i, field] = k.split('.');
        return root === 'next_of_kin' && (rows?.[i]?.[field] ?? '') !== (nokSnapshot[i]?.[field] ?? '');
    });
    if (stale.length) errors.value = Object.fromEntries(Object.entries(errors.value).filter(([k]) => !stale.includes(k)));
    nokSnapshot = JSON.parse(JSON.stringify(rows ?? []));
}, { deep: true });

async function loadMeta(companyId) {
    const params = companyId ? { company_id: companyId } : {};
    const { data } = await api.get('/employees/meta', { params });
    meta.value = data;
}

async function fetchNextNumber() {
    if (isEdit.value || !form.value.company_id) return;
    try {
        const { data } = await api.get('/employees/next-number', { params: { company_id: form.value.company_id } });
        form.value.employee_number = data.employee_number;
    } catch {}
}

async function loadEmployee() {
    if (!props.employeeId) return;
    const { data } = await api.get(`/employees/${props.employeeId}`);
    const e = data.data;
    const f = defaultForm();
    Object.keys(f).forEach(k => { if (e[k] !== undefined) f[k] = e[k]; });
    f.branch_id = e.branch_id ?? '';
    f.department_id = e.department_id ?? '';
    f.position_id = e.position_id ?? '';
    f.primary_supervisor_id = e.primary_supervisor?.id ?? '';
    f.secondary_supervisor_id = e.secondary_supervisor?.id ?? '';
    if (e.next_of_kin?.length) f.next_of_kin = e.next_of_kin;
    probationTouched.value = true; // keep the employee's saved probation length
    f.benefit_ids = e.benefits?.map(b => b.id) ?? [];
    // Extract spouse from dependants
    const spouse = e.dependants?.find(d => d.relationship === 'spouse');
    if (spouse) {
        f.spouse_name = spouse.name ?? '';
        f.spouse_phone = spouse.phone ?? '';
        f.spouse_email = spouse.email ?? '';
    }
    form.value = f;
}

watch(() => props.open, async (val) => {
    if (!val) return;
    activeTab.value = 'personal';
    errors.value = {};
    form.value = defaultForm();
    applicant.value = null;
    probationTouched.value = false;
    if (isSuperAdmin.value && !isEdit.value) {
        const scope = localStorage.getItem('companyScope') || '';
        form.value.company_id = scope || String(companies.value[0]?.id ?? '');
    }
    loading.value = true;
    try {
        // Converting a hired applicant (Recruitment → "Convert to employee"): /employees?applicant_id=X
        const applicantId = !isEdit.value ? (props.applicantId || route.query.applicant_id) : null;
        const prefill = applicantId ? (await api.get(`/recruitment/applicants/${applicantId}/conversion`)).data.data : null;
        if (prefill?.company_id) form.value.company_id = String(prefill.company_id);
        await Promise.all([loadMeta(form.value.company_id || null), loadEmployee()]);
        if (!isEdit.value) await fetchNextNumber();
        if (prefill) {
            Object.keys(form.value).forEach((k) => { if (prefill[k] !== undefined && prefill[k] !== null && k !== 'company_id') form.value[k] = prefill[k]; });
            form.value.applicant_id = prefill.applicant_id;
            form.value.gross_salary = prefill.gross_salary ?? '';
            applicant.value = prefill;
        }
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
});

watch(() => form.value.company_id, async (val, old) => {
    if (!val || val === old) return;
    // reload dropdowns for the new company and reset company-specific selections
    if (!isEdit.value) {
        form.value.branch_id = '';
        form.value.department_id = '';
        form.value.position_id = '';
        form.value.employment_level_id = '';
        form.value.primary_supervisor_id = '';
        form.value.secondary_supervisor_id = '';
        await Promise.all([loadMeta(val), fetchNextNumber()]);
    }
});

async function save() {
    const local = clientErrors();
    if (Object.keys(local).length) {
        showErrors(local);
        const n = Object.keys(local).length;
        swal.toast(n === 1 ? Object.values(local)[0] : `Please fix ${n} fields (marked in red on each tab).`, 'error');
        return;
    }
    saving.value = true;
    errors.value = {};
    try {
        // Transform spouse flat fields into dependants array entry
        const payload = { ...form.value };
        const spouseDependant = {
            name: payload.spouse_name || '',
            relationship: 'spouse',
            phone: payload.spouse_phone || '',
            email: payload.spouse_email || '',
            date_of_birth: '',
        };
        // Merge spouse into dependants: replace existing spouse entry or prepend
        const existingDependants = (payload.dependants ?? []).filter(d => d.relationship !== 'spouse');
        if (payload.spouse_name) {
            payload.dependants = [spouseDependant, ...existingDependants];
        }
        delete payload.spouse_name;
        delete payload.spouse_phone;
        delete payload.spouse_email;

        if (isEdit.value) {
            await api.put(`/employees/${props.employeeId}`, payload);
            swal.toast('Employee updated.');
        } else {
            const { data } = await api.post('/employees', payload);
            swal.toast('Employee added.');
            emit('done', data.data.id);
            emit('close');
            return;
        }
        emit('done', props.employeeId);
        emit('close');
    } catch (e) {
        showErrors(fieldErrors(e));
        swal.toast(errorMessage(e), 'error');
    } finally {
        saving.value = false;
    }
}

function addNok() { form.value.next_of_kin.push({ name: '', relationship: 'parent', phone: '', email: '', address: '' }); }
function removeNok(i) { if (form.value.next_of_kin.length > 1) form.value.next_of_kin.splice(i, 1); }
</script>

<template>
    <Modal :open="open" :title="isEdit ? 'Edit Employee' : 'Add Employee'" size="xl" @close="emit('close')">
        <div v-if="loading" class="flex justify-center py-10"><Spinner /></div>
        <template v-else>
            <div v-if="applicant" class="mb-3 rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800 dark:bg-brand-500/10 dark:text-brand-200">
                Converting applicant <strong>{{ applicant.first_name }} {{ applicant.last_name }}</strong><span v-if="applicant.posting_title"> ({{ applicant.posting_title }})</span>.
                Review the prefilled details and complete the rest (date of birth, ID, next of kin).
            </div>
            <Tabs :tabs="tabs" v-model="activeTab" />

            <div class="mt-4">
                <!-- Personal -->
                <template v-if="activeTab === 'personal'">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label">First Name <span class="text-red-500">*</span></label>
                            <input v-model="form.first_name" type="text" class="input" :class="errors.first_name && 'border-red-500'" />
                            <p v-if="errors.first_name" class="mt-1 text-xs text-red-500">{{ errors.first_name }}</p>
                        </div>
                        <div>
                            <label class="label">Last Name <span class="text-red-500">*</span></label>
                            <input v-model="form.last_name" type="text" class="input" :class="errors.last_name && 'border-red-500'" />
                            <p v-if="errors.last_name" class="mt-1 text-xs text-red-500">{{ errors.last_name }}</p>
                        </div>
                        <div>
                            <label class="label">Email <span class="text-red-500">*</span></label>
                            <input v-model="form.email" type="email" class="input" :class="errors.email && 'border-red-500'" />
                            <p v-if="errors.email" class="mt-1 text-xs text-red-500">{{ errors.email }}</p>
                        </div>
                        <div>
                            <label class="label" for="emp-phone">Phone <span class="text-red-500">*</span></label>
                            <input id="emp-phone" v-model="form.phone" type="tel" class="input" placeholder="07XXXXXXXX" :class="errors.phone && 'border-red-500'" />
                            <p v-if="errors.phone" class="mt-1 text-xs text-red-500">{{ errors.phone }}</p>
                        </div>
                        <div>
                            <label class="label" for="emp-gender">Gender <span class="text-red-500">*</span></label>
                            <select id="emp-gender" v-model="form.gender" class="input" :class="errors.gender && 'border-red-500'">
                                <option value="">— Select —</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other</option>
                            </select>
                            <p v-if="errors.gender" class="mt-1 text-xs text-red-500">{{ errors.gender }}</p>
                        </div>
                        <div>
                            <label class="label">Date of Birth <span class="text-red-500">*</span></label>
                            <input v-model="form.date_of_birth" type="date" class="input" :class="errors.date_of_birth && 'border-red-500'" />
                            <p v-if="errors.date_of_birth" class="mt-1 text-xs text-red-500">{{ errors.date_of_birth }}</p>
                        </div>
                        <div>
                            <label class="label">Marital Status</label>
                            <select v-model="form.marital_status" class="input">
                                <option value="">— Select —</option>
                                <option v-for="s in ['single','married','divorced','widowed']" :key="s" :value="s">{{ cap(s) }}</option>
                            </select>
                            <p v-if="errors.marital_status" class="mt-1 text-xs text-red-500">{{ errors.marital_status }}</p>
                        </div>
                        <div>
                            <label class="label">Residence</label>
                            <input v-model="form.residence" type="text" class="input" />
                            <p v-if="errors.residence" class="mt-1 text-xs text-red-500">{{ errors.residence }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="label">Postal Address</label>
                            <input v-model="form.postal_address" type="text" class="input" />
                            <p v-if="errors.postal_address" class="mt-1 text-xs text-red-500">{{ errors.postal_address }}</p>
                        </div>
                    </div>
                </template>

                <!-- Employment -->
                <template v-else-if="activeTab === 'employment'">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div v-if="isSuperAdmin && !isEdit" class="sm:col-span-2">
                            <label class="label">Company <span class="text-red-500">*</span></label>
                            <select v-model="form.company_id" class="input" :class="errors.company_id && 'border-red-500'">
                                <option value="">— Select company —</option>
                                <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                            <p v-if="errors.company_id" class="mt-1 text-xs text-red-500">{{ errors.company_id }}</p>
                        </div>
                        <div>
                            <label class="label">Employee Number <span class="text-red-500">*</span></label>
                            <input v-model="form.employee_number" type="text" class="input" :class="errors.employee_number && 'border-red-500'" />
                            <p v-if="errors.employee_number" class="mt-1 text-xs text-red-500">{{ errors.employee_number }}</p>
                        </div>
                        <div>
                            <label class="label">Date of Joining <span class="text-red-500">*</span></label>
                            <input v-model="form.date_of_joining" type="date" class="input" :class="errors.date_of_joining && 'border-red-500'" />
                            <p v-if="errors.date_of_joining" class="mt-1 text-xs text-red-500">{{ errors.date_of_joining }}</p>
                        </div>
                        <div>
                            <label class="label">Contract Type <span class="text-red-500">*</span></label>
                            <select v-model="form.contract_type" class="input" :class="errors.contract_type && 'border-red-500'">
                                <option v-for="t in ['permanent','contract','casual','intern']" :key="t" :value="t">{{ cap(t) }}</option>
                            </select>
                            <p v-if="errors.contract_type" class="mt-1 text-xs text-red-500">{{ errors.contract_type }}</p>
                        </div>
                        <div v-if="applicant && auth.can('employees.view-salary')">
                            <label class="label">Contract gross salary (from offer)</label>
                            <input v-model="form.gross_salary" type="number" min="0" class="input" />
                            <p v-if="errors.gross_salary" class="mt-1 text-xs text-red-500">{{ errors.gross_salary }}</p>
                        </div>
                        <div v-if="needsEndDate">
                            <label class="label">Contract End Date <span class="text-red-500">*</span></label>
                            <input v-model="form.contract_end_date" type="date" class="input" :class="errors.contract_end_date && 'border-red-500'" />
                            <p v-if="errors.contract_end_date" class="mt-1 text-xs text-red-500">{{ errors.contract_end_date }}</p>
                        </div>
                        <div>
                            <label class="label">Branch <span class="text-red-500">*</span></label>
                            <select v-model="form.branch_id" class="input" :class="errors.branch_id && 'border-red-500'">
                                <option value="">— Select —</option>
                                <option v-for="b in meta?.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                            <p v-if="errors.branch_id" class="mt-1 text-xs text-red-500">{{ errors.branch_id }}</p>
                        </div>
                        <div>
                            <label class="label">Department <span class="text-red-500">*</span></label>
                            <select v-model="form.department_id" class="input" :class="errors.department_id && 'border-red-500'">
                                <option value="">— Select —</option>
                                <option v-for="d in meta?.departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                            </select>
                            <p v-if="errors.department_id" class="mt-1 text-xs text-red-500">{{ errors.department_id }}</p>
                        </div>
                        <div>
                            <label class="label">Position <span class="text-red-500">*</span></label>
                            <select v-model="form.position_id" class="input" :class="errors.position_id && 'border-red-500'" @change="onPositionPicked">
                                <option value="">— Select —</option>
                                <option v-for="p in meta?.positions" :key="p.id" :value="p.id">{{ p.title }}</option>
                            </select>
                            <p v-if="errors.position_id" class="mt-1 text-xs text-red-500">{{ errors.position_id }}</p>
                        </div>
                        <div>
                            <label class="label">Employment Level</label>
                            <select v-model="form.employment_level_id" class="input">
                                <option value="">— Select —</option>
                                <option v-for="l in meta?.levels" :key="l.id" :value="l.id">{{ l.name }}</option>
                            </select>
                            <p v-if="errors.employment_level_id" class="mt-1 text-xs text-red-500">{{ errors.employment_level_id }}</p>
                        </div>
                        <div>
                            <label class="label">Primary Supervisor</label>
                            <select v-model="form.primary_supervisor_id" class="input">
                                <option value="">— None —</option>
                                <option v-for="s in meta?.supervisors" :key="s.id" :value="s.id">{{ s.full_name }}</option>
                            </select>
                            <p v-if="errors.primary_supervisor_id" class="mt-1 text-xs text-red-500">{{ errors.primary_supervisor_id }}</p>
                        </div>
                        <div>
                            <label class="label">Secondary Supervisor</label>
                            <select v-model="form.secondary_supervisor_id" class="input">
                                <option value="">— None —</option>
                                <option v-for="s in meta?.supervisors?.filter(s => s.id != form.primary_supervisor_id)" :key="s.id" :value="s.id">{{ s.full_name }}</option>
                            </select>
                            <p v-if="errors.secondary_supervisor_id" class="mt-1 text-xs text-red-500">{{ errors.secondary_supervisor_id }}</p>
                        </div>
                        <div>
                            <label class="label">Probation Months</label>
                            <input v-model="form.probation_months" type="number" min="0" max="12" class="input" :class="errors.probation_months && 'border-red-500'" @input="probationTouched = true" />
                            <p v-if="!probationTouched && form.employment_level_id" class="mt-1 text-xs text-slate-400">Default for the selected level.</p>
                            <p v-if="errors.probation_months" class="mt-1 text-xs text-red-500">{{ errors.probation_months }}</p>
                        </div>
                        <div class="flex items-center gap-2 pt-6">
                            <input v-model="form.has_medical_cover" type="checkbox" id="medical" class="rounded" />
                            <p v-if="errors.has_medical_cover" class="mt-1 text-xs text-red-500">{{ errors.has_medical_cover }}</p>
                            <label for="medical" class="text-sm">Has Medical Cover</label>
                        </div>
                        <div v-if="!isEdit" class="flex items-center gap-2 pt-6">
                            <input v-model="form.create_login" type="checkbox" id="login" class="rounded" />
                            <p v-if="errors.create_login" class="mt-1 text-xs text-red-500">{{ errors.create_login }}</p>
                            <label for="login" class="text-sm">Send activation email</label>
                        </div>
                        <div v-if="meta?.benefits?.length" class="sm:col-span-2">
                            <label class="label">Benefits</label>
                            <div class="flex flex-wrap gap-2">
                                <label v-for="b in meta.benefits" :key="b.id" class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700" :class="form.benefit_ids.includes(b.id) ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20' : ''">
                                    <input type="checkbox" class="rounded text-brand-600" :value="b.id" v-model="form.benefit_ids" />
                                    <p v-if="errors.benefit_ids" class="mt-1 text-xs text-red-500">{{ errors.benefit_ids }}</p>
                                    {{ b.name }}
                                </label>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Statutory -->
                <template v-else-if="activeTab === 'statutory'">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div v-for="[key, label, req] in [['national_id','National ID',true],['kra_pin','KRA PIN'],['nssf_number','NSSF No.'],['shif_number','SHIF No.'],['helb_number','HELB No.']]" :key="key">
                            <label class="label" :for="`emp-${key}`">{{ label }} <span v-if="req" class="text-red-500">*</span></label>
                            <input :id="`emp-${key}`" v-model="form[key]" type="text" class="input" :class="errors[key] && 'border-red-500'" />
                            <p v-if="errors[key]" class="mt-1 text-xs text-red-500">{{ errors[key] }}</p>
                        </div>
                    </div>
                </template>

                <!-- Family & NOK -->
                <template v-else-if="activeTab === 'family'">
                    <!-- Spouse -->
                    <h3 class="mb-3 font-semibold text-slate-800 dark:text-slate-200">Spouse</h3>
                    <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label class="label">Spouse Name</label>
                            <input v-model="form.spouse_name" type="text" class="input" />
                        </div>
                        <div>
                            <label class="label">Spouse Phone</label>
                            <input v-model="form.spouse_phone" type="tel" class="input" placeholder="07XXXXXXXX" />
                        </div>
                        <div>
                            <label class="label">Spouse Email</label>
                            <input v-model="form.spouse_email" type="email" class="input" />
                        </div>
                    </div>
                    <!-- Next of Kin -->
                    <h3 class="mb-3 font-semibold text-slate-800 dark:text-slate-200">Next of Kin</h3>
                    <div v-for="(nok, i) in form.next_of_kin" :key="i" class="mb-4 rounded-lg border border-slate-100 p-4 dark:border-slate-800">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label class="label">Name</label>
                                <input v-model="nok.name" type="text" class="input" :class="errors[`next_of_kin.${i}.name`] && 'border-red-500'" />
                                <p v-if="errors[`next_of_kin.${i}.name`]" class="mt-1 text-xs text-red-500">{{ errors[`next_of_kin.${i}.name`] }}</p>
                            </div>
                            <div>
                                <label class="label">Relationship</label>
                                <select v-model="nok.relationship" class="input">
                                    <option v-for="r in meta?.relationships ?? ['parent','sibling','spouse','child','other']" :key="r" :value="r">{{ cap(r) }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="label">Phone</label>
                                <input v-model="nok.phone" type="tel" class="input" :class="errors[`next_of_kin.${i}.phone`] && 'border-red-500'" />
                                <p v-if="errors[`next_of_kin.${i}.phone`]" class="mt-1 text-xs text-red-500">{{ errors[`next_of_kin.${i}.phone`] }}</p>
                            </div>
                            <div>
                                <label class="label">Email</label>
                                <input v-model="nok.email" type="email" class="input" :class="errors[`next_of_kin.${i}.email`] && 'border-red-500'" />
                                <p v-if="errors[`next_of_kin.${i}.email`]" class="mt-1 text-xs text-red-500">{{ errors[`next_of_kin.${i}.email`] }}</p>
                            </div>
                        </div>
                        <button v-if="form.next_of_kin.length > 1" type="button" class="mt-2 text-xs text-red-500 hover:underline" @click="removeNok(i)">Remove</button>
                    </div>
                    <p v-if="errors.next_of_kin" class="mb-2 text-xs text-red-500">{{ errors.next_of_kin }}</p>
                    <button type="button" class="btn-secondary text-sm" @click="addNok">+ Add Next of Kin</button>
                </template>
            </div>
        </template>

        <template #footer>
            <button class="btn-secondary" @click="emit('close')">Cancel</button>
            <button class="btn-primary" :disabled="saving || loading" @click="save">{{ saving ? 'Saving…' : (isEdit ? 'Save Changes' : 'Add Employee') }}</button>
        </template>
    </Modal>
</template>
