<script setup>
import { ref, onMounted } from 'vue';
import { Clock, CalendarDays, HeartPulse, Wallet, ShieldCheck, Contact } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import Spinner from '@/components/ui/Spinner.vue';
import { useCompanyContext } from '../../company';

/** Company rules (companies.settings) used by Leave, Attendance, Claims, Payroll and sign-in security. */
const ui = useUiStore();
const { cfg } = useCompanyContext();

const settings = ref(null);
const defaults = ref({});
const roles = ref([]);
const errors = ref({});
const loading = ref(true);
const saving = ref(false);

const groups = [
    { title: 'Working hours', icon: Clock, fields: [
        { key: 'work_start', label: 'Work starts', type: 'time' },
        { key: 'work_end', label: 'Work ends', type: 'time' },
        { key: 'hours_per_day', label: 'Hours per day', type: 'number', step: '0.5' },
        { key: 'late_grace_minutes', label: 'Late grace (minutes)', type: 'number' },
    ] },
    { title: 'Leave', icon: CalendarDays, fields: [
        { key: 'annual_leave_days', label: 'Annual leave days', type: 'number' },
        { key: 'monthly_accrual', label: 'Monthly accrual (days)', type: 'number', step: '0.01' },
        { key: 'carry_forward_cap', label: 'Carry-forward cap (days)', type: 'number', step: '0.5' },
        { key: 'resignation_notice_days', label: 'Resignation notice (days)', type: 'number' },
    ] },
    { title: 'Medical cover', icon: HeartPulse, fields: [
        { key: 'medical_annual_limit', label: 'Annual limit', type: 'number', money: true },
        { key: 'medical_monthly_limit', label: 'Monthly limit', type: 'number', money: true },
    ] },
    { title: 'Payroll', icon: Wallet, fields: [
        { key: 'currency', label: 'Currency', type: 'text', maxlength: 3 },
        { key: 'pay_day', label: 'Pay day (day of month)', type: 'number' },
        { key: 'overtime_multiplier_normal', label: 'Overtime rate (normal days)', type: 'number', step: '0.1' },
        { key: 'overtime_multiplier_holiday', label: 'Overtime rate (rest days & holidays)', type: 'number', step: '0.1' },
    ] },
    { title: 'HR contact', icon: Contact, fields: [
        { key: 'hr_contact_name', label: 'Name', type: 'text' },
        { key: 'hr_contact_email', label: 'Email', type: 'email' },
        { key: 'hr_contact_phone', label: 'Phone', type: 'tel' },
    ] },
];

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/admin/settings', cfg());
        settings.value = data.settings ?? null;
        defaults.value = data.defaults ?? {};
        roles.value = data.roles ?? [];
    } catch (e) {
        settings.value = null;
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

function toggleRole(role) {
    const list = settings.value.require_two_factor_roles ?? [];
    settings.value.require_two_factor_roles = list.includes(role) ? list.filter((r) => r !== role) : [...list, role];
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await api.put('/admin/settings', settings.value, cfg());
        settings.value = data.settings;
        ui.toast(data.message);
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally {
        saving.value = false;
    }
}

defineExpose({ save, saving });
onMounted(load);
</script>

<template>
    <div v-if="loading" class="flex justify-center py-16"><Spinner /></div>
    <div v-else-if="!settings" class="card p-10 text-center text-sm text-slate-500">Select a company to edit its settings.</div>
    <form v-else class="space-y-5" @submit.prevent="save">
        <div class="grid gap-5 lg:grid-cols-2">
            <section v-for="g in groups" :key="g.title" class="card p-5">
                <h3 class="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><component :is="g.icon" class="size-4 text-slate-400" /> {{ g.title }}</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-for="f in g.fields" :key="f.key">
                        <label class="label" :for="`set-${f.key}`">{{ f.label }}</label>
                        <input :id="`set-${f.key}`" v-model="settings[f.key]" :type="f.type" :step="f.step" :maxlength="f.maxlength" min="0" class="input" />
                        <p v-if="errors[f.key]" class="field-error">{{ errors[f.key] }}</p>
                        <p v-else-if="defaults[f.key] !== undefined && defaults[f.key] !== null" class="mt-1 text-xs text-slate-400">Default: {{ defaults[f.key] }}</p>
                    </div>
                </div>
            </section>

            <section class="card p-5">
                <h3 class="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><ShieldCheck class="size-4 text-slate-400" /> Sign-in security</h3>
                <div class="space-y-4">
                    <div>
                        <label class="label" for="set-idle">Sign out after inactivity (minutes)</label>
                        <input id="set-idle" v-model="settings.idle_timeout_minutes" type="number" min="5" max="480" class="input sm:w-40" />
                        <p v-if="errors.idle_timeout_minutes" class="field-error">{{ errors.idle_timeout_minutes }}</p>
                        <p v-else class="mt-1 text-xs text-slate-500">Enforced by the server as well as the browser. Every session also ends 4 hours after sign-in.</p>
                    </div>
                    <div>
                        <p class="label">Roles that must use two-factor authentication</p>
                        <div class="flex flex-wrap gap-2">
                            <label v-for="r in roles" :key="r" class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5 text-sm dark:border-slate-700">
                                <input type="checkbox" class="rounded text-brand-600" :checked="settings.require_two_factor_roles?.includes(r)" @change="toggleRole(r)" /> {{ r }}
                            </label>
                        </div>
                        <p v-if="errors.require_two_factor_roles" class="field-error">{{ errors.require_two_factor_roles }}</p>
                    </div>
                </div>
            </section>
        </div>
        <div class="flex justify-end">
            <button type="submit" class="btn-primary" :disabled="saving"><Spinner v-if="saving" /> Save settings</button>
        </div>
    </form>
</template>
