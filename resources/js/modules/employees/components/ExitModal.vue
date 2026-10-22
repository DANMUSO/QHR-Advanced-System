<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { LogOut, Users } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import Avatar from '@/components/ui/Avatar.vue';
import EmployeePicker from './EmployeePicker.vue';

/**
 * Exit an employee: date, reason, comments and reassignment of their direct reports.
 * Pass `employee` to exit a known person, or leave it null to pick one.
 */
// `prefill` seeds the form, e.g. from a self-service resignation: { exit_date, notice_date, reason, comments }.
const props = defineProps({ open: Boolean, employee: { type: Object, default: null }, prefill: { type: Object, default: null } });
const emit = defineEmits(['close', 'done']);

const today = () => new Date().toISOString().slice(0, 10);
const picked = ref(null);
const form = reactive({ exit_date: today(), notice_date: '', reason: '', comments: '', new_supervisor_id: '' });
const overrides = ref({});
const reports = ref([]);
const supervisors = ref([]);
const reasons = ref([]);
const errors = ref({});
const saving = ref(false);
const loadingCtx = ref(false);

const target = computed(() => props.employee ?? picked.value);
const supervisorChoices = computed(() => supervisors.value.filter((s) => s.id !== target.value?.id));

async function loadContext(emp) {
    reports.value = [];
    overrides.value = {};
    if (!emp) return;
    loadingCtx.value = true;
    try {
        let companyId = emp.company?.id;
        if (!companyId) companyId = (await api.get(`/employees/${emp.id}`)).data.data?.company?.id;
        const [meta, team] = await Promise.all([
            api.get('/employees/meta', { params: { company_id: companyId } }),
            api.get('/employees', { params: { supervisor_id: emp.id, status: 'active,suspended', per_page: 100 } }),
        ]);
        supervisors.value = meta.data.supervisors ?? [];
        reasons.value = meta.data.exit_reasons ?? [];
        reports.value = team.data.data ?? [];
        form.new_supervisor_id = emp.primary_supervisor?.id ?? emp.primary_supervisor_id ?? '';
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
    } finally {
        loadingCtx.value = false;
    }
}

watch(() => props.open, (o) => {
    if (!o) return;
    Object.assign(form, { exit_date: today(), notice_date: '', reason: '', comments: '', new_supervisor_id: '', ...(props.prefill ?? {}) });
    errors.value = {};
    picked.value = null;
    if (props.employee) loadContext(props.employee);
});
watch(picked, (e) => { if (!props.employee) loadContext(e); });

async function save() {
    if (!target.value) { errors.value = { employee_id: 'Choose the employee who is leaving.' }; return; }
    saving.value = true;
    errors.value = {};
    const reassignments = reports.value
        .map((r) => ({ employee_id: r.id, supervisor_id: overrides.value[r.id] || form.new_supervisor_id || null }))
        .filter((r) => overrides.value[r.employee_id]);
    try {
        const { data } = await api.post(`/employees/${target.value.id}/exit`, {
            exit_date: form.exit_date,
            notice_date: form.notice_date || null,
            reason: form.reason,
            comments: form.comments || null,
            new_supervisor_id: form.new_supervisor_id || null,
            reassignments: reassignments.length ? reassignments : null,
        });
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
    <Modal :open="open" title="Exit employee" size="lg" @close="emit('close')">
        <form novalidate class="space-y-4" @submit.prevent="save">
            <div v-if="!employee">
                <label class="label">Employee <span class="text-rose-500">*</span></label>
                <EmployeePicker v-model="picked" />
                <p v-if="errors.employee_id" class="field-error">{{ errors.employee_id }}</p>
            </div>
            <div v-else class="flex items-center gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50">
                <Avatar :name="employee.full_name" :src="employee.photo_url" size="sm" />
                <div>
                    <p class="text-sm font-medium text-slate-900 dark:text-white">{{ employee.full_name }}</p>
                    <p class="text-xs text-slate-500">{{ employee.employee_number }} · {{ employee.position?.title ?? '—' }}</p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label">Last working day <span class="text-rose-500">*</span></label>
                    <input v-model="form.exit_date" type="date" class="input" required />
                    <p class="mt-1 text-xs text-slate-500">A future date schedules the exit; today or earlier exits immediately.</p>
                    <p v-if="errors.exit_date" class="field-error">{{ errors.exit_date }}</p>
                </div>
                <div>
                    <label class="label">Notice date</label>
                    <input v-model="form.notice_date" type="date" class="input" />
                    <p v-if="errors.notice_date" class="field-error">{{ errors.notice_date }}</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Reason <span class="text-rose-500">*</span></label>
                    <select v-model="form.reason" class="input" required>
                        <option value="" disabled>Select a reason…</option>
                        <option v-for="r in reasons" :key="r.value" :value="r.value">{{ r.label }}</option>
                    </select>
                    <p v-if="errors.reason" class="field-error">{{ errors.reason }}</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Comments</label>
                    <textarea v-model="form.comments" rows="3" class="input" placeholder="Context for the exit record (visible to HR)" />
                    <p v-if="errors.comments" class="field-error">{{ errors.comments }}</p>
                </div>
            </div>

            <div v-if="target" class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                <div class="mb-3 flex items-center gap-2">
                    <Users class="size-4 text-slate-400" />
                    <h4 class="text-sm font-semibold text-slate-900 dark:text-white">Reassign direct reports</h4>
                    <Spinner v-if="loadingCtx" class="ml-auto" />
                </div>
                <p v-if="!loadingCtx && !reports.length" class="text-sm text-slate-500">{{ target.full_name }} has no direct reports.</p>
                <template v-else-if="reports.length">
                    <label class="label">New supervisor for all reports</label>
                    <select v-model="form.new_supervisor_id" class="input">
                        <option value="">— Leave without supervisor —</option>
                        <option v-for="s in supervisorChoices" :key="s.id" :value="s.id">{{ s.full_name }}</option>
                    </select>
                    <ul class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                        <li v-for="r in reports" :key="r.id" class="flex flex-wrap items-center gap-3 py-2">
                            <Avatar :name="r.full_name" :src="r.photo_url" size="xs" />
                            <span class="min-w-0 flex-1 truncate text-sm text-slate-800 dark:text-slate-200">{{ r.full_name }}</span>
                            <select v-model="overrides[r.id]" class="input !w-full !py-1.5 text-sm sm:!w-56">
                                <option :value="undefined">Same as above</option>
                                <option v-for="s in supervisorChoices.filter((x) => x.id !== r.id)" :key="s.id" :value="s.id">{{ s.full_name }}</option>
                            </select>
                        </li>
                    </ul>
                </template>
                <p v-if="errors.new_supervisor_id" class="field-error">{{ errors.new_supervisor_id }}</p>
            </div>
            <button type="submit" class="hidden" />
        </form>
        <template #footer>
            <button class="btn-secondary" @click="emit('close')">Cancel</button>
            <button class="btn-danger" :disabled="saving || !target || !form.reason || !form.exit_date" @click="save">
                <Spinner v-if="saving" /><LogOut v-else class="size-4" /> Exit employee
            </button>
        </template>
    </Modal>
</template>
